<?php

declare(strict_types=1);

namespace Switchboard\Webhook;

/**
 * The subset of a GitHub `pull_request` webhook payload the daemon acts on.
 *
 * Ported from `PullRequestEvent` in `ephpm/switchboard`'s `src/webhook.rs`,
 * with validation added. The Rust version deserialized straight into typed
 * structs, which enforced *types* but not *shapes* — a branch called
 * `--upload-pack=...` or a SHA containing a newline would have been passed
 * through to `git`.
 *
 * A signed payload is authentic, not harmless: it is still text that ends up as
 * an argument to `git` and as a path component in the daemon. Validating at
 * this boundary means the daemon receives values that have already been
 * constrained, and a job file can be trusted by its consumer.
 */
final class PullRequestEvent
{
    /** Actions that should produce (or refresh) a preview. */
    public const DEPLOY_ACTIONS = ['opened', 'synchronize', 'reopened'];

    /** Actions that should remove a preview. */
    public const TEARDOWN_ACTIONS = ['closed'];

    private function __construct(
        public readonly string $action,
        public readonly int $number,
        public readonly string $repoFullName,
        public readonly string $repoOwner,
        public readonly string $repoName,
        public readonly string $repoCloneUrl,
        public readonly ?string $repoDefaultBranch,
        public readonly bool $repoPrivate,
        public readonly string $headRef,
        public readonly string $headSha,
        public readonly ?string $headCloneUrl,
        public readonly ?string $headRepoFullName,
        public readonly string $baseRef,
        public readonly ?string $title,
        public readonly bool $draft,
        public readonly bool $merged,
        public readonly ?string $sender,
        public readonly ?int $installationId,
        public readonly bool $fork,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Decoded webhook body.
     * @param string               $githubHost Host that clone URLs must belong to.
     *
     * @throws InvalidPayloadException
     */
    public static function fromPayload(array $payload, string $githubHost): self
    {
        $action = self::str($payload, 'action');
        $repo = self::obj($payload, 'repository');
        $pr = self::obj($payload, 'pull_request');
        $head = self::obj($pr, 'head');
        $base = self::obj($pr, 'base');

        $owner = self::name(self::str(self::obj($repo, 'owner'), 'login', 'repository.owner.login'), 'repository.owner.login');
        $name = self::name(self::str($repo, 'name'), 'repository.name');
        $fullName = self::str($repo, 'full_name');

        if (strtolower($fullName) !== strtolower($owner . '/' . $name)) {
            throw new InvalidPayloadException('repository.full_name disagrees with owner/name');
        }

        // `number` is at the top level; `pull_request.number` mirrors it. They
        // must agree — a payload where they differ is not something to guess at.
        $number = self::positiveInt($payload, 'number');
        if (isset($pr['number']) && is_int($pr['number']) && $pr['number'] !== $number) {
            throw new InvalidPayloadException('number disagrees with pull_request.number');
        }

        $headRepo = isset($head['repo']) && is_array($head['repo']) ? $head['repo'] : null;
        $headRepoFullName = $headRepo !== null && isset($headRepo['full_name']) && is_string($headRepo['full_name'])
            ? $headRepo['full_name']
            : null;

        // A missing head repo means the fork was deleted; treat that as a fork
        // too, since the head is not reachable from the base repository's own
        // branches. `refs/pull/<n>/head` remains the reliable way to fetch it.
        $fork = $headRepoFullName === null || strtolower($headRepoFullName) !== strtolower($fullName);

        return new self(
            action: $action,
            number: $number,
            repoFullName: $fullName,
            repoOwner: $owner,
            repoName: $name,
            repoCloneUrl: self::cloneUrl(self::str($repo, 'clone_url'), $githubHost, 'repository.clone_url'),
            repoDefaultBranch: isset($repo['default_branch']) && is_string($repo['default_branch'])
                ? self::gitRef($repo['default_branch'], 'repository.default_branch')
                : null,
            repoPrivate: (bool) ($repo['private'] ?? false),
            headRef: self::gitRef(self::str($head, 'ref'), 'pull_request.head.ref'),
            headSha: self::sha(self::str($head, 'sha'), 'pull_request.head.sha'),
            headCloneUrl: $headRepo !== null && isset($headRepo['clone_url']) && is_string($headRepo['clone_url'])
                ? self::cloneUrl($headRepo['clone_url'], $githubHost, 'pull_request.head.repo.clone_url')
                : null,
            headRepoFullName: $headRepoFullName,
            baseRef: self::gitRef(self::str($base, 'ref'), 'pull_request.base.ref'),
            title: isset($pr['title']) && is_string($pr['title']) ? mb_substr($pr['title'], 0, 200) : null,
            draft: (bool) ($pr['draft'] ?? false),
            merged: (bool) ($pr['merged'] ?? false),
            sender: isset($payload['sender']['login']) && is_string($payload['sender']['login'])
                ? $payload['sender']['login']
                : null,
            installationId: isset($payload['installation']['id']) && is_int($payload['installation']['id'])
                ? $payload['installation']['id']
                : null,
            fork: $fork,
        );
    }

    public function shouldDeploy(): bool
    {
        return in_array($this->action, self::DEPLOY_ACTIONS, true);
    }

    public function shouldTeardown(): bool
    {
        return in_array($this->action, self::TEARDOWN_ACTIONS, true);
    }

    /** `deploy`, `teardown`, or null when the action is not actionable. */
    public function intent(): ?string
    {
        return match (true) {
            $this->shouldDeploy() => 'deploy',
            $this->shouldTeardown() => 'teardown',
            default => null,
        };
    }

    public function label(): string
    {
        return PreviewLabel::build($this->repoOwner, $this->repoName, $this->number);
    }

    /**
     * The clone URL for the PR's head, matching the Rust's fork handling: the
     * head repository's URL when present, otherwise the base repository's.
     */
    public function effectiveCloneUrl(): string
    {
        return $this->headCloneUrl ?? $this->repoCloneUrl;
    }

    /** The ref that resolves the head from the *base* repository, forks included. */
    public function pullRef(): string
    {
        return 'refs/pull/' . $this->number . '/head';
    }

    // ── payload accessors ───────────────────────────────────────────────

    /** @param array<string, mixed> $source */
    private static function obj(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!is_array($value)) {
            throw new InvalidPayloadException("missing or non-object field: {$key}");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     * @param string|null          $label Field name for the error message, when
     *                                    the key alone would not identify it.
     */
    private static function str(array $source, string $key, ?string $label = null): string
    {
        $value = $source[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidPayloadException('missing or non-string field: ' . ($label ?? $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private static function positiveInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!is_int($value) || $value < 1 || $value > 1_000_000_000) {
            throw new InvalidPayloadException("missing or out-of-range integer field: {$key}");
        }

        return $value;
    }

    // ── validators ──────────────────────────────────────────────────────

    /** A GitHub login or repository name. */
    private static function name(string $value, string $field): string
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) !== 1 || $value === '.' || $value === '..') {
            throw new InvalidPayloadException("invalid {$field}");
        }

        return $value;
    }

    /**
     * A branch name safe to hand to `git`.
     *
     * Rejects the leading `-` that `git` would read as an option, `..` (which
     * has meaning in revision syntax), and anything outside a conservative
     * character set. Argument-vector execution already prevents shell
     * injection; this prevents argument injection, which it does not.
     */
    private static function gitRef(string $value, string $field): string
    {
        if (strlen($value) > 255
            || str_starts_with($value, '-')
            || str_contains($value, '..')
            || preg_match('#^[A-Za-z0-9._/+-]+$#', $value) !== 1
        ) {
            throw new InvalidPayloadException("invalid {$field}");
        }

        return $value;
    }

    /** A commit id: 40 hex (SHA-1) or 64 hex (SHA-256 repositories). */
    private static function sha(string $value, string $field): string
    {
        if (preg_match('/^[0-9a-f]{40}$|^[0-9a-f]{64}$/i', $value) !== 1) {
            throw new InvalidPayloadException("invalid {$field}");
        }

        return strtolower($value);
    }

    /**
     * An `https://` clone URL on the expected GitHub host.
     *
     * The payload is signed, so this is not the main line of defence — but it
     * pins the one field that decides *where the daemon fetches code from*, and
     * a job file that can only ever point at the configured host is a stronger
     * contract to hand a consumer than one that could point anywhere.
     */
    private static function cloneUrl(string $value, string $githubHost, string $field): string
    {
        $parts = parse_url($value);
        if ($parts === false
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== $githubHost
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new InvalidPayloadException("invalid {$field}");
        }

        return $value;
    }
}
