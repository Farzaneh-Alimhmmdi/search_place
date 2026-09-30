<?php

namespace Src\Divar;

use Src\Http\CurlHttpClient;
use Src\Support\Config;
use Src\Support\Logger;
use Throwable;

/**
 * DivarContactFetcher - Fetches contact info (phone number) from Divar API.
 * Supports authentication via DivarCookieManager or DivarAuthManager.
 */
final class DivarContactFetcher
{
    private CurlHttpClient $http;
    private ?DivarAuthManager $authManager;
    private ?array $customCookies;

    public function __construct(
        ?CurlHttpClient $http = null,
        ?DivarAuthManager $authManager = null,
        ?array $customCookies = null
    ) {
        $this->http = $http ?? new CurlHttpClient();
        $this->authManager = $authManager;
        $this->customCookies = $customCookies;
    }

    /**
     * Set explicit cookies to use for requests.
     *
     * @param array $cookies
     */
    public function setCookies(array $cookies): void
    {
        $this->customCookies = $cookies;
    }

    /**
     * Get contact info (phone number) for a Divar post token.
     *
     * @param string $token Divar post token (external_id)
     * @param string $citySlug Divar city slug (e.g. 'tehran')
     * @param string $category Divar category slug (e.g. 'temporary-rent')
     * @return array{
     *     success: bool,
     *     phone: string|null,
     *     error: string|null,
     *     message: string|null,
     *     http_code: int,
     *     has_chat: bool,
     *     raw: array|null
     * }
     */
    public function getContactInfo(
        string $token,
        string $citySlug = 'tehran',
        string $category = 'temporary-rent'
    ): array {
        // Resolve authentication cookies
        $cookies = $this->resolveCookies();

        if (empty($cookies)) {
            return [
                'success' => false,
                'phone' => null,
                'error' => 'auth_missing',
                'message' => 'اطلاعات ورود (کوکی‌های دیوار) موجود نیست. لطفا ابتدا وارد شوید.',
                'http_code' => 401,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        $url = "https://api.divar.ir/v8/postcontact/web/contact_info_v2/{$token}";
        $trackerSessionId = $this->generateTrackerSessionId($token);

        $postData = json_encode([
            'contact_uuid' => $this->generateContactUuid(),
            'tracker_session_id' => $trackerSessionId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $headers = $this->buildContactHeaders($citySlug, $category, $cookies);

        // Execute cURL request directly for complete control over HTTP status codes
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 1. cURL / Network failure
        if ($response === false || $curlError !== '') {
            Logger::error('Divar contact cURL error', ['token' => $token, 'error' => $curlError]);
            return [
                'success' => false,
                'phone' => null,
                'error' => 'network_error',
                'message' => 'خطا در ارتباط شبکه با دیوار: ' . $curlError,
                'http_code' => 0,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 2. Authentication failure (401 or 403)
        if ($httpCode === 401 || $httpCode === 403) {
            Logger::warning('Divar contact auth expired', ['token' => $token, 'http_code' => $httpCode]);

            if ($this->authManager !== null) {
                $this->authManager->clearAuth();
            }

            return [
                'success' => false,
                'phone' => null,
                'error' => 'auth_expired',
                'message' => 'اعتبار نشست دیوار منقضی شده است. لطفا مجددا وارد شوید.',
                'http_code' => $httpCode,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 3. Rate limited (429 Too Many Requests)
        if ($httpCode === 429) {
            Logger::warning('Divar contact rate limited (429)', ['token' => $token]);
            return [
                'success' => false,
                'phone' => null,
                'error' => 'rate_limited',
                'message' => 'محدودیت تعداد درخواست دیوار رخ داد (HTTP 429 Too Many Requests).',
                'http_code' => 429,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 4. Listing not found or expired (404)
        if ($httpCode === 404) {
            Logger::info('Divar listing not found or expired (404)', ['token' => $token]);
            return [
                'success' => false,
                'phone' => null,
                'error' => 'not_found',
                'message' => 'آگهی در دیوار یافت نشد یا منقضی شده است.',
                'http_code' => 404,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 5. Other HTTP server error
        if ($httpCode < 200 || $httpCode >= 300) {
            Logger::error('Divar contact HTTP error', ['token' => $token, 'http_code' => $httpCode]);
            return [
                'success' => false,
                'phone' => null,
                'error' => 'http_error',
                'message' => 'سرور دیوار کد خطای HTTP ' . $httpCode . ' بازگرداند.',
                'http_code' => $httpCode,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 6. Parse JSON response
        $data = json_decode($response, true);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            Logger::error('Divar contact invalid JSON', ['token' => $token, 'response' => substr($response, 0, 500)]);
            return [
                'success' => false,
                'phone' => null,
                'error' => 'invalid_json',
                'message' => 'پاسخ دریافتی از دیوار معتبر نیست.',
                'http_code' => $httpCode,
                'has_chat' => false,
                'raw' => null,
            ];
        }

        // 7. Extract and normalize phone number
        $rawPhone = $this->extractPhoneFromResponse($data);
        $normalizedPhone = $rawPhone !== null ? $this->normalizePhoneNumber($rawPhone) : null;
        $hasChat = !empty($data['has_chat']);

        if ($normalizedPhone !== null && $normalizedPhone !== '') {
            return [
                'success' => true,
                'phone' => $normalizedPhone,
                'error' => null,
                'message' => 'شماره تماس با موفقیت دریافت شد.',
                'http_code' => 200,
                'has_chat' => $hasChat,
                'raw' => $data,
            ];
        }

        // 8. No phone in widgets (chat only or seller chose not to provide phone)
        return [
            'success' => false,
            'phone' => null,
            'error' => 'no_phone',
            'message' => 'آگهی فاقد شماره تماس است (فقط چت یا شماره مخفی).',
            'http_code' => 200,
            'has_chat' => $hasChat,
            'raw' => $data,
        ];
    }

    /**
     * Get contact info for multiple tokens with delay and error resilience.
     */
    public function getMultipleContactInfo(
        array $tokens,
        string $citySlug = 'tehran',
        string $category = 'temporary-rent',
        int $delayMs = 2000
    ): array {
        $results = [];

        foreach ($tokens as $index => $token) {
            if ($index > 0 && $delayMs > 0) {
                usleep($delayMs * 1000);
            }

            try {
                $results[$token] = $this->getContactInfo($token, $citySlug, $category);
            } catch (Throwable $e) {
                Logger::error('Contact fetch exception', [
                    'token' => $token,
                    'error' => $e->getMessage(),
                ]);
                $results[$token] = [
                    'success' => false,
                    'phone' => null,
                    'error' => 'exception',
                    'message' => $e->getMessage(),
                    'http_code' => 0,
                    'has_chat' => false,
                    'raw' => null,
                ];
            }
        }

        return $results;
    }

    /**
     * Resolve active cookies from custom, authManager, or DivarCookieManager.
     */
    private function resolveCookies(): array
    {
        if (!empty($this->customCookies)) {
            return $this->customCookies;
        }

        $managerCookies = DivarCookieManager::getCookies();
        if (!empty($managerCookies)) {
            return $managerCookies;
        }

        if ($this->authManager !== null) {
            $authResult = $this->authManager->getValidAuth();
            if ($authResult['success']) {
                return $authResult['cookies'] ?? [];
            }
        }

        return [];
    }

    /**
     * Extract phone number from Divar's widget_list response.
     */
    public function extractPhoneFromResponse(array $data): ?string
    {
        $widgetList = $data['widget_list'] ?? [];

        foreach ($widgetList as $widget) {
            $widgetType = $widget['widget_type'] ?? '';
            $widgetData = $widget['data'] ?? [];

            if ($widgetType === 'UNEXPANDABLE_ROW') {
                // 1. Direct phone in action payload
                if (isset($widgetData['action']['payload']['phone_number'])) {
                    return (string) $widgetData['action']['payload']['phone_number'];
                }

                // 2. Value when title matches "شمارهٔ موبایل" or similar
                $title = (string) ($widgetData['title'] ?? '');
                if (
                    str_contains($title, 'موبایل') ||
                    str_contains($title, 'تلفن') ||
                    str_contains($title, 'تماس')
                ) {
                    if (isset($widgetData['value'])) {
                        return (string) $widgetData['value'];
                    }
                }

                // 3. Fallback to any numeric value
                if (isset($widgetData['value']) && is_string($widgetData['value'])) {
                    $cleaned = $this->normalizePhoneNumber($widgetData['value']);
                    if ($cleaned !== null) {
                        return $cleaned;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Normalize Iranian phone number to standard format (e.g. 09123456789).
     *
     * - Converts Persian/Arabic digits to English digits
     * - Converts +98 or 0098 prefix to 0
     * - Strips spaces, dashes, parentheses
     */
    public function normalizePhoneNumber(string $phone): ?string
    {
        $persianDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabicDigits  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];

        $phone = str_replace($persianDigits, $englishDigits, $phone);
        $phone = str_replace($arabicDigits, $englishDigits, $phone);

        // Strip non-digit characters except leading plus
        $phone = trim($phone);
        if (str_starts_with($phone, '+98')) {
            $phone = '0' . substr($phone, 3);
        } elseif (str_starts_with($phone, '0098')) {
            $phone = '0' . substr($phone, 4);
        }

        $phone = preg_replace('/[^\d]/', '', $phone);

        if ($phone === null || $phone === '') {
            return null;
        }

        // If 10 digits starting with 9, prepend 0 (e.g. 9123456789 -> 09123456789)
        if (strlen($phone) === 10 && str_starts_with($phone, '9')) {
            $phone = '0' . $phone;
        }

        // Valid Iranian phone numbers are 10-11 digits (mobile: 09..., landline: 0XX...)
        if (strlen($phone) >= 10 && strlen($phone) <= 12 && str_starts_with($phone, '0')) {
            return $phone;
        }

        return null;
    }

    /**
     * Build HTTP headers for Divar contact API request.
     */
    private function buildContactHeaders(string $citySlug, string $category, array $cookies): array
    {
        $cookieParts = [];
        foreach ($cookies as $name => $value) {
            $cookieParts[] = "{$name}={$value}";
        }
        $cookieString = implode('; ', $cookieParts);

        $userAgent = Config::get(
            'user_agent',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        );

        return [
            'accept: application/json, text/plain, */*',
            'accept-language: fa,en;q=0.9',
            'cache-control: no-cache',
            'content-type: application/json',
            'origin: https://divar.ir',
            'pragma: no-cache',
            'referer: https://divar.ir/',
            'user-agent: ' . $userAgent,
            'x-render-type: CSR',
            'x-screen-size: 1920x1080',
            'x-web-serving-mode: desktop',
            'cookie: ' . $cookieString,
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
     * Generate tracker session ID for Divar API.
     */
    private function generateTrackerSessionId(string $token): string
    {
        $uuid = $this->generateContactUuid();
        return "{$uuid}_{$token}_P";
    }
}
