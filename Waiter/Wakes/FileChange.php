<?php

namespace Voyager\IOPools\Waiter\Wakes;

use Voyager\Contracts\IOPools\WakeReason;

readonly class FileChange extends WakingState
{
    public function __construct(public string $path)
    {
        parent::__construct(WakeReason::FILE_CHANGE);
    }

    public function key(): string
    {
        return 'file:'.$this->path;
    }
}