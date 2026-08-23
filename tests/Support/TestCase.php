<?php

declare(strict_types=1);

namespace Switchboard\Tests\Support;

/**
 * A minimal assertion base class.
 *
 * PHPUnit would be the obvious choice, but it would mean a `vendor/` tree
 * inside a directory that is also the document root of an internet-facing
 * vhost. The test suite is small and self-contained, so the trade favours no
 * dependency at all — see the README's note on why nothing here is installed
 * with Composer.
 */
abstract class TestCase
{
    private int $assertions = 0;

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    protected function assertTrue(bool $condition, string $message = ''): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new AssertionFailed($message !== '' ? $message : 'expected true, got false');
        }
    }

    protected function assertFalse(bool $condition, string $message = ''): void
    {
        $this->assertTrue(!$condition, $message !== '' ? $message : 'expected false, got true');
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                "%s\n  expected: %s\n  actual:   %s",
                $message !== '' ? $message : 'values differ',
                $this->render($expected),
                $this->render($actual),
            ));
        }
    }

    protected function assertNull(mixed $actual, string $message = ''): void
    {
        $this->assertSame(null, $actual, $message);
    }

    protected function assertNotNull(mixed $actual, string $message = ''): void
    {
        $this->assertTrue($actual !== null, $message !== '' ? $message : 'expected a non-null value');
    }

    protected function assertStringContains(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf(
                "%s\n  expected to contain: %s\n  actual: %s",
                $message !== '' ? $message : 'substring not found',
                $needle,
                $haystack,
            ));
        }
    }

    protected function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;
        if (preg_match($pattern, $subject) !== 1) {
            throw new AssertionFailed(sprintf(
                "%s\n  pattern: %s\n  subject: %s",
                $message !== '' ? $message : 'pattern did not match',
                $pattern,
                $subject,
            ));
        }
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    private function render(mixed $value): string
    {
        return match (true) {
            is_string($value) => var_export($value, true),
            is_bool($value), is_int($value), is_float($value), is_null($value) => var_export($value, true),
            default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: gettype($value),
        };
    }
}
