<?php

namespace Voyager\IOPools;

class ProcessPoolFrame
{
    /**
     * @param resource $stream
     * @param array $payload
     * @return void
     */
    public static function write($stream, array $payload): void
    {
        $body = serialize($payload);
        fwrite($stream, pack('N', strlen($body)) . $body);
    }

    public static function take(string &$buffer): ?array
    {
        $results = null;

        if(strlen($buffer) >= 4)
        {
            $length = unpack('N', $buffer)[1];

            // more than half a frame: get some
            if (!(strlen($buffer) < 4 + $length))
            {
                $body   = substr($buffer, 4, $length);

                // leave the rest for next time
                $buffer = substr($buffer, 4 + $length);

                $results = unserialize($body);
            }
        };

        return $results;
    }
}