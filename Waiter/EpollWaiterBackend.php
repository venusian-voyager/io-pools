<?php

namespace Voyager\IOPools\Waiter;

use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;

/**
 * One epoll set holds every stream. The kernel keeps one registration per descriptor with one
 * event mask, so readers and writers of the same stream share it and the mask is their union.
 * ext-epoll binds the epoll calls only: signals reach the Waiter through its relay, and process
 * exit and file changes wait for signalfd, pidfd and inotify bindings.
 * The epoll fd lives as long as the process: ext-epoll doesn't bind close().
 */
class EpollWaiterBackend extends WaiterBackendDriver
{
    private const int MAX_EVENTS = 64;

    /** Linux's EINTR. epoll exists only on Linux, where it is 4 on every architecture. */
    private const int EINTR = 4;

    private int $ep;

    /**
     * @var array<int, array{stream: resource, reads: array<string, Readable>, writes: array<string, Writable>}>
     *      PHP stream id => the stream and who watches it
     */
    private array $streams = [];

    public function __construct()
    {
        $ep = epoll_create1(EPOLL_CLOEXEC);

        if ($ep < 0) {
            throw new IOPoolsException('epoll_create1() failed with errno '.epoll_errno().'.');
        }

        $this->ep = $ep;
    }

    /** An epoll descriptor is itself readable while it has ready events (epoll(7)). */
    public function descriptor(): ?int
    {
        return $this->ep;
    }

    public function supports(WakeReason $kind): bool
    {
        return $kind === WakeReason::READABLE || $kind === WakeReason::WRITEABLE;
    }

    public function add(string $owner, Wake $wake): void
    {
        if (! $wake instanceof Readable && ! $wake instanceof Writable) {
            throw new IOPoolsException("epoll can't wait on a {$wake->kind()->value} wake.");
        }

        // PHP never reuses a resource id, so a closed stream's entry can't be mistaken for a new one.
        $id = (int) $wake->stream;
        $entry = $this->streams[$id] ?? ['stream' => $wake->stream, 'reads' => [], 'writes' => []];
        $before = $this->mask($entry);

        $entry[$wake instanceof Readable ? 'reads' : 'writes'][$owner] = $wake;

        $this->apply($id, $entry, $before);
    }

    public function remove(string $owner, Wake $wake): void
    {
        $id = (int) $wake->stream;

        if (! isset($this->streams[$id])) {
            return;
        }

        $entry = $this->streams[$id];
        $before = $this->mask($entry);

        unset($entry[$wake instanceof Writable ? 'writes' : 'reads'][$owner]);

        $this->apply($id, $entry, $before);
    }

    public function wait(?int $timeout_ns): array
    {
        $fired = $this->buffered();

        // Something is already due: the kernel only gets a glance.
        $events = epoll_pwait2($this->ep, self::MAX_EVENTS, $fired === [] ? $timeout_ns : 0, null);

        if ($events === false) {
            $errno = epoll_errno();

            // A signal cut the sleep short: nothing of ours fired.
            if ($errno === self::EINTR) {
                return $this->unique($fired);
            }

            throw new IOPoolsException("epoll_pwait2() failed: errno {$errno}.");
        }

        foreach ($events as ['events' => $mask, 'data' => $id]) {
            // Removed after the kernel queued it: nobody to tell.
            $entry = $this->streams[$id] ?? null;

            if (is_null($entry)) {
                continue;
            }

            // Hang-up and error end a wait for either direction: the next read or write reports them.
            if ($mask & (EPOLLIN | EPOLLRDHUP | EPOLLHUP | EPOLLERR)) {
                foreach ($entry['reads'] as $owner => $wake) {
                    $fired[$owner][] = $wake;
                }
            }

            if ($mask & (EPOLLOUT | EPOLLHUP | EPOLLERR)) {
                foreach ($entry['writes'] as $owner => $wake) {
                    $fired[$owner][] = $wake;
                }
            }
        }

        return $this->unique($fired);
    }

    /**
     * Add, change or delete the stream's registration so the kernel's mask matches its watchers.
     *
     * @param int $id
     * @param array $entry
     * @param int $before
     * @return void
     */
    private function apply(int $id, array $entry, int $before): void
    {
        $after = $this->mask($entry);

        if ($after === 0) {
            unset($this->streams[$id]);

            // Closing the last descriptor already removed it from the set.
            if ($before !== 0 && is_resource($entry['stream'])) {
                epoll_ctl($this->ep, EPOLL_CTL_DEL, $entry['stream']);
            }

            return;
        }

        $this->streams[$id] = $entry;

        if ($after === $before) {
            return;
        }

        $op = $before === 0 ? EPOLL_CTL_ADD : EPOLL_CTL_MOD;

        if (epoll_ctl($this->ep, $op, $entry['stream'], $after, $id) === -1) {
            $errno = epoll_errno();
            unset($this->streams[$id]);

            throw new IOPoolsException("epoll_ctl() could not register stream #{$id}: errno {$errno}.");
        }
    }

    private function mask(array $entry): int
    {
        return ($entry['reads'] === [] ? 0 : EPOLLIN | EPOLLRDHUP)
            | ($entry['writes'] === [] ? 0 : EPOLLOUT);
    }

    /**
     * Bytes PHP already read into a stream's buffer never show up in the kernel's set.
     * stream_select counts them; this does the same.
     *
     * @return array<string, list<Wake>>
     */
    private function buffered(): array
    {
        $fired = [];

        foreach ($this->streams as $entry) {
            if ($entry['reads'] === [] || ! is_resource($entry['stream'])) {
                continue;
            }

            if (stream_get_meta_data($entry['stream'])['unread_bytes'] > 0) {
                foreach ($entry['reads'] as $owner => $wake) {
                    $fired[$owner][] = $wake;
                }
            }
        }

        return $fired;
    }

    /**
     * @param array<string, list<Wake>> $fired
     * @return array<string, list<Wake>> each wake once per owner
     */
    private function unique(array $fired): array
    {
        foreach ($fired as $owner => $wakes) {
            $by_key = [];

            foreach ($wakes as $wake) {
                $by_key[$wake->key()] = $wake;
            }

            $fired[$owner] = array_values($by_key);
        }

        return $fired;
    }
}