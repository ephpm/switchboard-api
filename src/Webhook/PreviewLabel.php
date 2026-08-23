<?php

declare(strict_types=1);

namespace Switchboard\Webhook;

/**
 * The DNS label identifying a PR preview: `<owner>-<repo>-pr-<N>`, normalized.
 *
 * A **port of `preview_label()` from `ephpm/switchboard`'s `src/webhook.rs`**,
 * and it must stay byte-for-byte identical to it. The label is the primary key
 * of a preview: it names the directory under `sites_dir`, the vhost ePHPm
 * resolves, the status file, and the host in the PR comment. API and daemon
 * disagreeing about it would mean deploying to a directory nobody serves.
 *
 * That is why the **job file carries the label** and the daemon is expected to
 * use it verbatim rather than recomputing it. This implementation exists so the
 * API can name the preview in status files and the UI, not so the value can be
 * derived twice.
 *
 * The rules, unchanged from the Rust:
 *
 *  * Everything outside `[a-z0-9-]` becomes `-`, runs of `-` collapse, and
 *    leading/trailing `-` are trimmed.
 *  * If normalization changed nothing and the result fits in 63 characters
 *    (RFC 1035), it is used verbatim — previews get readable hostnames.
 *  * Otherwise 6 hex characters of `sha256("owner/repo#N")` are appended, over
 *    the *pre-normalization* identity, so two identities that normalize alike
 *    (`My-Repo` and `my_repo`) can never collide onto one host.
 *
 * Keeping the whole identity in a single label means one wildcard certificate
 * for `*.{domain}` covers every preview.
 */
final class PreviewLabel
{
    /** Maximum length of a single DNS label (RFC 1035). */
    public const MAX_LABEL = 63;

    /** Hex characters of the identity hash appended on collision/overflow. */
    public const HASH_LEN = 6;

    public static function build(string $owner, string $repo, int $number): string
    {
        $raw = $owner . '-' . $repo . '-pr-' . $number;
        $sanitized = self::sanitize($raw);

        // Verbatim only when nothing was lost to sanitization and it fits.
        if ($sanitized === $raw && strlen($sanitized) <= self::MAX_LABEL) {
            return $sanitized;
        }

        $hash = self::identityHash($owner, $repo, $number);
        $maxBase = self::MAX_LABEL - self::HASH_LEN - 1; // room for '-' + hash

        $base = strlen($sanitized) > $maxBase ? substr($sanitized, 0, $maxBase) : $sanitized;
        $base = rtrim($base, '-');

        return $base . '-' . $hash;
    }

    /** Is `$label` something this class could have produced? */
    public static function isValid(string $label): bool
    {
        return $label !== ''
            && strlen($label) <= self::MAX_LABEL
            && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) === 1;
    }

    /**
     * Lowercase, `[a-z0-9-]` only, runs of `-` collapsed, ends trimmed.
     *
     * Byte-wise where the Rust is char-wise, which lands on the same answer:
     * a multi-byte character maps to one `-` in Rust and to several in PHP, but
     * the run-collapsing reduces those to the single `-` Rust produced.
     */
    private static function sanitize(string $raw): string
    {
        $out = '';
        $prevDash = false;

        for ($i = 0, $len = strlen($raw); $i < $len; $i++) {
            $char = strtolower($raw[$i]);
            $isAlnum = ($char >= 'a' && $char <= 'z') || ($char >= '0' && $char <= '9');

            if ($isAlnum) {
                $out .= $char;
                $prevDash = false;
            } elseif (!$prevDash) {
                $out .= '-';
                $prevDash = true;
            }
        }

        return trim($out, '-');
    }

    /** First {@see HASH_LEN} hex characters of `sha256("owner/repo#N")`. */
    private static function identityHash(string $owner, string $repo, int $number): string
    {
        return substr(hash('sha256', $owner . '/' . $repo . '#' . $number), 0, self::HASH_LEN);
    }
}
