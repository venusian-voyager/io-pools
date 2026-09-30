<?php

namespace Voyager\IOPools\Waiter;

use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Waiter\Wakes\ControlSignal;
use Voyager\Contracts\IOPools\WaiterBackendDriver;

class SignalRelay
{
    public const OWNER = 'io-pools.signal-relay';

    /**
     * @var array{0: resource, 1: resource} read end, write end
     */
    private array $pair;

    /**
     * @var array<int, array<string, ControlSignal>> signo => owner => wake
     */
    private array $watchers = [];

    public function __construct(WaiterBackendDriver $backend)
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            throw new IOPoolsException('The signal relay could not open its socket pair.');
        }

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);
        $this->pair = $pair;

        // Handlers run the moment a signal lands, so the byte is written before the next wait blocks.
        pcntl_async_signals(true);
        $backend->add(self::OWNER, new Readable($pair[0]));
    }

    public function watch(string $owner, ControlSignal $wake): void
    {
        if (empty($this->watchers[$wake->signo])) {
            pcntl_signal($wake->signo, fn (int $signo) => @fwrite($this->pair[1], chr($signo)));
        }

        $this->watchers[$wake->signo][$owner] = $wake;
    }

    public function unwatch(string $owner, ControlSignal $wake): void
    {
        unset($this->watchers[$wake->signo][$owner]);

        if (empty($this->watchers[$wake->signo])) {
            unset($this->watchers[$wake->signo]);
            pcntl_signal($wake->signo, SIG_DFL);
        }
    }

    /**
     * Swap the relay's own readable for the ControlSignal wakes of whoever watches those signals.
     * @param array $fired
     * @return array
     */
    public function translate(array $fired): array
    {
        if (! isset($fired[self::OWNER]))
        {
            return $fired;
        }

        unset($fired[self::OWNER]);

        foreach (array_unique(array_map(ord(...), str_split((string) fread($this->pair[0], 4096)))) as $signo)
        {
            foreach ($this->watchers[$signo] ?? [] as $owner => $wake)
            {
                $fired[$owner][] = $wake;
            }
        }

        return $fired;
    }
}