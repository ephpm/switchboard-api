<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Queue\JobQueue;
use Switchboard\Storage\AtomicWriter;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/**
 * Job files must never be observable half-written.
 *
 * The daemon polls the queue directory on its own schedule and will open a file
 * the instant it appears. If the API wrote in place, the daemon would sooner or
 * later parse a truncated document.
 */
final class AtomicWriteTest extends TestCase
{
    /**
     * The core invariant, checked structurally: at no point during a write does
     * the destination name exist with partial content. The staging file is
     * written first, and only a `rename()` — a single atomic metadata operation
     * — publishes it.
     *
     * This is asserted by observing that a large payload's destination does not
     * exist while the staging file does, then exists complete after the rename.
     */
    public function testDestinationNeverExistsPartiallyWritten(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        $writer = new AtomicWriter($dir . '/tmp');

        // Big enough that a naive in-place write would take several syscalls
        // and be observable mid-flight.
        $contents = str_repeat('{"padding":"' . str_repeat('x', 900) . '"}' . "\n", 2000);
        $destination = $dir . '/job.json';

        $staged = $writer->stage($contents);

        // Fully written and complete under its temporary name...
        $this->assertSame($contents, (string) file_get_contents($staged));
        // ...while the name the daemon watches does not exist at all.
        $this->assertFalse(file_exists($destination), 'destination visible before rename');

        $this->assertTrue(rename($staged, $destination));
        $this->assertSame($contents, (string) file_get_contents($destination));
    }

    /**
     * A reader that scans the queue directory only ever sees complete files.
     * The staging directory is a *sibling* of the queue, so a `queue/*.json`
     * scan cannot pick up a file that is still being written.
     */
    public function testQueueScanNeverSeesStagingFiles(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        mkdir($dir . '/queue');
        $writer = new AtomicWriter($dir . '/tmp');
        $queue = new JobQueue($dir . '/queue', $writer);

        // Leave a staged file behind, as an interrupted request would.
        $writer->stage('{"incomplete":');

        $queue->enqueue(['schema' => 1, 'job_id' => 'x'], '1700000000000-abcdef0123456789');

        $pending = $queue->pending();
        $this->assertSame(1, count($pending), 'staging file leaked into the queue listing');
        $this->assertSame('1700000000000-abcdef0123456789.json', $pending[0]);

        foreach ($pending as $name) {
            $decoded = json_decode((string) file_get_contents($dir . '/queue/' . $name), true);
            $this->assertTrue(is_array($decoded), 'queue contained an unparseable file: ' . $name);
        }
    }

    public function testWrittenJobIsCompleteAndParseable(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        mkdir($dir . '/queue');
        $queue = new JobQueue($dir . '/queue', new AtomicWriter($dir . '/tmp'));

        $job = ['schema' => 1, 'job_id' => 'j', 'nested' => ['a' => 1, 'b' => [1, 2, 3]]];
        $filename = $queue->enqueue($job, '1700000000000-0123456789abcdef');

        $decoded = json_decode((string) file_get_contents($dir . '/queue/' . $filename), true);
        $this->assertSame($job, $decoded);
    }

    public function testStagingFileIsRemovedOnRenameFailure(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        $writer = new AtomicWriter($dir . '/tmp');

        try {
            // A destination inside a directory that does not exist.
            $writer->write($dir . '/missing/job.json', 'contents');
            $this->fail('expected the write to fail');
        } catch (\Throwable) {
            // The staging directory must not accumulate debris from failures.
            $leftovers = array_diff(scandir($dir . '/tmp') ?: [], ['.', '..']);
            $this->assertSame(0, count($leftovers), 'staging file left behind after a failed rename');
        }
    }

    public function testEachStagedWriteGetsItsOwnFile(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        $writer = new AtomicWriter($dir . '/tmp');

        // Concurrent requests must never share a staging file; the names come
        // from random bytes and are created with O_EXCL.
        $a = $writer->stage('a');
        $b = $writer->stage('b');

        $this->assertTrue($a !== $b, 'two staged writes shared a filename');
        $this->assertSame('a', (string) file_get_contents($a));
        $this->assertSame('b', (string) file_get_contents($b));
    }

    /** Overwriting an existing destination must also be atomic, for status files. */
    public function testRenameReplacesAnExistingDestination(): void
    {
        $dir = Fixtures::tempDir('atomic');
        mkdir($dir . '/tmp');
        $writer = new AtomicWriter($dir . '/tmp');
        $destination = $dir . '/status.json';

        $writer->write($destination, '{"phase":"queued"}');
        $writer->write($destination, '{"phase":"ready"}');

        $this->assertSame('{"phase":"ready"}', (string) file_get_contents($destination));
    }

    /** Lexicographic order equals arrival order — how the daemon reads the queue in order. */
    public function testJobIdsSortChronologically(): void
    {
        $ids = [
            JobQueue::jobId(1_700_000_000_000, 'a'),
            JobQueue::jobId(1_700_000_000_001, 'b'),
            JobQueue::jobId(1_700_000_001_000, 'c'),
            JobQueue::jobId(1_800_000_000_000, 'd'),
        ];

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        $this->assertSame($ids, $sorted);
        foreach ($ids as $id) {
            $this->assertMatches('/^\d{13}-[0-9a-f]{16}$/', $id);
        }
    }
}
