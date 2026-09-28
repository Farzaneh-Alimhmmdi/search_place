<?php

namespace Src\Divar;

/**
 * DivarSearchService - Orchestrates the search flow for Divar.
 *
 * Delegates to the client and formats results.
 */
final class DivarSearchService
{
    private DivarClient $client;

    public function __construct(DivarClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the search flow.
     *
     * @param string $citySlug  Divar city slug
     * @param string $category  Category key from config
     * @param string $query     Optional search query
     * @param int    $page      Result page number
     * @param int    $maxPages  Maximum number of pages to fetch
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, string $query = '', int $page = 1, int $maxPages = 1): array
    {
        $searchResult = $this->client->search($citySlug, $category, $query, $page, $maxPages);
        if (!$searchResult['success']) {
            return $searchResult;
        }

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