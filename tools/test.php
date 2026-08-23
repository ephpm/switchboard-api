<?php

/**
 * Test runner.
 *
 * Discovers `tests/*Test.php`, instantiates each test class, and runs every
 * public `test*` method. See `tests/Support/TestCase.php` for why the suite
 * does not use PHPUnit.
 *
 * Usage:
 *     ephpm php tools/test.php            # everything
 *     ephpm php tools/test.php Signature  # only classes matching a substring
 */

declare(strict_types=1);

use Switchboard\Tests\Support\AssertionFailed;
use Switchboard\Tests\Support\Fixtures;
use Switchboard\Tests\Support\TestCase;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "vendor/autoload.php missing — run `composer install`\n");
    exit(1);
}

require $root . '/vendor/autoload.php';

$filter = $argv[1] ?? null;

$files = glob($root . '/tests/*Test.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$assertions = 0;
/** @var list<string> $failures */
$failures = [];

foreach ($files as $file) {
    require_once $file;
    $class = 'Switchboard\\Tests\\' . basename($file, '.php');

    if (!class_exists($class) || !is_subclass_of($class, TestCase::class)) {
        fwrite(STDERR, "skipping {$file}: no matching TestCase subclass\n");
        continue;
    }

    if ($filter !== null && stripos($class, $filter) === false) {
        continue;
    }

    $short = substr($class, strrpos($class, '\\') + 1);
    echo "\n\033[1m{$short}\033[0m\n";

    foreach (get_class_methods($class) as $method) {
        if (!str_starts_with($method, 'test')) {
            continue;
        }

        $instance = new $class();
        try {
            $instance->{$method}();
            $passed++;
            $assertions += $instance->assertionCount();
            echo "  \033[32mPASS\033[0m {$method}\n";
        } catch (AssertionFailed $e) {
            $failed++;
            $assertions += $instance->assertionCount();
            echo "  \033[31mFAIL\033[0m {$method}\n";
            $failures[] = "{$short}::{$method}\n    " . str_replace("\n", "\n    ", $e->getMessage());
        } catch (Throwable $e) {
            $failed++;
            echo "  \033[31mERR \033[0m {$method} (" . $e::class . ")\n";
            $failures[] = "{$short}::{$method}\n    " . $e::class . ': ' . $e->getMessage()
                . "\n    at " . $e->getFile() . ':' . $e->getLine();
        }
    }
}

Fixtures::cleanup();

if ($failures !== []) {
    echo "\n\033[1mFailures\033[0m\n";
    foreach ($failures as $failure) {
        echo "\n  " . str_replace("\n", "\n  ", $failure) . "\n";
    }
}

echo "\n" . ($failed === 0 ? "\033[32m" : "\033[31m")
    . "{$passed} passed, {$failed} failed, {$assertions} assertions\033[0m\n";

exit($failed === 0 ? 0 : 1);
