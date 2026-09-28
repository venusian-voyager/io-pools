<?php

namespace Voyager\IOPools\WorkerPools\Thread;

use Throwable;
use RuntimeException;
use Voyager\Core\VenusianVoyager;
use Voyager\Contracts\Console\Kernel;
use Voyager\IOPools\WorkerPools\GigEnvelope;

/**
 * Runs inside a pool thread. Each thread is its own interpreter, so these statics are per
 * thread: one bell, one boot. Only strings cross the thread boundary, in both directions.
 */
final class ThreadRuntime
{
    /**
     * @var resource|null the thread's one line back to its worker
     */
    private static $bell = null;

    /**
     * First task on every thread: ring the bell's socket, boot the app, then say how that went.
     * The bell connects before the boot, so a boot that throws is still reported.
     */
    public static function hello(string $bell_path, string $base_path): void
    {
        self::$bell = stream_socket_client('unix://'.$bell_path, $errno, $errstr, 2.0)
            ?: throw new RuntimeException("Could not reach the worker's bell at {$bell_path}: {$errstr}");

        // A thread has no STDOUT: whatever a gig prints goes to the process's stderr.
        $stderr = fopen('php://stderr', 'w');
        ob_start(function (string $chunk) use ($stderr): string {
            fwrite($stderr, $chunk);

            return '';
        }, 1);

        try {
            $app = is_file($base_path.'/bootstrap/app.php')
                ? require $base_path.'/bootstrap/app.php'
                : VenusianVoyager::setup($base_path)->create();

            $app->make(Kernel::class)->bootstrap();
        } catch (Throwable $e) {
            fwrite(self::$bell, 'failed: '.str_replace("\n", ' ', $e::class.': '.$e->getMessage())."\n");

            return;
        }

        fwrite(self::$bell, "ready\n");
    }

    /**
     * Runs one serialized gig and rings once its envelope is ready to collect.
     */
    public static function work(string $gig): string
    {
        try {
            return serialize(GigEnvelope::run(unserialize($gig)));
        } finally {
            @fwrite(self::$bell, '!');
        }
    }
}
