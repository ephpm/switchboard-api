<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Tests\Support\FakeKvClient;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/**
 * `GET /drain` — the localhost-only endpoint that reconciles cluster-mode
 * desired state into this node's own queue. See
 * `Switchboard\Cluster\DrainHandler`.
 */
final class DrainTest extends TestCase
{
    /** @return list<string> */
    private function jobs(string $stateDir): array
    {
        $entries = glob($stateDir . '/queue/*.json') ?: [];
        sort($entries);

        return $entries;
    }

    // ── gating: peer, token, secret file ────────────────────────────────

    public function testNonLoopbackAddressIs404(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        Fixtures::writeDrainSecret($dir);

        $response = $app->handle(Fixtures::drainRequest(remoteAddr: '10.0.0.5'));

        $this->assertSame(404, $response->getStatusCode());
        // Identical body to any other unmatched route — see the class doc on why.
        $this->assertSame(['ok' => false, 'error' => 'not found'], Fixtures::decode($response));
    }

    public function testMissingTokenHeaderIs404(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        Fixtures::writeDrainSecret($dir);

        $response = $app->handle(Fixtures::drainRequest(token: null));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testWrongTokenIs404(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        Fixtures::writeDrainSecret($dir);

        $response = $app->handle(Fixtures::drainRequest(token: 'not-the-right-token'));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testEmptySecretFileIs404EvenWithTheUsualToken(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        file_put_contents($dir . '/drain_secret', '');

        $response = $app->handle(Fixtures::drainRequest());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMissingSecretFileIs404(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        // No drain_secret written at all — Paths::ensure() only creates
        // directories, never this file.

        $response = $app->handle(Fixtures::drainRequest());

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCorrectTokenFromLoopbackPasses(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        Fixtures::writeDrainSecret($dir);

        $response = $app->handle(Fixtures::drainRequest());

        $this->assertSame(200, $response->getStatusCode());
    }

    /** Rotation: same multi-line, `#`-comment format as `webhook_secret`. */
    public function testSecretFileSupportsMultipleLinesForRotation(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir, [], new FakeKvClient());
        file_put_contents($dir . '/drain_secret', "# rotating\nold-secret\nnew-secret\n");

        $old = $app->handle(Fixtures::drainRequest(token: 'old-secret'));
        $new = $app->handle(Fixtures::drainRequest(token: 'new-secret'));

        $this->assertSame(200, $old->getStatusCode());
        $this->assertSame(200, $new->getStatusCode());
    }

    // ── single-node: a no-op ─────────────────────────────────────────────

    public function testSingleNodeModeIsANoOp(): void
    {
        $dir = Fixtures::tempDir();
        $app = Fixtures::app($dir); // no KvClient => single-node
        Fixtures::writeDrainSecret($dir);

        $response = $app->handle(Fixtures::drainRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['ok' => true, 'mode' => 'single-node', 'queued' => 0], Fixtures::decode($response));
    }

    // ── materialization ──────────────────────────────────────────────────

    public function testMaterializesOneJobFilePerChangedLabelAndIsIdempotentOnReKick(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));
        $this->assertSame([], $this->jobs($dir), 'cluster mode must not write a local job at webhook time');

        $first = $app->handle(Fixtures::drainRequest());
        $firstBody = Fixtures::decode($first);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(1, $firstBody['checked']);
        $this->assertSame(1, $firstBody['queued']);
        $this->assertSame(1, count($this->jobs($dir)));

        $job = json_decode((string) file_get_contents($this->jobs($dir)[0]), true);
        $this->assertSame('deploy', $job['intent']);
        $this->assertSame('ephpm-wordpress-sample-pr-42', $job['preview']['label']);
        // A freshly generated job_id/filename, not the webhook's original —
        // this is a per-node materialization, not a redelivery.
        $this->assertMatches('/^\d{13}-[0-9a-f]{16}$/', $job['job_id']);

        // Re-kick with nothing new: `gen` is unchanged, so the fast path
        // returns before the index is even read.
        $second = $app->handle(Fixtures::drainRequest());
        $secondBody = Fixtures::decode($second);

        $this->assertSame(0, $secondBody['checked']);
        $this->assertSame(0, $secondBody['queued']);
        $this->assertSame(1, count($this->jobs($dir)), 'a re-kick with nothing new must not queue a second job');
    }

    /**
     * `gen` changing (because of an unrelated label) forces a full
     * reconciliation pass, but the per-label `applied/` marker still makes
     * the unchanged label a no-op within that pass.
     */
    public function testReKickAfterAnUnrelatedChangeStillSkipsTheUnchangedLabel(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            deliveryId: 'ffffffff-0000-0000-0000-000000000001',
        ));
        $app->handle(Fixtures::drainRequest());
        $this->assertSame(1, count($this->jobs($dir)));

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(['number' => 99, 'pull_request' => ['number' => 99]]),
            deliveryId: 'ffffffff-0000-0000-0000-000000000002',
        ));

        $response = $app->handle(Fixtures::drainRequest());
        $body = Fixtures::decode($response);

        $this->assertSame(2, $body['checked']);
        $this->assertSame(1, $body['queued']);
        $this->assertSame(2, count($this->jobs($dir)));
    }

    public function testTeardownIsQueuedThenPrunedFromTheIndex(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])));

        $label = 'ephpm-wordpress-sample-pr-42';
        $this->assertSame([$label], json_decode((string) $kv->raw('switchboard:index'), true));

        $response = $app->handle(Fixtures::drainRequest());
        $body = Fixtures::decode($response);

        $this->assertSame(1, $body['queued']);
        $this->assertSame(1, count($this->jobs($dir)));

        $job = json_decode((string) file_get_contents($this->jobs($dir)[0]), true);
        $this->assertSame('teardown', $job['intent']);

        // Pruned from the shared index and the preview key deleted, now that
        // a node has actually queued the teardown locally.
        $this->assertSame([], json_decode((string) $kv->raw('switchboard:index'), true));
        $this->assertNull($kv->raw('switchboard:preview:' . $label));
    }

    /** A deploy and a later teardown of the same label are two distinct markers, so both queue. */
    public function testDeployThenLaterTeardownOfTheSameLabelBothQueue(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(),
            deliveryId: 'aaaaaaaa-1111-1111-1111-000000000010',
        ));
        $app->handle(Fixtures::drainRequest());
        $this->assertSame(1, count($this->jobs($dir)));

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(['action' => 'closed']),
            deliveryId: 'aaaaaaaa-1111-1111-1111-000000000011',
        ));
        $response = $app->handle(Fixtures::drainRequest());
        $body = Fixtures::decode($response);

        $this->assertSame(1, $body['queued']);
        $this->assertSame(2, count($this->jobs($dir)));

        $intents = array_map(
            static fn (string $path): mixed => json_decode((string) file_get_contents($path), true)['intent'],
            $this->jobs($dir),
        );
        sort($intents);
        $this->assertSame(['deploy', 'teardown'], $intents);
    }

    public function testInvalidIndexEntryIsSkippedRatherThanUsedAsAPathComponent(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        // Simulate a corrupted/attacker-influenced index entry directly,
        // bypassing PreviewLabel::build() entirely.
        $kv->set('switchboard:index', json_encode(['../../etc/passwd']));
        $kv->incr('switchboard:gen');

        $response = $app->handle(Fixtures::drainRequest());
        $body = Fixtures::decode($response);

        $this->assertSame(0, $body['checked']);
        $this->assertSame(0, $body['queued']);
        $this->assertSame([], $this->jobs($dir));
    }
}
