<?php

namespace Voyager\IOPools\Drivers;

use Voyager\IOPools\DTO\RedisMessage;
use Voyager\Contracts\IOPools\PoolPump;
use Voyager\Contracts\IOPools\Sendable;
use Voyager\Contracts\IOPools\QueuedIO;
use Voyager\Redis\Connections\Connection;

class RedisResourceDriver extends AsyncResourceDriver
{
    protected string $key = 'default';

    protected Connection $connection;
    public function __construct(
        public readonly array $options,
        protected Connection $redis,
        public readonly PoolPump $io_pool,
        protected int $batch = 64,
    ) {}

    public function key(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function post(Sendable $message, ?string $key = null): void
    {
        $payload = json_encode([
            'class' => $message::class,
            'data' => $message->toSendable()
        ]);
        $key = $key ?? $this->key;
        $this->redis->rpush($key, $payload);
    }

    public function tick(): void
    {
        for ($i = 0; $i < $this->batch; $i++) {
            $raw = $this->redis->lpop($this->key);
            if (! is_string($raw) || $raw === '') {
                return;
            }

            $final_result = $this->reconstitute($raw);
            $this->io_pool->push($final_result);
        }
    }

    protected function reconstitute(string $raw): QueuedIO
    {
        $decoded = json_decode($raw, true);

        if (
            is_array($decoded)
            && is_string($decoded['class'] ?? null)
            && class_exists($decoded['class'])
            && is_subclass_of($decoded['class'], Sendable::class)
            && is_subclass_of($decoded['class'], QueuedIO::class)
        ) {
            $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

            return $decoded['class']::fromSendable($data);
        }

        return new RedisMessage($this->key, $raw);
    }
}
