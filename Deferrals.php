<?php

namespace Voyager\IOPools;

use Closure;
use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\Tickable;

/**
 * Work handed to the loop to run on its next turn. A Tickable only while it holds
 * something: registered on the first add, forgotten once drained, so an empty
 * queue can neither keep run() alive nor force the fallback pace.
 */
final class Deferrals implements Tickable
{
    public const NAME = 'deferrals';

    /** @var array<int, array{Closure, Promise}> */
    private array $queue = [];

    public function __construct(private readonly Loop $loop) {}

    public function add(Closure $work): Promise
    {
        $promise = $this->loop->promise();

        if (empty($this->queue)) {
            $this->loop->resource(self::NAME, $this);
        }

        $this->queue[] = [$work, $promise];

        return $promise;
    }

    public function idle(): bool
    {
        return empty($this->queue);
    }

    public function tick(): void
    {
        // take the batch first: work that defers more work lands on the NEXT turn
        [$batch, $this->queue] = [$this->queue, []];

        $this->loop->forget(self::NAME);

        foreach ($batch as [$work, $promise])
        {
            try { $promise->resolve($work()); } catch (Throwable $e) { $promise->reject($e); }
        }
    }
}
