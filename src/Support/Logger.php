<?php

namespace Src\Support;

final class Logger
{
    private static string $logPath = 'storage/logs';

    public static function setPath(string $path): void { self::$logPath = $path; }
    public static function debug(string $message, array $context = []): void { self::log('DEBUG', $message, $context); }
    public static function info(string $message, array $context = []): void { self::log('INFO', $message, $context); }
    public static function warning(string $message, array $context = []): void { self::log('WARNING', $message, $context); }
    public static function error(string $message, array $context = []): void { self::log('ERROR', $message, $context); }

    private static function log(string $level, string $message, array $context = []): void
    {
        if (!is_dir(self::$logPath)) mkdir(self::$logPath, 0755, true);
        file_put_contents(self::$logPath . '/app.log', "[" . date('Y-m-d H:i:s') . "] [$level] $message" . (empty($context) ? '' : ' ' . json_encode($context)) . "\n", FILE_APPEND | LOCK_EX);
    }
}
