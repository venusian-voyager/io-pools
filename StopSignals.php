<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\StopSignal;
use Voyager\Contracts\IOPools\LoopResources\Background;

/**
 * Stops the loop on SIGINT or SIGTERM, so run() returns and every onStop() hook runs:
 * a pool's workers are shut down instead of orphaned. Background: it never keeps run() alive.
 */
class StopSignals extends WakeSource implements Background
{
    public const string NAME = 'io-pools.stop-signals';

    /** Signal number => exit status, as a shell reports a death by that signal. SIGINT and SIGTERM are 2 and 15 on Linux and macOS. */
    private const array STATUS = [2 => 130, 15 => 143];

    private int $epoch = 0;

    private bool $tripped = false;

    /** Listening: a run or a standalone until() is going on. */
    private bool $armed = false;

    /** The loop leaves stop signals to the process (a loop in a thread, a loop nested in another runner). */
    private bool $ignored = false;

    /** @var list<\Closure(int): bool> asked first, in order, on a stop signal */
    private array $handlers = [];

    public function __construct(
        protected readonly LoopInterface $loop
    ) {}

    /** A new run or wait listens again after an earlier stop tripped the watch. */
    /** Ask $handler before stopping the loop on a stop signal; one returning true takes it. */
    public function listen(\Closure $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** Never listen: the process's stop signals are someone else's. */
    public function ignore(): void
    {
        $this->ignored = true;
    }

    /** Stop listening, until the next arm(). */
    public function disarm(): void
    {
        $this->armed = false;
    }

    public function arm(): void
    {
        $this->armed = true;

        if ($this->tripped) {
            $this->tripped = false;
            $this->epoch++;
        }
    }

    public function wakes(): array
    {
        if (! $this->armed || $this->ignored || $this->tripped || ! $this->loop->supports(WakeReason::CONTROL_SIGNAL))
        {
            return [];
        }

        return array_map(fn (int $signo): StopSignal => new StopSignal($signo, $this->epoch), array_keys(self::STATUS));
    }

    public function woke(array $fired): void
    {
        foreach ($fired as $wake) {
            if (! $wake instanceof StopSignal) {
                continue;
            }

            $this->tripped = true;

            // Asked once and still not stopped: a second one dies exactly as if nobody listened.
            foreach (array_keys(self::STATUS) as $signo)
            {
                pcntl_signal($signo, SIG_DFL);
            }

            foreach ($this->handlers as $handler) {
                if ($handler($wake->signo) === true) {
                    return;   // taken: the handler stops the loop once it is done
                }
            }

            $this->loop->stop(self::STATUS[$wake->signo]);

            return;
        }
    }
}