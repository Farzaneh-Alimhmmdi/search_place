<?php

namespace Src\Divar;

/**
 * Keeps a bounded snapshot of searched ads until their phone is fetched.
 *
 * The get_phone AJAX request only sends the ad token. Its listing data must
 * come from the server-side search, not from untrusted browser fields. Call
 * these methods after starting the PHP session, as DivarController does.
 */
final class DivarSearchAdStore
{
    private const SESSION_KEY = 'divar_search_ads';

    /** Roughly ten Divar pages, even when many searches/tabs are used. */
    private const MAX_ADS = 240;

    /**
     * Remember one search page without writing ads to the database yet.
     *
     * @param array[] $ads normalized Divar ads
     * @param array<string,mixed> $context search context for DivarAdMapper
     */
    public static function remember(array $ads, array $context): void
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? [];

        if (!is_array($stored)) {
            $stored = [];
        }

        foreach (DivarAdMapper::toRows($ads, $context) as $row) {
            $token = $row['external_id'];

            // Move repeated tokens to the end so recently viewed ads survive.
            unset($stored[$token]);
            $stored[$token] = $row;
        }

        $_SESSION[self::SESSION_KEY] = array_slice($stored, -self::MAX_ADS, null, true);
    }

    /**
     * Find the mapped listing for a previously displayed ad token.
     *
     * @return array<string,mixed>|null null for expired or unknown ads
     */
    public static function find(string $token): ?array
    {
        $row = $_SESSION[self::SESSION_KEY][$token] ?? null;

        return is_array($row) ? $row : null;
    }
}
