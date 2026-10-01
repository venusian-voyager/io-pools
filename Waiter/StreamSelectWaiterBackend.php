<?php

namespace Voyager\IOPools\Waiter;

use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;

class StreamSelectWaiterBackend extends WaiterBackendDriver
{
    /**
     * @var array<int, array<string, Readable>> stream id => owner => wake
     */
    private array $reads = [];

    /**
     * @var array<int, array<string, Writable>>
     */
    private array $writes = [];

    /** stream_select keeps its set in PHP arrays: there is no descriptor standing for it. */
    public function descriptor(): ?int
    {
        return null;
    }

    public function supports(WakeReason $kind): bool
    {
        return $kind === WakeReason::READABLE || $kind === WakeReason::WRITEABLE;
    }

    public function add(string $owner, Wake $wake): void
    {
        match (true) {
            $wake instanceof Readable => $this->reads[(int) $wake->stream][$owner] = $wake,
            $wake instanceof Writable => $this->writes[(int) $wake->stream][$owner] = $wake,
            default => throw new IOPoolsException("stream_select can't wait on a {$wake->kind()->value} wake."),
        };
    }

    public function remove(string $owner, Wake $wake): void
    {
        $table = $wake instanceof Writable ? 'writes' : 'reads';
        $id = (int) $wake->stream;

        unset($this->{$table}[$id][$owner]);

        if (empty($this->{$table}[$id])) {
            unset($this->{$table}[$id]);
        }
    }

    public function wait(?int $timeout_ns): array
    {
        $read  = $this->streams($this->reads);
        $write = $this->streams($this->writes);

        // Nothing to listen for: the wait is a plain sleep. The Waiter never passes null here.
        if (empty($read) && empty($write)) {
            if (! is_null($timeout_ns) && $timeout_ns > 0) {
                time_nanosleep(intdiv($timeout_ns, 1_000_000_000), $timeout_ns % 1_000_000_000);
            }

            return [];
        }

        $r = $read ?: null;
        $w = $write ?: null;
        $except = null;
        $seconds = is_null($timeout_ns) ? null : intdiv($timeout_ns, 1_000_000_000);
        $micro   = is_null($timeout_ns) ? null : intdiv($timeout_ns % 1_000_000_000, 1_000);

        // 0: the clock won. false: a signal cut the sleep. Either way nothing fired.
        if (! @stream_select($r, $w, $except, $seconds, $micro)) {
            return [];
        }

        $fired = [];

        foreach ($r ?? [] as $stream) {
            foreach ($this->reads[(int) $stream] as $owner => $wake) {
                $fired[$owner][] = $wake;
            }
        }

        foreach ($w ?? [] as $stream) {
            foreach ($this->writes[(int) $stream] as $owner => $wake) {
                $fired[$owner][] = $wake;
            }
        }

        return $fired;
    }

    /**
     * @return array<int, resource> the open streams, closed ones skipped
     */
    private function streams(array $table): array
    {
        $streams = [];

        foreach ($table as $id => $wakes) {
            $stream = reset($wakes)->stream;

            if (is_resource($stream)) {
                $streams[$id] = $stream;
            }
        }

        return $streams;
    }
}