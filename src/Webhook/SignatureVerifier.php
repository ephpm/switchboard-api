<?php

declare(strict_types=1);

namespace Switchboard\Webhook;

/**
 * `X-Hub-Signature-256` verification — HMAC-SHA256 over the raw request body.
 *
 * This is the only thing standing between the public internet and the job
 * queue, so a few properties are non-negotiable:
 *
 *  * **Compared with `hash_equals()`, never `==` or `===`.** String equality in
 *    PHP short-circuits on the first differing byte, which leaks the length of
 *    the matching prefix through timing. `hash_equals()` is constant-time over
 *    equal-length inputs.
 *  * **Computed over the raw body**, never a re-encoded payload. `json_decode`
 *    followed by `json_encode` does not round-trip byte-for-byte (key order,
 *    escaping, float formatting), so a re-serialized body would fail against a
 *    signature GitHub computed correctly.
 *  * **Fail closed.** No configured secret means every delivery is rejected.
 *    There is no "unsigned is fine" mode.
 *
 * Several secrets may be configured at once. GitHub signs with exactly one, but
 * accepting any of them is what lets a secret be rotated without a window in
 * which deliveries bounce: add the new secret, update the GitHub App, remove
 * the old one. All candidates are checked with no early exit, so verification
 * time does not depend on which secret matched.
 */
final class SignatureVerifier
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const MALFORMED = 'malformed';
    public const MISMATCH = 'mismatch';
    public const NOT_CONFIGURED = 'not_configured';

    /** @param list<string> $secrets */
    public function __construct(private readonly array $secrets)
    {
    }

    public function isConfigured(): bool
    {
        return $this->secrets !== [];
    }

    /**
     * Verify `$header` against `$rawBody`.
     *
     * @return string One of the class constants. Only {@see OK} means accepted.
     */
    public function verify(string $rawBody, ?string $header): string
    {
        if ($this->secrets === []) {
            return self::NOT_CONFIGURED;
        }

        if ($header === null || $header === '') {
            return self::MISSING;
        }

        // GitHub sends `sha256=` + 64 lowercase hex characters. Anything else
        // is rejected before any HMAC work: a malformed header can never match,
        // and the shape check keeps attacker-controlled text out of hash_equals.
        if (preg_match('/^sha256=([0-9a-fA-F]{64})$/', $header, $matches) !== 1) {
            return self::MALFORMED;
        }

        $provided = strtolower($matches[1]);

        $matched = false;
        foreach ($this->secrets as $secret) {
            $expected = hash_hmac('sha256', $rawBody, $secret);
            // `|` not `||`: no short-circuit, so the number of HMACs computed
            // does not depend on which secret (if any) matched.
            $matched = hash_equals($expected, $provided) | $matched;
        }

        return $matched ? self::OK : self::MISMATCH;
    }
}
