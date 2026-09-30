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
            $result['error'] = 'آماده‌سازی کوئری ذخیره‌سازی ناموفق بود: ' . $e->getMessage();

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
            $result['error'] = 'ذخیره‌سازی در دیتابیس ناموفق بود: ' . $e->getMessage();

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
}
