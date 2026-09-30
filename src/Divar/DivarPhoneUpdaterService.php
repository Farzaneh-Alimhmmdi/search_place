<?php

namespace Src\Divar;

use PDO;
use Src\Support\Config;
use Src\Support\Db;
use Src\Support\Logger;
use Src\Support\Schema;
use Throwable;

/**
 * DivarPhoneUpdaterService
 *
 * Independent background service to fetch and update phone numbers for
 * Divar accommodation listings in the database.
 *
 * Features & Divar Limitation Safeguards:
 *  1. Request Delay & Jitter: Configurable base delay with random jitter to
 *     prevent robotic request signatures.
 *  2. Batch Processing: Processes a bounded number of listings per execution.
 *  3. Daily Safety Quota: Tracks 24-hour request counts to avoid Divar account bans.
 *  4. Rate-Limit Backoff (429): Persists a cooldown timestamp and pauses execution.
 *  5. Auth-Aware (401/403): Immediately halts if cookies expire to avoid failed request floods.
 *  6. Circuit Breaker: Aborts run if consecutive network/server errors exceed threshold.
 *  7. Process Mutex: Non-blocking file lock ensures overlapping cron runs do not clash.
 *  8. Idempotent DB Updates: Normalizes phones, deduplicates contacts, and records
 *     metadata in provider_data to avoid duplicate lookups.
 */
final class DivarPhoneUpdaterService
{
    private PDO $pdo;
    private AccommodationRepository $repository;
    private DivarContactFetcher $fetcher;
    private array $config;

    /**
     * @var resource|null
     */
    private $lockHandle = null;

    public function __construct(
        ?PDO $pdo = null,
        ?AccommodationRepository $repository = null,
        ?DivarContactFetcher $fetcher = null,
        array $config = []
    ) {
        $this->loadConfiguration();

        if (!empty($config)) {
            $this->config = array_replace_recursive($this->config, $config);
        }

        $this->pdo = $pdo ?? $this->resolvePdo();
        $this->repository = $repository ?? new AccommodationRepository($this->pdo);
        $this->fetcher = $fetcher ?? new DivarContactFetcher();
    }

    /**
     * Load configuration from config/divar.php.
     */
    private function loadConfiguration(): void
    {
        $configFile = dirname(__DIR__, 2) . '/config/divar.php';
        if (file_exists($configFile)) {
            Config::load($configFile);
        }

        $divarConfig = Config::get('phone_updater', []);

        $this->config = [
            'batch_size' => (int) ($divarConfig['batch_size'] ?? 20),
            'request_delay_ms' => (int) ($divarConfig['request_delay_ms'] ?? 3000),
            'jitter_min_ms' => (int) ($divarConfig['jitter_min_ms'] ?? 500),
            'jitter_max_ms' => (int) ($divarConfig['jitter_max_ms'] ?? 1500),
            'max_daily_requests' => (int) ($divarConfig['max_daily_requests'] ?? 100),
            'max_consecutive_errors' => (int) ($divarConfig['max_consecutive_errors'] ?? 3),
            'rate_limit_cooldown_seconds' => (int) ($divarConfig['rate_limit_cooldown_seconds'] ?? 1800),
            'lock_file' => (string) ($divarConfig['lock_file'] ?? 'storage/phone_updater.lock'),
            'state_file' => (string) ($divarConfig['state_file'] ?? 'storage/phone_updater_state.json'),
            'log_file' => (string) ($divarConfig['log_file'] ?? 'storage/logs/phone_updater.log'),
            'max_attempts' => (int) ($divarConfig['max_attempts'] ?? 3),
            'contact_endpoint' => (string) ($divarConfig['contact_endpoint'] ?? 'https://api.divar.ir/v8/postcontact/web/contact_info_v2/'),
        ];
    }

    /**
     * Ensure database connection.
     */
    private function resolvePdo(): PDO
    {
        try {
            return Db::getConnection();
        } catch (Throwable) {
            Db::connect(
                (string) Config::get('db_host', '127.0.0.1'),
                (int) Config::get('db_port', 3306),
                (string) Config::get('db_database', 'search_place'),
                (string) Config::get('db_username', 'root'),
                (string) Config::get('db_password', '')
            );
            return Db::getConnection();
        }
    }

    /**
     * Execute a phone update run.
     *
     * @param array{
     *     limit?: int,
     *     delay_ms?: int,
     *     city?: string|null,
     *     retry_failed?: bool,
     *     max_attempts?: int,
     *     dry_run?: bool,
     *     progress_callback?: callable|null
     * } $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $startTime = microtime(true);

        $limit = isset($options['limit']) ? (int) $options['limit'] : $this->config['batch_size'];
        $baseDelayMs = isset($options['delay_ms']) ? (int) $options['delay_ms'] : $this->config['request_delay_ms'];
        $city = $options['city'] ?? null;
        $retryFailed = (bool) ($options['retry_failed'] ?? false);
        $maxAttempts = isset($options['max_attempts']) ? (int) $options['max_attempts'] : $this->config['max_attempts'];
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $progressCallback = isset($options['progress_callback']) && is_callable($options['progress_callback'])
            ? $options['progress_callback']
            : null;

        $stats = [
            'total_found_in_db' => 0,
            'processed' => 0,
            'phones_found' => 0,
            'no_phone' => 0,
            'expired' => 0,
            'errors' => 0,
            'stopped_reason' => 'completed',
            'error_message' => null,
            'daily_requests_used' => $this->getDailyRequestCount(),
            'daily_requests_limit' => $this->config['max_daily_requests'],
            'elapsed_seconds' => 0.0,
        ];

        // 1. Acquire process mutex lock (if not dry-run)
        if (!$dryRun && !$this->acquireLock()) {
            $stats['stopped_reason'] = 'already_running';
            $stats['error_message'] = 'فرآیند دیگری در حال اجراست (Another instance is currently running).';
            $this->notify($progressCallback, 'locked', $stats);
            return $stats;
        }

        try {
            // 2. Check if currently in rate limit cooldown (HTTP 429)
            $rateLimit = $this->checkRateLimit();
            if ($rateLimit['limited']) {
                $stats['stopped_reason'] = 'rate_limited';
                $stats['error_message'] = sprintf(
                    'در دوره انتظار به دلیل محدودیت دیوار (HTTP 429) قرار دارد. زمان باقی‌مانده: %d ثانیه',
                    $rateLimit['remaining_seconds']
                );
                $this->notify($progressCallback, 'rate_limited', $stats);
                return $stats;
            }

            // 3. Check daily safety quota
            if ($stats['daily_requests_used'] >= $this->config['max_daily_requests']) {
                $stats['stopped_reason'] = 'daily_limit_reached';
                $stats['error_message'] = sprintf(
                    'سقف مجاز روزانه درخواست شماره تماس دیوار (%d درخواست) تکمیل شده است. ادامه کار فردا انجام خواهد شد.',
                    $this->config['max_daily_requests']
                );
                $this->notify($progressCallback, 'daily_limit', $stats);
                return $stats;
            }

            // 4. Verify Divar authentication
            if (!DivarCookieManager::hasValidAuth()) {
                $stats['stopped_reason'] = 'auth_missing';
                $stats['error_message'] = 'کوکی‌ها یا توکن ورود به دیوار موجود نیست یا منقضی شده است. لطفا مجددا وارد حساب دیوار شوید.';
                $this->notify($progressCallback, 'auth_missing', $stats);
                return $stats;
            }

            // 5. Query DB for accommodations without phone
            $pendingListings = $this->repository->findPendingPhoneAccommodations(
                $limit,
                $city,
                $retryFailed,
                $maxAttempts
            );

            $stats['total_found_in_db'] = count($pendingListings);

            if (empty($pendingListings)) {
                $stats['stopped_reason'] = 'no_pending_records';
                $stats['error_message'] = 'هیچ آگهی بدون شماره تماسی جهت استعلام یافت نشد.';
                $this->notify($progressCallback, 'no_pending', $stats);
                return $stats;
            }

            $this->notify($progressCallback, 'batch_start', [
                'count' => count($pendingListings),
                'limit' => $limit,
                'city' => $city,
            ]);

            // 6. Loop through listings
            $consecutiveErrors = 0;

            foreach ($pendingListings as $index => $listing) {
                // Check daily quota before each call
                if ($this->getDailyRequestCount() >= $this->config['max_daily_requests']) {
                    $stats['stopped_reason'] = 'daily_limit_reached';
                    $stats['error_message'] = 'سقف مجاز روزانه در حین اجرای دسته تکمیل شد.';
                    break;
                }

                // Check circuit breaker (consecutive errors)
                if ($consecutiveErrors >= $this->config['max_consecutive_errors']) {
                    $stats['stopped_reason'] = 'consecutive_errors';
                    $stats['error_message'] = sprintf(
                        'به دلیل %d خطای پیاپی در ارتباط با دیوار، اجرای فرآیند متوقف شد.',
                        $consecutiveErrors
                    );
                    break;
                }

                $token = (string) ($listing['external_id'] ?? '');
                $listingId = (int) $listing['id'];
                $listingTitle = (string) ($listing['title'] ?? '');

                if ($token === '') {
                    continue;
                }

                // Inter-request delay with random jitter (skip for first item)
                if ($index > 0 && !$dryRun) {
                    $jitter = mt_rand($this->config['jitter_min_ms'], $this->config['jitter_max_ms']);
                    $totalDelayMs = $baseDelayMs + $jitter;
                    $this->notify($progressCallback, 'delay', [
                        'delay_ms' => $totalDelayMs,
                        'index' => $index + 1,
                        'total' => count($pendingListings),
                    ]);
                    usleep($totalDelayMs * 1000);
                }

                $this->notify($progressCallback, 'item_start', [
                    'id' => $listingId,
                    'token' => $token,
                    'title' => $listingTitle,
                    'index' => $index + 1,
                    'total' => count($pendingListings),
                ]);

                // Dry run mode
                if ($dryRun) {
                    $stats['processed']++;
                    $this->notify($progressCallback, 'dry_run_item', [
                        'id' => $listingId,
                        'token' => $token,
                    ]);
                    continue;
                }

                // Fetch contact info from Divar
                $citySlug = $this->extractCitySlug($listing);
                $categorySlug = (string) ($listing['category'] ?? 'temporary-rent');

                $fetchResult = $this->fetcher->getContactInfo($token, $citySlug, $categorySlug);
                $this->incrementDailyRequestCount();
                $stats['daily_requests_used'] = $this->getDailyRequestCount();
                $stats['processed']++;

                // Process fetch result
                if ($fetchResult['success'] && !empty($fetchResult['phone'])) {
                    // Phone found!
                    $phone = $fetchResult['phone'];
                    $contactId = $this->repository->findOrCreateContact(
                        $phone,
                        $listingTitle,
                        'Divar listing: ' . $token
                    );
                    $this->repository->linkContactToAccommodation($listingId, $contactId, $phone);

                    $stats['phones_found']++;
                    $consecutiveErrors = 0;

                    $this->logInfo('Phone found for listing', [
                        'id' => $listingId,
                        'token' => $token,
                        'phone' => $this->maskPhone($phone),
                    ]);

                    $this->notify($progressCallback, 'item_found', [
                        'id' => $listingId,
                        'token' => $token,
                        'phone' => $phone,
                    ]);

                } elseif ($fetchResult['http_code'] === 429) {
                    // Rate limited! Trigger backoff cooldown and abort batch
                    $this->recordRateLimit($this->config['rate_limit_cooldown_seconds']);
                    $this->repository->markAccommodationContactStatus($listingId, 'rate_limited', 'HTTP 429 Rate Limited');

                    $stats['errors']++;
                    $stats['stopped_reason'] = 'rate_limited';
                    $stats['error_message'] = 'دیوار درخواست‌ها را به دلیل محدودیت نرخ رد کرد (HTTP 429). دوره خنک‌سازی فعال شد.';

                    $this->logWarning('Divar rate limited during batch', [
                        'token' => $token,
                        'cooldown_sec' => $this->config['rate_limit_cooldown_seconds'],
                    ]);

                    $this->notify($progressCallback, 'rate_limited', $stats);
                    break;

                } elseif ($fetchResult['http_code'] === 401 || $fetchResult['http_code'] === 403) {
                    // Auth expired! Abort batch immediately
                    $this->repository->markAccommodationContactStatus($listingId, 'auth_expired', 'Divar Auth Expired');

                    $stats['errors']++;
                    $stats['stopped_reason'] = 'auth_expired';
                    $stats['error_message'] = 'اعتبار نشست دیوار در حین اجرا منقضی شد. اجرای دسته متوقف گردید.';

                    $this->logError('Divar auth expired during batch', ['token' => $token]);
                    $this->notify($progressCallback, 'auth_expired', $stats);
                    break;

                } elseif ($fetchResult['http_code'] === 404) {
                    // Ad expired or removed from Divar
                    $this->repository->markAccommodationContactStatus(
                        $listingId,
                        'expired',
                        'آگهی در دیوار منقضی یا حذف شده است'
                    );

                    $stats['expired']++;
                    $consecutiveErrors = 0;

                    $this->notify($progressCallback, 'item_expired', [
                        'id' => $listingId,
                        'token' => $token,
                    ]);

                } elseif ($fetchResult['error'] === 'no_phone') {
                    // Listing has no phone (chat only or seller hidden)
                    $this->repository->markAccommodationContactStatus(
                        $listingId,
                        'no_phone',
                        $fetchResult['message'] ?? 'فاقد شماره تماس یا فقط چت'
                    );

                    $stats['no_phone']++;
                    $consecutiveErrors = 0;

                    $this->notify($progressCallback, 'item_no_phone', [
                        'id' => $listingId,
                        'token' => $token,
                    ]);

                } else {
                    // Transient error
                    $errorMessage = $fetchResult['message'] ?? $fetchResult['error'] ?? 'خطای نامشخص';
                    $this->repository->markAccommodationContactStatus($listingId, 'error', $errorMessage);

                    $stats['errors']++;
                    $consecutiveErrors++;

                    $this->notify($progressCallback, 'item_error', [
                        'id' => $listingId,
                        'token' => $token,
                        'error' => $errorMessage,
                    ]);
                }
            }

        } catch (Throwable $e) {
            $stats['stopped_reason'] = 'fatal_exception';
            $stats['error_message'] = 'خطای غیرمنتظره: ' . $e->getMessage();
            $this->logError('Exception in phone updater service', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        } finally {
            $this->releaseLock();
        }

        $stats['elapsed_seconds'] = round(microtime(true) - $startTime, 2);
        $this->notify($progressCallback, 'batch_finish', $stats);

        return $stats;
    }

    /**
     * Extract city slug from accommodation row.
     */
    private function extractCitySlug(array $listing): string
    {
        $providerData = $listing['provider_data'] ?? null;
        if (is_string($providerData)) {
            $decoded = json_decode($providerData, true);
            if (is_array($decoded) && !empty($decoded['harvest']['city_slug'])) {
                return (string) $decoded['harvest']['city_slug'];
            }
        }

        $city = (string) ($listing['city'] ?? '');
        $citySlugs = Config::get('city_slugs', []);

        if (isset($citySlugs[$city])) {
            return $citySlugs[$city];
        }

        return $city !== '' ? rawurlencode($city) : 'tehran';
    }

    /**
     * Check if currently in rate limit cooldown period.
     *
     * @return array{limited: bool, remaining_seconds: int}
     */
    public function checkRateLimit(): array
    {
        $state = $this->readState();
        $until = (int) ($state['rate_limit_until'] ?? 0);

        if ($until > time()) {
            return [
                'limited' => true,
                'remaining_seconds' => $until - time(),
            ];
        }

        return [
            'limited' => false,
            'remaining_seconds' => 0,
        ];
    }

    /**
     * Record a rate limit cooldown.
     */
    public function recordRateLimit(int $cooldownSeconds): void
    {
        $state = $this->readState();
        $state['rate_limit_until'] = time() + $cooldownSeconds;
        $state['last_rate_limit_at'] = date('c');
        $this->writeState($state);
    }

    /**
     * Get count of requests made today.
     */
    public function getDailyRequestCount(): int
    {
        $state = $this->readState();
        $today = date('Y-m-d');

        if (($state['daily_date'] ?? '') !== $today) {
            return 0;
        }

        return (int) ($state['daily_requests'] ?? 0);
    }

    /**
     * Increment the daily request counter.
     */
    public function incrementDailyRequestCount(): int
    {
        $state = $this->readState();
        $today = date('Y-m-d');

        if (($state['daily_date'] ?? '') !== $today) {
            $state['daily_date'] = $today;
            $state['daily_requests'] = 0;
        }

        $state['daily_requests'] = (int) ($state['daily_requests'] ?? 0) + 1;
        $state['last_request_at'] = date('c');
        $this->writeState($state);

        return $state['daily_requests'];
    }

    /**
     * Get comprehensive statistics for status reporting.
     */
    public function getDatabaseStats(?string $city = null): array
    {
        $rateLimit = $this->checkRateLimit();
        $dailyUsed = $this->getDailyRequestCount();
        $dailyLimit = $this->config['max_daily_requests'];

        return [
            'total_divar_accommodations' => $this->repository->countByProvider(DivarAdMapper::PROVIDER),
            'with_phone' => $this->repository->countWithPhone($city),
            'pending_phone' => $this->repository->countPendingPhone($city),
            'auth_valid' => DivarCookieManager::hasValidAuth(),
            'rate_limited' => $rateLimit['limited'],
            'rate_limit_remaining_sec' => $rateLimit['remaining_seconds'],
            'daily_requests_used' => $dailyUsed,
            'daily_requests_limit' => $dailyLimit,
            'daily_requests_remaining' => max(0, $dailyLimit - $dailyUsed),
            'batch_size' => $this->config['batch_size'],
            'request_delay_ms' => $this->config['request_delay_ms'],
        ];
    }

    /**
     * Reset failed statuses back to 'retry'.
     */
    public function resetFailedStatuses(?string $city = null): int
    {
        return $this->repository->resetFailedContactStatuses($city);
    }

    /**
     * Read the persistent state file.
     */
    private function readState(): array
    {
        $path = $this->resolvePath($this->config['state_file']);
        if (!file_exists($path)) {
            return [];
        }

        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write to the persistent state file.
     */
    private function writeState(array $state): void
    {
        $path = $this->resolvePath($this->config['state_file']);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(
            $path,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * Acquire non-blocking file lock to prevent concurrent cron executions.
     */
    private function acquireLock(): bool
    {
        $lockFile = $this->resolvePath($this->config['lock_file']);
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return true;
    }

    /**
     * Release file lock.
     */
    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }

        $lockFile = $this->resolvePath($this->config['lock_file']);
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Helper to resolve project-relative paths.
     */
    private function resolvePath(string $relativePath): string
    {
        if (str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $relativePath)) {
            return $relativePath;
        }

        $projectRoot = dirname(__DIR__, 2);
        return $projectRoot . '/' . ltrim($relativePath, '/\\');
    }

    /**
     * Helper to invoke progress callback if provided.
     */
    private function notify(?callable $callback, string $event, array $data): void
    {
        if ($callback !== null) {
            try {
                $callback($event, $data);
            } catch (Throwable) {
                // Ignore errors in user callback
            }
        }
    }

    /**
     * Mask phone number for safe logging.
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) >= 7) {
            return substr($phone, 0, 4) . '***' . substr($phone, -4);
        }
        return '***';
    }

    private function logInfo(string $message, array $context = []): void
    {
        Logger::info('[PhoneUpdater] ' . $message, $context);
    }

    private function logWarning(string $message, array $context = []): void
    {
        Logger::warning('[PhoneUpdater] ' . $message, $context);
    }

    private function logError(string $message, array $context = []): void
    {
        Logger::error('[PhoneUpdater] ' . $message, $context);
    }
}
