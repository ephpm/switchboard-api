<?php

declare(strict_types=1);

namespace Switchboard\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * JSON responses built through the PSR-17 factory.
 *
 * Going through the factory rather than constructing a concrete response class
 * keeps every handler free of any particular PSR-7 implementation — the choice
 * of Diactoros lives in exactly one place ({@see \Switchboard\App::boot()}) and
 * could be swapped without touching a handler.
 */
final class Json
{
    public function __construct(private readonly ResponseFactoryInterface $responses)
    {
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function response(int $status, array $data, array $headers = []): ResponseInterface
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            $encoded = '{"ok":false,"error":"encoding failure"}';
        }

        $response = $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write($encoded . "\n");

        return $response;
    }
}
