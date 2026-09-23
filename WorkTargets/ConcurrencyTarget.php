<?php

namespace Voyager\IOPools\WorkTargets;

use Throwable;
use Voyager\Contracts\Concurrency\Driver;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkTarget;

/**
 * The Concurrency driver (sync, fork, process). Its run() blocks until the task returns, so this
 * is isolation, not overlap: the promise is settled when you get it. For overlap, use the pool.
 */
final class ConcurrencyTarget implements WorkTarget
{
    public function __construct(private readonly Loop $loop, private readonly Driver $driver) {}

    public function run(ShouldPool $gig): Promise
    {
        $promise = $this->loop->promise();

        try { $promise->resolve($this->driver->run([fn () => $gig->handle()])[0]); } catch (Throwable $e) { $promise->reject($e); }

        return $promise;
    }
}
