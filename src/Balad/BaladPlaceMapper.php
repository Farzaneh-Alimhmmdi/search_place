<?php

namespace Src\Balad;

use Src\Support\Str;

/**
 * Maps detailed Balad places to the shared `accommodations` table.
 *
 * Balad-specific fields that do not have dedicated columns are retained in
 * provider_data, while the complete API item is kept in raw_data.
 */
final class BaladPlaceMapper
{
    public const PROVIDER = 'balad';

    /**
     * Map one place to an accommodations row.
     *
     * @param array<string,mixed> $place Normalized place from BaladClient
     * @param array<string,mixed> $context Search context (city, city_slug,
     *                                      category, page)
     * @return array<string,mixed>|null Null when the place has no stable ID
     */
    public static function toRow(array $place, array $context): ?array
    {
        $externalId = self::externalId($place);

        if ($externalId === null) {
            return null;
        }

        $raw = isset($place['raw']) && is_array($place['raw'])
            ? $place['raw']
            : $place;

        $title = self::firstString([
            $place['name'] ?? null,
            $raw['name'] ?? null,
        ]) ?? ('مکان بلد ' . $externalId);

        $telephone = self::telephone($place);
        $website = self::firstString([
            $place['website'] ?? null,
            $raw['website'] ?? null,
        ]);
        $rawImage = $raw['image'] ?? null;
        $rawImagePreview = is_array($rawImage) ? ($rawImage['preview'] ?? null) : null;
        $image = self::firstString([
            $place['image_preview'] ?? null,
            $rawImagePreview,
        ]);

        $providerData = [
            'telephone' => $telephone,
            'website' => $website,
            'balad_category' => $place['category'] ?? ($raw['category'] ?? null),
            'image_preview' => $image,
            'rating' => $place['rating'] ?? null,
            'instagram_id' => self::firstString([
                $place['instagram_id'] ?? null,
                $raw['instagram_id'] ?? null,
            ]),
            'search' => [
                'city' => self::firstString([$context['city'] ?? null]),
                'city_slug' => self::firstString([$context['city_slug'] ?? null]),
                'category' => self::firstString([$context['category'] ?? null]),
                'page' => max(1, (int) ($context['page'] ?? 1)),
                'searched_at' => gmdate(DATE_ATOM),
            ],
        ];

        return [
            'contact_id' => null,
            'title' => Str::limit($title, 500),
            'description' => self::firstString([
                $place['description'] ?? null,
                $raw['description'] ?? null,
            ]),
            'province' => Str::limit(self::firstString([$context['province'] ?? null]), 100),
            'city' => Str::limit(self::firstString([$context['city'] ?? null]), 150),
            'category' => Str::limit(
                self::firstString([
                    $context['category'] ?? null,
                    $place['category'] ?? null,
                ]),
                100
            ),
            'address' => self::firstString([
                $place['address'] ?? null,
                $raw['address'] ?? null,
            ]),
            'latitude' => self::coordinate($place['latitude'] ?? null, -90.0, 90.0),
            'longitude' => self::coordinate($place['longitude'] ?? null, -180.0, 180.0),
            'price' => null,
            'provider' => self::PROVIDER,
            'external_id' => $externalId,
            'url' => self::firstString([
                $place['balad_url'] ?? null,
                $place['url'] ?? null,
            ]),
            'provider_data' => self::encodeJson($providerData),
            'raw_data' => self::encodeJson($raw),
        ];
    }

    /**
     * Map a page of detailed Balad results, dropping entries without an ID.
     *
     * @param array<int,mixed> $places
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public static function toRows(array $places, array $context): array
    {
        $rows = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $row = self::toRow($place, $context);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Return the stable ID used as the accommodations external_id.
     */
    public static function externalId(array $place): ?string
    {
        $externalId = self::firstString([
            $place['token'] ?? null,
            $place['id'] ?? null,
            $place['place_id'] ?? null,
        ]);

        if ($externalId === null || $externalId === '') {
            return null;
        }

        $externalId = Str::limit($externalId, 255) ?? '';

        return $externalId === '' ? null : $externalId;
    }

    /**
     * Return one valid normalized phone number to link through contact_id.
     *
     * accommodations has one contact_id per place. If Balad returns several
     * numbers in one telephone string, the first valid number is the primary
     * contact; the full source value remains in provider_data/raw_data.
     */
    public static function contactPhone(array $place): ?string
    {
        $telephone = self::telephone($place);

        if ($telephone === null) {
            return null;
        }

        $candidates = preg_split('/[,;،\\/|\\r\\n]+/u', $telephone, -1, PREG_SPLIT_NO_EMPTY);

        foreach (is_array($candidates) ? $candidates : [$telephone] as $candidate) {
            $candidate = trim(Str::normalizeNumbers(trim($candidate)));

            if ($candidate === '' || preg_match('/^\\+?[0-9\\s().-]+$/', $candidate) !== 1) {
                continue;
            }

            $digits = preg_replace('/[^0-9]/', '', $candidate);

            if (!is_string($digits) || strlen($digits) < 7) {
                continue;
            }

            $phone = str_starts_with($candidate, '+') ? '+' . $digits : $digits;

            if (strlen($phone) <= 20) {
                return $phone;
            }
        }

        return null;
    }

    private static function telephone(array $place): ?string
    {
        $raw = isset($place['raw']) && is_array($place['raw']) ? $place['raw'] : [];

        return self::firstString([
            $place['telephone'] ?? null,
            $place['phone'] ?? null,
            $raw['telephone'] ?? null,
            $raw['phone'] ?? null,
        ]);
    }

    /**
     * Select the first non-empty scalar value and normalize it to a string.
     *
     * @param array<int,mixed> $values
     */
    private static function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function coordinate(mixed $value, float $min, float $max): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        if (!is_finite($coordinate) || $coordinate < $min || $coordinate > $max) {
            return null;
        }

        return $coordinate;
    }

    private static function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE |
            JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return is_string($encoded) && $encoded !== '' ? $encoded : null;
    }
}
