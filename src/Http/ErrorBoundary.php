<?php

declare(strict_types=1);

namespace Switchboard\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Switchboard\Log;
use Switchboard\Storage\StorageException;

/**
 * Turns any escaped `Throwable` into a `500` with no detail in the body.
 *
 * Two reasons this is a middleware rather than a `try` in the front controller:
 * it composes into the pipeline for both fpm and worker mode, and in worker
 * mode it is the thing that stops one malformed request from killing a
 * long-lived process.
 *
 * The exception message is logged and never returned. Exception text routinely
 * carries absolute filesystem paths and fragments of the payload that caused
 * it, and this endpoint is reachable by anyone who can find the hostname.
 */
final class ErrorBoundary implements MiddlewareInterface
{
    public function __construct(private readonly Json $json)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (StorageException $e) {
            Log::error('storage failure', [
                'path' => $request->getUri()->getPath(),
                'reason' => $e->getMessage(),
            ]);

            return $this->json->response(500, ['ok' => false, 'error' => 'storage unavailable']);
        } catch (\Throwable $e) {
            Log::error('unhandled exception', [
                'path' => $request->getUri()->getPath(),
                'type' => $e::class,
                'reason' => $e->getMessage(),
            ]);

            return $this->json->response(500, ['ok' => false, 'error' => 'internal error']);
        }
    }
}
