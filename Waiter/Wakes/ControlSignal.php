<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\WakeReason;

readonly class ControlSignal extends WakingState
{
    public function __construct(public int $signo)
    {
        parent::__construct(WakeReason::CONTROL_SIGNAL);
    }

    public function key(): string
    {
        return 'signal:'.$this->signo;
    }
}