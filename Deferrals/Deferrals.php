<?php

namespace Voyager\IOPools\Deferrals;

use Closure;
use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\IOPools\Resources\FollowOnResource;

class Deferrals extends FollowOnResource
{
    public const string NAME = 'io-pools.deferrals';

    /**
     * @var list<array{Closure, Promise}> deferred since the turn started
     */
    private array $queue = [];

    /**
     * @var list<array{Closure, Promise}> this turn's batch
     */
    private array $ready = [];

    public function __construct(
        private readonly Loop $loop
    ) {}

    public function add(Closure $work): Promise
    {
        $promise = $this->loop->promise();
        $this->queue[] = [$work, $promise];

        return $promise;
    }

    /** Turn start: what is queued now is this turn's batch. */
    public function release(): void
    {
        [$this->ready, $this->queue] = [[...$this->ready, ...$this->queue], []];
    }

    public function pending(): bool
    {
        return ! empty($this->queue) || ! empty($this->ready);
    }

    public function resume(): bool
    {
        if (empty($this->ready))
        {
            return false;
        }

        [$batch, $this->ready] = [$this->ready, []];

        foreach ($batch as [$work, $promise])
        {
            try
            {
                $promise->resolve($work());
            }
            catch (Throwable $e)
            {
                $promise->reject($e);
            }
        }

        return true;
    }
}