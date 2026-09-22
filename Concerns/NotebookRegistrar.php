<?php

namespace Voyager\IOPools\Concerns;

use Voyager\Contracts\IOPools\Tickable;
use Voyager\Contracts\IOPools\Resumable;
use Voyager\Contracts\IOPools\LoopTimer;
use Voyager\Contracts\IOPools\StreamWatchable;

trait NotebookRegistrar
{
    public function oneShotTimer(float $delay_s, callable $fire): LoopTimer
    {
        return $this->notebook->setActionTimer($delay_s, $fire);
    }

    public function intervalTimer(float $interval_s, callable $fire, string $name): LoopTimer
    {
        return $this->notebook->setRecurringActionTimer($name, $interval_s, $fire);
    }

    public function streamingResource(string $name, StreamWatchable $resource): StreamWatchable
    {
        return $this->notebook->setStreamingResource($name, $resource);
    }

    public function tickableResource(string $name, Tickable $resource): Tickable
    {
        return $this->notebook->setTickableResource($name, $resource);
    }

    public function resumableResource(string $name, Resumable $resource): Resumable
    {
        return $this->notebook->setResumableResource($name, $resource);
    }
}