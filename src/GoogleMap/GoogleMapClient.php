<?php

namespace Src\GoogleMap;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;

/**
 * GoogleMapClient - Retrieves place data from Google Places API or falls back to scraping.
 *
 * If an API key is provided, uses the official Places Text Search API.
 * Otherwise, falls back to scraping Google search results (less reliable).
 */
final class GoogleMapClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private ?string $apiKey;
    private string $language;
    private string $region;
    private array $cityMap = []; // slug -> city name (Persian)
    private array $reverseCityMap = []; // city name (Persian) -> slug (for debugging)

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        ?string $apiKey = null,
        string $language = 'fa',
        string $region = 'ir',
        array $cityMap = []
    ) {
        $this->http = $http;
        $this->endpoint = $endpoint;
        $this->apiKey = $apiKey;
        $this->language = $language;
        $this->region = $region;
        $this->cityMap = $cityMap;
        $this->reverseCityMap = array_flip($cityMap);
    }

    /**
     * Search for places using Google Places Text Search API (if API key provided)
     * or fallback to scraping (if no API key).
     *
     * @param string $citySlug  URL-safe city identifier (e.g. 'tehran')
     * @param string $category  e.g. 'hotel', 'restaurant'
     * @param int    $page      Result page number (ignored)
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, int $page = 1): array
    {
        // If we have an API key, use the official Places Text Search API
        if (!empty($this->apiKey)) {
            $cityName = $this->cityMap[$citySlug] ?? $citySlug;
            $query = rawurlencode("$category in $cityName");
            $url = $this->endpoint .
                   '?query=' . rawurlencode($query) .
                   '&language=' . $this->language .
                   '&region=' . $this->region .
                   '&key=' . $this->apiKey;

            Logger::info('Google Places API search', ['url' => $url]);

            $result = $this->http->get($url, [
                'headers' => [] // No special headers needed
            ]);

            if (!$result['success']) {
                Logger::error('Google Places API request failed', ['error' => $result['error']]);
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Unknown HTTP error',
                ];
            }

            $data = $result['data'] ?? [];
            $status = $data['status'] ?? '';
            $errorMessage = $data['error_message'] ?? '';

            if ($status !== 'OK' && $status !== 'ZERO_RESULTS') {
                Logger::error('Google Places API error', [
                    'status' => $status,
                    'error_message' => $errorMessage
                ]);
                return [
                    'success' => false,
                    'error' => $errorMessage ?: "Places API returned status: $status",
                ];
            }

            $results = $data['results'] ?? [];
            $places = [];

            foreach ($results as $item) {
                $name = $item['name'] ?? null;
                if (!$name) {
                    continue;
                }

                $address = $item['formatted_address'] ?? null;
                $telephone = $item['formatted_phone_number'] ?? null;
                $website = $item['website'] ?? null;

                $types = $item['types'] ?? [];
                $categoryType = is_array($types) && !empty($types) ? $types[0] : null;

                $geometry = $item['geometry']['location'] ?? [];
                $latitude = $geometry['lat'] ?? null;
                $longitude = $geometry['lng'] ?? null;

                $imagePreview = null;
                $photos = $item['photos'] ?? [];
                if (!empty($photos)) {
                    $photoRef = $photos[0]['photo_reference'] ?? null;
                    if ($photoRef) {
                        $imagePreview = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=400&photoreference=' .
                            $photoRef . '&key=' . $this->apiKey;
                    }
                }

                $placeId = $item['place_id'] ?? null;

                $places[] = [
                    'name'         => $name,
                    'address'      => $address,
                    'telephone'    => $telephone,
                    'website'      => $website,
                    'category'     => $categoryType,
                    'latitude'     => $latitude,
                    'longitude'    => $longitude,
                    'image_preview'=> $imagePreview,
                    'place_id'     => $placeId,
                ];
            }

            return [
                'success'   => true,
                'title'     => $category,
                'total'     => count($places),
                'page'      => $page,
                'page_count'=> 1,
                'places'    => $places,
            ];
        }

        // Fallback to scraping (kept for compatibility when no API key)
        // Get Persian city name from slug map; fallback to slug itself
        $cityName = $this->cityMap[$citySlug] ?? $citySlug;
        // If still looks like a slug (contains hyphens) and not found, we could try to convert,
        // but we'll just use as is.

        $query = rawurlencode("$category in $cityName");
        $url = $this->endpoint . '?q=' . $query .
               '&hl=' . $this->language .
               '&gl=' . $this->region .
               '&num=10'; // request up to 10 results

        Logger::info('Google search scrape (fallback)', ['url' => $url]);

        $raw = $this->http->getRaw($url, [
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
                'Accept-Language' => $this->language . ',en;q=0.9',
            ]
        ]);

        if (!$raw['success']) {
            Logger::error('Google search scrape failed', ['error' => $raw['error']]);
            return [
                'success' => false,
                'error' => $raw['error'] ?? 'Unknown HTTP error',
            ];
        }

        $html = $raw['body'] ?? '';
        if (empty($html)) {
            return ['success' => false, 'error' => 'Empty response'];
        }

        // Extract JSON-LD blocks
        $places = [];
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
                    $types = is_array($item['@type'] ?? null) ? $item['@type'] : (is_string($item['@type'] ?? null) ? [$item['@type']] : []);
                    $isLocalBusiness = false;
                    foreach ($types as $t) {
                        if (is_string($t) && (strpos($t, 'LocalBusiness') !== false || $t === 'Restaurant' || $t === 'Lodging' || $t === 'FoodEstablishment' || $t === 'Place')) {
                            $isLocalBusiness = true;
                            break;
                        }
                    }
                    if (!$isLocalBusiness) {
                        continue;
                    }
                    $place = $this->extractPlaceFromJsonLd($item);
                    if ($place) {
                        $places[] = $place;
                    }
                }
            }
        }

        // If no JSON-LD results, we could try to parse HTML directly, but skip for simplicity.
        if (count($places) === 0) {
            // Debug: save HTML to file for inspection
            $debugFile = dirname(__DIR__, 3) . '/debug_google.html';
            file_put_contents($debugFile, $html ?? '', FILE_APPEND);
            file_put_contents($debugFile, "\n---\n", FILE_APPEND);
        }

        return [
            'success'   => true,
            'title'     => $category,
            'total'     => count($places),
            'page'      => $page,
            'page_count'=> 1,
            'places'    => $places,
        ];
    }

    /**
     * Extract a place array from JSON-LD LocalBusiness object.
     *
     * @param array $item JSON-LD decoded object
     * @return array|null Place data or null if essential info missing
     */
    private function extractPlaceFromJsonLd(array $item): ?array
    {
        $name = $item['name'] ?? null;
        if (!$name) {
            return null;
        }

        // Address
        $address = null;
        if (isset($item['address'])) {
            $addr = $item['address'];
            if (is_string($addr)) {
                $address = $addr;
            } elseif (is_array($addr)) {
                $parts = [];
                foreach (['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'] as $field) {
                    if (isset($addr[$field]) && $addr[$field] !== '') {
                        $parts[] = $addr[$field];
                    }
                }
                if ($parts) {
                    $address = implode(', ', $parts);
                }
            }
        }

        // Telephone
        $telephone = $item['telephone'] ?? null;

        // Website
        $website = $item['url'] ?? null;

        // Image
        $imagePreview = null;
        if (isset($item['image'])) {
            $img = $item['image'];
            if (is_string($img)) {
                $imagePreview = $img;
            } elseif (is_array($img) && !empty($img)) {
                // take first
                $first = $img[0];
                $imagePreview = is_string($first) ? $first : null;
            }
        }

        // Geo coordinates
        $latitude = null;
        $longitude = null;
        if (isset($item['geo'])) {
            $geo = $item['geo'];
            if (is_array($geo)) {
                if (isset($geo['latitude']) && isset($geo['longitude'])) {
                    $latitude = $geo['latitude'];
                    $longitude = $geo['longitude'];
                } elseif (isset($geo[0]) && isset($geo[1])) {
                    $longitude = $geo[0];
                    $latitude = $geo[1];
                }
            }
        }

        // Place ID (not available from scraping; we can use a hash)
        $placeId = md5($name . $address . $telephone);

        return [
            'name'         => $name,
            'address'      => $address,
            'telephone'    => $telephone,
            'website'      => $website,
            'category'     => isset($item['@type'][0]) ? $item['@type'][0] : null,
            'latitude'     => $latitude,
            'longitude'    => $longitude,
            'image_preview'=> $imagePreview,
            'place_id'     => $placeId,
        ];
    }

    /**
     * Get details for places (not implemented for scraping).
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