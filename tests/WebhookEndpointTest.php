<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/**
 * The webhook endpoint end to end: a signed PSR-7 request in, a job file on
 * disk out. Everything below the PSR-15 handler is real — the same code path a
 * live delivery takes, minus the socket.
 */
final class WebhookEndpointTest extends TestCase
{
    /** @return list<string> Job filenames currently in the queue. */
    private function jobs(string $stateDir): array
    {
        $entries = glob($stateDir . '/queue/*.json') ?: [];
        sort($entries);

        return array_map(basename(...), $entries);
    }

    /** The single job in the queue, failing loudly if there is not exactly one. */
    private function onlyJob(string $stateDir): array
    {
        $jobs = $this->jobs($stateDir);
        if (count($jobs) !== 1) {
            $this->fail('expected exactly one job, found ' . count($jobs));
        }

        return json_decode((string) file_get_contents($stateDir . '/queue/' . $jobs[0]), true);
    }

    // ── the happy path ──────────────────────────────────────────────────

    public function testValidSignatureEnqueuesAJob(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);

        $response = $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame(1, count($this->jobs($dir)));

        $payload = Fixtures::decode($response);
        $this->assertTrue($payload['ok']);
        $this->assertSame('deploy', $payload['intent']);
        $this->assertSame('ephpm-wordpress-sample-pr-42', $payload['label']);
    }

    public function testJobCarriesEverythingTheDaemonNeeds(): void
    {
        $dir = Fixtures::tempDir();
        Fixtures::app($dir)->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));
        $job = $this->onlyJob($dir);

        $this->assertSame(1, $job['schema']);
        $this->assertSame('pull_request', $job['event']);
        $this->assertSame('opened', $job['action']);
        $this->assertSame('deploy', $job['intent']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $job['delivery_id']);

        $this->assertSame('ephpm-wordpress-sample-pr-42', $job['preview']['label']);

        $this->assertSame('ephpm/wordpress-sample', $job['repository']['full_name']);
        $this->assertSame('ephpm', $job['repository']['owner']);
        $this->assertSame('wordpress-sample', $job['repository']['name']);
        $this->assertSame('https://github.com/ephpm/wordpress-sample.git', $job['repository']['clone_url']);

        $this->assertSame(42, $job['pull_request']['number']);
        $this->assertSame('feature/new-header', $job['pull_request']['head']['ref']);
        $this->assertSame('a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4', $job['pull_request']['head']['sha']);
        $this->assertSame('refs/pull/42/head', $job['pull_request']['head']['pull_ref']);
        $this->assertSame('main', $job['pull_request']['base']['ref']);
        $this->assertFalse($job['pull_request']['fork']);

        // The daemon needs this to mint its own installation token — this
        // service never calls the GitHub API and holds no App key.
        $this->assertSame(12345, $job['installation_id']);
        $this->assertMatches('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $job['received_at']);
    }

    public function testClosedPullRequestEnqueuesATeardown(): void
    {
        $dir = Fixtures::tempDir();
        $response = Fixtures::app($dir)->handle(
            Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])),
        );

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('teardown', $this->onlyJob($dir)['intent']);
    }

    // ── rejection: authentication ───────────────────────────────────────

    public function testWrongSignatureIsRejectedAndQueuesNothing(): void
    {
        $dir = Fixtures::tempDir();
        $body = Fixtures::pullRequestBody();

        $response = Fixtures::app($dir)->handle(
            Fixtures::webhookRequest($body, signature: Fixtures::sign($body, 'wrong-secret')),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir), 'a rejected delivery must not queue anything');
    }

    public function testMissingSignatureIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['X-Hub-Signature-256' => null],
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    /**
     * Sign one body, send another — the replay-with-modification case: a
     * captured signature applied to a payload that points somewhere else.
     */
    public function testBodyTamperedAfterSigningIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $signed = Fixtures::pullRequestBody();
        $tampered = Fixtures::pullRequestBody([
            'repository' => [
                'full_name' => 'attacker/evil',
                'name' => 'evil',
                'owner' => ['login' => 'attacker'],
                'clone_url' => 'https://github.com/attacker/evil.git',
            ],
        ]);

        $response = Fixtures::app($dir)->handle(
            Fixtures::webhookRequest($tampered, signature: Fixtures::sign($signed)),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    /** A single flipped byte in an otherwise valid payload must not verify. */
    public function testSingleByteTamperIsRejected(): void
    {
        $dir = Fixtures::tempDir();
        $body = Fixtures::pullRequestBody();
        $signature = Fixtures::sign($body);

        $response = Fixtures::app($dir)->handle(
            Fixtures::webhookRequest(str_replace('"number":42', '"number":43', $body), signature: $signature),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    public function testMalformedSignatureHeaderIsRejected(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);
        $body = Fixtures::pullRequestBody();

        foreach ([
            hash_hmac('sha256', $body, Fixtures::SECRET),          // no prefix
            'sha1=' . hash_hmac('sha1', $body, Fixtures::SECRET),   // deprecated algorithm
            'sha256=',                                              // empty digest
            'sha256=nothex' . str_repeat('z', 58),
        ] as $bad) {
            $this->assertSame(
                401,
                $app->handle(Fixtures::webhookRequest($body, signature: $bad))->getStatusCode(),
                'accepted a malformed signature: ' . $bad,
            );
        }

        $this->assertSame([], $this->jobs($dir));
    }

    public function testNoConfiguredSecretRejectsEverything(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, ['webhookSecrets' => []]);

        // Even a signature correct for the *usual* secret must fail: with
        // nothing configured there is nothing to be correct against.
        $response = $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    // ── deduplication ───────────────────────────────────────────────────

    public function testDuplicateDeliveryDoesNotQueueASecondJob(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);
        $body = Fixtures::pullRequestBody();

        $first = $app->handle(Fixtures::webhookRequest($body));
        $second = $app->handle(Fixtures::webhookRequest($body));

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertTrue(Fixtures::decode($second)['duplicate']);
        $this->assertSame(1, count($this->jobs($dir)), 'a redelivery must not queue a second job');
    }

    /**
     * GitHub's redelivery replays the *whole* request — signature, headers and
     * body all identical — so nothing but the delivery id can distinguish it.
     * That is exactly why dedup keys on that header.
     */
    public function testRedeliveryOfAnIdenticalRequestIsIdempotent(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);

        for ($i = 0; $i < 5; $i++) {
            $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));
        }

        $this->assertSame(1, count($this->jobs($dir)));
    }

    public function testDifferentDeliveryIdQueuesAnotherJob(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);
        $body = Fixtures::pullRequestBody(['action' => 'synchronize']);

        $app->handle(Fixtures::webhookRequest($body, deliveryId: 'aaaaaaaa-0000-0000-0000-000000000001'));
        $app->handle(Fixtures::webhookRequest($body, deliveryId: 'aaaaaaaa-0000-0000-0000-000000000002'));

        $this->assertSame(2, count($this->jobs($dir)), 'distinct deliveries are distinct jobs');
    }

    public function testMissingDeliveryIdIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['X-GitHub-Delivery' => null],
        ));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    public function testMalformedDeliveryIdIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['X-GitHub-Delivery' => '../../../etc/passwd'],
        ));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    // ── event and action filtering ──────────────────────────────────────

    public function testPingIsAcknowledgedWithoutAJob(): void
    {
        $dir = Fixtures::tempDir();
        $body = '{"zen":"Non-blocking is better than blocking.","hook_id":1}';

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest($body, event: 'ping'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(Fixtures::decode($response)['pong']);
        $this->assertSame([], $this->jobs($dir));
    }

    public function testUnwantedEventTypesAreAcknowledgedCheaply(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);

        foreach (['push', 'issues', 'star', 'workflow_run', 'check_suite'] as $event) {
            $response = $app->handle(Fixtures::webhookRequest('{"whatever":true}', event: $event));

            // 2xx, not 4xx: the delivery was valid, we just do not act on it. A
            // non-2xx would make GitHub retry forever and mark the App failing.
            $this->assertSame(202, $response->getStatusCode(), 'event ' . $event);
            $this->assertSame('event', Fixtures::decode($response)['ignored']);
        }

        $this->assertSame([], $this->jobs($dir));
    }

    /**
     * An unauthenticated caller must not learn which events we act on:
     * signature verification comes first, so every unsigned probe gets the same
     * 401 regardless of event type.
     */
    public function testUnsignedProbesCannotDistinguishHandledEvents(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);

        foreach (['pull_request', 'push', 'ping', 'nonsense'] as $event) {
            $response = $app->handle(Fixtures::webhookRequest(
                '{"action":"opened"}',
                event: $event,
                headerOverrides: ['X-Hub-Signature-256' => null],
            ));

            $this->assertSame(401, $response->getStatusCode(), 'event ' . $event);
            $this->assertSame('invalid signature', Fixtures::decode($response)['error']);
        }
    }

    public function testUninterestingActionsAreIgnored(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir);

        foreach (['edited', 'labeled', 'review_requested', 'assigned', 'converted_to_draft'] as $action) {
            $response = $app->handle(Fixtures::webhookRequest(
                Fixtures::pullRequestBody(['action' => $action]),
                deliveryId: 'bbbbbbbb-0000-0000-0000-' . substr(md5($action), 0, 12),
            ));

            $this->assertSame(202, $response->getStatusCode(), 'action ' . $action);
            $this->assertSame('action', Fixtures::decode($response)['ignored']);
        }

        $this->assertSame([], $this->jobs($dir));
    }

    // ── request shape ───────────────────────────────────────────────────

    public function testFormEncodedContentTypeIsRejectedWithAnActionableError(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['Content-Type' => 'application/x-www-form-urlencoded'],
        ));

        // Not a signature failure: PHP would have consumed php://input to build
        // $_POST, so the raw bytes are gone. Saying so plainly is worth an
        // afternoon of someone's debugging.
        $this->assertSame(415, $response->getStatusCode());
        $this->assertStringContains('application/json', Fixtures::decode($response)['hint']);
    }

    public function testContentTypeParametersAreTolerated(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['Content-Type' => 'application/json; charset=utf-8'],
        ));

        $this->assertSame(202, $response->getStatusCode());
    }

    public function testGetIsNotAllowed(): void
    {
        $dir = Fixtures::tempDir();
        $response = Fixtures::app($dir)->handle(Fixtures::request('GET', '/webhook'));

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testOversizedDeclaredBodyIsRejectedBeforeReading(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir, ['maxBodyBytes' => 1024])->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            headerOverrides: ['Content-Length' => '99999999'],
        ));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    /** A truthful Content-Length that is still over the cap. */
    public function testOversizedActualBodyIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir, ['maxBodyBytes' => 64])
            ->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    // ── payload validation ──────────────────────────────────────────────

    public function testNonJsonBodyIsRejected(): void
    {
        $dir = Fixtures::tempDir();
        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest('this is not json'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    public function testStructurallyInvalidPayloadIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        // Signed correctly, but the fields the daemon needs are missing.
        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest('{"action":"opened","number":1}'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    public function testDangerousBranchNameIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        // A ref `git` would read as an option rather than a branch.
        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'pull_request' => ['head' => ['ref' => '--upload-pack=/bin/sh']],
        ])));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    public function testCloneUrlOnAnotherHostIsRejected(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'pull_request' => ['head' => ['repo' => [
                'full_name' => 'ephpm/wordpress-sample',
                'clone_url' => 'https://evil.example.com/ephpm/wordpress-sample.git',
            ]]],
        ])));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame([], $this->jobs($dir));
    }

    // ── policy ──────────────────────────────────────────────────────────

    public function testForkPullRequestIsRefusedByDefault(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'pull_request' => ['head' => ['repo' => [
                'full_name' => 'outsider/wordpress-sample',
                'clone_url' => 'https://github.com/outsider/wordpress-sample.git',
            ]]],
        ])));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('fork', Fixtures::decode($response)['ignored']);
        $this->assertSame([], $this->jobs($dir), 'a fork must not be built without opt-in');
    }

    public function testForkPullRequestIsQueuedWhenExplicitlyAllowed(): void
    {
        $dir = Fixtures::tempDir();

        Fixtures::app($dir, ['allowForks' => true])->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'pull_request' => ['head' => ['repo' => [
                'full_name' => 'outsider/wordpress-sample',
                'clone_url' => 'https://github.com/outsider/wordpress-sample.git',
            ]]],
        ])));

        $job = $this->onlyJob($dir);
        $this->assertTrue($job['pull_request']['fork']);
        $this->assertSame('outsider/wordpress-sample', $job['pull_request']['head']['repo_full_name']);
        $this->assertSame('https://github.com/outsider/wordpress-sample.git', $job['pull_request']['head']['clone_url']);
    }

    /** Tearing a fork preview down is always allowed — refusing would leak previews. */
    public function testForkTeardownIsAllowedEvenWhenForkBuildsAreNot(): void
    {
        $dir = Fixtures::tempDir();

        $response = Fixtures::app($dir)->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'action' => 'closed',
            'pull_request' => ['head' => ['repo' => [
                'full_name' => 'outsider/wordpress-sample',
                'clone_url' => 'https://github.com/outsider/wordpress-sample.git',
            ]]],
        ])));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('teardown', $this->onlyJob($dir)['intent']);
    }

    public function testRepositoryAllowlistIsEnforced(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, ['allowedRepos' => ['ephpm/*']]);

        $allowed = $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            deliveryId: 'cccccccc-0000-0000-0000-000000000001',
        ));
        $this->assertSame(202, $allowed->getStatusCode());

        $blocked = $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody([
                'repository' => [
                    'full_name' => 'someoneelse/app',
                    'name' => 'app',
                    'owner' => ['login' => 'someoneelse'],
                    'clone_url' => 'https://github.com/someoneelse/app.git',
                ],
                'pull_request' => ['head' => ['repo' => [
                    'full_name' => 'someoneelse/app',
                    'clone_url' => 'https://github.com/someoneelse/app.git',
                ]]],
            ]),
            deliveryId: 'cccccccc-0000-0000-0000-000000000002',
        ));
        $this->assertSame('repository', Fixtures::decode($blocked)['ignored']);
        $this->assertSame(1, count($this->jobs($dir)));
    }

    // ── other routes ────────────────────────────────────────────────────

    public function testHealthzIsUnauthenticated(): void
    {
        $dir = Fixtures::tempDir();
        $response = Fixtures::app($dir)->handle(Fixtures::request('GET', '/healthz'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(Fixtures::decode($response)['webhook_configured']);
    }

    public function testHealthzReportsAMissingSecret(): void
    {
        $dir = Fixtures::tempDir();
        $response = Fixtures::app($dir, ['webhookSecrets' => []])->handle(Fixtures::request('GET', '/healthz'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse(Fixtures::decode($response)['webhook_configured']);
    }

    public function testUnknownRouteIs404(): void
    {
        $dir = Fixtures::tempDir();

        $this->assertSame(404, Fixtures::app($dir)->handle(Fixtures::request('GET', '/nope'))->getStatusCode());
        // The removed read API and dashboard must be gone, not dormant.
        $this->assertSame(404, Fixtures::app($dir)->handle(Fixtures::request('GET', '/api/previews'))->getStatusCode());
        $this->assertSame(404, Fixtures::app($dir)->handle(Fixtures::request('GET', '/'))->getStatusCode());
    }
}
