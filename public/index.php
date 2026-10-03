<?php

require_once __DIR__ . '/../vendor/autoload.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$basePath = '/my_search_place/search_place/public';

/*
 * Strip the deployment folder from the requested path.
 *
 * When the project is mounted somewhere else the path is used as it is, so the
 * router keeps working in both cases.
 */
if (strncmp((string) $requestPath, $basePath, strlen($basePath)) === 0) {
    $route = substr((string) $requestPath, strlen($basePath));
} else {
    $route = (string) $requestPath;
}

// Tolerate a trailing slash: "/divar_collect/" is the same route.
if ($route !== '/') {
    $route = rtrim($route, '/');
}

// Determine provider from POST or default to balad
$provider = $_POST['provider'] ?? 'balad';

if ($route === '/divar_collect') {
    /*
     * Divar "collect and store" page:
     * same selection as the search page, but every ad is written to the
     * database instead of being displayed with pagination.
     */
    require_once __DIR__ . '/../src/Controller/DivarCollectController.php';
} elseif ($route === '/search_place' || $route === '/' || $route === '') {
    if ($provider === 'neshan') {
        require_once __DIR__ . '/../src/Controller/NeshanController.php';
    } elseif ($provider === 'makanchi') {
        require_once __DIR__ . '/../src/Controller/MakanchiController.php';
    } elseif ($provider === 'vilayar') {
        require_once __DIR__ . '/../src/Controller/VilayarController.php';
    } elseif ($provider === 'google_map') {
        require_once __DIR__ . '/../src/Controller/GoogleMapController.php';
    } elseif ($provider === 'divar') {
        require_once __DIR__ . '/../src/Controller/DivarController.php';
    } else {
        require_once __DIR__ . '/../src/Controller/BaladController.php';
    }
} else {
    http_response_code(404);
    echo 'صفحه‌ای که دنبال آن بودید پیدا نشد.';
}
