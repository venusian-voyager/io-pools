<?php

namespace Voyager\IOPools\PromiseEngines;

use ReflectionException;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\NutsAndBolts\Manager;

class PromiseEngineManager extends Manager
{
    public function createReactDriver(): ReactPromiseEngine
    {
        if (class_exists(\React\Promise\Deferred::class)) {
            return new ReactPromiseEngine();
        }

        throw new IOPoolsException('The react promise engine needs react/promise ^3.0: composer require react/promise');

    }

    public function createGuzzleDriver(): GuzzlePromiseEngine
    {
        if (class_exists(\GuzzleHttp\Promise\Promise::class))
        {
            return new GuzzlePromiseEngine();
        }

        throw new IOPoolsException('The guzzle promise engine needs guzzlehttp/promises ^2.0: composer require guzzlehttp/promises');
    }

    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.promise_engines.default', 'guzzle');
    }
}