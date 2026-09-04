<?php

namespace Voyager\IOPools\Drivers;

use Voyager\Contracts\IOPools\AsyncResourceDriver as ResourceDriverContract;
use Voyager\Contracts\IOPools\QueuedIO;
use Voyager\Contracts\IOPools\Sendable;

/**
 * What every out-of-process resource owes, whatever carries the mail:
 * post() puts Sendable mail on the wire, tick() sweeps only what is
 * already buffered into the dock, and reconstitution never eats mail —
 * what cannot be rebuilt arrives raw.
 */
abstract class AsyncResourceDriver implements ResourceDriverContract
{
    /**
     * Retarget which key the sweep reads.
     */
    abstract public function key(string $key): static;

    /**
     * Producer side: put one piece of traveling mail on the wire — JSON
     * envelope of class plus toSendable() data, never native serialize().
     */
    abstract public function post(Sendable $message, ?string $key = null): void;
}
