<?php

namespace Voyager\IOPools\WorkTargets;

use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkTarget;

/** Next turn, main thread. Batches; never overlaps. */
final class DeferTarget implements WorkTarget
{
    public function __construct(private readonly Loop $loop) {}

    public function run(ShouldPool $gig): Promise
    {
        return $this->loop->defer(fn () => $gig->handle());
    }
}
