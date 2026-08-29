<?php

declare(strict_types=1);

namespace Switchboard\Cluster;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Http\Json;
use Switchboard\Log;
use Switchboard\Queue\JobQueue;
use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\Paths;
use Switchboard\Storage\SecretFile;
use Switchboard\Storage\StorageException;
use Switchboard\Webhook\PreviewLabel;

/**
 * `GET /drain` — localhost-only, kicked by this node's switchboard daemon on
 * a timer. Reconciles the gossip-replicated desired state
 * ({@see ClusterState}) into THIS node's own `queue/`, which is the only
 * thing the daemon otherwise ever reads (see `JobQueue`'s doc comment on why
 * the handoff between the API and the daemon is files, not the KV or the
 * database).
 *
 * # Why this can never be reached from the internet
 *
 * Three independent layers, deliberately redundant:
 *
 *  1. **`REMOTE_ADDR` must be `127.0.0.1`.** The NodeBalancer that fronts a
 *     cluster forwards internet traffic to the same listener this vhost
 *     answers on, so this vhost's ePHPm configuration must never put the
 *     NodeBalancer in `trusted_proxies` — if it did, a client-supplied
 *     `X-Forwarded-For` could spoof `REMOTE_ADDR` and defeat this check
 *     entirely. This is the concrete reason that matters operationally, not
 *     just a defence in depth footnote.
 *  2. **A bearer token**, `.switchboard/drain_secret`, compared with
 *     `hash_equals`. Belt and braces against anything else sharing the
 *     loopback interface on the same host.
 *  3. **Fail closed, indistinguishably.** A missing/empty secret file, a
 *     missing header, and a wrong token are all answered exactly like a
 *     request for a path that does not exist: `404`, the same body
 *     `Router` returns for an unmatched route. A probe of this endpoint
 *     learns nothing — the same property the README already claims for the
 *     removed dashboard and read API.
 *
 * # Materialization
 *
 * `switchboard:index` lists every label with published desired state. For
 * each one, this node compares `<intent>@<sha>` (from
 * `switchboard:preview:<label>`) against a local marker at
 * `.switchboard/applied/<label>` — the last `<intent>@<sha>` THIS node has
 * already queued. Different: write a fresh job file, reusing the exact same
 * atomic-write / `<millis>-<16 hex>.json` code the webhook path uses
 * ({@see \Switchboard\Queue\JobQueue}), with a freshly generated
 * `job_id`/filename — this is a per-node materialization of shared state,
 * not a redelivery of the original webhook, so there is nothing to
 * deduplicate against. Same: skip it, which is what makes a re-kick with no
 * changes a no-op. A queued teardown is additionally pruned from the shared
 * index and its preview key deleted — best-effort, and only after the local
 * queue write succeeds, so a crash between the two just costs a redundant
 * (but harmless, marker-suppressed) prune attempt on the next drain.
 *
 * No network calls anywhere in this path. The common case — nothing changed
 * since `.switchboard/last_gen` — is two KV reads.
 */
final class DrainHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Json $json,
        private readonly AtomicWriter $writer,
        private readonly JobQueue $queue,
        private readonly ?ClusterState $cluster,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->clientAddress($request) !== '127.0.0.1') {
            return $this->notFound();
        }

        if (!$this->tokenValid($request)) {
            return $this->notFound();
        }

        if ($this->cluster === null) {
            // Single-node: there is nothing to reconcile. The webhook path
            // already wrote straight to this node's own queue.
            return $this->json->response(200, ['ok' => true, 'mode' => 'single-node', 'queued' => 0]);
        }

        $gen = $this->cluster->generation();
        if ($gen === $this->readLastGen()) {
            return $this->json->response(200, ['ok' => true, 'gen' => $gen, 'checked' => 0, 'queued' => 0]);
        }

        [$checked, $queued] = $this->materialize($this->cluster);
        $this->writeLastGen($gen);

        return $this->json->response(200, ['ok' => true, 'gen' => $gen, 'checked' => $checked, 'queued' => $queued]);
    }

    private function clientAddress(ServerRequestInterface $request): ?string
    {
        $addr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($addr) ? $addr : null;
    }

    private function tokenValid(ServerRequestInterface $request): bool
    {
        $secrets = SecretFile::read($this->paths->drainSecret());
        if ($secrets === []) {
            return false;
        }

        $provided = $request->hasHeader('X-Drain-Token') ? $request->getHeaderLine('X-Drain-Token') : '';
        if ($provided === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals($secret, $provided)) {
                return true;
            }
        }

        return false;
    }

    /** Identical to `Router`'s own 404 body — see the class doc. */
    private function notFound(): ResponseInterface
    {
        return $this->json->response(404, ['ok' => false, 'error' => 'not found']);
    }

    /** @return array{0: int, 1: int} [$checked, $queued] */
    private function materialize(ClusterState $cluster): array
    {
        $checked = 0;
        $queued = 0;

        foreach ($cluster->index() as $label) {
            if (!PreviewLabel::isValid($label)) {
                // A corrupted index entry must never become a path component.
                Log::warn('drain: skipping invalid label in switchboard:index', ['label' => $label]);
                continue;
            }

            $checked++;

            $desired = $cluster->preview($label);
            if ($desired === null) {
                // Index says it exists, preview key does not (raced with a
                // delete, or never written). Nothing to materialize.
                continue;
            }

            $intent = is_string($desired['intent'] ?? null) ? $desired['intent'] : null;
            if ($intent === null) {
                continue;
            }

            $sha = is_string($desired['pull_request']['head']['sha'] ?? null)
                ? $desired['pull_request']['head']['sha']
                : '';
            $marker = $intent . '@' . $sha;
            $markerPath = $this->paths->applied() . DIRECTORY_SEPARATOR . $label;

            if (@file_get_contents($markerPath) === $marker) {
                continue;
            }

            $millis = (int) round(microtime(true) * 1000);
            $jobId = JobQueue::jobId($millis, $label . '|' . $marker . '|' . $millis);
            $job = $desired;
            $job['job_id'] = $jobId;

            try {
                $this->queue->enqueue($job, $jobId);
            } catch (StorageException $e) {
                Log::error('drain: could not enqueue job', ['label' => $label, 'reason' => $e->getMessage()]);
                continue;
            }

            try {
                $this->writer->write($markerPath, $marker);
            } catch (StorageException $e) {
                // The job is queued either way; a failed marker only means
                // the next drain re-queues it, which the daemon's own
                // coalescing (README: "Coalescing is the daemon's job")
                // already has to tolerate for rapid `synchronize` jobs.
                Log::error('drain: could not write applied marker', [
                    'label' => $label,
                    'reason' => $e->getMessage(),
                ]);
            }

            $queued++;

            if ($intent === 'teardown') {
                $cluster->removeFromIndex($label);
                $cluster->deletePreview($label);
            }
        }

        return [$checked, $queued];
    }

    private function readLastGen(): int
    {
        $raw = @file_get_contents($this->paths->lastGen());
        if ($raw === false) {
            return 0;
        }

        $raw = trim($raw);

        return ctype_digit($raw) ? (int) $raw : 0;
    }

    private function writeLastGen(int $gen): void
    {
        try {
            $this->writer->write($this->paths->lastGen(), (string) $gen);
        } catch (StorageException $e) {
            // Not fatal: the applied/ markers already make re-materialization
            // a no-op, so the only cost of losing this write is one extra
            // full index walk on the next drain.
            Log::error('drain: could not persist last_gen', ['reason' => $e->getMessage()]);
        }
    }
}
