<?php

namespace Voyager\IOPools\Concerns;

trait PoolWaiter
{
    /**
     * The ONE sleep. $for is the soonest timer's due time, or null when no alarm is set.
     */
    protected function wait(?float $for, float &$until): array
    {
        [$read, $owners] = $this->notebook->watchedStreams();

        // A sleeper owns the sleep on every path; the doorbell only gets a glance afterwards.
        if ($this->notebook->hasSleeper())
        {
            return $this->waitOnSleeper($read, $owners, $for, $until);
        }

        if (empty($read))
        {
            is_null($for)
                ? $this->waitOnFallback($until)                 // no alarm set → the fallback pace
                : $this->waitOnTimersIfNeeded($for, $until);    // nobody to listen for → alarm clock

            return [];
        }

        // No alarm set, but plain tickables still need checking on: the fallback pace stands in
        // as the deadline. With none of those either, $for stays null and the doorbell waits forever.
        if (is_null($for) && $this->notebook->hasTickables())
        {
            $for = $until + ($this->fallbackPaceMs() / 1000);
        }

        return $this->waitOnStreams($read, $owners, $for, $until);   // somebody → doorbell
    }

    /**
     * The sleeper sleeps until the soonest alarm, or the fallback pace when none is set.
     * Streams can't ride in its sleep, so they get a zero-timeout select once it wakes.
     */
    protected function waitOnSleeper(array $read, array $owners, ?float $for, float &$until): array
    {
        $budget_ms = is_null($for)
            ? $this->fallbackPaceMs()
            : (int) ceil(max(0.0, $for - $until) * 1000);

        $fired = $this->notebook->deferToSleeper($budget_ms);
        $until = microtime(true);

        return empty($read)
            ? $fired
            : [...$fired, ...$this->waitOnStreams($read, $owners, $until, $until)];
    }

    /**
     * No deadline, no doorbell, no sleeper: sleep the fallback pace here.
     */
    protected function waitOnFallback(float &$until): void
    {
        usleep($this->fallbackPaceMs() * 1_000);

        $until = microtime(true);
    }

    protected function fallbackPaceMs(): int
    {
        return $this->tick_budget_ms ?? 0;
    }

    protected function waitOnStreams(array $read, array $owners, ?float $for, float &$until): array
    {
        // A null deadline is a null timeout, which select reads as "forever" — and when the
        // seconds are null the microseconds must be too.
        $timeout = is_null($for) ? null : max(0.0, $for - $until);

        // The doorbell. Same timeout, split into the two ints select wants.
        $write = $except = null;
        $seconds = is_null($timeout) ? null : (int) $timeout;
        $micro   = is_null($timeout) ? null : (int) (($timeout - $seconds) * 1_000_000);

        $fired = @stream_select($read, $write, $except, $seconds, $micro);
        $until = microtime(true);

        /**
         * true = $read now holds ONLY the streams with data. Map them back to names.
         * false = a signal interrupted the sleep; 0 = the clock won. Either way, nobody rang.
         */
        return $fired
            ? array_map(fn ($stream) => $owners[(int) $stream], $read)
            : [];
    }

    protected function waitOnTimersIfNeeded(float $for, float &$until): void
    {
        // Soonest note is in the future — sleep until then. This is the ONE sleep.
        if ($for > $until)
        {
            usleep((int) (($for - $until) * 1_000_000));
            $until = microtime(true);
        }
    }
}