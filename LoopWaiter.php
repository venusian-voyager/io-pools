<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\Waiter;
use Voyager\Contracts\IOPools\WaiterBackendDriver;
use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\IOPools\Waiter\SignalRelay;
use Voyager\IOPools\Waiter\Wakes\ControlSignal;

class LoopWaiter implements Waiter
{
    /** @var array<string, array<string, Wake>> owner => key => wake the backend holds now */
    private array $held = [];

    private ?SignalRelay $relay = null;

    public function __construct(
        protected readonly ResourceRegistry $registry,
        protected readonly WaiterBackendDriver $backend,
        protected readonly int $pace_ns,
    ) {}

    public function supports(WakeReason $kind): bool
    {
        return $this->backend->supports($kind)
            || ($kind === WakeReason::CONTROL_SIGNAL && extension_loaded('pcntl'));
    }

    public function descriptor(): ?int
    {
        return $this->backend->descriptor();
    }

    public function wait(?int $deadline = null): array
    {
        $this->sync();

        $timeout = $this->timeout($deadline);
        $sleeper = $this->registry->sleeper();

        if ($sleeper)
        {
            // The sleeper's wake source is inside its own call: it blocks, the set gets a glance after.
            $sleeper->sleep($timeout ?? $this->pace_ns);
            $fired = $this->backend->wait(0);
        }
        else
        {
            $fired = $this->backend->wait($timeout);
        }

        return $this->relay?->translate($fired) ?? $fired;
    }

    /** Bring the backend's set in line with what every wake source declares this turn. */
    private function sync(): void
    {
        $wanted = [];

        foreach ($this->registry->wakeables() as $owner => $resource) {
            foreach ($resource->wakes() as $wake) {
                $wanted[$owner][$wake->key()] = $wake;
            }
        }

        foreach ($this->held as $owner => $wakes) {
            foreach ($wakes as $key => $wake) {
                if (! isset($wanted[$owner][$key])) {
                    $this->detach($owner, $wake);
                }
            }
        }

        foreach ($wanted as $owner => $wakes) {
            foreach ($wakes as $key => $wake) {
                if (! isset($this->held[$owner][$key])) {
                    $this->attach($owner, $wake);
                }
            }
        }

        $this->held = $wanted;
    }

    private function timeout(?int $deadline): ?int
    {
        // Follow-on work can run now: the wait is only a glance.
        if ($this->registry->pending()) {
            return 0;
        }

        $timeout = is_null($deadline) ? null : max(0, $deadline - hrtime(true));

        // Something polled needs checking on, or only background wakes are held and none of them
        // is work that could end an open wait: the pace caps it.
        if ($this->registry->hasPollables() || ! $this->holdsForeground()) {
            $timeout = is_null($timeout) ? $this->pace_ns : min($timeout, $this->pace_ns);
        }

        return $timeout;
    }

    private function holdsForeground(): bool
    {
        return array_any(array_keys($this->held), fn (string $owner): bool => ! $this->registry->isBackground($owner));
    }

    private function attach(string $owner, Wake $wake): void
    {
        if ($this->backend->supports($wake->kind())) {
            $this->backend->add($owner, $wake);
            return;
        }

        if ($wake instanceof ControlSignal && extension_loaded('pcntl')) {
            ($this->relay ??= new SignalRelay($this->backend))->watch($owner, $wake);
            return;
        }

        throw new IOPoolsException(sprintf(
            "Resource '%s' declared a %s wake, which %s can't wait on. Ask Loop::supports() before declaring it.",
            $owner, $wake->kind()->value, $this->backend::class,
        ));
    }

    private function detach(string $owner, Wake $wake): void
    {
        $this->backend->supports($wake->kind())
            ? $this->backend->remove($owner, $wake)
            : $this->relay?->unwatch($owner, $wake);
    }
}