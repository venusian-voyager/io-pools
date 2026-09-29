<?php

namespace Voyager\IOPools\MailHandlers;

use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\MailHandler;

/**
 * Holds the loop's mail for the sketch runner. Each delivery is kept, oldest first, until the
 * runner takes it at the sketch's next frame; the runner calls the sketch's loop() every frame,
 * with what was taken or with nothing.
 */
class SketchMailHandler implements MailHandler
{
    /** @var list<object> delivered since the last take() */
    protected array $held = [];

    public function handOff(array $mail, Loop $loop): void
    {
        array_push($this->held, ...$mail);
    }

    /**
     * Everything delivered since the last take(), oldest first, and nothing held after.
     *
     * @return list<object>
     */
    public function take(): array
    {
        [$mail, $this->held] = [$this->held, []];

        return $mail;
    }
}
