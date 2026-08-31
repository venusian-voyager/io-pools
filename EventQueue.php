<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\EventSink;
use Voyager\NutsAndBolts\Collection;

/**
 * The mailbox between producers and the loop's one consumer.
 *
 * Producers push during a tick; the consumer drains after it. A drain hands
 * back a Collection keyed by event name — has('api-somewhere') /
 * get('api-somewhere') — and leaves the queue empty. Two pushes of one name
 * inside one tick collapse to the LAST.
 */
class EventQueue implements EventSink
{
    protected Collection $pending;

    public function __construct()
    {
        $this->pending = new Collection();
    }

    public function push(Event $event): void
    {
        $this->pending->put($event->name, $event);
    }

    /**
     * Everything observed since the last drain; the queue is empty after.
     */
    public function drain(): Collection
    {
        $drained = $this->pending;
        $this->pending = new Collection();

        return $drained;
    }
}
