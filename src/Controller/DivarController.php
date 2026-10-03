<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\ProviderAccommodationMapper;
use Src\Support\Schema;
use Src\Support\SelectedPlaceService;
use Src\Http\CurlHttpClient;
use Src\Divar\AccommodationRepository;
use Src\Divar\DivarPhoneParser;
use Src\Divar\DivarSearchAdStore;
use Src\Divar\DivarClient;
use Src\Divar\DivarSearchService;
use Src\Divar\DivarCookieManager;
use Src\View\SearchView;

final class DivarController
{
    private const PHONE_FETCH_FAILURE_MESSAGE =
        'وارد سایت دیوار شوید و کپجا را حل کنیدتا دسترسی شما باز شود';

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
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

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

//    private function handleRequest(): void
//    {
//        if (session_status() !== PHP_SESSION_ACTIVE) {
//            session_start();
//        }
//        $this->selectedProvince = $_POST['province'] ?? '';
//        $this->selectedCity = $_POST['city'] ?? '';
//        $this->selectedCategory = $_POST['place'] ?? 'rent-temporary';
//        $this->selectedQuery = $_POST['query'] ?? '';
//        $this->results = null;
//        $this->error = null;
//        $this->existingCallLogs = [];
//
//        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
//            // Handle call logging (AJAX)
//            if (isset($_POST['action']) && $_POST['action'] === 'call' && isset($_POST['place_id'])) {
//                $this->logCall(
//                    $_POST['place_id'] ?? '',
//                    $_POST['phone'] ?? '',
//                    $_POST['city'] ?? $this->selectedCity ?? '',
//                    $_POST['category'] ?? $this->selectedCategory ?? '',
//                    $_POST['description'] ?? ''
//                );
//                return; // Exit early for AJAX call
//            }
//            if (
//                isset($_POST['action']) && $_POST['action'] === 'set_divar_cookies'
//            ) {
//                $this->setDivarCookiesAction();
//                return;
//            }
//            if (isset($_POST['action']) && $_POST['action'] === 'get_phone' && isset($_POST['place_id'])) {
//                $this->getPhoneNumberAction();
//                return; // Exit early for AJAX call
//            }
//            if ($this->selectedCity) {
//                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';
//
//                if ($citySlug === '') {
//                    $citySlug = rawurlencode($this->selectedCity);
//                }
//
//                $cityInfo = $this->cities[$citySlug] ?? null;
//
//                // Check if the selected value is a province (no city_id) instead of a city
//                if ($cityInfo && empty($cityInfo['city_id'])) {
//                    $this->error = 'لطفا یک شهر انتخاب کنید. «' . htmlspecialchars($this->selectedCity) . '» یک استان است، شهر نیست.';
//                    $this->results = null;
//                } else {
//                    $divarCitySlug = $cityInfo['divar_slug'] ?? $citySlug;
//
//                    /*
//                     * IMPORTANT:
//                     * Do not silently use Tehran when city_id is missing.
//                     */
//                    $divarCityId = $cityInfo['city_id'] ?? null;
//
//                    if (!$divarCityId) {
//                        $this->error = 'شناسه شهر دیواری یافت نشد برای: ' . htmlspecialchars($this->selectedCity);
//                        $this->results = null;
//                    } else {
//
//                try {
//                    $this->service = new DivarSearchService(
//                        $this->http,
//                        $this->selectedQuery,
//                        $divarCityId,
//                        null,
//                        $this->selectedCategory,
//                        null
//                    );
//
//                    /*
//                     * ---------------------------------------------------------
//                     * DIVAR CURSOR PAGINATION
//                     * ---------------------------------------------------------
//                     *
//                     * Divar does NOT use normal page numbers.
//                     *
//                     * Page 1:
//                     *     cursor = null
//                     *
//                     * Page 2:
//                     *     cursor = next_cursor returned by page 1
//                     *
//                     * Page 3:
//                     *     cursor = next_cursor returned by page 2
//                     *
//                     * etc.
//                     *
//                     * We keep those cursors in PHP session so the user can
//                     * navigate backwards and forwards.
//                     */
//
//                    $requestedPage = max(
//                        1,
//                        (int)($_POST['page'] ?? 1)
//                    );
//
//                    /*
//                     * Create a unique key for this exact Divar search.
//                     *
//                     * Changing city/query/category creates a different pagination
//                     * history automatically.
//                     */
//                    $divarSearchKey = hash(
//                        'sha256',
//                        implode('|', [
//                            'divar',
//                            $divarCityId,
//                            $this->selectedCity,
//                            $this->selectedCategory,
//                            $this->selectedQuery,
//                        ])
//                    );
//
//                    /*
//                     * Initialize the session storage.
//                     */
//                    if (!isset($_SESSION['divar_pagination'])) {
//                        $_SESSION['divar_pagination'] = [];
//                    }
//
//                    /*
//                     * If this is page 1, this is a NEW search.
//                     *
//                     * Reset the cursor history for this search.
//                     */
//                    if ($requestedPage === 1) {
//                        $_SESSION['divar_pagination'][$divarSearchKey] = [
//                            'cursors' => [
//                                1 => null,
//                            ],
//                            'has_next' => [],
//                        ];
//                    }
//
//                    /*
//                     * Make sure this search has pagination state.
//                     */
//                    if (
//                        !isset(
//                            $_SESSION['divar_pagination'][$divarSearchKey]
//                        )
//                    ) {
//                        $_SESSION['divar_pagination'][$divarSearchKey] = [
//                            'cursors' => [
//                                1 => null,
//                            ],
//                            'has_next' => [],
//                        ];
//                    }
//
//                    $paginationState =
//            &$_SESSION['divar_pagination'][$divarSearchKey];
//
//                    /*
//                     * We can only request a page if we already know the cursor
//                     * required to reach it.
//                     *
//                     * For example:
//                     *
//                     * page 1 -> cursor null
//                     * page 2 -> cursor from page 1
//                     * page 3 -> cursor from page 2
//                     */
//                    $cursor =
//                        $paginationState['cursors'][$requestedPage]
//                        ?? null;
//
//                    /*
//                     * If someone manually requests page 10 before pages 2-9
//                     * have been loaded, don't send an invalid request to Divar.
//                     */
//                    if (
//                        $requestedPage > 1 &&
//                        !array_key_exists(
//                            $requestedPage,
//                            $paginationState['cursors']
//                        )
//                    ) {
//                        $requestedPage = 1;
//
//                        $paginationState = [
//                            'cursors' => [
//                                1 => null,
//                            ],
//                            'has_next' => [],
//                        ];
//
//                        $_SESSION['divar_pagination'][$divarSearchKey] =
//                            $paginationState;
//
//                        $paginationState =
//                &$_SESSION['divar_pagination'][$divarSearchKey];
//
//                        $cursor = null;
//                    }
//
//                    /*
//                     * IMPORTANT:
//                     *
//                     * Only fetch ONE Divar page per HTTP request.
//                     *
//                     * DivarSearchService already has:
//                     *
//                     *     MAX_DIVAR_PAGES_PER_REQUEST = 1
//                     *
//                     * so we should NOT loop here.
//                     */
//                    $pageResult = $this->service->searchPage(
//                        $cursor,
//                        24
//                    );
//
//                    if (!$pageResult['success']) {
//                        throw new \Exception(
//                            'Divar search page failed: ' .
//                            ($pageResult['error'] ?? 'Unknown error')
//                        );
//                    }
//
//                    $ads = $pageResult['ads'] ?? [];
//
//                    $pagination =
//                        $pageResult['pagination'] ?? [];
//
//                    $hasNextPage =
//                        (bool)($pagination['has_next_page'] ?? false);
//
//                    $nextCursor =
//                        $pagination['next_cursor'] ?? null;
//
//                    /*
//                     * Store whether this page has another page.
//                     */
//                    $paginationState['has_next'][$requestedPage] =
//                        $hasNextPage;
//
//                    /*
//                     * Store the cursor needed to reach the NEXT page.
//                     *
//                     * Example:
//                     *
//                     * page 1 returns CURSOR_A
//                     *
//                     * store:
//                     *
//                     *     cursors[2] = CURSOR_A
//                     *
//                     * Then page 2 can use CURSOR_A.
//                     */
//                    if (
//                        $hasNextPage &&
//                        is_string($nextCursor) &&
//                        $nextCursor !== ''
//                    ) {
//                        $paginationState['cursors'][$requestedPage + 1] =
//                            $nextCursor;
//                    } else {
//                        /*
//                         * No next page.
//                         */
//                        unset(
//                            $paginationState['cursors'][$requestedPage + 1]
//                        );
//                    }
//
//                    /*
//                     * Keep only a reasonable amount of cursor history.
//                     *
//                     * This prevents the PHP session from growing forever if
//                     * someone browses hundreds of pages.
//                     */
//                    if (count($paginationState['cursors']) > 30) {
//                        $pageNumbers =
//                            array_keys($paginationState['cursors']);
//
//                        sort($pageNumbers);
//
//                        while (
//                            count($pageNumbers) > 30
//                        ) {
//                            /*
//                             * Never remove page 1.
//                             */
//                            $pageToRemove =
//                                $pageNumbers[1] ?? null;
//
//                            if ($pageToRemove === null) {
//                                break;
//                            }
//
//                            unset(
//                                $paginationState['cursors'][$pageToRemove]
//                            );
//
//                            unset(
//                                $paginationState['has_next'][$pageToRemove]
//                            );
//
//                            $pageNumbers =
//                                array_keys($paginationState['cursors']);
//
//                            sort($pageNumbers);
//                        }
//                    }
//
//                    /*
//                     * Determine whether the previous page is available.
//                     */
//                    $canGoPrevious =
//                        $requestedPage > 1 &&
//                        isset(
//                            $paginationState['cursors'][$requestedPage - 1]
//                        );
//
//                    /*
//                     * Determine which page numbers are already known.
//                     *
//                     * We don't know Divar's TOTAL number of pages.
//                     * We only know pages we have reached so far.
//                     */
//                    $knownPages =
//                        array_keys($paginationState['cursors']);
//
//                    $knownPages = array_map(
//                        'intval',
//                        $knownPages
//                    );
//
//                    sort($knownPages);
//
//                    $knownLastPage =
//                        !empty($knownPages)
//                            ? max($knownPages)
//                            : 1;
//
//                    /*
//                     * The page we just loaded may be the final page, meaning
//                     * there is no cursor for the next page.
//                     */
//                    if (!$hasNextPage) {
//                        $knownLastPage =
//                            max(
//                                1,
//                                $requestedPage
//                            );
//                    }
//
//                    /*
//                     * IMPORTANT:
//                     *
//                     * We intentionally don't claim to know Divar's total.
//                     *
//                     * SearchView's generic pagination needs totalPages, so for
//                     * Divar we provide the highest currently reachable page.
//                     * The actual Divar pagination UI below will use
//                     * divar_has_next_page instead.
//                     */
//                    $this->results = [
//                        'success' => true,
//
//                        'places' => $ads,
//
//                        'total' => count($ads),
//
//                        'page' => $requestedPage,
//
//                        /*
//                         * Used by generic SearchView code.
//                         */
//                        'page_count' => $knownLastPage,
//
//                        'title' =>
//                            $this->selectedCategory .
//                            ' در ' .
//                            $this->selectedCity,
//
//                        /*
//                         * Divar-specific pagination information.
//                         */
//                        'divar_has_next_page' => $hasNextPage,
//
//                        'divar_current_page' => $requestedPage,
//
//                        'divar_can_go_previous' => $canGoPrevious,
//
//                        'divar_search_key' => $divarSearchKey,
//
//                        'divar_known_last_page' => $knownLastPage,
//
//                        'divar_next_cursor' => $nextCursor,
//                    ];
//
//                    $this->error = null;
//
//                    if (
//                        $this->results &&
//                        !empty($this->results['places'])
//                    ) {
//                        $this->loadExistingCallLogs();
//                    }
//
//                } catch (\Exception $e) {
//                    Logger::error(
//                        'Divar Search failed',
//                        [
//                            'error' => $e->getMessage(),
//                        ]
//                    );
//
//                    $this->error =
//                        'خطا در ارتباط با دیوار';
//
//                    $this->results = null;
//                }
//            }
//            } // close outer else (province check)
//        } // close if ($this->selectedCity)
//
////            if ($this->selectedCity) {
////                // Get Divar city slug and ID from the selected city name (Persian)
////                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';
////                if ($citySlug === '') {
////                    $citySlug = rawurlencode($this->selectedCity);
////                }
////                $cityInfo = $this->cities[$citySlug] ?? null;
////                $divarCitySlug = $cityInfo['divar_slug'] ?? $citySlug;
////                $divarCityId = $cityInfo['city_id'] ?? '1';
////
////                try {
////                    // Create the Divar search service with the current selections
////                    $this->service = new DivarSearchService(
////                        $this->http,
////                        $this->selectedQuery,
////                        $divarCityId,
////                        null, // authManager
////                        $this->selectedCategory,
////                        null  // bbox
////                    );
////
////                    // Fetch up to $maxPages pages
////                    $maxPages = 3;
////                    $allAds = [];
////                    $cursor = null;
////                    $pagesFetched = 0;
////                    $hasNextPage = false;
////
////                    do {
////                        $pageResult = $this->service->searchPage($cursor, 24);
////                        echo '<pre>';
////                        print_r([
////                            'count' => count($pageResult['ads'] ?? []),
////                            'pagination' => $pageResult['pagination'] ?? null,
////                        ]);
////                        echo '</pre>';
////                        exit;
////                        if (!$pageResult['success']) {
////                            throw new \Exception('Divar search page failed: ' . ($pageResult['error'] ?? 'Unknown error'));
////                        }
////
////                        $ads = $pageResult['ads'] ?? [];
////                        $allAds = array_merge($allAds, $ads);
////
////                        $pagination = $pageResult['pagination'] ?? [];
////                        $hasNextPage = $pagination['has_next_page'] ?? false;
////                        $cursor = $pagination['next_cursor'] ?? null;
////
////                        $pagesFetched++;
////                    } while ($hasNextPage && $pagesFetched < $maxPages && $cursor !== null);
////
////                    // Prepare the result array to match the expected format
////                    $this->results = [
////                        'success' => true,
////                        'places' => $allAds,
////                        'total' => count($allAds),
////                        'page' => 1, // we are presenting all fetched pages as one set of results
////                        'page_count' => 1, // we don't have total pages from Divar in terms of UI pagination
////                        'title' => $this->selectedCategory . ' در ' . $this->selectedCity,
////                    ];
////
////                    $this->error = null;
////
////                    // Fetch existing call logs for these results
////                    if ($this->results && !empty($this->results['places'])) {
////                        $this->loadExistingCallLogs();
////                    }
////                } catch (\Exception $e) {
////                    Logger::error('Divar Search failed', ['error' => $e->getMessage()]);
////                    $this->error = 'خطا در ارتباط با دیوار';
////                    $this->results = null;
////                }
////            }
//        }
//    }


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

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'save_place') {
                SelectedPlaceAction::handle('divar');
            }

            if (isset($_POST['action']) && $_POST['action'] === 'call') {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(410);
                echo json_encode([
                    'success' => false,
                    'message' => 'ثبت تماس برای نتایج دیوار غیرفعال است. ذخیره شماره تماس کافی است.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (
                isset($_POST['action']) &&
                $_POST['action'] === 'set_divar_cookies'
            ) {
                $this->setDivarCookiesAction();
                exit; // MUST exit: run() would otherwise call render() right after this
            }
            if (isset($_POST['action']) && $_POST['action'] === 'get_phone') {
                $this->getPhoneNumberAction();
                exit; // MUST exit: run() would otherwise call render() right after this
            }
            // ----- Divar OTP login (per-user session) -----
            if (isset($_POST['action']) && $_POST['action'] === 'divar_send_otp') {
                $this->divarSendOtpAction();
                exit; // MUST exit: run() would otherwise call render() right after this
            }
            if (isset($_POST['action']) && $_POST['action'] === 'divar_verify_otp') {
                $this->divarVerifyOtpAction();
                exit; // MUST exit: run() would otherwise call render() right after this
            }
            if ($this->selectedCity) {
                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';

                if ($citySlug === '') {
                    $citySlug = rawurlencode($this->selectedCity);
                }

                $cityInfo = $this->cities[$citySlug] ?? null;

                // Check if the selected value is a province (no city_id) instead of a city
                if ($cityInfo && empty($cityInfo['city_id'])) {
                    $this->error = 'لطفا یک شهر انتخاب کنید. «' . htmlspecialchars($this->selectedCity) . '» یک استان است، شهر نیست.';
                    $this->results = null;
                } else {
                    $divarCitySlug = $cityInfo['divar_slug'] ?? $citySlug;

                    /*
                     * IMPORTANT:
                     * Do not silently use Tehran when city_id is missing.
                     */
                    $divarCityId = $cityInfo['city_id'] ?? null;

                    if (!$divarCityId) {
                        $this->error = 'شناسه شهر دیواری یافت نشد برای: ' . htmlspecialchars($this->selectedCity);
                        $this->results = null;
                    } else {

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

                            // Keep the listing server-side for the later get_phone request.
                            DivarSearchAdStore::remember($ads, [
                                'province' => $this->selectedProvince,
                                'city' => $this->selectedCity,
                                'city_slug' => $citySlug,
                                'divar_city_id' => $divarCityId,
                                'category' => $this->selectedCategory,
                                'query' => $this->selectedQuery,
                                'page' => $requestedPage,
                            ]);

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
                                $this->prepareSaveState($citySlug, $requestedPage);
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
                } // close outer else (province check)
            } // close if ($this->selectedCity)
        }
    }
    private function setDivarCookiesAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $cookieString =
            trim($_POST['cookies'] ?? '');

        if ($cookieString === '') {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'رشته کوکی الزامی است'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }


        /*
         * Convert:
         *
         * did=abc; sAccessToken=xyz; sFrontToken=123
         *
         * into:
         *
         * [
         *     'did' => 'abc',
         *     'sAccessToken' => 'xyz',
         *     'sFrontToken' => '123'
         * ]
         */

        $cookies = [];

        $parts =
            preg_split(
                '/;\s*/',
                $cookieString
            );


        foreach ($parts as $part) {

            $part = trim($part);

            if ($part === '') {
                continue;
            }


            $separator =
                strpos($part, '=');

            if ($separator === false) {
                continue;
            }


            $name =
                trim(
                    substr(
                        $part,
                        0,
                        $separator
                    )
                );


            $value =
                trim(
                    substr(
                        $part,
                        $separator + 1
                    )
                );


            if ($name === '') {
                continue;
            }


            $cookies[$name] =
                $value;
        }


        if (empty($cookies)) {

            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'کوکی‌ها قابل تفسیر نیست'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }


        /*
         * Store cookies in PHP session.
         */
        DivarCookieManager::setCookies(
            $cookies
        );


        /*
         * Check only cookie names.
         *
         * NEVER log the cookie values.
         */
        Logger::info(
            'Divar cookies stored',
            [
                'cookie_names' =>
                    array_keys($cookies)
            ]
        );


        echo json_encode([
            'success' => true,
            'message' => 'کوکی‌های دیوار با موفقیت ذخیره شد',
            'cookie_names' => array_keys($cookies)
        ], JSON_UNESCAPED_UNICODE);
    }
    private function prepareSaveState(string $citySlug, int $page): void
    {
        $places = is_array($this->results['places'] ?? null)
            ? $this->results['places']
            : [];
        $context = [
            'province' => $this->selectedProvince,
            'city' => $this->selectedCity,
            'city_slug' => $citySlug,
            'category' => $this->selectedCategory,
            'query' => $this->selectedQuery,
            'page' => $page,
        ];

        $this->saveResultSetKey = SelectedPlaceService::remember('divar', $places, $context);

        try {
            $this->hydrateStoredDivarResults($places);
        } catch (\Throwable $e) {
            $this->logPhoneIssue('WARNING', 'Could not check saved Divar places', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark listings that already exist in accommodations and copy any stored
     * contact phone onto the search result so the view can show it instead of
     * a "get phone" button.
     *
     * @param array<int,mixed> $places
     */
    private function hydrateStoredDivarResults(array $places): void
    {
        $ids = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $id = ProviderAccommodationMapper::externalId('divar', $place);

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        $records = (new AccommodationRepository())->findStoredByExternalIds('divar', $ids);
        $this->savedPlaceIds = [];

        if ($records === [] || !is_array($this->results['places'] ?? null)) {
            return;
        }

        foreach ($this->results['places'] as $index => $place) {
            if (!is_array($place)) {
                continue;
            }

            $id = ProviderAccommodationMapper::externalId('divar', $place);

            if ($id === null || !isset($records[$id])) {
                continue;
            }

            $this->savedPlaceIds[$id] = true;
            $phone = $records[$id]['phone'] ?? null;

            if (is_string($phone) && $phone !== '') {
                $this->results['places'][$index]['telephone'] = $phone;
                $this->results['places'][$index]['phone'] = $phone;
            }
        }
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
    private function generateUuidV4(): string
    {
        $data = random_bytes(16);

        // Version 4
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);

        // RFC 4122 variant
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return sprintf(
            '%s-%s-%s-%s-%s',
            bin2hex(substr($data, 0, 4)),
            bin2hex(substr($data, 4, 2)),
            bin2hex(substr($data, 6, 2)),
            bin2hex(substr($data, 8, 2)),
            bin2hex(substr($data, 10, 6))
        );
    }
    private function logPhoneIssue(string $level, string $message, array $context = []): void
    {
        Logger::issue('divar_phone', $level, $message, $context);
    }

    /**
     * True when Divar rejected the stored session and the user must log in
     * again with their phone number (OTP), not when a captcha blocked us.
     */
    private function isExpiredDivarSession(int $httpCode, mixed $response): bool
    {
        if ($httpCode === 401) {
            return true;
        }

        $body = is_string($response) ? $response : '';

        return preg_match('/jwt|expired|unauthorized|unauthenticated/i', $body) === 1;
    }

    private function lookupStoredDivarPhone(string $placeId): ?string
    {
        try {
            return (new AccommodationRepository())->findStoredPhone('divar', $placeId);
        } catch (\Throwable $e) {
            $this->logPhoneIssue('WARNING', 'Could not look up stored Divar phone', [
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getPhoneNumberAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $placeId = is_string($_POST['place_id'] ?? null)
            ? trim($_POST['place_id'])
            : '';

        $this->logPhoneIssue('INFO', 'get_phone started', [
            'place_id' => $placeId === '' ? null : $placeId,
        ]);

        if ($placeId === '') {
            $this->logPhoneIssue('WARNING', 'get_phone missing place_id', []);
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'message' => 'شناسه آگهی الزامی است'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $storedPhone = $this->lookupStoredDivarPhone($placeId);

        if ($storedPhone !== null) {
            $this->logPhoneIssue('INFO', 'get_phone reused stored phone', [
                'place_id' => $placeId,
            ]);

            echo json_encode([
                'success' => true,
                'already_saved' => true,
                'phone_number' => $storedPhone,
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * Get Divar cookies from the current session.
         */
        $cookies = DivarCookieManager::getCookies();
        $this->logPhoneIssue('INFO', 'get_phone session cookies', [
            'place_id' => $placeId,
            'cookie_names' => array_keys($cookies),
        ]);
        if (empty($cookies)) {
            $this->logPhoneIssue('WARNING', 'get_phone not authenticated', [
                'place_id' => $placeId,
            ]);
            http_response_code(401);

            echo json_encode([
                'success' => false,
                'authentication_required' => true,
                'message' => 'ابتدا وارد دیوار شوید.'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $ad = DivarSearchAdStore::find($placeId);

        if ($ad === null) {
            $this->logPhoneIssue('WARNING', 'get_phone missing search snapshot', [
                'place_id' => $placeId,
            ]);
            http_response_code(409);

            echo json_encode([
                'success' => false,
                'message' => 'اطلاعات آگهی در دسترس نیست. لطفاً جستجو را دوباره انجام دهید.'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * Build Cookie header.
         */
        $cookieParts = [];

        foreach ($cookies as $name => $value) {
            $cookieParts[] = $name . '=' . $value;
        }

        $cookieString = implode('; ', $cookieParts);

        /*
         * Divar uses a UUID for contact_uuid.
         */
        $contactUuid = $this->generateUuidV4();

        /*
         * tracker_session_id follows the pattern observed
         * in the real browser request:
         *
         * UUID_POST_TOKEN_P
         *
         * Example:
         *
         * cd3cc2c7-affe-4db3-b83f-d7b6baa5af62_QaJYvUXP_P
         */

        if (!isset($_SESSION['divar_tracker_uuid'])) {
            $_SESSION['divar_tracker_uuid'] = $this->generateUuidV4();
        }

        $trackerUuid = $_SESSION['divar_tracker_uuid'];

        $trackerSessionId =
            $trackerUuid . '_' . $placeId . '_P';

        /*
         * Divar contact endpoint.
         */
        $url =
            'https://api.divar.ir/v8/postcontact/web/contact_info_v2/' .
            rawurlencode($placeId);

        $requestData = [
            'contact_uuid' => $contactUuid,
            'tracker_session_id' => $trackerSessionId,
        ];

        $json = json_encode(
            $requestData,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            http_response_code(500);

            echo json_encode([
                'success' => false,
                'message' => 'ساخت درخواست ناموفق بود'
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * These are the headers that matter from the
         * successful browser request.
         *
         * We intentionally don't copy dynamic Sentry tracing
         * headers because they are not necessary for the
         * contact request itself.
         */
        $headers = [
            'accept: application/json, text/plain, */*',
            'accept-language: en-US,en;q=0.9',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://divar.ir',
            'pragma: no-cache',
            'referer: https://divar.ir/',
            'user-agent: ' . Config::get(
                'user_agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
                'AppleWebKit/537.36 (KHTML, like Gecko) ' .
                'Chrome/153.0.0.0 Safari/537.36'
            ),
            'x-render-type: CSR',
            'x-screen-size: 1920x444',
            'x-web-serving-mode: desktop',
            'cookie: ' . $cookieString
        ];

        /*
         * Execute request.
         */
        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $curlError = curl_error($ch);

        curl_close($ch);

        $this->logPhoneIssue('INFO', 'get_phone Divar HTTP', [
            'place_id' => $placeId,
            'http_code' => $httpCode,
            'curl_error' => $curlError === '' ? null : $curlError,
            'response_bytes' => is_string($response) ? strlen($response) : 0,
        ]);

        /*
         * cURL error.
         */
        if ($response === false || $curlError !== '') {

            $this->logPhoneIssue('ERROR', 'Divar phone request cURL error', [
                'place_id' => $placeId,
                'url' => $url,
                'error' => $curlError,
            ]);

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'phone_fetch_failed' => true,
                'message' => self::PHONE_FETCH_FAILURE_MESSAGE
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * Expired Divar session (JWT): drop the dead cookies and ask the
         * browser to open the OTP login, same as a first-time login.
         * Captcha / other 403s still use phone_fetch_failed so they do not
         * loop the login modal.
         */
        if ($httpCode === 401 || $httpCode === 403) {
            if ($this->isExpiredDivarSession($httpCode, $response)) {
                DivarCookieManager::clearCookies();

                $this->logPhoneIssue('WARNING', 'Divar session expired, OTP login required', [
                    'place_id' => $placeId,
                    'http_code' => $httpCode,
                    'response' => is_string($response) ? $response : null,
                ]);

                http_response_code(401);

                echo json_encode([
                    'success' => false,
                    'authentication_required' => true,
                    'message' => 'نشست دیوار منقضی شده است. با شماره موبایل دوباره وارد شوید.',
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            $this->logPhoneIssue('ERROR', 'Divar phone request authentication failed', [
                'place_id' => $placeId,
                'http_code' => $httpCode,
                'response' => is_string($response) ? $response : null,
            ]);

            http_response_code(401);

            echo json_encode([
                'success' => false,
                'authentication_required' => true,
                'phone_fetch_failed' => true,
                'message' => self::PHONE_FETCH_FAILURE_MESSAGE
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * Other HTTP errors.
         */
        if ($httpCode < 200 || $httpCode >= 300) {

            $this->logPhoneIssue('ERROR', 'Divar phone request failed', [
                'place_id' => $placeId,
                'http_code' => $httpCode,
                'response' => is_string($response) ? $response : null,
            ]);

            http_response_code(
                $httpCode > 0 ? $httpCode : 500
            );

            echo json_encode([
                'success' => false,
                'phone_fetch_failed' => true,
                'message' => self::PHONE_FETCH_FAILURE_MESSAGE
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        /*
         * Decode response.
         */
        $result = json_decode(
            $response,
            true
        );

        if (
            !is_array($result) ||
            json_last_error() !== JSON_ERROR_NONE
        ) {

            $this->logPhoneIssue('ERROR', 'Invalid Divar phone response', [
                'place_id' => $placeId,
                'json_error' => json_last_error_msg(),
                'response' => is_string($response) ? $response : null,
            ]);

            http_response_code(500);

            echo json_encode([
                'success' => false,
                'phone_fetch_failed' => true,
                'message' => self::PHONE_FETCH_FAILURE_MESSAGE
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $phoneNumber = DivarPhoneParser::parse($result);

        if ($phoneNumber !== null && $phoneNumber !== '') {
            try {
                if (($_SESSION['divar_schema_ready'] ?? null) !== Schema::VERSION) {
                    Schema::ensureTables();
                    $_SESSION['divar_schema_ready'] = Schema::VERSION;
                }

                (new AccommodationRepository())->upsertWithContact($ad, $phoneNumber);
            } catch (\Throwable $e) {
                $this->logPhoneIssue('ERROR', 'Divar ad/contact could not be saved', [
                    'place_id' => $placeId,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                http_response_code(500);

                echo json_encode([
                    'success' => false,
                    'message' => 'شماره تماس دریافت شد، اما ذخیره آگهی و مخاطب ناموفق بود. لطفاً دوباره تلاش کنید.'
                ], JSON_UNESCAPED_UNICODE);

                return;
            }

            $this->logPhoneIssue('INFO', 'get_phone stored number', [
                'place_id' => $placeId,
                'http_code' => $httpCode,
            ]);

            echo json_encode([
                'success' => true,
                'already_saved' => true,
                'phone_number' => $phoneNumber,
            ], JSON_UNESCAPED_UNICODE);

            return;
        }

        $this->logPhoneIssue('ERROR', 'Divar phone number not found in response', [
            'place_id' => $placeId,
            'http_code' => $httpCode,
            'widgets' => DivarPhoneParser::widgetSummary($result),
            'response' => is_string($response) ? $response : null,
        ]);

        http_response_code(404);

        echo json_encode([
            'success' => false,
            'phone_fetch_failed' => true,
            'message' => self::PHONE_FETCH_FAILURE_MESSAGE
        ], JSON_UNESCAPED_UNICODE);
    }


    /**
     * Add these two private methods to DivarController, and wire them into
     * handleRequest() the same way getPhoneNumberAction() is wired in:
     *
     *     if (isset($_POST['action']) && $_POST['action'] === 'divar_send_otp') {
     *         $this->divarSendOtpAction();
     *         return;
     *     }
     *     if (isset($_POST['action']) && $_POST['action'] === 'divar_verify_otp') {
     *         $this->divarVerifyOtpAction();
     *         return;
     *     }
     *
     * Both must be registered BEFORE the existing 'get_phone' check, order doesn't
     * matter much but keep them grouped with the other AJAX actions.
     */

    /**
     * Step 1: user submits their phone number.
     * Calls open-initiate-page page:1, extracts preAuthSessionId + device_id
     * from the returned form, stashes them in the PHP session, and tells the
     * front end to show the OTP input.
     */
    private function divarSendOtpAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $phone = trim($_POST['phone'] ?? '');

        // Basic Iranian mobile format check: 09XXXXXXXXX
        if (!preg_match('/^09\d{9}$/', $phone)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'شماره موبایل نامعتبر است'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $payload = [
            'specification' => [
                'page' => 1,
                'data' => [
                    'data' => [
                        'phone' => ['str' => ['value' => $phone]],
                        'send_code_method' => ['str' => ['value' => 'SMS']],
                    ],
                    'online_request_response_data' => new \stdClass(),
                ],
                'is_reload' => false,
            ],
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'accept: application/json-divar-filled',
            'accept-language: en-US,en;q=0.9',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://divar.ir',
            'pragma: no-cache',
            'referer: https://divar.ir/',
            'user-agent: ' . Config::get(
                'user_agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'
            ),
            'x-standard-divar-error: true',
            'x-web-serving-mode: desktop',
        ];

        // Divar's flow engine keys state off cookies like did/cdid/etc, not auth.
        // A fresh anonymous cookie jar is fine here since the user isn't logged in yet.
        $ch = curl_init('https://api.divar.ir/v8/auth/open-initiate-page');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            $this->logPhoneIssue('ERROR', 'Divar send-code cURL error', ['error' => $curlError]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'خطا در ارتباط با دیوار'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $result = json_decode($response, true);

        if (!is_array($result) || $httpCode < 200 || $httpCode >= 300) {
            $this->logPhoneIssue('ERROR', 'Divar send-code failed', [
                'http_code' => $httpCode,
                'response' => is_string($response) ? $response : null,
            ]);
            http_response_code($httpCode > 0 ? $httpCode : 500);
            echo json_encode(['success' => false, 'message' => 'ارسال کد ناموفق بود'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Pull preAuthSessionId + device_id out of the I_HIDDEN_ROW widgets.
        $preAuthSessionId = null;
        $deviceId = null;

        $widgets = $result['page']['widget_list'] ?? [];
        foreach ($widgets as $widget) {
            if (($widget['widget_type'] ?? '') !== 'I_HIDDEN_ROW') {
                continue;
            }
            $stringField = $widget['data']['string_field'] ?? null;
            if (!$stringField) {
                continue;
            }
            if (($stringField['key'] ?? '') === 'pre_auth_session_id') {
                $preAuthSessionId = $stringField['default'] ?? null;
            }
            if (($stringField['key'] ?? '') === 'device_id') {
                $deviceId = $stringField['default'] ?? null;
            }
        }

        if (!$preAuthSessionId || !$deviceId) {
            Logger::error('Divar send-code: could not parse session/device id', ['response' => $response]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'پاسخ دیوار قابل پردازش نبود'], JSON_UNESCAPED_UNICODE);
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['divar_otp'] = [
            'phone' => $phone,
            'pre_auth_session_id' => $preAuthSessionId,
            'device_id' => $deviceId,
            'created_at' => time(),
        ];

        $this->logPhoneIssue('INFO', 'Divar OTP sent', ['phone_suffix' => substr($phone, -4)]);

        echo json_encode([
            'success' => true,
            'message' => 'کد تأیید ارسال شد'
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Step 2: user submits the 6-digit code.
     * Calls the SuperTokens consume endpoint and stores the returned session
     * cookies via DivarCookieManager.
     */
    private function divarVerifyOtpAction(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $code = trim($_POST['code'] ?? '');

        if (!preg_match('/^\d{4,8}$/', $code)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'کد نامعتبر است'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $otpState = $_SESSION['divar_otp'] ?? null;

        if (!$otpState || empty($otpState['pre_auth_session_id']) || empty($otpState['device_id'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ابتدا شماره موبایل را ارسال کنید'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Expire the OTP attempt after 5 minutes to match Divar's own code lifetime.
        if (time() - ($otpState['created_at'] ?? 0) > 300) {
            unset($_SESSION['divar_otp']);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'کد منقضی شده، دوباره تلاش کنید'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $payload = [
            'preAuthSessionId' => $otpState['pre_auth_session_id'],
            'deviceId' => $otpState['device_id'],
            'userInputCode' => $code,
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = [
            'accept: application/json, text/plain, */*',
            'accept-language: en-US,en;q=0.9',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://divar.ir',
            'pragma: no-cache',
            'referer: https://divar.ir/',
            'rid: passwordless',
            'st-auth-mode: cookie',
            'user-agent: ' . Config::get(
                'user_agent',
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'
            ),
            'x-standard-divar-error: true',
            'x-web-serving-mode: desktop',
        ];

        $ch = curl_init('https://api.divar.ir/v8/authenticate/signinup/code/consume');
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // need response headers for Set-Cookie
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $raw = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            Logger::error('Divar verify-code cURL error', ['error' => $curlError]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'خطا در ارتباط با دیوار'], JSON_UNESCAPED_UNICODE);
            return;
        }

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        if ($httpCode < 200 || $httpCode >= 300) {
            // NEVER log $rawHeaders here - it may contain Set-Cookie tokens.
            Logger::error('Divar verify-code failed', ['http_code' => $httpCode]);
            http_response_code($httpCode === 401 || $httpCode === 400 ? 400 : 500);
            echo json_encode(['success' => false, 'message' => 'کد وارد شده صحیح نیست'], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Parse Set-Cookie headers into name => value pairs.
        $cookies = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (stripos($line, 'Set-Cookie:') !== 0) {
                continue;
            }
            $cookiePart = trim(substr($line, strlen('Set-Cookie:')));
            // Take only the "name=value" segment before the first ';'
            $nameValue = explode(';', $cookiePart, 2)[0];
            $eq = strpos($nameValue, '=');
            if ($eq === false) {
                continue;
            }
            $name = trim(substr($nameValue, 0, $eq));
            $value = trim(substr($nameValue, $eq + 1));
            if ($name !== '') {
                $cookies[$name] = $value;
            }
        }

        if (empty($cookies)) {
            Logger::error('Divar verify-code: no cookies returned', ['http_code' => $httpCode]);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'ورود ناموفق بود'], JSON_UNESCAPED_UNICODE);
            return;
        }

        DivarCookieManager::setCookies($cookies);

        $this->logPhoneIssue('INFO', 'Divar login succeeded via OTP', [
            'cookie_names' => array_keys($cookies),
        ]);

        unset($_SESSION['divar_otp']);

        echo json_encode([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز بود'
        ], JSON_UNESCAPED_UNICODE);
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
            $this->selectedQuery,
            $this->results['divar_current_page'] ?? 1,
            $this->results['page_count'] ?? 1,
            $this->results['total'] ?? 0,
            $this->saveResultSetKey,
            $this->savedPlaceIds
        );
        $view->render();
    }
}

$controller = new DivarController();
$controller->run();