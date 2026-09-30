<?php

namespace Src\Support;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Makes the database match `database/schema.sql`.
 *
 * The single source of truth for the schema is that file; this class parses it
 * and applies whatever is missing.
 *
 * IMPORTANT: `CREATE TABLE IF NOT EXISTS` alone is NOT enough. When the table
 * already exists - for example because it was created by hand from an older
 * draft - MySQL silently does nothing and the application then dies with
 * "Unknown column 'provider_data' in 'field list'".
 *
 * Therefore this class also upgrades existing tables:
 *
 *   - missing columns are added with ALTER TABLE ... ADD COLUMN
 *   - missing indexes / unique keys / foreign keys are added
 *
 * Both operations are non destructive: nothing is ever dropped, renamed or
 * narrowed, so running this on a filled database is safe.
 *
 * When a change cannot be applied (no ALTER privilege, duplicate rows blocking
 * a unique key, ...) the exception message contains the EXACT SQL statement to
 * run by hand.
 */
final class Schema
{
    /**
     * Bump this whenever database/schema.sql changes.
     *
     * The collector caches "schema is fine" in the PHP session; a new version
     * invalidates that cache so an updated schema is applied immediately
     * instead of after the user clears their session.
     */
    public const VERSION = '2';

    /**
     * MySQL error code for "Specified key was too long; max key length is N bytes".
     *
     * It happens on old InnoDB setups (COMPACT row format / no large prefix)
     * when indexing a VARCHAR(500) utf8mb4 column.
     */
    private const ER_TOO_LONG_KEY = 1071;

    /**
     * Unique key that makes the collector idempotent. Without it, re-running a
     * harvest would insert duplicates, so failing to create it is fatal.
     */
    private const CRITICAL_KEY = 'uq_provider_external_id';

    /**
     * Absolute path of the schema file.
     */
    public static function file(): string
    {
        return dirname(__DIR__, 2) . '/database/schema.sql';
    }

    /**
     * Create missing tables and upgrade existing ones.
     *
     * @return string[] human readable list of the applied changes
     * @throws RuntimeException when the schema file is missing/unusable or a
     *                          required change could not be applied
     */
    public static function ensureTables(): array
    {
        $statements = self::splitStatements(self::readFile());

        if ($statements === []) {
            throw new RuntimeException(
                'Schema file contains no SQL statements: ' . self::file()
            );
        }

        $pdo = Db::getConnection();

        $existing = self::existingTables($pdo);

        $changes = [];

        foreach ($statements as $statement) {
            $table = self::tableName($statement);

            /*
             * Not a CREATE TABLE statement (or an unparseable one): just run it.
             */
            if ($table === null) {
                $changes[] = self::execute($pdo, $statement, self::statementLabel($statement));

                continue;
            }

            if (!in_array($table, $existing, true)) {
                $changes[] = self::execute($pdo, $statement, 'CREATE TABLE ' . $table);

                $existing[] = $table;

                continue;
            }

            /*
             * The table is already there: bring its columns and keys up to date.
             */
            foreach (self::upgradeTable($pdo, $table, $statement) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    /**
     * Add the columns and keys that an already existing table is missing.
     *
     * @return string[] applied changes and warnings
     */
    private static function upgradeTable(PDO $pdo, string $table, string $createSql): array
    {
        $changes = [];

        $expectedColumns = self::parseColumns($createSql);
        $actualColumns = self::actualColumns($pdo, $table);

        /*
         * Position of the new column: right after the last expected column that
         * already exists, so the layout stays close to schema.sql.
         */
        $previous = null;

        foreach ($expectedColumns as $name => $definition) {
            if (array_key_exists($name, $actualColumns)) {
                self::warnAboutTypeDifference($table, $name, $definition, (string) $actualColumns[$name]);

                $previous = $name;

                continue;
            }

            $position = $previous === null ? ' FIRST' : ' AFTER `' . $previous . '`';

            $sql = 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $name . '` ' .
                $definition . $position;

            $changes[] = self::execute($pdo, $sql, 'ADD COLUMN ' . $table . '.' . $name);

            $previous = $name;
        }

        $actualKeys = self::actualKeyNames($pdo, $table);

        foreach (self::parseKeys($createSql) as $keyName => $clause) {
            if ($keyName === '' || in_array($keyName, $actualKeys, true)) {
                continue;
            }

            $sql = 'ALTER TABLE `' . $table . '` ADD ' . $clause;

            try {
                $changes[] = self::execute($pdo, $sql, 'ADD KEY ' . $table . '.' . $keyName);
            } catch (RuntimeException $e) {
                /*
                 * The unique key of the collector must exist: without it every
                 * re-run would duplicate rows. Anything else (plain index,
                 * foreign key) is only a performance/convenience detail, so a
                 * failure there is reported but does not stop the application.
                 */
                if ($keyName === self::CRITICAL_KEY) {
                    throw $e;
                }

                Logger::warning(
                    'Schema: optional key could not be created',
                    [
                        'table' => $table,
                        'key' => $keyName,
                        'error' => $e->getMessage(),
                    ]
                );

                $changes[] = 'WARNING key ' . $table . '.' . $keyName . ' not created: ' . $e->getMessage();
            }
        }

        return $changes;
    }

    /**
     * Run one statement, with a prefix index fallback for very old InnoDB
     * setups that reject a full length index on a utf8mb4 VARCHAR(500).
     *
     * @return string description of what was executed
     */
    private static function execute(PDO $pdo, string $sql, string $label): string
    {
        try {
            $pdo->exec($sql);

            Logger::info('Schema applied: ' . $label);

            return $label;
        } catch (PDOException $e) {
            $fallback = self::keyTooLongFallback($sql, $e);

            if ($fallback !== null) {
                try {
                    $pdo->exec($fallback);

                    Logger::warning(
                        'Schema applied with a prefix index fallback',
                        ['statement' => $label, 'error' => $e->getMessage()]
                    );

                    return $label . ' (prefix index)';
                } catch (Throwable $fallbackError) {
                    throw self::failure($label, $fallback, $fallbackError);
                }
            }

            throw self::failure($label, $sql, $e);
        }
    }

    /**
     * Error message that tells the administrator exactly what to run by hand.
     */
    private static function failure(string $label, string $sql, Throwable $e): RuntimeException
    {
        Logger::error(
            'Schema change failed',
            [
                'statement' => $label,
                'sql' => $sql,
                'error' => $e->getMessage(),
            ]
        );

        return new RuntimeException(
            $label . ' ناموفق بود: ' . $e->getMessage() .
            ' — اگر دسترسی ALTER ندارید این دستور را دستی در MySQL اجرا کنید: ' . $sql,
            0,
            $e
        );
    }

    // ------------------------------------------------------------------
    // Parsing database/schema.sql
    // ------------------------------------------------------------------

    private static function readFile(): string
    {
        $path = self::file();

        if (!is_file($path)) {
            throw new RuntimeException('Schema file not found: ' . $path);
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException('Schema file is not readable: ' . $path);
        }

        return $sql;
    }

    /**
     * Split a .sql file into single statements.
     *
     * Only "--"/"#" comment lines and empty lines are removed, then the file is
     * split on top level ";". Enough for plain DDL files (no stored procedures,
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

            if ($trimmed === '' || strncmp($trimmed, '--', 2) === 0 || strncmp($trimmed, '#', 1) === 0) {
                continue;
            }

            $kept[] = $line;
        }

        $parts = explode(';', implode("\n", $kept));

        $statements = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '') {
                $statements[] = $part;
            }
        }

        return $statements;
    }

    /**
     * Body of a CREATE TABLE statement: everything between the outer brackets.
     */
    private static function tableBody(string $createSql): string
    {
        $open = strpos($createSql, '(');

        if ($open === false) {
            return '';
        }

        $body = substr($createSql, $open + 1);

        $close = strrpos($body, ')');

        if ($close !== false) {
            $body = substr($body, 0, $close);
        }

        return $body;
    }

    /**
     * Split on commas that are not inside brackets or quotes.
     *
     * @return string[]
     */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;

        $length = strlen($body);

        for ($i = 0; $i < $length; $i++) {
            $char = $body[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $body[++$i];

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;

                continue;
            }

            if ($char === '(') {
                $depth++;
                $current .= $char;

                continue;
            }

            if ($char === ')') {
                $depth--;
                $current .= $char;

                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    /**
     * Columns of a CREATE TABLE statement, in order: name => definition.
     *
     * @return array<string,string>
     */
    public static function parseColumns(string $createSql): array
    {
        $columns = [];

        foreach (self::splitTopLevel(self::tableBody($createSql)) as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));

            if ($part === '' || self::isKeyClause($part)) {
                continue;
            }

            if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+(.+)$/i', $part, $matches) === 1) {
                $columns[$matches[1]] = trim($matches[2]);
            }
        }

        return $columns;
    }

    /**
     * Keys of a CREATE TABLE statement: key name => clause usable in ALTER TABLE ADD.
     *
     * @return array<string,string>
     */
    public static function parseKeys(string $createSql): array
    {
        $keys = [];

        foreach (self::splitTopLevel(self::tableBody($createSql)) as $part) {
            $part = trim((string) preg_replace('/\s+/', ' ', $part));

            if ($part === '' || !self::isKeyClause($part)) {
                continue;
            }

            $name = '';

            if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z0-9_]+)`?/i', $part, $matches) === 1) {
                $name = $matches[1];
            } elseif (preg_match('/^CONSTRAINT\s+`?([A-Za-z0-9_]+)`?/i', $part, $matches) === 1) {
                $name = $matches[1];
            } elseif (preg_match('/^PRIMARY\s+KEY/i', $part) === 1) {
                $name = 'PRIMARY';
            }

            $keys[$name] = $part;
        }

        return $keys;
    }

    private static function isKeyClause(string $part): bool
    {
        return preg_match(
                '/^(PRIMARY\s+KEY|UNIQUE(\s+KEY|\s+INDEX)?|KEY|INDEX|FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN\s+KEY|CHECK)\b/i',
                $part
            ) === 1;
    }

    // ------------------------------------------------------------------
    // Reading the live database
    // ------------------------------------------------------------------

    /**
     * @return string[]
     */
    private static function existingTables(PDO $pdo): array
    {
        try {
            $statement = $pdo->query('SHOW TABLES');

            if ($statement === false) {
                return [];
            }

            $tables = $statement->fetchAll(PDO::FETCH_COLUMN);

            return is_array($tables) ? array_map('strval', $tables) : [];
        } catch (Throwable $e) {
            Logger::warning('Schema: could not list tables', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return array<string,string> column name => reported type
     */
    private static function actualColumns(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');

        if ($statement === false) {
            return [];
        }

        $columns = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($row['Field'])) {
                $columns[(string) $row['Field']] = strtolower((string) ($row['Type'] ?? ''));
            }
        }

        return $columns;
    }

    /**
     * @return string[]
     */
    private static function actualKeyNames(PDO $pdo, string $table): array
    {
        try {
            $statement = $pdo->query('SHOW INDEX FROM `' . $table . '`');

            if ($statement === false) {
                return [];
            }

            $names = [];

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (isset($row['Key_name'])) {
                    $names[] = (string) $row['Key_name'];
                }
            }

            return array_values(array_unique($names));
        } catch (Throwable $e) {
            Logger::warning(
                'Schema: could not list indexes',
                ['table' => $table, 'error' => $e->getMessage()]
            );

            return [];
        }
    }

    /**
     * A column that exists with a different type is not touched automatically
     * (that could destroy data), but it is worth a log entry.
     */
    private static function warnAboutTypeDifference(
        string $table,
        string $column,
        string $expectedDefinition,
        string $actualType
    ): void {
        $expectedType = self::leadingType($expectedDefinition);

        if ($expectedType === '' || $actualType === '') {
            return;
        }

        if ($expectedType === $actualType) {
            return;
        }

        Logger::warning(
            'Schema: column type differs from database/schema.sql',
            [
                'table' => $table,
                'column' => $column,
                'expected' => $expectedType,
                'actual' => $actualType,
            ]
        );
    }

    /**
     * Type part of a column definition: "VARCHAR(500) NOT NULL" -> "varchar(500)".
     */
    private static function leadingType(string $definition): string
    {
        $cut = preg_split(
            '/\s+(?:NOT\s+NULL|NULL|DEFAULT|AUTO_INCREMENT|PRIMARY|UNIQUE|COMMENT|ON|REFERENCES|CHECK|GENERATED|COLLATE|CHARACTER)\b/i',
            trim($definition),
            2
        );

        $type = is_array($cut) && isset($cut[0]) ? $cut[0] : $definition;

        return strtolower(str_replace(' ', '', trim($type)));
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

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

    private static function statementLabel(string $statement): string
    {
        $table = self::tableName($statement);

        if ($table !== null) {
            return 'CREATE TABLE ' . $table;
        }

        return Str::limit((string) preg_replace('/\s+/', ' ', $statement), 60);
    }

    /**
     * Prefix index variant of a statement, but only when the error really was
     * "key too long".
     */
    private static function keyTooLongFallback(string $statement, PDOException $e): ?string
    {
        if (!self::isKeyTooLongError($e)) {
            return null;
        }

        $fallback = preg_replace(
            '/(INDEX|KEY)\s+([A-Za-z0-9_]+)\s*\(\s*(title|description|address|url)\s*\)/i',
            '$1 $2 ($3(191))',
            $statement
        );

        if (!is_string($fallback) || $fallback === $statement) {
            return null;
        }

        return $fallback;
    }

    private static function isKeyTooLongError(PDOException $e): bool
    {
        if ((int) ($e->errorInfo[1] ?? 0) === self::ER_TOO_LONG_KEY) {
            return true;
        }

        return stripos($e->getMessage(), 'key was too long') !== false;
    }
}