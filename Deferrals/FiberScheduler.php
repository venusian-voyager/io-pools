<?php

namespace Voyager\IOPools\Deferrals;

use Error;
use Fiber;
use Closure;
use Exception;
use Throwable;
use ReflectionProperty;
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
     * @var list<Closure(Fiber): void> handed each fiber this starts, before it runs
     */
    private array $on_start = [];

    /**
     * @var list<array{Closure(Fiber): void, Closure(Fiber): void}> run inside a fiber around each loop wait it suspends on
     */
    private array $on_suspend = [];

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
    public function onStart(Closure $hook): void
    {
        $this->on_start[] = $hook;
    }

    /**
     * Run $suspending inside a fiber just before it suspends on a loop wait, and $resumed just
     * after it returns there, by resumption, a throw or a cancel.
     */
    public function onSuspend(Closure $suspending, Closure $resumed): void
    {
        $this->on_suspend[] = [$suspending, $resumed];
    }

    /**
     * Suspend the current fiber, which this scheduler owns, with $value, inside its suspend hooks.
     * The suspending hooks run in the order given; the resumed ones in reverse, for every
     * suspending hook that ran, whatever the suspend point returned or threw. A suspending hook
     * that throws stops the suspend: the hooks before it are resumed and its exception thrown.
     * An exception from the suspend point is thrown as it is, with any a resumed hook threw
     * chained under it; with none in flight, the first resumed hook's exception is thrown.
     *
     * @throws Throwable
     */
    public function suspend(Fiber $fiber, mixed $value): void
    {
        $resumers = [];

        try {
            foreach ($this->on_suspend as [$suspending, $resumed]) {
                $suspending($fiber);
                $resumers[] = $resumed;
            }
        } catch (Throwable $e) {
            throw $this->resumed($fiber, $resumers, $e);
        }

        $in_flight = null;

        try {
            Fiber::suspend($value);
        } catch (Throwable $e) {
            $in_flight = $e;
        }

        if (($thrown = $this->resumed($fiber, $resumers, $in_flight)) !== null) {
            throw $thrown;
        }
    }

    public function start(Closure $body, Promise $promise): Fiber
    {
        $fiber = new Fiber(function () use ($body, $promise): void {
            // the body's outcome is the promise's outcome, never the loop's failure
            try { $promise->resolve($body()); } catch (Throwable $e) { $promise->reject($e); }
        });

        foreach ($this->on_start as $hook) {
            $hook($fiber);
        }

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
    /**
     * Run the resumed hooks newest first; each that throws is chained under the exception already
     * in flight, or becomes it.
     *
     * @param list<Closure(Fiber): void> $resumers
     */
    private function resumed(Fiber $fiber, array $resumers, ?Throwable $in_flight): ?Throwable
    {
        foreach (array_reverse($resumers) as $resumed) {
            try {
                $resumed($fiber);
            } catch (Throwable $e) {
                $in_flight = $in_flight === null ? $e : self::chain($in_flight, $e);
            }
        }

        return $in_flight;
    }

    /**
     * Put $cause at the end of $e's previous chain, keeping $e itself as thrown.
     */
    private static function chain(Throwable $e, Throwable $cause): Throwable
    {
        $last = $e;

        while ($last->getPrevious() !== null) {
            $last = $last->getPrevious();
        }

        // Throwable's previous is set only by its constructor; reflection is the one way to add a cause after.
        new ReflectionProperty($last instanceof Exception ? Exception::class : Error::class, 'previous')->setValue($last, $cause);

        return $e;
    }

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