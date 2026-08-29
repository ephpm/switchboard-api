<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Tests\Support\FakeKvClient;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/**
 * Cluster mode: the webhook publishes desired state to the KV instead of
 * writing to this node's local `queue/`. See
 * `Switchboard\Cluster\ClusterState` for the key layout and
 * `Switchboard\Tests\Support\FakeKvClient` for why these tests inject a fake
 * rather than relying on `function_exists('ephpm_kv_set')`.
 */
final class ClusterWebhookTest extends TestCase
{
    /** @return list<string> */
    private function jobs(string $stateDir): array
    {
        return glob($stateDir . '/queue/*.json') ?: [];
    }

    private function decodeKv(FakeKvClient $kv, string $key): mixed
    {
        $raw = $kv->raw($key);
        $this->assertNotNull($raw, "expected {$key} to be set");

        return json_decode((string) $raw, true);
    }

    public function testValidSignaturePublishesDesiredStateAndWritesNoLocalJob(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);

        $response = $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody()));

        $this->assertSame(202, $response->getStatusCode());
        $payload = Fixtures::decode($response);
        $this->assertTrue($payload['ok']);
        $this->assertSame('cluster', $payload['mode']);
        $this->assertNull($payload['job_file']);

        // No local queue file at all — that is the entire point of cluster mode.
        $this->assertSame([], $this->jobs($dir));

        $label = 'ephpm-wordpress-sample-pr-42';
        $preview = $this->decodeKv($kv, 'switchboard:preview:' . $label);
        $this->assertSame(1, $preview['schema']);
        $this->assertSame('deploy', $preview['intent']);
        $this->assertSame($label, $preview['preview']['label']);
        $this->assertSame('ephpm/wordpress-sample', $preview['repository']['full_name']);
        $this->assertSame(12345, $preview['installation_id']);

        $index = $this->decodeKv($kv, 'switchboard:index');
        $this->assertSame([$label], $index);

        $this->assertSame('1', $kv->raw('switchboard:gen'));
    }

    public function testGenerationBumpsOnEveryPublishAndIndexCollectsBothLabels(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(['action' => 'synchronize']),
            deliveryId: 'dddddddd-0000-0000-0000-000000000001',
        ));
        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody([
                'number' => 43,
                'pull_request' => ['number' => 43],
            ]),
            deliveryId: 'dddddddd-0000-0000-0000-000000000002',
        ));

        $this->assertSame('2', $kv->raw('switchboard:gen'));

        $index = $this->decodeKv($kv, 'switchboard:index');
        sort($index);
        $this->assertSame(['ephpm-wordpress-sample-pr-42', 'ephpm-wordpress-sample-pr-43'], $index);
    }

    public function testTwoDeliveriesForTheSameLabelDoNotDuplicateTheIndexEntry(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);

        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(['action' => 'synchronize']),
            deliveryId: 'eeeeeeee-0000-0000-0000-000000000001',
        ));
        $app->handle(Fixtures::webhookRequest(
            Fixtures::pullRequestBody(['action' => 'synchronize']),
            deliveryId: 'eeeeeeee-0000-0000-0000-000000000002',
        ));

        $index = $this->decodeKv($kv, 'switchboard:index');
        $this->assertSame(['ephpm-wordpress-sample-pr-42'], $index);
    }

    /**
     * The webhook path must NOT prune the index on teardown — only a drain
     * that has actually queued the teardown locally does that (see
     * `DrainTest`), so every node gets a chance to observe it first.
     */
    public function testTeardownIsPublishedButLabelStaysInTheIndex(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);

        $response = $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody(['action' => 'closed'])));

        $this->assertSame(202, $response->getStatusCode());
        $label = 'ephpm-wordpress-sample-pr-42';

        $preview = $this->decodeKv($kv, 'switchboard:preview:' . $label);
        $this->assertSame('teardown', $preview['intent']);

        $index = $this->decodeKv($kv, 'switchboard:index');
        $this->assertSame([$label], $index);
    }

    public function testRejectedDeliveryPublishesNothing(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        $body = Fixtures::pullRequestBody();

        $response = $app->handle(Fixtures::webhookRequest($body, signature: Fixtures::sign($body, 'wrong-secret')));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame([], $kv->keys());
    }

    public function testDuplicateDeliveryDoesNotPublishTwice(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);
        $body = Fixtures::pullRequestBody();

        $first = $app->handle(Fixtures::webhookRequest($body));
        $second = $app->handle(Fixtures::webhookRequest($body));

        $this->assertSame(202, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame('1', $kv->raw('switchboard:gen'));
    }

    /** A fork deploy refused by policy must still publish nothing, same as single-node. */
    public function testForkDeployRefusedByPolicyPublishesNothing(): void
    {
        $dir = Fixtures::tempDir();
        $kv = new FakeKvClient();
        $app = Fixtures::app($dir, [], $kv);

        $response = $app->handle(Fixtures::webhookRequest(Fixtures::pullRequestBody([
            'pull_request' => ['head' => ['repo' => [
                'full_name' => 'outsider/wordpress-sample',
                'clone_url' => 'https://github.com/outsider/wordpress-sample.git',
            ]]],
        ])));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('fork', Fixtures::decode($response)['ignored']);
        $this->assertSame([], $kv->keys());
    }
}
