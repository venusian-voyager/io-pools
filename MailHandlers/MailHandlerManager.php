<?php

namespace Voyager\IOPools\MailHandlers;

use ReflectionException;
use Voyager\NutsAndBolts\Manager;

class MailHandlerManager extends Manager
{
    protected function createSignalDriver(): SignalMailHandler
    {
        return new SignalMailHandler(fn () => $this->vessel->get('signals'));
    }

    protected function createSketchDriver(): SketchMailHandler
    {
        return new SketchMailHandler();
    }

    /**
     * @throws ReflectionException
     */
    public function getDefaultDriver(): string
    {
        return config('io-pools.event_loop.mail_handlers.default', 'signal');
    }
}
