<?php

namespace Voyager\IOPools\WorkerPools\Process;

use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\WorkerPools\IOPool;
use Voyager\Contracts\IOPools\WorkerPools\PoolWorker;

/**
 * Runs gigs in PHP child processes. Works on every build, NTS and ZTS.
 */
class ProcessPool extends IOPool
{
    /**
     * @param string $base_path the app every worker boots, so gigs can reach its services
     * @param string $autoload the Composer autoloader the workers load first
     * @param string $php the PHP binary the workers run on
     */
    public function __construct(
        Loop $loop,
        int $max_workers,
        private readonly string $base_path,
        private readonly string $autoload,
        private readonly string $php = PHP_BINARY,
    ) {
        parent::__construct($loop, $max_workers, 'process');
    }

    protected function spawn(string $name): PoolWorker
    {
        return new ProcessWorker($name, $this, $this->loop, [
            $this->php, dirname(__DIR__, 2).'/bin/pool-worker', $this->autoload, $this->base_path,
        ]);
    }
}