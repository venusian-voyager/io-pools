<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;

abstract readonly class WakingState implements Wake
{
    protected WakeReason $kind;

    public function __construct(WakeReason $k) {
        $this->kind = $k;
    }

    /**
     * @return WakeReason
     */
    public function kind(): WakeReason
    {
        return $this->kind;
    }
}