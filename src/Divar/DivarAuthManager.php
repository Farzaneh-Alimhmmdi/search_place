<?php

namespace Src\Divar;

use Src\Http\CurlHttpClient;
use Src\Support\Logger;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

/**
 * DivarAuthManager - Handles automated login and session management for Divar.
 * 
 * Flow:
 * 1. Navigate to login page
 * 2. Enter phone number
 * 3. Wait for OTP (SMS)
 * 4. Submit OTP
 * 5. Extract and cache tokens/cookies
 * 6. Auto-refresh when tokens expire
 */
final class DivarAuthManager
{
    private CurlHttpClient $http;
    private string $userAgent;
    private string $chromeBinary;
    private array $chromeArgs;
    private string $phoneNumber;
    
    // Session data
    private ?array $authCookies = null;
    private ?string $sAccessToken = null;
    private ?string $sFrontToken = null;
    private ?int $tokenExpiry = null;
    private ?string $sessionId = null;
    
    // Callbacks for OTP handling
    private mixed $otpCallback = null;

    public function __construct(
        CurlHttpClient $http,
        string $phoneNumber,
        string $userAgent = '',
        string $chromeBinary = 'C:\Program Files\Google\Chrome\Application\chrome.exe',
        array $chromeArgs = []
    ) {
        $this->http = $http;
        $this->phoneNumber = $phoneNumber;
        $this->userAgent = $userAgent ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $this->chromeBinary = $chromeBinary;
        $this->chromeArgs = $chromeArgs ?: ['--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--window-size=1920,1080'];
    }

    /**
     * Set callback for OTP retrieval.
     * Callback should return the OTP code as string.
     * Example: $auth->setOtpCallback(fn() => readline('Enter OTP: '));
     */
    public function setOtpCallback(callable $callback): void
    {
        $this->otpCallback = $callback;
    }

    /**
     * Set manual cookies (for testing without login).
     */
    public function setManualCookies(array $cookies): void
    {
        $this->authCookies = $cookies;
        $this->sAccessToken = $cookies['sAccessToken'] ?? null;
        $this->sFrontToken = $cookies['sFrontToken'] ?? null;
        $this->tokenExpiry = $this->sAccessToken ? $this->extractTokenExpiry($this->sAccessToken) : null;
        $this->sessionId = $this->generateSessionId();
    }

    /**
     * Get valid auth cookies/tokens, performing login if needed.
     * 
     * @return array ['success' => true, 'cookies' => [...], 'sAccessToken' => '...', 'sFrontToken' => '...'] 
     *         or ['success' => false, 'error' => '...']
     */
    public function getValidAuth(): array
    {
        // Check if cached auth is still valid (with 5 min buffer)
        if ($this->isAuthValid()) {
            return $this->getAuthData();
        }

        // If we have manual cookies but they're expired, clear and re-login
        if ($this->sAccessToken && !$this->isAuthValid()) {
            Logger::info('Manual cookies expired, will re-login');
            $this->clearAuth();
        }

        Logger::info('Auth expired or missing, performing login', ['phone' => $this->maskPhone($this->phoneNumber)]);

        $loginResult = $this->performLogin();
        
        if (!$loginResult['success']) {
            return $loginResult;
        }

        return $this->getAuthData();
    }

    /**
     * Check if current auth is valid.
     */
    public function isAuthValid(): bool
    {
        if (!$this->sAccessToken || !$this->sFrontToken || !$this->tokenExpiry) {
            return false;
        }
        
        // Valid if expires in more than 5 minutes
        return time() < ($this->tokenExpiry - 300);
    }

    /**
     * Get current auth data.
     */
    public function getAuthData(): array
    {
        return [
            'success' => true,
            'cookies' => $this->authCookies ?? [],
            'sAccessToken' => $this->sAccessToken,
            'sFrontToken' => $this->sFrontToken,
            'tokenExpiry' => $this->tokenExpiry,
            'sessionId' => $this->sessionId,
        ];
    }

    /**
     * Perform full login flow using headless Chrome.
     */
    private function performLogin(): array
    {
        try {
            $browserFactory = new BrowserFactory($this->chromeBinary, [
                'arguments' => $this->chromeArgs,
            ]);

            $browser = $browserFactory->createBrowser();
            $page = $browser->createPage();

            if ($this->userAgent) {
                $page->setUserAgent($this->userAgent);
            }

            // Step 1: Navigate to login page
            Logger::info('Navigating to login page');
            $page->navigate('https://divar.ir/user/login')->waitForNavigation();
            sleep(2);

            // Step 2: Find and fill phone input
            $phoneInput = $this->findPhoneInput($page);
            if (!$phoneInput) {
                $browser->close();
                return ['success' => false, 'error' => 'Could not find phone input field'];
            }

            Logger::info('Entering phone number');
            $page->type($phoneInput, $this->phoneNumber);
            sleep(1);

            // Step 3: Click "Next" / "Send code" button
            $nextButton = $this->findNextButton($page);
            if (!$nextButton) {
                $browser->close();
                return ['success' => false, 'error' => 'Could not find next button'];
            }

            $page->click($nextButton);
            Logger::info('Clicked next, waiting for OTP input');
            sleep(3);

            // Step 4: Wait for OTP input field
            $otpInput = $this->waitForOtpInput($page, 10);
            if (!$otpInput) {
                $browser->close();
                return ['success' => false, 'error' => 'OTP input field did not appear'];
            }

            // Step 5: Get OTP from callback
            $otp = $this->getOtpFromCallback();
            if (!$otp) {
                $browser->close();
                return ['success' => false, 'error' => 'Failed to get OTP from callback'];
            }

            Logger::info('Entering OTP');
            $page->type($otpInput, $otp);
            sleep(1);

            // Step 6: Click verify/submit button
            $verifyButton = $this->findVerifyButton($page);
            if ($verifyButton) {
                $page->click($verifyButton);
            } else {
                // Sometimes pressing Enter works
                $page->pressKey('Enter');
            }

            Logger::info('Submitted OTP, waiting for login to complete');
            sleep(5);

            // Step 7: Verify login success and extract tokens
            $authResult = $this->extractAuthAfterLogin($page);
            
            $browser->close();

            if (!$authResult['success']) {
                return $authResult;
            }

            Logger::info('Login successful', ['expiry' => date('Y-m-d H:i:s', $this->tokenExpiry ?? 0)]);
            return ['success' => true];

        } catch (\Exception $e) {
            Logger::error('Login failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Login exception: ' . $e->getMessage()];
        }
    }

    /**
     * Find phone input field selector.
     */
    private function findPhoneInput(Page $page): ?string
    {
        $selectors = [
            'input[type="tel"]',
            'input[name="phone"]',
            'input[placeholder*="شماره"]',
            'input[placeholder*="phone"]',
            'input[data-testid="phone-input"]',
            '.login-form input[type="tel"]',
            '#phone-input',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $page->find($selector);
                if ($element) {
                    Logger::info('Found phone input', ['selector' => $selector]);
                    return $selector;
                }
            } catch (\Exception $e) {
                // Continue to next selector
            }
        }

        // Try evaluating to find it
        $result = $page->evaluate('() => {
            const inputs = document.querySelectorAll("input");
            for (const input of inputs) {
                if (input.type === "tel" || input.name === "phone" || 
                    input.placeholder?.includes("شماره") || input.placeholder?.includes("phone")) {
                    return input.outerHTML;
                }
            }
            return null;
        }');
        
        if ($result->getReturnValue()) {
            return 'input[type="tel"]'; // fallback
        }

        return null;
    }

    /**
     * Find next/continue button.
     */
    private function findNextButton(Page $page): ?string
    {
        $selectors = [
            'button[type="submit"]',
            'button:contains("ادامه")',
            'button:contains("Next")',
            'button:contains("ارسال")',
            'button:contains("Send")',
            '.login-form button[type="submit"]',
            'button[data-testid="login-submit"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $page->find($selector);
                if ($element) {
                    Logger::info('Found next button', ['selector' => $selector]);
                    return $selector;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return 'button[type="submit"]'; // fallback
    }

    /**
     * Wait for OTP input to appear.
     */
    private function waitForOtpInput(Page $page, int $maxWaitSeconds): ?string
    {
        $selectors = [
            'input[name="otp"]',
            'input[name="code"]',
            'input[placeholder*="کد"]',
            'input[placeholder*="code"]',
            'input[data-testid="otp-input"]',
            'input[autocomplete="one-time-code"]',
            '.otp-input input',
        ];

        $startTime = time();
        while (time() - $startTime < $maxWaitSeconds) {
            foreach ($selectors as $selector) {
                try {
                    $element = $page->find($selector);
                    if ($element) {
                        Logger::info('Found OTP input', ['selector' => $selector]);
                        return $selector;
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }
            sleep(1);
        }

        return null;
    }

    /**
     * Find verify/submit button for OTP.
     */
    private function findVerifyButton(Page $page): ?string
    {
        $selectors = [
            'button[type="submit"]',
            'button:contains("تایید")',
            'button:contains("Verify")',
            'button:contains("ورود")',
            'button:contains("Login")',
            'button[data-testid="otp-submit"]',
        ];

        foreach ($selectors as $selector) {
            try {
                $element = $page->find($selector);
                if ($element) {
                    return $selector;
                }
            } catch (\Exception $e) {
                // Continue
            }
        }

        return null;
    }

    /**
     * Get OTP from callback.
     */
    private function getOtpFromCallback(): ?string
    {
        if ($this->otpCallback) {
            try {
                $otp = ($this->otpCallback)();
                return $otp ? trim((string)$otp) : null;
            } catch (\Exception $e) {
                Logger::error('OTP callback failed', ['error' => $e->getMessage()]);
            }
        }

        // Fallback: try to read from stdin (for CLI)
        if (PHP_SAPI === 'cli') {
            echo "Enter OTP code from SMS: ";
            $otp = trim(fgets(STDIN));
            return $otp ?: null;
        }

        return null;
    }

    /**
     * Extract auth tokens after successful login.
     */
    private function extractAuthAfterLogin(Page $page): array
    {
        // Get cookies
        $cookies = $this->extractCookies($page);
        $this->authCookies = $this->parseCookies($cookies);

        // Get localStorage for tokens
        $localStorage = $this->extractLocalStorage($page);

        // Extract JWT tokens
        $this->sAccessToken = $this->authCookies['sAccessToken'] ?? ($localStorage['sAccessToken'] ?? null);
        $this->sFrontToken = $this->authCookies['sFrontToken'] ?? ($localStorage['sFrontToken'] ?? null);

        if (!$this->sAccessToken || !$this->sFrontToken) {
            Logger::error('Failed to extract tokens', [
                'cookies' => array_keys($this->authCookies),
                'localStorage' => array_keys($localStorage)
            ]);
            return ['success' => false, 'error' => 'Could not extract auth tokens after login'];
        }

        // Parse expiry
        $this->tokenExpiry = $this->extractTokenExpiry($this->sAccessToken);
        $this->sessionId = $this->generateSessionId();

        return ['success' => true];
    }

    /**
     * Extract cookies from page.
     */
    private function extractCookies(Page $page): array
    {
        $cookiesCollection = $page->getCookies();
        $cookies = [];
        
        if ($cookiesCollection) {
            foreach ($cookiesCollection as $cookie) {
                $cookies[] = [
                    'name' => $cookie->getName(),
                    'value' => $cookie->getValue(),
                    'domain' => $cookie->getDomain(),
                    'path' => '/',
                    'secure' => true,
                    'httpOnly' => true,
                ];
            }
        }
        
        return $cookies;
    }

    /**
     * Extract localStorage from page.
     */
    private function extractLocalStorage(Page $page): array
    {
        $result = $page->evaluate('() => {
            const data = {};
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                data[key] = localStorage.getItem(key);
            }
            return data;
        }');
        
        return $result->getReturnValue() ?? [];
    }

    /**
     * Parse cookies to name => value array.
     */
    private function parseCookies(array $cookies): array
    {
        $parsed = [];
        foreach ($cookies as $cookie) {
            if (isset($cookie['name'], $cookie['value'])) {
                $parsed[$cookie['name']] = $cookie['value'];
            }
        }
        return $parsed;
    }

    /**
     * Extract expiry from JWT.
     */
    private function extractTokenExpiry(string $jwt): ?int
    {
        try {
            $parts = explode('.', $jwt);
            if (count($parts) !== 3) return null;
            
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
            return $payload['exp'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generate session ID.
     */
    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Mask phone number for logging.
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) >= 4) {
            return substr($phone, 0, 4) . '****' . substr($phone, -4);
        }
        return '****';
    }

    /**
     * Clear auth cache (force re-login).
     */
    public function clearAuth(): void
    {
        $this->authCookies = null;
        $this->sAccessToken = null;
        $this->sFrontToken = null;
        $this->tokenExpiry = null;
        $this->sessionId = null;
    }
}