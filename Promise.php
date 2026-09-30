<?php

namespace Voyager\IOPools;

use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\PromiseEngine;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\Promise as PromiseContract;

class Promise implements PromiseContract
{
    protected bool $settled = false;

    protected bool $fulfilled = false;

    protected mixed $value = null;

    protected ?Throwable $reason = null;

    public function __construct(
        public readonly PromiseEngine $engine,
        public readonly object $inner,
        public readonly Loop $loop,
    ) {
        // The observer handles rejection, so no library reports it as unhandled.
        $this->engine->chain(
            $this->inner,
            function (mixed $value): mixed {
                [$this->settled, $this->fulfilled, $this->value] = [true, true, $value];
                return $value;
            },
            function (mixed $reason): void {
                [$this->settled, $this->reason] = [true, self::throwable($reason)];
            },
        );
    }

    public function then(callable $callable, ?callable $on_rejected = null): PromiseContract
    {
        return $this->follow($this->engine->chain($this->inner, $callable, $on_rejected));
    }

    public function error(callable $callable): PromiseContract
    {
        return $this->follow($this->engine->chain(
            $this->inner,
            null,
            fn (mixed $reason): mixed => $callable(self::throwable($reason)),
        ));
    }

    public function finally(callable $callable): PromiseContract
    {
        return $this->follow($this->engine->chain(
            $this->inner,
            function (mixed $value) use ($callable): mixed {
                $callable();
                return $value;
            },
            function (mixed $reason) use ($callable): never {
                $callable();
                throw self::throwable($reason);
            },
        ));
    }

    public function wait(): mixed
    {
        $this->loop->until(fn (): bool => $this->settled);

        if (! $this->fulfilled) {
            throw $this->reason;
        }

        return $this->value;
    }

    public function resolve(mixed $value): void
    {
        $this->engine->resolve($this->inner, $value);
    }

    public function reject(Throwable $reason): void
    {
        $this->engine->reject($this->inner, $reason);
    }

    public function settled(): bool
    {
        return $this->settled;
    }

    public function fulfilled(): bool
    {
        return $this->settled && $this->fulfilled;
    }

    public function rejected(): bool
    {
        return $this->settled && ! $this->fulfilled;
    }

    private function follow(object $inner): PromiseContract
    {
        return new self($this->engine, $inner, $this->loop);
    }

    /** Libraries may reject with anything. The contract promises a Throwable. */
    private static function throwable(mixed $reason): Throwable
    {
        return $reason instanceof Throwable
            ? $reason
            : new IOPoolsException('The promise was rejected with '.get_debug_type($reason).'.');
    }
}