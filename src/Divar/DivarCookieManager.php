<?php

namespace Src\Divar;

/**
 * DivarCookieManager - Manages Divar authentication cookies in session, file, or memory.
 * Supports both web UI sessions and CLI / cron environments.
 */
final class DivarCookieManager
{
    private const SESSION_KEY = 'divar_cookies';
    private const DEFAULT_STORAGE_FILE = 'storage/divar_cookies.json';

    private static ?array $inMemoryCookies = null;
    private static ?string $customFilePath = null;

    /**
     * Set a custom path for the persistent cookie file.
     */
    public static function setStorageFilePath(?string $path): void
    {
        self::$customFilePath = $path;
    }

    /**
     * Get the path to the persistent cookie file.
     */
    public static function getStorageFilePath(): string
    {
        if (self::$customFilePath !== null) {
            return self::$customFilePath;
        }

        $projectRoot = dirname(__DIR__, 2);
        return $projectRoot . '/' . self::DEFAULT_STORAGE_FILE;
    }

    /**
     * Store Divar cookies in memory, session (if active), and persistent file.
     *
     * @param array $cookies Associative array of cookie name => value
     * @return void
     */
    public static function setCookies(array $cookies): void
    {
        self::$inMemoryCookies = $cookies;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_KEY] = $cookies;
        }

        self::saveToFile(self::getStorageFilePath(), $cookies);
    }

    /**
     * Retrieve Divar cookies from memory, session, file, or environment.
     *
     * @return array Associative array of cookie name => value
     */
    public static function getCookies(): array
    {
        // 1. In-memory cache
        if (self::$inMemoryCookies !== null && !empty(self::$inMemoryCookies)) {
            return self::$inMemoryCookies;
        }

        // 2. Session (web environment)
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION[self::SESSION_KEY])) {
            self::$inMemoryCookies = $_SESSION[self::SESSION_KEY];
            return self::$inMemoryCookies;
        }

        // 3. Persistent JSON file (CLI / cron / cross-request)
        $filePath = self::getStorageFilePath();
        if (file_exists($filePath)) {
            $cookies = self::loadFromFile($filePath);
            if (!empty($cookies)) {
                self::$inMemoryCookies = $cookies;
                return self::$inMemoryCookies;
            }
        }

        // 4. Environment variable (e.g. DIVAR_COOKIES in .env)
        $envCookies = getenv('DIVAR_COOKIES') ?: ($_ENV['DIVAR_COOKIES'] ?? null);
        if (!empty($envCookies)) {
            $cookies = self::parseCookieString((string) $envCookies);
            if (!empty($cookies)) {
                self::$inMemoryCookies = $cookies;
                return self::$inMemoryCookies;
            }
        }

        return [];
    }

    /**
     * Clear Divar cookies from session, memory, and persistent file.
     *
     * @return void
     */
    public static function clearCookies(): void
    {
        self::$inMemoryCookies = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }

        $filePath = self::getStorageFilePath();
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }

    /**
     * Parse raw cookie header string (e.g., "name1=val1; name2=val2") into array.
     *
     * @param string $cookieString
     * @return array<string,string>
     */
    public static function parseCookieString(string $cookieString): array
    {
        $cookies = [];
        $parts = preg_split('/;\s*/', trim($cookieString));

        if (!is_array($parts)) {
            return [];
        }

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }

            $name = trim(substr($part, 0, $eq));
            $value = trim(substr($part, $eq + 1));
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }

        return $cookies;
    }

    /**
     * Convert cookie array to string suitable for Cookie header.
     *
     * @param array|null $cookies
     * @return string
     */
    public static function getCookieString(?array $cookies = null): string
    {
        $cookies = $cookies ?? self::getCookies();
        $parts = [];

        foreach ($cookies as $name => $value) {
            $parts[] = $name . '=' . $value;
        }

        return implode('; ', $parts);
    }

    /**
     * Save cookie array to JSON file.
     *
     * @param string $path
     * @param array|null $cookies
     * @return bool
     */
    public static function saveToFile(string $path, ?array $cookies = null): bool
    {
        $cookies = $cookies ?? self::$inMemoryCookies ?? [];
        if (empty($cookies)) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $json = json_encode([
            'updated_at' => date('c'),
            'cookies' => $cookies,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return (bool) @file_put_contents($path, $json, LOCK_EX);
    }

    /**
     * Load cookies from JSON file.
     *
     * @param string $path
     * @return array
     */
    public static function loadFromFile(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        return $data['cookies'] ?? $data;
    }

    /**
     * Check if cookies contain valid authentication tokens and are not expired.
     *
     * @param array|null $cookies
     * @return bool
     */
    public static function hasValidAuth(?array $cookies = null): bool
    {
        $cookies = $cookies ?? self::getCookies();
        if (empty($cookies)) {
            return false;
        }

        // Must have at least sAccessToken or sFrontToken
        $hasAccessToken = !empty($cookies['sAccessToken']);
        $hasFrontToken = !empty($cookies['sFrontToken']);

        if (!$hasAccessToken && !$hasFrontToken) {
            return false;
        }

        // Check JWT expiry if access token exists
        if ($hasAccessToken) {
            $expiry = self::extractTokenExpiry((string) $cookies['sAccessToken']);
            if ($expiry !== null && time() >= ($expiry - 60)) {
                return false; // Expired (with 60-second safety margin)
            }
        }

        return true;
    }

    /**
     * Extract expiration timestamp from JWT token.
     *
     * @param string $token
     * @return int|null
     */
    public static function extractTokenExpiry(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) < 2) {
            return null;
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'));
        if ($payloadJson === false) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        return isset($payload['exp']) ? (int) $payload['exp'] : null;
    }
}
