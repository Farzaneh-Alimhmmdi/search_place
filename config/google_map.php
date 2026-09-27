<?php

return [
    // Google Places Text Search configuration (requires API key)
    'endpoint' => 'https://maps.googleapis.com/maps/api/place/textsearch/json',
    'language' => 'fa', // Persian language
    'region' => 'ir', // Iran region (used as 'region' parameter)
    'timeout' => 30,
    'connect_timeout' => 10,
    'max_retries' => 3,
    'request_delay' => 1,
    'city_slugs' => require __DIR__ . '/provinces.php',
    // Database config (shared)
    'db_host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'db_port' => $_ENV['DB_PORT'] ?? 3306,
    'db_database' => $_ENV['DB_DATABASE'] ?? 'search_place',
    'db_username' => $_ENV['DB_USERNAME'] ?? 'root',
    'db_password' => $_ENV['DB_PASSWORD'] ?? '',
];