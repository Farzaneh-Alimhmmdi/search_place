<?php

return [
    // Makanchi configuration (plain HTML scraping, no API key needed)
    'endpoint' => 'https://makanchi.com',
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_retries' => 3,
    'request_delay' => 1,
    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    // Makanchi's own accommodation types (used instead of the shared categories)
    'categories' => [
        ['value' => 'villa', 'label' => 'ویلا'],
        ['value' => 'apartments', 'label' => 'آپارتمان'],
        ['value' => 'studio', 'label' => 'سوئیت'],
        ['value' => 'rural-house', 'label' => 'خانه روستایی'],
        ['value' => 'cottage', 'label' => 'کلبه'],
        ['value' => 'ecolodge', 'label' => 'بوم‌گردی'],
        ['value' => 'apartmenthotel', 'label' => 'هتل آپارتمان'],
        ['value' => 'traditionalhouse', 'label' => 'هتل سنتی'],
        ['value' => 'complex', 'label' => 'مجتمع اقامتی'],
        ['value' => 'hostel', 'label' => 'مهمانپذیر'],
        ['value' => 'traditional', 'label' => 'خانه سنتی'],
    ],
    // Cards per list page (observed: 30)
    'per_page' => 30,
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
