<?php

declare(strict_types=1);

namespace Switchboard\Storage;

/**
 * The state directory layout — the on-disk half of the daemon contract.
 *
 * Everything lives under a single **dot-prefixed** directory (`.switchboard/`
 * by default) inside the vhost. That is not cosmetic. ePHPm serves the vhost
 * directory itself as the document root, so any file placed in it is a
 * candidate for static serving. ePHPm's router rejects a request whose path
 * contains **any** dot-prefixed segment (`has_hidden_segment` in
 * `crates/ephpm-server/src/router.rs`), and `[server.static] hidden_files`
 * defaults to `"deny"` — so `.switchboard/…` is answered `403` before either
 * the static handler or the PHP handler sees it. Job files and the webhook
 * secret are therefore unreachable over HTTP under the server's own defaults,
 * which is verified live rather than assumed (see the README).
 *
 *     .switchboard/
 *       webhook_secret     ← operator-provided (never committed)
 *       drain_secret       ← operator-provided, gates GET /drain (never committed)
 *       tmp/               ← staging for atomic writes; same filesystem as the rest
 *       queue/             ← API writes jobs here, daemon consumes   (API → daemon)
 *       queue/claimed/     ← daemon moves jobs here while working
 *       deliveries/        ← dedup markers, one per X-GitHub-Delivery
 *       applied/           ← cluster mode: one marker per label, this NODE's
 *                             last materialized `<intent>@<sha>` — see DrainHandler
 *       last_gen           ← cluster mode: this node's last-seen `switchboard:gen`
 *
 * There is deliberately **no status directory**. Preview state is reported to
 * GitHub by the daemon through the Deployments API, so nothing flows back to
 * this service and it holds no view of a preview's progress. That keeps the
 * API write-only, which is the property that makes it safe to expose.
 *
 * `applied/` and `last_gen` exist only for cluster mode ({@see
 * \Switchboard\Cluster\DrainHandler}) and are harmless, unused files in
 * single-node deployments.
 *
 * `tmp/` sits inside the same tree on purpose: an atomic write is "write to a
 * temp file, then `rename()` it into place", and `rename()` is only atomic
 * within one filesystem. `sys_get_temp_dir()` would be a different mount on
 * most real deployments, turning the rename into a copy — exactly the
 * non-atomic behaviour this design exists to avoid.
 */
final class Paths
{
    public function __construct(public readonly string $stateDir)
    {
    }

    public function tmp(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'tmp';
    }

    public function queue(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'queue';
    }

    public function claimed(): string
    {
        return $this->queue() . DIRECTORY_SEPARATOR . 'claimed';
    }

    public function deliveries(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'deliveries';
    }

    /** Cluster mode only: this node's last-materialized marker per label. */
    public function applied(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'applied';
    }

    /** Cluster mode only: gates `GET /drain` — see `DrainHandler`. */
    public function drainSecret(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'drain_secret';
    }

    /** Cluster mode only: this node's last-seen `switchboard:gen`. */
    public function lastGen(): string
    {
        return $this->stateDir . DIRECTORY_SEPARATOR . 'last_gen';
    }

    /** Create the directory tree. Idempotent. */
    public function ensure(): void
    {
        $dirs = [
            $this->stateDir,
            $this->tmp(),
            $this->queue(),
            $this->claimed(),
            $this->deliveries(),
            $this->applied(),
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new StorageException('cannot create state directory: ' . $dir);
            }
        }
    }
}
