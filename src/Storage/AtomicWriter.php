<?php

declare(strict_types=1);

namespace Switchboard\Storage;

/**
 * Write-to-temp-then-rename, the only way this application creates a file a
 * different process reads.
 *
 * The daemon polls `queue/` on its own schedule. If the API wrote job files in
 * place, the daemon would eventually open one mid-write and parse a truncated
 * JSON document — or worse, one that happens to still be valid JSON with fields
 * missing. `rename()` within a filesystem is atomic: the destination name
 * resolves either to the old inode or the new one, never to a partial file, and
 * a reader that opened the old inode keeps reading a consistent file.
 *
 * The sequence is:
 *
 *   1. Create a uniquely-named temp file in `tmp/` with `x` mode (`O_EXCL`), so
 *      two concurrent requests can never share a staging file.
 *   2. Write the full contents, `fflush()`, then `fsync()` — the bytes are on
 *      the device before the name exists, so a crash cannot expose a file whose
 *      name is durable but whose contents are not.
 *   3. `rename()` into place.
 *
 * Durability caveat, stated rather than implied: the *directory entry* created
 * by the rename is not itself fsynced — PHP cannot open a directory to sync it.
 * A power loss immediately after a rename can therefore lose a job that the API
 * already acknowledged. GitHub's redelivery is the recovery path; the delivery
 * marker is removed on any enqueue failure precisely so redelivery works.
 */
final class AtomicWriter
{
    public function __construct(private readonly string $tmpDir)
    {
    }

    /** @throws StorageException */
    public function write(string $destination, string $contents): void
    {
        $tmp = $this->stage($contents);

        if (!@rename($tmp, $destination)) {
            @unlink($tmp);

            throw new StorageException('cannot rename into place: ' . $destination);
        }
    }

    /**
     * Stage `$contents` in a temp file and return its path, leaving the caller
     * to rename it. Used when the destination name depends on the staged file
     * having been written successfully.
     *
     * @throws StorageException
     */
    public function stage(string $contents): string
    {
        if (!is_dir($this->tmpDir) && !@mkdir($this->tmpDir, 0o750, true) && !is_dir($this->tmpDir)) {
            throw new StorageException('cannot create temp directory: ' . $this->tmpDir);
        }

        $tmp = $this->tmpDir . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '.tmp';

        $handle = @fopen($tmp, 'xb');
        if ($handle === false) {
            throw new StorageException('cannot create temp file in ' . $this->tmpDir);
        }

        try {
            $written = @fwrite($handle, $contents);
            if ($written !== strlen($contents)) {
                throw new StorageException('short write to temp file');
            }

            if (!@fflush($handle)) {
                throw new StorageException('cannot flush temp file');
            }

            // fsync() exists from PHP 8.1. Guarded anyway: a missing fsync is a
            // weaker durability guarantee, not a reason to fail the write.
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);

            throw $e instanceof StorageException ? $e : new StorageException($e->getMessage(), 0, $e);
        }

        fclose($handle);

        return $tmp;
    }
}
