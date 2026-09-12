<?php

namespace Voyager\IOPools\Drivers;

use CurlHandle;
use CurlMultiHandle;
use Voyager\IOPools\Presumption;
use Voyager\IOPools\DTO\HttpResult;
use Voyager\Contracts\IOPools\PoolPump;
use Voyager\Contracts\IOPools\IOPoolsException;

class MultiCurlResourceDriver extends HttpResourceDriver
{
    protected int $id = 0;
    protected array $in_flight = [];
    protected ?CurlMultiHandle $multi = null;

    protected array $work = [];
    protected array $envelopes = [];
    protected array $last_progress = [];

    public function __construct(
        public readonly array $options,
        public readonly PoolPump $io_pool,
    ) {}

    public function tick(): void
    {
        $this->dispatchWork();
        $this->observe();
    }

    public function inFlight(string $name): ?Presumption
    {
        return $this->in_flight[$name] ?? null;
    }

    public function fetch(string $name, string $url, array $headers = [], array $params = [], ?callable $envelope = null): Presumption
    {
        if(!empty($params)) {
            $url .= "?".http_build_query($params);
        }
        return $this->call($name, $url, 'GET', $headers, null, $envelope);
    }

    public function post(string $name, string $url, array $headers = [], array $body = [], ?callable $envelope = null): Presumption
    {
        return $this->call($name, $url, 'POST', $headers, $body, $envelope);
    }

    public function call(string $name, string $url, string $method, array $headers = [], ?array $body = null, ?callable $envelope = null): Presumption
    {
        if (isset($this->in_flight[$name])) {
            throw new IOPoolsException("Call '{$name}' is already in flight.");
        }

        $presumption = new Presumption($name);
        $this->in_flight[$name] = $presumption;

        if (isset($envelope)) {
            $this->envelopes[$name] = $envelope;
        }

        $this->dispatch($name, $url, strtoupper($method), $headers, $body);

        return $presumption;
    }

    public function progress(): array
    {
        $moving = [];
        foreach ($this->work as $task) {
            $moving[$task['name']] = $task['progress'];
        }

        return $moving;
    }

    protected function observe(): void
    {
        foreach ($this->progress() as $name => $moved) {
            if (($this->last_progress[$name] ?? -1) === $moved['now']) {
                continue;
            }
            $this->last_progress[$name] = $moved['now'];

            ($this->in_flight[$name] ?? null)?->notifyProgress($moved['now'], $moved['total']);
        }
    }

    protected function dispatchWork(): void
    {
        foreach ($this->harvest() as $result) {
            if (! $result instanceof HttpResult) {
                continue;
            }

            $name = $result->name;
            $presumption = $this->in_flight[$name] ?? null;
            $envelope = $this->envelopes[$name] ?? null;
            unset($this->in_flight[$name], $this->envelopes[$name], $this->last_progress[$name]);
            $final_result = is_null($envelope) ? $result : $envelope($result);
            $this->io_pool->push($final_result);

            $presumption?->settle($result);
        }
    }

    protected function dispatch(string $name, string $url, string $method, array $headers = [], ?array $body = null): void
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new IOPoolsException('curl_init failed.');
        }

        $id = (int) spl_object_id($handle);
        $this->work[$id] = ['name' => $name, 'handle' => $handle, 'headers' => [], 'progress' => ['now' => 0, 'total' => 0]];

        $header_lines = [];
        foreach ($headers as $key => $value) {
            $header_lines[] = "{$key}: {$value}";
        }

        $this->fire($handle, $id, $url, $method, $header_lines, $body);
    }

    protected function fire(mixed $handle, string $id, string $url, string $method, array $header_lines, ?array $body = null): void
    {
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_LOW_SPEED_LIMIT => 1024,
            CURLOPT_LOW_SPEED_TIME => 120,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function (CurlHandle $h, int $dl_total, int $dl_now): int {
                $id = (int) spl_object_id($h);
                if (isset($this->work[$id])) {
                    $this->work[$id]['progress'] = ['now' => $dl_now, 'total' => $dl_total];
                }

                return 0;
            },
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $h, string $line) use ($id): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $this->work[$id]['headers'][trim($key)] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if (! is_null($body)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $this->multi ??= curl_multi_init();
        curl_multi_add_handle($this->multi, $handle);
    }

    protected function harvest(): array
    {
        if (is_null($this->multi)) {
            return [];
        }

        do {
            $code = curl_multi_exec($this->multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        $results = [];
        while (($info = curl_multi_info_read($this->multi)) !== false) {
            $handle = $info['handle'];
            $id = (int) spl_object_id($handle);
            $task_record = $this->work[$id];
            unset($this->work[$id]);

            $errno = (int) $info['result'];
            $results[] = new HttpResult(
                name: $task_record['name'],
                ok: $errno === CURLE_OK,
                status: (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                headers: $task_record['headers'],
                body: (string) curl_multi_getcontent($handle),
                error: $errno === CURLE_OK ? null : curl_strerror($errno),
            );

            curl_multi_remove_handle($this->multi, $handle);
            curl_close($handle);
        }

        return $results;
    }
}
