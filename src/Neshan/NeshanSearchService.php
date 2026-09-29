<?php

namespace Src\Neshan;

/**
 * NeshanSearchService - Orchestrates the search flow for Neshan.
 * 
 * Fixed issues:
 * 1. Works with headless Chrome for server execution
 * 2. Fetches all pages when needed (complete data)
 * 3. Supports proper pagination (server-side, memory efficient)
 * 4. Falls back to JSON file if Python fails
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

        // Use the places from the client (already parsed from Python or file)
        $places = $searchResult['places'] ?? [];
        
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
            'total_results' => $searchResult['total_results'] ?? count($formattedPlaces),
            'page'      => $page,
            'page_count'=> $searchResult['page_count'] ?? 1,
            'has_more'  => $searchResult['has_more'] ?? false,
            'places'    => $formattedPlaces,
        ];
    }
    
    /**
     * Search all pages at once (for complete data export)
     * Use with caution - can be slow and memory-intensive
     * 
     * @param string $citySlug URL-safe city identifier
     * @param string $category Category value
     * @return array All places across all pages
     */
    public function searchAllPages(string $citySlug, string $category): array
    {
        $searchResult = $this->client->searchAllPages($citySlug, $category);
        
        if (!$searchResult['success']) {
            return $searchResult;
        }

        // Use the places from the client (already parsed from Python or file)
        $places = $searchResult['places'] ?? [];
        
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
            'title'     => $category,
            'total'     => count($formattedPlaces),
            'total_results' => count($formattedPlaces),
            'page'      => 1,
            'page_count'=> 1,
            'has_more'  => false,
            'places'    => $formattedPlaces,
        ];
    }
}