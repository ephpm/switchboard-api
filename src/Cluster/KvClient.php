<?php

declare(strict_types=1);

namespace Switchboard\Cluster;

/**
 * The narrow slice of `ephpm_kv_*` cluster mode needs.
 *
 * An interface, not a direct call to the global `ephpm_kv_*` functions, so
 * {@see \Switchboard\App::build()} can take one as an explicit, optional
 * dependency: {@see SapiKvClient} in production, an in-memory fake
 * (`tests/Support/FakeKvClient.php`) in tests. That makes cluster-vs-
 * single-node mode a deterministic constructor argument rather than
 * something decided by `function_exists('ephpm_kv_set')` at request time.
 *
 * Two independent reasons that indirection is load-bearing, not just tidy:
 *
 *  1. PHP cannot un-define a function. A test-bootstrap polyfill of
 *     `ephpm_kv_*` is process-global and permanent once loaded; if
 *     cluster-vs-single-node mode were decided by `function_exists()` at
 *     request time, loading such a polyfill anywhere would silently flip
 *     every *other* test in the same `tools/test.php` run into cluster mode.
 *  2. **`function_exists('ephpm_kv_set')` is true whenever ePHPm's PHP is
 *     linked with the KV bridge compiled in, whether or not a KV store is
 *     actually bound at runtime** — confirmed by running this suite through
 *     the real `ephpm php tools/test.php`: the functions exist and are
 *     callable, but with no store registered (bare CLI, outside a served
 *     request) `ephpm_kv_set`/`ephpm_kv_incr` return `false` for every call.
 *     A raw `function_exists()` check is therefore not by itself proof the
 *     KV bridge will actually work; `SapiKvClient`'s calls can still fail
 *     per-request exactly like any other storage backend, and
 *     `ClusterState::publish()` surfaces that as a `StorageException` the
 *     same way `JobQueue::enqueue()` does for a filesystem failure.
 *
 * {@see SapiKvClient::available()} is the one place the `function_exists()`
 * check happens, and it is only ever consulted once, at boot, in
 * {@see \Switchboard\App::boot()}.
 */
interface KvClient
{
    /** `null` when the key does not exist. */
    public function get(string $key): ?string;

    /** `false` when the KV store rejected the write. */
    public function set(string $key, string $value): bool;

    public function del(string $key): void;

    /**
     * Set a time-to-live on an existing `$key`, in **seconds**.
     *
     * Used instead of {@see del()} to retire desired state that every node
     * must still get a chance to see — see {@see ClusterState::expirePreview()}.
     * A no-op when the key does not exist.
     */
    public function expire(string $key, int $ttlSeconds): void;

    /** Atomically increment `$key` (creating it at 0 first if absent). `null` on failure. */
    public function incr(string $key): ?int;
}
