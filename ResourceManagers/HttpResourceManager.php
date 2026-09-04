<?php

namespace Voyager\IOPools\ResourceManagers;

use Voyager\Contracts\IOPools\HttpResourceDriver;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Drivers\MultiCurlResourceDriver;

class HttpResourceManager extends ResourceManager
{
    public function createMultiCurlDriver(): HttpResourceDriver
    {
        $enabled = config('io-pools.resources.http.enabled', false);
        if(!$enabled)
        {
            throw new IOPoolsException("MultiCurl resource driver not enabled");
        }
        $config = config('io-pools.resources.http.drivers.multi-curl', []);
        $io_pool = app('io-pool');
        return new MultiCurlResourceDriver($config, $io_pool);
    }

    public function getDefaultDriver(): string
    {
        return config('io-pools.resources.http.default', 'multi-curl');
    }
}