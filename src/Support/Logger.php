<?php

namespace Src\Support;

final class Logger
{
    private static string $logPath = '';

    public static function setPath(string $path): void
    {
        self::$logPath = self::resolvePath($path);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('DEBUG', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    /**
     * Write a development issue to a dedicated file under storage/logs
     * (and also to app.log). Cookie/token values are stripped.
     */
    public static function issue(string $channel, string $level, string $message, array $context = []): void
    {
        $context = self::redact($context);
        $directory = self::directory();
        $safeChannel = preg_replace('/[^A-Za-z0-9._-]/', '_', $channel) ?: 'app';

        self::write($directory . '/' . $safeChannel . '.log', $level, $message, $context);
        self::write($directory . '/app.log', $level, '[' . $safeChannel . '] ' . $message, $context);
    }

    public static function directory(): string
    {
        if (self::$logPath === '') {
            self::$logPath = self::resolvePath('storage/logs');
        }

        return self::$logPath;
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        self::write(self::directory() . '/app.log', $level, $message, self::redact($context));
    }

    /** @param array<string,mixed> $context */
    private static function write(string $file, string $level, string $message, array $context): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . $message;

        if ($context !== []) {
            $encoded = json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
            $line .= ' ' . ($encoded === false ? '{"encode_error":true}' : $encoded);
        }

        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private static function resolvePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            $path = 'storage/logs';
        }

        if ($path[0] === '/' || preg_match('#^[A-Za-z]:/#', $path) === 1) {
            return rtrim($path, '/');
        }

        return dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function redact(array $context): array
    {
        $blocked = [
            'cookie', 'cookies', 'authorization', 'set-cookie', 'set_cookie',
            'saccesstoken', 'sfronttoken', 'password', 'token_value',
        ];
        $clean = [];

        foreach ($context as $key => $value) {
            $name = strtolower((string) $key);

            if (in_array($name, $blocked, true) || str_contains($name, 'cookie')) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_string($value) && strlen($value) > 4000) {
                $clean[$key] = substr($value, 0, 4000) . '…';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }
}
