<?php

declare(strict_types=1);

namespace Switchboard\Tests;

use Switchboard\Tests\Support\TestCase;
use Switchboard\Webhook\SignatureVerifier;

/**
 * Signature verification — the boundary between the public internet and the
 * job queue. Every rejection case here is a way in if it regresses.
 */
final class SignatureVerifierTest extends TestCase
{
    private const SECRET = 'It\'s a Secret to Everybody';

    /** GitHub's own documented example vector, so this is checked against a known-good digest. */
    public function testAcceptsGitHubDocumentedVector(): void
    {
        $verifier = new SignatureVerifier(['It\'s a Secret to Everybody']);
        $body = 'Hello, World!';
        $signature = 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17';

        $this->assertSame(SignatureVerifier::OK, $verifier->verify($body, $signature));
    }

    public function testAcceptsValidSignature(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $body = '{"action":"opened","number":1}';

        $this->assertSame(
            SignatureVerifier::OK,
            $verifier->verify($body, 'sha256=' . hash_hmac('sha256', $body, self::SECRET)),
        );
    }

    public function testRejectsWrongSignature(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $body = '{"action":"opened"}';

        // A well-formed signature computed with the wrong secret.
        $wrong = 'sha256=' . hash_hmac('sha256', $body, 'not-the-secret');

        $this->assertSame(SignatureVerifier::MISMATCH, $verifier->verify($body, $wrong));
    }

    public function testRejectsAllZeroSignature(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);

        $this->assertSame(
            SignatureVerifier::MISMATCH,
            $verifier->verify('body', 'sha256=' . str_repeat('0', 64)),
        );
    }

    public function testRejectsMissingSignature(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);

        $this->assertSame(SignatureVerifier::MISSING, $verifier->verify('body', null));
        $this->assertSame(SignatureVerifier::MISSING, $verifier->verify('body', ''));
    }

    public function testRejectsMalformedSignature(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $body = 'body';
        $valid = hash_hmac('sha256', $body, self::SECRET);

        // Right digest, missing algorithm prefix.
        $this->assertSame(SignatureVerifier::MALFORMED, $verifier->verify($body, $valid));
        // Wrong algorithm prefix (sha1 signatures are the deprecated header).
        $this->assertSame(SignatureVerifier::MALFORMED, $verifier->verify($body, 'sha1=' . $valid));
        // Truncated digest.
        $this->assertSame(SignatureVerifier::MALFORMED, $verifier->verify($body, 'sha256=' . substr($valid, 0, 63)));
        // Non-hex.
        $this->assertSame(SignatureVerifier::MALFORMED, $verifier->verify($body, 'sha256=' . str_repeat('z', 64)));
        // Junk.
        $this->assertSame(SignatureVerifier::MALFORMED, $verifier->verify($body, 'bad-header'));
    }

    /**
     * The property that actually matters: a signature is only valid for the
     * exact bytes it was computed over. Mutating the body after signing —
     * which is what an attacker replaying a captured delivery would do — must
     * not verify.
     */
    public function testRejectsBodyTamperedAfterSigning(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $original = '{"action":"opened","number":42}';
        $signature = 'sha256=' . hash_hmac('sha256', $original, self::SECRET);

        $this->assertSame(SignatureVerifier::OK, $verifier->verify($original, $signature));

        foreach ([
            '{"action":"opened","number":43}',   // a different PR
            '{"action":"closed","number":42}',   // a different action
            $original . ' ',                      // a single trailing space
            ' ' . $original,                      // a single leading space
            substr($original, 0, -1),             // truncated
        ] as $tampered) {
            $this->assertSame(
                SignatureVerifier::MISMATCH,
                $verifier->verify($tampered, $signature),
                'tampered body accepted: ' . $tampered,
            );
        }
    }

    /**
     * Byte-for-byte, not semantically: a JSON document that decodes to the same
     * structure but differs in whitespace or key order has a different HMAC.
     * This is the concrete reason the handler signs the raw body rather than a
     * re-encoded payload.
     */
    public function testReserializedPayloadDoesNotVerify(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $raw = '{"action":"opened","number":42}';
        $signature = 'sha256=' . hash_hmac('sha256', $raw, self::SECRET);

        $reserialized = json_encode(json_decode($raw, true), JSON_PRETTY_PRINT);

        $this->assertSame(SignatureVerifier::MISMATCH, $verifier->verify((string) $reserialized, $signature));
    }

    public function testFailsClosedWithNoSecretConfigured(): void
    {
        $verifier = new SignatureVerifier([]);
        $body = 'body';

        $this->assertFalse($verifier->isConfigured());
        // Not even a correctly-computed signature gets in — there is nothing to
        // compute it against, and "no secret" must never mean "accept".
        $this->assertSame(
            SignatureVerifier::NOT_CONFIGURED,
            $verifier->verify($body, 'sha256=' . hash_hmac('sha256', $body, self::SECRET)),
        );
        $this->assertSame(SignatureVerifier::NOT_CONFIGURED, $verifier->verify($body, null));
    }

    /** Rotation: both the outgoing and incoming secret are accepted during the overlap. */
    public function testAcceptsAnyConfiguredSecret(): void
    {
        $verifier = new SignatureVerifier(['old-secret', 'new-secret']);
        $body = '{"a":1}';

        $this->assertSame(SignatureVerifier::OK, $verifier->verify($body, 'sha256=' . hash_hmac('sha256', $body, 'old-secret')));
        $this->assertSame(SignatureVerifier::OK, $verifier->verify($body, 'sha256=' . hash_hmac('sha256', $body, 'new-secret')));
        $this->assertSame(SignatureVerifier::MISMATCH, $verifier->verify($body, 'sha256=' . hash_hmac('sha256', $body, 'third-secret')));
    }

    /** Uppercase hex is still the same digest; GitHub sends lowercase but the check should not be brittle. */
    public function testAcceptsUppercaseHexDigest(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);
        $body = 'body';

        $this->assertSame(
            SignatureVerifier::OK,
            $verifier->verify($body, 'sha256=' . strtoupper(hash_hmac('sha256', $body, self::SECRET))),
        );
    }

    public function testEmptyBodyIsSignedNormally(): void
    {
        $verifier = new SignatureVerifier([self::SECRET]);

        $this->assertSame(
            SignatureVerifier::OK,
            $verifier->verify('', 'sha256=' . hash_hmac('sha256', '', self::SECRET)),
        );
        $this->assertSame(SignatureVerifier::MISMATCH, $verifier->verify('', 'sha256=' . str_repeat('a', 64)));
    }
}
