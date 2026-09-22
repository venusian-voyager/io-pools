<?php

namespace Voyager\IOPools\PromiseEngines;

use GuzzleHttp\Promise\Utils;
use Voyager\Contracts\IOPools\PromiseEngine as PromiseContract;

abstract class PromiseEngine implements PromiseContract
{
    /**
     * Every promise library agrees on then(), which is what lets one engine
     * chain onto a promise that came from another.
     */
    public function chain(object $inner, ?callable $on_fulfilled, ?callable $on_rejected): object
    {
        return $inner->then($on_fulfilled, $on_rejected);
    }

    /**
     * Guzzle never calls a then() callback on the spot; it queues them, and somebody has to run
     * the queue. Done here for every engine, because a Guzzle promise can arrive from outside
     * (await) whatever the app's own engine is. Costs nothing unless Guzzle is already loaded.
     */
    public function flush(): void
    {
        if (class_exists(Utils::class, false)) {
            Utils::queue()->run();
        }
    }
}
