<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Cluster\ClusterState;
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

        // Re-kick with nothing new: since issue #4 the drain always reconciles
        // on content rather than trusting `gen == last_gen`, so it DOES re-walk
        // the index (checked = 1) — but the per-label `applied/` marker makes
        // the unchanged label a no-op, so it queues nothing and writes no second
        // job. Idempotency now comes from the marker, not from skipping the walk.
        $second = $app->handle(Fixtures::drainRequest());
        $secondBody = Fixtures::decode($second);

        $this->assertSame(1, $secondBody['checked'], 'content is reconciled every kick, not gated on the gen cursor');
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

    public function testTeardownIsQueuedAndRetiredOnATtlNotDeletedOutright(): void
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

        // switchboard#24: this node is done, the other nodes are not. The
        // desired state stays readable — on a clock — and the index still
        // lists it, so a sibling that has not drained yet can still find it.
        $this->assertNotNull(
            $kv->raw('switchboard:preview:' . $label),
            'deleting the key here withdraws the teardown from every node that has not drained yet',
        );
        $this->assertTrue($kv->hasExpiry('switchboard:preview:' . $label));
        $this->assertSame([$label], json_decode((string) $kv->raw('switchboard:index'), true));
    }

    /**
     * The whole incident, reproduced: three nodes, one publish, and a node
     * whose drain tick lands after a sibling's. Before switchboard#24 the
     * late node found the label already gone from the index, queued nothing,
     * and advanced its cursor anyway — leaving the preview served and its
     * tenant database on disk on that node alone.
     */
    public function testATeardownReachesEveryNodeNotJustTheFirstToDrain(): void
    {
        // One shared KV — the gossip-replicated store — and three independent
        // state dirs, which is exactly the real topology.
        $kv = new FakeKvClient();
        $nodes = [];
        foreach (['a', 'b', 'c'] as $name) {
            $dir = Fixtures::tempDir();
            Fixtures::writeDrainSecret($dir);
            $nodes[$name] = ['dir' => $dir, 'app' => Fixtures::app($dir, [], $kv)];
        }

        // The webhook lands on exactly one node — round-robin DNS picks it.
        $nodes['a']['app']->handle(
            Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])),
        );

        // Every node drains, in whatever order. All three must queue it.
        foreach ($nodes as $name => $node) {
            $body = Fixtures::decode($node['app']->handle(Fixtures::drainRequest()));
            $this->assertSame(1, $body['queued'], "node {$name} must queue the teardown");

            $jobs = $this->jobs($node['dir']);
            $this->assertSame(1, count($jobs), "node {$name} must have exactly one job");
            $job = json_decode((string) file_get_contents($jobs[0]), true);
            $this->assertSame('teardown', $job['intent'], "node {$name} queued the wrong intent");
        }
    }

    /**
     * Issue #4: a node reaps a teardown even when its generation cursor already
     * equals the published generation.
     *
     * `switchboard:gen` is one best-effort, gossip-replicated counter, and
     * ClusterState::publish()'s incr is explicitly best-effort — gossip can drop
     * or lag that single increment while the preview-key `set` still replicates.
     * When it does, a node that already advanced `last_gen` to the current `gen`
     * (here: after materializing the deploy) sees `gen == last_gen` even though
     * the preview CONTENT has since flipped deploy→teardown. On the live v0.10.1
     * cluster that left the torn-down preview served on the two nodes that never
     * received the close webhook.
     *
     * The old code short-circuited on `gen == last_gen` and never reaped. This
     * models the divergence directly — flip the preview to teardown WITHOUT
     * bumping `gen` — and asserts the node still queues the teardown. It fails on
     * the pre-#4 code (queued = 0).
     */
    public function testTeardownIsReapedEvenWhenTheGenerationCursorAlreadyMatches(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        // The node deploys the preview and advances its cursor to the current gen.
        $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));
        $app->handle(Fixtures::drainRequest());
        $this->assertSame(1, count($this->jobs($dir)));

        $label = 'ephpm-wordpress-sample-pr-42';
        $genAfterDeploy = $kv->raw('switchboard:gen');
        $applied = @file_get_contents($dir . '/applied/' . $label);
        $this->assertTrue(str_starts_with((string) $applied, 'deploy@'), 'precondition: this node has the deploy applied');

        // The close webhook's teardown desired state replicates (the `set`), but
        // its generation increment is lost/lagged in gossip — so `gen` stays
        // exactly where this node's cursor already sits. Model that by flipping
        // the preview content to teardown WITHOUT bumping the counter.
        $teardown = json_decode((string) $kv->raw('switchboard:preview:' . $label), true);
        $teardown['intent'] = 'teardown';
        $kv->set('switchboard:preview:' . $label, json_encode($teardown, JSON_UNESCAPED_SLASHES));
        $this->assertSame($genAfterDeploy, $kv->raw('switchboard:gen'), 'precondition: gen did NOT move with the flip');

        // Old code: gen == last_gen, so the drain returns without walking and
        // never reaps. Fixed code reconciles on content and queues the teardown.
        $body = Fixtures::decode($app->handle(Fixtures::drainRequest()));
        $this->assertSame(1, $body['queued'], 'the node must reap a teardown even when its gen cursor already matches');

        $intents = array_map(
            static fn (string $p): mixed => json_decode((string) file_get_contents($p), true)['intent'],
            $this->jobs($dir),
        );
        sort($intents);
        $this->assertSame(['deploy', 'teardown'], $intents, 'both the deploy and the later teardown were materialized');
    }

    /** A node that already queued the teardown must not queue it again while the key lives. */
    public function testReDrainingARetiredTeardownIsANoOp(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])));
        $app->handle(Fixtures::drainRequest());
        $this->assertSame(1, count($this->jobs($dir)));

        // Force a full walk rather than the last_gen fast path, so this proves
        // the applied/ marker is doing the suppressing, not the cursor.
        $kv->incr('switchboard:gen');
        $body = Fixtures::decode($app->handle(Fixtures::drainRequest()));

        $this->assertSame(0, $body['queued']);
        $this->assertSame(1, count($this->jobs($dir)), 'the marker must suppress a re-queue');
    }

    /** Once the TTL has run out, the index entry is the only thing left — prune it. */
    public function testAnExpiredPreviewKeyIsPrunedFromTheIndex(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        Fixtures::writeDrainSecret($dir);

        $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])));
        $app->handle(Fixtures::drainRequest());

        $label = 'ephpm-wordpress-sample-pr-42';
        $this->assertSame([$label], json_decode((string) $kv->raw('switchboard:index'), true));

        $kv->advance(ClusterState::RETIRED_PREVIEW_TTL_SECONDS + 1);
        $this->assertNull($kv->raw('switchboard:preview:' . $label));

        $kv->incr('switchboard:gen');
        $app->handle(Fixtures::drainRequest());

        $this->assertSame(
            [],
            json_decode((string) $kv->raw('switchboard:index'), true),
            'the index shrinks only after the desired state is unreachable for everyone',
        );
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
