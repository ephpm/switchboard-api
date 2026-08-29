<?php

declare(strict_types=1);

namespace Switchboard\Storage;

/**
 * Reads a rotation-friendly secret file: one value per line, blank lines and
 * `#` comments ignored, everything else trimmed and kept.
 *
 * Shared by {@see \Switchboard\Config} (the webhook secret) and
 * {@see \Switchboard\Cluster\DrainHandler} (the drain token) — same format,
 * same rotation story: add the new value, update the caller, remove the old
 * one, and there is never a window where a still-valid credential is
 * rejected.
 */
final class SecretFile
{
    /** @return list<string> Empty when the file is missing, unreadable, or has no usable line. */
    public static function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
