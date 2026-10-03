<?php

namespace Src\Behtarino;

use Src\Support\Logger;

/**
 * BehtarinoSearchService - Orchestrates the Behtarino search flow.
 *
 * One page = one list fetch per city (merged) plus one detail fetch per card
 * (phones are embedded in detail pages and need no login). Detail fetches are
 * spaced with a small polite delay and each has its own short timeout, so a
 * single slow card cannot stall the page.
 */
final class BehtarinoSearchService
{
    private BehtarinoClient $client;

    public function __construct(BehtarinoClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the search flow for one page, phones included.
     *
     * @param string $provinceFa Persian province name (e.g. 'مازندران')
     * @param string $category   Behtarino section value (e.g. 'اقامتگاه-بومگردی')
     * @param int    $page       List page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, ...]
     */
    public function search(string $provinceFa, string $category, int $page = 1): array
    {
        $page = max(1, $page);
        $label = $this->client->getCategoryLabel($category);

        if ($label === null) {
            return ['success' => false, 'error' => 'دسته‌بندی انتخاب‌شده معتبر نیست.'];
        }

        $pageResult = $this->client->searchPage($provinceFa, $category, $page);

        if (!$pageResult['success']) {
            return $pageResult;
        }

        $cards = $pageResult['cards'];
        $detailUrls = [];
        foreach ($cards as $i => $card) {
            $detailUrls[$i] = $card['url'];
        }

        $phones = [];
        if ($detailUrls !== []) {
            try {
                $phones = $this->client->fetchDetailPhones($detailUrls);
            } catch (\Throwable $e) {
                Logger::warning('Behtarino batch detail fetch failed', ['error' => $e->getMessage()]);
                foreach ($detailUrls as $i => $url) {
                    $phones[$i] = null;
                }
            }
        }

        $places = [];
        foreach ($cards as $i => $card) {
            $phone = $phones[$i] ?? null;

            $places[] = $this->formatPlace($card, $phone, $label, $category);
        }

        return [
            'success' => true,
            'title' => $label . ' در ' . trim($provinceFa),
            'total' => count($places),
            'total_results' => count($places),
            'page' => $page,
            'page_count' => $pageResult['page_count'],
            'places' => $places,
        ];
    }

    /**
     * @param array<string,mixed> $card
     * @return array<string,mixed>
     */
    private function formatPlace(
        array $card,
        ?string $phone,
        string $categoryLabel,
        string $categoryValue
    ): array {
        $parts = [];
        if ($card['review_count'] !== null) {
            $parts[] = 'بر اساس ' . BehtarinoClient::faDigits((string) $card['review_count']) . ' نظر';
        }

        return [
            'id' => $card['external_id'],
            'token' => $card['external_id'],
            'name' => $card['name'] ?? 'نامشخص',
            'address' => $card['address'] ?? null,
            'telephone' => $phone,
            'phone' => $phone,
            'website' => null,
            'category' => $categoryLabel,
            'category_value' => $categoryValue,
            'latitude' => $card['latitude'] ?? null,
            'longitude' => $card['longitude'] ?? null,
            'price' => null,
            'price_raw' => null,
            'image_preview' => $card['image'] ?? null,
            'behtarino_url' => $card['url'],
            'rating' => $card['rating'] ?? null,
            'instagram_id' => null,
            'description' => $parts === [] ? null : implode('، ', $parts),
        ];
    }
}
