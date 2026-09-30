<?php

namespace Voyager\IOPools\WorkerPools\Thread;

use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\WorkerPools\IOPool;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\WorkerPools\PoolWorker;

/** Runs gigs in ext-parallel threads. ZTS builds only; it runs beside a ProcessWorkerPool, not instead of one. */
class ThreadWorkerPool extends IOPool
{
    /**
     * @param string $base_path the app every thread boots, so gigs can reach its services
     * @param string $autoload the Composer autoloader each thread loads first
     */
    public function __construct(
        Loop $loop,
        int $max_workers,
        private readonly string $base_path,
        private readonly string $autoload,
    ) {
        if (! PHP_ZTS || ! extension_loaded('parallel')) {
            throw new IOPoolsException('The thread pool needs a ZTS build of PHP with ext-parallel loaded.');
        }

        parent::__construct($loop, $max_workers, 'thread');
    }

    protected function spawn(string $name): PoolWorker
    {
        return new ThreadWorker($name, $this, $this->loop, $this->autoload, $this->base_path);
    }
}
