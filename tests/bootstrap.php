<?php

// Dependency-free test bootstrap; no Divar credentials or live database needed.
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Src\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true) .
            ', got ' . var_export($actual, true));
    }
}

/** Records SQL and transaction behavior without connecting to production data. */
final class RecordingPDO extends PDO
{
    public array $executions = [];
    public array $events = [];
    public string $contactId = '42';
    public ?string $failOn = null;
    private bool $transaction = false;

    public function __construct() {}

    public function beginTransaction(): bool
    {
        $this->events[] = 'begin';
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        if ($this->failOn === 'commit') {
            throw new PDOException('Simulated commit failure');
        }
        $this->events[] = 'commit';
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->events[] = 'rollback';
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool { return $this->transaction; }
    public function lastInsertId(?string $name = null): string|false { return $this->contactId; }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RecordingStatement($this, $query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $statement = $this->prepare($query);
        $statement->execute();
        return $statement;
    }

    public function exec(string $statement): int|false
    {
        $this->record($statement, []);
        return 0;
    }

    public function record(string $sql, array $params): void
    {
        $this->executions[] = ['sql' => $sql, 'params' => $params];
        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new PDOException('Simulated SQL failure');
        }
    }
}

final class RecordingStatement extends PDOStatement
{
    public function __construct(private RecordingPDO $pdo, private string $sql) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->record($this->sql, $params ?? []);
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
    public function rowCount(): int { return 1; }
}

function testAd(string $token = 'test-token'): array
{
    return [
        'token' => $token,
        'title' => 'اجاره روزانه آپارتمان',
        'raw' => [
            'title' => 'اجاره روزانه آپارتمان',
            'top_description_text' => 'دو خوابه',
            'middle_description_text' => '۱٬۵۰۰٬۰۰۰ تومان',
            'bottom_description_text' => 'تهران، ونک',
            'image_url' => 'https://example.test/ad.jpg',
        ],
    ];
}

function testContext(): array
{
    return [
        'province' => 'تهران',
        'city' => 'تهران',
        'city_slug' => 'tehran',
        'divar_city_id' => '1',
        'category' => 'temporary-rent',
        'query' => 'آپارتمان',
        'page' => 2,
    ];
}
