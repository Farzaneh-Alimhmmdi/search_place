<?php

namespace Src\Vilayar;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;
use Src\Support\Str;

/**
 * VilayarClient - Scrapes vilayar.com with plain HTTP (no browser, no API key).
 *
 * Flow:
 *   1. Map the Persian province name to Vilayar's numeric state id
 *      (config/vilayar/states.json covers every province).
 *   2. GET /search?state={id}&villaTypes={type}&page={n} - fully
 *      server-rendered cards (article.vila), no session or token needed.
 *      (The JSON AJAX path with _token exists but is unnecessary here.)
 *   3. Per card, GET /VillaDetails/{id} and read the public tel: link.
 *      Phones need no login on Vilayar.
 */
final class VilayarClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private array $categories = [];
    private array $states = [];
    private int $detailDelayMs;
    private int $detailTimeout;

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        array $categories = [],
        array $states = [],
        int $detailDelayMs = 200,
        int $detailTimeout = 15
    ) {
        $this->http = $http;
        $this->endpoint = rtrim($endpoint, '/');
        $this->categories = $categories;
        $this->states = $states;
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
     * Vilayar numeric state id for a Persian province name.
     */
    public function getStateId(string $provinceFa): ?string
    {
        $provinceFa = trim($provinceFa);

        if ($provinceFa === '' || !isset($this->states[$provinceFa])) {
            return null;
        }

        $id = trim((string) $this->states[$provinceFa]);

        return $id !== '' ? $id : null;
    }

    /**
     * Search one list page (cards only, without phones).
     *
     * @return array ['success' => true, 'cards' => [...], 'page_count' => int, 'list_url' => string]
     *               or ['success' => false, 'error' => '...']
     */
    public function searchPage(string $provinceFa, string $villaType, int $page = 1): array
    {
        $page = max(1, $page);

        if ($this->getCategoryLabel($villaType) === null) {
            return ['success' => false, 'error' => "Invalid category for Vilayar: $villaType"];
        }

        $stateId = $this->getStateId($provinceFa);

        if ($stateId === null) {
            return ['success' => false, 'error' => 'استان موردنظر در ویلایار پیدا نشد.'];
        }

        $params = ['state' => $stateId, 'villaTypes' => $villaType];
        if ($page > 1) {
            $params['page'] = $page;
        }
        $url = $this->endpoint . '/search?' . http_build_query($params);

        Logger::info('Vilayar search', ['url' => $url, 'page' => $page]);

        $result = $this->http->getRaw($url);

        if (!$result['success']) {
            Logger::error('Vilayar list fetch failed', ['error' => $result['error']]);
            return ['success' => false, 'error' => 'خطا در ارتباط با ویلایار'];
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
     * Fetch one detail page and extract its public phone and owner.
     *
     * @return array{phone: string|null, owner: string|null}
     */
    public function fetchDetail(string $detailUrl): array
    {
        $result = $this->http->getRaw($detailUrl, ['timeout' => $this->detailTimeout]);

        if (!$result['success'] || $result['body'] === '') {
            throw new \RuntimeException('Detail fetch failed: ' . ($result['error'] ?? 'empty response'));
        }

        return [
            'phone' => self::parseDetailPhone($result['body']),
            'owner' => self::parseDetailOwner($result['body']),
        ];
    }

    /**
     * Parse list cards from a Vilayar search page.
     *
     * @return array<int,array<string,mixed>> each: numeric_id, url, image, title,
     *         place, rating, price_text, specs
     */
    public static function parseListCards(string $html): array
    {
        $cards = [];

        if (stripos($html, 'article class="vila"') === false && stripos($html, "article class='vila'") === false) {
            return $cards;
        }

        if (!preg_match_all('#<article[^>]*class=["\']vila["\'][^>]*>(.*?)</article>#su', $html, $matches)) {
            return $cards;
        }

        foreach ($matches[1] as $block) {
            if (!preg_match('#/VillaDetails/(\d+)#', $block, $idMatch)) {
                continue;
            }

            $title = null;
            if (preg_match('#<h3[^>]*class=["\']title["\'][^>]*>.*?<a[^>]*>(.*?)</a>#su', $block, $t)) {
                $title = self::cleanText($t[1]);
            }
            if ($title === null || $title === '') {
                continue;
            }

            $image = null;
            if (preg_match('#<img[^>]*src="(https?://[^"]+|//[^"]+)"#i', $block, $img)) {
                $image = str_starts_with($img[1], '//') ? 'https:' . $img[1] : $img[1];
            }

            $rating = null;
            if (preg_match('#data-score="([\d.]+)"#', $block, $score) && (float) $score[1] > 0) {
                $rating = (float) $score[1];
            }

            $specs = [];
            if (preg_match_all('#<li[^>]*>.*?<span>(.*?)</span>#su', $block, $specMatches)) {
                foreach ($specMatches[1] as $raw) {
                    $clean = self::cleanText($raw);
                    if ($clean !== null && !in_array($clean, $specs, true)) {
                        $specs[] = $clean;
                    }
                }
            }

            $cards[] = [
                'numeric_id' => $idMatch[1],
                'url' => 'https://vilayar.com/VillaDetails/' . $idMatch[1],
                'image' => $image,
                'title' => $title,
                'place' => self::firstText($block, '#<p[^>]*class=["\']place[^"\']*["\'][^>]*>(.*?)</p>#su'),
                'rating' => $rating,
                'price_text' => self::firstText($block, '#<span[^>]*class=["\']price[^"\']*["\'][^>]*>(.*?)</span>#su'),
                'specs' => $specs === [] ? null : implode('، ', $specs),
            ];
        }

        return $cards;
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
     * First public tel: link of a detail page, normalized to Latin digits.
     */
    public static function parseDetailPhone(string $html): ?string
    {
        if (!preg_match_all('#href="tel:(?://)?([^"]+)"#i', $html, $matches)) {
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
     * Owner name from a detail page (.title-author in .box-property).
     */
    public static function parseDetailOwner(string $html): ?string
    {
        if (preg_match('#class=["\']title-author["\'][^>]*>(.*?)<#su', $html, $m)) {
            return self::cleanText($m[1]);
        }

        return null;
    }

    /**
     * Persian display price ("6,500,000 تومان" -> "۶,۵۰۰,۰۰۰ تومان").
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
}
