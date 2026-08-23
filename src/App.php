<?php

declare(strict_types=1);

namespace Switchboard;

use Laminas\Diactoros\ResponseFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Http\ErrorBoundary;
use Switchboard\Http\Json;
use Switchboard\Http\Pipeline;
use Switchboard\Http\Router;
use Switchboard\Queue\DeliveryLog;
use Switchboard\Queue\JobQueue;
use Switchboard\Storage\AtomicWriter;
use Switchboard\Storage\Paths;
use Switchboard\Webhook\SignatureVerifier;
use Switchboard\Webhook\WebhookHandler;

/**
 * Composition root.
 *
 * `build()` returns a PSR-15 `RequestHandlerInterface`, which is the entire
 * public shape of this application. That is what lets the same object graph
 * serve fpm requests through `index.php` and worker requests through
 * `ephpm/psr15-worker` with no branching anywhere else.
 *
 * The single place a concrete PSR-7 implementation is named is the
 * `ResponseFactory` default below; everything downstream takes the PSR-17
 * interface.
 *
 * # Worker-mode discipline
 *
 * The graph holds no request state. Configuration and the storage objects are
 * resolved once and are read-only thereafter; `Pipeline` walks a fresh index
 * per call; nothing caches a request, a response, or a body. That is what makes
 * it safe to build once and reuse across a worker's lifetime — and it is the
 * discipline the code should follow regardless, which is why it is followed
 * even though fpm mode is the default.
 */
final class App
{
    /** Build the handler from the environment, rooted at the application directory. */
    public static function boot(string $appRoot): RequestHandlerInterface
    {
        return self::build(Config::load($appRoot));
    }

    public static function build(Config $config, ?ResponseFactoryInterface $responses = null): RequestHandlerInterface
    {
        $paths = new Paths($config->stateDir);
        $paths->ensure();

        $json = new Json($responses ?? new ResponseFactory());
        $writer = new AtomicWriter($paths->tmp());

        $webhook = new WebhookHandler(
            $config,
            $json,
            new SignatureVerifier($config->webhookSecrets),
            new DeliveryLog($paths->deliveries(), $writer),
            new JobQueue($paths->queue(), $writer),
        );

        return new Pipeline(
            [new ErrorBoundary($json)],
            new Router($config, $json, $webhook),
        );
    }
}
