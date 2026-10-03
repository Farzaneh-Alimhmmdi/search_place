<?php

namespace Src\Makanchi;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;
use Src\Support\Str;

/**
 * MakanchiClient - Scrapes makanchi.com with plain HTTP (no browser, no API key).
 *
 * Flow (mirrors what the website itself does):
 *   1. Resolve the Persian city/province name via POST /Search/SearchForm
 *      (keyword) and use the landing URL as the canonical list URL.
 *      Examples: تهران -> /List-Tehran-1, ساری -> /List-Sari-376,
 *      مازندران -> /List?جستجوی=مازندران
 *   2. GET the list URL with ?Category={type}&صفحه={page} (both verified live).
 *      Page holds ~30 server-rendered cards (li.mb-4.place).
 *   3. Per card, GET its detail page (/{Type}/{id}-{slug}) and read the
 *      public tel: link. Phones need no login on Makanchi.
 */
final class MakanchiClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private array $categories = [];
    private int $detailDelayMs;
    private int $detailTimeout;

    /** @var array<string,string> Persian name -> canonical list URL */
    private array $resolvedUrls = [];

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        array $categories = [],
        int $detailDelayMs = 200,
        int $detailTimeout = 15
    ) {
        $this->http = $http;
        $this->endpoint = rtrim($endpoint, '/');
        $this->categories = $categories;
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
            if (($cat['value'] ?? '') === $value) {
                return $cat['label'] ?? null;
            }
        }
        return null;
    }

    /**
     * Search one list page (cards only, without phones).
     *
     * @return array ['success' => true, 'cards' => [...], 'page_count' => int, 'list_url' => string]
     *               or ['success' => false, 'error' => '...']
     */
    public function searchPage(string $cityFa, string $category, int $page = 1): array
    {
        $page = max(1, $page);

        if ($this->getCategoryLabel($category) === null) {
            return ['success' => false, 'error' => "Invalid category for Makanchi: $category"];
        }

        try {
            $base = $this->resolveCityUrl($cityFa);
        } catch (\Throwable $e) {
            Logger::error('Makanchi city resolve failed', ['city' => $cityFa, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'شهر موردنظر در مکانچی پیدا نشد.'];
        }

        $params = ['Category' => $category];
        if ($page > 1) {
            $params['صفحه'] = $page;
        }
        $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params);

        Logger::info('Makanchi search', ['url' => $url, 'page' => $page]);

        $result = $this->http->getRaw($url);

        if (!$result['success']) {
            Logger::error('Makanchi list fetch failed', ['error' => $result['error']]);
            return ['success' => false, 'error' => 'خطا در ارتباط با مکانچی'];
        }

        $cards = self::parseListCards($result['body']);

        if ($cards === []) {
            return ['success' => true, 'cards' => [], 'page_count' => $page, 'list_url' => $url];
        }

        return [
            'success' => true,
            'cards' => $cards,
            'page_count' => self::parsePageCount($result['body'], $page),
            'list_url' => $url,
        ];
    }

    /**
     * Fetch one detail page and extract its public phone and address.
     *
     * @return array{phone: string|null, address: string|null}
     */
    public function fetchDetail(string $detailUrl): array
    {
        $result = $this->http->getRaw($detailUrl, ['timeout' => $this->detailTimeout]);

        if (!$result['success'] || $result['body'] === '') {
            throw new \RuntimeException('Detail fetch failed: ' . ($result['error'] ?? 'empty response'));
        }

        return [
            'phone' => self::parseDetailPhone($result['body']),
            'address' => self::parseDetailAddress($result['body']),
        ];
    }

    /**
     * Fetch one detail page and extract its public phone number.
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
     * Resolve a Persian city/province name to its canonical Makanchi list URL
     * using the site's own keyword search (POST, follow redirect).
     */
    public function resolveCityUrl(string $cityFa): string
    {
        $cityFa = trim($cityFa);

        if ($cityFa === '') {
            throw new \RuntimeException('City name is required.');
        }

        if (isset($this->resolvedUrls[$cityFa])) {
            return $this->resolvedUrls[$cityFa];
        }

        $result = $this->http->getRaw(
            $this->endpoint . '/Search/SearchForm',
            ['POST' => http_build_query(['keyword' => $cityFa])]
        );

        if (!$result['success'] || $result['body'] === '') {
            throw new \RuntimeException('City search failed: ' . ($result['error'] ?? 'empty response'));
        }

        $final = $result['final_url'];

        if (!str_contains($final, '/List')) {
            throw new \RuntimeException('City not found on Makanchi.');
        }

        $this->resolvedUrls[$cityFa] = $final;

        return $final;
    }

    /**
     * Parse list cards from a Makanchi list page.
     *
     * @return array<int,array<string,mixed>> each: href, image, city, title,
     *         description, price_text, capacity_text
     */
    public static function parseListCards(string $html): array
    {
        $cards = [];

        if (stripos($html, 'mb-4 place') === false) {
            return $cards;
        }

        if (!preg_match_all('#<li[^>]*class="[^"]*mb-4 place[^"]*"[^>]*>(.*?)</li>#su', $html, $matches)) {
            return $cards;
        }

        foreach ($matches[1] as $block) {
            if (!preg_match('#<a[^>]*href="((?:/(?:Villa|Apartment|Boomgardi|Suite|Cottage)/\d+[^"]*))"#i', $block, $href)) {
                continue;
            }

            $title = self::firstText($block, '#<h2[^>]*class="[^"]*vilalist-title-666[^"]*"[^>]*>(.*?)</h2>#su');
            if ($title === null || $title === '') {
                continue;
            }

            $numericId = null;
            if (preg_match('#/(?:Villa|Apartment|Boomgardi|Suite|Cottage)/(\d+)#i', $href[1], $idMatch)) {
                $numericId = $idMatch[1];
            }

            $image = null;
            if (preg_match('#<img[^>]*src="(//cdn[^"]+)"#i', $block, $img)) {
                $image = 'https:' . $img[1];
            }

            $cards[] = [
                'numeric_id' => $numericId,
                'href' => $href[1],
                'image' => $image,
                'city' => self::firstText($block, '#listPagelist-btn[^>]*>(.*?)</div>#su'),
                'title' => $title,
                'description' => self::firstText($block, '#vilaList-2line[^>]*>(.*?)</div>#su'),
                'price_text' => self::firstText($block, '#big-vilalist-title[^>]*>(.*?)</div>#su'),
                'capacity_text' => self::joinTexts($block, '#<span>\s*([^<>]{1,60})\s*</span>#u'),
            ];
        }

        return $cards;
    }

    /**
     * Highest page number found in pagination links (Persian صفحه= or page=).
     */
    public static function parsePageCount(string $html, int $currentPage): int
    {
        $max = $currentPage;

        if (preg_match_all('/[?&](?:صفحه|page)=(\d+)/u', $html, $matches)) {
            foreach ($matches[1] as $number) {
                $max = max($max, (int) $number);
            }
        }

        return max(1, $max);
    }

    /**
     * First public tel: link of a detail page, normalized to Latin digits.
     */
    public static function parseDetailPhone(string $html): ?string
    {
        if (!preg_match_all('#href="tel:([^"]+)"#i', $html, $matches)) {
            return null;
        }

        foreach ($matches[1] as $raw) {
            $phone = Str::normalizeNumbers(trim($raw));
            $phone = (string) preg_replace('/[^\d+]/', '', $phone);

            if (preg_match('/^\+?[0-9]{7,15}$/', $phone) === 1) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Best-effort address from a detail page (محله/نشانی/موقعیت/آدرس …).
     */
    public static function parseDetailAddress(string $html): ?string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        if (preg_match('/(?:آدرس|نشانی|موقعیت|محله)\s*[:：\-–]?\s*([^.،\n]{2,120})/u', $text, $m)) {
            $address = trim($m[1]);
            return $address !== '' ? $address : null;
        }

        return null;
    }

    /**
     * Persian display price ("قیمت برای هر شب 1,344,000 تومان" ->
     * "قیمت برای هر شب ۱,۳۴۴,۰۰۰ تومان").
     */
    public static function faPrice(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        return self::faDigits(trim($text));
    }

    /**
     * Numeric value of a price text, or null (توافقی / تماس …).
     */
    public static function numericPrice(?string $text): ?float
    {
        if ($text === null) {
            return null;
        }

        $normalized = Str::normalizeNumbers($text);
        $digits = (string) preg_replace('/[^\d.]/', '', $normalized);

        if ($digits === '' || !is_numeric($digits)) {
            return null;
        }

        $value = (float) $digits;

        return $value > 0 ? $value : null;
    }

    public static function faDigits(string $text): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $text
        );
    }

    private static function cleanText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        return $text === '' ? null : $text;
    }

    private static function firstText(string $block, string $pattern): ?string
    {
        if (preg_match($pattern, $block, $m) !== 1) {
            return null;
        }

        return self::cleanText($m[1]);
    }

    private static function joinTexts(string $block, string $pattern): ?string
    {
        if (!preg_match_all($pattern, $block, $matches)) {
            return null;
        }

        $parts = [];
        foreach ($matches[1] as $raw) {
            $clean = self::cleanText($raw);
            if ($clean !== null && !in_array($clean, $parts, true)) {
                $parts[] = $clean;
            }
        }

        return $parts === [] ? null : implode('، ', $parts);
    }
}
