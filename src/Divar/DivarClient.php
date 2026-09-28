<?php

namespace Src\Divar;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

/**
 * DivarClient - Fetches Divar listings using headless Chrome for initial page
 * and Divar's search API for subsequent pages (supports pagination via last_post_date).
 */
final class DivarClient
{
    private CurlHttpClient $http;
    private string $endpoint;
    private array $categories = [];
    private array $cityMap = []; // slug -> city info
    private string $userAgent;
    private string $chromeBinary;
    private array $chromeArgs = [];

    public function __construct(
        CurlHttpClient $http,
        string $endpoint,
        array $categories = [],
        string $userAgent = '',
        array $cityMap = [],
        string $chromeBinary = 'C:\Program Files\Google\Chrome\Application\chrome.exe',
        array $chromeArgs = []
    ) {
        $this->http = $http;
        $this->endpoint = rtrim($endpoint, '/');
        $this->categories = $categories;
        $this->userAgent = $userAgent;
        $this->cityMap = $cityMap;
        $this->chromeBinary = $chromeBinary;
        $this->chromeArgs = $chromeArgs ?: ['--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'];
    }

    /**
     * Get category label from value.
     * Categories array is now: [['value' => '...', 'label' => '...'], ...]
     */
    private function getCategoryLabel(string $value): ?string
    {
        foreach ($this->categories as $cat) {
            if (($cat['value'] ?? '') === $value) {
                return $cat['label'] ?? null;
            }
        }
        return null;
    }

    /**
     * Search for listings on Divar with pagination support.
     *
     * @param string $citySlug  Divar city slug (e.g. 'tabriz', 'tehran')
     * @param string $category  Category key from config (e.g. 'rent-temporary')
     * @param string $query     Optional search query (e.g. 'ویلا', 'بوم گردی')
     * @param int    $page      Page number to fetch (1 = first page)
     * @param int    $maxPages  Maximum number of pages to fetch (default: 1 for backward compatibility)
     * @return array ['success' => true, 'places' => [...], ...] or ['success' => false, 'error' => '...']
     */
    public function search(string $citySlug, string $category, string $query = '', int $page = 1, int $maxPages = 1): array
    {
        // Validate category - categories is now array of ['value' => '...', 'label' => '...']
        $categoryLabel = $this->getCategoryLabel($category);
        if ($categoryLabel === null) {
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

        // Build base URL
        $baseUrl = $this->endpoint . '/' . $divarCitySlug . '/' . $category;
        if (!empty($query)) {
            $baseUrl .= '?q=' . rawurlencode($query);
        }

        Logger::info('Divar search', ['url' => $baseUrl, 'page' => $page, 'maxPages' => $maxPages]);

        // If requesting only page 1 with maxPages=1, use the original method for backward compatibility
        if ($maxPages === 1) {
            return $this->searchSinglePage($divarCitySlug, $category, $query, $baseUrl, $categoryLabel);
        }

        // For multi-page search, fetch all pages and combine results
        return $this->searchMultiplePages($divarCitySlug, $category, $query, $baseUrl, $categoryLabel, $maxPages);
    }

    /**
     * Search a single page using headless Chrome (original method).
     */
    private function searchSinglePage(string $divarCitySlug, string $category, string $query, string $baseUrl, string $categoryLabel): array
    {
        $url = $this->endpoint . '/' . $divarCitySlug . '/' . $category;
        if (!empty($query)) {
            $url .= '?q=' . rawurlencode($query);
        }

        Logger::info('Divar search (headless)', ['url' => $baseUrl]);

        try {
            $html = $this->fetchWithHeadlessChrome($baseUrl);
        } catch (\Exception $e) {
            Logger::error('Divar headless fetch failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Failed to fetch page: ' . $e->getMessage(),
            ];
        }

        if (empty($html)) {
            return ['success' => false, 'error' => 'Empty response from headless Chrome'];
        }

        // Extract listings from __PRELOADED_STATE__
        $listings = $this->extractListingsFromPreloadedState($html, $divarCitySlug, $category);

        return [
            'success'   => true,
            'title'     => $categoryLabel,
            'total'     => count($listings),
            'page'      => 1,
            'page_count'=> 1,
            'places'    => $listings,
        ];
    }

    /**
     * Fetch multiple pages using Divar's search API with pagination.
     */
    private function searchMultiplePages(string $divarCitySlug, string $category, string $query, string $baseUrl, string $categoryLabel, int $maxPages): array
    {
        $allListings = [];
        $lastPostDate = 0;
        $totalPages = 0;
        $hasMore = true;
        $page = 0;

        // First page: use headless Chrome to get initial data and extract last_post_date
        $firstPageUrl = $this->endpoint . '/' . $divarCitySlug . '/' . $category;
        if (!empty($query)) {
            $firstPageUrl .= '?q=' . rawurlencode($query);
        }

        Logger::info('Divar search first page (headless)', ['url' => $firstPageUrl]);

        try {
            $html = $this->fetchWithHeadlessChrome($firstPageUrl);
        } catch (\Exception $e) {
            Logger::error('Divar headless fetch failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => 'Failed to fetch first page: ' . $e->getMessage(),
            ];
        }

        if (empty($html)) {
            return ['success' => false, 'error' => 'Empty response from headless Chrome'];
        }

        // Extract listings from first page
        $listings = $this->extractListingsFromPreloadedState($html, $divarCitySlug, $category);
        $allListings = array_merge($allListings, $listings);
        $totalPages = 1;

        // Extract last_post_date from preloaded state for pagination
        $preloadedState = $this->extractPreloadedState($html);
        if ($preloadedState) {
            $lastPostDate = $this->extractLastPostDate($preloadedState);
        }

        // Fetch additional pages using Divar's search API
        for ($page = 2; $page <= $maxPages && $hasMore; $page++) {
            if ($lastPostDate <= 0) {
                Logger::info('No more pages available (no last_post_date)');
                break;
            }

            Logger::info("Fetching Divar page $page", ['last_post_date' => $lastPostDate]);

            $apiListings = $this->fetchApiPage($divarCitySlug, $category, $query, $lastPostDate);
            
            if (empty($apiListings)) {
                $hasMore = false;
                break;
            }

            $allListings = array_merge($allListings, $apiListings);
            $totalPages++;
            
            // Update last_post_date for next page (use the last item's sort_date)
            $lastItem = end($apiListings);
            $lastPostDate = $lastItem['sort_date'] ?? 0;
            
            // Small delay to be respectful
            sleep(1);
        }

        return [
            'success'   => true,
            'title'     => $categoryLabel,
            'total'     => count($allListings),
            'page'      => 1,
            'page_count'=> $totalPages,
            'places'    => $allListings,
        ];
    }

    /**
     * Fetch a page using Divar's search API directly (for pagination).
     */
    private function fetchApiPage(string $divarCitySlug, string $category, string $query, int $lastPostDate): array
    {
        $apiUrl = 'https://api.divar.ir/v8/web-search/' . $divarCitySlug . '/' . $category;
        
        $postData = json_encode([
            'json_schema' => [
                'category' => ['value' => $category]
            ],
            'last_post_date' => $lastPostDate
        ]);

        $result = $this->http->post($apiUrl, [
            'headers' => [
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'fa,en;q=0.9',
                'Content-Type' => 'application/json',
                'Referer' => 'https://divar.ir/s/' . $divarCitySlug . '/' . $category,
                'Origin' => 'https://divar.ir',
                'X-Requested-With' => 'XMLHttpRequest',
            ],
            'POST' => $postData
        ]);

        if (!$result['success']) {
            Logger::error('Divar API request failed', ['error' => $result['error']]);
            return [];
        }

        $data = $result['data'] ?? [];
        
        // Check for error status
        if (isset($data['widget_list'])) {
            $posts = [];
            
            foreach ($data['widget_list'] as $widget) {
                if (!isset($widget['data']['dto']['data']['token'])) {
                    continue;
                }
                
                $dtoData = $widget['data']['dto']['data'];
                
                // Convert to our standard format
                $post = [
                    'token' => $dtoData['token'] ?? null,
                    'title' => $dtoData['title'] ?? '',
                    'image_url' => $dtoData['image_url'] ?? null,
                    'bottom_description_text' => $dtoData['bottom_description_text'] ?? null,
                    'red_text' => $dtoData['red_text'] ?? null,
                    'middle_description_text' => $dtoData['middle_description_text'] ?? null,
                    'top_description_text' => $dtoData['top_description_text'] ?? null,
                    'image_top_left_tag' => $dtoData['image_top_left_tag'] ?? null,
                    'action' => $dtoData['action'] ?? [],
                    'sort_date' => $widget['data']['action_log']['server_side_info']['info']['sort_date'] ?? 0,
                ];
                
                $posts[] = $post;
            }
            
            return $posts;
        }

        return [];
    }

    /**
     * Extract last_post_date from preloaded state.
     */
    private function extractLastPostDate(array $preloadedState): int
    {
        $nb = $preloadedState['nb'] ?? null;
        if (!$nb) return 0;

        $listWidgets = $nb['listWidgets'] ?? [];
        
        // Find the last POST_ROW widget and extract its sort_date
        for ($i = count($listWidgets) - 1; $i >= 0; $i--) {
            $widget = $listWidgets[$i];
            $data = $widget['data'] ?? [];
            $widgetType = $data['widgetType'] ?? '';
            
            if ($widgetType === 'POST_ROW') {
                $actionLog = $widget['data']['action_log']['server_side_info']['info'] ?? [];
                if (isset($actionLog['sort_date'])) {
                    return (int)$actionLog['sort_date'];
                }
            }
        }

        return 0;
    }

    /**
     * Fetch page using headless Chrome and return rendered HTML.
     */
    private function fetchWithHeadlessChrome(string $url): string
    {
        $browserFactory = new BrowserFactory($this->chromeBinary, [
            'arguments' => $this->chromeArgs,
        ]);

        $browser = $browserFactory->createBrowser();
        $page = $browser->createPage();

        // Set user agent
        if ($this->userAgent) {
            $page->setUserAgent($this->userAgent);
        }

        // Navigate and wait for network to be idle
        $page->navigate($url)->waitForNavigation();

        // Wait for the React app to hydrate
        sleep(3);

        $html = $page->getHtml();
        $browser->close();

        return $html;
    }

    /**
     * Extract listings from __PRELOADED_STATE__ in the HTML.
     */
    private function extractListingsFromPreloadedState(string $html, string $citySlug, string $category): array
    {
        $listings = [];

        // Extract __PRELOADED_STATE__ from HTML
        $preloadedState = $this->extractPreloadedState($html);
        if (!$preloadedState) {
            Logger::warning('Could not extract __PRELOADED_STATE__ from HTML');
            return $listings;
        }

        // Extract posts from POST_ROW widgets in nb.listWidgets
        $posts = $this->extractPostsFromWidgets($preloadedState);
        if (empty($posts)) {
            Logger::info('No POST_ROW posts found in preloaded state');
            return $listings;
        }

        // Convert posts to our standard format
        foreach ($posts as $post) {
            $listing = $this->convertPostToListing($post, $citySlug, $category);
            if ($listing) {
                $listings[] = $listing;
            }
        }

        return $listings;
    }

    /**
     * Extract __PRELOADED_STATE__ JSON from HTML.
     */
    private function extractPreloadedState(string $html): ?array
    {
        // Try multiple patterns
        $patterns = [
            '/window\.__PRELOADED_STATE__\s*=\s*({.*?});/s',
            '/window\.__PRELOADED_STATE__\s*=\s*({.*})/s',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $jsonStr = $matches[1];
                $data = json_decode($jsonStr, true);
                if ($data !== null && json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
            }
        }

        return null;
    }

    /**
     * Extract posts from POST_ROW widgets in nb.listWidgets.
     */
    private function extractPostsFromWidgets(array $preloadedState): array
    {
        $posts = [];

        $nb = $preloadedState['nb'] ?? null;
        if (!$nb) {
            Logger::warning('No "nb" key in preloaded state');
            return $posts;
        }

        $listWidgets = $nb['listWidgets'] ?? [];
        if (empty($listWidgets)) {
            Logger::warning('No listWidgets in nb');
            return $posts;
        }

        foreach ($listWidgets as $widget) {
            $data = $widget['data'] ?? [];
            $widgetType = $data['widgetType'] ?? '';

            // Look for POST_ROW widgets
            if ($widgetType === 'POST_ROW') {
                $dto = $data['dto'] ?? [];
                $dtoData = $dto['data'] ?? [];

                // POST_ROW data structure
                if (isset($dtoData['token'])) {
                    $posts[] = $dtoData;
                }
            }
        }

        return $posts;
    }

    /**
     * Convert a Divar POST_ROW to our standard listing format.
     */
    private function convertPostToListing(array $post, string $citySlug, string $category): ?array
    {
        $token = $post['token'] ?? null;
        if (!$token) return null;

        $title = $post['title'] ?? 'آگهی دیوار';
        $imageUrl = $post['image_url'] ?? null;
        $bottomDesc = $post['bottom_description_text'] ?? null; // location/district
        $redText = $post['red_text'] ?? null; // special badge
        $middleDesc = $post['middle_description_text'] ?? null; // price
        $topDesc = $post['top_description_text'] ?? null; // description (rooms, capacity)
        $imageTopLeftTag = $post['image_top_left_tag'] ?? null; // image count badge

        $action = $post['action'] ?? [];
        $payload = $action['payload'] ?? [];
        $webInfo = $payload['web_info'] ?? [];
        $district = $webInfo['district_persian'] ?? null;
        $cityPersian = $webInfo['city_persian'] ?? null;

        // Build Divar URL
        $divarUrl = "https://divar.ir/v/{$token}";

        // Build address from available info
        $addressParts = [];
        if ($district) $addressParts[] = $district;
        if ($cityPersian) $addressParts[] = $cityPersian;
        $address = !empty($addressParts) ? implode(', ', $addressParts) : null;

        // Build description from available fields
        $descParts = [];
        if ($topDesc) $descParts[] = $topDesc;
        if ($middleDesc) $descParts[] = $middleDesc;
        if ($redText) $descParts[] = $redText;
        $description = !empty($descParts) ? implode(' | ', $descParts) : null;

        $placeId = 'divar_' . $token;

        return [
            'name'         => $title,
            'address'      => $address,
            'description'  => $description,
            'price'        => $middleDesc,
            'telephone'    => null, // Not available in search results
            'website'      => "https://divar.ir/v/{$token}",
            'category'     => $category,
            'city_slug'    => $citySlug,
            'latitude'     => null,
            'longitude'    => null,
            'image_preview'=> $imageUrl,
            'place_id'     => 'divar_' . $token,
            'divar_url'    => "https://divar.ir/v/{$token}",
            'token'        => $token,
            'district'     => $district,
            'image_count'  => $post['image_count'] ?? null,
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