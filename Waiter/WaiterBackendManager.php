<?php

namespace Voyager\IOPools\Waiter;

use ReflectionException;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\NutsAndBolts\Manager;

class WaiterBackendManager extends Manager
{
    protected function createKqueueDriver(): KqueueWaiterBackend
    {
        if(extension_loaded('kqueue'))
        {
            return new KqueueWaiterBackend();
        }

        throw new IOPoolsException("ext-kqueue not installed.");
    }

    protected function createEpollDriver(): EpollWaiterBackend
    {
        if(extension_loaded('epoll'))
        {
            return new EpollWaiterBackend();
        }

        throw new IOPoolsException("ext-epoll not installed.");
    }

    protected function createSelectDriver(): StreamSelectWaiterBackend
    {
        return new StreamSelectWaiterBackend();
    }

    protected function createAutoDriver(): WaiterBackendDriver
    {
        if(extension_loaded('epoll'))
        {
            return $this->createEpollDriver();
        }
        elseif(extension_loaded('kqueue'))
        {
            return $this->createKqueueDriver();
        }

        return $this->createSelectDriver();
    }

    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.pool_waiters.default', 'auto');
    }
}