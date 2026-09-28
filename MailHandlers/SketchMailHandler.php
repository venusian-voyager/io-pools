<?php

namespace Voyager\IOPools\MailHandlers;

use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\Sketches\Sketch;
use Voyager\Contracts\IOPools\MailHandler;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\Sketches\SketchLoopResult;

/**
 * Passes all of a turn's mail to the attached sketch's loop(). A sketch that answers
 * STOP stops the loop.
 */
class SketchMailHandler implements MailHandler
{
    protected ?Sketch $sketch = null;

    public function attach(Sketch $sketch): void
    {
        $this->sketch = $sketch;
    }

    public function detach(): void
    {
        $this->sketch = null;
    }

    public function sketch(): ?Sketch
    {
        return $this->sketch;
    }

    public function handOff(array $mail, Loop $loop): void
    {
        if (! $this->sketch) {
            throw new IOPoolsException('The sketch mail handler has mail to deliver but no sketch attached.');
        }

        if ($this->sketch->loop($mail) === SketchLoopResult::STOP) {
            $loop->stop();
        }
    }
}
