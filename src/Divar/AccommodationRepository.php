<?php

namespace Src\Divar;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\Str;
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
     * Save an ad after fetching its phone, or update only its contact link.
     *
     * Contacts are unique by phone and may be shared by multiple listings. A
     * changed phone therefore links the ad to the matching/new contact instead
     * of changing the old contact's phone (and affecting other ads).
     *
     * Both writes are atomic: a failed ad insert must not leave an orphaned
     * contact behind. Existing listing details and contact names/notes stay
     * untouched.
     *
     * @param array<string,mixed> $row output of DivarAdMapper::toRow()
     * @return int the linked contact ID
     * @throws Throwable when either write fails
     */
    public function upsertWithContact(array $row, string $phone): int
    {
        $phone = Str::normalizeNumbers(trim($phone));
        $phone = (string) preg_replace('/[\s()\-]+/u', '', $phone);

        // Use the same local form for Iranian numbers returned with +98/0098.
        $phone = (string) preg_replace('/^(?:\+98|0098|98)([1-9][0-9]{9})$/D', '0$1', $phone);

        if (preg_match('/^\+?[0-9]{7,15}$/D', $phone) !== 1) {
            throw new InvalidArgumentException('Invalid phone number.');
        }

        if (empty($row['provider']) || empty($row['external_id'])) {
            throw new InvalidArgumentException('Provider and external ID are required.');
        }

        $this->pdo->beginTransaction();

        try {
            $contact = $this->pdo->prepare(
                'INSERT INTO contacts (phone) VALUES (?) ' .
                'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $contact->execute([$phone]);

            // LAST_INSERT_ID(id) also returns the existing ID on a duplicate.
            $contactId = (int) $this->pdo->lastInsertId();
            $row['contact_id'] = $contactId;

            $columns = implode(', ', self::COLUMNS);
            $placeholders = implode(', ', array_fill(0, count(self::COLUMNS), '?'));
            $ad = $this->pdo->prepare(
                'INSERT INTO accommodations (' . $columns . ') ' .
                'VALUES (' . $placeholders . ') ' .
                'ON DUPLICATE KEY UPDATE contact_id = VALUES(contact_id)'
            );
            $ad->execute($this->bindValues($row));

            $this->pdo->commit();

            return $contactId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Stored phone for one listing, if that ad was already saved with a contact.
     *
     * Lookup path:
     *   UNIQUE uq_provider_external_id (provider, external_id)
     *     -> accommodations.contact_id (idx_accommodations_contact_id)
     *     -> contacts.id (PRIMARY KEY) / contacts.phone (uq_contacts_phone)
     *
     * That is already an index nested-loop join, so a second
     * (provider, external_id) index would only duplicate the unique key.
     */
    public function findStoredPhone(string $provider, string $externalId): ?string
    {
        $externalId = trim($externalId);

        if ($provider === '' || $externalId === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            'SELECT c.phone FROM accommodations a ' .
            'INNER JOIN contacts c ON c.id = a.contact_id ' .
            'WHERE a.provider = ? AND a.external_id = ? ' .
            "AND c.phone IS NOT NULL AND c.phone != '' " .
            'LIMIT 1'
        );
        $statement->execute([$provider, $externalId]);
        $phone = $statement->fetchColumn();

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * Stored listings for the current result page, including contact phones.
     *
     * The IN list is the page's tokens (typically 24). MySQL uses
     * uq_provider_external_id for that filter; phones come from the contact
     * join above. Chunking keeps the placeholder list bounded.
     *
     * @param string[] $externalIds
     * @return array<string, array{phone: ?string}>
     */
    public function findStoredByExternalIds(string $provider, array $externalIds): array
    {
        $ids = [];

        foreach ($externalIds as $id) {
            if (!is_string($id)) {
                continue;
            }

            $id = trim($id);

            if ($id !== '') {
                $ids[$id] = $id;
            }
        }

        if ($provider === '' || $ids === []) {
            return [];
        }

        $stored = [];

        foreach (array_chunk(array_values($ids), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT a.external_id, c.phone ' .
                'FROM accommodations a ' .
                'LEFT JOIN contacts c ON c.id = a.contact_id ' .
                'WHERE a.provider = ? AND a.external_id IN (' . $placeholders . ')'
            );
            $statement->execute(array_merge([$provider], $chunk));

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = (string) ($row['external_id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $phone = $row['phone'] ?? null;
                $stored[$id] = [
                    'phone' => is_string($phone) && $phone !== '' ? $phone : null,
                ];
            }
        }

        return $stored;
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
}