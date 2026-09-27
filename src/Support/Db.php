<?php

namespace Src\Support;

use PDO;

final class Db
{
    private static ?PDO $connection = null;

    public static function connect(string $host, int $port, string $database, string $username, string $password): void
    {
        $dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            self::$connection = new PDO($dsn, $username, $password, $options);
        } catch (\PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getConnection(): \PDO
    {
        if (self::$connection === null) {
            throw new \RuntimeException("Database not connected. Call Db::connect() first.");
        }
        return self::$connection;
    }

    public static function disconnect(): void
    {
        if (self::$connection !== null) {
            self::$connection = null;
        }
    }
}