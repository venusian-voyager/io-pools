<?php

namespace Voyager\IOPools\PromiseEngines;

use Throwable;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use Voyager\Contracts\IOPools\IOPoolsException;
use function React\Promise\resolve;

class ReactPromiseEngine extends PromiseEngine
{
    public function make(): object
    {
        return new Deferred();
    }

    public function adopt(object $thenable): object
    {
        return resolve($thenable);
    }

    public function resolve(object $inner, mixed $value): void
    {
        $this->deferred($inner)->resolve($value);
    }

    public function reject(object $inner, Throwable $reason): void
    {
        $this->deferred($inner)->reject($reason);
    }

    public function chain(object $inner, ?callable $on_fulfilled, ?callable $on_rejected): object
    {
        $promise = $inner instanceof Deferred ? $inner->promise() : $inner;

        return $promise->then($on_fulfilled, $on_rejected);
    }

    public function flush(): void
    {
        // react/promise 3 settles synchronously: no queue to run.
    }

    private function deferred(object $inner): Deferred
    {
        if (! $inner instanceof Deferred) {
            throw new IOPoolsException('Only a promise made by Loop::promise() can be resolved or rejected.');
        }

        return $inner;
    }
}