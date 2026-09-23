<?php

namespace Voyager\IOPools;

use Closure;
use Throwable;
use parallel\Future;
use parallel\Runtime;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\PoolWorker;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\DeadWorkerException;
use Voyager\Contracts\IOPools\StoppedPoolException;

/**
 * One warm thread. The Future can't be waited on by the loop, so the thread rings a socket when
 * it's done and the loop watches that instead.
 */
class ThreadPoolWorker implements PoolWorker
{
    private int $jobs_done = 0;

    private bool $retiring = false;

    private bool $closed = false;

    private ?Future $future = null;

    private ?Promise $current = null;

    /**
     * @param resource $bell  accepted connection; the name line is already consumed, so it only ever carries '!'
     */
    public function __construct(
        private readonly string $name,
        private readonly Pool $pool,
        private readonly Runtime $runtime,
        private $bell,
        private readonly string $socket_path,
        private readonly Closure $work,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function streams(): array
    {
        return [$this->bell];
    }

    public function busy(): bool
    {
        return ! is_null($this->current);
    }

    public function alive(): bool
    {
        return ! $this->closed;
    }

    public function jobsDone(): int
    {
        return $this->jobs_done;
    }

    public function retiring(): bool
    {
        return $this->retiring;
    }

    /** True once the task is over, however it ended. The sweep asks this when the bell stays silent. */
    public function futureDone(): bool
    {
        return (bool) $this->future?->done();
    }

    public function give(ShouldPool $gig, Promise $promise): void
    {
        $this->current = $promise;

        try {
            // only strings cross: copying objects into a thread fails loudly going in and silently coming back
            $this->future = $this->runtime->run($this->work, [$this->socket_path, serialize($gig)]);
        } catch (Throwable $e) {
            $this->current = null;

            $promise->reject(new EventLoopException("This gig can't be sent to a worker: {$e->getMessage()}", 0, $e));

            $this->pool->finished($this);
        }
    }

    public function tick(): void
    {
        // false is a stream error, not a ring: never let it send us into a blocking value()
        $rang = ($chunk = fread($this->bell, 64)) !== false && $chunk !== '';

        // swept before it finished, or a stray wake
        if (! $this->busy() || (! $rang && ! $this->futureDone())) {
            return;
        }

        [$promise, $this->current] = [$this->current, null];

        try {
            // rang: the thread's return is landing, microseconds. done: instant.
            $envelope = unserialize($this->future->value());
        } catch (Throwable) {
            // parallel\Future\Error\Killed: the gig called exit(). The runtime still runs, in an unknown state.
            $promise->reject(new DeadWorkerException("Worker {$this->name} exited mid-gig."));
            $this->pool->died($this);

            return;
        }

        $this->jobs_done++;

        PoolEnvelope::settle($promise, $envelope);

        $this->pool->finished($this);
    }

    /** Only ever called on a worker that just settled, so the runtime is idle and leaves at once. */
    public function retire(): void
    {
        $this->retiring = true;

        $this->pool->retired($this);
    }

    public function stop(): void
    {
        [$promise, $this->current] = [$this->current, null];

        // reject BEFORE kill: kill() blocks until the thread reaches an interrupt point
        $promise?->reject(new StoppedPoolException("The pool shut down while worker {$this->name} was on this gig."));

        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        try {
            $this->runtime->kill();
        } catch (Throwable) {
            // already gone
        }

        if (is_resource($this->bell)) {
            fclose($this->bell);
        }
    }
}
