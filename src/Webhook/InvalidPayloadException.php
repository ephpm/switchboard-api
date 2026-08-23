<?php

declare(strict_types=1);

namespace Switchboard\Webhook;

/** The payload is authentic but not shaped like something we can act on. */
final class InvalidPayloadException extends \RuntimeException
{
}
