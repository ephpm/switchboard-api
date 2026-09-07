<?php

declare(strict_types=1);

namespace Switchboard\Cluster;

/**
 * {@see KvClient} over the real `ephpm_kv_*` SAPI functions.
 *
 * Only ever constructed when {@see available()} is true — see
 * {@see \Switchboard\App::boot()}, the one place that decision is made.
 *
 * Arity and return shape were verified against
 * `crates/ephpm-php/ephpm_wrapper.c` rather than assumed:
 *
 *   - `ephpm_kv_set(string $key, string $value, int $ttl_secs = 0): bool` —
 *     the third parameter is a plain `int` default, not `?int`; `0` means no
 *     expiry. This class never passes one: desired state is retired by
 *     {@see expire()} at the moment it becomes retirable, not on a clock
 *     started when it was published.
 *   - `ephpm_kv_get(string $key): ?string`
 *   - `ephpm_kv_del(string $key): int` (count removed — this class discards it)
 *   - `ephpm_kv_expire(string $key, int $ttl_secs): bool` — **two required
 *     args**, and the TTL is in whole **seconds**: the wrapper multiplies by
 *     1000 itself (`ttl_ms = ttl * 1000`) before calling into Rust. Passing
 *     milliseconds here would set an expiry a thousand times too long.
 *   - `ephpm_kv_incr(string $key): int|false` (false only when no KV store is
 *     registered at all, which {@see available()} already rules out)
 *
 * ePHPm scopes the KV store per vhost when `sites_dir` multi-tenant mode is
 * active (`ephpm-server/src/router.rs` calls `kv_bridge::set_site_store` per
 * request, keyed on the resolved site) — the same isolation the per-site
 * database gets. switchboard-api's `switchboard:*` keys therefore live in
 * this vhost's own keyspace, unreachable from any other tenant's PHP code,
 * exactly like every other credential this service depends on.
 */
final class SapiKvClient implements KvClient
{
    /** Is the KV SAPI bridge present in this process? */
    public static function available(): bool
    {
        return function_exists('ephpm_kv_get')
            && function_exists('ephpm_kv_set')
            && function_exists('ephpm_kv_del')
            && function_exists('ephpm_kv_expire')
            && function_exists('ephpm_kv_incr');
    }

    public function get(string $key): ?string
    {
        $value = \ephpm_kv_get($key);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value): bool
    {
        return \ephpm_kv_set($key, $value);
    }

    public function del(string $key): void
    {
        \ephpm_kv_del($key);
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        // Seconds, not milliseconds — see the class doc. The bool return says
        // only whether the key existed; a key already gone needs no expiry.
        \ephpm_kv_expire($key, $ttlSeconds);
    }

    public function incr(string $key): ?int
    {
        $result = \ephpm_kv_incr($key);

        return is_int($result) ? $result : null;
    }
}
