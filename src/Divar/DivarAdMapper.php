<?php

namespace Src\Divar;

use Src\Support\Str;

/**
 * Maps one normalized Divar ad to one row of the `accommodations` table.
 *
 * Input  : the array returned by DivarSearchService::normalizePostRow()
 * Output : an associative array whose keys are exactly the columns of
 *          `accommodations` (see database/schema.sql).
 *
 * The mapper never throws: a bad/missing value simply becomes NULL, because a
 * single weird ad must not stop a harvest of thousands of ads.
 */
final class DivarAdMapper
{
    public const PROVIDER = 'divar';

    /**
     * Safety cap while walking the raw payload looking for coordinates.
     */
    private const MAX_NODES = 4000;

    /**
     * DECIMAL(15, 2) cannot hold more than this.
     */
    private const MAX_PRICE = 9999999999999.99;

    private const PRICE_MULTIPLIERS = [
        'میلیارد' => 1000000000.0,
        'میلیون' => 1000000.0,
        'هزار' => 1000.0,
    ];

    /**
     * @param array $ad      normalized Divar ad
     * @param array $context search context, keys:
     *                       province, city, city_slug, divar_city_id,
     *                       category, category_label, query, job_id, page,
     *                       store_raw
     * @return array<string,mixed>|null null when the ad has no token
     */
    public static function toRow(array $ad, array $context): ?array
    {
        $token = self::cleanString($ad['token'] ?? ($ad['id'] ?? null));

        if ($token === null || $token === '') {
            return null;
        }

        $raw = [];

        if (isset($ad['raw']) && is_array($ad['raw'])) {
            $raw = $ad['raw'];
        }

        $webInfo = self::webInfo($raw);

        $title = self::firstString([
            $ad['title'] ?? null,
            $ad['name'] ?? null,
            $raw['title'] ?? null,
            $raw['title_text'] ?? null,
        ]);

        if ($title === null || $title === '') {
            $title = 'آگهی دیوار ' . $token;
        }

        $priceText = self::firstString([
            $raw['middle_description_text'] ?? null,
            $ad['price'] ?? null,
            $ad['price_text'] ?? null,
        ]);

        $coordinates = self::extractCoordinates($raw);

        $storeRaw = true;

        if (array_key_exists('store_raw', $context)) {
            $storeRaw = (bool) $context['store_raw'];
        }

        return [
            'contact_id' => null,

            'title' => Str::limit($title, 500),
            'description' => self::buildDescription($raw),
            'province' => Str::limit(self::cleanString($context['province'] ?? null), 100),
            'city' => Str::limit(self::resolveCityName($webInfo, $context), 150),
            'category' => Str::limit(self::cleanString($context['category'] ?? null), 100),
            'address' => self::buildAddress($raw, $webInfo, $ad),
            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],
            'price' => self::parsePrice($priceText),

            'provider' => self::PROVIDER,
            'external_id' => Str::limit($token, 255),
            'url' => self::buildUrl($ad, $token),

            'provider_data' => self::encodeJson(
                self::buildProviderData($ad, $raw, $webInfo, $priceText, $context)
            ),
            'raw_data' => $storeRaw ? self::encodeJson($raw === [] ? null : $raw) : null,
        ];
    }

    /**
     * Map a list of ads to a list of accommodation rows.
     *
     * @param array[] $ads
     * @return array[]
     */
    public static function toRows(array $ads, array $context): array
    {
        $rows = [];

        foreach ($ads as $ad) {
            if (!is_array($ad)) {
                continue;
            }

            $row = self::toRow($ad, $context);

            if ($row === null) {
                continue;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Turn a Persian price sentence into a number.
     *
     * Examples:
     *   "۱٬۵۰۰٬۰۰۰ تومان"        -> 1500000
     *   "رهن: ۱۰۰٬۰۰۰٬۰۰۰ تومان"  -> 100000000
     *   "۲.۵ میلیارد"             -> 2500000000
     *   "توافقی" / "رهن کامل"      -> null
     */
    public static function parsePrice(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }

        $normalized = Str::normalizeNumbers($text);

        /*
         * Thousands separators would split one number into many, remove them.
         */
        $normalized = str_replace([',', ' '], '', $normalized);

        if ($normalized === '') {
            return null;
        }

        $found = preg_match(
            '/(\d+(?:\.\d+)?)(میلیارد|میلیون|هزار)?/u',
            $normalized,
            $matches
        );

        if ($found !== 1) {
            return null;
        }

        $value = (float) $matches[1];

        if (!is_finite($value) || $value <= 0) {
            return null;
        }

        $multiplierWord = $matches[2] ?? '';

        if ($multiplierWord !== '' && isset(self::PRICE_MULTIPLIERS[$multiplierWord])) {
            $value *= self::PRICE_MULTIPLIERS[$multiplierWord];
        }

        if (!is_finite($value) || $value > self::MAX_PRICE) {
            return null;
        }

        return round($value, 2);
    }

    /**
     * `data.action.payload.web_info` holds the Persian city/district/category
     * names of the ad.
     */
    private static function webInfo(array $raw): array
    {
        $webInfo = $raw['action']['payload']['web_info'] ?? null;

        if (is_array($webInfo)) {
            return $webInfo;
        }

        $webInfo = $raw['web_info'] ?? null;

        return is_array($webInfo) ? $webInfo : [];
    }

    private static function resolveCityName(array $webInfo, array $context): ?string
    {
        return self::firstString([
            $webInfo['city_persian'] ?? null,
            $context['city'] ?? null,
            $webInfo['city'] ?? null,
        ]);
    }

    private static function buildDescription(array $raw): ?string
    {
        $parts = [];

        foreach (
            [
                $raw['top_description_text'] ?? null,
                $raw['middle_description_text'] ?? null,
                $raw['red_text'] ?? null,
                $raw['description'] ?? null,
            ] as $part
        ) {
            $clean = self::cleanString($part);

            if ($clean !== null && $clean !== '' && !in_array($clean, $parts, true)) {
                $parts[] = $clean;
            }
        }

        return $parts === [] ? null : implode(' | ', $parts);
    }

    private static function buildAddress(array $raw, array $webInfo, array $ad): ?string
    {
        $address = self::cleanString(
            $raw['bottom_description_text'] ?? ($ad['address'] ?? null)
        );

        $district = self::cleanString($webInfo['district_persian'] ?? null);

        if ($district !== null && $district !== '') {
            if ($address === null || $address === '') {
                $address = $district;
            } elseif (strpos($address, $district) === false) {
                $address = $address . '، ' . $district;
            }
        }

        return ($address === null || $address === '') ? null : $address;
    }

    private static function buildUrl(array $ad, string $token): string
    {
        $url = self::firstString([
            $ad['divar_url'] ?? null,
            $ad['url'] ?? null,
        ]);

        if ($url !== null && $url !== '') {
            return $url;
        }

        return 'https://divar.ir/v/' . $token;
    }

    /**
     * Everything Divar gave us that does not have its own column, plus the
     * harvest metadata (which job/page found this ad).
     */
    private static function buildProviderData(
        array $ad,
        array $raw,
        array $webInfo,
        ?string $priceText,
        array $context
    ): array {
        return [
            'token' => self::cleanString($ad['token'] ?? ($ad['id'] ?? null)),
            'price_text' => $priceText,
            'top_description' => self::cleanString($raw['top_description_text'] ?? null),
            'middle_description' => self::cleanString($raw['middle_description_text'] ?? null),
            'bottom_description' => self::cleanString($raw['bottom_description_text'] ?? null),
            'red_text' => self::cleanString($raw['red_text'] ?? null),
            'image_url' => self::cleanString($raw['image_url'] ?? ($ad['image_preview'] ?? null)),
            'image_count' => $raw['image_count'] ?? ($ad['image_count'] ?? null),
            'layout_type' => $raw['layout_type'] ?? ($ad['layout_type'] ?? null),
            'web_info' => $webInfo === [] ? null : $webInfo,
            'category_label' => self::cleanString($context['category_label'] ?? null),
            'harvest' => [
                'job_id' => self::cleanString($context['job_id'] ?? null),
                'page' => isset($context['page']) ? (int) $context['page'] : null,
                'query' => self::cleanString($context['query'] ?? null) ?? '',
                'city_slug' => self::cleanString($context['city_slug'] ?? null),
                'divar_city_id' => self::cleanString($context['divar_city_id'] ?? null),
                'collected_at' => date('c'),
            ],
        ];
    }

    /**
     * Look for latitude/longitude anywhere inside the raw payload.
     *
     * Divar's list endpoint usually does NOT send coordinates, so this returns
     * [null, null] most of the time - which is exactly what the schema expects
     * (both columns are nullable).
     *
     * @return array{latitude: float|null, longitude: float|null}
     */
    public static function extractCoordinates(array $raw): array
    {
        $latitude = null;
        $longitude = null;

        $queue = [$raw];

        for ($i = 0; $i < count($queue) && $i < self::MAX_NODES; $i++) {
            $node = $queue[$i];

            if (!is_array($node)) {
                continue;
            }

            foreach ($node as $key => $value) {
                if (is_string($key)) {
                    $lowerKey = strtolower($key);

                    if (
                        $latitude === null &&
                        ($lowerKey === 'latitude' || $lowerKey === 'lat') &&
                        is_scalar($value)
                    ) {
                        $latitude = self::toCoordinate($value, 90.0);
                    } elseif (
                        $longitude === null &&
                        ($lowerKey === 'longitude' || $lowerKey === 'lng' ||
                            $lowerKey === 'lon' || $lowerKey === 'long') &&
                        is_scalar($value)
                    ) {
                        $longitude = self::toCoordinate($value, 180.0);
                    }
                }

                if (is_array($value)) {
                    $queue[] = $value;
                }
            }

            if ($latitude !== null && $longitude !== null) {
                break;
            }
        }

        /*
         * Half a coordinate is useless, and (0,0) means "no data" for Divar.
         */
        if ($latitude === null || $longitude === null) {
            return ['latitude' => null, 'longitude' => null];
        }

        if (abs($latitude) < 0.0000001 && abs($longitude) < 0.0000001) {
            return ['latitude' => null, 'longitude' => null];
        }

        return [
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
        ];
    }

    private static function toCoordinate(mixed $value, float $max): ?float
    {
        if (is_bool($value)) {
            return null;
        }

        $normalized = Str::normalizeNumbers(trim((string) $value));

        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;

        if (!is_finite($number) || abs($number) > $max) {
            return null;
        }

        return $number;
    }

    /**
     * json_encode() that never produces invalid JSON for a MySQL JSON column.
     */
    public static function encodeJson(mixed $value): ?string
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

        if (!is_string($encoded) || $encoded === '' || $encoded === 'null') {
            return null;
        }

        return $encoded;
    }

    /**
     * First non-empty string of a list of candidates.
     *
     * @param array<int,mixed> $candidates
     */
    public static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $clean = self::cleanString($candidate);

            if ($clean !== null && $clean !== '') {
                return $clean;
            }
        }

        return null;
    }

    /**
     * Accept only real strings (or numbers, which are cast) and trim them.
     */
    public static function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return trim((string) $value);
        }

        return null;
    }
}
