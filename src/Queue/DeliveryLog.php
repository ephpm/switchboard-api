<?php

declare(strict_types=1);

namespace Switchboard\Queue;

use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\StorageException;

/**
 * Delivery deduplication, keyed on `X-GitHub-Delivery`.
 *
 * GitHub redelivers: automatically on a non-2xx or a timeout, and manually from
 * the App's delivery UI. A redelivery carries the **same** delivery GUID as the
 * original, which is the only durable signal that two requests are the same
 * event. Without this, a webhook that took eleven seconds to answer would queue
 * a second deploy of the same commit.
 *
 * # The primitive
 *
 * `fopen($path, 'x')` is `O_CREAT|O_EXCL`: it succeeds for exactly one caller
 * and fails for every other, atomically, in the kernel. No lock file, no
 * read-then-write window, and it survives a restart — which an in-memory or
 * KV-based check would not (ePHPm's KV store is a `DashMap`; a restart empties
 * it, and dedup state that evaporates is not dedup state).
 *
 * # Why the filename is a hash
 *
 * The delivery GUID arrives in a request header and is therefore attacker-
 * supplied until proven otherwise. It is validated against a UUID shape *and*
 * stored under its SHA-256 — so even if the shape check were wrong, the value
 * reaching the filesystem is 64 hex characters and cannot traverse or collide
 * with anything else in the directory.
 *
 * # Ordering and the crash window
 *
 * A marker is claimed *before* the job is written, so a duplicate can never
 * race past the claim and enqueue. The cost is a window: if the process dies
 * between claiming and writing, the marker survives, the job does not, and
 * GitHub's redelivery would be suppressed forever — the PR silently never
 * deploys.
 *
 * The marker therefore records a state. It is written `claimed`, and promoted
 * to `queued` once the job file is in the queue. A marker still in `claimed`
 * after {@see RECLAIM_AFTER_SECONDS} can only have come from a crashed request,
 * and is re-claimable. A healthy request promotes in about a millisecond, so
 * this can never fire for one.
 *
 * Residual race, stated plainly: two redeliveries of the *same* abandoned
 * marker arriving simultaneously, more than two minutes after a crash, can both
 * re-claim and produce two jobs. Closing it would need a lock the rest of this
 * design does not require; a duplicate deploy of an already-crashed delivery is
 * a better failure than a preview that never appears.
 */
final class DeliveryLog
{
    /** A `claimed` marker older than this came from a crashed request. */
    public const RECLAIM_AFTER_SECONDS = 120;

    /** Default retention for markers before {@see prune()} removes them. */
    public const DEFAULT_RETENTION_SECONDS = 7 * 24 * 60 * 60;

    /** @var \Closure(): int */
    private \Closure $clock;

    public function __construct(
        private readonly string $dir,
        private readonly AtomicWriter $writer,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
    }

    /** Does `$deliveryId` look like a GitHub delivery GUID? */
    public static function isValidId(string $deliveryId): bool
    {
        return preg_match('/^[0-9a-fA-F-]{8,64}$/', $deliveryId) === 1;
    }

    /**
     * Take ownership of a delivery.
     *
     * @return bool `true` if this request owns the delivery and should enqueue;
     *              `false` if it is a duplicate and must not.
     *
     * @throws StorageException when the marker cannot be created at all — the
     *                          caller must fail the request rather than guess.
     */
    public function claim(string $deliveryId): bool
    {
        $path = $this->pathFor($deliveryId);

        $handle = @fopen($path, 'xb');
        if ($handle !== false) {
            $this->fill($handle, $deliveryId, 'claimed', null);

            return true;
        }

        // `x` mode fails both for "already exists" and for real I/O errors.
        // Only the first is a duplicate; the second must not be reported as one,
        // or a broken state directory would look like a stream of duplicates and
        // silently drop every job.
        if (!is_file($path)) {
            throw new StorageException('cannot create delivery marker in ' . $this->dir);
        }

        if (!$this->isAbandoned($path)) {
            return false;
        }

        // Abandoned by a crashed request: drop it and re-run the exclusive
        // create, so whoever wins the create owns the delivery.
        @unlink($path);
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return false;
        }
        $this->fill($handle, $deliveryId, 'claimed', null);

        return true;
    }

    /** Promote a claimed marker to `queued` once its job file exists. */
    public function markQueued(string $deliveryId, string $jobFile): void
    {
        $this->writer->write($this->pathFor($deliveryId), $this->encode($deliveryId, 'queued', $jobFile));
    }

    /**
     * Release a claim so GitHub's redelivery can retry.
     *
     * Called when enqueueing failed after the claim succeeded. Without it the
     * marker would suppress the retry of a delivery that never produced a job.
     */
    public function release(string $deliveryId): void
    {
        @unlink($this->pathFor($deliveryId));
    }

    public function seen(string $deliveryId): bool
    {
        return is_file($this->pathFor($deliveryId));
    }

    /**
     * Remove markers older than `$retentionSeconds`, up to `$budget` of them.
     *
     * Bounded so it can be called opportunistically from a request without
     * turning a webhook into an unbounded directory walk.
     *
     * @return int Number of markers removed.
     */
    public function prune(int $retentionSeconds = self::DEFAULT_RETENTION_SECONDS, int $budget = 200): int
    {
        $handle = @opendir($this->dir);
        if ($handle === false) {
            return 0;
        }

        $cutoff = ($this->clock)() - $retentionSeconds;
        $removed = 0;

        try {
            while ($budget > 0 && ($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $this->dir . DIRECTORY_SEPARATOR . $entry;
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $cutoff) {
                    $budget--;
                    if (@unlink($path)) {
                        $removed++;
                    }
                }
            }
        } finally {
            closedir($handle);
        }

        return $removed;
    }

    public function count(): int
    {
        $entries = @scandir($this->dir);

        return $entries === false ? 0 : max(0, count($entries) - 2);
    }

    private function pathFor(string $deliveryId): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $deliveryId);
    }

    /** @param resource $handle */
    private function fill($handle, string $deliveryId, string $state, ?string $jobFile): void
    {
        @fwrite($handle, $this->encode($deliveryId, $state, $jobFile));
        @fflush($handle);
        fclose($handle);
    }

    private function encode(string $deliveryId, string $state, ?string $jobFile): string
    {
        return json_encode([
            'delivery_id' => $deliveryId,
            'state' => $state,
            'job_file' => $jobFile,
            'at' => ($this->clock)(),
        ], JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * A marker left in `claimed` past the reclaim window.
     *
     * An unreadable or corrupt marker is treated as a duplicate while it is
     * fresh (the safe reading) and as abandoned once it is stale.
     */
    private function isAbandoned(string $path): bool
    {
        $age = ($this->clock)() - (int) (@filemtime($path) ?: 0);
        if ($age <= self::RECLAIM_AFTER_SECONDS) {
            return false;
        }

        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return true;
        }

        $decoded = json_decode($raw, true);

        return !is_array($decoded) || ($decoded['state'] ?? null) === 'claimed';
    }
}
