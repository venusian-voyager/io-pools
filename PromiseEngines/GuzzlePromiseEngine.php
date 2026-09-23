<?php

namespace Voyager\IOPools\PromiseEngines;

use Throwable;
use GuzzleHttp\Promise\Promise as GuzzlePromise;

class GuzzlePromiseEngine extends PromiseEngine
{
    public function make(): object
    {
        return new GuzzlePromise();
    }

    public function resolve(object $inner, mixed $value): void
    {
        $inner->resolve($value);
    }

    public function reject(object $inner, Throwable $reason): void
    {
        $inner->reject($reason);
    }
}
