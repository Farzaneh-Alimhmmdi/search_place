<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Neshan\NeshanClient;
use Src\Neshan\NeshanSearchService;
use Src\View\SearchView;

/**
 * NeshanController - Handles search requests using Neshan provider.
 */
final class NeshanController
{
    private NeshanClient $client;
    private NeshanSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $categories = [];

    private string $selectedCity = '';
    private string $selectedCategory = 'guest-house';
    private ?array $results = null;
    private ?string $error = null;

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/balad.php'); // Reuse same config for DB
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
        $pythonScript = $root . '/src/Neshan/neshan_search.py';
        $pythonExecutable = Config::get('neshan_python', 'python');

        $this->client = new NeshanClient($pythonScript, $pythonExecutable, 120);
        $this->service = new NeshanSearchService($this->client);
    }

    private function loadData(): void
    {
        $root = dirname(__DIR__, 2);
        $this->provinces = json_decode(file_get_contents($root . '/provinces.json'), true) ?? [];
        $this->citySlugs = Config::get('city_slugs');
        $this->categories = require $root . '/config/categories.php';
    }

    private function handleRequest(): void
    {
        $this->selectedCity = $_POST['city'] ?? '';
        $this->selectedCategory = trim(strtolower($_POST['place'] ?? 'guest-house'));
        $this->results = null;
        $this->error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Handle call logging
            if (isset($_POST['action']) && $_POST['action'] === 'call' && isset($_POST['place_id'])) {
                $this->logCall($_POST['place_id'] ?? '', $_POST['phone'] ?? '', $this->selectedCity ?? '', $this->selectedCategory ?? '');
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
                    $result = $this->service->search($citySlug, $this->selectedCategory, 1);
                    $this->results = $result['success'] ? $result : null;
                    $this->error = $result['success'] ? null : 'Category not found in this city';
                } catch (\Exception $e) {
                    Logger::error('Neshan Search failed', ['error' => $e->getMessage()]);
                    $this->error = 'خطا در ارتباط با سرور نشان';
                }
            }
        }
    }

    private function logCall(string $placeId, string $phone, string $city, string $category): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $query = "INSERT INTO call_logs (place_id, phone_number, city, category, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = Db::getConnection()->prepare($query);
        $stmt->execute([$placeId, $phone, $city, $category, $ipAddress, $userAgent]);
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
            'neshan' // provider type
        );
        $view->render();
    }
}

$controller = new NeshanController();
$controller->run();