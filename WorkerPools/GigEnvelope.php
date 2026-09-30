<?php

namespace Voyager\IOPools\WorkerPools;

use Throwable;
use Voyager\Contracts\IOPools\Promise;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\WorkerPools\ShouldPool;
use Voyager\Contracts\IOPools\WorkerPools\RemoteException;

/**
 * The one shape a gig's outcome takes on its way back from any worker. Plain values and
 * strings only: an exception object never crosses, its class, message and trace do.
 */
class GigEnvelope
{
    /**
     * Worker side: run the gig. Never throws.
     *
     * @return array{ok: true, value: mixed}|array{ok: false, class: string, message: string, trace: string}
     */
    public static function run(mixed $gig): array
    {
        if (! $gig instanceof ShouldPool) {
            return self::failed(new IOPoolsException(
                'The gig did not arrive as a ShouldPool. Is its class autoloadable in the worker?'
            ));
        }

        try {
            $envelope = ['ok' => true, 'value' => $gig->handle()];

            // Prove the value can travel before anyone tries to send it.
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

    /**
     * Loop side: the envelope becomes the promise's outcome.
     */
    public static function settle(Promise $promise, array $envelope): void
    {
        $envelope['ok']
            ? $promise->resolve($envelope['value'])
            : $promise->reject(new RemoteException($envelope['class'], $envelope['message'], $envelope['trace']));
    }
}