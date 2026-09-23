<?php

namespace Voyager\IOPools;

use ReflectionException;
use Voyager\NutsAndBolts\Manager;
use Voyager\Contracts\IOPools\PromiseEngine;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\IOPools\PromiseEngines\ReactPromiseEngine;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;

class PromiseEngineManager extends Manager
{
    public function createReactDriver(): PromiseEngine
    {
        $this->ensureInstalled(\React\Promise\Deferred::class, 'react', 'react/promise');

        return new ReactPromiseEngine();
    }

    public function createGuzzleDriver(): PromiseEngine
    {
        $this->ensureInstalled(\GuzzleHttp\Promise\Promise::class, 'guzzle', 'guzzlehttp/promises');

        return new GuzzlePromiseEngine();
    }

    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.promises.default_engine', 'guzzle');
    }

    protected function ensureInstalled(string $class, string $engine, string $package): void
    {
        if (! class_exists($class)) {
            throw new EventLoopException("The [{$engine}] promise engine needs [{$package}]. Run: composer require {$package}");
        }
    }
}
