<?php

namespace Src\Divar;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;

/**
 * DivarClient - Scrapes Divar (Iranian classifieds) for rental listings.
 *
 * It fetches the Divar listing page for a city and category
 * and extracts structured data from the page.
 */
final class DivarClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private array $categories = [];
    private array $cityMap = []; // slug -> city info
    private string $userAgent;

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        array $categories = [],
        string $userAgent = '',
        array $cityMap = []
    ) {
        $this->http = $http;
        $this->endpoint = rtrim($endpoint, '/');
        $this->categories = $categories;
        $this->userAgent = $userAgent;
        $this->cityMap = $cityMap;
    }

    /**
     * Search for listings on Divar.
     *
     * @param string $citySlug  Divar city slug (e.g. 'tabriz', 'tehran')
     * @param string $category  Category key from config (e.g. 'rent-temporary')
     * @param string $query     Optional search query (e.g. 'بوم گردی', 'ویلا')
     * @param int    $page      Page number (not used in basic scraping)
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, string $query = '', int $page = 1): array
    {
        // Validate category
        if (!isset($this->categories[$category])) {
            return [
                'success' => false,
                'error' => "Invalid category for Divar: $category",
            ];
        }

        // Get city info
        $cityInfo = $this->cityMap[$citySlug] ?? null;
        if (!$cityInfo) {
            // Fallback: use slug as Divar slug
            $divarCitySlug = $citySlug;
        } else {
            $divarCitySlug = $cityInfo['divar_slug'] ?? $cityInfo['slug'] ?? $citySlug;
        }

        $url = $this->endpoint . '/' . $divarCitySlug . '/' . $category;
        if (!empty($query)) {
            $url .= '?q=' . rawurlencode($query);
        }

        Logger::info('Divar search', ['url' => $url]);

        $raw = $this->http->getRaw($url, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept-Language' => 'fa,en;q=0.9',
            ]
        ]);

        if (!$raw['success']) {
            Logger::error('Divar scrape failed', ['error' => $raw['error']]);
            return [
                'success' => false,
                'error' => $raw['error'] ?? 'Unknown HTTP error',
            ];
        }

        $html = $raw['body'] ?? '';
        if (empty($html)) {
            return ['success' => false, 'error' => 'Empty response'];
        }

        // Extract listings from Divar's page
        $listings = $this->extractListings($html, $divarCitySlug, $category);

        return [
            'success'   => true,
            'title'     => $this->categories[$category] ?? $category,
            'total'     => count($listings),
            'page'      => $page,
            'page_count'=> 1,
            'places'    => $listings,
        ];
    }

    /**
     * Extract listing data from Divar HTML.
     */
    private function extractListings(string $html, string $citySlug, string $category): array
    {
        $listings = [];

        // Divar uses JSON-LD structured data for listings
        // Pattern: <script type="application/ld+json"> with @type "Product" or "Offer"
        if (preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/is', $html, $matches)) {
            foreach ($matches[1] as $jsonString) {
                $data = json_decode($jsonString, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                // Handle both single object and array
                $items = is_array($data) && isset($data['@type']) ? [$data] : (is_array($data) ? $data : []);
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $listing = $this->extractListingFromJsonLd($item, $citySlug, $category);
                    if ($listing) {
                        $listings[] = $listing;
                    }
                }
            }
        }

        // Also try to extract from the initial data script (Divar often embeds data in window.__INITIAL_DATA__)
        if (preg_match('/window\.__INITIAL_DATA__\s*=\s*({.*?});/s', $html, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data && isset($data['listWidgets'])) {
                foreach ($data['listWidgets'] as $widget) {
                    if (isset($widget['data']['items'])) {
                        foreach ($widget['data']['items'] as $item) {
                            $listing = $this->extractListingFromWidget($item, $citySlug, $category);
                            if ($listing) {
                                $listings[] = $listing;
                            }
                        }
                    }
                }
            }
        }

        return $listings;
    }

    /**
     * Extract a listing from JSON-LD Product/Offer object.
     */
    private function extractListingFromJsonLd(array $item, string $citySlug, string $category): ?array
    {
        // Divar uses Product for listings
        if (($item['@type'] ?? '') !== 'Product') {
            return null;
        }

        $name = $item['name'] ?? null;
        if (!$name) {
            return null;
        }

        $description = $item['description'] ?? null;
        $url = $item['url'] ?? null;
        $image = $item['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        // Price
        $price = null;
        if (isset($item['offers'])) {
            $offers = $item['offers'];
            if (is_array($offers)) {
                foreach ($offers as $offer) {
                    if (isset($offer['price'])) {
                        $price = $offer['price'];
                        break;
                    }
                }
            } elseif (is_array($offers) && isset($offers['price'])) {
                $price = $offers['price'];
            }
        }

        // Location - Divar might have address in the description or a separate field
        $address = null;
        if (isset($item['address'])) {
            $addr = $item['address'];
            if (is_string($addr)) {
                $address = $addr;
            } elseif (is_array($addr)) {
                $address = $addr['streetAddress'] ?? ($addr['addressLocality'] ?? null);
            }
        }

        // Generate place_id
        $placeId = 'divar_' . md5($name . $description . $url);

        return [
            'name'         => $name,
            'address'      => $address,
            'description'  => $description,
            'price'        => $price,
            'telephone'    => null, // Divar doesn't show phone in listing page without login
            'website'      => $url ? 'https://divar.ir' . $url : null,
            'category'     => $category,
            'city_slug'    => $citySlug,
            'latitude'     => null,
            'longitude'    => null,
            'image_preview'=> $image,
            'place_id'     => $placeId,
            'divar_url'    => $url ? 'https://divar.ir' . $url : null,
        ];
    }

    /**
     * Extract a listing from Divar's widget data (window.__INITIAL_DATA__).
     */
    private function extractListingFromWidget(array $item, string $citySlug, string $category): ?array
    {
        // Widget items have different structure
        $title = $item['title'] ?? $item['data']['title'] ?? null;
        if (!$title) {
            return null;
        }

        $description = $item['description'] ?? $item['data']['description'] ?? null;
        $url = $item['url'] ?? $item['data']['url'] ?? null;
        $image = $item['image'] ?? $item['data']['image'] ?? null;
        if (is_array($image)) {
            $image = $image[0] ?? null;
        }

        // Price
        $price = null;
        if (isset($item['price'])) {
            $price = $item['price'];
        } elseif (isset($item['data']['price'])) {
            $price = $item['data']['price'];
        }

        // Location
        $address = $item['location'] ?? $item['data']['location'] ?? null;

        // Generate place_id
        $placeId = 'divar_' . md5($title . $description . $url);

        return [
            'name'         => $title,
            'address'      => $address,
            'description'  => $description,
            'price'        => $price,
            'telephone'    => null,
            'website'      => $url ? 'https://divar.ir' . $url : null,
            'category'     => $category,
            'city_slug'    => $citySlug,
            'latitude'     => null,
            'longitude'    => null,
            'image_preview'=> $image,
            'place_id'     => $placeId,
            'divar_url'    => $url ? 'https://divar.ir' . $url : null,
        ];
    }

    /**
     * Get details for listings (not implemented for scraping).
     *
     * @param array $placeIds Array of place IDs from search
     * @return array ['success' => true, 'items' => []]
     */
    public function getDetails(array $placeIds): array
    {
        // Scraping does not provide easy detail fetching without additional requests.
        // Return empty for compatibility.
        return ['success' => true, 'items' => []];
    }
}