<?php

namespace Voyager\IOPools;

use ReflectionException;

class IOPools
{
    /**
     * @throws ReflectionException
     */
    public static function eventLoop(): ?EventLoop
    {
        if(function_exists('app'))
        {
            return app('event-loop');
        }

        return null;
    }
}
