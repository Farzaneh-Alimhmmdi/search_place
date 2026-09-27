<?php

return [
    'app_name' => 'Raah V4 Search',
    'base_url' => 'https://search.raah.ir/v4/placeslist/cat/',
    'preview_bulk_url' => 'https://poi.raah.ir/web/v4/preview-bulk/',
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_retries' => 3,
    'request_delay' => 1,
    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
    'city_slugs' => require __DIR__ . '/provinces.php',
    'neshan_python' => 'python',  // Path to Python executable
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];
