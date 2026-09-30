<?php

namespace Src\Support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Creates the application tables when they are missing.
 *
 * The single source of truth for the schema is `database/schema.sql`.
 * This class only reads that file and executes the statements it contains,
 * so a DBA and the application always see exactly the same schema.
 *
 * Everything is idempotent (CREATE TABLE IF NOT EXISTS), therefore it is
 * cheap and safe to call on every request.
 */
final class Schema
{
    /**
     * MySQL error code for "Specified key was too long; max key length is N bytes".
     *
     * It happens on old InnoDB setups (COMPACT row format / no large prefix)
     * when indexing a VARCHAR(500) utf8mb4 column.
     */
    private const ER_TOO_LONG_KEY = 1071;

    /**
     * Absolute path of the schema file.
     */
    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/database/schema.sql';
    }

    /**
     * Make sure every table of database/schema.sql exists.
     *
     * @return string[] human readable names of the executed statements
     * @throws RuntimeException when the schema file is missing or a statement fails
     */
    public static function ensureTables(): array
    {
        $path = self::file();

        if (!is_file($path)) {
            throw new RuntimeException(
                'Schema file not found: ' . $path
            );
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException(
                'Schema file is not readable: ' . $path
            );
        }

        $statements = self::splitStatements($sql);

        if ($statements === []) {
            throw new RuntimeException(
                'Schema file contains no SQL statements: ' . $path
            );
        }

        $pdo = Db::getConnection();

        $existing = self::existingTables($pdo);

        $executed = [];

        foreach ($statements as $statement) {
            $label = self::statementLabel($statement);

            $table = self::tableName($statement);

            /*
             * Skip tables that are already there. This keeps the collector
             * working with a database user that has no CREATE privilege,
             * as long as the schema was created once.
             */
            if ($table !== null && in_array($table, $existing, true)) {
                continue;
            }

            try {
                $pdo->exec($statement);

                $executed[] = $label;

                continue;
            } catch (PDOException $e) {
                /*
                 * Old MySQL/MariaDB installations refuse a full length index
                 * on a utf8mb4 VARCHAR(500) column.
                 *
                 * Retry once with a prefix index instead of failing the whole
                 * request: the table is still created and still usable.
                 */
                $fallback = self::keyTooLongFallback($statement, $e);

                if ($fallback !== null) {
                    try {
                        $pdo->exec($fallback);

                        $executed[] = $label . ' (prefix index)';

                        Logger::warning(
                            'Schema statement needed a prefix index fallback',
                            [
                                'statement' => $label,
                                'error' => $e->getMessage(),
                            ]
                        );

                        continue;
                    } catch (Throwable $fallbackError) {
                        throw new RuntimeException(
                            'Schema migration failed for ' . $label . ': ' .
                            $fallbackError->getMessage(),
                            0,
                            $fallbackError
                        );
                    }
                }

                throw new RuntimeException(
                    'Schema migration failed for ' . $label . ': ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        return $executed;
    }

    /**
     * Tables that already exist in the connected database.
     *
     * @return string[]
     */
    private static function existingTables(PDO $pdo): array
    {
        try {
            $statement = $pdo->query('SHOW TABLES');

            if ($statement === false) {
                return [];
            }

            $tables = $statement->fetchAll(\PDO::FETCH_COLUMN);

            return is_array($tables) ? array_map('strval', $tables) : [];
        } catch (Throwable $e) {
            Logger::warning(
                'Could not list existing tables',
                ['error' => $e->getMessage()]
            );

            return [];
        }
    }

    /**
     * Table name of a CREATE TABLE statement.
     */
    private static function tableName(string $statement): ?string
    {
        if (
            preg_match(
                '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?/i',
                $statement,
                $matches
            ) === 1
        ) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Split a .sql file into single statements.
     *
     * Only "--"/"#" comment lines and empty lines are removed, then the file is
     * split on ";". This is enough for plain DDL files (no stored procedures,
     * no DELIMITER changes).
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $sql);

        if ($lines === false) {
            $lines = [$sql];
        }

        $kept = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                continue;
            }

            if (strncmp($trimmed, '--', 2) === 0) {
                continue;
            }

            if (strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            $kept[] = $line;
        }

        $parts = explode(';', implode("\n", $kept));

        $statements = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $statements[] = $part;
        }

        return $statements;
    }

    /**
     * Short description of a statement, used for logs and error messages.
     */
    private static function statementLabel(string $statement): string
    {
        $table = self::tableName($statement);

        if ($table !== null) {
            return 'CREATE TABLE ' . $table;
        }

        return Str::limit(preg_replace('/\s+/', ' ', $statement) ?? $statement, 60);
    }

    /**
     * Build a variant of the statement that uses prefix indexes, but only when
     * the original error really was "key too long".
     */
    private static function keyTooLongFallback(string $statement, PDOException $e): ?string
    {
        if (!self::isKeyTooLongError($e)) {
            return null;
        }

        $fallback = preg_replace(
            '/INDEX\s+([A-Za-z0-9_]+)\s*\(\s*(title|description|address|url)\s*\)/i',
            'INDEX $1 ($2(191))',
            $statement
        );

        if (!is_string($fallback) || $fallback === $statement) {
            return null;
        }

        return $fallback;
    }

    private static function isKeyTooLongError(PDOException $e): bool
    {
        $code = $e->errorInfo[1] ?? null;

        if ((int) $code === self::ER_TOO_LONG_KEY) {
            return true;
        }

        return stripos($e->getMessage(), 'key was too long') !== false;
    }
}
