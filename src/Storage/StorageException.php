<?php

declare(strict_types=1);

namespace Switchboard\Storage;

/**
 * A state-directory operation failed.
 *
 * Always fatal to the request that raised it. Losing a job silently would mean
 * a PR that never gets a preview and no record of why, so the webhook answers
 * `500` and lets GitHub redeliver instead.
 */
final class StorageException extends \RuntimeException
{
}
