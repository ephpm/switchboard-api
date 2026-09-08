<?php

declare(strict_types=1);

namespace Switchboard;

use Switchboard\Storage\SecretFile;

/**
 * Runtime configuration.
 *
 * # Where the webhook secret comes from
 *
 * Two sources, in this precedence order:
 *
 *  1. `.switchboard/webhook_secret` — **the recommended source.**
 *  2. `SWITCHBOARD_WEBHOOK_SECRET` in the environment — a fallback for
 *     container deployments.
 *
 * The file wins because of how ePHPm multi-tenancy actually works: the process
 * environment is shared by every vhost in the ePHPm process, so any other site
 * on the same instance could read `SWITCHBOARD_WEBHOOK_SECRET` with `getenv()`.
 * A file inside this vhost's directory is covered by this vhost's
 * `open_basedir` and by no other's, which is the isolation boundary ePHPm
 * actually enforces. Prefer the file whenever switchboard-api shares an
 * instance with anything else.
 *
 * The file may hold several secrets, one per line. A signature matching *any*
 * of them is accepted, which is what makes GitHub webhook secret rotation
 * possible without a window of rejected deliveries. Blank lines and `#`
 * comments are ignored.
 *
 * # The repository allowlist comes from a file too (issue #3)
 *
 * The allowlist follows the **exact same file-first pattern** as the webhook
 * secret, and for the same reason: on ePHPm the process environment is shared
 * across vhosts, and — more to the point — ePHPm injects *nothing* into this
 * vhost's environment, so a value that lives only in `SWITCHBOARD_ALLOWED_REPOS`
 * never reaches this code and the allowlist silently never applies. Reading
 * `.switchboard/allowed_repos` (one `owner/repo` or `owner/*` pattern per line,
 * `#` comments and blank lines ignored) is what makes it actually take effect on
 * a live node.
 *
 * **It fails closed.** When neither the file nor `SWITCHBOARD_ALLOWED_REPOS`
 * provides an allowlist, {@see repoAllowed()} denies *every* repository. A
 * public GitHub App has a single webhook URL and one secret, so every
 * installer's deliveries are validly signed; an unconfigured allowlist that
 * defaulted open (the pre-#3 behaviour) let any GitHub user who installed the
 * App deploy arbitrary `build:`/`seed:` commands onto the node. Unrestricted
 * operation is still possible, but only as a loud, explicit opt-in — a
 * `.switchboard/allow_any_repo` sentinel file (any non-comment content) or
 * `SWITCHBOARD_ALLOW_ANY_REPO=1` — never something reached by omission. This is
 * the ePHPm "an operator's intended restriction must not silently become a
 * no-op" rule (ephpm#429/#463/#473).
 *
 * # Reading the environment
 *
 * {@see env()} checks `$_SERVER` before `getenv()`, and that order is
 * load-bearing on ePHPm: values the server injects per request — `DB_USER`,
 * `DB_PASSWORD`, `EPHPM_REDIS_*` — arrive through the SAPI's
 * `register_server_variables` hook and land in **`$_SERVER` only**. Neither
 * `getenv()` nor `$_ENV` sees them. Nothing here needs those particular
 * variables today (this service uses no database; its cluster-mode KV use
 * goes through the `ephpm_kv_*` SAPI functions, which need no injected
 * credentials), but any configuration added later must read `$_SERVER` or it
 * will silently find nothing.
 */
final class Config
{
    /** GitHub caps webhook payloads at 25 MiB; anything larger is not from GitHub. */
    public const DEFAULT_MAX_BODY_BYTES = 26_214_400;

    /**
     * @param list<string>      $webhookSecrets Accepted webhook secrets (rotation-friendly).
     * @param list<string>|null $allowedRepos   `owner/repo` or `owner/*` patterns; null = unconfigured (fails closed).
     * @param bool              $allowAnyRepo   Explicit opt-in to accept every repository — bypasses the allowlist.
     */
    private function __construct(
        public readonly string $appRoot,
        public readonly string $stateDir,
        public readonly array $webhookSecrets,
        public readonly string $githubHost,
        public readonly bool $allowForks,
        public readonly ?array $allowedRepos,
        public readonly bool $allowAnyRepo,
        public readonly int $maxBodyBytes,
    ) {
    }

    public static function load(string $appRoot): self
    {
        $appRoot = rtrim($appRoot, '/\\');
        $stateDir = rtrim(self::env('SWITCHBOARD_STATE_DIR') ?? $appRoot . DIRECTORY_SEPARATOR . '.switchboard', '/\\');

        return new self(
            appRoot: $appRoot,
            stateDir: $stateDir,
            webhookSecrets: self::secrets($stateDir . DIRECTORY_SEPARATOR . 'webhook_secret', 'SWITCHBOARD_WEBHOOK_SECRET'),
            githubHost: strtolower(self::env('SWITCHBOARD_GITHUB_HOST') ?? 'github.com'),
            allowForks: self::flag('SWITCHBOARD_ALLOW_FORKS', false),
            allowedRepos: self::allowlist($stateDir . DIRECTORY_SEPARATOR . 'allowed_repos', 'SWITCHBOARD_ALLOWED_REPOS'),
            allowAnyRepo: self::sentinel($stateDir . DIRECTORY_SEPARATOR . 'allow_any_repo')
                || self::flag('SWITCHBOARD_ALLOW_ANY_REPO', false),
            maxBodyBytes: self::intEnv('SWITCHBOARD_MAX_BODY_BYTES', self::DEFAULT_MAX_BODY_BYTES),
        );
    }

    /**
     * Build a config directly — used by tests and by anything that wants to
     * drive the app without touching the environment.
     *
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $appRoot = rtrim((string) ($values['appRoot'] ?? ''), '/\\');

        return new self(
            appRoot: $appRoot,
            stateDir: rtrim((string) ($values['stateDir'] ?? $appRoot . DIRECTORY_SEPARATOR . '.switchboard'), '/\\'),
            webhookSecrets: array_values($values['webhookSecrets'] ?? []),
            githubHost: strtolower((string) ($values['githubHost'] ?? 'github.com')),
            allowForks: (bool) ($values['allowForks'] ?? false),
            allowedRepos: $values['allowedRepos'] ?? null,
            allowAnyRepo: (bool) ($values['allowAnyRepo'] ?? false),
            maxBodyBytes: (int) ($values['maxBodyBytes'] ?? self::DEFAULT_MAX_BODY_BYTES),
        );
    }

    /**
     * Is an allowlist (or an explicit allow-any opt-in) in force at all?
     *
     * `false` means the operator configured nothing — which {@see repoAllowed()}
     * treats as "deny everything", not "allow everything". Surfaced on
     * `/healthz` and named in the webhook rejection log so the misconfiguration
     * is visible rather than silent.
     */
    public function allowlistConfigured(): bool
    {
        return $this->allowAnyRepo || ($this->allowedRepos !== null && $this->allowedRepos !== []);
    }

    /**
     * Is `owner/repo` allowed to enqueue jobs?
     *
     * Fails **closed**: an unconfigured allowlist (`null` or empty) denies every
     * repository. Only the explicit `allowAnyRepo` opt-in accepts everything —
     * see the class docblock and issue #3.
     */
    public function repoAllowed(string $fullName): bool
    {
        if ($this->allowAnyRepo) {
            return true;
        }

        if ($this->allowedRepos === null || $this->allowedRepos === []) {
            return false;
        }

        $fullName = strtolower($fullName);
        foreach ($this->allowedRepos as $pattern) {
            $pattern = strtolower($pattern);
            if ($pattern === $fullName) {
                return true;
            }
            if (str_ends_with($pattern, '/*') && str_starts_with($fullName, substr($pattern, 0, -1))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a secret from its file, falling back to an environment variable.
     *
     * @return list<string>
     */
    private static function secrets(string $file, string $envName): array
    {
        $lines = SecretFile::read($file);
        if ($lines !== []) {
            return $lines;
        }

        $fromEnv = self::env($envName);

        return $fromEnv === null ? [] : [$fromEnv];
    }

    /**
     * Read the repository allowlist from its file, falling back to an
     * environment variable. Mirrors {@see secrets()} — the file is the source
     * that actually reaches this code on ePHPm (nothing is injected into the
     * vhost environment). `null` means "unconfigured", which the caller treats
     * as fail-closed; a file present but holding only comments/blanks reads as
     * unconfigured too, exactly like the secret file.
     *
     * @return list<string>|null
     */
    private static function allowlist(string $file, string $envName): ?array
    {
        $lines = SecretFile::read($file);
        if ($lines !== []) {
            return $lines;
        }

        $fromEnv = self::env($envName);

        return $fromEnv === null ? null : self::splitList($fromEnv);
    }

    /**
     * A sentinel file is "set" when it exists and carries at least one
     * non-comment, non-blank line. Requiring content (rather than mere
     * presence) keeps an accidental empty `touch` from silently opening the
     * gate — the fail-closed direction. Any token works; `# why` comments are
     * ignored, so leave a note about *who* opted in and keep a real line too.
     */
    private static function sentinel(string $file): bool
    {
        return SecretFile::read($file) !== [];
    }

    /** `$_SERVER` first — see the class docblock; ePHPm-injected values live there only. */
    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function flag(string $name, bool $default): bool
    {
        $raw = self::env($name);

        return $raw === null ? $default : in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private static function intEnv(string $name, int $default): int
    {
        $raw = self::env($name);

        return $raw !== null && ctype_digit($raw) ? (int) $raw : $default;
    }

    /** @return list<string> */
    private static function splitList(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }
}
