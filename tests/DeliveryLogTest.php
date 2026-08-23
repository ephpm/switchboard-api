<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Queue\DeliveryLog;
use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\StorageException;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/** Deduplication on `X-GitHub-Delivery`. */
final class DeliveryLogTest extends TestCase
{
    private function log(?callable $clock = null): array
    {
        $dir = Fixtures::tempDir('deliveries');
        mkdir($dir . '/markers');
        mkdir($dir . '/tmp');

        return [new DeliveryLog($dir . '/markers', new AtomicWriter($dir . '/tmp'), $clock), $dir . '/markers'];
    }

    public function testFirstClaimSucceedsAndSecondIsRefused(): void
    {
        [$log] = $this->log();
        $id = '11111111-2222-3333-4444-555555555555';

        $this->assertTrue($log->claim($id), 'first claim must succeed');
        $this->assertFalse($log->claim($id), 'second claim of the same delivery must be refused');
        $this->assertFalse($log->claim($id), 'and it must stay refused');
    }

    public function testDistinctDeliveriesEachClaim(): void
    {
        [$log] = $this->log();

        $this->assertTrue($log->claim('aaaaaaaa-1111-2222-3333-444444444444'));
        $this->assertTrue($log->claim('bbbbbbbb-1111-2222-3333-444444444444'));
    }

    public function testMarkerSurvivesANewLogInstance(): void
    {
        $dir = Fixtures::tempDir('deliveries');
        mkdir($dir . '/markers');
        mkdir($dir . '/tmp');
        $id = 'cccccccc-1111-2222-3333-444444444444';

        $first = new DeliveryLog($dir . '/markers', new AtomicWriter($dir . '/tmp'));
        $this->assertTrue($first->claim($id));

        // A different request, a different object graph, the same state
        // directory: dedup has to be durable, not per-process. An in-memory or
        // KV-backed check would let this through after a restart.
        $second = new DeliveryLog($dir . '/markers', new AtomicWriter($dir . '/tmp'));
        $this->assertFalse($second->claim($id));
    }

    public function testMarkerFilenameIsHashedNotRaw(): void
    {
        [$log, $markerDir] = $this->log();
        $id = 'dddddddd-1111-2222-3333-444444444444';

        $log->claim($id);

        $entries = array_values(array_diff(scandir($markerDir) ?: [], ['.', '..']));
        $this->assertSame(1, count($entries));
        // The delivery id comes from a request header. Storing it under its
        // digest means the byte string that reaches the filesystem is always 64
        // hex characters, whatever the header contained.
        $this->assertSame(hash('sha256', $id), $entries[0]);
        $this->assertSame(64, strlen($entries[0]));
    }

    public function testReleaseAllowsARetry(): void
    {
        [$log] = $this->log();
        $id = 'eeeeeeee-1111-2222-3333-444444444444';

        $this->assertTrue($log->claim($id));
        $this->assertFalse($log->claim($id));

        // Enqueue failed: the claim is released so GitHub's redelivery can work.
        $log->release($id);
        $this->assertTrue($log->claim($id), 'a released delivery must be claimable again');
    }

    /**
     * The crash window: a marker stuck in `claimed` means a request died before
     * writing its job. After the reclaim window it must be re-claimable, or the
     * PR would never deploy and no redelivery could fix it.
     */
    public function testStaleClaimedMarkerIsReclaimable(): void
    {
        $now = 1_000_000;
        [$log, $markerDir] = $this->log(static function () use (&$now): int { return $now; });
        $id = 'ffffffff-1111-2222-3333-444444444444';

        $this->assertTrue($log->claim($id));
        $this->assertFalse($log->claim($id), 'still fresh — a duplicate');

        // Age the marker past the reclaim window. mtime is what isAbandoned()
        // reads, so it has to move too, not just the injected clock.
        $path = $markerDir . DIRECTORY_SEPARATOR . hash('sha256', $id);
        $now += DeliveryLog::RECLAIM_AFTER_SECONDS + 1;
        touch($path, $now - DeliveryLog::RECLAIM_AFTER_SECONDS - 1);

        $this->assertTrue($log->claim($id), 'an abandoned claim must be reclaimable');
    }

    /**
     * The mirror image: a marker that reached `queued` had its job written. It
     * must stay a duplicate forever, however old it gets — otherwise a manual
     * redelivery days later would deploy twice.
     */
    public function testQueuedMarkerIsNeverReclaimed(): void
    {
        $now = 2_000_000;
        [$log, $markerDir] = $this->log(static function () use (&$now): int { return $now; });
        $id = '99999999-1111-2222-3333-444444444444';

        $this->assertTrue($log->claim($id));
        $log->markQueued($id, '0000000000001-abcdef0123456789.json');

        $path = $markerDir . DIRECTORY_SEPARATOR . hash('sha256', $id);
        $now += 30 * 24 * 60 * 60;
        touch($path, $now - 30 * 24 * 60 * 60);

        $this->assertFalse($log->claim($id), 'a queued delivery must never be re-claimed');
    }

    public function testMarkQueuedRecordsTheJobFile(): void
    {
        [$log, $markerDir] = $this->log();
        $id = '77777777-1111-2222-3333-444444444444';

        $log->claim($id);
        $log->markQueued($id, '1700000000000-abcdef0123456789.json');

        $marker = json_decode((string) file_get_contents($markerDir . DIRECTORY_SEPARATOR . hash('sha256', $id)), true);
        $this->assertSame('queued', $marker['state']);
        $this->assertSame('1700000000000-abcdef0123456789.json', $marker['job_file']);
        $this->assertSame($id, $marker['delivery_id']);
    }

    /**
     * An unwritable marker directory must raise, not report "duplicate".
     * Reporting a duplicate would make a broken state directory look like a
     * stream of redeliveries and silently discard every job.
     */
    public function testUnwritableDirectoryRaisesRatherThanReportingDuplicate(): void
    {
        $dir = Fixtures::tempDir('deliveries');
        mkdir($dir . '/tmp');
        // Point the log at a path that is not a directory at all.
        $log = new DeliveryLog($dir . '/does-not-exist', new AtomicWriter($dir . '/tmp'));

        try {
            $log->claim('88888888-1111-2222-3333-444444444444');
            $this->fail('expected a StorageException');
        } catch (StorageException) {
            $this->assertTrue(true);
        }
    }

    public function testIdValidationRejectsPathTraversalShapes(): void
    {
        $this->assertTrue(DeliveryLog::isValidId('11111111-2222-3333-4444-555555555555'));
        $this->assertTrue(DeliveryLog::isValidId('abcdef01'));

        foreach (['../../etc/passwd', '..', '/', 'a/b', 'x' . str_repeat('y', 100), '', 'has space', "nul\0byte"] as $bad) {
            $this->assertFalse(DeliveryLog::isValidId($bad), 'accepted a bad delivery id: ' . $bad);
        }
    }

    public function testPruneRemovesOnlyOldMarkers(): void
    {
        $now = 3_000_000;
        [$log, $markerDir] = $this->log(static function () use (&$now): int { return $now; });

        $log->claim('11111111-aaaa-bbbb-cccc-000000000001');
        $old = $markerDir . DIRECTORY_SEPARATOR . hash('sha256', '11111111-aaaa-bbbb-cccc-000000000001');
        touch($old, $now - (8 * 24 * 60 * 60));

        $log->claim('11111111-aaaa-bbbb-cccc-000000000002');

        $this->assertSame(1, $log->prune());
        $this->assertSame(1, $log->count(), 'the fresh marker must survive');
    }
}
