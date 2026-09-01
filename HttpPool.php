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

    /** @var array<string, int> Last byte count spoken per in-flight name. */
    protected array $last_progress = [];

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

    /**
     * The pending call still flying under a name, or null. The door a
     * client needs to coalesce identical requests instead of throwing.
     */
    public function inFlight(string $name): ?PendingCall
    {
        return $this->in_flight[$name] ?? null;
    }

    public function tick(): void
    {
        foreach ($this->driver->harvest() as $result) {
            $call = $this->in_flight[$result->name] ?? null;
            unset($this->in_flight[$result->name], $this->last_progress[$result->name]);

            $this->sink->push(new Event(
                family: 'task',
                name: $result->name,
                payload: $result->toPayload(),
            ));

            $call?->settle($result);
        }

        // Progress rides its own lane, 'progress.<name>', so has('<name>')
        // still means "finished". Spoken only when the byte count moved.
        foreach ($this->driver->progress() as $name => $moved) {
            if (($this->last_progress[$name] ?? -1) === $moved['now']) {
                continue;
            }
            $this->last_progress[$name] = $moved['now'];

            $this->sink->push(new Event(
                family: 'task.progress',
                name: "progress.{$name}",
                payload: ['name' => $name, 'bytes_now' => $moved['now'], 'bytes_total' => $moved['total']],
            ));

            ($this->in_flight[$name] ?? null)?->notifyProgress($moved['now'], $moved['total']);
        }
    }
}
