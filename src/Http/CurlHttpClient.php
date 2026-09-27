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
}
