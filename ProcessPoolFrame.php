<?php

namespace Voyager\IOPools;

use Voyager\Contracts\IOPools\EventLoopException;

class ProcessPoolFrame
{
    /** Opens every frame, so stray bytes on the pipe fail loudly instead of reading as a length. */
    public const string MAGIC = "VFP\x01";

    public static function encode(array $payload): string
    {
        $body = serialize($payload);

        return self::MAGIC.pack('N', strlen($body)).$body;
    }

    /**
     * @param resource $stream
     */
    public static function write($stream, array $payload): void
    {
        fwrite($stream, self::encode($payload));
    }

    /**
     * @throws EventLoopException the buffer starts with something that isn't a frame
     */
    public static function take(string &$buffer): ?array
    {
        // a partial magic is fine, a wrong one never becomes right
        $seen = min(strlen($buffer), strlen(self::MAGIC));

        if (strncmp($buffer, self::MAGIC, $seen) !== 0) {
            throw new EventLoopException('Expected a frame, got '.json_encode(substr($buffer, 0, 200), JSON_INVALID_UTF8_SUBSTITUTE).'.');
        }

        if (strlen($buffer) < 8) {
            return null;
        }

        $length = unpack('N', $buffer, 4)[1];

        // more than half a frame: get some
        if (strlen($buffer) < 8 + $length) {
            return null;
        }

        $body = substr($buffer, 8, $length);

        // leave the rest for next time
        $buffer = substr($buffer, 8 + $length);

        return unserialize($body);
    }
}
