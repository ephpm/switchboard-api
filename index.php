<?php

/**
 * switchboard-api front controller (fpm mode — the default).
 *
 * This file is the document root's index. ePHPm serves the vhost directory
 * itself as the web root, so everything beside it is potentially reachable:
 * `src/` and `vendor/` contain no routable entry points, and `.switchboard/`
 * (state and secrets) is refused by ePHPm because its path contains a
 * dot-prefixed segment.
 *
 * For worker mode see `worker.php`.
 */

declare(strict_types=1);

use Laminas\Diactoros\ServerRequestFactory;

require __DIR__ . '/vendor/autoload.php';

try {
    $handler = Switchboard\App::boot(__DIR__);
    $response = $handler->handle(ServerRequestFactory::fromGlobals());
} catch (Throwable $e) {
    // Boot failed — almost always an unwritable state directory. Log the
    // detail, return none of it.
    Switchboard\Log::error('boot failure', ['type' => $e::class, 'reason' => $e->getMessage()]);
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"ok":false,"error":"not configured"}' . "\n";

    return;
}

// Emit the PSR-7 response. Written out here rather than pulled in from
// laminas-httphandlerrunner: it is a dozen lines, and this is the one place in
// the application that touches PHP's output layer at all.
http_response_code($response->getStatusCode());

foreach ($response->getHeaders() as $name => $values) {
    $first = true;
    foreach ($values as $value) {
        header($name . ': ' . $value, $first);
        $first = false;
    }
}

$body = $response->getBody();
if ($body->isSeekable()) {
    $body->rewind();
}

while (!$body->eof()) {
    echo $body->read(65536);
}
