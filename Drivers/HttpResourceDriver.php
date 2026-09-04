<?php

namespace Voyager\IOPools\Drivers;

use Voyager\Contracts\IOPools\HttpResourceDriver as ResourceDriverContract;
use Voyager\IOPools\DTO\HttpResult;
use Voyager\IOPools\Presumption;

/**
 * What every HTTP resource owes, whatever its transport: named calls that
 * hand back a Presumption, one in-flight call per name, finished work
 * mailed to the dock inside tick(), and none of it may block.
 */
abstract class HttpResourceDriver implements ResourceDriverContract
{
    /**
     * Start a named GET; params bake into the query string.
     */
    abstract public function fetch(string $name, string $url, array $headers = [], array $params = [], ?callable $envelope = null): Presumption;

    /**
     * Start a named POST carrying a body.
     */
    abstract public function post(string $name, string $url, array $headers = [], array $body = [], ?callable $envelope = null): Presumption;

    /**
     * Start a call. One in-flight call per name — a duplicate is refused;
     * the name frees when the call settles.
     */
    abstract public function call(string $name, string $url, string $method, array $headers = [], ?array $body = null, ?callable $envelope = null): Presumption;

    /**
     * The presumption still flying under a name, or null — the door a
     * client needs to coalesce identical requests instead of throwing.
     */
    abstract public function inFlight(string $name): ?Presumption;

    /**
     * Bytes moved so far per in-flight name. Transports that cannot know
     * answer an empty array.
     *
     * @return array<string, array{now: int, total: int}>
     */
    abstract public function progress(): array;

    /**
     * Start one request on the transport and return immediately.
     */
    abstract protected function dispatch(string $name, string $url, string $method, array $headers = [], ?array $body = null): void;

    /**
     * Advance in-flight transfers and hand back whatever finished as mail.
     *
     * @return list<HttpResult>
     */
    abstract protected function harvest(): array;
}
