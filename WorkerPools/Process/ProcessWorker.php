<?php

namespace Voyager\IOPools\WorkerPools\Process;

use Throwable;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Resources\WakeSource;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\ProcessExit;
use Voyager\IOPools\WorkerPools\IOPool;
use Voyager\IOPools\WorkerPools\GigEnvelope;
use Voyager\Contracts\IOPools\WorkerPools\PoolWorker;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkerPools\DeadWorkerException;

/**
 * A PHP child running bin/pool-worker. Gigs go down its stdin as frames, results come back up
 * its stdout as frames, and whatever it prints lands on stderr. It wakes on both pipes, and on
 * its own exit where the backend can wait on a process.
 */
final class ProcessWorker extends WakeSource implements PoolWorker
{
    private const int STDERR_TAIL = 2048;

    /** @var resource */
    private $process;

    /** @var resource */
    private $stdin;

    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private int $pid;

    private bool $greeted = false;

    private bool $gone = false;

    private bool $stderr_open = true;

    private string $buffer = '';

    private string $stderr_tail = '';

    /** @var array{0: ShouldPool, 1: Promise}|null the gig it holds, sent once the child says hello */
    private ?array $current = null;

    /**
     * @param list<string> $command
     */
    public function __construct(
        private readonly string $name,
        private readonly IOPool $pool,
        private readonly Loop $loop,
        array $command,
    ) {
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);

        if (! is_resource($process)) {
            throw new DeadWorkerException("Worker {$name} could not be started: ".implode(' ', $command));
        }

        [$this->stdin, $this->stdout, $this->stderr] = $pipes;
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'];
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
        return ! $this->gone && proc_get_status($this->process)['running'];
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
        if ($this->gone) {
            return [];
        }

        $wakes = [new Readable($this->stdout)];

        if ($this->stderr_open) {
            $wakes[] = new Readable($this->stderr);
        }

        if ($this->loop->supports(WakeReason::PROCESS_EXIT)) {
            $wakes[] = new ProcessExit($this->pid);
        }

        return $wakes;
    }

    public function woke(array $fired): void
    {
        if ($this->gone) {
            return;
        }

        $this->drainStderr();

        $exited = array_any($fired, fn (Wake $wake): bool => $wake instanceof ProcessExit);

        // An exit notice can beat the child's last bytes: once it has exited, read the pipe to its end.
        if ($exited) {
            stream_set_blocking($this->stdout, true);
        }

        $chunk = $exited ? stream_get_contents($this->stdout) : fread($this->stdout, 65536);
        $this->buffer .= $chunk === false ? '' : $chunk;

        try {
            while (! is_null($frame = Frame::take($this->buffer))) {
                $this->receive($frame);

                if ($this->gone) {
                    return;
                }
            }
        } catch (IOPoolsException $e) {
            $this->die("wrote to its protocol pipe outside a frame. {$e->getMessage()}");
            return;
        }

        if ($exited || feof($this->stdout)) {
            $this->drainStderr();
            $this->die($this->current ? 'exited before its gig finished.' : 'exited.');
        }
    }

    private function receive(array $frame): void
    {
        if (isset($frame['hello'])) {
            $this->greeted = true;

            if ($this->current) {
                $this->send();
            }

            return;
        }

        if (is_null($this->current)) {
            $this->die('sent a result nobody asked for.');
            return;
        }

        [, $promise] = $this->current;
        $this->current = null;

        GigEnvelope::settle($promise, $frame);
        $this->pool->finished($this);
    }

    private function send(): void
    {
        [$gig, $promise] = $this->current;

        try {
            $frame = Frame::encode(['gig' => $gig]);
        } catch (Throwable $e) {
            // Nothing reached the child: this worker is still free for the next gig.
            $this->current = null;
            $promise->reject(new IOPoolsException("This gig can't be sent to a worker: {$e->getMessage()}", 0, $e));
            $this->pool->finished($this);
            return;
        }

        if (@fwrite($this->stdin, $frame) !== strlen($frame)) {
            $this->die('closed its stdin before the gig was sent.');
        }
    }

    private function die(string $why): void
    {
        [$current, $this->current] = [$this->current, null];
        $this->close();

        $death = new DeadWorkerException("Worker {$this->name} (pid {$this->pid}) {$why}{$this->tail()}");

        $current && $current[1]->reject($death);
        $this->pool->died($this);
    }

    private function close(): void
    {
        $this->gone = true;

        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (proc_get_status($this->process)['running']) {
            proc_terminate($this->process);
        }

        proc_close($this->process);
    }

    private function drainStderr(): void
    {
        if (! $this->stderr_open) {
            return;
        }

        $chunk = fread($this->stderr, 65536);
        $this->stderr_tail = substr($this->stderr_tail.($chunk === false ? '' : $chunk), -self::STDERR_TAIL);

        if (feof($this->stderr)) {
            $this->stderr_open = false;
        }
    }

    private function tail(): string
    {
        $tail = trim($this->stderr_tail);

        return $tail === '' ? '' : " Last output: {$tail}";
    }

}