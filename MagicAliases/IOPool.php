<?php

namespace Voyager\IOPools\MagicAliases;

use Voyager\Contracts\IOPools\QueuedIO;
use Voyager\MagicAliases\MagicAlias;

/**
 * @method static void push(QueuedIO $event)
 */
class IOPool extends MagicAlias
{
    protected static function getMagicAliasAccessor(): string
    {
        return 'io-pool';
    }
}