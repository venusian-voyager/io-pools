<?php

namespace Voyager\IOPools\WorkTargets;

use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkerPool;
use Voyager\Contracts\IOPools\WorkTarget;

/** A worker: process or thread, whichever the pool is. Off the main thread. */
final class PoolTarget implements WorkTarget
{
    public function __construct(private readonly WorkerPool $pool) {}

    public function run(ShouldPool $gig): Promise
    {
        return $this->pool->submit($gig);
    }
}
