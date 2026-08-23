<?php

/**
 * switchboard-api worker entrypoint — **opt-in**, not the default.
 *
 * Enable with:
 *
 *     composer require ephpm/psr15-worker    # plus the vcs repositories, see README
 *
 *     # ephpm.toml
 *     [php]
 *     mode = "worker"
 *     worker_script = "worker.php"
 *
 * This is a self-contained worker script rather than a pointer at
 * `vendor/ephpm/psr15-worker/bin/ephpm-worker`, which is the arrangement that
 * package's README recommends when you would rather not depend on
 * `EPHPM_WORKER_BOOTSTRAP` being set on the server process. It builds the app
 * itself and runs the loop.
 *
 * # Why worker mode is not the default
 *
 * A webhook receiver is low-traffic — a handful of deliveries per push, not
 * thousands of requests per second — so the per-request bootstrap cost worker
 * mode removes is close to irrelevant here. What worker mode adds is a
 * long-lived process in which state can leak between requests, and this is
 * security-sensitive code where that failure mode is at its most expensive.
 *
 * Being a PSR-15 application means we get the option without betting the
 * webhook path on it. The object graph holds no request state (see
 * {@see \Switchboard\App}), so it is correct under both.
 */

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

if (!class_exists(Ephpm\Psr15\Worker::class)) {
    fwrite(STDERR, "switchboard-api: worker mode requires ephpm/psr15-worker (see README)\n");
    exit(1);
}

$app = Switchboard\App::boot(__DIR__);

exit((new Ephpm\Psr15\Worker($app))->run());
