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
 * # Reading the environment
 *
 * {@see env()} checks `$_SERVER` before `getenv()`, and that order is
 * load-bearing on ePHPm: values the server injects per request — `DB_USER`,
 * `DB_PASSWORD`, `EPHPM_REDIS_*` — arrive through the SAPI's
 * `register_server_variables` hook and land in **`$_SERVER` only**. Neither
 * `getenv()` nor `$_ENV` sees them. Nothing here needs those particular
 * variables today (this service uses no database and no KV store), but any
 * configuration added later must read `$_SERVER` or it will silently find
 * nothing.
 */
final class Config
{
    /** GitHub caps webhook payloads at 25 MiB; anything larger is not from GitHub. */
    public const DEFAULT_MAX_BODY_BYTES = 26_214_400;

    /**
     * @param list<string>      $webhookSecrets Accepted webhook secrets (rotation-friendly).
     * @param list<string>|null $allowedRepos   `owner/repo` or `owner/*` patterns; null = no restriction.
     */
    private function __construct(
        public readonly string $appRoot,
        public readonly string $stateDir,
        public readonly array $webhookSecrets,
        public readonly string $githubHost,
        public readonly bool $allowForks,
        public readonly ?array $allowedRepos,
        public readonly int $maxBodyBytes,
    ) {
    }

    public static function load(string $appRoot): self
    {
        $appRoot = rtrim($appRoot, '/\\');
        $stateDir = rtrim(self::env('SWITCHBOARD_STATE_DIR') ?? $appRoot . DIRECTORY_SEPARATOR . '.switchboard', '/\\');
        $allowedRepos = self::env('SWITCHBOARD_ALLOWED_REPOS');

        return new self(
            appRoot: $appRoot,
            stateDir: $stateDir,
            webhookSecrets: self::secrets($stateDir . DIRECTORY_SEPARATOR . 'webhook_secret', 'SWITCHBOARD_WEBHOOK_SECRET'),
            githubHost: strtolower(self::env('SWITCHBOARD_GITHUB_HOST') ?? 'github.com'),
            allowForks: self::flag('SWITCHBOARD_ALLOW_FORKS', false),
            allowedRepos: $allowedRepos === null ? null : self::splitList($allowedRepos),
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
            maxBodyBytes: (int) ($values['maxBodyBytes'] ?? self::DEFAULT_MAX_BODY_BYTES),
        );
    }

    /** Is `owner/repo` allowed to enqueue jobs? */
    public function repoAllowed(string $fullName): bool
    {
        if ($this->allowedRepos === null) {
            return true;
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
