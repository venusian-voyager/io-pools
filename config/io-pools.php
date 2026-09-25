<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Event Loop
    |--------------------------------------------------------------------------
    |
    | tick_budget is the fallback sleep, in milliseconds, for a turn that has
    | no timer due and no stream to select on. Mail collected during a turn is
    | handed to the default handler. "signal" dispatches every event through
    | the signal bus.
    | Available Handlers: 'signal'
    */

    'event_loop' => [
        'tick_budget' => env("RESOURCE_TICK_BUDGET", 16),
        'mail_handlers' => [
            'default' => env('EVENT_MAIL_HANDLER', 'signal'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Promises
    |--------------------------------------------------------------------------
    |
    | Which library stores and chains a settled value. "guzzle" ships with the
    | framework. "react" needs react/promise. This is not where fibers are
    | chosen: a fiber decides what the caller does while it waits, and it
    | works with either engine.
    | Available options: 'guzzle' and 'react'
    */

    'promises' => [
        'default_engine' => 'guzzle',
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Pool
    |--------------------------------------------------------------------------
    |
    | One pool, two drivers. "process" is the default and runs on any build:
    | each worker is a child process. "thread" needs a ZTS build with
    | ext-parallel. A thread shares this process, so a gig that segfaults
    | takes the app with it, and shutting one down waits until the thread
    | reaches an interrupt point.
    |
    | size is how many workers may be busy at once. max_jobs recycles a
    | worker after that many gigs; null never recycles. A null autoload_path
    | is the app's vendor/autoload.php, and a null base_path is the app's
    | base path. Both drivers boot the framework there. A worker that hasn't
    | finished booting within hello_timeout_s is killed and its gig rejected.
    |
    | worker_script and php_args apply to the process driver. A null script
    | uses the packaged bin/pool-worker. sweep_seconds applies to the thread
    | driver: how long a gig that calls exit() can sit before the pool
    | notices. exit() never rings the bell.
    | Available Pool Drivers: 'process' | 'thread'
    |
    */

    'thread_pool' => [
        'driver'        => env('POOL_DRIVER', 'process'),
        'size'          => (int) env('POOL_SIZE', 4),
        'max_jobs'      => is_null($max = env('POOL_MAX_JOBS', 500)) ? null : (int) $max,
        'autoload_path' => null,
        'base_path'     => null,
        'hello_timeout_s' => (float) env('POOL_HELLO_TIMEOUT', 5.0),

        // process driver
        'worker_script' => null,
        'php_args'      => [],

        // thread driver
        'sweep_seconds' => (float) env('POOL_SWEEP_SECONDS', 0.5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Work Targets
    |--------------------------------------------------------------------------
    |
    | Where a gig runs when you send it through via() or WorkTargetManager.
    | sync is now; defer is the next loop turn; pool is a worker; concurrency
    | isolates (blocks); queue is fire-and-forget and the promise is the job id.
    |
    */

    'work' => [
        'default' => env('WORK_TARGET', 'pool'),            // sync | defer | pool | concurrency | queue
        'queue_connection' => env('WORK_QUEUE', null),      // for the queue target; null = queue.default
    ],

];
