<?php

namespace Voyager\IOPools;

use Throwable;
use Voyager\Contracts\IOPools\DeadWorkerException;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ProcessWorker;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\Loop as LoopInterface;
use Voyager\Contracts\IOPools\StoppedPoolException;

class ProcessPoolWorker implements ProcessWorker
{
    /**
     * @var resource
     */
    private $process;

    /**
     * @var resource $stdin
     */
    private $stdin;

    /**
     * @var resource $stdout
     */
    private $stdout;

    /**
     * @var resource $stderr
     */
    private $stderr;

    private int $jobs_done = 0;

    private ?int $pid = null;

    private bool $retiring = false;

    /**
     * @var string
     */
    private string $buffer = '';

    /**
     * @var string
     */
    private string $stderr_tail = '';

    /**
     * @var Promise|null
     */
    private ?Promise $current = null;

    public function __construct(
        public readonly string $name,           // 'pool:0'
        protected readonly LoopInterface $loop,
        private readonly ProcessPool $pool,
        array $command,                         // [PHP_BINARY, script, autoload, base_path]
    ) {
        $this->bootstrap($command);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function tick(): void
    {
        $this->stderr_tail = substr($this->stderr_tail.fread($this->stderr, 65536), -2048);
        $this->buffer .= fread($this->stdout, 65536);

        $dead  = feof($this->stdout);
        $frame = ProcessPoolFrame::take($this->buffer);

        if (! is_null($frame)) {
            $this->settle($frame);
        }

        if ($dead) {
            $this->die();                        // died() respawns for the queue; don't hand this one more work
            return;
        }
        if (! is_null($frame)) {
            $this->pool->finished($this);
        }
    }

    public function busy(): bool
    {
        return ! is_null($this->current);
    }

    public function stop(): void
    {
        [$promise, $this->current] = [$this->current, null];

        $promise?->reject(new StoppedPoolException("The pool shut down while worker {$this->name} was on this gig."));

        // fclose stdin/stdout/stderr, proc_terminate, proc_close — no grace, as agreed
        $this->close();
    }

    public function retire(): void
    {
        $this->retiring = true;

        if (is_resource($this->stdin)) {
            fclose($this->stdin);         // pool-worker's while (! feof(STDIN)) ends by itself
        }
    }

    public function alive(): bool
    {
        return is_resource($this->process) && proc_get_status($this->process)['running'];
    }

    public function close(): void
    {
        foreach ([$this->stdin, $this->stdout, $this->stderr] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function streams(): array
    {
        return [$this->stdout, $this->stderr];
    }

    public function give(ShouldPool $gig, Promise $promise): void
    {
        $this->current = $promise;

        try {
            // serializes before it writes: a gig that can't travel throws with nothing on the pipe
            ProcessPoolFrame::write($this->stdin, ['job' => $gig]);
        } catch (Throwable $e) {
            $this->current = null;

            $promise->reject(new EventLoopException("This gig can't be sent to a worker: {$e->getMessage()}", 0, $e));

            $this->pool->finished($this);
        }
    }

    protected function settle(array $frame): void
    {
        [$promise, $this->current] = [$this->current, null];
        $this->jobs_done++;

        PoolEnvelope::settle($promise, $frame);
    }

    private function die(): void
    {
        [$promise, $this->current] = [$this->current, null];
        $promise?->reject(new DeadWorkerException(
            "Worker {$this->name} died. Last output: {$this->stderr_tail}"
        ));
        $this->pool->died($this);
    }

    private function bootstrap(array $command): void
    {
        $this->process = proc_open($command, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        [$this->stdin, $this->stdout, $this->stderr] = $pipes;

        // cached: still answerable after close()
        $this->pid = proc_get_status($this->process)['pid'];

        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);
    }

    public function jobsDone(): int
    {
        return $this->jobs_done;
    }

    public function retiring(): bool
    {
        return $this->retiring;
    }
}