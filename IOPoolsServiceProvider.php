<?php

namespace Voyager\IOPools;

use ReflectionException;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Contracts\IOPools\WorkerPool;
use Voyager\NutsAndBolts\ServiceProvider;

class IOPoolsServiceProvider extends ServiceProvider
{
    /**
     * @throws ReflectionException
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/config/io-pools.php', 'io-pools');

        $this->app->bind('mail-handler-mgr', fn(FrameworkCore $app) => new MailHandlerManager($app));
        $this->app->bind('promise-engine-mgr', fn(FrameworkCore $app) => new PromiseEngineManager($app));
        $this->app->registerSingleton('worker-pool-mgr', fn(FrameworkCore $app) => new WorkerPoolManager($app));
        $this->app->registerSingleton('work-targets', fn(FrameworkCore $app) => new WorkTargetManager($app));
        $this->app->registerSingleton('loop-notebook', fn() => new ResourceNotebook());
    }

    /**
     * @throws ReflectionException
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/config/io-pools.php' => $this->app->configPath('io-pools.php'),
        ], 'voyager-io-pools-config');

        // bound by its alias key: the core aliases route EventLoop::class and Loop::class here
        $this->app->registerSingleton('event-loop', function(FrameworkCore $app) {
            $notebook = $app->get('loop-notebook');
            $tick_budget_ms = config('io-pools.event_loop.tick_budget', 16);
            /** @var MailHandlerManager $mail_handler_mgr */
            $mail_handler_mgr = $app->get('mail-handler-mgr');
            $mail_handler = $mail_handler_mgr->driver();
            /** @var PromiseEngineManager $promise_engine_mgr */
            $promise_engine_mgr = $app->get('promise-engine-mgr');
            $promise_engine = $promise_engine_mgr->driver();
            return new EventLoop(
                $notebook,
                $tick_budget_ms,
                $mail_handler,
                $promise_engine,
            );
        });

        $this->app->registerSingleton(WorkerPool::class, function(FrameworkCore $app) {
            /** @var WorkerPoolManager $worker_pool_mgr */
            $worker_pool_mgr = $app->get('worker-pool-mgr');

            return $worker_pool_mgr->driver();
        });
    }
}