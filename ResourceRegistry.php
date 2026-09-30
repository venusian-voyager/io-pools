<?php

namespace Voyager\IOPools;

use Closure;
use Throwable;
use Voyager\Contracts\IOPools\LoopResource;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\LoopResources\Background;
use Voyager\Contracts\IOPools\LoopResources\Deadlined;
use Voyager\Contracts\IOPools\LoopResources\Resumable;
use Voyager\Contracts\IOPools\LoopResources\Sleepable;
use Voyager\Contracts\IOPools\LoopResources\Tickable;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use Voyager\Contracts\IOPools\LoopResources\Wakeable;
use Voyager\Contracts\IOPools\Pumpable;
use Voyager\Contracts\IOPools\Wake;

class ResourceRegistry
{
    /** @var array<string, Wakeable> */
    private array $wakeables = [];

    /** @var array<string, Deadlined> */
    private array $deadlines = [];

    /** @var array<string, Resumable> */
    private array $follow_ons = [];

    /** @var array<string, Tickable> polled only: sleepers are kept apart */
    private array $pollables = [];

    /** @var array<string, Sleepable> */
    private array $sleepers = [];

    /** @var list<string> sleeper names, first in line holds the sleep */
    private array $succession = [];

    /** @var array<string, Pumpable> */
    private array $pumpables = [];

    /**
     * @var array<string, true> names that never keep the loop running
     */
    private array $background = [];

    /**
     * @var list<object> mail posted or pumped since the last delivery
     */
    private array $mail = [];

    private ?Throwable $failure = null;

    public function add(string $name, LoopResource $resource): LoopResource
    {
        // Re-using a name replaces the old resource in every list, never in half of them.
        $this->forget($name);

        $kinds = 0;

        if ($resource instanceof Wakeable)  { $this->wakeables[$name]  = $resource; $kinds++; }
        if ($resource instanceof Deadlined) { $this->deadlines[$name]  = $resource; $kinds++; }
        if ($resource instanceof Resumable) { $this->follow_ons[$name] = $resource; $kinds++; }

        if ($resource instanceof Sleepable)
        {
            $this->sleepers[$name] = $resource;
            $this->succession[] = $name;
            $kinds++;
        }
        elseif ($resource instanceof Tickable)
        {
            $this->pollables[$name] = $resource;
            $kinds++;
        }

        if ($resource instanceof Pumpable)
        {
            $this->pumpables[$name] = $resource;
        }

        if ($resource instanceof Background)
        {
            $this->background[$name] = true;
        }

        if ($kinds === 0)
        {
            throw new IOPoolsException("Resource '{$name}' implements no loop resource kind.");
        }

        return $resource;
    }

    public function forget(string $name): void
    {
        if (isset($this->pumpables[$name])) {
            $this->collect($this->pumpables[$name]);   // last mail out before it leaves
        }

        unset(
            $this->wakeables[$name], $this->deadlines[$name], $this->follow_ons[$name],
            $this->pollables[$name], $this->sleepers[$name], $this->pumpables[$name],
            $this->background[$name],
        );

        // The next sleeper in line inherits the sleep.
        $this->succession = array_values(array_diff($this->succession, [$name]));
    }

    public function crown(string $name): void
    {
        if (! isset($this->sleepers[$name])) {
            throw new IOPoolsException("No sleeper named '{$name}' is registered.");
        }

        $this->succession = [$name, ...array_values(array_diff($this->succession, [$name]))];
    }

    public function sleeper(): ?Sleepable
    {
        return isset($this->succession[0]) ? $this->sleepers[$this->succession[0]] : null;
    }

    /**
     * @return array<string, Wakeable>
     */
    public function wakeables(): array
    {
        return $this->wakeables;
    }

    /**
     * Anything that must be checked on at the pace: pollables, and sleepers not holding the sleep.
     *
     * @return bool
     */
    public function hasPollables(): bool
    {
        return ! empty($this->pollables) || count($this->succession) > 1;
    }

    /**
     * Follow-on work that can run right now. The Waiter makes the next wait a glance.
     *
     * @return bool
     */
    public function pending(): bool
    {
        return array_any($this->follow_ons, fn($resource) => $resource->pending());

    }

    public function soonestDue(): ?int
    {
        $soonest = null;

        foreach ($this->deadlines as $deadline) {
            $due = $deadline->dueAt();

            if (! is_null($due) && (is_null($soonest) || $due < $soonest)) {
                $soonest = $due;
            }
        }

        return $soonest;
    }

    /**
     * Anything registered that could still produce work.
     *
     * @return bool
     */
    public function hasWork(): bool
    {
        return $this->counts($this->wakeables)
            || $this->counts($this->pollables)
            || $this->counts($this->sleepers)
            || ! is_null($this->soonestDue())
            || $this->pending();
    }

    /** A background resource serves the loop while it runs but never keeps it running. */
    public function isBackground(string $name): bool
    {
        return isset($this->background[$name]);
    }

    /**
     * @param array<string, list<Wake>> $fired
     */
    public function wake(array $fired): void
    {
        foreach ($fired as $owner => $wakes) {
            // A resource forgotten earlier this turn may still be in the backend's answer.
            if (isset($this->wakeables[$owner])) {
                $this->guarded(fn () => $this->wakeables[$owner]->woke($wakes));
            }
        }
    }

    public function fireDue(int $now): void
    {
        foreach ($this->deadlines as $name => $deadline) {
            if ($deadline instanceof Timer && $deadline->cancelled()) {
                $this->forget($name);
                continue;
            }

            $due = $deadline->dueAt();

            if (is_null($due) || $due > $now) {
                continue;
            }

            $this->guarded(fn () => $deadline->fire());

            // A one-shot timer has no next due time once it fired.
            if ($deadline instanceof Timer && is_null($deadline->dueAt())) {
                $this->forget($name);
            }
        }
    }

    public function tick(): void
    {
        foreach ([...$this->sleepers, ...$this->pollables] as $resource) {
            $this->guarded(fn () => $resource->tick());
        }
    }

    /**
     * True if any follow-on ran. The loop flushes promises and asks again until none do.
     *
     * @return bool
     */
    public function resume(): bool
    {
        $ran = false;

        foreach ($this->follow_ons as $resource) {
            $ran = $resource->resume() || $ran;
        }

        return $ran;
    }

    public function pump(): void
    {
        foreach ($this->pumpables as $resource) {
            $this->guarded(fn () => $this->collect($resource));
        }
    }

    /**
     * Queue mail for the next delivery, as if a resource had pumped it.
     *
     * @param object $mail
     * @return void
     */
    public function post(object $mail): void
    {
        $this->mail[] = $mail;
    }

    /**
     * @return list<object>
     */
    public function mail(): array
    {
        [$mail, $this->mail] = [$this->mail, []];

        return $mail;
    }

    /**
     * @return Throwable|null
     */
    public function failure(): ?Throwable
    {
        [$e, $this->failure] = [$this->failure, null];

        return $e;
    }

    /** @param array<string, LoopResource> $resources */
    private function counts(array $resources): bool
    {
        return array_any(array_keys($resources), fn (string $name): bool => ! isset($this->background[$name]));
    }

    /**
     * @param Pumpable $resource
     * @return void
     */
    private function collect(Pumpable $resource): void
    {
        array_push($this->mail, ...$resource->pump());
    }

    /**
     * @param Closure $work
     * @return void
     */
    private function guarded(Closure $work): void
    {
        try
        {
            $work();
        }
        catch (Throwable $e)
        {
            $this->failure ??= $e;
        }
    }
}