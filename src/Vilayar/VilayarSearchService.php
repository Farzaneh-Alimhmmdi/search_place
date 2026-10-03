<?php

namespace Src\Vilayar;

use Src\Support\Logger;

/**
 * VilayarSearchService - Orchestrates the Vilayar search flow.
 *
 * One page = one list fetch plus one detail fetch per card (owner phones
 * live on detail pages and need no login). Detail fetches are spaced with a
 * small polite delay and each has its own short timeout, so a single slow
 * card cannot stall the page.
 */
final class VilayarSearchService
{
    private VilayarClient $client;

    public function __construct(VilayarClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the search flow for one list page, phones included.
     *
     * @param string $provinceFa Persian province name (e.g. 'مازندران')
     * @param string $villaType  Vilayar type value (e.g. '7' = استخردار)
     * @param int    $page       List page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, ...]
     */
    public function search(string $provinceFa, string $villaType, int $page = 1): array
    {
        $page = max(1, $page);
        $label = $this->client->getCategoryLabel($villaType);

        if ($label === null) {
            return ['success' => false, 'error' => 'دسته‌بندی انتخاب‌شده معتبر نیست.'];
        }

        $pageResult = $this->client->searchPage($provinceFa, $villaType, $page);

        if (!$pageResult['success']) {
            return $pageResult;
        }

        $places = [];
        $first = true;

        foreach ($pageResult['cards'] as $card) {
            // Polite pause between detail fetches (not before the first one).
            if (!$first && $this->client->getDetailDelayMs() > 0) {
                usleep($this->client->getDetailDelayMs() * 1000);
            }
            $first = false;

            $phone = null;
            $owner = null;

            try {
                $detail = $this->client->fetchDetail($card['url']);
                $phone = $detail['phone'];
                $owner = $detail['owner'];
            } catch (\Throwable $e) {
                Logger::warning('Vilayar detail fetch failed', [
                    'url' => $card['url'],
                    'error' => $e->getMessage(),
                ]);
            }

            $places[] = $this->formatPlace($card, $phone, $owner, $label, $villaType, $provinceFa);
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
        ?string $owner,
        string $categoryLabel,
        string $categoryValue,
        string $provinceFa
    ): array {
        $externalId = 'vilayar_' . $card['numeric_id'];

        $parts = [];
        if ($card['specs'] !== null) {
            $parts[] = $card['specs'];
        }
        if ($owner !== null) {
            $parts[] = 'میزبان: ' . $owner;
        }

        return [
            'id' => $externalId,
            'token' => $externalId,
            'name' => $card['title'] ?? 'نامشخص',
            'address' => $card['place'] ?? trim($provinceFa),
            'telephone' => $phone,
            'phone' => $phone,
            'website' => null,
            'category' => $categoryLabel,
            'category_value' => $categoryValue,
            'latitude' => null,
            'longitude' => null,
            'price' => VilayarClient::faPrice($card['price_text'] ?? null),
            'price_raw' => VilayarClient::numericPrice($card['price_text'] ?? null),
            'image_preview' => $card['image'] ?? null,
            'vilayar_url' => $card['url'],
            'rating' => $card['rating'] ?? null,
            'instagram_id' => null,
            'description' => $parts === [] ? null : implode('، ', $parts),
        ];
    }
}
