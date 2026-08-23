<?php

declare(strict_types=1);

namespace Switchboard\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 middleware pipeline.
 *
 * Twenty lines, and it is the whole reason this application needs no dispatcher
 * package. PSR-15 defines the contract precisely — a middleware receives a
 * request and the *next* handler, and either delegates or short-circuits — so a
 * conforming pipeline is a `foreach` with an index.
 *
 * The alternative was `relay/relay` (dormant since 2024-10-22) or
 * `laminas-stratigility` (actively maintained, but six transitive dependencies
 * including `laminas-escaper`). On an internet-facing endpoint that accepts
 * unauthenticated POSTs, third-party runtime code is the thing to minimise, and
 * neither buys anything over this for a two-route application.
 *
 * The FIG interface packages this implements (`psr/http-server-handler`,
 * `psr/http-server-middleware`) contain no runtime code at all — one interface
 * file each — so depending on them costs nothing in attack surface while making
 * the app genuinely PSR-15 and therefore runnable under `ephpm/psr15-worker`.
 *
 * Stateless: `handle()` walks a fresh index per call, so one instance safely
 * serves many requests in worker mode.
 */
final class Pipeline implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private readonly array $middleware;

    /** @param iterable<MiddlewareInterface> $middleware Outermost first. */
    public function __construct(
        iterable $middleware,
        private readonly RequestHandlerInterface $handler,
    ) {
        $this->middleware = array_values([...$middleware]);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->at(0)->handle($request);
    }

    /** The handler representing "the rest of the pipeline from `$index`". */
    private function at(int $index): RequestHandlerInterface
    {
        if (!isset($this->middleware[$index])) {
            return $this->handler;
        }

        return new class($this->middleware[$index], fn (): RequestHandlerInterface => $this->at($index + 1)) implements RequestHandlerInterface {
            /** @param \Closure(): RequestHandlerInterface $next */
            public function __construct(
                private readonly MiddlewareInterface $middleware,
                private readonly \Closure $next,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, ($this->next)());
            }
        };
    }
}
