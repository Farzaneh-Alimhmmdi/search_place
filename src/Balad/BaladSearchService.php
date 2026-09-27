<?php

namespace Src\Balad;

/**
 * BaladSearchService - Orchestrates the two-step search flow.
 *
 * The Raah API requires a two-step process:
 *   1. Search      → get a list of location tokens (IDs)
 *   2. Preview-bulk → fetch full details using those tokens
 *
 * This class coordinates both steps in the correct order so the controller
 * only needs to call $service->search() once.
 */
final class BaladSearchService
{
    private BaladClient $client;

    public function __construct(BaladClient $client)
    {
        $this->client = $client;
    }

    /**
     * Execute the full search flow: search → getDetails.
     *
     * Calls BaladClient::search() to get tokens, then passes those tokens
     * to BaladClient::getDetails() to retrieve complete place information.
     *
     * @param string $citySlug  URL-safe city identifier
     * @param string $category  e.g. 'hotel', 'restaurant'
     * @param int    $page      Result page number
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $searchResult = $this->client->search($citySlug, $category, $page);
        if (!$searchResult['success']) return $searchResult;

        $detailsResult = $this->client->getDetails($searchResult['tokens']);
        if (!$detailsResult['success']) return $detailsResult;

        return [
            'success'   => true,
            'title'     => $searchResult['title'],
            'total'     => $searchResult['total'],
            'page'      => $page,
            'page_count'=> $searchResult['page_count'],
            'places'    => $detailsResult['items'],
        ];
    }
}
