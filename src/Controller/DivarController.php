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
    private DivarSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $cities = [];
    private array $divarCategories = [];

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
        $this->service = new DivarSearchService($this->client);
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
                // Get Divar city slug from our cities mapping
                $cityInfo = $this->cities[$this->selectedCity] ?? null;
                $divarCitySlug = $cityInfo['divar_slug'] ?? $this->selectedCity;

                try {
                    $result = $this->service->search($divarCitySlug, $this->selectedCategory, $this->selectedQuery, 1);
                    $this->results = $result['success'] ? $result : null;
                    $this->error = $result['success'] ? null : ($result['error'] ?? 'خطای ناشناخته');

                    // Fetch existing call logs for these results
                    if ($this->results && !empty($this->results['places'])) {
                        $this->loadExistingCallLogs();
                    }
                } catch (\Exception $e) {
                    Logger::error('Divar Search failed', ['error' => $e->getMessage()]);
                    $this->error = 'خطا در ارتباط با دیوار';
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