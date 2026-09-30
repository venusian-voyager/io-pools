<?php

namespace Voyager\IOPools\PromiseEngines;

use Throwable;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\Promise as GuzzlePromise;

class GuzzlePromiseEngine extends PromiseEngine
{
    public function make(): object
    {
        return new GuzzlePromise();
    }

    public function adopt(object $thenable): object
    {
        return Create::promiseFor($thenable);
    }

    public function resolve(object $inner, mixed $value): void
    {
        $inner->resolve($value);
    }

    public function reject(object $inner, Throwable $reason): void
    {
        $inner->reject($reason);
    }

    public function chain(object $inner, ?callable $on_fulfilled, ?callable $on_rejected): object
    {
        return $inner->then($on_fulfilled, $on_rejected);
    }

    public function flush(): void
    {
        Utils::queue()->run();
    }
}