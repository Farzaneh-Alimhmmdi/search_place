<?php

/**
 * Option 2: Automated Login Flow Test
 * 
 * This test uses headless Chrome to:
 * 1. Navigate to Divar login page
 * 2. Enter phone number
 * 3. Wait for SMS OTP
 * 4. Submit OTP
 * 5. Fetch contact info using authenticated session
 * 
 * Requirements:
 * - Chrome installed at C:\Program Files\Google\Chrome\Application\chrome.exe
 * - Valid Iranian phone number registered on Divar
 * - Access to SMS/OTP for that number
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
// CONFIG - CHANGE THESE
// ============================================================

$phoneNumber = '09xxxxxxxxx';  // YOUR DIVAR REGISTERED PHONE NUMBER (e.g., 09123456789)
$testToken = 'gaVIGcy9';       // Token to test contact fetching (from search results)
$citySlug = 'tehran';
$category = 'rent-temporary';

// Chrome path (adjust if different)
$chromeBinary = 'C:\Program Files\Google\Chrome\Application\chrome.exe';

// ============================================================
// TEST
// ============================================================

try {
    echoJson(['status' => 'starting', 'phone' => maskPhone($phoneNumber), 'test_token' => $testToken]);

    if ($phoneNumber === '09xxxxxxxxx') {
        echoJson([
            'status' => 'error',
            'message' => 'Please edit this file and set your phone number in $phoneNumber variable',
            'instructions' => [
                '1. Set $phoneNumber to your Divar-registered phone (e.g., 09123456789)',
                '2. Make sure you can receive SMS on that number',
                '3. Run this script again'
            ]
        ]);
        exit;
    }

    // Initialize
    $httpClient = new CurlHttpClient();
    
    // Create Auth Manager with OTP callback
    $authManager = new DivarAuthManager(
        $httpClient,
        $phoneNumber,
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        $chromeBinary
    );

    // Set OTP callback - this will be called when OTP is needed
    $authManager->setOtpCallback(function() {
        echo "\n📱 SMS sent to your phone. Enter the OTP code here: ";
        $otp = trim(fgets(STDIN));
        return $otp;
    });

    // Create Contact Fetcher
    $contactFetcher = new DivarContactFetcher($httpClient, $authManager);

    // Test contact fetching (will trigger login if needed)
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

function maskPhone(string $phone): string
{
    if (strlen($phone) >= 8) {
        return substr($phone, 0, 4) . '****' . substr($phone, -4);
    }
    return '****';
}

function echoJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    echo PHP_SAPI === 'cli' ? PHP_EOL : '<br><br>';
}