<?php

declare(strict_types=1);

namespace Switchboard\Cluster;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Http\Json;
use Switchboard\Log;
use Switchboard\Queue\JobQueue;
use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\Paths;
use Switchboard\Storage\SecretFile;
use Switchboard\Storage\StorageException;
use Switchboard\Webhook\PreviewLabel;

/**
 * `GET /drain` — localhost-only, kicked by this node's switchboard daemon on
 * a timer. Reconciles the gossip-replicated desired state
 * ({@see ClusterState}) into THIS node's own `queue/`, which is the only
 * thing the daemon otherwise ever reads (see `JobQueue`'s doc comment on why
 * the handoff between the API and the daemon is files, not the KV or the
 * database).
 *
 * # Why this can never be reached from the internet
 *
 * Three independent layers, deliberately redundant:
 *
 *  1. **`REMOTE_ADDR` must be `127.0.0.1`.** The NodeBalancer that fronts a
 *     cluster forwards internet traffic to the same listener this vhost
 *     answers on, so this vhost's ePHPm configuration must never put the
 *     NodeBalancer in `trusted_proxies` — if it did, a client-supplied
 *     `X-Forwarded-For` could spoof `REMOTE_ADDR` and defeat this check
 *     entirely. This is the concrete reason that matters operationally, not
 *     just a defence in depth footnote.
 *  2. **A bearer token**, `.switchboard/drain_secret`, compared with
 *     `hash_equals`. Belt and braces against anything else sharing the
 *     loopback interface on the same host.
 *  3. **Fail closed, indistinguishably.** A missing/empty secret file, a
 *     missing header, and a wrong token are all answered exactly like a
 *     request for a path that does not exist: `404`, the same body
 *     `Router` returns for an unmatched route. A probe of this endpoint
 *     learns nothing — the same property the README already claims for the
 *     removed dashboard and read API.
 *
 * # Materialization
 *
 * `switchboard:index` lists every label with published desired state. For
 * each one, this node compares `<intent>@<sha>` (from
 * `switchboard:preview:<label>`) against a local marker at
 * `.switchboard/applied/<label>` — the last `<intent>@<sha>` THIS node has
 * already queued. Different: write a fresh job file, reusing the exact same
 * atomic-write / `<millis>-<16 hex>.json` code the webhook path uses
 * ({@see \Switchboard\Queue\JobQueue}), with a freshly generated
 * `job_id`/filename — this is a per-node materialization of shared state,
 * not a redelivery of the original webhook, so there is nothing to
 * deduplicate against. Same: skip it, which is what makes a re-kick with no
 * changes a no-op.
 *
 * # Retiring a teardown is not the same as finishing one (switchboard#24)
 *
 * A queued teardown starts a TTL on its preview key
 * ({@see ClusterState::expirePreview()}); it does **not** delete the key or
 * prune the index. That distinction is the whole point. Desired state is
 * published once and has to be consumed independently by all N nodes, so any
 * step that makes it unreadable the moment *one* node is done turns the shared
 * state into a race with a single winner. It did: the first node to drain a
 * teardown deleted the key and pruned the index, and every node whose
 * two-second tick had not yet fired then walked an index the label had already
 * left, queued nothing, and advanced `last_gen` anyway — recording itself
 * current at a generation whose work it had never done. The preview stayed
 * served on those nodes, and its tenant database stayed on disk.
 *
 * The index therefore shrinks in exactly one place: when a label's preview key
 * is found already gone, i.e. the TTL has expired and every node has had a day
 * to act. Re-materialization in the meantime is free — the `applied/<label>`
 * marker suppresses it — which is why the daemon must **keep** a
 * `teardown@<sha>` marker rather than reaping it (switchboard's `teardown.rs`).
 * Those two halves are one contract; the daemon's half ships first.
 *
 * # Reconcile on content, not on the generation cursor (switchboard#24)
 *
 * {@see handle()} reconciles the desired-state **content** on every kick and
 * never lets `switchboard:gen == last_gen` short-circuit that. `gen` is a
 * single best-effort, gossip-replicated counter; a lost or lagged increment
 * lets a preview key's content change — a deploy flipping to a teardown —
 * while `gen` stays equal to a node's last-seen value. The old fast path
 * trusted the cursor and returned without walking the index, so a node whose
 * `gen` already matched never learned a teardown it had not received a webhook
 * for, and kept serving a torn-down preview with every health check green.
 * Walking every time is what closes that gap; the per-label `applied/<label>`
 * marker keeps the walk idempotent and stops it re-materializing intents this
 * node has already applied (which is why forcing a re-walk by resetting
 * `last_gen` — resurrecting stale deploy intents — was the wrong fix).
 *
 * The cursor is kept only for observability: when a walk queues work the
 * generation number did not announce, {@see handle()} logs it as the signature
 * of a lagged `gen`. No network calls anywhere in this path; a kick with
 * nothing changed is one index read plus a marker comparison per label.
 */
final class DrainHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Paths $paths,
        private readonly Json $json,
        private readonly AtomicWriter $writer,
        private readonly JobQueue $queue,
        private readonly ?ClusterState $cluster,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->clientAddress($request) !== '127.0.0.1') {
            return $this->notFound();
        }

        if (!$this->tokenValid($request)) {
            return $this->notFound();
        }

        if ($this->cluster === null) {
            // Single-node: there is nothing to reconcile. The webhook path
            // already wrote straight to this node's own queue.
            return $this->json->response(200, ['ok' => true, 'mode' => 'single-node', 'queued' => 0]);
        }

        // Reconcile on the desired-state CONTENT every time — never trust the
        // generation cursor to decide there is nothing to do (issue #4).
        //
        // `switchboard:gen` is one best-effort, gossip-replicated counter:
        // ClusterState::publish() bumps it with an incr its own doc calls
        // best-effort, and gossip can drop or lag that single increment while
        // the preview-key `set` still replicates. When it does, a node's
        // `last_gen` can already equal the published `gen` even though a preview
        // key's content has since flipped deploy→teardown. The old
        // `gen === last_gen` fast path returned right there, so `materialize()`
        // never ran and the node never learned the preview should be gone —
        // exactly how a torn-down preview kept being served on the cluster
        // nodes that never received the close webhook, with every health check
        // green (switchboard#24 closed the index-pruning race one layer up; this
        // is the generation-vs-content race underneath it).
        //
        // Always walking is cheap and safe: the per-label `applied/<label>`
        // marker makes an unchanged label a file read plus a string compare and
        // re-queues nothing, so a re-kick with no real change stays idempotent.
        // This is deliberately NOT "reset last_gen to force a re-walk" — that
        // was rejected in the issue because it re-materializes stale deploy
        // intents; the applied markers are what keep this walk from doing so.
        $gen = $this->cluster->generation();
        $lastGen = $this->readLastGen();

        [$checked, $queued] = $this->materialize($this->cluster);

        // The cursor is advisory now: recorded for observability, never used to
        // skip reconciliation. When content reconciliation queues work the
        // generation number did NOT announce, that is the fingerprint of a
        // gossip-lagged `switchboard:gen` — the very condition that used to
        // strand a teardown. Naming it turns an invisible divergence into a log
        // line.
        if ($queued > 0 && $gen === $lastGen) {
            Log::info('drain: reconciled desired-state content the generation cursor did not reflect', [
                'gen' => $gen,
                'checked' => $checked,
                'queued' => $queued,
                'hint' => 'a lagged/lost switchboard:gen increment; content reconciliation caught it (switchboard#24)',
            ]);
        }

        $this->writeLastGen($gen);

        return $this->json->response(200, ['ok' => true, 'gen' => $gen, 'checked' => $checked, 'queued' => $queued]);
    }

    private function clientAddress(ServerRequestInterface $request): ?string
    {
        $addr = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($addr) ? $addr : null;
    }

    private function tokenValid(ServerRequestInterface $request): bool
    {
        $secrets = SecretFile::read($this->paths->drainSecret());
        if ($secrets === []) {
            return false;
        }

        $provided = $request->hasHeader('X-Drain-Token') ? $request->getHeaderLine('X-Drain-Token') : '';
        if ($provided === '') {
            return false;
        }

        foreach ($secrets as $secret) {
            if (hash_equals($secret, $provided)) {
                return true;
            }
        }

        return false;
    }

    /** Identical to `Router`'s own 404 body — see the class doc. */
    private function notFound(): ResponseInterface
    {
        return $this->json->response(404, ['ok' => false, 'error' => 'not found']);
    }

    /** @return array{0: int, 1: int} [$checked, $queued] */
    private function materialize(ClusterState $cluster): array
    {
        $checked = 0;
        $queued = 0;

        foreach ($cluster->index() as $label) {
            if (!PreviewLabel::isValid($label)) {
                // A corrupted index entry must never become a path component.
                Log::warn('drain: skipping invalid label in switchboard:index', ['label' => $label]);
                continue;
            }

            $checked++;

            $desired = $cluster->preview($label);
            if ($desired === null) {
                // The index lists a label whose preview key is gone. Post
                // switchboard#24 that means one thing in the normal course of
                // events: a retired teardown whose TTL has run out, so every
                // node has had a full day to materialize it and the index entry
                // is now the only thing left. Prune it — this is the *only*
                // place the index shrinks, and it is safe precisely because the
                // shared state it points at is already unreachable for
                // everyone, rather than about to become unreachable for
                // everyone but us.
                Log::info('drain: pruning an index entry whose desired state has expired', [
                    'label' => $label,
                ]);
                $cluster->removeFromIndex($label);
                continue;
            }

            $intent = is_string($desired['intent'] ?? null) ? $desired['intent'] : null;
            if ($intent === null) {
                continue;
            }

            $sha = is_string($desired['pull_request']['head']['sha'] ?? null)
                ? $desired['pull_request']['head']['sha']
                : '';
            $marker = $intent . '@' . $sha;
            $markerPath = $this->paths->applied() . DIRECTORY_SEPARATOR . $label;

            if (@file_get_contents($markerPath) === $marker) {
                continue;
            }

            $millis = (int) round(microtime(true) * 1000);
            $jobId = JobQueue::jobId($millis, $label . '|' . $marker . '|' . $millis);
            $job = $desired;
            $job['job_id'] = $jobId;

            try {
                $this->queue->enqueue($job, $jobId);
            } catch (StorageException $e) {
                Log::error('drain: could not enqueue job', ['label' => $label, 'reason' => $e->getMessage()]);
                continue;
            }

            try {
                $this->writer->write($markerPath, $marker);
            } catch (StorageException $e) {
                // The job is queued either way; a failed marker only means
                // the next drain re-queues it, which the daemon's own
                // coalescing (README: "Coalescing is the daemon's job")
                // already has to tolerate for rapid `synchronize` jobs.
                Log::error('drain: could not write applied marker', [
                    'label' => $label,
                    'reason' => $e->getMessage(),
                ]);
            }

            $queued++;

            if ($intent === 'teardown') {
                // Start the retirement clock rather than deleting the key.
                // This node is done with the label; the other nodes are not
                // necessarily, and there is no way from here to know. Deleting
                // here — which is what this did — made the first node to drain
                // silently withdraw the teardown from every node that had not
                // ticked yet. See ClusterState::expirePreview(). The index
                // entry stays until the key has actually expired.
                $cluster->expirePreview($label);
            }
        }

        return [$checked, $queued];
    }

    private function readLastGen(): int
    {
        $raw = @file_get_contents($this->paths->lastGen());
        if ($raw === false) {
            return 0;
        }

        $raw = trim($raw);

        return ctype_digit($raw) ? (int) $raw : 0;
    }

    private function writeLastGen(int $gen): void
    {
        try {
            $this->writer->write($this->paths->lastGen(), (string) $gen);
        } catch (StorageException $e) {
            // Not fatal: the applied/ markers already make re-materialization
            // a no-op, so the only cost of losing this write is one extra
            // full index walk on the next drain.
            Log::error('drain: could not persist last_gen', ['reason' => $e->getMessage()]);
        }
    }
}
