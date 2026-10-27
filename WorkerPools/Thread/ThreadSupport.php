<?php

namespace Voyager\IOPools\WorkerPools\Thread;

/**
 * Whether this PHP can run a parallel\Runtime, found by running one in a child: a build that
 * loads ext-parallel can still crash on its first thread (another extension in the same build has
 * done exactly that), and only a child can find out without going down with it.
 */
final class ThreadSupport
{
    private static ?self $probed = null;

    /** A verdict a test stands in, taken over the probed one until flush(). */
    private static ?self $pretended = null;

    private function __construct(
        public readonly bool $supported,
        public readonly string $reason,
    ) {}

    /** The verdict for this PHP, probed once per process. */
    public static function probe(int $timeout_s = 10): self
    {
        return self::$pretended ?? (self::$probed ??= self::run($timeout_s));
    }

    /**
     * Take $supported as the verdict instead of the probed one, until flush(): how a test stands
     * in a broken or a working build.
     *
     * @internal For tests only: an app never decides whether its threads run.
     */
    public static function pretend(bool $supported, string $reason): void
    {
        self::$pretended = new self($supported, $reason);
    }

    /**
     * Drop a pretended verdict. The probed one, once there is one, stands for the process.
     *
     * @internal For tests only.
     */
    public static function flush(): void
    {
        self::$pretended = null;
    }

    private static function run(int $timeout_s): self
    {
        if (! PHP_ZTS) {
            return new self(false, 'this PHP is not a ZTS build');
        }

        if (! extension_loaded('parallel')) {
            return new self(false, 'ext-parallel is not loaded');
        }

        $command = [PHP_BINARY];

        if (($ini = php_ini_loaded_file()) !== false) {
            $command = [...$command, '-c', $ini];
        }

        $command = [...$command, '-r', 'exit((new parallel\Runtime)->run(fn () => 6 * 7)->value() === 42 ? 0 : 3);'];

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            return new self(false, 'the probe could not start a child PHP');
        }

        fclose($pipes[0]);
        $deadline = microtime(true) + $timeout_s;

        while (($status = proc_get_status($process))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);

                return new self(false, "a parallel\\Runtime in a child did not answer within {$timeout_s}s");
            }

            usleep(10_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $exit = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];

        return $exit === 0
            ? new self(true, 'a parallel\Runtime ran in a child')
            : new self(false, "a parallel\\Runtime in a child exited {$exit}");
    }
}
