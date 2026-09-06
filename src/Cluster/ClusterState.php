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
    /**
     * How long retired desired state stays readable after the first node has
     * materialized it — see {@see expirePreview()}.
     *
     * This is the window in which a node that has not drained yet can still
     * discover a teardown. It has to comfortably exceed the longest gap
     * between one node's drains: the interval itself is two seconds, but a
     * node is also not draining while it reboots, while its ePHPm is being
     * upgraded, or while it is briefly unreachable. A day covers all of those
     * with room to spare, and bounds how long a retired label keeps costing a
     * KV read on every drain.
     *
     * A node down *longer* than this returns having permanently missed the
     * teardown. That residual is stated rather than hidden, and it is the case
     * {@see DrainHandler}'s "generation advanced but nothing to do" warning
     * exists to surface.
     */
    public const RETIRED_PREVIEW_TTL_SECONDS = 24 * 60 * 60;

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
     * lives inside `$job` and travels through unchanged. The label is added to
     * the index either way, and nothing on this path ever removes it. Retiring
     * desired state is {@see expirePreview()}'s job, on a clock; pruning the
     * index is {@see removeFromIndex()}'s, and only once that clock has run
     * out. Neither may happen merely because one node is finished with the
     * label — see {@see expirePreview()} for what that cost.
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

    /**
     * Retire desired state for `$label` **without** making it immediately
     * unreadable by the nodes that have not seen it yet.
     *
     * This replaces an outright `del()`, and the difference is the whole of
     * switchboard#24. Desired state is published once and has to be consumed
     * independently by every node in the cluster. Deleting the key as soon as
     * *one* node had queued the teardown made the shared state a race with
     * exactly one winner: nodes whose two-second drain tick had not yet fired
     * found the label gone, queued nothing, and — because the `gen` cursor
     * advances regardless — recorded themselves as current at a generation
     * whose work they had never done. The observed result was a torn-down
     * preview still being served by a subset of the cluster, with every health
     * check green, plus its tenant database left on disk indefinitely.
     *
     * Giving the key a TTL instead keeps it discoverable for
     * {@see RETIRED_PREVIEW_TTL_SECONDS} while still bounding its lifetime.
     * Re-materializing is free: {@see DrainHandler} compares the published
     * `<intent>@<sha>` against this node's own `applied/<label>` marker, so a
     * node that has already queued the teardown skips it every time.
     *
     * Best-effort by design — a failed expiry leaves the key readable, which
     * is the safe direction. The label leaves {@see index()} only once the key
     * has actually gone (see {@see removeFromIndex()}).
     */
    public function expirePreview(string $label): void
    {
        $this->kv->expire(self::PREVIEW_PREFIX . $label, self::RETIRED_PREVIEW_TTL_SECONDS);
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

    /**
     * Drop `$label` from the shared index.
     *
     * Called from the drain side, and **only** once
     * `switchboard:preview:<label>` has actually expired — never merely
     * because this node has finished with the label. The index is the only way
     * a node enumerates desired state, so removing an entry is removing it from
     * every node at once; doing that on one node's say-so is precisely the bug
     * {@see expirePreview()} describes. By the time the key is gone the TTL has
     * given every node a day to notice it.
     */
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
