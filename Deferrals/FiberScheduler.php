<?php

namespace Voyager\IOPools\Deferrals;

use Closure;
use Fiber;
use Throwable;
use Voyager\Contracts\IOPools\CancelledException;
use Voyager\Contracts\IOPools\Promise;
use Voyager\IOPools\Resources\FollowOnResource;

class FiberScheduler extends FollowOnResource
{
    public const string NAME = 'io-pools.fibers';

    /**
     * @var array<int, Fiber> spl_object_id => fiber, started here and not yet finished
     */
    private array $owned = [];

    /**
     * @var array<int, Closure> spl_object_id => wake condition, for the parked ones
     */
    private array $waiting = [];

    private bool $resuming = false;

    /**
     * A parked fiber whose condition holds can run now
     * @return bool
     */
    public function pending(): bool
    {
        foreach ($this->waiting as $assertion) {
            try {
                /** @var callable $assertion */
                if ($assertion()) {
                    return true;
                }
            } catch (Throwable) {
                return true;   // resume() delivers the throw where the fiber waited
            }
        }

        return false;
    }

    /** Start the body now; it runs to its first suspend or its end before this returns.
     * @throws Throwable
     */
    public function start(Closure $body, Promise $promise): Fiber
    {
        $fiber = new Fiber(function () use ($body, $promise): void {
            // the body's outcome is the promise's outcome, never the loop's failure
            try { $promise->resolve($body()); } catch (Throwable $e) { $promise->reject($e); }
        });

        $this->owned[spl_object_id($fiber)] = $fiber;

        $this->park($fiber, $fiber->start());

        return $fiber;
    }

    public function owns(Fiber $fiber): bool
    {
        return isset($this->owned[spl_object_id($fiber)]);
    }

    public function idle(): bool
    {
        return empty($this->waiting);
    }

    /**
     * @throws Throwable
     */
    public function resume(): bool
    {
        // a resumed fiber that borrows the loop turns it, and the turn would land back here
        if ($this->resuming) {
            return false;
        }

        $this->resuming = true;
        $ran = false;

        try
        {
            foreach ($this->waiting as $id => $assertion)
            {
                $fiber = $this->owned[$id];

                try
                {
                    if (! $assertion()) continue;

                    unset($this->waiting[$id]);
                    $this->park($fiber, $fiber->resume());
                }
                catch (Throwable $e)
                {
                    // the assertion threw: that's the fiber's problem, delivered where it waited
                    unset($this->waiting[$id]);
                    $this->park($fiber, $fiber->throw($e));
                }

                $ran = true;
            }
        }
        finally
        {
            $this->resuming = false;
        }

        return $ran;
    }

    /**
     * @throws Throwable
     */
    public function cancel(Fiber $fiber): void
    {
        $id = spl_object_id($fiber);

        if (! isset($this->waiting[$id])) {
            return;                                  // running (its own business) or finished
        }

        unset($this->waiting[$id]);
        $this->park($fiber, $fiber->throw(new CancelledException('The task was cancelled.')));
    }

    public function cancelAll(): void
    {
        foreach (array_keys($this->waiting) as $id) {
            $this->cancel($this->owned[$id]);
        }
    }

    /** Record what a start()/resume()/throw() came back with: a wake condition, or nothing (finished). */
    private function park(Fiber $fiber, mixed $yielded): void
    {
        $id = spl_object_id($fiber);

        if ($fiber->isTerminated()) {
            unset($this->owned[$id], $this->waiting[$id]);
            return;
        }

        $this->waiting[$id] = $yielded instanceof Closure
            ? $yielded
            : fn () => true;                         // suspended with no condition: run again next phase
    }
}