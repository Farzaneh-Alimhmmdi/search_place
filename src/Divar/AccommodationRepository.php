<?php

namespace Src\Divar;

use PDO;
use PDOException;
use PDOStatement;
use Src\Support\Db;
use Src\Support\Logger;
use Throwable;

/**
 * Writes collected ads into the `accommodations` table.
 *
 * Design notes for very large harvests:
 *
 *  - Rows are written in small batches (one Divar page = ~24 rows) inside a
 *    single transaction, so the DB round trips stay low and a crash never
 *    leaves a half written batch behind.
 *  - INSERT ... ON DUPLICATE KEY UPDATE on (provider, external_id) makes every
 *    write idempotent: re-running or resuming a harvest can never create
 *    duplicates.
 *  - Nothing is accumulated in PHP memory between batches.
 */
final class AccommodationRepository
{
    /**
     * Columns written by the upsert, in SQL order.
     */
    private const COLUMNS = [
        'contact_id',
        'title',
        'description',
        'province',
        'city',
        'category',
        'address',
        'latitude',
        'longitude',
        'price',
        'provider',
        'external_id',
        'url',
        'provider_data',
        'raw_data',
    ];

    /**
     * MySQL error numbers that mean "the table/column is not there".
     *
     * 1054 unknown column, 1146 table does not exist, 1091 unknown column in
     * a clause, 1072 key column does not exist.
     */
    private const SCHEMA_ERROR_CODES = [1054, 1146, 1091, 1072];

    private const SCHEMA_ERROR_STATES = ['42S22', '42S02'];

    private const SCHEMA_HINT =
        ' — ساختار جدول با database/schema.sql یکی نیست. صفحه را یک بار دیگر ' .
        'بارگذاری کنید تا ستون/کلید جامانده خودکار اضافه شود، یا schema.sql را ' .
        'دستی روی دیتابیس اجرا کنید.';

    private PDO $pdo;

    private ?PDOStatement $upsertStatement = null;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::getConnection();
    }

    /**
     * Insert or update a batch of mapped rows.
     *
     * @param array<int,array<string,mixed>> $rows output of DivarAdMapper::toRows()
     * @return array{
     *     ok: bool,
     *     error: string|null,
     *     inserted: int,
     *     updated: int,
     *     unchanged: int,
     *     failed: int,
     *     failed_ids: string[]
     * }
     */
    public function upsertMany(array $rows): array
    {
        $result = [
            'ok' => true,
            'retry' => true,
            'error' => null,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'failed_ids' => [],
        ];

        if ($rows === []) {
            return $result;
        }

        try {
            $statement = $this->upsertStatement();
        } catch (Throwable $e) {
            $result['ok'] = false;
            $result['retry'] = !$this->isSchemaError($e);
            $result['error'] = 'آماده‌سازی کوئری ذخیره‌سازی ناموفق بود: ' . $e->getMessage() .
                ($this->isSchemaError($e) ? self::SCHEMA_HINT : '');

            return $result;
        }

        $inTransaction = false;

        try {
            $this->pdo->beginTransaction();

            $inTransaction = true;

            foreach ($rows as $row) {
                $externalId = (string) ($row['external_id'] ?? '');

                try {
                    $statement->execute($this->bindValues($row));
                } catch (PDOException $e) {
                    /*
                     * One broken row must not kill the whole batch.
                     *
                     * MySQL keeps the transaction usable after a statement
                     * level error, so we simply count the failure and continue.
                     */
                    $result['failed']++;

                    if (count($result['failed_ids']) < 20) {
                        $result['failed_ids'][] = $externalId;
                    }

                    Logger::warning(
                        'Accommodation row rejected by MySQL',
                        [
                            'external_id' => $externalId,
                            'error' => $e->getMessage(),
                        ]
                    );

                    continue;
                }

                $affected = $statement->rowCount();

                if ($affected === 1) {
                    $result['inserted']++;
                } elseif ($affected >= 2) {
                    $result['updated']++;
                } else {
                    $result['unchanged']++;
                }
            }

            $this->pdo->commit();

            $inTransaction = false;
        } catch (Throwable $e) {
            if ($inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $result['ok'] = false;
            $result['retry'] = !$this->isSchemaError($e);
            $result['error'] = 'ذخیره‌سازی در دیتابیس ناموفق بود: ' . $e->getMessage() .
                ($this->isSchemaError($e) ? self::SCHEMA_HINT : '');

            Logger::error(
                'Accommodation batch failed',
                [
                    'error' => $e->getMessage(),
                    'rows' => count($rows),
                ]
            );
        }

        return $result;
    }

    /**
     * Total number of stored rows of one provider.
     *
     * Uses the (provider, external_id) unique index, so it stays an index only
     * scan even for very large tables.
     */
    public function countByProvider(string $provider = DivarAdMapper::PROVIDER): int
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM accommodations WHERE provider = ?'
            );

            $statement->execute([$provider]);

            return (int) $statement->fetchColumn();
        } catch (Throwable $e) {
            Logger::warning(
                'Could not count accommodations',
                ['error' => $e->getMessage()]
            );

            return 0;
        }
    }

    /**
     * Rows of one city/category already stored - used to show a resume hint.
     */
    public function countByContext(
        string $provider,
        ?string $province,
        ?string $city,
        ?string $category
    ): int {
        $sql = 'SELECT COUNT(*) FROM accommodations WHERE provider = ?';

        $params = [$provider];

        if ($province !== null && $province !== '') {
            $sql .= ' AND province = ?';
            $params[] = $province;
        }

        if ($city !== null && $city !== '') {
            $sql .= ' AND city = ?';
            $params[] = $city;
        }

        if ($category !== null && $category !== '') {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }

        try {
            $statement = $this->pdo->prepare($sql);

            $statement->execute($params);

            return (int) $statement->fetchColumn();
        } catch (Throwable $e) {
            Logger::warning(
                'Could not count accommodations by context',
                ['error' => $e->getMessage()]
            );

            return 0;
        }
    }

    /**
     * Is this error caused by a wrong/incomplete table structure?
     *
     * Such an error never fixes itself, so the harvest must stop instead of
     * retrying the same step forever.
     */
    private function isSchemaError(Throwable $e): bool
    {
        $errno = (int) ($e->errorInfo[1] ?? 0);

        if (in_array($errno, self::SCHEMA_ERROR_CODES, true)) {
            return true;
        }

        return in_array(strtoupper((string) $e->getCode()), self::SCHEMA_ERROR_STATES, true);
    }

    /**
     * Prepared (and therefore cached) upsert statement.
     */
    private function upsertStatement(): PDOStatement
    {
        if ($this->upsertStatement instanceof PDOStatement) {
            return $this->upsertStatement;
        }

        $columns = implode(', ', self::COLUMNS);

        $placeholders = implode(', ', array_fill(0, count(self::COLUMNS), '?'));

        /*
         * contact_id / latitude / longitude / price keep their old value when
         * the new one is NULL: a later step (phone numbers, geocoding) must not
         * be erased by a re-run of the collector.
         */
        $updates = [
            'contact_id = COALESCE(VALUES(contact_id), contact_id)',
            'title = VALUES(title)',
            'description = COALESCE(VALUES(description), description)',
            'province = COALESCE(VALUES(province), province)',
            'city = COALESCE(VALUES(city), city)',
            'category = COALESCE(VALUES(category), category)',
            'address = COALESCE(VALUES(address), address)',
            'latitude = COALESCE(VALUES(latitude), latitude)',
            'longitude = COALESCE(VALUES(longitude), longitude)',
            'price = COALESCE(VALUES(price), price)',
            'url = COALESCE(VALUES(url), url)',
            'provider_data = COALESCE(VALUES(provider_data), provider_data)',
            'raw_data = COALESCE(VALUES(raw_data), raw_data)',
        ];

        $sql = 'INSERT INTO accommodations (' . $columns . ') ' .
            'VALUES (' . $placeholders . ') ' .
            'ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

        $this->upsertStatement = $this->pdo->prepare($sql);

        return $this->upsertStatement;
    }

    /**
     * Convert a mapped row into an ordered list of bind values.
     *
     * @param array<string,mixed> $row
     * @return array<int,mixed>
     */
    private function bindValues(array $row): array
    {
        $values = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;

            if ($value === null) {
                $values[] = null;

                continue;
            }

            if ($column === 'latitude' || $column === 'longitude' || $column === 'price') {
                $values[] = is_numeric($value) ? (float) $value : null;

                continue;
            }

            if ($column === 'contact_id') {
                $values[] = is_numeric($value) ? (int) $value : null;

                continue;
            }

            if (is_scalar($value)) {
                $values[] = (string) $value;

                continue;
            }

            /*
             * Anything unexpected (array/object) is stored as JSON instead of
             * breaking the statement.
             */
            $values[] = DivarAdMapper::encodeJson($value);
        }

        return $values;
    }

    /**
     * Find accommodations that do not have a contact phone number yet.
     *
     * @param int $limit Max records to return
     * @param string|null $city Optional city filter
     * @param bool $retryFailed Whether to retry items previously marked as failed/no_phone
     * @param int $maxAttempts Max attempts before an ad is excluded
     * @return array<int,array<string,mixed>>
     */
    public function findPendingPhoneAccommodations(
        int $limit = 20,
        ?string $city = null,
        bool $retryFailed = false,
        int $maxAttempts = 3
    ): array {
        $sql = 'SELECT id, provider, external_id, title, city, province, category, provider_data, created_at ' .
               'FROM accommodations ' .
               'WHERE provider = ? ' .
               'AND contact_id IS NULL ';

        $params = [DivarAdMapper::PROVIDER];

        if ($city !== null && $city !== '') {
            $sql .= 'AND (city = ? OR province = ?) ';
            $params[] = $city;
            $params[] = $city;
        }

        if (!$retryFailed) {
            // Exclude already processed statuses like 'found', 'no_phone', 'expired', 'chat_only'
            // and enforce max attempts
            $sql .= 'AND (
                provider_data IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(provider_data, "$.contact_status")) IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(provider_data, "$.contact_status")) IN ("pending", "retry")
            ) ';
            $sql .= 'AND (
                provider_data IS NULL
                OR COALESCE(JSON_EXTRACT(provider_data, "$.contact_attempts"), 0) < ?
            ) ';
            $params[] = $maxAttempts;
        } else {
            // Include everything except already found
            $sql .= 'AND (
                provider_data IS NULL
                OR JSON_UNQUOTE(JSON_EXTRACT(provider_data, "$.contact_status")) != "found"
            ) ';
        }

        $sql .= 'ORDER BY id ASC LIMIT ' . (int) $limit;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            Logger::error('Failed to query pending phone accommodations', [
                'error' => $e->getMessage(),
                'sql' => $sql,
            ]);
            return [];
        }
    }

    /**
     * Count divar accommodations that do not have a contact_id.
     */
    public function countPendingPhone(?string $city = null): int
    {
        $sql = 'SELECT COUNT(*) FROM accommodations WHERE provider = ? AND contact_id IS NULL';
        $params = [DivarAdMapper::PROVIDER];

        if ($city !== null && $city !== '') {
            $sql .= ' AND (city = ? OR province = ?)';
            $params[] = $city;
            $params[] = $city;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            Logger::warning('Could not count pending phone accommodations', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Count divar accommodations that have a linked contact.
     */
    public function countWithPhone(?string $city = null): int
    {
        $sql = 'SELECT COUNT(*) FROM accommodations WHERE provider = ? AND contact_id IS NOT NULL';
        $params = [DivarAdMapper::PROVIDER];

        if ($city !== null && $city !== '') {
            $sql .= ' AND (city = ? OR province = ?)';
            $params[] = $city;
            $params[] = $city;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            Logger::warning('Could not count accommodations with phone', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Find existing contact by phone or create a new one.
     * Guaranteed safe against unique constraint collisions.
     *
     * @param string $phone Sanitized phone number (e.g. 09123456789)
     * @param string|null $name Optional contact name / ad title
     * @param string|null $notes Optional notes
     * @return int Contact ID
     */
    public function findOrCreateContact(string $phone, ?string $name = null, ?string $notes = null): int
    {
        // 1. Check if contact exists
        $findStmt = $this->pdo->prepare('SELECT id FROM contacts WHERE phone = ? LIMIT 1');
        $findStmt->execute([$phone]);
        $existingId = $findStmt->fetchColumn();

        if ($existingId !== false && $existingId !== null) {
            return (int) $existingId;
        }

        // 2. Insert new contact, safely handling concurrency with ON DUPLICATE KEY UPDATE
        $insertSql = 'INSERT INTO contacts (phone, name, notes, created_at, updated_at) ' .
                     'VALUES (?, ?, ?, NOW(), NOW()) ' .
                     'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = NOW()';

        $insertStmt = $this->pdo->prepare($insertSql);
        $insertStmt->execute([$phone, $name, $notes]);

        $lastId = (int) $this->pdo->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }

        // Fallback: re-select in case LAST_INSERT_ID did not return it
        $findStmt->execute([$phone]);
        return (int) $findStmt->fetchColumn();
    }

    /**
     * Link a contact to an accommodation and update provider_data with found status.
     */
    public function linkContactToAccommodation(int $accommodationId, int $contactId, string $phone): bool
    {
        $sql = 'UPDATE accommodations SET ' .
               'contact_id = :contact_id, ' .
               'provider_data = JSON_SET(' .
                   'COALESCE(provider_data, "{}"), ' .
                   '"$.contact_status", "found", ' .
                   '"$.contact_phone", :phone, ' .
                   '"$.contact_fetched_at", :fetched_at' .
               '), ' .
               'updated_at = NOW() ' .
               'WHERE id = :id';

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                'contact_id' => $contactId,
                'phone' => $phone,
                'fetched_at' => date('c'),
                'id' => $accommodationId,
            ]);
        } catch (Throwable $e) {
            Logger::error('Failed to link contact to accommodation', [
                'accommodation_id' => $accommodationId,
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Update accommodation's provider_data status when phone could not be fetched
     * (e.g. no phone in ad, chat only, expired/deleted ad, rate limited, error).
     */
    public function markAccommodationContactStatus(
        int $accommodationId,
        string $status,
        ?string $error = null
    ): bool {
        $sql = 'UPDATE accommodations SET ' .
               'provider_data = JSON_SET(' .
                   'COALESCE(provider_data, "{}"), ' .
                   '"$.contact_status", :status, ' .
                   '"$.contact_error", :error, ' .
                   '"$.contact_attempted_at", :attempted_at, ' .
                   '"$.contact_attempts", COALESCE(JSON_EXTRACT(provider_data, "$.contact_attempts"), 0) + 1' .
               '), ' .
               'updated_at = NOW() ' .
               'WHERE id = :id';

        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                'status' => $status,
                'error' => $error,
                'attempted_at' => date('c'),
                'id' => $accommodationId,
            ]);
        } catch (Throwable $e) {
            Logger::error('Failed to update accommodation contact status', [
                'accommodation_id' => $accommodationId,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Reset contact status of accommodations previously marked as failed or no_phone,
     * so they can be re-evaluated.
     */
    public function resetFailedContactStatuses(?string $city = null): int
    {
        $sql = 'UPDATE accommodations SET ' .
               'provider_data = JSON_SET(' .
                   'COALESCE(provider_data, "{}"), ' .
                   '"$.contact_status", "retry", ' .
                   '"$.contact_attempts", 0' .
               ') ' .
               'WHERE provider = ? ' .
               'AND contact_id IS NULL ';

        $params = [DivarAdMapper::PROVIDER];

        if ($city !== null && $city !== '') {
            $sql .= 'AND (city = ? OR province = ?) ';
            $params[] = $city;
            $params[] = $city;
        }

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            Logger::error('Failed to reset failed contact statuses', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}