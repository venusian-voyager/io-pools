<?php

namespace Voyager\IOPools\WorkerPools;

use Voyager\Contracts\Vessel\TheServiceContainer;
use Voyager\IOPools\WorkerPools\Thread\ThreadSupport;

/**
 * Which pool runs work that names none: the thread workers when they are on and threads really
 * run here, the process workers otherwise. Whether threads run is probed the first time the
 * choice falls to them, never before, so code that doesn't offload pays nothing.
 */
final class AutoPool
{
    private static bool $warned = false;

    /** The binding to use, or null when neither pool is on. */
    public static function binding(TheServiceContainer $app): ?string
    {
        if ($app->isBound('thread-workers') && self::threadsRun($app)) {
            return 'thread-workers';
        }

        return $app->isBound('process-workers') ? 'process-workers' : null;
    }

    private static function threadsRun(TheServiceContainer $app): bool
    {
        $probe = ThreadSupport::probe();

        if (! $probe->supported && ! self::$warned) {
            self::$warned = true;

            if ($app->isBound('log')) {
                $app->get('log')->warning("The thread workers are on, but threads don't run in this PHP ({$probe->reason}): work that names no pool runs on the process workers.");
            }
        }

        return $probe->supported;
    }

    /** Warn again on the next fallback: for tests. */
    public static function flush(): void
    {
        self::$warned = false;
    }
}
