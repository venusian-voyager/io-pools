<?php

namespace Voyager\IOPools;

use RuntimeException;
use Voyager\Core\VenusianVoyager;
use Voyager\Contracts\Console\Kernel as ConsoleKernel;
use Voyager\Contracts\IOPools\ShouldPool;

/**
 * Runs INSIDE a pool thread. Each thread is its own interpreter, so these statics are per thread:
 * one bell connection, one boot.
 */
final class ThreadRuntime
{
    /** @var resource|null */
    private static $bell = null;

    /** @var resource|null */
    private static $stderr = null;

    /**
     * The thread's one connection back to the pool. Threads can't be handed a stream, only a path.
     * @return resource
     */
    public static function bell(string $socket_path)
    {
        return self::$bell ??= stream_socket_client('unix://'.$socket_path, $errno, $errstr, 2.0)
            ?: throw new RuntimeException("Could not reach the pool's bell at {$socket_path}: {$errstr}");
    }

    /**
     * First task on every runtime: boot the framework, then say who we are.
     */
    public static function hello(string $socket_path, string $name, string $base_path): bool
    {
        // STDOUT/STDERR don't exist in a thread. A gig's echo goes to the process's stderr, not the terminal's stdout.
        self::$stderr = fopen('php://stderr', 'w');

        ob_start(function (string $chunk): string {
            fwrite(self::$stderr, $chunk);

            return '';
        }, 1);

        // launch() only builds the container. A gig reaching for app('hash') needs what the console boots.
        VenusianVoyager::launch($base_path)->make(ConsoleKernel::class)->bootstrap();

        fwrite(self::bell($socket_path), $name."\n");

        return true;
    }

    /**
     * Run one gig. Only strings cross the thread boundary, both ways.
     */
    public static function work(string $socket_path, string $gig): string
    {
        try {
            $job = unserialize($gig);

            return serialize(
                $job instanceof ShouldPool
                    ? PoolEnvelope::run($job)
                    : PoolEnvelope::failed(new RuntimeException(
                        'The gig did not arrive as a ShouldPool. Is its class autoloadable inside the thread?'
                    ))
            );
        } finally {
            // ring on return AND on throw. exit() skips this; the pool's sweep covers that.
            @fwrite(self::bell($socket_path), '!');
        }
    }
}
