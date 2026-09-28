<?php

namespace Voyager\IOPools\WorkerPools;

use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\WorkerPools\PoolWorker;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkerPools\WorkerPool;

/**
 * What every pool shares: the queue of gigs waiting for a worker, picking an idle worker or
 * spawning one while there's room, and registering a worker on the loop only while it has a
 * gig, so idle workers never keep run() alive. A concrete pool only knows how to spawn.
 */
abstract class IOPool implements WorkerPool
{
    /**
     * @var array<string, PoolWorker> name => worker, every live one
     */
    private array $workers = [];

    /**
     * @var list<array{0: ShouldPool, 1: Promise}> gigs waiting for a worker, oldest first
     */
    private array $queue = [];

    private int $spawned = 0;

    public function __construct(
        protected readonly Loop $loop,
        protected readonly int $max_workers,
        private readonly string $prefix,
    ) {
        if ($max_workers < 1) {
            throw new IOPoolsException("A pool needs room for at least one worker, {$max_workers} given.");
        }

        // The loop stops turning after run(): nothing could settle an outstanding gig after that.
        $this->loop->onStop($this->shutDown(...));
    }

    abstract protected function spawn(string $name): PoolWorker;

    public function submit(ShouldPool $gig): Promise
    {
        $promise = $this->loop->promise();
        $worker = $this->idle() ?? $this->spawnIfRoom();

        is_null($worker)
            ? $this->queue[] = [$gig, $promise]
            : $this->assign($worker, $gig, $promise);

        return $promise;
    }

    public function warm(int $count): void
    {
        while (count($this->workers) < min($count, $this->max_workers)) {
            $this->start();
        }
    }

    public function workerCount(): int
    {
        return count($this->workers);
    }

    public function shutDown(): void
    {
        [$queued, $this->queue] = [$this->queue, []];

        foreach ($queued as [, $promise]) {
            $promise->reject(new IOPoolsException('The pool shut down before the gig reached a worker.'));
        }

        [$workers, $this->workers] = [$this->workers, []];

        foreach ($workers as $name => $worker) {
            $this->loop->forget($name);
            $worker->stop();
        }
    }

    /** A worker settled its gig: hand it the next one, or take it off the loop while it idles. */
    public function finished(PoolWorker $worker): void
    {
        if (! isset($this->workers[$worker->name()])) {
            return;
        }

        $next = array_shift($this->queue);

        is_null($next)
            ? $this->loop->forget($worker->name())
            : $this->assign($worker, ...$next);
    }

    /** A worker is gone. Queued gigs still need one, so a replacement takes the oldest. */
    public function died(PoolWorker $worker): void
    {
        $this->loop->forget($worker->name());
        unset($this->workers[$worker->name()]);

        if ($this->queue !== [] && ! is_null($replacement = $this->spawnIfRoom())) {
            $this->assign($replacement, ...array_shift($this->queue));
        }
    }

    private function assign(PoolWorker $worker, ShouldPool $gig, Promise $promise): void
    {
        $this->loop->resource($worker->name(), $worker);
        $worker->assign($gig, $promise);
    }

    private function idle(): ?PoolWorker
    {
        foreach ($this->workers as $worker) {
            // An idle worker is off the loop, so a death between gigs is only found here.
            if (! $worker->alive()) {
                $worker->stop();        // releases what the dead worker still holds
                $this->died($worker);
                continue;
            }

            if (! $worker->busy()) {
                return $worker;
            }
        }

        return null;
    }

    private function spawnIfRoom(): ?PoolWorker
    {
        return count($this->workers) < $this->max_workers ? $this->start() : null;
    }

    private function start(): PoolWorker
    {
        $name = $this->prefix.'.worker.'.(++$this->spawned);

        return $this->workers[$name] = $this->spawn($name);
    }
}