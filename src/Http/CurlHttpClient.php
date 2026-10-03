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