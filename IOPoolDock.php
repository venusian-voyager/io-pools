<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\AsyncResourceDriver;
use Voyager\Contracts\IOPools\HttpResourceDriver;
use Voyager\Contracts\IOPools\IOResourceDriver;
use Voyager\Contracts\IOPools\PoolService;
use Voyager\Contracts\Vessel\Vessel;
use Voyager\Contracts\IOPools\QueuedIO;
use Voyager\IOPools\ResourceManagers\AsyncResourceManager;
use Voyager\IOPools\ResourceManagers\HttpResourceManager;
use Voyager\NutsAndBolts\Collection;

class IOPoolDock implements PoolService
{
    protected IOEventBag $bag;
    protected Collection $resources;

    public function __construct(
        protected Vessel $vessel
    )
    {
        $this->bag = new IOEventBag();
        $this->resources = new Collection();
    }

    public function push(QueuedIO $event): void
    {
        $this->bag->push($event);
    }

    public function pump(): void
    {
        $this->resources->each(fn(IOResourceDriver $resource) => $resource->tick());
    }

    public function drain(): IOEventBag
    {
        $payload = $this->bag;
        $this->bag = new IOEventBag();
        return $payload;
    }

    public function http(): ?HttpResourceDriver
    {
        return $this->resources->has('http') ? $this->resources->get('http') : null;
    }

    public function async(): ?AsyncResourceDriver
    {
        return $this->resources->has('async') ? $this->resources->get('async') : null;
    }

    public function resource(string $name, IOResourceDriver $resource): static
    {
        $this->resources->put($name, $resource);
        return $this;
    }

    public function resources(): Collection
    {
        return $this->resources;
    }

    /**
     * Any registered resource answers as a method of its name —
     * $dock->surface() returns the 'surface' resource, or null when
     * nothing registered under that name.
     */
    public function __call(string $method, array $arguments): ?IOResourceDriver
    {
        return $this->resources->get($method);
    }

    public function bootResources(array $config): void
    {
        $resources = $config['resources'] ?? [];

        if($resources['http']['enabled'] ?? false) {
            $driver = app(HttpResourceManager::class)->driver();
            $this->resources->put('http', $driver);
        }

        if($resources['async']['enabled'] ?? false) {
            $driver = app(AsyncResourceManager::class)->driver();
            $this->resources->put('async', $driver);
        }
    }


}