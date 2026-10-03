<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Http\CurlHttpClient;
use Src\Behtarino\BehtarinoClient;
use Src\Behtarino\BehtarinoSearchService;
use Src\Support\SelectedPlaceService;
use Src\View\SearchView;

/**
 * BehtarinoController - Handles search requests using the Behtarino provider.
 *
 * Behtarino serves plain server HTML (no API key, no login): one list fetch
 * per city (merged across the province's cities) plus one detail fetch per
 * card (phones are embedded in detail pages), so a page can take a minute -
 * the PHP time limit is raised for the search.
 */
final class BehtarinoController
{
    private BehtarinoClient $client;
    private BehtarinoSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $categories = [];
    private array $cities = [];

    private string $selectedCity = '';
    private string $selectedCategory = 'اقامتگاه';
    private ?array $results = null;
    private ?string $error = null;
    private array $existingCallLogs = [];
    private int $currentPage = 1;
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/behtarino.php');
        Db::connect(
            Config::get('db_host', '127.0.0.1'),
            Config::get('db_port', 3306),
            Config::get('db_database', 'search_place'),
            Config::get('db_username', 'root'),
            Config::get('db_password', '')
        );
        Logger::setPath(Config::get('log_path', 'storage/logs'));

        $this->initClient();
        $this->loadData();
        $this->handleRequest();
        $this->render();
    }

    private function initClient(): void
    {
        $root = dirname(__DIR__, 2);
        $citiesFile = (string) Config::get('cities_file', $root . '/config/behtarino/cities.json');
        if (is_file($citiesFile)) {
            $decoded = json_decode((string) file_get_contents($citiesFile), true);
            $this->cities = is_array($decoded) ? $decoded : [];
        }

        $http = new CurlHttpClient([
            'timeout' => Config::get('timeout', 30),
            'connect_timeout' => Config::get('connect_timeout', 10),
            'user_agent' => Config::get('user_agent'),
            'max_retries' => Config::get('max_retries', 3),
            'request_delay' => Config::get('request_delay', 1),
        ]);

        $this->client = new BehtarinoClient(
            $http,
            (string) Config::get('endpoint', 'https://behtarino.com'),
            (array) Config::get('categories', []),
            $this->cities,
            (int) Config::get('detail_delay_ms', 200),
            (int) Config::get('detail_timeout', 15)
        );
        $this->service = new BehtarinoSearchService($this->client);
    }

    private function loadData(): void
    {
        $root = dirname(__DIR__, 2);
        $this->provinces = json_decode(file_get_contents($root . '/config/provinces.json'), true) ?? [];
        $this->citySlugs = Config::get('city_slugs', []);
        $this->categories = Config::get('categories', []);

        if ($this->citySlugs === []) {
            $this->citySlugs = require $root . '/config/provinces.php';
        }
    }

    private function defaultCategory(): string
    {
        foreach ($this->categories as $cat) {
            if (is_array($cat) && isset($cat['value'])) {
                return (string) $cat['value'];
            }
        }
        return 'اقامتگاه';
    }

    private function handleRequest(): void
    {
        $this->selectedCity = $_POST['city'] ?? '';
        $this->selectedCategory = trim((string) ($_POST['place'] ?? ''));
        $this->currentPage = max(1, (int)($_POST['page'] ?? 1));
        $this->results = null;
        $this->error = null;
        $this->existingCallLogs = [];

        if ($this->selectedCategory === '') {
            $this->selectedCategory = $this->defaultCategory();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'save_place') {
                SelectedPlaceAction::handle('behtarino');
            }

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
                // One page fans out to dozens of detail fetches; stay clear of max_execution_time.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(240);
                }

                try {
                    $result = $this->service->search($this->selectedCity, $this->selectedCategory, $this->currentPage);
                    $this->results = $result['success'] ? $result : null;
                    $this->error = $result['success'] ? null : ($result['error'] ?? 'خطای ناشناخته');

                    // Fetch existing call logs for these results
                    if ($this->results && !empty($this->results['places'])) {
                        $this->loadExistingCallLogs();
                        $this->cacheSearchResults();
                    }
                } catch (\Exception $e) {
                    Logger::error('Behtarino Search failed', ['error' => $e->getMessage()]);
                    $this->error = 'خطا در ارتباط با بهترینو';
                }
            }
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

    /**
     * Cache this Behtarino page and mark rows already present in accommodations.
     */
    private function cacheSearchResults(): void
    {
        $places = is_array($this->results['places'] ?? null)
            ? $this->results['places']
            : [];
        $context = [
            'city' => $this->selectedCity,
            'city_slug' => '',
            'category' => $this->selectedCategory,
            'page' => $this->currentPage,
        ];

        $this->saveResultSetKey = SelectedPlaceService::remember('behtarino', $places, $context);

        if ($places === []) {
            return;
        }

        try {
            $this->savedPlaceIds = SelectedPlaceService::savedIds('behtarino', $places);
        } catch (\Throwable $e) {
            Logger::warning('Could not check saved Behtarino places', [
                'error' => $e->getMessage(),
            ]);
        }
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
            'behtarino',
            '', // selectedProvince
            [], // provinceCities
            [],  // allProvinceCities
            '', // selectedQuery
            $this->currentPage,
            $this->results['page_count'] ?? 1,
            $this->results['total_results'] ?? 0,
            $this->saveResultSetKey,
            $this->savedPlaceIds
        );
        // Pass existing call logs to view
        $view->setExistingCallLogs($this->existingCallLogs);
        $view->render();
    }
}

$controller = new BehtarinoController();
$controller->run();
