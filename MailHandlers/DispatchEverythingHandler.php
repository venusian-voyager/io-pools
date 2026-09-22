<?php

namespace Voyager\IOPools\MailHandlers;

use ReflectionException;
use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\MailCollection;
use Voyager\Contracts\IOPools\Receivable;

class DispatchEverythingHandler implements Receivable
{
    /**
     * @throws ReflectionException
     */
    public function handOff(MailCollection $mail): void
    {
        foreach($mail->mail() as $event)
        {
            /** @var Event $event */
            signal($event);
        }
    }
}