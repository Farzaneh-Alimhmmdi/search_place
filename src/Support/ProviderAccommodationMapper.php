<?php

namespace Src\Support;

use Src\Balad\BaladPlaceMapper;
use Src\Divar\DivarAdMapper;

/**
 * Maps the normalized result shape used by SearchView to accommodation rows.
 */
final class ProviderAccommodationMapper
{
    public const PROVIDERS = ['balad', 'neshan', 'google_map', 'divar'];

    /**
     * Resolve the same provider-scoped ID for rendering, session validation,
     * saved-record lookup, and storage.
     *
     * @param array<string,mixed> $place
     */
    public static function externalId(string $provider, array $place): ?string
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        if ($provider === 'balad') {
            return BaladPlaceMapper::externalId($place);
        }

        if ($provider === 'divar') {
            // Match DivarAdMapper's token-first identity rule.
            $value = $place['token'] ?? ($place['id'] ?? null);

            return self::cleanId($value);
        }

        $values = $provider === 'neshan'
            ? [
                self::neshanUrlId($place['neshan_url'] ?? null),
                $place['place_id'] ?? null,
                $place['id'] ?? null,
                $place['token'] ?? null,
            ]
            : [
                $place['place_id'] ?? null,
                $place['id'] ?? null,
                $place['token'] ?? null,
            ];

        foreach ($values as $value) {
            $id = self::cleanId($value);

            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Convert one normalized search result to an accommodations row.
     *
     * @param array<string,mixed> $place
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public static function toRow(string $provider, array $place, array $context): ?array
    {
        if (!in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        if ($provider === 'balad') {
            return BaladPlaceMapper::toRow($place, $context);
        }

        if ($provider === 'divar') {
            return DivarAdMapper::toRow($place, $context);
        }

        $externalId = self::externalId($provider, $place);

        if ($externalId === null) {
            return null;
        }

        $raw = isset($place['raw']) && is_array($place['raw'])
            ? $place['raw']
            : $place;
        $title = self::firstString([
            $place['name'] ?? null,
            $place['title'] ?? null,
            $raw['name'] ?? null,
            $raw['title'] ?? null,
        ]) ?? ($provider === 'neshan' ? 'مکان نشان ' : 'مکان گوگل ') . $externalId;
        $telephone = self::firstString([
            $place['telephone'] ?? null,
            $place['phone'] ?? null,
            $raw['telephone'] ?? null,
            $raw['phone'] ?? null,
        ]);
        $website = self::firstString([
            $place['website'] ?? null,
            $raw['website'] ?? null,
        ]);
        $category = self::firstString([
            $context['category'] ?? null,
            $place['category'] ?? null,
            $raw['category'] ?? null,
        ]);
        $image = self::firstString([
            $place['image_preview'] ?? null,
            $raw['image_preview'] ?? null,
        ]);
        $url = self::firstString([
            $place['balad_url'] ?? null,
            $place['neshan_url'] ?? null,
            $place['url'] ?? null,
        ]);

        if ($url === null && $provider === 'google_map') {
            $url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(
                $title . ' ' . (self::firstString([$place['address'] ?? null]) ?? '')
            );
        }

        $providerData = [
            'telephone' => $telephone,
            'website' => $website,
            'provider_category' => self::firstString([
                $place['category'] ?? null,
                $raw['category'] ?? null,
            ]),
            'image_preview' => $image,
            'rating' => $place['rating'] ?? ($raw['rating'] ?? null),
            'instagram_id' => self::firstString([
                $place['instagram_id'] ?? null,
                $raw['instagram_id'] ?? null,
            ]),
            'search' => [
                'city' => self::firstString([$context['city'] ?? null]),
                'city_slug' => self::firstString([$context['city_slug'] ?? null]),
                'province' => self::firstString([$context['province'] ?? null]),
                'category' => self::firstString([$context['category'] ?? null]),
                'query' => self::firstString([$context['query'] ?? null]),
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
            'province' => Str::limit(
                self::firstString([$context['province'] ?? null]),
                100
            ),
            'city' => Str::limit(
                self::firstString([$context['city'] ?? null]),
                150
            ),
            'category' => Str::limit($category, 100),
            'address' => self::firstString([
                $place['address'] ?? null,
                $raw['address'] ?? null,
            ]),
            'latitude' => self::coordinate($place['latitude'] ?? ($raw['latitude'] ?? null), -90.0, 90.0),
            'longitude' => self::coordinate($place['longitude'] ?? ($raw['longitude'] ?? null), -180.0, 180.0),
            'price' => is_numeric($place['price'] ?? null) ? (float) $place['price'] : null,
            'provider' => $provider,
            'external_id' => $externalId,
            'url' => $url,
            'provider_data' => self::encodeJson($providerData),
            'raw_data' => self::encodeJson($raw),
        ];
    }

    /**
     * Reuse Balad's validated, first-valid-number parsing for every provider's
     * normalized phone/telephone fields.
     *
     * @param array<string,mixed> $place
     */
    public static function contactPhone(array $place): ?string
    {
        return BaladPlaceMapper::contactPhone($place);
    }

    private static function cleanId(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $id = trim((string) $value);

        if ($id === '') {
            return null;
        }

        $id = Str::limit($id, 255) ?? '';

        return $id === '' ? null : $id;
    }

    private static function neshanUrlId(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('~/places/([^/?#]+)~', $value, $matches) !== 1) {
            return null;
        }

        return self::cleanId('neshan_' . $matches[1]);
    }

    /** @param array<int,mixed> $values */
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

        return is_finite($coordinate) && $coordinate >= $min && $coordinate <= $max
            ? $coordinate
            : null;
    }

    private static function encodeJson(mixed $value): ?string
    {
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
