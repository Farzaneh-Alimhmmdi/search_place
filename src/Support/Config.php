<?php

namespace Src\Support;

final class Config
{
    private static array $data = [];

    public static function load(string $path): void
    {
        if (file_exists($path)) {
            self::$data = require $path;
        }
    }

    public static function get(string $key, $default = null)
    {
        return self::$data[$key] ?? $default;
    }
}
