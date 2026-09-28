<?php

namespace Voyager\IOPools\WorkerPools\Process;

use Voyager\Contracts\IOPools\IOPoolsException;

/**
 * The pipe protocol between a process pool and its workers: magic, a 4-byte big-endian length,
 * then a serialized array. Stray bytes fail loudly instead of reading as a length.
 */
class Frame
{
    public const string MAGIC = "VPF\x01";

    private const int HEADER = 8;

    public static function encode(array $payload): string
    {
        $body = serialize($payload);

        return self::MAGIC.pack('N', strlen($body)).$body;
    }

    /**
     * Takes one whole frame off the front of $buffer, or returns null until one has arrived.
     *
     * @throws IOPoolsException the buffer starts with something that isn't a frame
     */
    public static function take(string &$buffer): ?array
    {
        // A partial magic is fine; a wrong one never becomes right.
        $seen = min(strlen($buffer), strlen(self::MAGIC));

        if (strncmp($buffer, self::MAGIC, $seen) !== 0) {
            throw new IOPoolsException('Expected a frame, got '.json_encode(substr($buffer, 0, 200), JSON_INVALID_UTF8_SUBSTITUTE).'.');
        }

        if (strlen($buffer) < self::HEADER) {
            return null;
        }

        $length = unpack('N', $buffer, 4)[1];

        if (strlen($buffer) < self::HEADER + $length) {
            return null;
        }

        $payload = unserialize(substr($buffer, self::HEADER, $length));
        $buffer = substr($buffer, self::HEADER + $length);

        if (! is_array($payload)) {
            throw new IOPoolsException('A frame arrived whose body is not an array.');
        }

        return $payload;
    }
}