<?php

namespace Voyager\IOPools;

use Voyager\NutsAndBolts\Manager;
use Voyager\Contracts\IOPools\WorkerPool;

class WorkerPoolManager extends Manager
{
    public function createProcessDriver(): WorkerPool
    {
        $config = $this->settings();

        return new ProcessPool(
            loop: $this->vessel->make(EventLoop::class),
            command: [
                PHP_BINARY,
                ...($config['php_args'] ?? []),
                $config['worker_script'] ?? __DIR__.'/bin/pool-worker',
                $this->autoloadPath($config),
                $this->basePath($config),
            ],
            size: $config['size'] ?? 4,
            max_jobs: $config['max_jobs'] ?? null,
            hello_timeout_s: $config['hello_timeout_s'] ?? 5.0,
        );
    }

    /**
     * ThreadPool itself refuses to build without ext-parallel, or with Xdebug active.
     */
    public function createThreadDriver(): WorkerPool
    {
        $config = $this->settings();

        return new ThreadPool(
            loop: $this->vessel->make(EventLoop::class),
            autoload_path: $this->autoloadPath($config),
            base_path: $this->basePath($config),
            size: $config['size'] ?? 4,
            max_jobs: $config['max_jobs'] ?? null,
            sweep_seconds: $config['sweep_seconds'] ?? 0.5,
            hello_timeout_s: $config['hello_timeout_s'] ?? 5.0,
        );
    }

    public function getDefaultDriver(): string
    {
        return $this->settings()['driver'] ?? 'process';
    }

    private function settings(): array
    {
        return $this->config->get('io-pools.thread_pool', []);
    }

    private function basePath(array $config): string
    {
        return $config['base_path'] ?? $this->vessel->basePath();
    }

    private function autoloadPath(array $config): string
    {
        return $config['autoload_path'] ?? $this->vessel->basePath('vendor/autoload.php');
    }
}
