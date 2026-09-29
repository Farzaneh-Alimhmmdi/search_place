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
    // Database config (shared)
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];