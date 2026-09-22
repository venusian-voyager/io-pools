<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\QueuedResourceMail;
use Voyager\NutsAndBolts\Collection;

class MailBag implements QueuedResourceMail
{
    /** @var array<Event>  */
    private array $queued_mail = [];

    public function drain(): void
    {
        $this->queued_mail = [];
    }

    public function collect(): Collection
    {
        return new Collection($this->queued_mail);
    }

    public function queue(array $mail): void
    {
        foreach ($mail as $event) {
            if($event instanceof Event) {
                $this->queued_mail[] = $event;
            }
        }
    }

    public function flush(bool $as_array = false): Collection|array
    {
        $results = $this->collect();
        $this->drain();

        if ($as_array) {
            $results = $results->all();
        }

        return $results;
    }
}