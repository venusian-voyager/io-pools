<?php

namespace Voyager\IOPools;

use Voyager\Contracts\Vessel\Vessel;
use Voyager\NutsAndBolts\ServiceProvider;


class
IOPoolsServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {

        app()->singleton('io-pool', function ($app) {
            $dock = new IOPoolDock($app);   // constructor no longer boots resources
            $app->instance('io-pool', $dock);        // store it FIRST
            return $dock;
        });
    }

    /**
     * Bootstrap the io pool.
     * @throws \ReflectionException
     */
    public function boot(): void
    {
        $config = config('io-pools', ['resources' => []]);
        $dock = $this->app->make('io-pool');
        $dock->bootResources($config);           // NOW managers can app('io-pool') safely
    }
}