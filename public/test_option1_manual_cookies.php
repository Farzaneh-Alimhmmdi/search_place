<?php

/**
 * Option 1: Manual Cookie Import Test (using DivarAuthManager)
 * 
 * Paste your Divar cookies from browser DevTools here to test contact fetching.
 * 
 * How to get cookies:
 * 1. Login to divar.ir in Chrome
 * 2. Open DevTools (F12) → Application → Cookies → https://divar.ir
 * 3. Copy these cookie values: sAccessToken, sFrontToken, did, cdid, city, theme
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';

use Src\Http\CurlHttpClient;
use Src\Divar\DivarAuthManager;
use Src\Divar\DivarContactFetcher;

// ============================================================
// PASTE YOUR COOKIES HERE (from browser DevTools)
// ============================================================

$manualCookies = [
    'sAccessToken' => 'eyJraWQiOiJkLTE3OTA0MzMyMTAyNTgiLCJ0eXAiOiJKV1QiLCJ2ZXJzaW9uIjoiNCIsImFsZyI6IlJTMjU2In0.eyJpYXQiOjE3OTA1ODkwNjcsImV4cCI6MTc5MDU5OTY2Niwic3ViIjoiNGE1ODY2M2ItZmI0Yi00YzNmLWFlOTctMDEzYjhjNzNhYmZlIiwidElkIjoicHVibGljIiwic2Vzc2lvbkhhbmRsZSI6ImI4NjI5ZWUzLTQ0NmItNDIxOC1iNTAwLTQ1YjlkZWZlOGJjNyIsInJlZnJlc2hUb2tlbkhhc2gxIjoiY2YxMzdlMTQxNzZhNjg1MzJmNGYyNThkODdiNmFkY2RkOThlOTVjMzVlOWI4MDEwZWU2NDk2MDY3ODhhY2VkNiIsInBhcmVudFJlZnJlc2hUb2tlbkhhc2gxIjpudWxsLCJhbnRpQ3NyZlRva2VuIjpudWxsLCJpc3MiOiJodHRwczovL2FwaS5kaXZhci5pci92OC9hdXRoZW50aWNhdGUiLCJwaG9uZU51bWJlciI6Iis5ODkwMTczMDE1NDAiLCJzdC1wZXJtIjp7InQiOjE3OTA1ODkwNjc5MDMsInYiOltdfSwic3Qtcm9sZSI6eyJ0IjoxNzkwNTg5MDY3OTAzLCJ2IjpbXX19.dLLlLivGGkneiZr3Kx2IZOSfEyJ6LRXfYC80t8DTxO9CpWmkwjmlBdkEuYPH89Yt3zEt9ZF2AfuBFs4oo-bDQKPBlYnb6qp42DvrXtPSAfwucLLvy_ZMXkDRSxAQt684G2Cu0kr-y9jZdgIWJ7IUVqhyGyBsFw2y9--UCsoM_OSaxuM89SdVnCkBn57XKOrw0KXqRqrEx5KQFZN8Pk7qACjrEytuY_yCVq22rXQIsXrRv3IQs3NuNQvq0CnBVbsIAd32_mDJqbDGAdEkFVUK72oI0MV1PIpa5cTKJHV_b5m8lQuhZKfIU9CreqsTj2cXYZR7neBY3H-vYSfv8Jw4Mg',
    'sFrontToken' => 'eyJ1aWQiOiI0YTU4NjYzYi1mYjRiLTRjM2YtYWU5Ny0wMTNiOGM3M2FiZmUiLCJhdGUiOjE3OTA1OTk2NjYwMDAsInVwIjp7ImFudGlDc3JmVG9rZW4iOm51bGwsImV4cCI6MTc5MDU5OTY2NiwiaWF0IjoxNzkwNTg5MDY3LCJpc3MiOiJodHRwczovL2FwaS5kaXZhci5pci92OC9hdXRoZW50aWNhdGUiLCJwYXJlbnRSZWZyZXNoVG9rZW5IYXNoMSI6bnVsbCwicGhvbmVOdW1iZXIiOiIrOTg5MDE3MzAxNTQwIiwicmVmcmVzaFRva2VuSGFzaDEiOiJjZjEzN2UxNDE3NmE2ODUzMmY0ZjI1OGQ4N2I2YWRjZGQ5OGU5NWMzNWU5YjgwMTBlZTY0OTYwNjc4OGFjZWQ2Iiwic2Vzc2lvbkhhbmRsZSI6ImI4NjI5ZWUzLTQ0NmItNDIxOC1iNTAwLTQ1YjlkZWZlOGJjNyIsInN0LXBlcm0iOnsidCI6MTc5MDU4OTA2NzkwMywidiI6W119LCJzdC1yb2xlIjp7InQiOjE3OTA1ODkwNjc5MDMsInYiOltdfSwic3ViIjoiNGE1ODY2M2ItZmI0Yi00YzNmLWFlOTctMDEzYjhjNzNhYmZlIiwidElkIjoicHVibGljIn19',
    'did' => '3ffb298c-fe0c-4000-94bc-6c0dc741f418',
    'cdid' => 'cf72990c-d626-4ee7-8ef0-dbc0c13aa16a',
    'city' => 'tehran',
    'theme' => 'dark',
    'multi-city' => 'tehran%7C',
    '_gcl_au' => '1.1.1547057867.1790495217',
    '_ga' => 'GA1.1.1268639324.1790495217',
    '_ga_1G1K17N77F' => 'GS2.1.s1790594967$o9$g1$t1790594969$j58$l0$h0',
    'csid' => '7f1915f78aa66d4b1c',
    '_vid_t' => 'fZNtVoU3a3QZEcBJtLHV1/vu1mASAZSy5QHIXgYO8ypq310SdNsm1UfpvIEQ5AWlWtFBqW46zZXqRw==',
    'player_id' => 'feb61e07-353c-4848-bfb2-e36205bff945',
    'resolution_width' => '1920',
    'referrer' => '',
    'ff' => '{"f":{"device_fp_enable":true,"enable-places-selector-online-search-web":true,"chat_message_disabled":true,"web_sentry_sample_rate":0.2,"web_sentry_traces_sample_rate":0.01,"is_web_proactive_refresh_enabled":true,"post-stats-batch-event-web-max-batch-size":"20","post-stats-batch-event-web-flush-interval-sec":"20","divar_default_call_center":"neda","web_client_exporter_page_load_sample_rate":0.5},"e":1790592404583,"r":1790675204583}',
];

// Test token (from your curl)
$testToken = 'Aa6FGD3Z';
$citySlug = 'tehran';
$category = 'rent-temporary';

// ============================================================
// TEST
// ============================================================

try {
    echoJson(['status' => 'starting', 'test_token' => $testToken]);

    // Check if cookies are provided
    $hasAuth = !empty($manualCookies['sAccessToken']) && !empty($manualCookies['sFrontToken']);
    
    if (!$hasAuth) {
        echoJson([
            'status' => 'error',
            'message' => 'No cookies provided. Please edit this file and paste your cookies from browser DevTools.',
            'instructions' => [
                '1. Login to divar.ir in Chrome',
                '2. Open DevTools (F12) → Application → Cookies → https://divar.ir',
                '3. Copy: sAccessToken, sFrontToken, did, cdid, city, theme',
                '4. Paste them in the $manualCookies array above',
                '5. Run this script again'
            ]
        ]);
        exit;
    }

    // Initialize Auth Manager with manual cookies (no login needed)
    $httpClient = new CurlHttpClient();
    
    $authManager = new DivarAuthManager(
        $httpClient,
        '09xxxxxxxxx', // dummy phone, won't be used with manual cookies
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    );
    
    // Inject manual cookies
    $authManager->setManualCookies($manualCookies);

    // Create Contact Fetcher
    $contactFetcher = new DivarContactFetcher($httpClient, $authManager);

    // Fetch contact info
    echoJson(['status' => 'fetching_contact', 'token' => $testToken]);
    $result = $contactFetcher->getContactInfo($testToken, $citySlug, $category);

    echoJson([
        'status' => 'finished',
        'result' => $result
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echoJson([
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
}

function echoJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo PHP_SAPI === 'cli' ? PHP_EOL : '<br><br>';
}