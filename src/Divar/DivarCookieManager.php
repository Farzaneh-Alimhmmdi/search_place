<?php
namespace Src\Divar;

/**
 * DivarCookieManager - Manages Divar authentication cookies stored in PHP session.
 */
final class DivarCookieManager
{
    /**
     * Store Divar cookies in the session.
     *
     * @param array $cookies Associative array of cookie name => value
     * @return void
     */
    public static function setCookies(array $cookies): void
    {
        $_SESSION['divar_cookies'] = $cookies;
    }

    /**
     * Retrieve Divar cookies from the session.
     *
     * @return array Associative array of cookie name => value
     */
    public static function getCookies(): array
    {
        return $_SESSION['divar_cookies'] ?? [];
    }

    /**
     * Clear Divar cookies from the session.
     *
     * @return void
     */
    public static function clearCookies(): void
    {
        unset($_SESSION['divar_cookies']);
    }
}