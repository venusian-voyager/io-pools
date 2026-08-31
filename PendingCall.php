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
