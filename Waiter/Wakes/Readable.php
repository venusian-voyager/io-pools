<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\WakeReason;

readonly class Readable extends WakingState
{
    /** @param resource $stream */
    public function __construct(public mixed $stream)
    {
        parent::__construct(WakeReason::READABLE);
    }

    /**
     * @return string
     */
    public function key(): string
    {
        return 'read:'.(int) $this->stream;
    }
}