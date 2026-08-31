<?php

namespace Voyager\IOPools;

use CurlHandle;
use CurlMultiHandle;
use Voyager\Contracts\IOPools\IOPoolsException;
use Voyager\Contracts\IOPools\HttpDriver;

/**
 * curl_multi transport: many requests in flight, one thread, zero blocking.
 * harvest() spends no time waiting — it advances whatever the kernel has
 * ready and returns.
 *
 * Opinionated defaults: 10s connect, 30s total, follows redirects. They can
 * become per-call options when a sketch needs them.
 */
class MultiCurlDriver implements HttpDriver
{
    protected ?CurlMultiHandle $multi = null;

    /** @var array<int, array{name: string, handle: CurlHandle, headers: array<string, string>}> */
    protected array $transfers = [];

    public function dispatch(string $name, string $method, string $url, array $headers, ?string $body): void
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new IOPoolsException('curl_init failed.');
        }

        $id = (int) spl_object_id($handle);
        $this->transfers[$id] = ['name' => $name, 'handle' => $handle, 'headers' => []];

        $header_lines = [];
        foreach ($headers as $key => $value) {
            $header_lines[] = "{$key}: {$value}";
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $header_lines,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $h, string $line) use ($id): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $this->transfers[$id]['headers'][trim($key)] = trim($value);
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

    public function harvest(): array
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
            $transfer = $this->transfers[$id];
            unset($this->transfers[$id]);

            $errno = (int) $info['result'];
            $results[] = new HttpResult(
                name: $transfer['name'],
                ok: $errno === CURLE_OK,
                status: (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                headers: $transfer['headers'],
                body: (string) curl_multi_getcontent($handle),
                error: $errno === CURLE_OK ? null : curl_strerror($errno),
            );

            curl_multi_remove_handle($this->multi, $handle);
            curl_close($handle);
        }

        return $results;
    }
}
