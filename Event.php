<?php

namespace Voyager\IOPools;

/**
 * One thing that happened, delivered through the queue to the loop's one
 * consumer. $family is a machine-matchable kind ('task', 'window.closed');
 * $name is what a consumer checks with has()/get() — author-chosen names
 * pass through raw, machine-generated ones arrive namespaced.
 *
 * Domain layers subclass to add their vocabulary (Surface adds the window).
 */
class Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $family,
        public readonly string $name,
        public readonly array $payload = [],
    ) {}
}
