<?php

namespace Src\Support;

use RuntimeException;
use Src\Divar\AccommodationRepository;
use Src\Divar\DivarSearchAdStore;
use Throwable;

/**
 * Stores a server-side snapshot of displayed search results and saves one
 * provider result only after the user explicitly selects it.
 */
final class SelectedPlaceService
{
    private const SESSION_KEY = 'selected_place_result_sets';
    private const RESULT_SET_TTL = 1800;
    private const MAX_RESULT_SETS = 10;

    /**
     * Keep the current page in the session so save requests can only refer to
     * places that were actually returned by that provider search.
     *
     * @param array<int,mixed> $places
     * @param array<string,mixed> $context
     */
    public static function remember(string $provider, array $places, array $context): ?string
    {
        if (!in_array($provider, ProviderAccommodationMapper::PROVIDERS, true)) {
            return null;
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $placesById = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $externalId = ProviderAccommodationMapper::externalId($provider, $place);

            if ($externalId !== null) {
                // Divar's full ad snapshot already lives in DivarSearchAdStore;
                // only keep its ID and phone here to avoid duplicating large raw payloads.
                $placesById[$externalId] = $provider === 'divar'
                    ? ['telephone' => ProviderAccommodationMapper::contactPhone($place)]
                    : $place;
            }
        }

        if ($placesById === []) {
            return null;
        }

        try {
            $key = bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            Logger::warning('Could not create selected-place result key', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $now = time();
        $sets = $_SESSION[self::SESSION_KEY] ?? [];
        $sets = is_array($sets) ? $sets : [];

        foreach ($sets as $existingKey => $set) {
            if (!is_array($set) || (int) ($set['expires_at'] ?? 0) < $now) {
                unset($sets[$existingKey]);
            }
        }

        $sets[$key] = [
            'provider' => $provider,
            'expires_at' => $now + self::RESULT_SET_TTL,
            'context' => $context,
            'places' => $placesById,
        ];

        while (count($sets) > self::MAX_RESULT_SETS) {
            $oldestKey = array_key_first($sets);

            if ($oldestKey === null) {
                break;
            }

            unset($sets[$oldestKey]);
        }

        $_SESSION[self::SESSION_KEY] = $sets;

        return $key;
    }

    /**
     * Return provider-scoped IDs that already exist in accommodations.
     * The returned map is keyed by external_id for direct view lookups.
     *
     * @param array<int,mixed> $places
     * @return array<string,true>
     */
    public static function savedIds(string $provider, array $places): array
    {
        if (!in_array($provider, ProviderAccommodationMapper::PROVIDERS, true)) {
            return [];
        }

        $ids = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $id = ProviderAccommodationMapper::externalId($provider, $place);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $savedIds = [];

        foreach (array_chunk(array_values($ids), 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $query = 'SELECT external_id FROM accommodations ' .
                'WHERE provider = ? AND external_id IN (' . $placeholders . ')';
            $statement = Db::getConnection()->prepare($query);
            $statement->execute(array_merge([$provider], $chunk));

            foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $savedId) {
                $savedIds[(string) $savedId] = true;
            }
        }

        return $savedIds;
    }

    /**
     * Save one selected result. The result-set key and ID are checked against
     * the server-side snapshot, and existing records are never written again.
     *
     * @return array{success:bool,message:string,already_saved?:bool,contact_saved?:bool,http_status?:int}
     */
    public static function save(string $provider, mixed $resultSetKey, mixed $placeId): array
    {
        if (!in_array($provider, ProviderAccommodationMapper::PROVIDERS, true)) {
            return self::failure('درخواست ذخیره‌سازی معتبر نیست.', 400);
        }

        if (
            !is_string($resultSetKey) || preg_match('/^[a-f0-9]{32}$/i', $resultSetKey) !== 1 ||
            !is_string($placeId) || $placeId === '' || strlen($placeId) > 1024
        ) {
            return self::failure('درخواست ذخیره‌سازی اقامتگاه نامعتبر است.', 400);
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return self::failure('نشست کاربری در دسترس نیست؛ دوباره جستجو کنید.', 400);
        }

        $set = $_SESSION[self::SESSION_KEY][$resultSetKey] ?? null;

        if (
            !is_array($set) ||
            ($set['provider'] ?? null) !== $provider ||
            (int) ($set['expires_at'] ?? 0) < time() ||
            !is_array($set['places'] ?? null)
        ) {
            return self::failure('نتیجه جستجو منقضی شده است؛ دوباره جستجو کنید.', 410);
        }

        $place = $set['places'][$placeId] ?? null;
        $context = is_array($set['context'] ?? null) ? $set['context'] : [];

        if (!is_array($place)) {
            return self::failure('اقامتگاه انتخاب‌شده در نتایج این جستجو نیست.', 404);
        }

        $row = null;

        if ($provider === 'divar') {
            $row = DivarSearchAdStore::find($placeId);

            if (
                !is_array($row) ||
                ($row['provider'] ?? null) !== 'divar' ||
                (string) ($row['external_id'] ?? '') !== $placeId
            ) {
                return self::failure('اطلاعات آگهی دیوار منقضی شده است؛ دوباره جستجو کنید.', 410);
            }
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        Schema::ensureTables();

        $row = $row ?? ProviderAccommodationMapper::toRow($provider, $place, $context);

        if ($row === null) {
            return self::failure('شناسه اقامتگاه برای ذخیره‌سازی معتبر نیست.', 422);
        }

        if (self::isSaved($provider, $row['external_id'])) {
            return [
                'success' => true,
                'already_saved' => true,
                'contact_saved' => false,
                'message' => 'این اقامتگاه قبلاً ذخیره شده است.',
            ];
        }

        $phone = self::normalizedContactPhone(
            ProviderAccommodationMapper::contactPhone($place)
        );

        if ($phone !== null) {
            (new AccommodationRepository())->upsertWithContact($row, $phone);
        } else {
            $result = (new AccommodationRepository())->upsertMany([$row]);

            if (!$result['ok'] || $result['failed'] > 0) {
                throw new RuntimeException(
                    $result['error'] ?? 'Selected accommodation could not be saved.'
                );
            }
        }

        Logger::info('Selected provider accommodation stored', [
            'provider' => $provider,
            'external_id' => $row['external_id'],
            'contact_saved' => $phone !== null,
        ]);

        return [
            'success' => true,
            'already_saved' => false,
            'contact_saved' => $phone !== null,
            'message' => 'اقامتگاه انتخاب‌شده با موفقیت ذخیره شد.',
        ];
    }

    private static function isSaved(string $provider, string $externalId): bool
    {
        $statement = Db::getConnection()->prepare(
            'SELECT 1 FROM accommodations WHERE provider = ? AND external_id = ? LIMIT 1'
        );
        $statement->execute([$provider, $externalId]);

        return $statement->fetchColumn() !== false;
    }

    private static function normalizedContactPhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $phone = Str::normalizeNumbers(trim($phone));
        $phone = (string) preg_replace('/[\s().-]+/u', '', $phone);
        $phone = (string) preg_replace('/^(?:\+98|0098|98)([1-9][0-9]{9})$/D', '0$1', $phone);

        return preg_match('/^\+?[0-9]{7,15}$/D', $phone) === 1
            ? $phone
            : null;
    }

    /** @return array{success:false,message:string,http_status:int} */
    private static function failure(string $message, int $status): array
    {
        return [
            'success' => false,
            'message' => $message,
            'http_status' => $status,
        ];
    }
}
