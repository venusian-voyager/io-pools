<?php

namespace Voyager\IOPools;

/**
 * The outcome of one non-blocking HTTP call.
 *
 * $ok reports TRANSPORT success only — a 404 is a successful conversation
 * and the consumer judges the status itself. $error carries the transport
 * failure (DNS, refused, timeout) when $ok is false.
 */
final class HttpResult
{
    /**
     * @param array<string, string> $headers Response headers, last value wins.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $ok,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $error = null,
    ) {}

    /** @return array<string, mixed> The event-payload shape. */
    public function toPayload(): array
    {
        return [
            'ok' => $this->ok,
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
            'error' => $this->error,
        ];
    }
}
