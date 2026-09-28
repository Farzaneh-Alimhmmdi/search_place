<?php

namespace Src\Balad;

use Src\Exceptions\BaladRequestException;
use Src\Http\CurlHttpClient;
use Src\Support\Logger;

/**
 * BaladClient - Low-level HTTP client for the Raah (بالاد) API.
 *
 * It handles raw communication with two Raah API endpoints:
 * 1. Search endpoint  → returns location tokens
 * 2. Preview-bulk endpoint → returns place details using those tokens
 *
 * A "token" is a unique string identifier for a location (hotel, restaurant, etc.)
 * returned by the search endpoint. It is needed to fetch the actual place details.
 * Think of it as an ID: the search gives you a list of IDs (tokens), and the
 * preview-bulk endpoint takes those IDs to return full information (name, address,
 * coordinates, image, etc.) for each location.
 */
final class BaladClient
{
    private CurlHttpClient $http;
    private string $searchUrl;
    private string $previewBulkUrl;

    public function __construct(
        CurlHttpClient $http,
        string $searchUrl,
        string $previewBulkUrl
    ) {
        $this->http = $http;
        $this->searchUrl = $searchUrl;
        $this->previewBulkUrl = $previewBulkUrl;
    }

    /**
     * Step 1: Search the Balad API for locations in a city and category.
     *
     * Returns an array containing:
     * - 'tokens':  array of location identifiers (IDs) from the API
     * - 'title':   display title for the search results
     * - 'page_count': total number of pages available
     * - 'total':   number of results found
     *
     * @param string $citySlug  URL-safe city identifier (e.g. 'tehran')
     * @param string $category  e.g. 'hotel', 'restaurant'
     * @param int    $page      Result page number
     * @return array ['success' => true, 'tokens' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        $url = $this->searchUrl
            . '?region=city-' . rawurlencode($citySlug)
            . '&name=' . rawurlencode($category)
            . '&page=' . $page;

        Logger::info('Balad V4 search', ['url' => $url]);

        $result = $this->http->get($url, [
            'headers' => [
                'sec-ch-ua' => '"Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"macOS"',
            ]
        ]);

        $items = $result['data']['items'] ?? [];

        if (empty($items)) {
            return [
                'success'   => true,
                'tokens'    => [],
                'title'     => $result['data']['title'] ?? $category,
                'page_count'=> $result['data']['page_count'] ?? 1,
                'total'     => 0,
            ];
        }

        return [
            'success'   => true,
            'tokens'    => $items,
            'title'     => $result['data']['title'] ?? $category,
            'page_count'=> $result['data']['page_count'] ?? 1,
            'total'     => count($items),
        ];
    }

    /**
     * Step 2: Fetch full details for locations using their tokens (IDs).
     *
     * Takes an array of tokens returned by search() and calls the preview-bulk
     * endpoint to get complete information (name, address, coordinates, image, etc.)
     * for each location.
     *
     * @param array $tokens Array of location identifiers from search()
     * @return array ['success' => true, 'items' => [...places...]] or ['success' => false, ...]
     */
    public function getDetails(array $tokens): array
    {
        $url = $this->previewBulkUrl . implode(',', $tokens);
        Logger::info('Raah V4 preview-bulk', ['url' => $url, 'token_count' => count($tokens)]);

        $result = $this->http->get($url, [
            'headers' => [
                'sec-ch-ua' => '"Not=A?Brand";v="99", "Google Chrome";v="151", "Chromium";v="151"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"macOS"',
            ]
        ]);

        $places = [];
        foreach ($result['data']['items'] ?? [] as $item) {
            $coords = $item['geometry']['coordinates'] ?? [];
            $image  = $item['image'] ?? [];

            // Extract rating from the rating object
            $rating = null;
            if (isset($item['rating']['score']) && is_numeric($item['rating']['score'])) {
                $rating = (float)$item['rating']['score'];
            }

            // Extract Instagram ID - not available in preview-bulk, check images sources
            $instagramId = null;
            if (isset($item['images']) && is_array($item['images'])) {
                foreach ($item['images'] as $img) {
                    if (($img['source'] ?? '') === 'instagram' && isset($img['profile']['username'])) {
                        $instagramId = $img['profile']['username'];
                        break;
                    }
                }
            }

            $places[] = [
                'id'           => $item['token'] ?? null,
                'token'        => $item['token'] ?? null,
                'name'         => $item['name'] ?? 'نامشخص',
                'address'      => $item['address'] ?? null,
                'telephone'    => $item['telephone'] ?? null,
                'website'      => $item['website'] ?? null,
                'category'     => $item['category'] ?? null,
                'latitude'     => $coords[1] ?? null,
                'longitude'    => $coords[0] ?? null,
                'image_preview'=> $image['preview'] ?? null,
                'balad_url'    => ($item['url_title'] ?? null) && ($item['token'] ?? null)
                    ? 'https://balad.ir/p/' . $item['url_title'] . '-' . $item['token'] . '/'
                    : null,
                'rating'       => $rating,
                'instagram_id' => $instagramId,
            ];
        }

        return ['success' => true, 'items' => $places];
    }
}
