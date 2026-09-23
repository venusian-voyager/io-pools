<?php

namespace Voyager\IOPools;

use Voyager\NutsAndBolts\Manager;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\WorkerPool;
use Voyager\Contracts\IOPools\WorkTarget;
use Voyager\Concurrency\ConcurrencyManager;
use Voyager\Contracts\Concurrency\Driver as ConcurrencyDriver;
use Voyager\Contracts\Queue\Factory as QueueFactory;
use Voyager\IOPools\WorkTargets\PoolTarget;
use Voyager\IOPools\WorkTargets\SyncTarget;
use Voyager\IOPools\WorkTargets\DeferTarget;
use Voyager\IOPools\WorkTargets\QueueTarget;
use Voyager\IOPools\WorkTargets\ConcurrencyTarget;

class WorkTargetManager extends Manager
{
    public function createSyncDriver(): WorkTarget
    {
        return new SyncTarget($this->vessel->make(Loop::class));
    }

    public function createDeferDriver(): WorkTarget
    {
        return new DeferTarget($this->vessel->make(Loop::class));
    }

    public function createPoolDriver(): WorkTarget
    {
        return new PoolTarget($this->vessel->make(WorkerPool::class));
    }

    public function createConcurrencyDriver(): WorkTarget
    {
        $driver = $this->vessel->isBound(ConcurrencyDriver::class)
            ? $this->vessel->make(ConcurrencyDriver::class)
            : $this->vessel->make(ConcurrencyManager::class)->driver();

        return new ConcurrencyTarget($this->vessel->make(Loop::class), $driver);
    }

    public function createQueueDriver(): WorkTarget
    {
        return new QueueTarget(
            $this->vessel->make(Loop::class),
            $this->vessel->make(QueueFactory::class),
            $this->config->get('io-pools.work.queue_connection'),
        );
    }

    public function getDefaultDriver(): string
    {
        return $this->config->get('io-pools.work.default', 'pool');
    }
}
