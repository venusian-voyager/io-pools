<?php

namespace Voyager\IOPools;

use ReflectionException;
use Voyager\NutsAndBolts\Manager;
use Voyager\Contracts\IOPools\Receivable;
use Voyager\IOPools\MailHandlers\DispatchEverythingHandler;

class MailHandlerManager extends Manager
{
    public function createSignalDriver(): Receivable
    {
        return new DispatchEverythingHandler();
    }
    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.event_loop.mail_handlers.default', 'signal');
    }
}