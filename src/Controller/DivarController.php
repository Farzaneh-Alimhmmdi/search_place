<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Http\CurlHttpClient;
use Src\Divar\DivarClient;
use Src\Divar\DivarSearchService;
use Src\View\SearchView;

final class DivarController
{
    private CurlHttpClient $http;
    private DivarClient $client;
    private ?DivarSearchService $service = null;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $cities = [];
    private array $divarCategories = [];
    private array $cityNameToSlug = []; // Maps Persian city names to slugs

    private string $selectedProvince = '';
    private string $selectedCity = '';
    private string $selectedCategory = 'rent-temporary';
    private string $selectedQuery = '';
    private ?array $results = null;
    private ?string $error = null;
    private array $existingCallLogs = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/divar.php');
        Db::connect(
            Config::get('db_host', '127.0.0.1'),
            Config::get('db_port', 3306),
            Config::get('db_database', 'search_place'),
            Config::get('db_username', 'root'),
            Config::get('db_password', '')
        );
        Logger::setPath(Config::get('log_path', 'storage/logs'));

        $this->initHttp();
        $this->loadData();
        $this->handleRequest();
        $this->render();
    }

    private function initHttp(): void
    {
        $this->http = new CurlHttpClient([
            'timeout' => Config::get('timeout'),
            'connect_timeout' => Config::get('connect_timeout'),
            'user_agent' => Config::get('user_agent'),
            'max_retries' => Config::get('max_retries'),
            'request_delay' => Config::get('request_delay'),
        ]);

        $this->client = new DivarClient(
            $this->http,
            Config::get('endpoint'),
            Config::get('categories'),
            Config::get('user_agent'),
            Config::get('city_slugs')
        );
    }

    /**
     * Get the Divar city ID from the selected city name (Persian).
     * @param string $cityName Persian name of the city
     * @return string Divar city ID
     */
    private function getDivarCityId(string $cityName): string
    {
        // First, get the slug from the city name using the city_slugs mapping
        $citySlug = $this->citySlugs[$cityName] ?? '';
        if ($citySlug === '') {
            // Fallback: use the city name as slug (url encoded)
            $citySlug = rawurlencode($cityName);
        }
        // Now look up the city info in the cities array (keyed by slug)
        $cityInfo = $this->cities[$citySlug] ?? null;
        return $cityInfo['city_id'] ?? '1'; // default to 1 if not found
    }

    /**
     * Get the Divar city slug from the selected city name (Persian).
     * @param string $cityName Persian name of the city
     * @return string Divar city slug
     */
    private function getDivarCitySlug(string $cityName): string
    {
        $citySlug = $this->citySlugs[$cityName] ?? '';
        if ($citySlug === '') {
            $citySlug = rawurlencode($cityName);
        }
        $cityInfo = $this->cities[$citySlug] ?? null;
        return $cityInfo['divar_slug'] ?? $citySlug;
    }

    private function loadData(): void
    {
        $root = dirname(__DIR__, 2);
        $this->provinces = json_decode(file_get_contents($root . '/provinces.json'), true) ?? [];
        $this->citySlugs = Config::get('city_slugs');
        $this->divarCategories = Config::get('categories');
        $this->cities = json_decode(file_get_contents(Config::get('cities_file')), true) ?? [];
    }

    private function handleRequest(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->selectedProvince = $_POST['province'] ?? '';
        $this->selectedCity = $_POST['city'] ?? '';
        $this->selectedCategory = $_POST['place'] ?? 'rent-temporary';
        $this->selectedQuery = $_POST['query'] ?? '';
        $this->results = null;
        $this->error = null;
        $this->existingCallLogs = [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Handle call logging (AJAX)
            if (isset($_POST['action']) && $_POST['action'] === 'call' && isset($_POST['place_id'])) {
                $this->logCall(
                    $_POST['place_id'] ?? '',
                    $_POST['phone'] ?? '',
                    $_POST['city'] ?? $this->selectedCity ?? '',
                    $_POST['category'] ?? $this->selectedCategory ?? '',
                    $_POST['description'] ?? ''
                );
                return; // Exit early for AJAX call
            }
            if ($this->selectedCity) {
                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';

                if ($citySlug === '') {
                    $citySlug = rawurlencode($this->selectedCity);
                }

                $cityInfo = $this->cities[$citySlug] ?? null;

                $divarCitySlug = $cityInfo['divar_slug'] ?? $citySlug;

                /*
                 * IMPORTANT:
                 * Do not silently use Tehran when city_id is missing.
                 */
                $divarCityId = $cityInfo['city_id'] ?? null;

                if (!$divarCityId) {
                    throw new \Exception(
                        'Divar city_id not found for city: ' . $this->selectedCity
                    );
                }

                try {
                    $this->service = new DivarSearchService(
                        $this->http,
                        $this->selectedQuery,
                        $divarCityId,
                        null,
                        $this->selectedCategory,
                        null
                    );

                    /*
                     * ---------------------------------------------------------
                     * DIVAR CURSOR PAGINATION
                     * ---------------------------------------------------------
                     *
                     * Divar does NOT use normal page numbers.
                     *
                     * Page 1:
                     *     cursor = null
                     *
                     * Page 2:
                     *     cursor = next_cursor returned by page 1
                     *
                     * Page 3:
                     *     cursor = next_cursor returned by page 2
                     *
                     * etc.
                     *
                     * We keep those cursors in PHP session so the user can
                     * navigate backwards and forwards.
                     */

                    $requestedPage = max(
                        1,
                        (int)($_POST['page'] ?? 1)
                    );

                    /*
                     * Create a unique key for this exact Divar search.
                     *
                     * Changing city/query/category creates a different pagination
                     * history automatically.
                     */
                    $divarSearchKey = hash(
                        'sha256',
                        implode('|', [
                            'divar',
                            $divarCityId,
                            $this->selectedCity,
                            $this->selectedCategory,
                            $this->selectedQuery,
                        ])
                    );

                    /*
                     * Initialize the session storage.
                     */
                    if (!isset($_SESSION['divar_pagination'])) {
                        $_SESSION['divar_pagination'] = [];
                    }

                    /*
                     * If this is page 1, this is a NEW search.
                     *
                     * Reset the cursor history for this search.
                     */
                    if ($requestedPage === 1) {
                        $_SESSION['divar_pagination'][$divarSearchKey] = [
                            'cursors' => [
                                1 => null,
                            ],
                            'has_next' => [],
                        ];
                    }

                    /*
                     * Make sure this search has pagination state.
                     */
                    if (
                        !isset(
                            $_SESSION['divar_pagination'][$divarSearchKey]
                        )
                    ) {
                        $_SESSION['divar_pagination'][$divarSearchKey] = [
                            'cursors' => [
                                1 => null,
                            ],
                            'has_next' => [],
                        ];
                    }

                    $paginationState =
            &$_SESSION['divar_pagination'][$divarSearchKey];

                    /*
                     * We can only request a page if we already know the cursor
                     * required to reach it.
                     *
                     * For example:
                     *
                     * page 1 -> cursor null
                     * page 2 -> cursor from page 1
                     * page 3 -> cursor from page 2
                     */
                    $cursor =
                        $paginationState['cursors'][$requestedPage]
                        ?? null;

                    /*
                     * If someone manually requests page 10 before pages 2-9
                     * have been loaded, don't send an invalid request to Divar.
                     */
                    if (
                        $requestedPage > 1 &&
                        !array_key_exists(
                            $requestedPage,
                            $paginationState['cursors']
                        )
                    ) {
                        $requestedPage = 1;

                        $paginationState = [
                            'cursors' => [
                                1 => null,
                            ],
                            'has_next' => [],
                        ];

                        $_SESSION['divar_pagination'][$divarSearchKey] =
                            $paginationState;

                        $paginationState =
                &$_SESSION['divar_pagination'][$divarSearchKey];

                        $cursor = null;
                    }

                    /*
                     * IMPORTANT:
                     *
                     * Only fetch ONE Divar page per HTTP request.
                     *
                     * DivarSearchService already has:
                     *
                     *     MAX_DIVAR_PAGES_PER_REQUEST = 1
                     *
                     * so we should NOT loop here.
                     */
                    $pageResult = $this->service->searchPage(
                        $cursor,
                        24
                    );

                    if (!$pageResult['success']) {
                        throw new \Exception(
                            'Divar search page failed: ' .
                            ($pageResult['error'] ?? 'Unknown error')
                        );
                    }

                    $ads = $pageResult['ads'] ?? [];

                    $pagination =
                        $pageResult['pagination'] ?? [];

                    $hasNextPage =
                        (bool)($pagination['has_next_page'] ?? false);

                    $nextCursor =
                        $pagination['next_cursor'] ?? null;

                    /*
                     * Store whether this page has another page.
                     */
                    $paginationState['has_next'][$requestedPage] =
                        $hasNextPage;

                    /*
                     * Store the cursor needed to reach the NEXT page.
                     *
                     * Example:
                     *
                     * page 1 returns CURSOR_A
                     *
                     * store:
                     *
                     *     cursors[2] = CURSOR_A
                     *
                     * Then page 2 can use CURSOR_A.
                     */
                    if (
                        $hasNextPage &&
                        is_string($nextCursor) &&
                        $nextCursor !== ''
                    ) {
                        $paginationState['cursors'][$requestedPage + 1] =
                            $nextCursor;
                    } else {
                        /*
                         * No next page.
                         */
                        unset(
                            $paginationState['cursors'][$requestedPage + 1]
                        );
                    }

                    /*
                     * Keep only a reasonable amount of cursor history.
                     *
                     * This prevents the PHP session from growing forever if
                     * someone browses hundreds of pages.
                     */
                    if (count($paginationState['cursors']) > 30) {
                        $pageNumbers =
                            array_keys($paginationState['cursors']);

                        sort($pageNumbers);

                        while (
                            count($pageNumbers) > 30
                        ) {
                            /*
                             * Never remove page 1.
                             */
                            $pageToRemove =
                                $pageNumbers[1] ?? null;

                            if ($pageToRemove === null) {
                                break;
                            }

                            unset(
                                $paginationState['cursors'][$pageToRemove]
                            );

                            unset(
                                $paginationState['has_next'][$pageToRemove]
                            );

                            $pageNumbers =
                                array_keys($paginationState['cursors']);

                            sort($pageNumbers);
                        }
                    }

                    /*
                     * Determine whether the previous page is available.
                     */
                    $canGoPrevious =
                        $requestedPage > 1 &&
                        isset(
                            $paginationState['cursors'][$requestedPage - 1]
                        );

                    /*
                     * Determine which page numbers are already known.
                     *
                     * We don't know Divar's TOTAL number of pages.
                     * We only know pages we have reached so far.
                     */
                    $knownPages =
                        array_keys($paginationState['cursors']);

                    $knownPages = array_map(
                        'intval',
                        $knownPages
                    );

                    sort($knownPages);

                    $knownLastPage =
                        !empty($knownPages)
                            ? max($knownPages)
                            : 1;

                    /*
                     * The page we just loaded may be the final page, meaning
                     * there is no cursor for the next page.
                     */
                    if (!$hasNextPage) {
                        $knownLastPage =
                            max(
                                1,
                                $requestedPage
                            );
                    }

                    /*
                     * IMPORTANT:
                     *
                     * We intentionally don't claim to know Divar's total.
                     *
                     * SearchView's generic pagination needs totalPages, so for
                     * Divar we provide the highest currently reachable page.
                     * The actual Divar pagination UI below will use
                     * divar_has_next_page instead.
                     */
                    $this->results = [
                        'success' => true,

                        'places' => $ads,

                        'total' => count($ads),

                        'page' => $requestedPage,

                        /*
                         * Used by generic SearchView code.
                         */
                        'page_count' => $knownLastPage,

                        'title' =>
                            $this->selectedCategory .
                            ' در ' .
                            $this->selectedCity,

                        /*
                         * Divar-specific pagination information.
                         */
                        'divar_has_next_page' => $hasNextPage,

                        'divar_current_page' => $requestedPage,

                        'divar_can_go_previous' => $canGoPrevious,

                        'divar_search_key' => $divarSearchKey,

                        'divar_known_last_page' => $knownLastPage,

                        'divar_next_cursor' => $nextCursor,
                    ];

                    $this->error = null;

                    if (
                        $this->results &&
                        !empty($this->results['places'])
                    ) {
                        $this->loadExistingCallLogs();
                    }

                } catch (\Exception $e) {
                    Logger::error(
                        'Divar Search failed',
                        [
                            'error' => $e->getMessage(),
                        ]
                    );

                    $this->error =
                        'خطا در ارتباط با دیوار';

                    $this->results = null;
                }
            }

//            if ($this->selectedCity) {
//                // Get Divar city slug and ID from the selected city name (Persian)
//                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';
//                if ($citySlug === '') {
//                    $citySlug = rawurlencode($this->selectedCity);
//                }
//                $cityInfo = $this->cities[$citySlug] ?? null;
//                $divarCitySlug = $cityInfo['divar_slug'] ?? $citySlug;
//                $divarCityId = $cityInfo['city_id'] ?? '1';
//
//                try {
//                    // Create the Divar search service with the current selections
//                    $this->service = new DivarSearchService(
//                        $this->http,
//                        $this->selectedQuery,
//                        $divarCityId,
//                        null, // authManager
//                        $this->selectedCategory,
//                        null  // bbox
//                    );
//
//                    // Fetch up to $maxPages pages
//                    $maxPages = 3;
//                    $allAds = [];
//                    $cursor = null;
//                    $pagesFetched = 0;
//                    $hasNextPage = false;
//
//                    do {
//                        $pageResult = $this->service->searchPage($cursor, 24);
//                        echo '<pre>';
//                        print_r([
//                            'count' => count($pageResult['ads'] ?? []),
//                            'pagination' => $pageResult['pagination'] ?? null,
//                        ]);
//                        echo '</pre>';
//                        exit;
//                        if (!$pageResult['success']) {
//                            throw new \Exception('Divar search page failed: ' . ($pageResult['error'] ?? 'Unknown error'));
//                        }
//
//                        $ads = $pageResult['ads'] ?? [];
//                        $allAds = array_merge($allAds, $ads);
//
//                        $pagination = $pageResult['pagination'] ?? [];
//                        $hasNextPage = $pagination['has_next_page'] ?? false;
//                        $cursor = $pagination['next_cursor'] ?? null;
//
//                        $pagesFetched++;
//                    } while ($hasNextPage && $pagesFetched < $maxPages && $cursor !== null);
//
//                    // Prepare the result array to match the expected format
//                    $this->results = [
//                        'success' => true,
//                        'places' => $allAds,
//                        'total' => count($allAds),
//                        'page' => 1, // we are presenting all fetched pages as one set of results
//                        'page_count' => 1, // we don't have total pages from Divar in terms of UI pagination
//                        'title' => $this->selectedCategory . ' در ' . $this->selectedCity,
//                    ];
//
//                    $this->error = null;
//
//                    // Fetch existing call logs for these results
//                    if ($this->results && !empty($this->results['places'])) {
//                        $this->loadExistingCallLogs();
//                    }
//                } catch (\Exception $e) {
//                    Logger::error('Divar Search failed', ['error' => $e->getMessage()]);
//                    $this->error = 'خطا در ارتباط با دیوار';
//                    $this->results = null;
//                }
//            }
        }
    }

    private function loadExistingCallLogs(): void
    {
        $placeIds = [];
        foreach ($this->results['places'] as $place) {
            $id = $place['id'] ?? $place['token'] ?? $place['place_id'] ?? null;
            if ($id) {
                $placeIds[] = $id;
            }
        }

        if (empty($placeIds)) return;

        $placeholders = implode(',', array_fill(0, count($placeIds), '?'));
        $query = "SELECT place_id, phone_number, status FROM call_logs WHERE place_id IN ($placeholders)";
        $stmt = Db::getConnection()->prepare($query);
        $stmt->execute($placeIds);
        $logs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Create a lookup map: place_id -> phone_number -> status
        $this->existingCallLogs = [];
        foreach ($logs as $log) {
            $this->existingCallLogs[$log['place_id']][$log['phone_number']] = $log['status'];
        }
    }

    private function logCall(string $placeId, string $phone, string $city, string $category, string $description = ''): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Check if already exists
        $checkQuery = "SELECT id, status FROM call_logs WHERE place_id = ? AND phone_number = ?";
        $checkStmt = Db::getConnection()->prepare($checkQuery);
        $checkStmt->execute([$placeId, $phone]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Already exists
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Call already logged',
                'already_exists' => true,
                'status' => $existing['status']
            ]);
            exit;
        }

        $query = "INSERT INTO call_logs (place_id, phone_number, city, category, description, status, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)";
        $stmt = Db::getConnection()->prepare($query);
        $stmt->execute([$placeId, $phone, $city, $category, $description, $ipAddress, $userAgent]);

        // Return JSON response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Call logged successfully']);
        exit;
    }

    private function getCitiesForProvince(string $provinceName): array
    {
        $result = [];
        foreach ($this->cities as $slug => $info) {
            if (($info['province'] ?? '') === $provinceName) {
                $result[$slug] = $info['name'];
            }
        }
        return $result;
    }

    private function render(): void
    {
        $provinceCities = [];
        if ($this->selectedProvince) {
            $provinceCities = $this->getCitiesForProvince($this->selectedProvince);
        }

        // Build full cities-by-province mapping for JavaScript
        $allProvinceCities = [];
        foreach ($this->provinces as $prov) {
            $provinceName = $prov['name'];
            $allProvinceCities[$provinceName] = $this->getCitiesForProvince($provinceName);
        }

        $view = new SearchView(
            $this->provinces,
            $this->divarCategories,
            $this->citySlugs,
            $this->results,
            $this->error,
            $this->selectedCity,
            $this->selectedCategory,
            'divar',
            $this->selectedProvince,
            $provinceCities,
            $allProvinceCities,
            $this->selectedQuery
        );
        // Pass existing call logs to view
        $view->setExistingCallLogs($this->existingCallLogs);
        $view->render();
    }
}

$controller = new DivarController();
$controller->run();