<?php

namespace Voyager\IOPools\Waiter\Wakes;

readonly class StopSignal extends ControlSignal
{
    public function __construct(int $signo, public int $epoch)
    {
        parent::__construct($signo);
    }

    public function key(): string
    {
        return parent::key().'#'.$this->epoch;
    }
}