<?php

namespace Voyager\IOPools\MailHandlers;

use Closure;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\MailHandler;
use Voyager\Contracts\Signals\SignalDispatcher;

/**
 * Dispatches every piece of mail through Signals, one at a time, in the order it arrived.
 */
class SignalMailHandler implements MailHandler
{
    /**
     * @param Closure(): SignalDispatcher $signals resolved at hand-off, so provider order never matters
     */
    public function __construct(protected readonly Closure $signals) {}

    public function handOff(array $mail, Loop $loop): void
    {
        $signals = ($this->signals)();

        foreach ($mail as $signal) {
            $signals->dispatch($signal);
        }
    }
}
