<?php

namespace Voyager\IOPools;

use Closure;
use Fiber;
use FiberError;
use Throwable;
use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Receivable;
use Voyager\Contracts\IOPools\PromiseEngine;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use Voyager\Contracts\IOPools\Tickable;
use Voyager\Contracts\IOPools\Resumable;
use Voyager\Contracts\IOPools\LoopTimer;
use Voyager\IOPools\Concerns\PoolWaiter;
use Voyager\Contracts\IOPools\Sourceable;
use Voyager\Contracts\IOPools\StreamWatchable;
use Voyager\IOPools\Concerns\NotebookRegistrar;
use Voyager\Contracts\IOPools\Loop as LoopContract;
use Voyager\Contracts\IOPools\Promise as PromiseContract;
use Voyager\Contracts\IOPools\Task as TaskContract;

class EventLoop implements LoopContract
{
    use PoolWaiter, NotebookRegistrar;

    private int $status = 0;
    private array $on_stop = [];
    private bool $running = false;
    private bool $stopping = false;
    private bool $signals_installed = false;
    private FiberScheduler $scheduler;
    private Deferrals $deferrals;

    public function __construct(
        protected ?ResourceNotebook $notebook = null,
        protected ?int $tick_budget_ms = 0,
        protected ?Receivable $mail_handler = null,
        protected ?PromiseEngine $promise_engine = null,
    ) {
        $this->notebook ??= new ResourceNotebook();
        $this->promise_engine ??= new GuzzlePromiseEngine();

        // always registered: it is never counted as work, so it can't keep a run alive
        $this->scheduler = new FiberScheduler;
        $this->notebook->setResumableResource('fibers', $this->scheduler);
        $this->deferrals = new Deferrals($this);
    }

    /**
     * @return int
     * @throws Throwable
     */
    public function run(): int
    {
        $this->status = 0;
        $this->stopping = false;
        $this->running = true;

        $this->listenForDeath();

        try {
            $this->loop();
        }
        finally {
            $this->running = false;

            // fibers first: their tasks reject before any other stop hook (a pool's, say) runs
            $this->scheduler->cancelAll();
            $this->promise_engine->flush();

            foreach ($this->on_stop as $hook) {
                try { $hook(); } catch (Throwable) {}
            }

            // the stop ended this run, not the loop: later until() calls and the next run() start clean
            $this->stopping = false;
        }

        return $this->status;
    }

    /**
     * Wait until $assertion holds. Same call site, context decides:
     *  - inside a fiber async() started: suspend, and the resume phase brings us back once it holds
     *  - anywhere else (main stack, a foreign fiber, a fiber sitting on a C frame): borrow the loop
     *    and turn quietly. Mail stays in the bag.
     * @throws Throwable
     */
    public function until(Closure $assertion): void
    {
        if ($assertion()) {
            return;
        }

        $fiber = Fiber::getCurrent();

        if (! is_null($fiber) && $this->scheduler->owns($fiber))
        {
            try {
                Fiber::suspend($assertion);
                return;
            } catch (FiberError) {
                // a C frame (a native callback) is between us and the fiber: fall through and borrow
            }
        }

        $this->listenForDeath();

        while (! $assertion())
        {
            if ($this->stopping) {
                // outside run() nothing else clears the flag: the stop ends this wait, not the loop
                if (! $this->running) {
                    $this->stopping = false;
                }

                throw new EventLoopException('The loop was stopped while until() was still waiting.');
            }

            // Asked fresh every turn: the last timer may have fired, the last resource may have left.
            if (is_null($this->nextDue()) && ! $this->notebook->hasResources())
            {
                // a settled foreign promise still has its callbacks queued: that is work, flush it first
                $this->promise_engine->flush();
                if ($assertion()) {
                    return;
                }

                // only suspended fibers are left and nothing can wake them: cancel them, then look again,
                // so a wait on one of their tasks sees CancelledException rather than this
                if (! $this->scheduler->idle())
                {
                    $this->scheduler->cancelAll();
                    $this->promise_engine->flush();
                    continue;
                }

                throw new EventLoopException('until() ran out of work before its condition was met.');
            }

            $this->turn(quiet: true);
        }
    }

    public function running(): bool
    {
        return $this->running;
    }

    public function stop(int $status = 0): void
    {
        $this->stopping = true;
        $this->status = $status;
    }

    /**
     * Lets the ResourceNotebook tick resource as
     * soon as something is ready
     * @param bool $quiet
     * @return void
     * @throws Throwable
     */
    protected function turn(bool $quiet = false): void
    {
        $now = microtime(true);

        $next = $this->nextDue();

        // A null $next is still a turn: no alarm is set, so the wait decides what to sleep on.
        $fired = $this->wait(for: $next, until: $now);

        // Tick everything that's due. For a timer, "tick" = call its function.
        $this->notebook->read($fired);
        $this->notebook->fire($now);
        $this->notebook->tick();
        // settled this turn → callbacks this turn → fibers waiting on them resume this turn, until quiet
        do {
            $this->promise_engine->flush();
        } while ($this->notebook->resume());

        $this->notebook->pump();

        if(!$quiet)
        {
            $this->react();
        }
        $this->throwFailsIfAny();
    }

    public function at(float $delay_s, callable $fire): LoopTimer
    {
        return $this->oneShotTimer($delay_s, $fire);
    }

    public function every(float $interval_s, callable $fire, string $name): LoopTimer
    {
        return $this->intervalTimer($interval_s, $fire, $name);
    }

    /**
     * A new pending promise. Hand it out, keep a reference, and resolve() or reject() it from a tick.
     */
    public function promise(): Promise
    {
        return new Promise($this->promise_engine, $this->promise_engine->make(), $this);
    }

    /**
     * Wait on anything: our promise, anyone else's thenable, or a plain value (handed straight back).
     * @throws Throwable
     */
    public function await(mixed $value): mixed
    {
        return match (true) {
            $value instanceof PromiseContract => $value->wait(),
            is_object($value) && method_exists($value, 'then') => $this->adopt($value)->wait(),
            default => $value,
        };
    }

    public function adopt(object $thenable): PromiseContract
    {
        return new Promise($this->promise_engine, $thenable, $this);
    }

    public function async(callable $body): TaskContract
    {
        $promise = $this->promise();

        return new Task($promise, $this->scheduler->start($body(...), $promise), $this->scheduler);
    }

    public function defer(Closure $work): PromiseContract
    {
        return $this->deferrals->add($work);
    }

    public function post(Event $event): void
    {
        $this->notebook->queue($event);
    }

    public function forget(string $name): void
    {
        $this->notebook->forget($name);
    }

    public function resource(string $name, Sourceable $resource): Sourceable
    {
        return match (true) {
            $resource instanceof StreamWatchable => $this->streamingResource($name, $resource),
            $resource instanceof Tickable => $this->tickableResource($name, $resource),
            $resource instanceof Resumable => $this->resumableResource($name, $resource),
        };
    }

    /**
     * Runs when run() ends, however it ends. For services that live as long as the loop:
     * hooks are never removed.
     *
     * @param callable $hook
     * @return void
     */
    public function onStop(callable $hook): void
    {
        $this->on_stop[] = $hook(...);
    }

    /**
     * @throws Throwable
     */
    private function loop(): void
    {
        $next = $this->nextDue();

        // Nothing due and nobody registered: nothing could ever produce work, so the run is over.
        while (! $this->stopping && (!is_null($next) || $this->notebook->hasResources()))
        {
            $this->turn();

            // Look at the notebook. When is the soonest note due?
            $next = $this->nextDue();
        }

        $this->running = false;
    }

    private function react() : void
    {
        if($mail = $this->notebook->mail())
        {
            $this->mail_handler?->handOff($mail);
        }

    }

    private function nextDue(): ?float
    {
        return $this->notebook->soonestDueDate();
    }

    private function listenForDeath(): void
    {
        if ($this->signals_installed || ! extension_loaded('pcntl')) {
            return;
        }

        $this->signals_installed = true;
        pcntl_async_signals(true);

        foreach ([SIGINT => 130, SIGTERM => 143] as $signal => $status)
        {
            pcntl_signal($signal, function () use ($signal, $status) {
                // Asked once already and we still haven't got there: they mean it.
                // Die exactly as if we had never listened.
                if ($this->stopping) {
                    pcntl_signal($signal, SIG_DFL);
                    posix_kill(posix_getpid(), $signal);
                }

                $this->stop($status);
            });
        }
    }

    /**
     * @throws Throwable
     */
    private function throwFailsIfAny(): void
    {
        if ($e = $this->notebook->failure()) throw $e;
    }
}