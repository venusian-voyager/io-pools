<?php

namespace Voyager\IOPools\DTO;

use Voyager\Contracts\IOPools\Completion;

class HttpResult implements Completion
{
    public function __construct(
        public readonly string $name,
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error = null,
    ) {}

    public function ok(): bool
    {
        return $this->ok;
    }
}