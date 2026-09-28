<?php

namespace Voyager\IOPools;

use Fiber;
use Closure;
use Throwable;
use FiberError;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\Deferrals\Task;
use Voyager\Contracts\IOPools\Waiter;
use Voyager\Contracts\IOPools\MailHandler;
use Voyager\IOPools\Deferrals\Deferrals;
use Voyager\IOPools\Timers\ActionTimer;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\LoopResource;
use Voyager\Contracts\IOPools\PromiseEngine;
use Voyager\IOPools\Deferrals\FiberScheduler;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use Voyager\Contracts\IOPools\Promise as PromiseInterface;

class EventLoop implements Loop
{
    protected int $status = 0;

    protected bool $running = false;

    protected bool $stopping = false;

    protected array $on_stop = [];

    protected FiberScheduler $fibers;

    protected Deferrals $deferrals;

    protected StopSignals $stop_signals;

    public function __construct(
        public readonly ResourceRegistry $registry,
        public readonly Waiter $waiter,
        public readonly PromiseEngine $promises,
        public readonly ?MailHandler $mail_handler = null,
    ) {
        $this->fibers = new FiberScheduler();
        $this->deferrals = new Deferrals($this);
        $this->stop_signals = new StopSignals($this);

        $this->registry->add(Deferrals::NAME, $this->deferrals);
        $this->registry->add(FiberScheduler::NAME, $this->fibers);
        $this->registry->add(StopSignals::NAME, $this->stop_signals);
    }

    public function at(float $delay_s, callable $fire): Timer
    {
        $timer = new ActionTimer((int) round($delay_s * 1e9), $fire(...));
        $this->registry->add('timer.'.spl_object_id($timer), $timer);

        return $timer;
    }

    public function every(float $interval_s, callable $fire, string $name): Timer
    {
        $interval_ns = (int) round($interval_s * 1e9);

        /**
         * @var Timer
         */
        return $this->registry->add($name, new ActionTimer($interval_ns, $fire(...), $interval_ns));
    }

    public function promise(): Promise
    {
        return new Promise($this->promises, $this->promises->make(), $this);
    }

    /**
     * @throws Throwable
     */
    public function await(mixed $value): mixed
    {
        return match (true) {
            $value instanceof PromiseInterface => $value->wait(),
            is_object($value) && method_exists($value, 'then') =>
            new Promise($this->promises, $this->promises->adopt($value), $this)->wait(),
            default => $value,
        };
    }

    /**
     * @throws Throwable
     */
    public function async(callable $body): Task
    {
        $promise = $this->promise();

        return new Task($promise, $this->fibers->start($body(...), $promise), $this->fibers);
    }

    public function defer(Closure $work): PromiseInterface
    {
        return $this->deferrals->add($work);
    }

    public function post(object $mail): void
    {
        $this->registry->post($mail);
    }

    public function resource(string $name, LoopResource $resource): LoopResource
    {
        return $this->registry->add($name, $resource);
    }

    public function forget(string $name): void
    {
        $this->registry->forget($name);
    }

    public function crown(string $name): void
    {
        $this->registry->crown($name);
    }

    public function supports(WakeReason $kind): bool
    {
        return $this->waiter->supports($kind);
    }

    public function onStop(callable $hook): void
    {
        $this->on_stop[] = $hook(...);
    }

    /**
     * @throws Throwable
     */
    public function run(): int
    {
        [$this->status, $this->stopping, $this->running] = [0, false, true];
        $this->stop_signals->arm();

        try {
            while (! $this->stopping && $this->registry->hasWork()) {
                $this->turn();
            }
        } finally {
            $this->running = false;
            $this->fibers->cancelAll();
            $this->promises->flush();

            foreach ($this->on_stop as $hook) {
                try { $hook(); } catch (Throwable) {}
            }

            // What the hooks settled (a pool rejecting its gigs, say) lands now, not on some later turn.
            $this->promises->flush();

            $this->stopping = false;
        }

        return $this->status;
    }

    public function stop(int $status = 0): void
    {
        $this->stopping = true;
        $this->status = $status;
    }

    /**
     * @throws Throwable
     */
    public function until(Closure $assertion): void
    {
        if ($assertion()) {
            return;
        }

        $fiber = Fiber::getCurrent();

        if (! is_null($fiber) && $this->fibers->owns($fiber)) {
            try {
                Fiber::suspend($assertion);
                return;
            } catch (FiberError) {
                // a C frame sits between us and the fiber: borrow the loop instead
            }
        }

        // Inside a run the run's own watch stands; a wait on its own listens again.
        if (! $this->running) {
            $this->stop_signals->arm();
        }

        while (! $assertion())
        {
            if ($this->stopping) {
                if (! $this->running) {
                    $this->stopping = false;
                }

                throw new IOPoolsException('The loop was stopped while until() was still waiting.');
            }

            if (! $this->registry->hasWork())
            {
                $this->promises->flush();

                if ($assertion()) {
                    return;
                }

                // Only parked fibers are left and nothing can wake them: cancel, then look again.
                if (! $this->fibers->idle()) {
                    $this->fibers->cancelAll();
                    $this->promises->flush();
                    continue;
                }

                throw new IOPoolsException('until() ran out of work before its condition was met.');
            }

            $this->turn(quiet: true);
        }
    }

    /**
     * @throws Throwable
     */
    protected function turn(bool $quiet = false): void
    {
        $fired = $this->waiter->wait($this->registry->soonestDue());

        $this->deferrals->release();
        $this->registry->wake($fired);
        $this->registry->fireDue(hrtime(true));
        $this->registry->tick();

        do {
            $this->promises->flush();
        } while ($this->registry->resume());

        $this->registry->pump();

        if (! $quiet && $this->mail_handler && ($mail = $this->registry->mail())) {
            $this->mail_handler->handOff($mail, $this);
        }

        if ($e = $this->registry->failure()) {
            throw $e;
        }
    }
}