<?php

declare(strict_types=1);

namespace Switchboard\Tests\Support;

use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\App;
use Switchboard\Cluster\KvClient;
use Switchboard\Config;

/** Shared helpers: temp state directories, signed PSR-7 requests, sample payloads. */
final class Fixtures
{
    public const SECRET = 'test-webhook-secret';

    /** @var list<string> */
    private static array $tempDirs = [];

    /** A fresh, empty state directory, removed when the suite ends. */
    public static function tempDir(string $prefix = 'sbapi'): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: ' . $dir);
        }
        self::$tempDirs[] = $dir;

        return $dir;
    }

    /** @param array<string, mixed> $overrides */
    public static function config(string $stateDir, array $overrides = []): Config
    {
        // `+` keeps the LEFT operand's keys, so overrides must come first or a
        // caller could never change a value that has a default here.
        //
        // `allowedRepos` defaults to `ephpm/*` because the sample payloads all
        // belong to `ephpm/*` and, since issue #3, an *unconfigured* allowlist
        // fails closed — a node with no allowlist rejects every delivery. This
        // default therefore models a correctly-configured node; the fail-closed
        // path is exercised by explicitly overriding `allowedRepos` to `null`.
        return Config::fromArray($overrides + [
            'appRoot' => $stateDir,
            'stateDir' => $stateDir,
            'webhookSecrets' => [self::SECRET],
            'allowedRepos' => ['ephpm/*'],
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param KvClient|null        $kv Pass a {@see FakeKvClient} to build the app
     *                                 in cluster mode; `null` (the default) is
     *                                 single-node, matching production when the
     *                                 KV SAPI bridge is absent.
     */
    public static function app(string $stateDir, array $overrides = [], ?KvClient $kv = null): RequestHandlerInterface
    {
        return App::build(self::config($stateDir, $overrides), kv: $kv);
    }

    /** The `X-Hub-Signature-256` value GitHub would send for `$body`. */
    public static function sign(string $body, string $secret = self::SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * A PSR-7 request with a literal body.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $serverParams e.g. `['REMOTE_ADDR' => '127.0.0.1']`
     */
    public static function request(
        string $method,
        string $path,
        string $body = '',
        array $headers = [],
        array $serverParams = [],
    ): ServerRequestInterface {
        $stream = new Stream('php://temp', 'wb+');
        $stream->write($body);
        $stream->rewind();

        return new ServerRequest(
            serverParams: $serverParams,
            uploadedFiles: [],
            uri: $path,
            method: $method,
            body: $stream,
            headers: $headers,
        );
    }

    /**
     * A `GET /drain` request as the local daemon would send it: from
     * loopback, carrying the token header.
     */
    public static function drainRequest(
        ?string $token = self::SECRET,
        string $remoteAddr = '127.0.0.1',
    ): ServerRequestInterface {
        $headers = $token === null ? [] : ['X-Drain-Token' => $token];

        return self::request('GET', '/drain', '', $headers, ['REMOTE_ADDR' => $remoteAddr]);
    }

    /**
     * A correctly signed webhook request.
     *
     * @param array<string, string|null> $headerOverrides A null value removes the header.
     */
    public static function webhookRequest(
        string $body,
        string $deliveryId = '11111111-2222-3333-4444-555555555555',
        string $event = 'pull_request',
        ?string $signature = null,
        array $headerOverrides = [],
    ): ServerRequestInterface {
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Length' => (string) strlen($body),
            'X-GitHub-Event' => $event,
            'X-GitHub-Delivery' => $deliveryId,
            'X-Hub-Signature-256' => $signature ?? self::sign($body),
        ];

        foreach ($headerOverrides as $name => $value) {
            // Header names are case-insensitive in PSR-7, but the array key is
            // not — drop any existing spelling before applying the override.
            foreach (array_keys($headers) as $existing) {
                if (strcasecmp($existing, $name) === 0) {
                    unset($headers[$existing]);
                }
            }
            if ($value !== null) {
                $headers[$name] = $value;
            }
        }

        return self::request('POST', '/webhook', $body, $headers);
    }

    /**
     * Write `.switchboard/drain_secret` directly, bypassing the app — the state
     * directory must already exist (i.e. `Fixtures::app()` must have run first,
     * since `App::build()` is what calls `Paths::ensure()`).
     */
    public static function writeDrainSecret(string $stateDir, string $secret = self::SECRET): void
    {
        file_put_contents($stateDir . '/drain_secret', $secret . "\n");
    }

    /** @return array<string, mixed> */
    public static function decode(ResponseInterface $response): array
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        $decoded = json_decode($body->getContents(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A realistic `pull_request` payload.
     *
     * @param array<string, mixed> $overrides Deep-merged over the defaults.
     */
    public static function pullRequestPayload(array $overrides = []): array
    {
        return self::merge([
            'action' => 'opened',
            'number' => 42,
            'pull_request' => [
                'number' => 42,
                'title' => 'Add a thing',
                'draft' => false,
                'merged' => false,
                'head' => [
                    'ref' => 'feature/new-header',
                    'sha' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4',
                    'repo' => [
                        'full_name' => 'ephpm/wordpress-sample',
                        'clone_url' => 'https://github.com/ephpm/wordpress-sample.git',
                    ],
                ],
                'base' => ['ref' => 'main'],
            ],
            'repository' => [
                'full_name' => 'ephpm/wordpress-sample',
                'name' => 'wordpress-sample',
                'owner' => ['login' => 'ephpm'],
                'clone_url' => 'https://github.com/ephpm/wordpress-sample.git',
                'default_branch' => 'main',
                'private' => false,
            ],
            'sender' => ['login' => 'octocat'],
            'installation' => ['id' => 12345],
        ], $overrides);
    }

    /** JSON-encoded {@see pullRequestPayload()}. */
    public static function pullRequestBody(array $overrides = []): string
    {
        return json_encode(self::pullRequestPayload($overrides), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function cleanup(): void
    {
        foreach (self::$tempDirs as $dir) {
            self::removeTree($dir);
        }
        self::$tempDirs = [];
    }

    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            @unlink($dir);

            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($dir . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($dir);
    }
}
