<?php

namespace Voyager\IOPools;

use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\MailHandlers\MailHandlerManager;
use Voyager\IOPools\PromiseEngines\PromiseEngineManager;
use Voyager\IOPools\Waiter\WaiterBackendManager;
use Voyager\IOPools\WorkerPools\WorkerPoolManager;
use Voyager\NutsAndBolts\ServiceProvider;

class IOPoolsServiceProvider extends ServiceProvider
{
    /**
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->app->registerSingleton('loop-resource-registry', fn () => new ResourceRegistry());
        $this->app->bind('waiter-backend-mgr', fn (FrameworkCore $app) => new WaiterBackendManager($app));
        $this->app->bind('promise-engine-mgr', fn (FrameworkCore $app) => new PromiseEngineManager($app));
        $this->app->registerSingleton('mail-handler-mgr', fn (FrameworkCore $app) => new MailHandlerManager($app));
        $this->app->registerSingleton('worker-pool-mgr', fn (FrameworkCore $app) => new WorkerPoolManager($app));
    }

    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->app->registerSingleton('event-loop', function (FrameworkCore $app) {
            /** @var ResourceRegistry $registry */
            $registry = $app->get('loop-resource-registry');

            /** @var WaiterBackendManager $wait_mgr */
            $wait_mgr = $app->get('waiter-backend-mgr');
            $waiter = new LoopWaiter(
                $registry,
                $wait_mgr->driver(),
                (int) config('io-pools.event_loop.pace_ms', 16) * 1_000_000,
            );

            /** @var PromiseEngineManager $promise_mgr */
            $promise_mgr = $app->get('promise-engine-mgr');

            /** @var MailHandlerManager $mail_mgr */
            $mail_mgr = $app->get('mail-handler-mgr');

            return new EventLoop(
                $registry,
                $waiter,
                $promise_mgr->driver(),
                $mail_mgr->driver(),
            );
        });

        // Each enabled pool under its own name: callers ask for the pool they want.
        if (config('io-pools.pool_workers.process.enabled', false)) {
            $this->app->registerSingleton('process-pool', fn (FrameworkCore $app) => $app->get('worker-pool-mgr')->driver('process'));
        }

        if (config('io-pools.pool_workers.threads.enabled', false)) {
            if (! PHP_ZTS || ! extension_loaded('parallel')) {
                throw new IOPoolsException('io-pools.pool_workers.threads is enabled, but this PHP is not a ZTS build with ext-parallel loaded.');
            }

            $this->app->registerSingleton('thread-pool', fn (FrameworkCore $app) => $app->get('worker-pool-mgr')->driver('thread'));
        }
    }
}