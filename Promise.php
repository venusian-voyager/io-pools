<?php

namespace Voyager\IOPools;

use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\PromiseEngine;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Promise as PromiseContract;

/**
 * The one promise the framework hands out, whichever library is underneath.
 * The engine knows the library; everything that is true of THIS promise lives here.
 */
class Promise implements PromiseContract
{
    protected bool $settled = false;
    protected mixed $value = null;
    protected ?Throwable $reason = null;

    public function __construct(
        protected readonly PromiseEngine $engine,
        protected readonly object $inner,
        protected readonly Loop $loop,
    ) {
        $this->engine->chain($this->inner, $this->recordValue(...), $this->recordReason(...));
    }

    public function then(callable $callable): PromiseContract
    {
        return $this->chain($callable, null);
    }

    public function error(callable $callable): PromiseContract
    {
        return $this->chain(null, $callable);
    }

    /**
     * Runs either way, and passes the outcome through untouched.
     */
    public function finally(callable $callable): PromiseContract
    {
        return $this->chain(
            function (mixed $value) use ($callable) { $callable(); return $value; },
            function (Throwable $reason) use ($callable) { $callable(); throw $reason; },
        );
    }

    /**
     * Borrow the loop until this settles. Hands back the value, or throws the reason.
     * @throws Throwable
     */
    public function wait(): mixed
    {
        $this->engine->flush();     // already settled, but its callbacks may still be queued

        $this->loop->until(fn () => $this->settled);

        return is_null($this->reason) ? $this->value : throw $this->reason;
    }

    /** The writing end: whoever does the work calls one of these, once. */
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
        return $this->settled && is_null($this->reason);
    }

    public function rejected(): bool
    {
        return $this->settled && ! is_null($this->reason);
    }

    protected function chain(?callable $on_fulfilled, ?callable $on_rejected): PromiseContract
    {
        return new static(
            $this->engine,
            $this->engine->chain($this->inner, $on_fulfilled, $on_rejected),
            $this->loop,
        );
    }

    protected function recordValue(mixed $value): void
    {
        $this->settled = true;
        $this->value = $value;
    }

    protected function recordReason(mixed $reason): void
    {
        $this->settled = true;
        $this->reason = $reason instanceof Throwable
            ? $reason
            : new EventLoopException('Promise rejected: '.(is_scalar($reason) ? $reason : get_debug_type($reason)));
    }
}
