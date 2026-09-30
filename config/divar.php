<?php

return [
    // Divar configuration (scraping, no API key needed)
    'endpoint' => 'https://divar.ir/s',
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_retries' => 3,
    'request_delay' => 1,
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    // Categories for Divar (rent related)
    'categories' => [
        ['value' => 'temporary-rent', 'label' => 'اجاره روزانه/موقت'],
    ],
    'city_slugs' => require __DIR__ . '/provinces.php',
    'cities_file' => __DIR__ . '/cities.json',
    'log_path' => 'storage/logs',

    /*
     * ---------------------------------------------------------------
     * Collection settings (route: /divar_collect)
     * ---------------------------------------------------------------
     *
     * The collector stores EVERY ad of a search in the database and shows
     * no pagination at all. Because such a search can contain thousands of
     * ads, the work is split into small steps:
     *
     *   1 browser request = 1 Divar page (page_limit ads) = 1 DB batch
     *
     * That keeps PHP far away from max_execution_time / memory_limit and
     * makes the run interruptible and resumable at any moment.
     */
    'collect' => [
        // Ads fetched per Divar request (Divar itself returns ~24 per page).
        'page_limit' => 24,

        // Pause between two steps, in milliseconds. Keeps Divar calm.
        'step_delay_ms' => 800,

        // Hard cap of steps per run. 0 = unlimited (collect everything).
        'max_steps' => 0,

        // Browser side retries for one failed step before giving up.
        'max_retries' => 6,

        // Backoff after a failed step: retry_base_ms * attempt number.
        'retry_base_ms' => 2000,

        // Store the complete raw Divar payload in accommodations.raw_data.
        'store_raw' => true,

        // Stop when Divar keeps returning empty pages (infinite loop guard).
        'max_empty_pages' => 30,

        // Job log lines kept in the PHP session.
        'log_tail' => 8,
    ],

    /*
     * ---------------------------------------------------------------
     * Phone updater cronjob settings
     * ---------------------------------------------------------------
     *
     * Divar limitations:
     *  - Divar aggressively rate limits phone number reveal requests.
     *  - If requests are sent too fast, Divar returns HTTP 429 or blocks the IP.
     *  - Daily limit: Divar allows a limited number of contact reveals per day
     *    per account (typically 50-100).
     *  - Auth requirement: Divar requires active session cookies
     *    (sAccessToken, sFrontToken, did, cdid).
     */
    'phone_updater' => [
        // Number of ads without phone to process in one cron execution
        'batch_size' => 20,

        // Base delay between consecutive Divar requests in milliseconds (3000ms = 3s)
        'request_delay_ms' => 3000,

        // Random jitter added to delay (in ms) to avoid predictable bot patterns
        'jitter_min_ms' => 500,
        'jitter_max_ms' => 1500,

        // Maximum requests allowed in a 24-hour period (daily safety quota)
        'max_daily_requests' => 100,

        // Maximum consecutive errors before terminating the run early
        'max_consecutive_errors' => 3,

        // Cooldown period on HTTP 429 Too Many Requests (in seconds, 1800s = 30min)
        'rate_limit_cooldown_seconds' => 1800,

        // File where lock is held so multiple cron runs do not overlap
        'lock_file' => 'storage/phone_updater.lock',

        // File where cookies are stored for CLI/cron environment
        'cookie_file' => 'storage/divar_cookies.json',

        // State file to track daily request counts and rate limits
        'state_file' => 'storage/phone_updater_state.json',

        // Log file path for phone updater
        'log_file' => 'storage/logs/phone_updater.log',

        // Max retry attempts per ad before marking it permanently failed
        'max_attempts' => 3,

        // Divar contact API endpoint
        'contact_endpoint' => 'https://api.divar.ir/v8/postcontact/web/contact_info_v2/',
    ],

    // Database config (shared)
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];
