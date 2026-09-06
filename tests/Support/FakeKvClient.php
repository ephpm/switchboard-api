<?php

declare(strict_types=1);

namespace Switchboard\Tests\Support;

use Switchboard\Cluster\KvClient;

/**
 * An in-memory {@see KvClient}, one instance per test.
 *
 * This — not `function_exists('ephpm_kv_set')` — is how cluster-mode tests
 * turn cluster mode on: they build the app with `Fixtures::app($dir, kv: new
 * FakeKvClient())`. Because it is injected explicitly per test rather than
 * relying on a process-global polyfill, cluster-mode and single-node tests
 * can run in any order in the same `tools/test.php` process without either
 * leaking into the other. See `Switchboard\Cluster\KvClient`'s doc comment.
 */
final class FakeKvClient implements KvClient
{
    /** @var array<string, string> */
    private array $store = [];

    /**
     * Expiry deadlines, in this fake's own seconds — see {@see advance()}.
     *
     * @var array<string, int>
     */
    private array $expiresAt = [];

    /** This fake's clock. Only {@see advance()} moves it. */
    private int $now = 0;

    public function get(string $key): ?string
    {
        $this->reap();

        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value): bool
    {
        $this->store[$key] = $value;
        // A plain write clears any pending expiry, matching a KV `set`.
        unset($this->expiresAt[$key]);

        return true;
    }

    public function del(string $key): void
    {
        unset($this->store[$key], $this->expiresAt[$key]);
    }

    public function expire(string $key, int $ttlSeconds): void
    {
        // A KV `expire` on a missing key is a no-op, not a resurrection.
        if (!array_key_exists($key, $this->store)) {
            return;
        }

        $this->expiresAt[$key] = $this->now + $ttlSeconds;
    }

    public function incr(string $key): ?int
    {
        $this->reap();
        $value = ((int) ($this->store[$key] ?? '0')) + 1;
        $this->store[$key] = (string) $value;

        return $value;
    }

    /**
     * Test-only: move this fake's clock forward so TTLs can actually elapse.
     *
     * Without it a test for expiry-driven behaviour would have to sleep for
     * {@see \Switchboard\Cluster\ClusterState::RETIRED_PREVIEW_TTL_SECONDS},
     * i.e. a day.
     */
    public function advance(int $seconds): void
    {
        $this->now += $seconds;
        $this->reap();
    }

    /** Test-only inspection: the raw stored value, if any. */
    public function raw(string $key): ?string
    {
        $this->reap();

        return $this->store[$key] ?? null;
    }

    /** Test-only inspection: every key currently set. */
    public function keys(): array
    {
        $this->reap();

        return array_keys($this->store);
    }

    /** Test-only inspection: whether `$key` has an expiry pending. */
    public function hasExpiry(string $key): bool
    {
        $this->reap();

        return array_key_exists($key, $this->expiresAt);
    }

    /** Drop everything whose deadline has passed. */
    private function reap(): void
    {
        foreach ($this->expiresAt as $key => $deadline) {
            if ($deadline <= $this->now) {
                unset($this->store[$key], $this->expiresAt[$key]);
            }
        }
    }
}
