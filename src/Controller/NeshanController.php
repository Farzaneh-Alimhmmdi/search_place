<?php

namespace Src\Controller;

use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\SelectedPlaceService;
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
    private int $currentPage = 1;
    private ?string $saveResultSetKey = null;
    private array $savedPlaceIds = [];

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
        $this->provinces = json_decode(file_get_contents($root . '/config/provinces.json'), true) ?? [];
        $this->citySlugs = Config::get('city_slugs');
        $this->categories = require $root . '/config/categories.php';
    }

    private function handleRequest(): void
    {
        $this->selectedCity = $_POST['city'] ?? '';
        $this->selectedCategory = trim(strtolower($_POST['place'] ?? 'guest-house'));
        $this->currentPage = max(1, (int)($_POST['page'] ?? 1));
        $this->results = null;
        $this->error = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (($_POST['action'] ?? '') === 'save_place') {
                SelectedPlaceAction::handle('neshan');
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
                    // Use pagination (memory efficient) - only fetch current page
                    $result = $this->service->search($citySlug, $this->selectedCategory, $this->currentPage);
                    $this->results = $result['success'] ? $result : null;
                    $this->error = $result['success'] ? null : ($result['error'] ?? 'خطای ناشناخته');

                    // Cache the displayed page for explicit selected saves and mark persisted rows.
                    if ($this->results && !empty($this->results['places'])) {
                        $this->prepareSaveState($citySlug);
                    }
                } catch (\Exception $e) {
                    Logger::error('Neshan Search failed', ['error' => $e->getMessage()]);
                    $this->error = 'خطا در ارتباط با سرور نشان';
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
            'page' => $this->currentPage,
        ];

        $this->saveResultSetKey = SelectedPlaceService::remember('neshan', $places, $context);

        try {
            $this->savedPlaceIds = SelectedPlaceService::savedIds('neshan', $places);
        } catch (\Throwable $e) {
            Logger::warning('Could not check saved Neshan places', [
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
            'neshan',
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
        $view->render();
    }
}

$controller = new NeshanController();
$controller->run();