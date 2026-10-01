<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Http\CurlHttpClient;
use Src\Balad\BaladClient;
use Src\Balad\BaladContactRepository;
use Src\Balad\BaladPlaceMapper;
use Src\Balad\BaladSearchService;
use Src\Divar\AccommodationRepository;
use Src\Support\Schema;
use Src\View\SearchView;

final class BaladController
{
    private CurlHttpClient $http;
    private BaladClient $client;
    private BaladSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $categories = [];

    private string $selectedCity = '';
    private string $selectedCategory = 'guest-house';
    private ?array $results = null;
    private ?string $error = null;
    private ?string $baladResultSetKey = null;

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/balad.php');
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

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

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

        $this->client = new BaladClient(
            $this->http,
            Config::get('base_url'),
            Config::get('preview_bulk_url')
        );
        $this->service = new BaladSearchService($this->client);
    }

    private function loadData(): void
    {
        $root = dirname(__DIR__, 2);
        $this->provinces = json_decode(file_get_contents($root . '/provinces.json'), true) ?? [];
        $this->citySlugs = Config::get('city_slugs');
        $this->categories = require $root . '/config/categories.php';
    }

    private int $currentPage = 1;

    private function handleRequest(): void
    {
        $this->selectedCity = $_POST['city'] ?? '';
        $this->selectedCategory = $_POST['place'] ?? 'guest-house';
        $this->currentPage = max(1, (int)($_POST['page'] ?? 1));
        $this->results = null;
        $this->error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'save_place') {
                $this->saveSelectedPlace();
                return;
            }

            // Handle call logging (AJAX)
            if (isset($_POST['action']) && $_POST['action'] === 'call' && isset($_POST['place_id'])) {
                try {
                    // Call tracking uses its own table and never writes to the
                    // Divar collector's accommodations data.
                    $this->logCall(
                        $_POST['place_id'] ?? '',
                        $_POST['phone'] ?? '',
                        $_POST['city'] ?? $this->selectedCity ?? '',
                        $_POST['category'] ?? $this->selectedCategory ?? '',
                        $_POST['description'] ?? ''
                    );
                } catch (\Throwable $e) {
                    Logger::error('Balad call logging failed', ['error' => $e->getMessage()]);
                    http_response_code(500);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode([
                        'success' => false,
                        'message' => 'ثبت تماس در پایگاه داده ناموفق بود.',
                    ]);
                    exit;
                }

                return; // Exit early for AJAX call
            }

            if ($this->selectedCity) {
                $citySlug = $this->citySlugs[$this->selectedCity] ?? '';

                if ($citySlug === '') {
                    foreach ($this->provinces as $prov) {
                        if (($prov['name'] ?? '') === $this->selectedCity && !empty($prov['slug'])) {
                            $citySlug = $prov['slug'];
                            break;
                        }
                    }
                }

                if ($citySlug === '') {
                    $citySlug = rawurlencode($this->selectedCity);
                }

                try {
                    $result = $this->service->search($citySlug, $this->selectedCategory, $this->currentPage);
                    $this->results = $result['success'] ? $result : null;
                    $this->error = $result['success'] ? null : ($result['error'] ?? 'خطای ناشناخته');

                    if ($this->results) {
                        // Cache this page in the session so only an explicitly
                        // selected result can be saved later.
                        $this->cacheSearchResults($citySlug);

                        // Schema preparation does not save places. Keep it
                        // separate from call-status loading so one failure does
                        // not prevent the existing status lookup from running.
                        if (!empty($this->results['places'])) {
                            try {
                                Schema::ensureTables();
                            } catch (\Throwable $e) {
                                Logger::warning('Could not prepare Balad result schema', [
                                    'error' => $e->getMessage(),
                                ]);
                            }

                            try {
                                $this->loadExistingCallLogs();
                            } catch (\Throwable $e) {
                                Logger::warning('Could not load Balad call logs', [
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                } catch (\Exception $e) {
                    Logger::error('Search failed', ['error' => $e->getMessage()]);
                    $this->error = $this->selectedCategory .'در' .$this->selectedCity . ' یافت نشد';
                }
            }
        }
    }

    /**
     * Cache the current page server-side so a save action can only select a
     * place that was actually returned to this user's Balad search.
     */
    private function cacheSearchResults(string $citySlug): void
    {
        $this->baladResultSetKey = null;

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $places = $this->results['places'] ?? [];

        if (!is_array($places) || $places === []) {
            return;
        }

        $placesById = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $externalId = BaladPlaceMapper::externalId($place);

            if ($externalId !== null) {
                $placesById[$externalId] = $place;
            }
        }

        if ($placesById === []) {
            return;
        }

        try {
            $key = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            Logger::warning('Could not create Balad result selection key', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $now = time();
        $sets = $_SESSION['balad_search_result_sets'] ?? [];
        $sets = is_array($sets) ? $sets : [];

        foreach ($sets as $existingKey => $set) {
            if (!is_array($set) || (int) ($set['expires_at'] ?? 0) < $now) {
                unset($sets[$existingKey]);
            }
        }

        $sets[$key] = [
            'expires_at' => $now + 1800,
            'context' => [
                'city' => $this->selectedCity,
                'city_slug' => $citySlug,
                'category' => $this->selectedCategory,
                'page' => $this->currentPage,
            ],
            'places' => $placesById,
        ];

        while (count($sets) > 5) {
            $oldestKey = array_key_first($sets);

            if ($oldestKey === null) {
                break;
            }

            unset($sets[$oldestKey]);
        }

        $_SESSION['balad_search_result_sets'] = $sets;
        $this->baladResultSetKey = $key;
    }

    /**
     * Save only the place explicitly selected from the current Balad results.
     */
    private function saveSelectedPlace(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $key = $_POST['result_set_key'] ?? null;
        $placeId = $_POST['place_id'] ?? null;

        if (
            session_status() !== PHP_SESSION_ACTIVE ||
            !is_string($key) || preg_match('/^[a-f0-9]{32}$/i', $key) !== 1 ||
            !is_string($placeId) || $placeId === '' || strlen($placeId) > 1024
        ) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => 'درخواست ذخیره‌سازی مکان نامعتبر است.',
            ], 400);
        }

        $set = $_SESSION['balad_search_result_sets'][$key] ?? null;

        if (
            !is_array($set) ||
            (int) ($set['expires_at'] ?? 0) < time() ||
            !is_array($set['places'] ?? null)
        ) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => 'نتیجه جستجو منقضی شده است؛ دوباره جستجو کنید.',
            ], 410);
        }

        $place = $set['places'][$placeId] ?? null;
        $context = is_array($set['context'] ?? null) ? $set['context'] : [];

        if (!is_array($place)) {
            $this->sendJsonResponse([
                'success' => false,
                'message' => 'مکان انتخاب‌شده در نتایج این جستجو نیست.',
            ], 404);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        try {
            Schema::ensureTables();

            $row = BaladPlaceMapper::toRow($place, $context);

            if ($row === null) {
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => 'شناسه مکان برای ذخیره‌سازی معتبر نیست.',
                ], 422);
            }

            $phone = BaladPlaceMapper::contactPhone($place);

            if ($phone !== null) {
                $row['contact_id'] = (new BaladContactRepository())->findOrCreate($phone);
            }

            $saveResult = (new AccommodationRepository())->upsertMany([$row]);

            if (!$saveResult['ok'] || $saveResult['failed'] > 0) {
                Logger::error('Could not save selected Balad place', [
                    'external_id' => $row['external_id'],
                    'error' => $saveResult['error'],
                ]);
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => 'ذخیره مکان انتخاب‌شده در پایگاه داده ناموفق بود.',
                ], 500);
            }

            Logger::info('Selected Balad place stored', [
                'external_id' => $row['external_id'],
                'contact_saved' => $phone !== null,
                'inserted' => $saveResult['inserted'],
                'updated' => $saveResult['updated'],
                'unchanged' => $saveResult['unchanged'],
            ]);

            $this->sendJsonResponse([
                'success' => true,
                'message' => 'مکان انتخاب‌شده با موفقیت ذخیره شد.',
                'contact_saved' => $phone !== null,
            ]);
        } catch (\Throwable $e) {
            Logger::error('Could not save selected Balad place', [
                'place_id' => $placeId,
                'error' => $e->getMessage(),
            ]);
            $this->sendJsonResponse([
                'success' => false,
                'message' => 'ذخیره مکان انتخاب‌شده در پایگاه داده ناموفق بود.',
            ], 500);
        }
    }

    /** @param array<string,mixed> $payload */
    private function sendJsonResponse(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        exit;
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

        // Use INSERT IGNORE to prevent duplicates, or check first
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

    private function render(): void
    {
        $view = new SearchView(
            $this->provinces,
            $this->categories,
            $this->citySlugs,
            $this->results,
            $this->error,
            $this->selectedCity,
            $this->selectedCategory,
            'balad',
            '', // selectedProvince
            [], // provinceCities
            [],  // allProvinceCities
            '', // selectedQuery
            $this->currentPage,
            $this->results['page_count'] ?? 1,
            0, // totalResults is used by Divar pagination only
            $this->baladResultSetKey
        );
        // Pass existing call logs to view
        $view->setExistingCallLogs($this->existingCallLogs ?? []);
        $view->render();
    }
}

$controller = new BaladController();
$controller->run();
