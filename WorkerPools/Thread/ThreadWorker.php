<?php

namespace Voyager\IOPools\WorkerPools\Thread;

use Throwable;
use parallel\Future;
use parallel\Runtime;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\WorkerPools\IOPool;
use Voyager\IOPools\WorkerPools\GigEnvelope;
use Voyager\Contracts\IOPools\WorkerPools\PoolWorker;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkerPools\DeadWorkerException;

/**
 * One ext-parallel runtime. A Future has no descriptor, so the thread rings a unix socket, the
 * bell, when a gig is done. The worker listens for the bell's connection, then wakes on the bell:
 * "ready\n" or "failed: …\n" once booted, "!" per finished gig, end-of-file if the thread dies.
 */
final class ThreadWorker extends WakeSource implements PoolWorker
{
    private Runtime $runtime;

    private string $bell_path;

    /** @var resource|null accepts the thread's one connection, then closes */
    private $listener;

    /** @var resource|null */
    private $bell = null;

    private string $inbox = '';

    private bool $greeted = false;

    private bool $gone = false;

    private ?Future $future = null;

    /** @var array{0: ShouldPool, 1: Promise}|null the gig it holds, started once the thread is ready */
    private ?array $current = null;

    public function __construct(
        private readonly string $name,
        private readonly IOPool $pool,
        private readonly Loop $loop,
        string $autoload,
        string $base_path,
    ) {
        $this->bell_path = sys_get_temp_dir().'/io-pools-bell-'.getmypid().'-'.bin2hex(random_bytes(6)).'.sock';

        $listener = stream_socket_server('unix://'.$this->bell_path, $errno, $errstr);

        if ($listener === false) {
            throw new DeadWorkerException("Worker {$name} could not open its bell at {$this->bell_path}: {$errstr}");
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;

        $this->runtime = new Runtime($autoload);
        $this->runtime->run(static function (string $bell_path, string $base_path): void {
            ThreadRuntime::hello($bell_path, $base_path);
        }, [$this->bell_path, $base_path]);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function busy(): bool
    {
        return ! is_null($this->current);
    }

    public function alive(): bool
    {
        return ! $this->gone;
    }

    public function assign(ShouldPool $gig, Promise $promise): void
    {
        $this->current = [$gig, $promise];

        if ($this->greeted) {
            $this->send();
        }
    }

    public function stop(): void
    {
        if ($this->gone) {
            return;
        }

        [$current, $this->current] = [$this->current, null];
        $this->close();
        $current && $current[1]->reject(new IOPoolsException("The pool shut down before worker {$this->name} finished its gig."));
    }

    public function wakes(): array
    {
        return match (true) {
            $this->gone => [],
            is_null($this->bell) => [new Readable($this->listener)],
            default => [new Readable($this->bell)],
        };
    }

    public function woke(array $fired): void
    {
        if ($this->gone) {
            return;
        }

        if (is_null($this->bell)) {
            $this->answer();
            return;
        }

        $chunk = fread($this->bell, 1024);
        $this->inbox .= $chunk === false ? '' : $chunk;

        if (! $this->greeted && ! $this->greet()) {
            return;
        }

        // One ring per finished gig.
        while ($this->greeted && ! is_null($this->current) && ($ring = strpos($this->inbox, '!')) !== false) {
            $this->inbox = substr($this->inbox, $ring + 1);
            $this->collect();

            if ($this->gone) {
                return;
            }
        }

        if (feof($this->bell)) {
            $this->die($this->current ? 'ended before its gig finished.' : 'ended.');
        }
    }

    /** Takes the thread's connection. What it says arrives on the bell's next wake. */
    private function answer(): void
    {
        $bell = @stream_socket_accept($this->listener, 0);

        if ($bell === false) {
            return;
        }

        stream_set_blocking($bell, false);
        $this->bell = $bell;

        fclose($this->listener);
        $this->removeBellPath();
    }

    /** False until the boot line is whole; true once the thread is ready. */
    private function greet(): bool
    {
        $newline = strpos($this->inbox, "\n");

        if ($newline === false) {
            if (feof($this->bell)) {
                $this->die('ended before it finished booting.');
            }

            return false;
        }

        $line = substr($this->inbox, 0, $newline);
        $this->inbox = substr($this->inbox, $newline + 1);

        if ($line !== 'ready') {
            $this->die('could not boot: '.substr($line, strlen('failed: ')));
            return false;
        }

        $this->greeted = true;

        if ($this->current) {
            $this->send();
        }

        return true;
    }

    private function send(): void
    {
        [$gig, $promise] = $this->current;

        try {
            $payload = serialize($gig);
        } catch (Throwable $e) {
            // Nothing reached the thread: this worker is still free for the next gig.
            $this->current = null;
            $promise->reject(new IOPoolsException("This gig can't be sent to a worker: {$e->getMessage()}", 0, $e));
            $this->pool->finished($this);
            return;
        }

        $this->future = $this->runtime->run(static function (string $gig): string {
            return ThreadRuntime::work($gig);
        }, [$payload]);
    }

    private function collect(): void
    {
        [, $promise] = $this->current;
        $this->current = null;

        try {
            // The ring comes from the gig's finally, a moment before its return lands: value() waits that moment.
            $envelope = unserialize($this->future->value());
        } catch (Throwable $e) {
            $promise->reject(new DeadWorkerException("Worker {$this->name} lost its gig: {$e->getMessage()}", 0, $e));
            $this->die('lost its gig.');
            return;
        } finally {
            $this->future = null;
        }

        GigEnvelope::settle($promise, $envelope);
        $this->pool->finished($this);
    }

    private function die(string $why): void
    {
        [$current, $this->current] = [$this->current, null];
        $this->close();

        $current && $current[1]->reject(new DeadWorkerException("Worker {$this->name} {$why}"));
        $this->pool->died($this);
    }

    private function close(): void
    {
        $this->gone = true;

        foreach ([$this->listener, $this->bell] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }

        $this->removeBellPath();

        try {
            $this->runtime->kill();
        } catch (Throwable) {
            // already closed or killed
        }
    }

    /** The socket file is only needed until the thread connects. */
    private function removeBellPath(): void
    {
        if (file_exists($this->bell_path)) {
            unlink($this->bell_path);
        }
    }
}
