<?php

namespace Src\Behtarino;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;
use Src\Support\Str;

/**
 * BehtarinoClient - Scrapes behtarino.com with plain HTTP (no browser, no API key).
 *
 * Flow:
 *   1. Map the Persian province name to 1-3 Behtarino city slugs
 *      (config/behtarino/cities.json; the site has no province pages).
 *   2. GET /r/{type}/{city}?page={n} per city - server-rendered pages whose
 *      listings are embedded as JSON-LD ItemList (name, address, geo, rating,
 *      images, detail URL). City results are merged and deduplicated.
 *   3. Per card, GET its /p/{hash}~{slug} detail page and read the phone from
 *      the embedded "phoneNumbers" data or the meta description (تلفن: …).
 *      No login is needed anywhere.
 */
final class BehtarinoClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private array $categories = [];
    private array $cities = [];
    private int $detailDelayMs;
    private int $detailTimeout;

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        array $categories = [],
        array $cities = [],
        int $detailDelayMs = 200,
        int $detailTimeout = 15
    ) {
        $this->http = $http;
        $this->endpoint = rtrim($endpoint, '/');
        $this->categories = $categories;
        $this->cities = $cities;
        $this->detailDelayMs = max(0, $detailDelayMs);
        $this->detailTimeout = max(5, $detailTimeout);
    }

    public function getDetailDelayMs(): int
    {
        return $this->detailDelayMs;
    }

    /**
     * Get category label from value.
     * Categories shape: [['value' => '...', 'label' => '...'], ...]
     */
    public function getCategoryLabel(string $value): ?string
    {
        foreach ($this->categories as $cat) {
            if ((string) ($cat['value'] ?? '') === $value) {
                return $cat['label'] ?? null;
            }
        }
        return null;
    }

    /**
     * Behtarino city slugs searched for a Persian province name.
     *
     * @return string[]
     */
    public function getCitiesForProvince(string $provinceFa): array
    {
        $provinceFa = trim($provinceFa);

        if ($provinceFa === '' || !isset($this->cities[$provinceFa])) {
            return [];
        }

        $cities = array_values(array_filter(array_map(
            static fn ($city): string => trim((string) $city),
            (array) $this->cities[$provinceFa]
        )));

        return array_values(array_unique($cities));
    }

    /**
     * Search one page across the province's cities (merged, deduplicated).
     *
     * @return array ['success' => true, 'cards' => [...], 'page_count' => int, 'cities' => [...]]
     *               or ['success' => false, 'error' => '...']
     */
    public function searchPage(string $provinceFa, string $category, int $page = 1): array
    {
        $page = max(1, $page);

        if ($this->getCategoryLabel($category) === null) {
            return ['success' => false, 'error' => "Invalid category for Behtarino: $category"];
        }

        $cities = $this->getCitiesForProvince($provinceFa);

        if ($cities === []) {
            return ['success' => false, 'error' => 'استان موردنظر در بهترینو پیدا نشد.'];
        }

        $cards = [];
        $seen = [];
        $pageCount = $page;
        $errors = 0;

        foreach ($cities as $city) {
            $url = $this->endpoint . '/r/' . rawurlencode($category) . '/' . rawurlencode($city);
            if ($page > 1) {
                $url .= '?page=' . $page;
            }

            Logger::info('Behtarino search', ['url' => $url, 'page' => $page]);

            $result = $this->http->getRaw($url);

            if (!$result['success']) {
                $errors++;
                Logger::error('Behtarino list fetch failed', ['error' => $result['error']]);
                continue;
            }

            foreach (self::parseListCards($result['body']) as $card) {
                $key = $card['external_id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $card['city'] = $city;
                $cards[] = $card;
            }

            $pageCount = max($pageCount, self::parsePageCount($result['body'], $page));
        }

        if ($cards === [] && $errors >= count($cities)) {
            return ['success' => false, 'error' => 'خطا در ارتباط با بهترینو'];
        }

        return [
            'success' => true,
            'cards' => $cards,
            'page_count' => $pageCount,
            'cities' => $cities,
        ];
    }

    /**
     * Fetch one detail page and extract its public phone.
     */
    public function fetchDetailPhone(string $detailUrl): ?string
    {
        $result = $this->http->getRaw($detailUrl, ['timeout' => $this->detailTimeout]);

        if (!$result['success'] || $result['body'] === '') {
            return null;
        }

        return self::parseDetailPhone($result['body']);
    }

    /**
     * Parse listings from a Behtarino list page (JSON-LD ItemList).
     *
     * @return array<int,array<string,mixed>> each: external_id, url, name,
     *         address, latitude, longitude, rating, review_count, image
     */
    public static function parseListCards(string $html): array
    {
        $cards = [];

        if (!preg_match_all('#<script type="application/ld\+json">(.*?)</script>#su', $html, $scripts)) {
            return $cards;
        }

        foreach ($scripts[1] as $json) {
            $data = json_decode(trim($json), true);

            if (!is_array($data) || !isset($data['mainEntity']['itemListElement'])) {
                continue;
            }

            foreach ((array) $data['mainEntity']['itemListElement'] as $element) {
                $card = self::normalizeJsonLdItem(is_array($element) ? ($element['item'] ?? null) : null);

                if ($card !== null) {
                    $cards[] = $card;
                }
            }
        }

        return $cards;
    }

    /**
     * @param mixed $item
     * @return array<string,mixed>|null
     */
    public static function normalizeJsonLdItem(mixed $item): ?array
    {
        if (!is_array($item)) {
            return null;
        }

        $url = isset($item['url']) && is_string($item['url']) ? trim($item['url']) : '';

        if ($url === '' || !preg_match('#/p/([A-Za-z0-9]+)#', $url, $idMatch)) {
            return null;
        }

        $name = isset($item['name']) ? trim((string) $item['name']) : '';

        if ($name === '') {
            return null;
        }

        $addressParts = [];
        $address = $item['address'] ?? null;
        if (is_array($address)) {
            foreach (['streetAddress', 'addressLocality', 'addressRegion'] as $field) {
                if (isset($address[$field]) && trim((string) $address[$field]) !== '') {
                    $addressParts[] = trim((string) $address[$field]);
                }
            }
        }
        $addressParts = array_values(array_unique($addressParts));

        $latitude = null;
        $longitude = null;
        $geo = $item['geo'] ?? null;
        if (is_array($geo)) {
            if (isset($geo['latitude']) && is_numeric($geo['latitude'])) {
                $latitude = (float) $geo['latitude'];
            }
            if (isset($geo['longitude']) && is_numeric($geo['longitude'])) {
                $longitude = (float) $geo['longitude'];
            }
        }

        $rating = null;
        $reviewCount = null;
        $agg = $item['aggregateRating'] ?? null;
        if (is_array($agg)) {
            if (isset($agg['ratingValue']) && is_numeric($agg['ratingValue'])) {
                $rating = (float) $agg['ratingValue'];
            }
            if (isset($agg['reviewCount']) && is_numeric($agg['reviewCount'])) {
                $reviewCount = (int) $agg['reviewCount'];
            }
        }

        $image = null;
        if (isset($item['image']) && is_array($item['image'])) {
            foreach ($item['image'] as $candidate) {
                if (is_string($candidate) && str_starts_with($candidate, 'http')) {
                    $image = $candidate;
                    break;
                }
            }
        } elseif (isset($item['image']) && is_string($item['image'])) {
            $image = $item['image'];
        }

        return [
            'external_id' => 'behtarino_' . $idMatch[1],
            'url' => $url,
            'name' => $name,
            'address' => $addressParts === [] ? null : implode('، ', $addressParts),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'rating' => $rating,
            'review_count' => $reviewCount,
            'image' => $image,
        ];
    }

    /**
     * Highest page number found in pagination links.
     */
    public static function parsePageCount(string $html, int $currentPage): int
    {
        $max = $currentPage;

        if (preg_match_all('/[?&]page=(\d+)/', $html, $matches)) {
            foreach ($matches[1] as $number) {
                $max = max($max, (int) $number);
            }
        }

        return max(1, $max);
    }

    /**
     * Public phone of a detail page: embedded phoneNumbers data first,
     * then the meta description (تلفن: …), then any tel: link.
     */
    public static function parseDetailPhone(string $html): ?string
    {
        if (preg_match('#"phoneNumbers":\["([\d,\s"]+)"\]#', $html, $m)) {
            foreach (preg_split('/\s*,\s*/', trim($m[1], '" ')) as $candidate) {
                $phone = self::cleanPhone($candidate);
                if ($phone !== null) {
                    return $phone;
                }
            }
        }

        if (preg_match('/تلفن:\s*([0-9۰-۹]{8,15})/u', $html, $m)) {
            $phone = self::cleanPhone($m[1]);
            if ($phone !== null) {
                return $phone;
            }
        }

        if (preg_match_all('#href="tel:([^"]+)"#i', $html, $matches)) {
            foreach ($matches[1] as $raw) {
                $phone = self::cleanPhone($raw);
                if ($phone !== null) {
                    return $phone;
                }
            }
        }

        return null;
    }

    private static function cleanPhone(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $phone = Str::normalizeNumbers(trim($raw));
        $phone = (string) preg_replace('/[^\d+]/', '', $phone);

        return preg_match('/^\+?[0-9]{7,15}$/', $phone) === 1 ? $phone : null;
    }

    public static function faDigits(string $text): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $text
        );
    }
}
