<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\EventSink;
use Voyager\Contracts\IOPools\HttpDriver;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\Tickable;

/**
 * Non-blocking HTTP riding a loop tick. call() starts a named request;
 * tick() advances the driver and, for each finished call, pushes a 'task'
 * event named exactly what the author named the call and fires the
 * PendingCall's hook.
 */
class HttpPool implements Tickable
{
    /** @var array<string, PendingCall> */
    protected array $in_flight = [];

    public function __construct(
        protected HttpDriver $driver,
        protected EventSink $sink,
    ) {}

    /**
     * Start a call. One in-flight call per name — a silent supersede hides
     * bugs, so a duplicate is refused; the name frees when the call settles.
     * @param array<string, string> $headers
     * @throws IOPoolsException When the name is already in flight.
     */
    public function call(string $name, string $method, string $url, array $headers = [], ?string $body = null): PendingCall
    {
        if (isset($this->in_flight[$name])) {
            throw new IOPoolsException("Call '{$name}' is already in flight.");
        }

        $call = new PendingCall($name);
        $this->in_flight[$name] = $call;
        $this->driver->dispatch($name, strtoupper($method), $url, $headers, $body);

        return $call;
    }

    public function tick(): void
    {
        foreach ($this->driver->harvest() as $result) {
            $call = $this->in_flight[$result->name] ?? null;
            unset($this->in_flight[$result->name]);

            $this->sink->push(new Event(
                family: 'task',
                name: $result->name,
                payload: $result->toPayload(),
            ));

            $call?->settle($result);
        }
    }
}
