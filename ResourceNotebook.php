<?php

namespace Voyager\IOPools;

use Closure;
use Throwable;
use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\Sleepable;
use Voyager\NutsAndBolts\Collection;
use Voyager\Contracts\IOPools\Pumpable;
use Voyager\Contracts\IOPools\Resumable;
use Voyager\Contracts\IOPools\Tickable;
use Voyager\Contracts\IOPools\LoopTimer;
use Voyager\Contracts\IOPools\Sourceable;
use Voyager\Contracts\IOPools\StreamWatchable;

class ResourceNotebook
{
    /** @var Collection<LoopTimer>  */
    protected Collection $timers;

    /** @var Collection<Tickable|Sourceable>  */
    protected Collection $tickables;

    /** @var Collection<StreamWatchable>  */
    protected Collection $streamables;

    /** @var Collection<Pumpable>  */
    protected Collection $pumpables;

    /** @var Collection<Resumable>  */
    protected Collection $resumables;

    protected ?Sleepable $fallback_timer_owner = null;

    protected MailBag $mail;

    protected ?Throwable $failure = null;

    public function __construct() {
        $this->timers = new Collection();
        $this->tickables = new Collection();
        $this->streamables = new Collection();
        $this->pumpables = new Collection();
        $this->resumables = new Collection();
        $this->mail = new MailBag();
    }

    public function watchedStreams(): array
    {
        $streams = [];
        $owners  = [];

        foreach ($this->streamables as $name => $resource)
        {
            foreach ($resource->streams() as $stream)
            {
                if(is_resource($stream))
                {
                    $streams[] = $stream;
                    $owners[(int) $stream] = $name;      // stream id → resource name
                }
            }
        }

        return [$streams, $owners];
    }

    public function setStreamingResource(string $name, StreamWatchable $resource): StreamWatchable
    {
        $this->streamables[$name] = $resource;
        $this->setPumpableResource($name, $resource);
        return $resource;
    }

    public function setTickableResource(string $name, Tickable $resource): Tickable
    {
        $this->tickables[$name] = $resource;
        $this->setPumpableResource($name, $resource);
        $this->setSleepableResource($resource);
        return $resource;
    }

    public function setPumpableResource(string $name, Sourceable $resource): ?Pumpable
    {
        if($resource instanceof Pumpable)
        {
            $this->pumpables[$name] = $resource;
            return $resource;
        }

        return null;
    }

    public function setSleepableResource(Tickable $resource): ?Sleepable
    {
        if($resource instanceof Sleepable)
        {
            $this->fallback_timer_owner = $resource;
            return $resource;
        }

        return null;
    }

    public function setResumableResource(string $name, Resumable $resource): Resumable
    {
        $this->resumables[$name] = $resource;
        return $resource;
    }

    public function soonestDueDate(): ?float
    {
        $next = null;

        $timers = $this->timers->sortBy(fn(LoopTimer $t) => $t->dueAt());

        foreach ($timers as $timer)
        {
            $timer_is_alive = !$timer->cancelled();
            if($timer_is_alive)
            {
                $should_hang_tight_a_little_longer = (empty($next) || $timer->dueAt() <= $next);
                if($should_hang_tight_a_little_longer)
                {
                    $next = $timer->dueAt();
                    break;
                }
            }
        }

        return $next;
    }

    public function fire(float $now): void
    {
        foreach ($this->timers as $i => $timer)
        {
            if($timer->cancelled())
            {
                $this->delistTimer($i);
            }
            elseif ($timer->dueAt() <= $now)
            {
                // Move the note BEFORE calling it: a callback that borrows the loop (until())
                // turns it again, and a note still sitting here due would fire a second time.
                $interval = $timer->interval();

                is_null($interval)
                    ? $this->delistTimer($i)
                    : $timer->setDueAt($timer->dueAt() + $interval);

                $this->guarded(fn() => $timer->fire());

                // The callback may have stalled past several beats: skip them, stay on the grid.
                if (! is_null($interval) && $timer->dueAt() <= ($after = microtime(true)))
                {
                    $due_at = $timer->dueAt();
                    $timer->setDueAt($due_at + ceil(($after - $due_at) / $interval) * $interval);
                }
            }
        }
    }

    public function read(array $names): void
    {
        foreach (array_unique($names) as $name)
        {
            $this->guarded(fn() => $this->streamables->get($name)?->tick());
        }
    }

    public function tick(): void
    {
        foreach ($this->tickables as $resource)
        {
            $this->guarded(fn() => $resource->tick());
        }
    }

    public function pump(): void
    {
        foreach ($this->pumpables as $resource)
        {
            $this->guarded(fn() => $this->pumpResource($resource));
        }
    }

    /**
     * The phase after flush: fibers whose waits just settled run now, not next turn.
     * Not guarded: a resumable owns its own failures.
     */
    public function resume(): bool
    {
        $ran = false;

        foreach ($this->resumables as $resource)
        {
            $ran = $resource->resume() || $ran;
        }

        return $ran;
    }

    public function queue(Event $mail): void
    {
        $this->mail->queue([$mail]);
    }

    public function mail(): ?QueuedMail
    {
        $mail = $this->mail->flush(true);
        return empty($mail)
            ? null
            : QueuedMail::make($mail);
    }

    /**
     * Plain tickables can't end a sleep, so they need checking on at a pace.
     */
    public function hasTickables(): bool
    {
        return !$this->tickables->isEmpty();
    }

    /**
     * Anything registered that could still produce work. Timers are the
     * loop's question — soonestDueDate() already skips the cancelled ones.
     */
    public function hasResources(): bool
    {
        return !$this->tickables->isEmpty()
            || !$this->streamables->isEmpty()
            || !is_null($this->fallback_timer_owner);
    }

    public function hasSleeper(): bool
    {
        return !is_null($this->fallback_timer_owner);
    }

    /**
     * Hand the sleep to the sleeper. Answers who fired while it slept.
     * @return array<string>
     */
    public function deferToSleeper(int $budget_ms): array
    {
        return $this->fallback_timer_owner?->sleep($budget_ms) ?? [];
    }

    public function forget(string $name): void
    {
        $resource = $this->tickables->get($name) ?? $this->streamables->get($name);

        if ($resource instanceof Pumpable) {
            $this->mail->queue($resource->pump());        // last mail out before it leaves
        }

        $this->tickables->forget($name);
        $this->streamables->forget($name);
        $this->pumpables->forget($name);
        $this->resumables->forget($name);

        if ($resource === $this->fallback_timer_owner) {
            $this->fallback_timer_owner = null;
        }
    }

    public function setActionTimer(float $delay_s, callable $fire): ActionTimer
    {
        $now = microtime(true);

        $timer = new ActionTimer($now + $delay_s, $fire(...));

        $this->timers->put($timer->uuid(), $timer);

        return $timer;
    }

    public function setRecurringActionTimer(string $name, float $interval_s, callable $fire): ActionTimer
    {
        $now = microtime(true);
        $timer = new ActionTimer($now + $interval_s, $fire(...), $interval_s, $name);
        $this->timers->put($timer->uuid(), $timer);
        return $timer;
    }

    public function failure(): ?Throwable
    {
        [$e, $this->failure] = [$this->failure, null];
        return $e;
    }

    protected function guarded(Closure $work): void
    {
        try { $work(); } catch (Throwable $e) { $this->failure ??= $e; }
    }

    protected function delistTimer(string $uuid): void
    {
        $this->timers->forget($uuid);
    }

    private function pumpResource(Pumpable $resource): void
    {
        $mail = $resource->pump();
        if(!empty($mail))
        {
            $this->mail->queue($mail);
        }
    }
}