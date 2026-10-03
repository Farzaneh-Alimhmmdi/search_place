<?php

namespace Src\Makanchi;

use Src\Support\Logger;

/**
 * MakanchiSearchService - Orchestrates the Makanchi search flow.
 *
 * One page = one list fetch plus one detail fetch per card (phones live on
 * detail pages). Detail fetches are spaced with a small polite delay and each
 * has its own short timeout, so a single slow card cannot stall the page.
 */
final class MakanchiSearchService
{
    private MakanchiClient $client;
    private string $endpoint;

    public function __construct(MakanchiClient $client, string $endpoint = 'https://makanchi.com')
    {
        $this->client = $client;
        $this->endpoint = rtrim($endpoint, '/');
    }

    /**
     * Execute the search flow for one list page, phones included.
     *
     * @param string $cityFa   Persian city/province name (e.g. 'تهران', 'ساری')
     * @param string $category Makanchi type value (e.g. 'villa', 'ecolodge')
     * @param int    $page     List page number (Persian صفحه= param)
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, ...]
     */
    public function search(string $cityFa, string $category, int $page = 1): array
    {
        $page = max(1, $page);
        $label = $this->client->getCategoryLabel($category);

        if ($label === null) {
            return ['success' => false, 'error' => 'دسته‌بندی انتخاب‌شده معتبر نیست.'];
        }

        $pageResult = $this->client->searchPage($cityFa, $category, $page);

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

            $detailUrl = $this->endpoint . $card['href'];
            $phone = null;
            $address = $card['city'];

            try {
                $detail = $this->client->fetchDetail($detailUrl);
                $phone = $detail['phone'];
                if ($detail['address'] !== null) {
                    $address = $detail['address'];
                }
            } catch (\Throwable $e) {
                Logger::warning('Makanchi detail fetch failed', [
                    'url' => $detailUrl,
                    'error' => $e->getMessage(),
                ]);
            }

            $places[] = $this->formatPlace($card, $detailUrl, $phone, $address, $label, $category);
        }

        $cityShown = $cityFa !== '' ? $cityFa : '';

        return [
            'success' => true,
            'title' => $label . ($cityShown !== '' ? ' در ' . $cityShown : ''),
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
        string $detailUrl,
        ?string $phone,
        ?string $address,
        string $categoryLabel,
        string $categoryValue
    ): array {
        $externalId = $card['numeric_id'] !== null ? 'makanchi_' . $card['numeric_id'] : null;

        if ($externalId === null) {
            $externalId = 'makanchi_' . md5($detailUrl);
        }

        $parts = [];
        if ($card['description'] !== null) {
            $parts[] = $card['description'];
        }
        if ($card['capacity_text'] !== null) {
            $parts[] = $card['capacity_text'];
        }

        return [
            'id' => $externalId,
            'token' => $externalId,
            'name' => $card['title'] ?? 'نامشخص',
            'address' => $address,
            'telephone' => $phone,
            'phone' => $phone,
            'website' => null,
            'category' => $categoryLabel,
            'category_value' => $categoryValue,
            'latitude' => null,
            'longitude' => null,
            'price' => MakanchiClient::faPrice($card['price_text'] ?? null),
            'price_raw' => MakanchiClient::numericPrice($card['price_text'] ?? null),
            'image_preview' => $card['image'] ?? null,
            'makanchi_url' => $detailUrl,
            'rating' => null,
            'instagram_id' => null,
            'description' => $parts === [] ? null : implode('، ', $parts),
        ];
    }
}
