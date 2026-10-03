<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Http\CurlHttpClient;
use Src\Vilayar\VilayarClient;
use Src\Vilayar\VilayarSearchService;
use Src\Support\SelectedPlaceService;
use Src\View\SearchView;

/**
 * VilayarController - Handles search requests using the Vilayar provider.
 *
 * Vilayar serves plain server HTML (no API key, no login): one list fetch
 * plus one detail fetch per card (owner phones live on detail pages), so a
 * page can take over a minute - the PHP time limit is raised for the search.
 */
final class VilayarController
{
    private VilayarClient $client;
    private VilayarSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $categories = [];
    private array $states = [];

    private string $selectedCity = '';
    private string $selectedCategory = '7';
    private ?array $results = null;
    private ?string $error = null;
    private array $existingCallLogs = [];
    private int $currentPage = 1;
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/vilayar.php');
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
        $statesFile = (string) Config::get('states_file', $root . '/config/vilayar/states.json');
        if (is_file($statesFile)) {
            $decoded = json_decode((string) file_get_contents($statesFile), true);
            $this->states = is_array($decoded) ? $decoded : [];
        }

        $http = new CurlHttpClient([
            'timeout' => Config::get('timeout', 30),
            'connect_timeout' => Config::get('connect_timeout', 10),
            'user_agent' => Config::get('user_agent'),
            'max_retries' => Config::get('max_retries', 3),
            'request_delay' => Config::get('request_delay', 1),
        ]);

        $this->client = new VilayarClient(
            $http,
            (string) Config::get('endpoint', 'https://vilayar.com'),
            (array) Config::get('categories', []),
            $this->states,
            (int) Config::get('detail_delay_ms', 200),
            (int) Config::get('detail_timeout', 15)
        );
        $this->service = new VilayarSearchService($this->client);
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
        return '7';
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
                SelectedPlaceAction::handle('vilayar');
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
                // One page fans out to ~40 detail fetches; stay clear of max_execution_time.
                if (function_exists('set_time_limit')) {
                    @set_time_limit(180);
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
                    Logger::error('Vilayar Search failed', ['error' => $e->getMessage()]);
                    $this->error = 'خطا در ارتباط با ویلایار';
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
            'vilayar',
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

    /**
     * Cache this Vilayar page and mark rows already present in accommodations.
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

        $this->saveResultSetKey = SelectedPlaceService::remember('vilayar', $places, $context);

        if ($places === []) {
            return;
        }

        try {
            $this->savedPlaceIds = SelectedPlaceService::savedIds('vilayar', $places);
        } catch (\Throwable $e) {
            Logger::warning('Could not check saved Vilayar places', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

$controller = new VilayarController();
$controller->run();
