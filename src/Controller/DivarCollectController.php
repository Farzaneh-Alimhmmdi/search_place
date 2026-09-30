<?php

namespace Src\Controller;

use Src\Divar\AccommodationRepository;
use Src\Divar\DivarAdMapper;
use Src\Divar\DivarSearchService;
use Src\Http\CurlHttpClient;
use Src\Support\Config;
use Src\Support\Db;
use Src\Support\HarvestJobStore;
use Src\Support\Logger;
use Src\Support\Schema;
use Src\Support\Str;
use Src\View\DivarCollectView;
use Throwable;

/**
 * Route: /divar_collect
 *
 * "Collect and store" version of the Divar search page.
 *
 * The selection UI is exactly the same as the Divar search page
 * (province -> city -> category -> optional keyword), but the result is NOT
 * shown as a paginated list of cards. Instead every ad of that search is
 * written into the `accommodations` table until Divar has no next page.
 *
 * ---------------------------------------------------------------------------
 * HOW THE "TOO MUCH DATA" PROBLEM IS SOLVED
 * ---------------------------------------------------------------------------
 * A city/category combination can hold thousands of ads. Fetching all of them
 * inside one PHP request would hit max_execution_time, memory_limit and the
 * web server's request timeout. Therefore the harvest runs in small steps:
 *
 *   browser ──POST action=collect_step──▶ PHP: fetch ONE Divar page (cursor)
 *                                          store that batch in MySQL
 *                                          keep the next cursor in session
 *   browser ◀────────── JSON counters ─────┘
 *   browser waits `step_delay_ms`, then asks for the next step ... until done
 *
 * Consequences:
 *   - every request is short (one Divar call + one small DB batch),
 *   - PHP memory stays flat: a batch is stored and then dropped,
 *   - the run can be paused/resumed (the cursor lives in the session),
 *   - re-running is harmless: (provider, external_id) is UNIQUE and the write
 *     is an upsert, so nothing is ever duplicated,
 *   - the user sees live progress instead of a pagination bar.
 */
final class DivarCollectController
{
    private CurlHttpClient $http;

    private ?AccommodationRepository $repository = null;

    /** @var array<int,array<string,string>> */
    private array $provinces = [];

    /** @var array<string,string> Persian city name -> slug */
    private array $citySlugs = [];

    /** @var array<string,array<string,mixed>> slug -> city info */
    private array $cities = [];

    /** @var array<int,array<string,string>> */
    private array $divarCategories = [];

    private string $selectedProvince = '';
    private string $selectedCity = '';
    private string $selectedCityName = '';
    private string $selectedCategory = '';
    private string $selectedQuery = '';

    /** @var array<string,mixed>|null resolved city info of the selection */
    private ?array $cityInfo = null;

    private ?string $error = null;
    private ?string $fatalError = null;

    /** @var array<string,mixed>|null */
    private ?array $resumableJob = null;

    private bool $autoStart = false;

    /** @var array<string,mixed> */
    private array $options = [];

    public function run(): void
    {
        Config::load(__DIR__ . '/../../config/divar.php');
        Logger::setPath((string) Config::get('log_path', 'storage/logs'));

        HarvestJobStore::ensureSession();

        $this->loadData();
        $this->options = $this->defaultOptions();
        $this->initHttp();
        $this->connectDatabase();

        /*
         * Any POST carrying an `action` is an AJAX call of the collector and
         * must answer with JSON only (json() exits).
         */
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action !== '') {
            $this->handleAction($action);

            return;
        }

        $this->handlePageRequest();
        $this->render();
    }

    // ---------------------------------------------------------------------
    // Bootstrap
    // ---------------------------------------------------------------------

    private function loadData(): void
    {
        $root = dirname(__DIR__, 2);

        $provincesFile = $root . '/provinces.json';

        if (is_file($provincesFile)) {
            $decoded = json_decode((string) file_get_contents($provincesFile), true);

            $this->provinces = is_array($decoded) ? $decoded : [];
        }

        $slugs = Config::get('city_slugs', []);
        $this->citySlugs = is_array($slugs) ? $slugs : [];

        $categories = Config::get('categories', []);
        $this->divarCategories = is_array($categories) ? $categories : [];

        $citiesFile = (string) Config::get('cities_file', $root . '/config/cities.json');

        if (is_file($citiesFile)) {
            $decoded = json_decode((string) file_get_contents($citiesFile), true);

            $this->cities = is_array($decoded) ? $decoded : [];
        }
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
    }

    /**
     * Connect to MySQL and make sure the two tables exist.
     *
     * A failure here is not fatal for the page itself: the form is still
     * rendered together with a clear Persian error, instead of a blank screen.
     */
    private function connectDatabase(): void
    {
        try {
            Db::connect(
                (string) Config::get('db_host', '127.0.0.1'),
                (int) Config::get('db_port', 3306),
                (string) Config::get('db_database', 'search_place'),
                (string) Config::get('db_username', 'root'),
                (string) Config::get('db_password', '')
            );
        } catch (Throwable $e) {
            $this->fatalError = 'اتصال به دیتابیس برقرار نشد: ' . $e->getMessage();

            Logger::error(
                'Divar collect: database connection failed',
                ['error' => $e->getMessage()]
            );

            return;
        }

        try {
            /*
             * CREATE TABLE IF NOT EXISTS is cheap, but there is no reason to
             * run it on every single AJAX step: once per session is enough.
             */
            if (empty($_SESSION['divar_schema_ready'])) {
                Schema::ensureTables();

                $_SESSION['divar_schema_ready'] = true;

                Logger::info('Divar collect: schema ensured');
            }

            $this->options['schema_ready'] = true;
            $this->repository = new AccommodationRepository();
        } catch (Throwable $e) {
            $this->fatalError = 'جدول‌های دیتابیس آماده نشد: ' . $e->getMessage();

            Logger::error(
                'Divar collect: schema failed',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultOptions(): array
    {
        $collect = Config::get('collect', []);

        if (!is_array($collect)) {
            $collect = [];
        }

        return [
            'page_limit' => $this->clamp((int) ($collect['page_limit'] ?? 24), 1, 24),
            'step_delay_ms' => $this->clamp((int) ($collect['step_delay_ms'] ?? 800), 0, 20000),
            'max_steps' => $this->clamp((int) ($collect['max_steps'] ?? 0), 0, 1000000),
            'max_retries' => $this->clamp((int) ($collect['max_retries'] ?? 6), 1, 20),
            'retry_base_ms' => $this->clamp((int) ($collect['retry_base_ms'] ?? 2000), 500, 30000),
            'store_raw' => (bool) ($collect['store_raw'] ?? true),
            'max_empty_pages' => $this->clamp((int) ($collect['max_empty_pages'] ?? 30), 1, 500),
            'log_tail' => $this->clamp((int) ($collect['log_tail'] ?? 8), 1, 50),
            'db_total' => null,
            'context_rows' => null,
            'schema_ready' => false,
        ];
    }

    // ---------------------------------------------------------------------
    // AJAX actions
    // ---------------------------------------------------------------------

    private function handleAction(string $action): void
    {
        switch ($action) {
            case 'collect_start':
                $this->actionStart();
                break;

            case 'collect_step':
                $this->actionStep();
                break;

            case 'collect_stop':
                $this->actionStop();
                break;

            case 'collect_status':
                $this->actionStatus();
                break;

            case 'collect_reset':
                $this->actionReset();
                break;

            default:
                $this->json(
                    [
                        'success' => false,
                        'fatal' => true,
                        'error' => 'کنش نامعتبر: ' . $action,
                    ],
                    400
                );
        }
    }

    /**
     * Create (or resume) a harvest job for the submitted selection.
     */
    private function actionStart(): void
    {
        $this->requireDatabase();

        $province = trim((string) ($_POST['province'] ?? ''));
        $cityValue = trim((string) ($_POST['city'] ?? ''));
        $category = trim((string) ($_POST['place'] ?? ''));
        $query = trim((string) ($_POST['query'] ?? ''));
        $resume = (string) ($_POST['resume'] ?? '') === '1';

        $categoryInfo = $this->findCategory($category);

        if ($categoryInfo === null) {
            $this->json(
                [
                    'success' => false,
                    'fatal' => true,
                    'error' => 'دسته‌بندی انتخاب‌شده معتبر نیست.',
                ],
                422
            );
        }

        $resolved = $this->resolveCity($cityValue);

        if (!$resolved['ok']) {
            $this->json(
                [
                    'success' => false,
                    'fatal' => true,
                    'error' => (string) $resolved['error'],
                ],
                422
            );
        }

        if ($province === '') {
            $province = (string) ($resolved['province'] ?? '');
        }

        /*
         * An empty form field must fall back to the configured default instead
         * of becoming 0 (= unlimited pages / no delay at all).
         */
        $maxSteps = (array_key_exists('max_steps', $_POST) && trim((string) $_POST['max_steps']) !== '')
            ? $this->clamp((int) $_POST['max_steps'], 0, 1000000)
            : (int) $this->options['max_steps'];

        $stepDelay = (array_key_exists('step_delay_ms', $_POST) && trim((string) $_POST['step_delay_ms']) !== '')
            ? $this->clamp((int) $_POST['step_delay_ms'], 0, 20000)
            : (int) $this->options['step_delay_ms'];

        $signature = HarvestJobStore::signature(
            (string) $resolved['divar_city_id'],
            (string) $resolved['name'],
            $category,
            $query
        );

        $existing = HarvestJobStore::unfinished($signature);

        $storedRows = $this->repository === null
            ? 0
            : $this->repository->countByContext(
                DivarAdMapper::PROVIDER,
                $province,
                (string) $resolved['name'],
                $category
            );

        /*
         * Resume: keep cursor and counters, continue where we stopped.
         */
        if ($resume && $existing !== null) {
            $existing['status'] = HarvestJobStore::STATUS_RUNNING;
            $existing['max_steps'] = $maxSteps;
            $existing['step_delay_ms'] = $stepDelay;
            $existing['last_error'] = null;
            $existing['stop_reason'] = null;

            HarvestJobStore::save($existing);

            $this->json([
                'success' => true,
                'resumed' => true,
                'message' => 'ادامه‌ی جمع‌آوری از صفحه‌ی ' . ((int) $existing['steps'] + 1),
                'job' => HarvestJobStore::publicView($existing),
                'stored_rows' => $storedRows,
            ]);
        }

        /*
         * A brand new run replaces an older unfinished run of the same search.
         */
        if ($existing !== null) {
            $existing['status'] = HarvestJobStore::STATUS_PAUSED;
            $existing['stop_reason'] = 'اجرای جدید جایگزین شد';

            HarvestJobStore::save($existing);
        }

        $job = HarvestJobStore::create([
            'signature' => $signature,
            'province' => $province,
            'city' => (string) $resolved['name'],
            'city_slug' => (string) $resolved['slug'],
            'divar_city_id' => (string) $resolved['divar_city_id'],
            'divar_slug' => (string) $resolved['divar_slug'],
            'category' => $category,
            'category_label' => (string) ($categoryInfo['label'] ?? $category),
            'query' => $query,
            'page_limit' => (int) $this->options['page_limit'],
            'max_steps' => $maxSteps,
            'step_delay_ms' => $stepDelay,
            'max_empty_pages' => (int) $this->options['max_empty_pages'],
        ]);

        $job = HarvestJobStore::pushLog(
            $job,
            'جمع‌آوری شروع شد: ' . $resolved['name'] . ' / ' .
            ($categoryInfo['label'] ?? $category) .
            ($query !== '' ? ' / «' . $query . '»' : '')
        );

        HarvestJobStore::save($job);

        Logger::info(
            'Divar collect started',
            [
                'job_id' => $job['id'],
                'city' => $resolved['name'],
                'divar_city_id' => $resolved['divar_city_id'],
                'category' => $category,
                'query' => $query,
            ]
        );

        $this->json([
            'success' => true,
            'resumed' => false,
            'message' => 'جمع‌آوری شروع شد',
            'job' => HarvestJobStore::publicView($job),
            'stored_rows' => $storedRows,
        ]);
    }

    /**
     * Fetch ONE Divar page with the stored cursor, write it to MySQL, and
     * report the counters. This is the heart of the collector.
     */
    private function actionStep(): void
    {
        $this->requireDatabase();

        /*
         * A single step is one Divar call plus one small DB batch, so the
         * default 30s limit is normally plenty. Raise it anyway for slow
         * networks.
         */
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $jobId = trim((string) ($_POST['job_id'] ?? ''));

        $job = $jobId !== '' ? HarvestJobStore::find($jobId) : null;

        if ($job === null) {
            $this->json(
                [
                    'success' => false,
                    'fatal' => true,
                    'restart' => true,
                    'error' => 'اجرای جمع‌آوری در این نشست پیدا نشد. لطفاً دوباره «شروع جمع‌آوری» را بزنید.',
                ],
                404
            );
        }

        if ((string) ($job['status'] ?? '') === HarvestJobStore::STATUS_DONE) {
            $this->json([
                'success' => true,
                'done' => true,
                'message' => $job['stop_reason'] ?? 'جمع‌آوری پیش‌تر تمام شده است.',
                'job' => HarvestJobStore::publicView($job),
            ]);
        }

        $maxSteps = (int) ($job['max_steps'] ?? 0);

        if ($maxSteps > 0 && (int) ($job['steps'] ?? 0) >= $maxSteps) {
            $this->finishJob($job, 'به سقف صفحات تعیین‌شده (' . $maxSteps . ' صفحه) رسید');
        }

        $startedAt = microtime(true);

        $cursor = (isset($job['cursor']) && is_string($job['cursor']) && $job['cursor'] !== '')
            ? $job['cursor']
            : null;

        try {
            $service = new DivarSearchService(
                $this->http,
                (string) ($job['query'] ?? ''),
                (string) ($job['divar_city_id'] ?? ''),
                null,
                (string) ($job['category'] ?? ''),
                null
            );

            $page = $service->searchPage(
                $cursor,
                (int) ($job['page_limit'] ?? 24)
            );
        } catch (Throwable $e) {
            /*
             * Network / Divar problems are retryable: the cursor did not move,
             * so the very same step can simply be requested again.
             */
            $this->failStep($job, $e->getMessage());
        }

        if (empty($page['success'])) {
            $this->failStep($job, (string) ($page['error'] ?? 'پاسخ ناموفق از دیوار'));
        }

        $ads = isset($page['ads']) && is_array($page['ads']) ? $page['ads'] : [];

        $pagination = isset($page['pagination']) && is_array($page['pagination'])
            ? $page['pagination']
            : [];

        $hasNext = (bool) ($pagination['has_next_page'] ?? false);

        $nextCursor = $pagination['next_cursor'] ?? null;

        if (!is_string($nextCursor) || trim($nextCursor) === '') {
            $nextCursor = null;
        }

        /*
         * Infinite loop guard: Divar must hand us a NEW cursor.
         */
        if ($hasNext && $nextCursor !== null && $nextCursor === $cursor) {
            $this->failStep(
                $job,
                'دیوار همان cursor قبلی را برگرداند؛ برای جلوگیری از حلقه‌ی بی‌پایان متوقف شدیم.',
                false
            );
        }

        $context = [
            'province' => (string) ($job['province'] ?? ''),
            'city' => (string) ($job['city'] ?? ''),
            'city_slug' => (string) ($job['city_slug'] ?? ''),
            'divar_city_id' => (string) ($job['divar_city_id'] ?? ''),
            'category' => (string) ($job['category'] ?? ''),
            'category_label' => (string) ($job['category_label'] ?? ''),
            'query' => (string) ($job['query'] ?? ''),
            'job_id' => (string) ($job['id'] ?? ''),
            'page' => (int) ($job['steps'] ?? 0) + 1,
            'store_raw' => (bool) $this->options['store_raw'],
        ];

        $rows = DivarAdMapper::toRows($ads, $context);

        $write = $this->repository === null
            ? ['ok' => false, 'error' => 'دیتابیس در دسترس نیست.', 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'failed_ids' => []]
            : $this->repository->upsertMany($rows);

        if ($write['ok'] === false) {
            /*
             * The cursor is NOT advanced, so the retry re-fetches this page and
             * the upsert makes it safe.
             */
            $this->failStep($job, (string) $write['error']);
        }

        $pageNumber = (int) ($job['steps'] ?? 0) + 1;

        $job['steps'] = $pageNumber;
        $job['fetched'] = (int) ($job['fetched'] ?? 0) + count($ads);
        $job['inserted'] = (int) ($job['inserted'] ?? 0) + (int) $write['inserted'];
        $job['updated'] = (int) ($job['updated'] ?? 0) + (int) $write['updated'];
        $job['unchanged'] = (int) ($job['unchanged'] ?? 0) + (int) $write['unchanged'];
        $job['failed'] = (int) ($job['failed'] ?? 0) + (int) $write['failed'];
        $job['empty_pages'] = $ads === [] ? (int) ($job['empty_pages'] ?? 0) + 1 : 0;
        $job['status'] = HarvestJobStore::STATUS_RUNNING;
        $job['last_error'] = null;
        $job['cursor'] = ($hasNext && $nextCursor !== null) ? $nextCursor : null;

        $titles = [];

        foreach (array_slice($rows, 0, 3) as $row) {
            $titles[] = Str::limit((string) ($row['title'] ?? ''), 70);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $batch = [
            'page' => $pageNumber,
            'fetched' => count($ads),
            'inserted' => (int) $write['inserted'],
            'updated' => (int) $write['updated'],
            'unchanged' => (int) $write['unchanged'],
            'failed' => (int) $write['failed'],
            'failed_ids' => $write['failed_ids'],
            'elapsed_ms' => $elapsedMs,
        ];

        /*
         * Finish conditions. Only the reason is decided here, the job is saved
         * exactly once below.
         */
        $finishReason = null;

        if (!$hasNext || $nextCursor === null) {
            $finishReason = 'همه‌ی صفحات دیوار دریافت و ذخیره شد';
        } elseif ((int) $job['empty_pages'] >= (int) ($job['max_empty_pages'] ?? 30)) {
            $finishReason = 'دیوار چند صفحه‌ی پشت‌سرهم خالی برگرداند؛ جمع‌آوری متوقف شد';
        } elseif ($maxSteps > 0 && (int) $job['steps'] >= $maxSteps) {
            $finishReason = 'به سقف صفحات تعیین‌شده (' . $maxSteps . ' صفحه) رسید';
        }

        $job = HarvestJobStore::pushLog($job, $this->batchLine($batch));

        HarvestJobStore::save($job);

        if ($finishReason !== null) {
            $this->finishJob($job, $finishReason);
        }

        $this->json([
            'success' => true,
            'done' => false,
            'has_next_page' => true,
            'batch' => $batch,
            'titles' => $titles,
            'job' => HarvestJobStore::publicView($job),
        ]);
    }

    /**
     * Pause the run (cursor stays in the session, so it can be resumed).
     */
    private function actionStop(): void
    {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));

        $job = $jobId !== '' ? HarvestJobStore::find($jobId) : null;

        if ($job === null) {
            $this->json([
                'success' => true,
                'stopped' => true,
                'message' => 'اجرای فعالی پیدا نشد.',
            ]);
        }

        $job['status'] = HarvestJobStore::STATUS_PAUSED;
        $job['stop_reason'] = 'توقف توسط کاربر';

        $job = HarvestJobStore::pushLog($job, 'توقف توسط کاربر');

        HarvestJobStore::save($job);

        Logger::info(
            'Divar collect paused',
            [
                'job_id' => $job['id'],
                'steps' => $job['steps'],
                'fetched' => $job['fetched'],
            ]
        );

        $this->json([
            'success' => true,
            'stopped' => true,
            'message' => 'جمع‌آوری متوقف شد؛ هر زمان خواستید ادامه دهید.',
            'job' => HarvestJobStore::publicView($job),
        ]);
    }

    /**
     * Report the state of a job (used after a page reload to offer "resume").
     */
    private function actionStatus(): void
    {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));

        $job = null;

        if ($jobId !== '') {
            $job = HarvestJobStore::find($jobId);
        } else {
            $signature = $this->signatureFromPost();

            $job = HarvestJobStore::unfinished($signature);
        }

        $payload = [
            'success' => true,
            'job' => $job === null ? null : HarvestJobStore::publicView($job),
            'db_ready' => $this->repository !== null,
        ];

        if ($this->fatalError !== null) {
            $payload['fatal'] = true;
            $payload['error'] = $this->fatalError;
        }

        if ($this->repository !== null) {
            $payload['db_total'] = $this->repository->countByProvider();
        }

        $this->json($payload);
    }

    /**
     * Drop a job (or every job) from the session.
     */
    private function actionReset(): void
    {
        $jobId = trim((string) ($_POST['job_id'] ?? ''));

        if ($jobId !== '') {
            HarvestJobStore::forget($jobId);
        } else {
            HarvestJobStore::flush();
        }

        $this->json([
            'success' => true,
            'message' => 'اجرای جمع‌آوری پاک شد.',
        ]);
    }

    /**
     * Mark the job as finished, save it and answer the browser.
     *
     * @param array<string,mixed> $job
     */
    private function finishJob(array $job, string $reason): void
    {
        $job['status'] = HarvestJobStore::STATUS_DONE;
        $job['stop_reason'] = $reason;
        $job['finished_at'] = time();
        $job['cursor'] = null;

        $job = HarvestJobStore::pushLog($job, 'پایان: ' . $reason);

        HarvestJobStore::save($job);

        Logger::info(
            'Divar collect finished',
            [
                'job_id' => $job['id'],
                'reason' => $reason,
                'steps' => $job['steps'],
                'fetched' => $job['fetched'],
                'inserted' => $job['inserted'],
                'updated' => $job['updated'],
                'unchanged' => $job['unchanged'],
                'failed' => $job['failed'],
                'errors' => $job['errors'],
            ]
        );

        $payload = [
            'success' => true,
            'done' => true,
            'message' => $reason,
            'job' => HarvestJobStore::publicView($job),
        ];

        if ($this->repository !== null) {
            $payload['db_total'] = $this->repository->countByProvider();
        }

        $this->json($payload);
    }

    /**
     * Report a failed step. The cursor is untouched, so the browser can retry.
     *
     * @param array<string,mixed> $job
     */
    private function failStep(array $job, string $message, bool $retry = true): void
    {
        $job['errors'] = (int) ($job['errors'] ?? 0) + 1;
        $job['last_error'] = Str::limit($message, 500);
        $job['status'] = HarvestJobStore::STATUS_ERROR;

        $job = HarvestJobStore::pushLog($job, 'خطا: ' . Str::limit($message, 160));

        HarvestJobStore::save($job);

        Logger::error(
            'Divar collect step failed',
            [
                'job_id' => $job['id'] ?? null,
                'steps' => $job['steps'] ?? null,
                'error' => $message,
            ]
        );

        $this->json([
            'success' => false,
            'retry' => $retry,
            'error' => Str::limit($message, 400),
            'job' => HarvestJobStore::publicView($job),
        ]);
    }

    /**
     * Abort the AJAX call when the database is unusable.
     */
    private function requireDatabase(): void
    {
        if ($this->fatalError === null && $this->repository !== null) {
            return;
        }

        $this->json(
            [
                'success' => false,
                'fatal' => true,
                'error' => $this->fatalError ?? 'دیتابیس در دسترس نیست.',
            ],
            500
        );
    }

    private function batchLine(array $batch): string
    {
        return sprintf(
            'صفحهٔ %d: %d آگهی (جدید %d، به‌روز %d، تکراری %d%s) در %d ms',
            (int) $batch['page'],
            (int) $batch['fetched'],
            (int) $batch['inserted'],
            (int) $batch['updated'],
            (int) $batch['unchanged'],
            ((int) $batch['failed'] > 0) ? '، ناموفق ' . (int) $batch['failed'] : '',
            (int) $batch['elapsed_ms']
        );
    }

    // ---------------------------------------------------------------------
    // Page request
    // ---------------------------------------------------------------------

    private function handlePageRequest(): void
    {
        $input = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') ? $_POST : $_GET;

        $this->selectedProvince = trim((string) ($input['province'] ?? ''));
        $this->selectedCity = trim((string) ($input['city'] ?? ''));
        $this->selectedCategory = trim((string) ($input['place'] ?? ''));
        $this->selectedQuery = trim((string) ($input['query'] ?? ''));
        $this->autoStart = (string) ($input['start'] ?? '') === '1';

        if (array_key_exists('max_steps', $input) && $input['max_steps'] !== '') {
            $this->options['max_steps'] = $this->clamp((int) $input['max_steps'], 0, 1000000);
        }

        if (array_key_exists('step_delay_ms', $input) && $input['step_delay_ms'] !== '') {
            $this->options['step_delay_ms'] = $this->clamp((int) $input['step_delay_ms'], 0, 20000);
        }

        /*
         * Category must be one of the configured values (RULES.md #2).
         */
        if ($this->findCategory($this->selectedCategory) === null) {
            if ($this->selectedCategory !== '') {
                $this->error = 'دسته‌بندی «' . Str::limit($this->selectedCategory, 40) . '» معتبر نیست؛ مقدار پیش‌فرض انتخاب شد.';
            }

            $this->selectedCategory = $this->defaultCategory();
        }

        if ($this->selectedCity === '') {
            $this->autoStart = false;

            return;
        }

        $resolved = $this->resolveCity($this->selectedCity);

        if (!$resolved['ok']) {
            $this->error = (string) $resolved['error'];
            $this->autoStart = false;

            return;
        }

        $this->cityInfo = $resolved;
        $this->selectedCityName = (string) $resolved['name'];

        if ($this->selectedProvince === '') {
            $this->selectedProvince = (string) ($resolved['province'] ?? '');
        }

        /*
         * Is there an unfinished run for exactly this selection? Then the page
         * can offer "resume" instead of starting from the first page.
         */
        $this->resumableJob = HarvestJobStore::unfinished(
            HarvestJobStore::signature(
                (string) $resolved['divar_city_id'],
                (string) $resolved['name'],
                $this->selectedCategory,
                $this->selectedQuery
            )
        );
    }

    private function render(): void
    {
        $provinceCities = [];

        if ($this->selectedProvince !== '') {
            $provinceCities = $this->getCitiesForProvince($this->selectedProvince);
        }

        $allProvinceCities = [];

        foreach ($this->provinces as $province) {
            $name = $province['name'] ?? '';

            if (!is_string($name) || $name === '') {
                continue;
            }

            $allProvinceCities[$name] = $this->getCitiesForProvince($name);
        }

        if ($this->repository !== null) {
            $this->options['db_total'] = $this->repository->countByProvider();

            if ($this->cityInfo !== null) {
                $this->options['context_rows'] = $this->repository->countByContext(
                    DivarAdMapper::PROVIDER,
                    $this->selectedProvince,
                    $this->selectedCityName,
                    $this->selectedCategory
                );
            }
        }

        $view = new DivarCollectView([
            'provinces' => $this->provinces,
            'categories' => $this->divarCategories,
            'province_cities' => $provinceCities,
            'all_province_cities' => $allProvinceCities,
            'selected_province' => $this->selectedProvince,
            'selected_city' => $this->selectedCity,
            'selected_city_name' => $this->selectedCityName,
            'selected_category' => $this->selectedCategory,
            'selected_query' => $this->selectedQuery,
            'city_info' => $this->cityInfo,
            'error' => $this->error,
            'fatal_error' => $this->fatalError,
            'job' => $this->resumableJob === null ? null : HarvestJobStore::publicView($this->resumableJob),
            'endpoint' => $this->selfUrl(),
            'search_url' => $this->searchUrl(),
            'auto_start' => $this->autoStart,
            'options' => $this->options,
        ]);

        $view->render();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Resolve the posted city value (slug OR Persian name) to full city info.
     *
     * @return array<string,mixed> ok, error, slug, name, province, divar_slug, divar_city_id
     */
    private function resolveCity(string $value): array
    {
        $value = trim($value);

        $fail = static function (string $message): array {
            return [
                'ok' => false,
                'error' => $message,
                'slug' => '',
                'name' => '',
                'province' => '',
                'divar_slug' => '',
                'divar_city_id' => null,
            ];
        };

        if ($value === '') {
            return $fail('لطفاً استان و شهر را انتخاب کنید.');
        }

        $info = null;
        $slug = '';

        /*
         * 1) The value is a Persian city name that config/provinces.php maps
         *    to a slug.
         */
        $mapped = $this->citySlugs[$value] ?? null;

        if (is_string($mapped) && $mapped !== '' && isset($this->cities[$mapped])) {
            $slug = $mapped;
            $info = $this->cities[$mapped];
        }

        /*
         * 2) The value already is a slug of config/cities.json
         *    (this is what the form sends).
         */
        if ($info === null && isset($this->cities[$value])) {
            $slug = $value;
            $info = $this->cities[$value];
        }

        /*
         * 3) URL encoded variant.
         */
        if ($info === null) {
            $encoded = rawurlencode($value);

            if (isset($this->cities[$encoded])) {
                $slug = $encoded;
                $info = $this->cities[$encoded];
            }
        }

        if ($info === null || !is_array($info)) {
            return $fail('شهر «' . Str::limit($value, 40) . '» در فهرست شهرها (config/cities.json) پیدا نشد.');
        }

        $cityId = $info['city_id'] ?? null;

        $cityId = (is_int($cityId) || is_string($cityId)) && trim((string) $cityId) !== ''
            ? trim((string) $cityId)
            : null;

        /*
         * Without Divar's numeric city id the search API cannot be called.
         * (See README: "Divar city IDs".)
         */
        if ($cityId === null) {
            return $fail(
                'شناسه‌ی عددی شهر دیوار (city_id) برای «' .
                Str::limit((string) ($info['name'] ?? $value), 40) .
                '» در config/cities.json تعریف نشده است. بدون آن نمی‌توان از دیوار داده گرفت.'
            );
        }

        return [
            'ok' => true,
            'error' => null,
            'slug' => (string) ($info['slug'] ?? $slug),
            'name' => (string) ($info['name'] ?? $value),
            'province' => (string) ($info['province'] ?? ''),
            'divar_slug' => (string) ($info['divar_slug'] ?? $slug),
            'divar_city_id' => $cityId,
        ];
    }

    /**
     * @return array<string,string>|null
     */
    private function findCategory(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach ($this->divarCategories as $category) {
            if (!is_array($category)) {
                continue;
            }

            if ((string) ($category['value'] ?? '') === $value) {
                return [
                    'value' => $value,
                    'label' => (string) ($category['label'] ?? $value),
                ];
            }
        }

        return null;
    }

    private function defaultCategory(): string
    {
        foreach ($this->divarCategories as $category) {
            if (is_array($category) && isset($category['value'])) {
                return (string) $category['value'];
            }
        }

        return 'temporary-rent';
    }

    /**
     * @return array<string,string> slug => Persian name
     */
    private function getCitiesForProvince(string $provinceName): array
    {
        $result = [];

        foreach ($this->cities as $slug => $info) {
            if (!is_array($info)) {
                continue;
            }

            if ((string) ($info['province'] ?? '') === $provinceName) {
                $result[(string) $slug] = (string) ($info['name'] ?? $slug);
            }
        }

        return $result;
    }

    /**
     * Signature of the selection posted with an AJAX call.
     */
    private function signatureFromPost(): ?string
    {
        $resolved = $this->resolveCity(trim((string) ($_POST['city'] ?? '')));

        if (!$resolved['ok']) {
            return null;
        }

        $category = trim((string) ($_POST['place'] ?? ''));

        if ($this->findCategory($category) === null) {
            $category = $this->defaultCategory();
        }

        return HarvestJobStore::signature(
            (string) $resolved['divar_city_id'],
            (string) $resolved['name'],
            $category,
            trim((string) ($_POST['query'] ?? ''))
        );
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * URL of this page, used by the browser for every AJAX step.
     */
    private function selfUrl(): string
    {
        $path = $this->currentPath();

        if (preg_match('#/divar_collect/?$#', $path) === 1) {
            return rtrim($path, '/');
        }

        if (basename($path) === 'index.php') {
            return dirname($path) . '/divar_collect';
        }

        $parent = dirname($path);

        return ($parent === '/' ? '' : rtrim($parent, '/')) . '/divar_collect';
    }

    /**
     * URL of the normal (paginated) search page.
     */
    private function searchUrl(): string
    {
        $parent = dirname($this->selfUrl());

        return ($parent === '/' ? '' : $parent) . '/search_place';
    }

    private function currentPath(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        $path = parse_url($uri, PHP_URL_PATH);

        return (is_string($path) && $path !== '') ? $path : '/';
    }

    /**
     * Send a JSON answer and stop everything else (same pattern as the other
     * AJAX actions of this project).
     *
     * @param array<string,mixed> $payload
     */
    private function json(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
        }

        $encoded = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        echo is_string($encoded)
            ? $encoded
            : '{"success":false,"fatal":true,"error":"json_encode failed"}';

        exit;
    }
}

$controller = new DivarCollectController();
$controller->run();
