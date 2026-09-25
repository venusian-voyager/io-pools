<?php

namespace Voyager\IOPools;

use Throwable;
use Voyager\Contracts\IOPools\DeadWorkerException;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\LoopTimer;
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

    private bool $greeted = false;

    private float $hello_by;

    /** A gig's frame, encoded and waiting for the hello: a booting worker isn't reading stdin. */
    private ?string $held = null;

    private ?LoopTimer $hello_timer = null;

    public function __construct(
        public readonly string $name,           // 'pool:0'
        protected readonly LoopInterface $loop,
        private readonly ProcessPool $pool,
        array $command,                         // [PHP_BINARY, script, autoload, base_path]
        private readonly float $hello_timeout_s = 5.0,
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

        $dead = feof($this->stdout);

        try {
            $greeting = ! $this->greeted && $this->takeHello();
            $frame    = $this->greeted ? ProcessPoolFrame::take($this->buffer) : null;
        } catch (EventLoopException $e) {
            $this->die(new DeadWorkerException(
                "Worker {$this->name} wrote to its protocol pipe outside a frame. {$e->getMessage()} Last output: {$this->stderr_tail}", 0, $e
            ));
            return;
        }

        // a worker that said hello and left in one breath gets DeadWorkerException from die(), not a broken pipe
        if ($greeting && ! $dead) {
            $this->handOver();
        }

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
        $this->hello_timer?->cancel();
        [$this->hello_timer, $this->held] = [null, null];

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
            // serializes before it writes or holds: a gig that can't travel throws with nothing on the pipe
            $frame = ProcessPoolFrame::encode(['job' => $gig]);
        } catch (Throwable $e) {
            $this->refuse($e);
            return;
        }

        if ($this->greeted) {
            $this->send($frame);
            return;
        }

        // Still booting, so nothing reads stdin: a frame past the pipe buffer would block this process
        // in fwrite() until the boot ends, or forever. Hold it for the hello and bound the wait.
        $this->held = $frame;
        $this->hello_timer = $this->loop->at(max(0.0, $this->hello_by - microtime(true)), $this->helloOverdue(...));
    }

    protected function settle(array $frame): void
    {
        [$promise, $this->current] = [$this->current, null];
        $this->jobs_done++;

        PoolEnvelope::settle($promise, $frame);
    }

    private function die(?EventLoopException $why = null): void
    {
        [$promise, $this->current] = [$this->current, null];
        $promise?->reject($why ?? new DeadWorkerException(
            "Worker {$this->name} died. Last output: {$this->stderr_tail}"
        ));
        $this->pool->died($this);
    }

    /**
     * @throws EventLoopException the first frame wasn't a hello
     */
    private function takeHello(): bool
    {
        $hello = ProcessPoolFrame::take($this->buffer);

        if (is_null($hello)) {
            return false;
        }

        if (! array_key_exists('hello', $hello)) {
            throw new EventLoopException('Expected a hello first, got a frame keyed '.json_encode(array_keys($hello)).'.');
        }

        $this->hello_timer?->cancel();
        [$this->hello_timer, $this->greeted] = [null, true];

        return true;
    }

    private function handOver(): void
    {
        [$frame, $this->held] = [$this->held, null];

        if (! is_null($frame)) {
            $this->send($frame);
        }
    }

    private function helloOverdue(): void
    {
        $this->hello_timer = null;

        // a hello that landed after this turn's wait returned is in the pipe, unread: it still counts
        $this->tick();

        // tick() may have closed it (died, broke the protocol) or greeted it
        if (! $this->greeted && is_resource($this->process)) {
            $this->die(new EventLoopException(
                "Process worker {$this->name} failed to start: did not answer within {$this->hello_timeout_s}s. Last output: {$this->stderr_tail}"
            ));
        }
    }

    private function send(string $frame): void
    {
        try {
            fwrite($this->stdin, $frame);
        } catch (Throwable $e) {
            $this->refuse($e);
        }
    }

    /** Frees the worker on the spot: the gig never reached it. */
    private function refuse(Throwable $e): void
    {
        [$promise, $this->current] = [$this->current, null];

        $promise?->reject(new EventLoopException("This gig can't be sent to a worker: {$e->getMessage()}", 0, $e));

        $this->pool->finished($this);
    }

    private function bootstrap(array $command): void
    {
        $this->process = proc_open($command, [['pipe','r'], ['pipe','w'], ['pipe','w']], $pipes);
        [$this->stdin, $this->stdout, $this->stderr] = $pipes;

        // cached: still answerable after close()
        $this->pid = proc_get_status($this->process)['pid'];
        $this->hello_by = microtime(true) + $this->hello_timeout_s;

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