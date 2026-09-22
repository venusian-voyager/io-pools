<?php

namespace Voyager\IOPools;

use SplQueue;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\PoolWorker;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\IOPools\StoppedPoolException;
use Voyager\Contracts\IOPools\WorkerPool as PoolContract;

/**
 * Everything a pool does that doesn't depend on what a worker is made of.
 * A driver says how to spawn one; the rest lives here.
 */
abstract class Pool implements PoolContract
{
    /**
     * @var array<string, PoolWorker>
     */
    protected array $workers = [];

    private SplQueue $waiting;

    private int $spawned = 0;

    public function __construct(
        protected LoopInterface $loop,
        protected int $size = 4,
        protected ?int $max_jobs = null,
    )
    {
        if ($size < 1) {
            throw new EventLoopException("A pool needs at least one worker; size was {$size}.");
        }

        if (! is_null($max_jobs) && $max_jobs < 1) {
            throw new EventLoopException("max_jobs must be 1 or more, or null to never recycle; got {$max_jobs}.");
        }

        $this->waiting = new SplQueue;
        $this->loop->onStop($this->shutDown(...));
    }

    abstract protected function spawn(string $name): PoolWorker;

    public function shutDown(): void
    {
        while (! $this->waiting->isEmpty())
        {
            [, $promise] = $this->waiting->dequeue();
            $promise->reject(new StoppedPoolException('The pool shut down before this gig started.'));
        }

        foreach ($this->workers as $worker)
        {
            $this->loop->forget($worker->name());
            $worker->stop();
        }

        // reusable: the next submit() spawns fresh
        $this->workers = [];

        $this->afterSettle();
    }

    /** A worker calls this when its job settles. */
    public function finished(PoolWorker $worker): void
    {
        if (! is_null($this->max_jobs) && $worker->jobsDone() >= $this->max_jobs)
        {
            $worker->retire();
            $worker = $this->waiting->isEmpty() ? null : $this->spawnIfRoom();
        }

        match (true) {
            is_null($worker)          => null,
            $this->waiting->isEmpty() => $this->loop->forget($worker->name()),      // idle = unwatched
            default                   => $this->assign($worker, ...$this->waiting->dequeue()),
        };

        $this->afterSettle();
    }

    public function submit(ShouldPool $gig): Promise
    {
        $promise = $this->loop->promise();

        ($worker = $this->idle() ?? $this->spawnIfRoom())
            ? $this->assign($worker, $gig, $promise)
            : $this->waiting->enqueue([$gig, $promise]);

        return $promise;
    }

    /**
     * Spawn up to $count workers now, so the first gigs don't pay for it. Capped at size.
     */
    public function warm(int $count): void
    {
        while (count($this->workers) < min($count, $this->size))
        {
            $this->spawnIfRoom();
        }
    }

    public function workerCount(): int
    {
        return count($this->workers);
    }

    public function died(PoolWorker $worker): void
    {
        $this->loop->forget($worker->name());
        $worker->close();
        unset($this->workers[$worker->name()]);

        // someone's still in line: replace it, unless the pool is already full of healthy workers
        if (! $this->waiting->isEmpty() && ($fresh = $this->spawnIfRoom())) {
            $this->assign($fresh, ...$this->waiting->dequeue());
        }

        $this->afterSettle();
    }

    /**
     * A worker that can leave at once calls this from retire(). Unlike died(), it doesn't respawn:
     * finished() is already deciding that.
     */
    public function retired(PoolWorker $worker): void
    {
        $this->loop->forget($worker->name());
        $worker->close();
        unset($this->workers[$worker->name()]);
    }

    public function __destruct()
    {
        $this->shutDown();
    }

    /** A worker just took a gig. */
    protected function afterAssign(): void
    {
        //
    }

    /** A worker just stopped being busy, or left. */
    protected function afterSettle(): void
    {
        //
    }

    private function assign(PoolWorker $worker, ShouldPool $gig, Promise $promise): void
    {
        // watched only while working
        $this->loop->resource($worker->name(), $worker);
        $worker->give($gig, $promise);

        // give() can refuse the gig and free the worker on the spot
        if ($worker->busy()) {
            $this->afterAssign();
        }
    }

    private function idle(): ?PoolWorker
    {
        // foreach copies: died() may unset during the walk
        foreach ($this->workers as $worker)
        {
            // a retiring worker's stdin is already closed
            if ($worker->busy() || $worker->retiring()) {
                continue;
            }
            if (! $worker->alive())
            {
                // died while unwatched: nobody saw the EOF
                $this->died($worker);
                continue;
            }
            return $worker;
        }
        return null;
    }

    private function spawnIfRoom(): ?PoolWorker
    {
        // a worker on its way out doesn't hold a seat, or a pool of one stalls while it winds down
        $active = count(array_filter($this->workers, fn (PoolWorker $w) => ! $w->retiring()));

        if ($active >= $this->size) return null;

        $name = 'pool:'.$this->spawned++;

        return $this->workers[$name] = $this->spawn($name);
    }
}
