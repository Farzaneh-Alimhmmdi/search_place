<?php

namespace Src\Http;

use Src\Exceptions\BaladRequestException;
use Src\Support\Logger;

final class CurlHttpClient
{
    private int $timeout;
    private int $connectTimeout;
    private string $userAgent;
    private array $headers = [];
    private int $maxRetries;
    private float $delay;
    private int $lastStatusCode = 0;

    public function __construct(array $config = [])
    {
        $this->timeout = $config['timeout'] ?? 30;
        $this->connectTimeout = $config['connect_timeout'] ?? 10;
        $this->userAgent = $config['user_agent'] ?? 'Mozilla/5.0';
        $this->maxRetries = $config['max_retries'] ?? 3;
        $this->delay = $config['request_delay'] ?? 0.0;
    }

    public function setTimeout(int $seconds): self { $this->timeout = $seconds; return $this; }
    public function setConnectTimeout(int $seconds): self { $this->connectTimeout = $seconds; return $this; }
    public function setUserAgent(string $userAgent): self { $this->userAgent = $userAgent; return $this; }
    public function setHeader(string $key, string $value): self { $this->headers[$key] = $value; return $this; }
    public function retry(int $maxRetries): self { $this->maxRetries = $maxRetries; return $this; }
    public function setDelay(float $seconds): self { $this->delay = $seconds; return $this; }

    public function get(string $url, array $options = []): array
    {
        $attempt = 0;
        $lastError = '';

        while ($attempt <= $this->maxRetries) {
            if ($attempt > 0 && $this->delay > 0) {
                usleep((int)($this->delay * 1000000));
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers),
            ]);

            if (!empty($options['POST'])) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['POST']);
            }

            $response = curl_exec($ch);
            $this->lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $lastError = $error;
                Logger::warning("HTTP request failed (attempt $attempt): $error", ['url' => $url]);
                $attempt++;
                continue;
            }

            if ($this->lastStatusCode >= 200 && $this->lastStatusCode < 300) {
                $data = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new BaladRequestException("Invalid JSON response from: $url");
                }
                return ['success' => true, 'data' => $data, 'http_code' => $this->lastStatusCode];
            }

            if ($this->lastStatusCode >= 429 || $this->lastStatusCode >= 500) {
                $lastError = "HTTP $this->lastStatusCode";
                Logger::warning("HTTP retryable error: $this->lastStatusCode for $url");
                $attempt++;
                continue;
            }

            throw new BaladRequestException("HTTP request failed with status $this->lastStatusCode for URL: $url");
        }

        Logger::error("HTTP request failed after $attempt attempts", ['url' => $url, 'error' => $lastError]);
        throw new BaladRequestException("HTTP request failed after $attempt attempts: $lastError");
    }

    public function getLastStatusCode(): int { return $this->lastStatusCode; }

    /**
     * Fetch many URLs in parallel (raw HTML, not JSON).
     *
     * Each detail page is small, so 8 concurrent requests is polite and fast:
     * ~30 cards go from ~30 round-trips to ~4 batches (~6-8s instead of ~30s).
     *
     * @param string[] $urls    List of absolute URLs (keys are preserved).
     * @param array    $options Same as getRaw() ['timeout' => int, 'follow' => bool]
     * @param int      $concurrency Max simultaneous handles.
     * @return array<string|int,array{success:bool,body:string,final_url:string,http_code:int,error:string|null}>
     */
    public function getRawMulti(array $urls, array $options = [], int $concurrency = 8): array
    {
        if ($urls === []) {
            return [];
        }

        $concurrency = max(1, min(16, $concurrency));
        $timeout = (int) ($options['timeout'] ?? $this->timeout);
        $follow = array_key_exists('follow', $options) ? (bool) $options['follow'] : true;

        $headerList = array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers);
        $results = [];

        // Chunk politely: never open more than $concurrency at once.
        foreach (array_chunk($urls, $concurrency, true) as $chunk) {
            $multi = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $url) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $url,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                    CURLOPT_USERAGENT => $this->userAgent,
                    CURLOPT_FOLLOWLOCATION => $follow,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => $headerList,
                ]);
                curl_multi_add_handle($multi, $ch);
                $handles[(int) $ch] = ['key' => $key, 'handle' => $ch, 'url' => $url];
            }

            $running = null;
            do {
                $status = curl_multi_exec($multi, $running);
                if ($running) {
                    // Wait up to 1s for activity; avoids busy-loop.
                    curl_multi_select($multi, 1.0);
                }
            } while ($running && $status === CURLM_OK);

            foreach ($handles as $entry) {
                $ch = $entry['handle'];
                $key = $entry['key'];
                $url = $entry['url'];

                $body = (string) curl_multi_getcontent($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $error = curl_error($ch);

                if ($error !== '') {
                    Logger::warning('Parallel fetch failed', ['url' => $url, 'error' => $error]);
                    $results[$key] = [
                        'success' => false,
                        'body' => '',
                        'final_url' => $url,
                        'http_code' => $httpCode,
                        'error' => $error,
                    ];
                } elseif ($httpCode >= 200 && $httpCode < 300) {
                    $this->lastStatusCode = $httpCode;
                    $results[$key] = [
                        'success' => true,
                        'body' => $body,
                        'final_url' => $finalUrl !== '' ? $finalUrl : $url,
                        'http_code' => $httpCode,
                        'error' => null,
                    ];
                } else {
                    $results[$key] = [
                        'success' => false,
                        'body' => '',
                        'final_url' => $url,
                        'http_code' => $httpCode,
                        'error' => "HTTP $httpCode for $url",
                    ];
                }

                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
            }

            curl_multi_close($multi);

            // Tiny pause between batches to stay polite.
            if (count($results) < count($urls)) {
                usleep(120000);
            }
        }

        return $results;
    }

    /**
     * Perform a GET (or form POST) request and return the raw response body.
     *
     * Unlike get(), the body is NOT expected to be JSON (used for HTML pages).
     *
     * @param array $options ['POST' => string|array form fields, 'timeout' => int,
     *                        'follow' => bool follow redirects (default true)]
     * @return array ['success' => bool, 'body' => string, 'final_url' => string,
     *                'http_code' => int, 'error' => string|null]
     */
    public function getRaw(string $url, array $options = []): array
    {
        $attempt = 0;
        $lastError = '';
        $timeout = (int) ($options['timeout'] ?? $this->timeout);
        $follow = array_key_exists('follow', $options) ? (bool) $options['follow'] : true;

        while ($attempt <= $this->maxRetries) {
            if ($attempt > 0 && $this->delay > 0) {
                usleep((int)($this->delay * 1000000));
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_FOLLOWLOCATION => $follow,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($this->headers), $this->headers),
            ]);

            if (array_key_exists('POST', $options) && $options['POST'] !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['POST']);
            }

            $response = curl_exec($ch);
            $this->lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $lastError = $error;
                Logger::warning("HTTP request failed (attempt $attempt): $error", ['url' => $url]);
                $attempt++;
                continue;
            }

            if ($this->lastStatusCode >= 200 && $this->lastStatusCode < 300) {
                return [
                    'success' => true,
                    'body' => (string) $response,
                    'final_url' => $finalUrl !== '' ? $finalUrl : $url,
                    'http_code' => $this->lastStatusCode,
                    'error' => null,
                ];
            }

            if ($this->lastStatusCode >= 429 || $this->lastStatusCode >= 500) {
                $lastError = "HTTP $this->lastStatusCode";
                Logger::warning("HTTP retryable error: $this->lastStatusCode for $url");
                $attempt++;
                continue;
            }

            return [
                'success' => false,
                'body' => '',
                'final_url' => $url,
                'http_code' => $this->lastStatusCode,
                'error' => "HTTP request failed with status $this->lastStatusCode for URL: $url",
            ];
        }

        Logger::error("HTTP request failed after $attempt attempts", ['url' => $url, 'error' => $lastError]);
        return [
            'success' => false,
            'body' => '',
            'final_url' => $url,
            'http_code' => $this->lastStatusCode,
            'error' => "HTTP request failed after $attempt attempts: $lastError",
        ];
    }

    /**
     * Perform a POST request with custom headers.
     *
     * @param string $url
     * @param string $postData
     * @param array $headers
     * @return array ['success' => bool, 'data' => array, 'http_code' => int, 'error' => string|null]
     */
    public function postWithHeaders(string $url, string $postData, array $headers): array
    {
        $attempt = 0;
        $lastError = '';
        $mergedHeaders = array_merge($this->headers, $headers);

        while ($attempt <= $this->maxRetries) {
            if ($attempt > 0 && $this->delay > 0) {
                usleep((int)($this->delay * 1000000));
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($mergedHeaders), $mergedHeaders),
            ]);

            $response = curl_exec($ch);
            $this->lastStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                $lastError = $error;
                Logger::warning("HTTP request failed (attempt $attempt): $error", ['url' => $url]);
                $attempt++;
                continue;
            }

            if ($this->lastStatusCode >= 200 && $this->lastStatusCode < 300) {
                $data = json_decode($response, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new BaladRequestException("Invalid JSON response from: $url");
                }
                return ['success' => true, 'data' => $data, 'http_code' => $this->lastStatusCode];
            }

            if ($this->lastStatusCode >= 429 || $this->lastStatusCode >= 500) {
                $lastError = "HTTP $this->lastStatusCode";
                Logger::warning("HTTP retryable error: $this->lastStatusCode for $url");
                $attempt++;
                continue;
            }

            throw new BaladRequestException("HTTP request failed with status $this->lastStatusCode for URL: $url");
        }

        Logger::error("HTTP request failed after $attempt attempts", ['url' => $url, 'error' => $lastError]);
        throw new BaladRequestException("HTTP request failed after $attempt attempts: $lastError");
    }
}