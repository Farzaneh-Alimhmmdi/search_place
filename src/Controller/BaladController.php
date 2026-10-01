<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Http\CurlHttpClient;
use Src\Balad\BaladClient;
use Src\Balad\BaladSearchService;
use Src\Support\SelectedPlaceService;
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
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/balad.php');
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

            if (($_POST['action'] ?? '') === 'call') {
                $this->sendJsonResponse([
                    'success' => false,
                    'message' => 'ثبت تماس برای نتایج بلد غیرفعال است.',
                ], 410);
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
                    }
                } catch (\Exception $e) {
                    Logger::error('Search failed', ['error' => $e->getMessage()]);
                    $this->error = $this->selectedCategory .'در' .$this->selectedCity . ' یافت نشد';
                }
            }
        }
    }

    /**
     * Cache this Balad page and mark rows already present in accommodations.
     */
    private function cacheSearchResults(string $citySlug): void
    {
        $places = is_array($this->results['places'] ?? null)
            ? $this->results['places']
            : [];
        $context = [
            'city' => $this->selectedCity,
            'city_slug' => $citySlug,
            'category' => $this->selectedCategory,
            'page' => $this->currentPage,
        ];

        $this->saveResultSetKey = SelectedPlaceService::remember('balad', $places, $context);

        if ($places === []) {
            return;
        }

        try {
            $this->connectDatabase();
            $this->savedPlaceIds = SelectedPlaceService::savedIds('balad', $places);
        } catch (\Throwable $e) {
            Logger::warning('Could not check saved Balad places', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function saveSelectedPlace(): void
    {
        try {
            $this->connectDatabase();
        } catch (\Throwable $e) {
            Logger::error('Could not connect to save selected Balad place', [
                'error' => $e->getMessage(),
            ]);
            $this->sendJsonResponse([
                'success' => false,
                'message' => 'اتصال به پایگاه داده برای ذخیره اقامتگاه ناموفق بود.',
            ], 500);
        }

        SelectedPlaceAction::handle('balad');
    }

    private function connectDatabase(): void
    {
        Db::connect(
            Config::get('db_host', '127.0.0.1'),
            Config::get('db_port', 3306),
            Config::get('db_database', 'search_place'),
            Config::get('db_username', 'root'),
            Config::get('db_password', '')
        );
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
            $this->saveResultSetKey,
            $this->savedPlaceIds
        );
        $view->render();
    }
}

$controller = new BaladController();
$controller->run();
