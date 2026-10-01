<?php

namespace Src\Divar;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;

/**
 * DivarContactFetcher - Fetches contact info using DivarAuthManager for authentication.
 */
final class DivarContactFetcher
{
    private CurlHttpClient $http;
    private DivarAuthManager $authManager;

    public function __construct(
        CurlHttpClient $http,
        DivarAuthManager $authManager
    ) {
        $this->http = $http;
        $this->authManager = $authManager;
    }

    /**
     * Get contact info (phone number) for a post token.
     */
    public function getContactInfo(string $token, string $citySlug = 'tehran', string $category = 'rent-temporary'): array
    {
        // Ensure we have valid auth
        $authResult = $this->authManager->getValidAuth();
        
        if (!$authResult['success']) {
            return ['success' => false, 'error' => 'Auth failed: ' . ($authResult['error'] ?? 'unknown')];
        }

        $url = "https://api.divar.ir/v8/postcontact/web/contact_info_v2/{$token}";
        $trackerSessionId = $this->generateTrackerSessionId($token);

        $postData = json_encode([
            'contact_uuid' => $this->generateContactUuid(),
            'tracker_session_id' => $trackerSessionId
        ]);

        $headers = $this->buildContactHeaders($citySlug, $category, $trackerSessionId, $authResult);

        $result = $this->http->postWithHeaders($url, $postData, $headers);

        if (!$result['success']) {
            Logger::error('Contact info request failed', ['error' => $result['error'], 'token' => $token]);
            return ['success' => false, 'error' => $result['error']];
        }

        $data = $result['data'] ?? [];
        
        // Check for auth errors (token expired)
        if (isset($data['error']) || ($result['http_code'] ?? 0) === 401) {
            Logger::warning('Auth expired, clearing cache and retrying', ['token' => $token]);
            $this->authManager->clearAuth();
            
            // Retry once with fresh auth
            $authResult = $this->authManager->getValidAuth();
            if ($authResult['success']) {
                return $this->getContactInfo($token, $citySlug, $category);
            }
            return ['success' => false, 'error' => 'Authentication expired'];
        }

        $phone = DivarPhoneParser::parse(is_array($data) ? $data : []);

        return [
            'success' => true,
            'phone' => $phone,
            'has_chat' => $data['has_chat'] ?? false,
            'show_contact_button' => $data['show_contact_button'] ?? false,
            'contact_count' => $data['contact_count'] ?? 0,
            'raw' => $data
        ];
    }

    /**
     * Get contact info for multiple tokens (with rate limiting).
     */
    public function getMultipleContactInfo(array $tokens, string $citySlug, string $category, int $delayMs = 2000): array
    {
        $results = [];
        
        Logger::info('Starting batch contact fetch', ['count' => count($tokens), 'tokens' => $tokens]);
        
        foreach ($tokens as $index => $token) {
            if ($index > 0) {
                Logger::info('Waiting before next request', ['delay_ms' => $delayMs]);
                usleep($delayMs * 1000);
            }
            
            Logger::info('Fetching contact for token', ['token' => $token, 'index' => $index + 1, 'total' => count($tokens)]);
            
            try {
                $results[$token] = $this->getContactInfo($token, $citySlug, $category);
                
                Logger::info('Contact info fetched', [
                    'token' => $token,
                    'success' => $results[$token]['success'] ?? false,
                    'has_phone' => !empty($results[$token]['phone'] ?? null),
                    'error' => $results[$token]['error'] ?? null
                ]);
            } catch (\Throwable $e) {
                Logger::error('Contact fetch exception', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                $results[$token] = ['success' => false, 'error' => 'Exception: ' . $e->getMessage()];
            }
        }
        
        return $results;
    }

    /**
     * Build headers for contact API request.
     */
    private function buildContactHeaders(string $citySlug, string $category, string $trackerSessionId, array $authData): array
    {
        $cookies = $authData['cookies'] ?? [];
        
        $importantCookies = [
            'did', 'cdid', 'city', 'theme', 'multi-city',
            'sAccessToken', 'sFrontToken', 'csid', '_vid_t', 'player_id',
            '_gcl_au', '_ga', '_ga_1G1K17N77F', 'resolution_width', 'referrer', 'ff'
        ];
        
        $cookieParts = [];
        foreach ($importantCookies as $name) {
            if (isset($cookies[$name])) {
                $cookieParts[] = "{$name}={$cookies[$name]}";
            }
        }
        
        $cookieString = implode('; ', $cookieParts);
        
        return [
            'accept' => 'application/json, text/plain, */*',
            'accept-language' => 'fa,en;q=0.9',
            'content-type' => 'application/json',
            'origin' => 'https://divar.ir',
            'referer' => "https://divar.ir/s/{$citySlug}/{$category}",
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'x-requested-with' => 'XMLHttpRequest',
            'cookie' => $cookieString,
            'sec-ch-ua' => '"Google Chrome";v="120", "Not_A Brand";v="8", "Chromium";v="120"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
            'sec-fetch-dest' => 'empty',
            'sec-fetch-mode' => 'cors',
            'sec-fetch-site' => 'same-site',
        ];
    }

    /**
     * Generate a contact UUID (random UUID v4).
     */
    private function generateContactUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0x8000, 0x8fff),
            mt_rand(0x8000, 0xbfff),
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Generate tracker session ID.
     */
    private function generateTrackerSessionId(string $token): string
    {
        $uuid = $this->generateContactUuid();
        return "{$uuid}_{$token}_P";
    }
}