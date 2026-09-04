<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\QueuedIO;
use Voyager\NutsAndBolts\Collection;

class IOEventBag extends Collection
{
    public function dispatch(): void
    {
        $this->each(fn (QueuedIO $event) => event($event));
    }
}
