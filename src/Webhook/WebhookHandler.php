<?php

declare(strict_types=1);

namespace Switchboard\Webhook;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Config;
use Switchboard\Http\Json;
use Switchboard\Log;
use Switchboard\Queue\DeliveryLog;
use Switchboard\Queue\Job;
use Switchboard\Queue\JobQueue;
use Switchboard\Storage\StorageException;

/**
 * `POST /webhook` — the only endpoint that writes, and the only one reachable
 * without credentials.
 *
 * # Order of operations
 *
 * Each step exists because skipping it breaks something specific.
 *
 *  1. **Shape checks** (method, content type, declared length) — cheap, and
 *     they turn away traffic that is obviously not GitHub before any work.
 *  2. **Read the raw body**, capped. Raw, because the signature covers those
 *     exact bytes.
 *  3. **Verify the signature.** Before parsing, before dispatching on the event
 *     type, before touching the disk. Nothing downstream of this line runs for
 *     an unauthenticated caller — including the event-type check, so a prober
 *     cannot map which events we handle.
 *  4. **Filter the event type**, still without decoding JSON.
 *  5. **Decode and validate** the payload.
 *  6. **Apply policy** — repository allowlist, fork gate.
 *  7. **Claim the delivery** (dedup), then **write the job** atomically.
 *
 * # Answering fast
 *
 * GitHub abandons a webhook at 10 seconds and marks the delivery failed. This
 * handler makes **no network calls at all** and touches the disk a handful of
 * times: an HMAC, a JSON decode, one exclusive create, one write plus rename.
 *
 * Everything expensive belongs to the daemon: cloning, `composer install`,
 * seeding, health polling — and, deliberately, **every call to the GitHub API**,
 * including creating the deployment and posting its statuses. Two reasons. A
 * call to `api.github.com` on this path would put an unbounded network
 * round-trip inside a 10-second budget. More importantly, minting an
 * installation token needs the GitHub App private key, and that key can write
 * to every repository the App is installed on — handing it to the
 * internet-facing component that accepts unauthenticated POSTs would give away
 * the confinement this split exists to create. The daemon runs unconfined and
 * already holds the key.
 *
 * So this service holds exactly one credential: the webhook secret, which can
 * only be used to *verify*. The job carries `installation_id` so the daemon can
 * mint its own token.
 */
final class WebhookHandler implements RequestHandlerInterface
{
    /** Events accepted for processing. Anything else is acknowledged and dropped. */
    private const HANDLED_EVENTS = ['pull_request'];

    /** One request in this many opportunistically prunes old delivery markers. */
    private const PRUNE_ODDS = 50;

    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
        private readonly SignatureVerifier $verifier,
        private readonly DeliveryLog $deliveries,
        private readonly JobQueue $queue,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') {
            return $this->json->response(405, ['ok' => false, 'error' => 'method not allowed'], ['Allow' => 'POST']);
        }

        if (($contentTypeError = $this->checkContentType($request)) !== null) {
            return $contentTypeError;
        }

        // Cheap pre-check on the declared length, so an oversized body is
        // refused before a single byte of it is buffered.
        $declared = $request->getHeaderLine('Content-Length');
        if (ctype_digit($declared) && (int) $declared > $this->config->maxBodyBytes) {
            Log::warn('webhook body too large (declared)', ['declared' => (int) $declared]);

            return $this->json->response(413, ['ok' => false, 'error' => 'payload too large']);
        }

        $body = $this->readBody($request);
        if ($body === null) {
            Log::warn('webhook body too large (streamed)');

            return $this->json->response(413, ['ok' => false, 'error' => 'payload too large']);
        }

        // ── authentication ──────────────────────────────────────────────
        $verdict = $this->verifier->verify($body, $this->headerOrNull($request, 'X-Hub-Signature-256'));

        if ($verdict === SignatureVerifier::NOT_CONFIGURED) {
            // Fail closed, and say so loudly: an unconfigured secret is an
            // operator error, and accepting unsigned deliveries "until it is
            // fixed" would be an open door to the job queue.
            Log::error('webhook rejected: no webhook secret configured', [
                'hint' => 'write .switchboard/webhook_secret or set SWITCHBOARD_WEBHOOK_SECRET',
            ]);

            return $this->json->response(500, ['ok' => false, 'error' => 'webhook secret not configured']);
        }

        if ($verdict !== SignatureVerifier::OK) {
            Log::warn('webhook signature rejected', [
                'reason' => $verdict,
                'delivery' => $this->safeDeliveryId($request),
            ]);

            // One status and one message for every failure mode. The reason
            // goes to the log, where it helps an operator, and not to the
            // response, where it would only help someone probing.
            return $this->json->response(401, ['ok' => false, 'error' => 'invalid signature']);
        }

        // ── identity ────────────────────────────────────────────────────
        $deliveryId = $this->headerOrNull($request, 'X-GitHub-Delivery');
        if ($deliveryId === null || !DeliveryLog::isValidId($deliveryId)) {
            Log::warn('webhook missing or malformed delivery id');

            return $this->json->response(400, ['ok' => false, 'error' => 'missing or malformed X-GitHub-Delivery']);
        }

        $event = $request->getHeaderLine('X-GitHub-Event');

        // GitHub sends `ping` when the webhook is created; answering it is how
        // the App's configuration page reports the endpoint as healthy.
        if ($event === 'ping') {
            Log::info('webhook ping', ['delivery' => $deliveryId]);

            return $this->json->response(200, ['ok' => true, 'pong' => true]);
        }

        if (!in_array($event, self::HANDLED_EVENTS, true)) {
            // 2xx, not an error: the delivery was valid, we simply do not act
            // on it. A non-2xx would make GitHub retry forever and show the App
            // as failing.
            return $this->json->response(202, ['ok' => true, 'ignored' => 'event', 'event' => $event]);
        }

        // ── payload ─────────────────────────────────────────────────────
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            Log::warn('webhook payload is not a JSON object', ['delivery' => $deliveryId]);

            return $this->json->response(400, ['ok' => false, 'error' => 'payload is not a JSON object']);
        }

        // Checked before full validation because it is the common case:
        // `edited`, `labeled`, `review_requested` and friends vastly outnumber
        // the four actions that mean anything to us.
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : '';
        if (!in_array($action, [...PullRequestEvent::DEPLOY_ACTIONS, ...PullRequestEvent::TEARDOWN_ACTIONS], true)) {
            return $this->json->response(202, ['ok' => true, 'ignored' => 'action', 'action' => $action]);
        }

        try {
            $pr = PullRequestEvent::fromPayload($payload, $this->config->githubHost);
        } catch (InvalidPayloadException $e) {
            Log::warn('webhook payload rejected', ['delivery' => $deliveryId, 'reason' => $e->getMessage()]);

            return $this->json->response(400, ['ok' => false, 'error' => 'invalid payload: ' . $e->getMessage()]);
        }

        // ── policy ──────────────────────────────────────────────────────
        if (!$this->config->repoAllowed($pr->repoFullName)) {
            Log::warn('webhook repository not allowed', ['delivery' => $deliveryId, 'repo' => $pr->repoFullName]);

            return $this->json->response(202, ['ok' => true, 'ignored' => 'repository', 'repo' => $pr->repoFullName]);
        }

        // A fork PR's head is code from outside the organisation, and the
        // daemon runs the checked-out manifest's `build:` and `seed:` commands
        // with the operator's secrets resolved into the preview environment.
        // Building it is a decision, not a default. Teardown stays allowed:
        // removing a preview is safe, and refusing would leak previews forever.
        if ($pr->fork && !$this->config->allowForks && $pr->intent() === 'deploy') {
            Log::warn('webhook fork deploy refused', [
                'delivery' => $deliveryId,
                'repo' => $pr->repoFullName,
                'head_repo' => $pr->headRepoFullName,
                'pr' => $pr->number,
            ]);

            return $this->json->response(202, [
                'ok' => true,
                'ignored' => 'fork',
                'hint' => 'set SWITCHBOARD_ALLOW_FORKS=true to build pull requests from forks',
            ]);
        }

        $intent = $pr->intent();
        if ($intent === null) {
            return $this->json->response(202, ['ok' => true, 'ignored' => 'action', 'action' => $pr->action]);
        }

        // ── dedup, then enqueue ─────────────────────────────────────────
        try {
            $claimed = $this->deliveries->claim($deliveryId);
        } catch (StorageException $e) {
            Log::error('delivery marker could not be created', [
                'delivery' => $deliveryId,
                'reason' => $e->getMessage(),
            ]);

            // 500 so GitHub redelivers. Enqueueing without a marker would risk
            // a duplicate deploy; dropping it would lose the delivery entirely.
            return $this->json->response(500, ['ok' => false, 'error' => 'queue unavailable']);
        }

        if (!$claimed) {
            Log::info('duplicate delivery ignored', [
                'delivery' => $deliveryId,
                'repo' => $pr->repoFullName,
                'pr' => $pr->number,
            ]);

            return $this->json->response(200, ['ok' => true, 'duplicate' => true, 'delivery' => $deliveryId]);
        }

        $millis = (int) round(microtime(true) * 1000);
        $jobId = JobQueue::jobId($millis, $deliveryId);

        try {
            $filename = $this->queue->enqueue(Job::build($pr, $jobId, $deliveryId, $intent, $millis), $jobId);
            $this->deliveries->markQueued($deliveryId, $filename);
        } catch (\Throwable $e) {
            // Release the claim so GitHub's redelivery gets another chance;
            // otherwise the marker would suppress the retry of a delivery that
            // never produced a job.
            $this->deliveries->release($deliveryId);
            Log::error('enqueue failed', ['delivery' => $deliveryId, 'reason' => $e->getMessage()]);

            return $this->json->response(500, ['ok' => false, 'error' => 'could not enqueue job']);
        }

        Log::info('job enqueued', [
            'delivery' => $deliveryId,
            'job_id' => $jobId,
            'repo' => $pr->repoFullName,
            'pr' => $pr->number,
            'action' => $pr->action,
            'intent' => $intent,
            'label' => $pr->label(),
        ]);

        $this->maybePrune();

        return $this->json->response(202, [
            'ok' => true,
            'job_id' => $jobId,
            'job_file' => $filename,
            'intent' => $intent,
            'label' => $pr->label(),
        ]);
    }

    /**
     * The raw request body, or null if it exceeds the ceiling.
     *
     * Read from the PSR-7 stream in bounded chunks rather than with
     * `(string) $request->getBody()`, which has no ceiling and would fully
     * buffer a hostile body before anything could reject it.
     *
     * This is also what makes the app work identically in fpm and worker mode:
     * under fpm the stream is Diactoros's re-readable `php://input` wrapper,
     * under `ephpm/psr15-worker` it is the engine's buffered body. Neither the
     * handler nor the signature check needs to know which.
     */
    private function readBody(ServerRequestInterface $request): ?string
    {
        $stream = $request->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';
        while (!$stream->eof()) {
            $body .= $stream->read(65536);
            if (strlen($body) > $this->config->maxBodyBytes) {
                return null;
            }
        }

        return $body;
    }

    /**
     * GitHub webhooks may be configured to send `application/json` or
     * `application/x-www-form-urlencoded`. Only the former works here: for the
     * form encoding PHP consumes `php://input` to populate `$_POST`, and the
     * exact bytes the signature covers are no longer readable.
     *
     * Detected explicitly rather than left to surface as a signature mismatch,
     * because that misdiagnosis costs an operator an afternoon.
     */
    private function checkContentType(ServerRequestInterface $request): ?ResponseInterface
    {
        $raw = strtolower(trim($request->getHeaderLine('Content-Type')));
        $type = trim(explode(';', $raw)[0]);

        if ($type === 'application/json') {
            return null;
        }

        Log::warn('webhook rejected: unsupported content type', ['content_type' => $type]);

        return $this->json->response(415, [
            'ok' => false,
            'error' => 'content type must be application/json',
            'hint' => 'set the GitHub App webhook content type to application/json',
        ]);
    }

    private function headerOrNull(ServerRequestInterface $request, string $name): ?string
    {
        return $request->hasHeader($name) ? $request->getHeaderLine($name) : null;
    }

    /** The delivery id for logging, before it has been validated. */
    private function safeDeliveryId(ServerRequestInterface $request): ?string
    {
        $id = $this->headerOrNull($request, 'X-GitHub-Delivery');

        return $id !== null && DeliveryLog::isValidId($id) ? $id : null;
    }

    /**
     * Occasionally trim old delivery markers.
     *
     * There is no cron inside a vhost, so retention has to ride on request
     * traffic. Bounded work on a small fraction of requests keeps it off the
     * latency budget GitHub's 10-second timeout defines.
     */
    private function maybePrune(): void
    {
        if (random_int(1, self::PRUNE_ODDS) !== 1) {
            return;
        }

        $removed = $this->deliveries->prune();
        if ($removed > 0) {
            Log::info('pruned delivery markers', ['removed' => $removed]);
        }
    }
}
