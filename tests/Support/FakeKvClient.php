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

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value): bool
    {
        $this->store[$key] = $value;

        return true;
    }

    public function del(string $key): void
    {
        unset($this->store[$key]);
    }

    public function incr(string $key): ?int
    {
        $value = ((int) ($this->store[$key] ?? '0')) + 1;
        $this->store[$key] = (string) $value;

        return $value;
    }

    /** Test-only inspection: the raw stored value, if any. */
    public function raw(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    /** Test-only inspection: every key currently set. */
    public function keys(): array
    {
        return array_keys($this->store);
    }
}
