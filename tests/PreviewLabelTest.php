<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Tests\Support\TestCase;
use Switchboard\Webhook\PreviewLabel;

/**
 * Cross-implementation checks against `preview_label()` in
 * `ephpm/switchboard`'s `src/webhook.rs`.
 *
 * The expected values below are the ones the Rust unit tests assert, so a
 * divergence between the two implementations fails here. That matters because
 * the label names the vhost directory: if the API and the daemon ever computed
 * it differently, previews would be deployed to a directory ePHPm does not
 * serve.
 */
final class PreviewLabelTest extends TestCase
{
    /** Matches `preview_label_verbatim_when_clean` in the Rust suite. */
    public function testVerbatimWhenClean(): void
    {
        $this->assertSame(
            'ephpm-wordpress-sample-pr-1',
            PreviewLabel::build('ephpm', 'wordpress-sample', 1),
        );
    }

    /** Matches `preview_host_format`, minus the domain the daemon appends. */
    public function testMatchesTheRustPreviewHostExample(): void
    {
        $this->assertSame('ephpm-my-blog-pr-42', PreviewLabel::build('ephpm', 'my-blog', 42));
    }

    /** Matches `preview_label_sanitized_and_hashed`. */
    public function testSanitizedIdentityGetsAHash(): void
    {
        $hash = substr(hash('sha256', 'ephpm/My_Repo#1'), 0, 6);

        $this->assertSame('ephpm-my-repo-pr-1-' . $hash, PreviewLabel::build('ephpm', 'My_Repo', 1));
    }

    /** Matches `preview_label_long_repo_truncated_and_hashed`. */
    public function testLongNamesAreTruncatedWithinTheDnsLimit(): void
    {
        $longRepo = str_repeat('a', 100);
        $label = PreviewLabel::build('ephpm', $longRepo, 7);
        $hash = substr(hash('sha256', 'ephpm/' . $longRepo . '#7'), 0, 6);

        $this->assertTrue(strlen($label) <= PreviewLabel::MAX_LABEL, 'label too long: ' . strlen($label));
        $this->assertTrue(str_ends_with($label, '-' . $hash), 'label must end in the identity hash');
        $this->assertFalse(str_ends_with(substr($label, 0, -7), '-'), 'label must not end in a dash before the hash');
    }

    /**
     * Matches `preview_label_collision_safe`. Two identities that normalize to
     * the same base must not land on the same hostname — otherwise one PR's
     * preview would silently overwrite another's.
     */
    public function testDistinctIdentitiesNeverCollide(): void
    {
        $a = PreviewLabel::build('ephpm', 'My-Repo', 1);
        $b = PreviewLabel::build('ephpm', 'my_repo', 1);

        $this->assertTrue(str_starts_with($a, 'ephpm-my-repo-pr-1-'));
        $this->assertTrue(str_starts_with($b, 'ephpm-my-repo-pr-1-'));
        $this->assertTrue($a !== $b, 'distinct identities collided: ' . $a);
    }

    public function testEveryLabelIsDnsSafe(): void
    {
        $cases = [
            ['ephpm', 'wordpress-sample', 1],
            ['ephpm', 'My_Repo', 1],
            ['ACME.Corp', 'some repo!', 999],
            ['a', str_repeat('b', 200), 123456],
            ['-leading', 'trailing-', 5],
            ['ünïcode', 'répo', 3],
            ['a__b', 'c--d', 1],
        ];

        foreach ($cases as [$owner, $repo, $number]) {
            $label = PreviewLabel::build($owner, $repo, $number);

            $this->assertMatches('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label, "not DNS-safe: {$label}");
            $this->assertTrue(strlen($label) <= PreviewLabel::MAX_LABEL, "too long: {$label}");
            $this->assertTrue(PreviewLabel::isValid($label), "isValid disagrees with build(): {$label}");
        }
    }

    /**
     * Multi-byte input: the Rust maps one non-ASCII *character* to one `-`,
     * PHP maps each of its *bytes*, and the run-collapsing makes both land on
     * the same string. Checked explicitly because it is the one place the port
     * is not a literal transliteration.
     */
    public function testMultibyteInputMatchesTheCharWiseRustBehaviour(): void
    {
        // "é" is two bytes; "ünïcode" would differ if runs were not collapsed.
        $this->assertSame(
            'ephpm-r-po-pr-1-' . substr(hash('sha256', 'ephpm/répo#1'), 0, 6),
            PreviewLabel::build('ephpm', 'répo', 1),
        );
    }

    public function testIsValidRejectsPathAndHostTricks(): void
    {
        foreach ([
            '',
            '.',
            '..',
            '../../etc/passwd',
            'has.dot',
            'UPPER',
            '-leading',
            'trailing-',
            'has_underscore',
            str_repeat('a', 64),
            "nul\0byte",
        ] as $bad) {
            $this->assertFalse(PreviewLabel::isValid($bad), 'accepted an invalid label: ' . $bad);
        }
    }

    public function testBoundaryLengthIsUsedVerbatim(): void
    {
        // Exactly 63 characters and unchanged by sanitization: verbatim, no hash.
        $repo = str_repeat('a', 63 - strlen('o-') - strlen('-pr-1'));
        $label = PreviewLabel::build('o', $repo, 1);

        $this->assertSame(63, strlen($label));
        $this->assertSame('o-' . $repo . '-pr-1', $label);
    }
}
