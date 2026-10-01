<?php

namespace Src\Balad;

use PDO;
use PDOStatement;
use RuntimeException;
use Src\Support\Db;

/**
 * Creates or reuses contacts for phone numbers found on Balad places.
 *
 * The contact table's unique phone key prevents duplicate contact rows when
 * the same business appears on multiple Balad result pages.
 */
final class BaladContactRepository
{
    private PDO $pdo;

    private ?PDOStatement $upsertStatement = null;

    /** @var array<string,int> */
    private array $contactIdsByPhone = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::getConnection();
    }

    /**
     * Insert a phone number if it is new, otherwise return its existing ID.
     */
    public function findOrCreate(string $phone): int
    {
        if (isset($this->contactIdsByPhone[$phone])) {
            return $this->contactIdsByPhone[$phone];
        }

        if (preg_match('/^\\+?[0-9]{7,19}$/D', $phone) !== 1) {
            throw new RuntimeException('Invalid normalized phone number.');
        }

        if (!$this->upsertStatement instanceof PDOStatement) {
            $this->upsertStatement = $this->pdo->prepare(
                'INSERT INTO contacts (phone) VALUES (?) ' .
                'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
        }

        $this->upsertStatement->execute([$phone]);
        $contactId = (int) $this->pdo->lastInsertId();

        if ($contactId <= 0) {
            $select = $this->pdo->prepare('SELECT id FROM contacts WHERE phone = ? LIMIT 1');
            $select->execute([$phone]);
            $contactId = (int) $select->fetchColumn();
        }

        if ($contactId <= 0) {
            throw new RuntimeException('Could not resolve the contact ID for a phone number.');
        }

        $this->contactIdsByPhone[$phone] = $contactId;

        return $contactId;
    }
}
