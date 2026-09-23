<?php

namespace Voyager\IOPools;

use Throwable;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\ShouldPool;
use Voyager\Contracts\IOPools\RemoteException;

/**
 * The one shape a gig's outcome takes on its way back, whatever carried it.
 * Strings and plain values only: no exception object ever crosses.
 */
final class PoolEnvelope
{
    /**
     * Run the gig, worker side. Never throws.
     * @return array{ok: true, value: mixed}|array{ok: false, class: string, message: string, trace: string}
     */
    public static function run(ShouldPool $gig): array
    {
        try {
            $envelope = ['ok' => true, 'value' => $gig->handle()];

            // prove it can travel before anyone tries to send it
            serialize($envelope);

            return $envelope;
        } catch (Throwable $e) {
            return self::failed($e);
        }
    }

    /**
     * @return array{ok: false, class: string, message: string, trace: string}
     */
    public static function failed(Throwable $e): array
    {
        return [
            'ok' => false,
            'class' => $e::class,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ];
    }

    /** Loop side: turn an envelope into the promise's outcome. */
    public static function settle(Promise $promise, array $envelope): void
    {
        $envelope['ok']
            ? $promise->resolve($envelope['value'])
            : $promise->reject(new RemoteException($envelope['class'], $envelope['message'], $envelope['trace']));
    }
}
