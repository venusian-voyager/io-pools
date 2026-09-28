<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\WakeReason;

readonly class Writable extends WakingState
{
    public function __construct(public mixed $stream)
    {
        parent::__construct(WakeReason::WRITEABLE);
    }

    public function key(): string
    {
        return 'write:'.(int) $this->stream;
    }
}