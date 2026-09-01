<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\Tickable;

/**
 * The list a loop owner pumps. One roster per loop so nothing is pumped
 * twice; registration order is pump order.
 */
class TickRoster implements Tickable
{
    /** @var list<Tickable> */
    protected array $tickables = [];

    public function register(Tickable $tickable): static
    {
        $this->tickables[] = $tickable;

        return $this;
    }

    public function tick(): void
    {
        foreach ($this->tickables as $tickable) {
            $tickable->tick();
        }
    }
}
