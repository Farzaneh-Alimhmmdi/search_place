<?php

/**
 * Divar Search + Contact Info Test
 * 
 * - Search Divar using postlist/w/search API (with fixed pagination)
 * - Fetch phone numbers for found listings using authenticated session
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';

use Src\Http\CurlHttpClient;
use Src\Divar\DivarContactFetcher;

// ============================================================
// CONFIG
// ============================================================

$query = 'ویلا';
$cityId = '1';
$category = 'temporary-rent';
$bbox = [50.5240746, 35.593174, 52.0425568, 35.7663078];
$maxPages = 2;
$maxContactsToFetch = 5;  // Limit contact fetching for testing
$delayMilliseconds = 1000;


// ============================================================
// MAIN
// ============================================================

try {
    echoJson(['status' => 'starting', 'query' => $query, 'city_id' => $cityId, 'category' => $category]);

    // Step 1: Search for listings
    $searchResult = searchDivar($query, $cityId, $category, $bbox, $maxPages, $delayMilliseconds);
    
    $allAds = array_values($searchResult['ads']);
    echoJson([
        'status' => 'search_complete',
        'total_ads' => count($allAds),
        'pages_requested' => $searchResult['pages_requested']
    ]);

    // Step 2: Fetch contact info for first N ads
    $tokensToFetch = array_slice(array_column($allAds, 'token'), 0, $maxContactsToFetch);
    
    echoJson([
        'status' => 'fetching_contacts',
        'tokens_to_fetch' => count($tokensToFetch),
        'tokens' => $tokensToFetch
    ]);

    // Initialize contact fetcher
    $httpClient = new CurlHttpClient();
    $contactFetcher = new DivarContactFetcher($httpClient);
    
    $contactResults = $contactFetcher->getMultipleContactInfo(
        $tokensToFetch,
        'tehran',  // city slug for referer
        $category,
        2000  // 2 second delay between requests
    );

    // Merge contact info with ads
    $adsWithContacts = [];
    foreach ($allAds as $ad) {
        $token = $ad['token'];
        $contactInfo = $contactResults[$token] ?? ['success' => false, 'error' => 'not_fetched'];
        
        $adsWithContacts[] = array_merge($ad, [
            'contact_info' => $contactInfo
        ]);
    }

    echoJson([
        'status' => 'finished',
        'total_ads' => count($adsWithContacts),
        'contacts_fetched' => count(array_filter($contactResults, fn($r) => $r['success'] ?? false)),
        'phones_found' => count(array_filter($contactResults, fn($r) => !empty($r['phone'] ?? null))),
        'ads' => $adsWithContacts
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echoJson([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}


// ============================================================
// SEARCH FUNCTIONS (copied from test_divar.php)
// ============================================================

function searchDivar(
    string $query,
    string $cityId,
    string $category,
    array $bbox,
    int $maxPages,
    int $delayMilliseconds
): array {

    $ads = [];
    $paginationData = null;
    $page = 1;
    $hasNextPage = true;
    $searchUid = null;

    while ($hasNextPage && $page <= $maxPages) {

        if ($page > 1) {
            usleep($delayMilliseconds * 1000);
        }

        $payload = buildSearchPayload($query, $cityId, $category, $bbox, $paginationData);

        echoJson(['status' => 'requesting_page', 'page' => $page]);

        $response = divarRequest($payload);

        $rows = [];
        extractPostRows($response, $rows);

        $newAds = 0;
        foreach ($rows as $row) {
            $token = $row['token'] ?? null;
            if (!$token) continue;
            if (!isset($ads[$token])) {
                $ads[$token] = $row;
                $newAds++;
            }
        }

        $nextPagination = findPaginationData($response);
        if ($nextPagination !== null) {
            $paginationData = filterPaginationForResend($nextPagination);
            if (!empty($paginationData['search_uid'])) {
                $searchUid = $paginationData['search_uid'];
            }
        }

        $foundHasNext = findHasNextPage($response);
        $hasNextPage = $foundHasNext !== null ? $foundHasNext : false;

        echoJson([
            'status' => 'page_finished',
            'page' => $page,
            'rows_found' => count($rows),
            'new_ads' => $newAds,
            'total_unique_ads' => count($ads),
            'has_next_page' => $hasNextPage,
            'search_uid' => $searchUid
        ]);

        $page++;
    }

    return [
        'ads' => $ads,
        'pages_requested' => $page - 1,
        'has_next_page' => $hasNextPage
    ];
}

function buildSearchPayload(string $query, string $cityId, string $category, array $bbox, ?array $paginationData): array
{
    $payload = [
        'source_view' => 'MAP_DISCOVERY_MAP',
        'disable_recommendation' => false,
        'city_ids' => [$cityId],
        'user_selected_location' => ['places' => [['place_id' => $cityId]]],
        'category' => ['value' => $category],
        'sort' => ['sort_option' => 'relevance'],
        'map_view' => [
            'camera' => [
                'min_latitude' => $bbox[1],
                'min_longitude' => $bbox[0],
                'max_latitude' => $bbox[3],
                'max_longitude' => $bbox[2],
                'zoom' => 9.122844107418382
            ],
            'map_free_roaming' => true,
            'place_hash' => $cityId . '||' . $category . '||'
        ],
        'query' => $query
    ];

    if ($paginationData !== null) {
        $payload['pagination_data'] = $paginationData;
    }

    return $payload;
}

function divarRequest(array $payload): array
{
    $url = 'https://api.divar.ir/v8/postlist/w/search';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'content-type: application/json',
            'origin: https://divar.ir',
            'referer: https://divar.ir/',
            'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ],
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_ENCODING => ''
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) throw new RuntimeException('cURL error: ' . $curlError);
    if ($httpCode < 200 || $httpCode >= 300) throw new RuntimeException("Divar returned HTTP {$httpCode}: {$body}");

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) throw new RuntimeException('Divar returned invalid JSON: ' . $body);

    return $decoded;
}

function extractPostRows(mixed $value, array &$rows): void
{
    if (!is_array($value)) return;

    if (isset($value['widget_type']) && $value['widget_type'] === 'POST_ROW' && isset($value['data']) && is_array($value['data'])) {
        $data = $value['data'];
        $token = $data['token'] ?? ($data['action']['payload']['token'] ?? null);
        
        if ($token !== null) {
            $rows[] = [
                'title' => $data['title'] ?? null,
                'url' => 'https://divar.ir/v/' . $token,
                'token' => $token,
                'price' => $data['middle_description_text'] ?? null,
                'location' => $data['bottom_description_text'] ?? null,
                'top_description' => $data['top_description_text'] ?? null,
                'middle_description' => $data['middle_description_text'] ?? null,
                'bottom_description' => $data['bottom_description_text'] ?? null,
                'red_text' => $data['red_text'] ?? null,
                'image_count' => $data['image_count'] ?? null,
                'layout_type' => $data['layout_type'] ?? null,
                'raw' => $data
            ];
        }
    }

    foreach ($value as $child) {
        if (is_array($child)) extractPostRows($child, $rows);
    }
}

function findHasNextPage(mixed $value): ?bool
{
    if (!is_array($value)) return null;
    if (array_key_exists('has_next_page', $value)) {
        $v = $value['has_next_page'];
        if (is_bool($v)) return $v;
        if ($v === 1 || $v === 'true') return true;
        if ($v === 0 || $v === 'false') return false;
    }
    foreach ($value as $child) {
        if (is_array($child)) {
            $result = findHasNextPage($child);
            if ($result !== null) return $result;
        }
    }
    return null;
}

function findPaginationData(mixed $value): ?array
{
    if (!is_array($value)) return null;
    
    $looksLikePagination = isset($value['search_uid']) && isset($value['page']) && isset($value['layer_page']) &&
        (array_key_exists('viewed_tokens', $value) || array_key_exists('filters_hash', $value) || array_key_exists('last_post_date', $value));
    
    if ($looksLikePagination) return $value;
    
    foreach ($value as $child) {
        if (is_array($child)) {
            $result = findPaginationData($child);
            if ($result !== null) return $result;
        }
    }
    return null;
}

function filterPaginationForResend(array $pagination): array
{
    $allowedFields = ['@type', 'search_uid', 'page', 'layer_page', 'cumulative_widgets_count', 'viewed_tokens', 'filters_hash', 'last_post_date', 'first_page_viewed_at'];
    $filtered = [];
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $pagination)) $filtered[$field] = $pagination[$field];
    }
    if (!isset($filtered['viewed_tokens'])) $filtered['viewed_tokens'] = [];
    return $filtered;
}

function echoJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo PHP_SAPI === 'cli' ? PHP_EOL : '<br><br>';
}