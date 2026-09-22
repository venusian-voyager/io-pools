<?php

namespace Voyager\IOPools;

use Closure;
use Ramsey\Uuid\Uuid;
use Voyager\Contracts\IOPools\LoopTimer;

/**
 * One note in the loop's notebook: call $fire at $due_at. A null interval is
 * a one-shot; otherwise the loop moves the note forward by $interval after
 * each firing.
 */
class ActionTimer implements LoopTimer
{
    public readonly string $uuid;
    protected bool $cancelled = false;

    public function __construct(
        protected float $due_at,
        protected readonly Closure $fire,
        protected readonly ?float $interval = null,
        public readonly string $name = "",
    ) {
        $this->uuid = Uuid::uuid4()->toString();
    }

    public function fire(): void
    {
        ($this->fire)();
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function cancelled(): bool
    {
        return $this->cancelled;
    }

    public function dueAt(): ?float
    {
        return $this->due_at;
    }

    public function setDueAt(float $due_at): void
    {
        $this->due_at = $due_at;
    }

    public function interval(): ?float
    {
        return $this->interval;
    }

    public function uuid(): string
    {
        return $this->uuid;
    }
}
