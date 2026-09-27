<?php

require_once __DIR__ . '/../vendor/autoload.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$basePath = '/my_search_place/search_place/public';

$route = substr($requestPath, strlen($basePath));

// Determine provider from POST or default to balad
$provider = $_POST['provider'] ?? 'balad';

if ($route === '/search_place' || $route === '/') {
    if ($provider === 'neshan') {
        require_once __DIR__ . '/../src/Controller/NeshanController.php';
    } else {
        require_once __DIR__ . '/../src/Controller/BaladController.php';
    }
} else {
    http_response_code(404);
    echo 'You are here';
}