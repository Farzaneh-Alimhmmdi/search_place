<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\SelectedPlaceService;
use Src\Http\CurlHttpClient;
use Src\GoogleMap\GoogleMapClient;
use Src\GoogleMap\GoogleMapSearchService;
use Src\View\SearchView;

final class GoogleMapController
{
    private CurlHttpClient $http;
    private GoogleMapClient $client;
    private GoogleMapSearchService $service;

    private array $provinces = [];
    private array $citySlugs = [];
    private array $categories = [];

    private string $selectedCity = '';
    private string $selectedCategory = 'guest-house';
    private ?array $results = null;
    private ?string $error = null;
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/google_map.php');
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
            'user_agent' => Config::get('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'),
            'max_retries' => Config::get('max_retries'),
            'request_delay' => Config::get('request_delay'),
        ]);

        $this->client = new GoogleMapClient(
            $this->http,
            Config::get('endpoint'),
            Config::get('api_key'),
            Config::get('language', 'fa'),
            Config::get('region', 'ir'),
            Config::get('city_slugs')
        );
        $this->service = new GoogleMapSearchService($this->client);
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
        $this->selectedCategory = $_POST['place'] ?? 'guest-house';
        $this->results = null;
        $this->error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'save_place') {
                SelectedPlaceAction::handle('google_map');
            }

            if (isset($_POST['action']) && $_POST['action'] === 'call') {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(410);
                echo json_encode([
                    'success' => false,
                    'message' => 'ثبت تماس غیرفعال است. ذخیره اقامتگاه کافی است.',
                ], JSON_UNESCAPED_UNICODE);
                exit;
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
                    $this->error = $result['success'] ? null : ($result['error'] ?? 'خطای ناشناخته');

                    // Cache the displayed page for explicit selected saves and mark persisted rows.
                    if ($this->results && !empty($this->results['places'])) {
                        $this->prepareSaveState($citySlug);
                    }
                } catch (\Exception $e) {
                    Logger::error('Search failed', ['error' => $e->getMessage()]);
                    $this->error = $this->selectedCategory .'در' .$this->selectedCity . ' یافت نشد';
                }
            }
        }
    }

    private function prepareSaveState(string $citySlug): void
    {
        $places = is_array($this->results['places'] ?? null)
            ? $this->results['places']
            : [];
        $context = [
            'city' => $this->selectedCity,
            'city_slug' => $citySlug,
            'category' => $this->selectedCategory,
            'page' => 1,
        ];

        $this->saveResultSetKey = SelectedPlaceService::remember('google_map', $places, $context);

        try {
            $this->savedPlaceIds = SelectedPlaceService::savedIds('google_map', $places);
        } catch (\Throwable $e) {
            Logger::warning('Could not check saved Google Maps places', [
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
            'google_map',
            '', // selectedProvince
            [], // provinceCities
            [], // allProvinceCities
            '', // selectedQuery
            1, // currentPage
            $this->results['page_count'] ?? 1,
            $this->results['total'] ?? 0,
            $this->saveResultSetKey,
            $this->savedPlaceIds
        );
        $view->render();
    }
}

$controller = new GoogleMapController();
$controller->run();