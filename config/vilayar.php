<?php

return [
    // Vilayar configuration (plain HTML scraping, no API key needed)
    'endpoint' => 'https://vilayar.com',
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_retries' => 3,
    'request_delay' => 1,
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    // Vilayar's own villa types (used instead of the shared categories)
    'categories' => [
        ['value' => '1', 'label' => 'جنگلی'],
        ['value' => '5', 'label' => 'شهرکی'],
        ['value' => '6', 'label' => 'کوهستانی'],
        ['value' => '7', 'label' => 'استخردار'],
        ['value' => '8', 'label' => 'چسبیده به دریا'],
        ['value' => '9', 'label' => 'شهری'],
        ['value' => '10', 'label' => 'روستایی'],
        ['value' => '2', 'label' => 'ساحلی'],
        ['value' => '3', 'label' => 'ییلاقی'],
        ['value' => '11', 'label' => 'اجاره سال'],
    ],
    // Persian province name -> Vilayar numeric state id
    'states_file' => __DIR__ . '/vilayar/states.json',
    // Cards per list page (observed: ~40)
    'per_page' => 40,
    // Polite pause between per-card detail fetches (phones live on detail pages)
    'detail_delay_ms' => 200,
    // Shorter budget for a single detail fetch so one slow card cannot stall the page
    'detail_timeout' => 15,
    'log_path' => 'storage/logs',

    // Database config (shared)
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];
