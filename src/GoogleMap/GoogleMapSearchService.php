<?php

namespace Src\GoogleMap;

/**
 * GoogleMapSearchService - Orchestrates the search flow for Google Maps.
 *
 * Since Google Maps Text Search already returns relatively complete information,
 * we mainly delegate to the client. This service exists for interface compatibility.
 */
final class GoogleMapSearchService
{
    private GoogleMapClient $client;

    public function __construct(GoogleMapClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the search flow.
     *
     * @param string $citySlug  URL-safe city identifier
     * @param string $category  e.g. 'hotel', 'restaurant'
     * @param int    $page      Result page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $searchResult = $this->client->search($citySlug, $category, $page);
        if (!$searchResult['success']) {
            return $searchResult;
        }

        // Optionally, we could fetch details for each place using getDetails,
        // but for now we just return the search results as places.
        return [
            'success'   => true,
            'title'     => $searchResult['title'],
            'total'     => $searchResult['total'],
            'page'      => $searchResult['page'],
            'page_count'=> $searchResult['page_count'],
            'places'    => $searchResult['places'],
        ];
    }
}