<?php

namespace Voyager\IOPools\WorkerPools;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use Composer\Autoload\ClassLoader;
use Voyager\NutsAndBolts\Manager;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\WorkerPools\Thread\ThreadWorkerPool;
use Voyager\IOPools\WorkerPools\Process\ProcessWorkerPool;

/**
 * Builds pools by name. There is no default: an NTS build runs only the process pool, a ZTS
 * build may run both side by side, so every caller names the pool it wants.
 */
class WorkerPoolManager extends Manager
{
    /**
     * @return ProcessWorkerPool
     * @throws ReflectionException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function createProcessDriver(): ProcessWorkerPool
    {
        $this->ensureEnabled('process');

        return new ProcessWorkerPool(
            $this->vessel->get('event-loop'),
            (int) config('io-pools.pool_workers.process.max_workers', 4),
            $this->vessel->get('path.base'),
            self::autoloader(),
        );
    }

    /**
     * @return ThreadWorkerPool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ReflectionException
     */
    public function createThreadDriver(): ThreadWorkerPool
    {
        $this->ensureEnabled('threads');

        return new ThreadWorkerPool(
            $this->vessel->get('event-loop'),
            (int) config('io-pools.pool_workers.threads.max_workers', 4),
            $this->vessel->get('path.base'),
            self::autoloader(),
        );
    }

    public function getDefaultDriver(): ?string
    {
        throw new IOPoolsException('There is no default worker pool: ask for "process" or "thread" by name.');
    }

    /** The Composer autoloader this process runs on, handed to every worker. */
    public static function autoloader(): string
    {
        return dirname(new ReflectionClass(ClassLoader::class)->getFileName(), 2).'/autoload.php';
    }

    /**
     * @throws ReflectionException
     */
    private function ensureEnabled(string $pool): void
    {
        if (! config("io-pools.pool_workers.{$pool}.enabled", false)) {
            throw new IOPoolsException("The {$pool} pool is disabled: io-pools.pool_workers.{$pool}.enabled is off.");
        }
    }
}