<?php

namespace Voyager\IOPools\WorkTargets;

use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkTarget;
use Voyager\Contracts\Queue\Factory as QueueFactory;

/**
 * A queue: the gig is pushed as a job and runs wherever that queue is worked (inline on the sync
 * connection, a queue:work process otherwise). Fire-and-forget: the promise resolves with the
 * queue's job id at push time. Nothing carries the gig's return back here.
 */
final class QueueTarget implements WorkTarget
{
    public function __construct(
        private readonly Loop $loop,
        private readonly QueueFactory $queues,
        private readonly ?string $connection = null,       // null = queue.default
    ) {}

    public function run(ShouldPool $gig): Promise
    {
        $promise = $this->loop->promise();

        // ShouldPool and ShouldQueue share Handleable: the gig IS the job object
        try { $promise->resolve($this->queues->connection($this->connection)->push($gig)); } catch (Throwable $e) { $promise->reject($e); }

        return $promise;
    }
}
