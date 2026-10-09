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
        return match ($this->autoDriver()) {
            'epoll' => $this->createEpollDriver(),
            'kqueue' => $this->createKqueueDriver(),
            default => $this->createSelectDriver(),
        };
    }

    /** The backend `auto` picks: epoll, then kqueue, then select. */
    public function autoDriver(): string
    {
        return match (true) {
            extension_loaded('epoll') => 'epoll',
            extension_loaded('kqueue') => 'kqueue',
            default => 'select',
        };
    }

    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.pool_waiters.default', 'auto');
    }
}