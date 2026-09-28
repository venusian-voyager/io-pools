<?php

namespace Voyager\IOPools\Waiter;

use kevent;
use timespec;
use Voyager\Contracts\IOPools\Wake;
use Voyager\Contracts\IOPools\WakeReason;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\IOPools\Waiter\Wakes\Readable;
use Voyager\IOPools\Waiter\Wakes\Writable;
use Voyager\IOPools\Waiter\Wakes\FileChange;
use Voyager\IOPools\Waiter\Wakes\ProcessExit;
use Voyager\IOPools\Waiter\Wakes\ControlSignal;

/**
 * One kqueue holds every wake. The kernel keys a registration by (ident, filter), so owners
 * watching the same thing share one registration and each gets the wake it declared.
 * The kqueue fd lives as long as the process: nothing on macOS binds close() for it.
 */
class KqueueWaiterBackend extends WaiterBackendDriver
{
    private const int MAX_EVENTS = 64;

    private int $kq;

    private int $next_id = 1;

    /**
     * @var array<string, int> "filter:ident" => registration id
     */
    private array $ids = [];

    /**
     * @var array<int, array{filter: int, ident: int, owners: array<string, Wake>, file: resource|null}>
     */
    private array $registrations = [];

    /**
     * @var array<string, array<string, int>> owner => wake key => registration id
     */
    private array $by_owner = [];

    /**
     * @var array<string, list<Wake>> fired before any wait: a process already gone when watched
     */
    private array $early = [];

    public function __construct()
    {
        $kq = kqueue();

        if ($kq < 0) {
            throw new IOPoolsException('kqueue() failed with errno '.kqueue_errno().'.');
        }

        $this->kq = $kq;
    }

    public function supports(WakeReason $kind): bool
    {
        return match ($kind) {
            // The filter records a signal, but the default action still runs unless it's ignored.
            WakeReason::CONTROL_SIGNAL => extension_loaded('pcntl'),
            default => true,
        };
    }

    public function add(string $owner, Wake $wake): void
    {
        [$ident, $filter, $flags, $fflags, $file] = $this->target($wake);

        $kev = new kevent();
        EV_SET($kev, $ident, $filter, EV_ADD | $flags, $fflags, 0, 0);
        $key = $filter.':'.$kev->ident;

        // A registration whose streams were all closed was dropped by the kernel with the fd:
        // the fd number may now belong to the stream being added.
        if (isset($this->ids[$key]) && ! $this->alive($this->ids[$key])) {
            $this->drop($this->ids[$key]);
        }

        if (isset($this->ids[$key])) {
            $id = $this->ids[$key];
            $this->registrations[$id]['owners'][$owner] = $wake;
            $this->by_owner[$owner][$wake->key()] = $id;

            if ($file) {
                fclose($file);
            }

            return;
        }

        $id = $this->next_id++;
        $kev->udata = $id;

        if (kevent($this->kq, [$kev], 1, $none, 0, null) === -1) {
            $errno = kqueue_errno();

            if ($file) {
                fclose($file);
            }

            // The process exited before it was watched: that is its exit, reported on the next wait.
            if ($wake instanceof ProcessExit && $errno === ESRCH) {
                $this->early[$owner][] = $wake;
                return;
            }

            throw new IOPoolsException("kevent() could not add {$wake->key()} for '{$owner}': errno {$errno}.");
        }

        if ($wake instanceof ControlSignal) {
            pcntl_signal($wake->signo, SIG_IGN);
        }

        $this->ids[$key] = $id;
        $this->registrations[$id] = [
            'filter' => $filter,
            'ident' => $kev->ident,
            'owners' => [$owner => $wake],
            'file' => $file,
        ];
        $this->by_owner[$owner][$wake->key()] = $id;
    }

    public function remove(string $owner, Wake $wake): void
    {
        $this->early[$owner] = array_values(array_filter(
            $this->early[$owner] ?? [],
            fn (Wake $early): bool => $early->key() !== $wake->key(),
        ));

        if ($this->early[$owner] === []) {
            unset($this->early[$owner]);
        }

        $id = $this->by_owner[$owner][$wake->key()] ?? null;
        unset($this->by_owner[$owner][$wake->key()]);

        if (($this->by_owner[$owner] ?? []) === []) {
            unset($this->by_owner[$owner]);
        }

        if (is_null($id) || ! isset($this->registrations[$id])) {
            return;
        }

        unset($this->registrations[$id]['owners'][$owner]);

        if ($this->registrations[$id]['owners'] === []) {
            $this->drop($id);
        }
    }

    public function wait(?int $timeout_ns): array
    {
        [$fired, $this->early] = [$this->early, []];

        foreach ($this->buffered() as $owner => $wakes) {
            $fired[$owner] = [...($fired[$owner] ?? []), ...$wakes];
        }

        // Something is already due: the kernel only gets a glance.
        if ($fired !== []) {
            $timeout_ns = 0;
        }

        $timeout = null;

        if (! is_null($timeout_ns)) {
            $timeout = new timespec();
            $timeout->tv_sec = intdiv($timeout_ns, 1_000_000_000);
            $timeout->tv_nsec = $timeout_ns % 1_000_000_000;
        }

        if (kevent($this->kq, [], 0, $events, self::MAX_EVENTS, $timeout) === -1) {
            $errno = kqueue_errno();

            // A signal cut the sleep short: nothing of ours fired.
            if ($errno === EINTR) {
                return $this->unique($fired);
            }

            throw new IOPoolsException("kevent() wait failed: errno {$errno}.");
        }

        foreach ($events ?? [] as $event) {
            // Removed after the kernel queued it: nobody to tell.
            foreach ($this->registrations[$event->udata]['owners'] ?? [] as $owner => $wake) {
                $fired[$owner][] = $wake;
            }
        }

        return $this->unique($fired);
    }

    /**
     * @return array{0: mixed, 1: int, 2: int, 3: int, 4: resource|null} ident, filter, flags, fflags, opened file
     */
    private function target(Wake $wake): array
    {
        return match (true) {
            $wake instanceof Readable => [$wake->stream, EVFILT_READ, 0, 0, null],
            $wake instanceof Writable => [$wake->stream, EVFILT_WRITE, 0, 0, null],
            $wake instanceof ControlSignal => [$wake->signo, EVFILT_SIGNAL, 0, 0, null],
            $wake instanceof ProcessExit => [$wake->pid, EVFILT_PROC, EV_CLEAR, NOTE_EXIT, null],
            $wake instanceof FileChange => $this->vnode($wake),
            default => throw new IOPoolsException("kqueue can't wait on a {$wake->kind()->value} wake."),
        };
    }

    /**
     * EVFILT_VNODE watches an open descriptor, so the backend holds the file open while it's watched.
     * @param FileChange $wake
     * @return array
     */
    private function vnode(FileChange $wake): array
    {
        $file = @fopen($wake->path, 'r');

        if ($file === false) {
            throw new IOPoolsException("kqueue can't watch '{$wake->path}': it could not be opened for reading.");
        }

        $notes = NOTE_WRITE | NOTE_EXTEND | NOTE_ATTRIB | NOTE_DELETE | NOTE_RENAME | NOTE_REVOKE;

        return [$file, EVFILT_VNODE, EV_CLEAR, $notes, $file];
    }

    private function drop(int $id): void
    {
        $registration = $this->registrations[$id];

        // A closed fd already took its registrations with it: only an open one is deleted.
        if ($this->alive($id)) {
            $kev = new kevent();
            EV_SET($kev, $registration['ident'], $registration['filter'], EV_DELETE, 0, 0, 0);
            kevent($this->kq, [$kev], 1, $none, 0, null);
        }

        if ($registration['filter'] === EVFILT_SIGNAL) {
            pcntl_signal($registration['ident'], SIG_DFL);
        }

        if (is_resource($registration['file'])) {
            fclose($registration['file']);
        }

        unset($this->ids[$registration['filter'].':'.$registration['ident']], $this->registrations[$id]);
    }

    /**
     * False once every stream behind a read or write registration has been closed.
     *
     * @param int $id
     * @return bool
     */
    private function alive(int $id): bool
    {
        $registration = $this->registrations[$id];

        if ($registration['filter'] !== EVFILT_READ && $registration['filter'] !== EVFILT_WRITE) {
            return true;
        }

        foreach ($registration['owners'] as $wake) {
            if (is_resource($wake->stream)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bytes PHP already read into a stream's buffer never show up in the kernel's queue.
     * stream_select counts them; this does the same.
     *
     * @return array<string, list<Wake>>
     */
    private function buffered(): array
    {
        $fired = [];

        foreach ($this->registrations as $registration) {
            if ($registration['filter'] !== EVFILT_READ) {
                continue;
            }

            foreach ($registration['owners'] as $owner => $wake) {
                if (is_resource($wake->stream) && stream_get_meta_data($wake->stream)['unread_bytes'] > 0) {
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