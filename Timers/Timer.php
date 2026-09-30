<?php

namespace Voyager\IOPools\Timers;

use Closure;
use Voyager\IOPools\Resources\Deadline;
use Voyager\Contracts\IOPools\LoopResources\Timer as TimerContract;

abstract class Timer extends Deadline implements TimerContract
{
    protected ?int $due_at;
    protected bool $cancelled = false;

    public function __construct(
        int $delay_ns,
        protected readonly Closure $action,
        protected readonly ?int $interval_ns = null
    ) {
        $this->due_at = hrtime(true) + $delay_ns;
    }

    public function dueAt(): ?int
    {
        return $this->cancelled ? null : $this->due_at;
    }

    public function interval(): ?int
    {
        return $this->interval_ns;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function fire(): void
    {
        // Advance first: a callback that borrows the loop through until() must not find this timer due again.
        $this->due_at = is_null($this->interval_ns) ? null : $this->due_at + $this->interval_ns;

        ($this->action)();

        // A callback that overran several beats skips them and stays on the grid.
        if (! is_null($this->due_at) && $this->due_at <= ($now = hrtime(true)))
        {
            $this->due_at += (intdiv($now - $this->due_at, $this->interval_ns) + 1) * $this->interval_ns;
        }
    }
}