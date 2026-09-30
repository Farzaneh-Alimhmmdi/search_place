<?php

namespace Src\Neshan;

/** Formats the scraped Neshan results for the common search view. */
final class NeshanSearchService
{
    private NeshanClient $client;

    public function __construct(NeshanClient $client)
    {
        $this->client = $client;
    }

    /**
     * Search one page while preserving Neshan's real infinite-scroll pagination.
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $searchResult = $this->client->search($citySlug, $category, $page);
        if (empty($searchResult['success'])) {
            return $searchResult;
        }

        $places = $this->formatPlaces($searchResult['places'] ?? []);

        return [
            'success'        => true,
            'title'          => $searchResult['title'] ?? $category,
            'total'          => count($places),
            // Neshan does not publish a total count for this UI search. Keep it
            // null until the scraper reaches the end rather than showing a
            // misleading per-page count as the total.
            'total_results'  => $searchResult['total_results'] ?? null,
            'loaded_results' => $searchResult['loaded_results'] ?? count($places),
            'page'           => $searchResult['page'] ?? max(1, $page),
            'page_count'     => $searchResult['page_count'] ?? 1,
            'has_more'       => (bool)($searchResult['has_more'] ?? false),
            'complete'       => (bool)($searchResult['complete'] ?? false),
            'places'         => $places,
        ];
    }

    /** Fetch every page for export or background jobs. */
    public function searchAllPages(string $citySlug, string $category): array
    {
        $searchResult = $this->client->searchAllPages($citySlug, $category);
        if (empty($searchResult['success'])) {
            return $searchResult;
        }

        $places = $this->formatPlaces($searchResult['places'] ?? []);
        return [
            'success'        => true,
            'title'          => $category,
            'total'          => count($places),
            'total_results'  => count($places),
            'loaded_results' => count($places),
            'page'           => $searchResult['page'] ?? 1,
            'page_count'     => $searchResult['page_count'] ?? 1,
            'has_more'       => false,
            'complete'       => true,
            'places'         => $places,
        ];
    }

    private function formatPlaces(array $places): array
    {
        $formatted = [];
        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $sourceId = $place['place_id']
                ?? $place['id']
                ?? $place['neshan_url']
                ?? (($place['name'] ?? '') . '|' . ($place['address'] ?? '') . '|' . ($place['phone'] ?? ''));
            $stableId = 'neshan_' . md5((string)$sourceId);

            $formatted[] = [
                'id'            => $stableId,
                'place_id'      => $place['place_id'] ?? null,
                'name'          => $place['name'] ?? 'نامشخص',
                'address'       => $place['address'] ?? null,
                'telephone'     => $place['phone'] ?? null,
                'website'       => $place['website'] ?? null,
                'category'      => $place['category'] ?? null,
                'latitude'      => $place['latitude'] ?? null,
                'longitude'     => $place['longitude'] ?? null,
                'image_preview' => null,
                'neshan_url'    => $place['neshan_url'] ?? null,
                'rating'        => $place['rating'] ?? null,
                'instagram_id'  => $place['instagram_id'] ?? null,
            ];
        }

        return $formatted;
    }
}
