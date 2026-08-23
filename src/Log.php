<?php

declare(strict_types=1);

namespace Switchboard;

/**
 * Structured logging to PHP's `error_log()`, which ePHPm captures.
 *
 * Every line is `switchboard-api <level> <message> <json-context>`. Context is
 * for identifiers and decisions — **never** for secrets, signatures, or request
 * bodies. Callers are responsible for not passing those in; nothing here can
 * un-log a value that has already been handed over.
 */
final class Log
{
    /** @param array<string, scalar|null> $context */
    public static function info(string $message, array $context = []): void
    {
        self::emit('info', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public static function warn(string $message, array $context = []): void
    {
        self::emit('warn', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public static function error(string $message, array $context = []): void
    {
        self::emit('error', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private static function emit(string $level, string $message, array $context): void
    {
        $encoded = $context === []
            ? ''
            : ' ' . (json_encode($context, JSON_UNESCAPED_SLASHES) ?: '{"log":"unencodable context"}');

        error_log('switchboard-api ' . $level . ' ' . $message . $encoded);
    }
}
