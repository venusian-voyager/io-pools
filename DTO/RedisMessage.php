<?php

namespace Voyager\IOPools\DTO;

use Voyager\Contracts\IOPools\Occurrence;

class RedisMessage implements Occurrence
{
    public function __construct(
        public readonly string $name,
        public readonly string $raw,
    ) {
    }
}