<?php

namespace Voyager\IOPools;

use Closure;

/**
 * A call in flight. Hooks are optional and run inside the tick that
 * harvested the result; the task event fires through the queue regardless —
 * both lanes, always.
 */
final class PendingCall
{
    protected ?Closure $on_success = null;

    protected ?Closure $on_fail = null;

    protected ?Closure $on_progress = null;

    protected ?HttpResult $result = null;

    public function __construct(
        public readonly string $name,
    ) {}

    public function onSuccess(callable $hook): static
    {
        $this->on_success = Closure::fromCallable($hook);

        return $this;
    }

    public function onFail(callable $hook): static
    {
        $this->on_fail = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Hear the download move: hook(bytes_now, bytes_total). total is 0
     * until the server declares a length. Runs inside the tick, and only
     * when the byte count actually changed.
     */
    public function onProgress(callable $hook): static
    {
        $this->on_progress = Closure::fromCallable($hook);

        return $this;
    }

    /**
     * Fire the progress hook. Pool-internal.
     */
    public function notifyProgress(int $now, int $total): void
    {
        if (! is_null($this->on_progress)) {
            ($this->on_progress)($now, $total);
        }
    }

    public function settled(): bool
    {
        return ! is_null($this->result);
    }

    public function result(): ?HttpResult
    {
        return $this->result;
    }

    /**
     * Record the outcome and fire the matching hook. Pool-internal.
     */
    public function settle(HttpResult $result): void
    {
        $this->result = $result;

        $hook = $result->ok ? $this->on_success : $this->on_fail;
        if (! is_null($hook)) {
            $hook($result);
        }
    }
}
