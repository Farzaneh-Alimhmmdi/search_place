<?php

namespace Src\Neshan;

/**
 * NeshanSearchService - Orchestrates the search flow for Neshan.
 * 
 * Similar to BaladSearchService but works with NeshanClient which
 * calls an external Python script for searching.
 */
final class NeshanSearchService
{
    private NeshanClient $client;

    public function __construct(NeshanClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the search flow.
     * 
     * @param string $citySlug URL-safe city identifier
     * @param string $category Category value from categories.php
     * @param int $page Page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $searchResult = $this->client->search($citySlug, $category, $page);
        if (!$searchResult['success']) {
            return $searchResult;
        }

        // Use the raw places from the Python script
        $places = $searchResult['_raw_places'] ?? [];
        
        // Convert to the same format as Balad results
        $formattedPlaces = array_map(function ($place) {
            $placeId = 'neshan_' . md5(($place['name'] ?? '') . ($place['address'] ?? '') . ($place['phone'] ?? ''));
            return [
                'id'           => $placeId,
                'name'         => $place['name'] ?? 'نامشخص',
                'address'      => $place['address'] ?? null,
                'telephone'    => $place['phone'] ?? null,
                'website'      => $place['website'] ?? null,
                'category'     => $place['category'] ?? null,
                'latitude'     => $place['latitude'] ?? null,
                'longitude'    => $place['longitude'] ?? null,
                'image_preview'=> null, // Neshan doesn't provide image previews easily
                'neshan_url'   => $place['neshan_url'] ?? null,
                'rating'       => $place['rating'] ?? null,
                'instagram_id' => $place['instagram_id'] ?? null,
            ];
        }, $places);

        return [
            'success'   => true,
            'title'     => $searchResult['title'] ?? $category,
            'total'     => $searchResult['total'] ?? count($formattedPlaces),
            'page'      => $page,
            'page_count'=> $searchResult['page_count'] ?? 1,
            'places'    => $formattedPlaces,
        ];
    }
}