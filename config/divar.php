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
    'cities_file' => __DIR__ . '/divar/cities.json',
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

    // Database config (shared)
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];
