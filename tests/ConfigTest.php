<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Config;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

/**
 * `Switchboard\Config` — the repository allowlist in particular (issue #3).
 *
 * The allowlist must (a) actually reach this code, which on ePHPm means being
 * read from a file since nothing is injected into the vhost environment, and
 * (b) fail **closed** when unconfigured. These assert the pre-#3 behaviour as
 * the thing that must NOT happen: an unset allowlist accepting every repo.
 */
final class ConfigTest extends TestCase
{
    /** A `.switchboard` state dir with `$files` written into it; returns the app root. */
    private function stateDirWith(array $files): string
    {
        $root = Fixtures::tempDir();
        $state = $root . '/.switchboard';
        if (!mkdir($state, 0o750, true) && !is_dir($state)) {
            $this->fail('cannot create state dir: ' . $state);
        }
        foreach ($files as $name => $contents) {
            file_put_contents($state . '/' . $name, $contents);
        }

        return $root;
    }

    /** Run `$fn` with `$_SERVER[$name] = $value`, restoring `$_SERVER` afterwards. */
    private function withServer(string $name, ?string $value, callable $fn): void
    {
        $had = array_key_exists($name, $_SERVER);
        $prev = $_SERVER[$name] ?? null;
        if ($value === null) {
            unset($_SERVER[$name]);
        } else {
            $_SERVER[$name] = $value;
        }

        try {
            $fn();
        } finally {
            if ($had) {
                $_SERVER[$name] = $prev;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    // ── the defect: unconfigured must fail closed ───────────────────────────

    public function testUnconfiguredAllowlistDeniesEveryRepository(): void
    {
        // No file, no env — the exact state observed on the live cluster. Before
        // #3 this returned true (accept anything); it must now deny.
        $config = Config::load($this->stateDirWith([]));

        $this->assertFalse($config->allowlistConfigured(), 'an unconfigured allowlist must report itself unconfigured');
        $this->assertFalse($config->repoAllowed('ephpm/wordpress-sample'), 'unconfigured must fail closed, not open');
        $this->assertFalse($config->repoAllowed('anyone/anything'));
    }

    public function testAnAllowedRepoFileTakesEffect(): void
    {
        // The whole point of #3: the allowlist reaches the enforcer via a file,
        // because ePHPm injects nothing into the vhost environment.
        $config = Config::load($this->stateDirWith([
            'allowed_repos' => "# our previews only\nephpm/*\n",
        ]));

        $this->assertTrue($config->allowlistConfigured());
        $this->assertTrue($config->repoAllowed('ephpm/wordpress-sample'));
        $this->assertFalse($config->repoAllowed('someoneelse/app'));
    }

    public function testAllowedReposFileWithOnlyCommentsReadsAsUnconfigured(): void
    {
        // A file present but carrying no usable pattern is not "allow nothing
        // configured" — it is unconfigured, and so fails closed. Mirrors how the
        // webhook secret file behaves when it holds only comments.
        $config = Config::load($this->stateDirWith([
            'allowed_repos' => "# TODO: fill this in\n\n",
        ]));

        $this->assertFalse($config->allowlistConfigured());
        $this->assertFalse($config->repoAllowed('ephpm/wordpress-sample'));
    }

    // ── the env fallback still works, but the file wins ─────────────────────

    public function testAllowedReposEnvIsHonouredAsASecondarySource(): void
    {
        $root = $this->stateDirWith([]);
        $this->withServer('SWITCHBOARD_ALLOWED_REPOS', 'ephpm/*, other/repo', function () use ($root): void {
            $config = Config::load($root);
            $this->assertTrue($config->repoAllowed('ephpm/site'));
            $this->assertTrue($config->repoAllowed('other/repo'));
            $this->assertFalse($config->repoAllowed('nope/repo'));
        });
    }

    public function testTheFileWinsOverTheEnvVar(): void
    {
        $root = $this->stateDirWith(['allowed_repos' => "fromfile/*\n"]);
        $this->withServer('SWITCHBOARD_ALLOWED_REPOS', 'fromenv/*', function () use ($root): void {
            $config = Config::load($root);
            $this->assertTrue($config->repoAllowed('fromfile/x'), 'the file is the source of truth');
            $this->assertFalse($config->repoAllowed('fromenv/x'), 'the env var must not shadow the file');
        });
    }

    // ── pattern matching ────────────────────────────────────────────────────

    public function testExactAndWildcardPatternsBothMatch(): void
    {
        $config = Config::load($this->stateDirWith([
            'allowed_repos' => "ephpm/*\nsomeone/exact-repo\n",
        ]));

        $this->assertTrue($config->repoAllowed('ephpm/anything'), 'owner/* matches any repo under the owner');
        $this->assertTrue($config->repoAllowed('someone/exact-repo'), 'an exact owner/repo matches');
        $this->assertFalse($config->repoAllowed('someone/other-repo'), 'an exact pattern must not match a sibling');
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $config = Config::load($this->stateDirWith(['allowed_repos' => "EPHPM/*\n"]));

        $this->assertTrue($config->repoAllowed('ephpm/WordPress-Sample'));
    }

    public function testALookalikeOwnerDoesNotMatchAWildcard(): void
    {
        // The `/` in the compared prefix is what stops `ephpm/*` from matching a
        // different owner that merely starts with the same letters. A public App
        // makes this the difference between "our org" and an impostor.
        $config = Config::load($this->stateDirWith(['allowed_repos' => "ephpm/*\n"]));

        $this->assertFalse($config->repoAllowed('evil/ephpm-lookalike'), 'a different owner must never match ephpm/*');
        $this->assertFalse($config->repoAllowed('ephpm-evil/repo'), 'an owner prefixed with ephpm must not match');
    }

    // ── the explicit, loud opt-out ──────────────────────────────────────────

    public function testAllowAnyRepoSentinelFileOptsOutExplicitly(): void
    {
        $config = Config::load($this->stateDirWith([
            'allow_any_repo' => "yes — public preview sandbox, owner accepts the risk\n",
        ]));

        $this->assertTrue($config->allowAnyRepo);
        $this->assertTrue($config->allowlistConfigured());
        $this->assertTrue($config->repoAllowed('literally/anyone'));
    }

    public function testAnEmptyAllowAnyRepoSentinelDoesNotOptOut(): void
    {
        // An accidental empty `touch` must not silently open the gate — the
        // sentinel requires content, the fail-closed direction.
        $config = Config::load($this->stateDirWith(['allow_any_repo' => "\n# nothing here\n"]));

        $this->assertFalse($config->allowAnyRepo);
        $this->assertFalse($config->repoAllowed('literally/anyone'));
    }

    public function testAllowAnyRepoEnvFlagOptsOut(): void
    {
        $root = $this->stateDirWith([]);
        $this->withServer('SWITCHBOARD_ALLOW_ANY_REPO', '1', function () use ($root): void {
            $config = Config::load($root);
            $this->assertTrue($config->allowAnyRepo);
            $this->assertTrue($config->repoAllowed('literally/anyone'));
        });
    }
}
