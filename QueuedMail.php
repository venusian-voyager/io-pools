<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\Event;
use Voyager\Contracts\IOPools\MailCollection;
use Voyager\NutsAndBolts\Collection;

class QueuedMail implements MailCollection
{
    public function __construct(
        public readonly Collection $mail
    ) {}

    public function mail(): Collection
    {
        return $this->mail;
    }

    public static function make(array $mail): static
    {
        $results = new Collection();

        foreach($mail as $id => $envelope)
        {
            if($envelope instanceof Event)
            {
                $results->put($id, $envelope);
            }
        }

        return new static($results);
    }
}