<?php

declare(strict_types=1);

namespace Switchboard\Queue;

use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\StorageException;

/**
 * The outbound half of the daemon contract: writing job files into `queue/`.
 *
 * # Filenames
 *
 * `<millis>-<16 hex>.json`, where `<millis>` is a 13-digit zero-padded Unix
 * timestamp in milliseconds and the hex is derived from the delivery GUID.
 *
 * Thirteen digits is not arbitrary: it keeps the timestamp fixed-width until
 * the year 2286, which makes **lexicographic order equal chronological order**.
 * The daemon can therefore process the queue in arrival order with a plain
 * `readdir` + `sort`, with no need to open and parse every file first.
 *
 * # Why files and not the database or the KV store
 *
 * ePHPm ships both — `ephpm/db` over per-site Turso and `ephpm/cache` over the
 * embedded KV — and neither can carry this handoff. Both are **in-process**
 * APIs: `ephpm_db_*` runs against a per-thread litewire session inside the
 * server, and `ephpm_kv_*` reads a `DashMap` in the server's address space. The
 * daemon is a different process. It would have to link the Turso engine, or
 * authenticate to the KV RESP listener with this site's derived credential,
 * to see either of them.
 *
 * A directory of JSON files needs neither. It is also the only one of the three
 * that a human can inspect during an incident with `ls` and `cat`, and the only
 * one that survives an ePHPm restart with no recovery logic (the KV store is
 * in-memory; a restart empties it).
 */
final class JobQueue
{
    public function __construct(
        private readonly string $queueDir,
        private readonly AtomicWriter $writer,
    ) {
    }

    /**
     * Write a job atomically and return its filename.
     *
     * @param array<string, mixed> $job
     *
     * @throws StorageException
     */
    public function enqueue(array $job, string $jobId): string
    {
        $encoded = json_encode(
            $job,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        $filename = $jobId . '.json';
        $this->writer->write($this->queueDir . DIRECTORY_SEPARATOR . $filename, $encoded);

        return $filename;
    }

    /**
     * A sortable, filesystem-safe job id.
     *
     * Derived from the delivery GUID rather than random, so re-deriving it for
     * the same delivery yields the same id — which keeps the delivery marker,
     * the job file and the status entry mutually traceable.
     */
    public static function jobId(int $millis, string $deliveryId): string
    {
        return sprintf('%013d-%s', $millis, substr(hash('sha256', $deliveryId), 0, 16));
    }

    /**
     * Pending job filenames in arrival order.
     *
     * @return list<string>
     */
    public function pending(int $limit = 100): array
    {
        $entries = @scandir($this->queueDir);
        if ($entries === false) {
            return [];
        }

        $jobs = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '.json')) {
                $jobs[] = $entry;
            }
        }
        sort($jobs, SORT_STRING);

        return array_slice($jobs, 0, $limit);
    }

    /** @return array{depth: int, oldest_age_seconds: int|null} */
    public function stats(): array
    {
        $pending = $this->pending(10_000);
        $oldest = null;

        if ($pending !== []) {
            $mtime = @filemtime($this->queueDir . DIRECTORY_SEPARATOR . $pending[0]);
            if ($mtime !== false) {
                $oldest = max(0, time() - $mtime);
            }
        }

        return ['depth' => count($pending), 'oldest_age_seconds' => $oldest];
    }

    /** @return array<string, mixed>|null */
    public function read(string $filename): ?array
    {
        if (!preg_match('/^[0-9a-f-]+\.json$/', $filename)) {
            return null;
        }

        $raw = @file_get_contents($this->queueDir . DIRECTORY_SEPARATOR . $filename);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
