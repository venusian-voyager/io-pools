<?php

namespace Voyager\IOPools\PromiseEngines;

use Throwable;
use React\Promise\Deferred;
use Voyager\Contracts\IOPools\EventLoopException;

class ReactPromiseEngine extends PromiseEngine
{
    /**
     * React splits the two ends: the Deferred is written to, its promise() is read from.
     */
    public function make(): object
    {
        return new Deferred();
    }

    public function resolve(object $inner, mixed $value): void
    {
        $this->writable($inner)->resolve($value);
    }

    public function reject(object $inner, Throwable $reason): void
    {
        $this->writable($inner)->reject($reason);
    }

    public function chain(object $inner, ?callable $on_fulfilled, ?callable $on_rejected): object
    {
        $readable = $inner instanceof Deferred ? $inner->promise() : $inner;

        return parent::chain($readable, $on_fulfilled, $on_rejected);
    }

    private function writable(object $inner): Deferred
    {
        return $inner instanceof Deferred
            ? $inner
            : throw new EventLoopException('Only the promise the loop made can be settled; a chained one settles itself.');
    }
}
