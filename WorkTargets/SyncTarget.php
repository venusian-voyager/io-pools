<?php

namespace Voyager\IOPools\WorkTargets;

use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkTarget;

/** Runs the gig on the spot. The promise is already settled when you get it. */
final class SyncTarget implements WorkTarget
{
    public function __construct(private readonly Loop $loop) {}

    public function run(ShouldPool $gig): Promise
    {
        $promise = $this->loop->promise();

        try { $promise->resolve($gig->handle()); } catch (Throwable $e) { $promise->reject($e); }

        return $promise;
    }
}
