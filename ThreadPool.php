<?php

namespace Voyager\IOPools;

use Throwable;
use parallel\Runtime;
use Voyager\Contracts\IOPools\LoopTimer;
use Voyager\Contracts\IOPools\PoolWorker;
use Voyager\Contracts\IOPools\EventLoopException;
use Voyager\Contracts\IOPools\Loop as LoopInterface;

/**
 * Workers are ext-parallel threads inside this process.
 *
 * Cheaper than processes (spawn ~15ms vs ~125ms, M-series Mac) and a job's echo/throw behave the same, but:
 *  - a job that segfaults takes the whole app with it; there is no isolation
 *  - a job must never touch UI; AppKit and GTK are main-thread only
 *  - needs a ZTS build with ext-parallel, and extensions built thread-safe
 */
class ThreadPool extends Pool
{
    /** @var resource|null */
    private $server = null;

    private ?string $socket_path = null;

    private ?LoopTimer $sweep = null;

    public function __construct(
        LoopInterface $loop,
        private readonly string $autoload_path,
        private readonly string $base_path,
        int $size = 4,
        ?int $max_jobs = null,
        private readonly float $sweep_seconds = 0.5,
        private readonly float $hello_timeout_s = 5.0,
    )
    {
        if (! extension_loaded('parallel')) {
            throw new EventLoopException('The thread pool needs ext-parallel on a ZTS build of PHP. Use the process driver here.');
        }

        // With Xdebug active, a Killed future leaves its variable undefined instead of throwing:
        // the dead-worker path would fail silently.
        if (extension_loaded('xdebug') && ini_get('xdebug.mode') !== 'off') {
            throw new EventLoopException('The thread pool cannot run with Xdebug active. Start PHP with -d xdebug.mode=off.');
        }

        if ($sweep_seconds <= 0) {
            throw new EventLoopException("sweep_seconds must be above zero; got {$sweep_seconds}.");
        }

        parent::__construct($loop, $size, $max_jobs);

        // a thread writing to a bell we've closed must not take the process down (Linux; macOS returns false)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGPIPE, SIG_IGN);
        }
    }

    /**
     * Rejects every promise first, then kills the runtimes.
     *
     * Runtime::kill() blocks until the thread reaches an interrupt point; a thread inside one long
     * C call (a query, an HTTP request, sleep) holds this method for the rest of that call. Anything
     * waiting on the loop, including a wait() in progress, waits with it. Under a container's
     * SIGTERM grace period that stall can use the whole window before SIGKILL. The process driver
     * terminates at once and has no such cost.
     */
    public function shutDown(): void
    {
        parent::shutDown();

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        if (! is_null($this->socket_path) && file_exists($this->socket_path)) {
            @unlink($this->socket_path);
        }

        [$this->server, $this->socket_path] = [null, null];
    }

    /** Where the bell is listening, or null before the first spawn. */
    public function socketPath(): ?string
    {
        return $this->socket_path;
    }

    protected function spawn(string $name): PoolWorker
    {
        $server = $this->server();

        $runtime = new Runtime($this->autoload_path);
        $hello = $runtime->run(ThreadRuntime::hello(...), [$this->socket_path, $name, $this->base_path]);

        $bell = @stream_socket_accept($server, $this->hello_timeout_s);

        if ($bell === false) {
            $this->abandon($runtime, $name, $this->whyNoHello($hello));
        }

        stream_set_timeout($bell, (int) ceil($this->hello_timeout_s));

        // the name is consumed HERE, before the loop ever sees this stream: tick() only ever reads '!'
        $said = rtrim((string) fgets($bell), "\n");

        if ($said !== $name) {
            fclose($bell);
            $this->abandon($runtime, $name, "answered as [{$said}]");
        }

        $hello->value();                            // a boot failure surfaces at spawn, not at the first gig

        stream_set_blocking($bell, false);

        return new ThreadPoolWorker($name, $this, $runtime, $bell, $this->socket_path, ThreadRuntime::work(...));
    }

    /** The sweep exists only while something is busy, so it can never keep an idle loop alive. */
    protected function afterAssign(): void
    {
        $this->sweep ??= $this->loop->every($this->sweep_seconds, $this->sweepBusy(...), 'thread-pool-sweep');
    }

    protected function afterSettle(): void
    {
        if (! is_null($this->sweep) && ! array_any($this->workers, fn (PoolWorker $w) => $w->busy())) {
            $this->sweep->cancel();
            $this->sweep = null;
        }
    }

    /** A gig that calls exit() never rings: finally and shutdown functions are both skipped. */
    private function sweepBusy(): void
    {
        foreach ($this->workers as $worker)
        {
            if ($worker instanceof ThreadPoolWorker && $worker->busy() && $worker->futureDone()) {
                $worker->tick();
            }
        }
    }

    /**
     * @return resource
     */
    private function server()
    {
        if (is_resource($this->server)) {
            return $this->server;
        }

        $this->socket_path = $this->freshSocketPath();

        $server = @stream_socket_server('unix://'.$this->socket_path, $errno, $errstr);

        if ($server === false) {
            throw new EventLoopException("The thread pool could not open its bell at {$this->socket_path}: {$errstr}");
        }

        return $this->server = $server;
    }

    private function freshSocketPath(): string
    {
        $file = 'vf-pool-'.getmypid().'-'.spl_object_id($this).'.sock';
        $path = rtrim(sys_get_temp_dir(), '/').'/'.$file;

        // sun_path is 104 bytes on macOS, 108 on Linux; macOS temp dirs run long
        if (strlen($path) > 100) {
            $path = '/tmp/'.$file;
        }

        // a leftover from a crashed run; missing is the normal case, and @unlink still warns under PHPUnit
        if (file_exists($path)) {
            @unlink($path);
        }

        return $path;
    }

    private function whyNoHello(\parallel\Future $hello): string
    {
        if (! $hello->done()) {
            return "did not answer within {$this->hello_timeout_s}s";
        }

        try {
            $hello->value();
        } catch (Throwable $e) {
            return $e::class.': '.$e->getMessage();
        }

        return 'finished its hello without connecting';
    }

    private function abandon(Runtime $runtime, string $name, string $why): never
    {
        try {
            $runtime->kill();
        } catch (Throwable) {
            //
        }

        throw new EventLoopException("Thread worker {$name} failed to start: {$why}. Is [{$this->autoload_path}] the app's autoloader?");
    }
}
