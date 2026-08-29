<?php

declare(strict_types=1);

namespace Switchboard\Cluster;

use Switchboard\Storage\StorageException;

/**
 * The gossip-replicated KV key layout for cluster mode.
 *
 * `ephpm_kv_*` is a per-node call, but ePHPm's KV store gossip-replicates:
 * every node in the cluster converges on the same keys. In a NodeBalancer-
 * fronted cluster a webhook delivery lands on exactly one node; publishing
 * desired state here (rather than writing straight to that one node's local
 * `queue/`) is what lets the *other* nodes find out about it. `GET /drain` —
 * see {@see DrainHandler} — is kicked by every node's own daemon and turns
 * this shared state back into that node's own queue files.
 *
 * # Keys
 *
 *   `switchboard:preview:<label>`  the full schema-1 job document, verbatim,
 *                                   as built by `Switchboard\Queue\Job::build()`
 *   `switchboard:index`            JSON array of every label with a preview key
 *   `switchboard:gen`              a counter bumped on every {@see publish()}
 *
 * # Why there is an index at all
 *
 * The verified `ephpm_kv_*` SAPI surface is
 * get/set/setnx/del/exists/incr/decr/incr_by/expire/ttl/pttl/flush_all/wait
 * (`crates/ephpm-php/ephpm_wrapper.c`) — there is no scan, so there is no way
 * to enumerate `switchboard:preview:*` directly. `switchboard:index` is a
 * hand-rolled one.
 *
 * # The race, acknowledged rather than hidden
 *
 * {@see publish()} and {@see removeFromIndex()} are both read-modify-write
 * against `switchboard:index`: read the array, change it, write it back.
 * Two webhooks for two different labels landing on two different nodes at
 * the same instant can both read the same starting array, and whichever
 * write lands second silently overwrites the first one's addition — a lost
 * update on the index only. This is the same kind of trade
 * {@see \Switchboard\Queue\DeliveryLog} already makes for its own crash
 * window: closing it needs a lock this design does not otherwise require,
 * and the failure mode is bounded and self-healing rather than silent —
 * `switchboard:preview:<label>` itself is a single `set()`, not part of the
 * race, so the desired state a lost index update "hid" is still there and
 * the next webhook (or an operator re-kicking a drain after fixing the
 * index by hand) recovers it. A preview does not silently vanish; at worst
 * it is late.
 *
 * # `gen`, not a version per key
 *
 * One shared counter, bumped with `ephpm_kv_incr`, is cheaper than watching
 * every preview key individually, and `/drain`'s fast path only needs to
 * know "has anything changed since I last looked" before it pays for the
 * index read and the per-label comparisons.
 */
final class ClusterState
{
    private const PREVIEW_PREFIX = 'switchboard:preview:';
    private const INDEX_KEY = 'switchboard:index';
    private const GEN_KEY = 'switchboard:gen';

    public function __construct(private readonly KvClient $kv)
    {
    }

    /**
     * Publish desired state for `$label` and bump the generation counter.
     *
     * Called for both `intent: deploy` and `intent: teardown` — the intent
     * lives inside `$job` and travels through unchanged. The label is added
     * to the index either way; a teardown is deliberately NOT removed from
     * the index here; {@see DrainHandler} prunes it only once a node has
     * actually queued the teardown locally, so every node gets a chance to
     * see it.
     *
     * @param array<string, mixed> $job The full schema-1 job document, verbatim.
     *
     * @throws StorageException if the KV store rejects the write.
     */
    public function publish(string $label, array $job): void
    {
        $encoded = json_encode(
            $job,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (!$this->kv->set(self::PREVIEW_PREFIX . $label, $encoded)) {
            throw new StorageException('cannot publish desired state for ' . $label);
        }

        $this->addToIndex($label);

        // Best-effort: a failed increment only delays other nodes noticing
        // this change until their next drain's full reconciliation catches
        // up (they compare `gen`, not this call's success), not silently.
        $this->kv->incr(self::GEN_KEY);
    }

    /** @return array<string, mixed>|null */
    public function preview(string $label): ?array
    {
        $raw = $this->kv->get(self::PREVIEW_PREFIX . $label);
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Best-effort cleanup, called once a node has queued a teardown locally. */
    public function deletePreview(string $label): void
    {
        $this->kv->del(self::PREVIEW_PREFIX . $label);
    }

    /** @return list<string> */
    public function index(): array
    {
        $raw = $this->kv->get(self::INDEX_KEY);
        if ($raw === null) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }

    /** Called from the drain side, only after a teardown has been queued locally. */
    public function removeFromIndex(string $label): void
    {
        $index = array_values(array_filter(
            $this->index(),
            static fn (string $existing): bool => $existing !== $label,
        ));

        $this->kv->set(self::INDEX_KEY, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** `0` when the counter has never been incremented. */
    public function generation(): int
    {
        $raw = $this->kv->get(self::GEN_KEY);

        return $raw !== null && ctype_digit($raw) ? (int) $raw : 0;
    }

    private function addToIndex(string $label): void
    {
        $index = $this->index();
        if (in_array($label, $index, true)) {
            return;
        }

        $index[] = $label;
        $this->kv->set(self::INDEX_KEY, json_encode($index, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
