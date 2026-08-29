<?php

declare(strict_types=1);

namespace Switchboard\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Config;

/**
 * Routing, in one `match`.
 *
 * The HTTP surface is three endpoints: the webhook, a health check, and (in
 * cluster mode) the localhost-only drain. A router package — Slim,
 * League\Route, FastRoute — would add between one and seven transitive
 * dependencies to dispatch that, and every one of them is code reachable
 * from an unauthenticated POST. There is no pattern to compile and no path
 * parameters to extract.
 *
 * Path resolution note: ePHPm's default fallback chain sends anything that is
 * not a real file to `index.php`, while keeping `REQUEST_URI` as the *original*
 * client URI. The PSR-7 request therefore carries the path the client asked
 * for, not the front controller's own path.
 */
final class Router implements RequestHandlerInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
        private readonly RequestHandlerInterface $webhook,
        private readonly RequestHandlerInterface $drain,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = rtrim($request->getUri()->getPath(), '/');
        if ($path === '') {
            $path = '/';
        }

        return match ($path) {
            '/webhook' => $this->webhook->handle($request),
            '/healthz' => $this->health(),
            '/drain' => $this->drain->handle($request),
            default => $this->json->response(404, ['ok' => false, 'error' => 'not found']),
        };
    }

    /**
     * Liveness, unauthenticated.
     *
     * Reports whether a webhook secret is loaded as a boolean, so an operator
     * can confirm a deployment is configured without the check itself
     * disclosing anything usable. There is nothing else to expose: the daemon
     * reports preview state to GitHub, so this service holds no view of it.
     */
    private function health(): ResponseInterface
    {
        return $this->json->response(200, [
            'ok' => true,
            'service' => 'switchboard-api',
            'webhook_configured' => $this->config->webhookSecrets !== [],
        ]);
    }
}
