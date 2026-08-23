<?php

declare(strict_types=1);

namespace Switchboard\Queue;

use Switchboard\Webhook\PullRequestEvent;

/**
 * The job document — the contract between switchboard-api and the daemon.
 *
 * Schema version 1. Every field the daemon needs to clone, build, deploy or
 * tear down a preview without calling back into the API, derived from what
 * `deployer.rs` actually consumes:
 *
 *   * `clone_url`, `head.ref`, `head.sha`  → `clone_checkout()`
 *   * `repository.owner`/`name`/`number`   → the preview host
 *   * `repository.full_name`               → per-repo secret scope in `secrets.rs`
 *   * `installation_id`                    → the GitHub App token for PR comments
 *
 * Two fields are new relative to what the Rust derived for itself, and both are
 * deliberate:
 *
 *   * **`preview.label`** is computed here and is authoritative. The daemon
 *     appends its configured preview domain to it; it must not recompute the
 *     label. One producer for the identity means API and daemon cannot drift
 *     into disagreeing about which directory a PR maps to.
 *   * **`head.pull_ref`** (`refs/pull/<n>/head`) resolves the head commit from
 *     the *base* repository. For a fork PR that is the reliable fetch path,
 *     and it does not require trusting a third-party clone URL.
 *
 * The document is written once and never mutated. Progress and results travel
 * the other way, through `status/` — see {@see StatusStore}.
 */
final class Job
{
    public const SCHEMA = 1;

    /**
     * @param string $jobId      Unique per enqueue, sortable, safe as a filename.
     * @param string $deliveryId The `X-GitHub-Delivery` GUID this job came from.
     * @param string $intent     `deploy` or `teardown`.
     *
     * @return array<string, mixed>
     */
    public static function build(
        PullRequestEvent $event,
        string $jobId,
        string $deliveryId,
        string $intent,
        int $receivedAtMillis,
    ): array {
        return [
            'schema' => self::SCHEMA,
            'job_id' => $jobId,
            'delivery_id' => $deliveryId,
            'event' => 'pull_request',
            'action' => $event->action,
            'intent' => $intent,
            'received_at' => self::rfc3339($receivedAtMillis),
            'received_at_ms' => $receivedAtMillis,

            'preview' => [
                'label' => $event->label(),
            ],

            'repository' => [
                'full_name' => $event->repoFullName,
                'owner' => $event->repoOwner,
                'name' => $event->repoName,
                'clone_url' => $event->repoCloneUrl,
                'default_branch' => $event->repoDefaultBranch,
                'private' => $event->repoPrivate,
            ],

            'pull_request' => [
                'number' => $event->number,
                'title' => $event->title,
                'draft' => $event->draft,
                'merged' => $event->merged,
                'fork' => $event->fork,
                'head' => [
                    'ref' => $event->headRef,
                    'sha' => $event->headSha,
                    'clone_url' => $event->effectiveCloneUrl(),
                    'repo_full_name' => $event->headRepoFullName,
                    'pull_ref' => $event->pullRef(),
                ],
                'base' => [
                    'ref' => $event->baseRef,
                ],
            ],

            'sender' => $event->sender,
            'installation_id' => $event->installationId,
        ];
    }

    /** Millisecond-precision UTC timestamp, RFC 3339. */
    public static function rfc3339(int $millis): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000))
            . sprintf('.%03dZ', $millis % 1000);
    }
}
