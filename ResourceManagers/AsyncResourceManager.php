<?php

namespace Voyager\IOPools\ResourceManagers;

use Voyager\Contracts\IOPools\AsyncResourceDriver;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Drivers\RedisResourceDriver;

class AsyncResourceManager extends ResourceManager
{
    public function createRedisDriver(): AsyncResourceDriver
    {
        $enabled = config('io-pools.resources.async.enabled', false);
        if(!$enabled)
        {
            throw new IOPoolsException("Redis Async resource driver not enabled");
        }

        $config = config('io-pools.resources.async.drivers.redis', []);
        $connection = app('redis')->connection($config['connection'] ?? 'default');
        $io_pool = app('io-pool');
        return new RedisResourceDriver($config, $connection, $io_pool);
    }

    public function getDefaultDriver()
    {
        return config('io-pools.resources.async.default', 'redis');
    }
}