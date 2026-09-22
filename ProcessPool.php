<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\PoolWorker;
use Voyager\Contracts\IOPools\ProcessWorker;
use Voyager\Contracts\IOPools\Loop as LoopInterface;

class ProcessPool extends Pool
{
    public function __construct(
        LoopInterface $loop,
        protected array $command,
        int $size = 4,
        ?int $max_jobs = null,
    )
    {
        parent::__construct($loop, $size, $max_jobs);
    }

    /**
     * @return int[]
     */
    public function pids(): array
    {
        return array_map(fn (ProcessWorker $w) => $w->pid(), array_values($this->workers));
    }

    protected function spawn(string $name): PoolWorker
    {
        return new ProcessPoolWorker($name, $this->loop, $this, $this->command);
    }
}
