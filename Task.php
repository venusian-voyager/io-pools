<?php

namespace Voyager\IOPools;

use Fiber;
use Throwable;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\Task as TaskContract;

/** The promise async() hands out, plus the fiber behind it. Chains are plain promises. */
final class Task implements TaskContract
{
    public function __construct(
        private readonly Promise $promise,
        private readonly Fiber $fiber,
        private readonly FiberScheduler $scheduler,
    ) {}

    public function cancel(): void
    {
        $this->scheduler->cancel($this->fiber);
    }

    public function then(callable $callable, ?callable $on_rejected = null): Promise
    {
        return $this->promise->then($callable, $on_rejected);
    }

    public function error(callable $callable): Promise
    {
        return $this->promise->error($callable);
    }

    public function finally(callable $callable): Promise
    {
        return $this->promise->finally($callable);
    }

    public function wait(): mixed
    {
        return $this->promise->wait();
    }

    public function resolve(mixed $value): void
    {
        $this->promise->resolve($value);
    }

    public function reject(Throwable $reason): void
    {
        $this->promise->reject($reason);
    }

    public function settled(): bool
    {
        return $this->promise->settled();
    }

    public function fulfilled(): bool
    {
        return $this->promise->fulfilled();
    }

    public function rejected(): bool
    {
        return $this->promise->rejected();
    }
}
