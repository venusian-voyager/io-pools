<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\WakeReason;

readonly class ProcessExit extends WakingState
{
    public function __construct(public int $pid)
    {
        parent::__construct(WakeReason::PROCESS_EXIT);
    }

    public function key(): string
    {
        return 'exit:'.$this->pid;
    }
}