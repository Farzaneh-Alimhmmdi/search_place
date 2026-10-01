<?php

namespace Src\Support;

/**
 * Tiny string helpers shared by the collectors.
 *
 * The project requires ext-json and ext-curl only, therefore every mbstring
 * usage here has a safe fallback: the code must not fatally break on a server
 * where ext-mbstring is missing.
 */
final class Str
{
    /**
     * Cut a string to a maximum number of UTF-8 characters.
     *
     * Used before writing to VARCHAR columns so MySQL never rejects a row
     * because a Divar title was longer than the column.
     */
    public static function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($max <= 0) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max, 'UTF-8');
        }

        /*
         * Without mbstring we cannot count characters, so cut generously on
         * bytes and drop a possibly broken trailing multi byte sequence.
         */
        $cut = substr($value, 0, $max * 4);

        $clean = preg_replace('/[\x80-\xBF]+$/', '', $cut);

        return is_string($clean) ? $clean : $cut;
    }

    /**
     * Substring helper that works with or without mbstring.
     */
    public static function slice(string $value, int $length): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length, 'UTF-8');
        }

        return substr($value, 0, $length);
    }

    /**
     * Convert Persian/Arabic-Indic digits to Latin digits and remove the
     * thousands separators used in Iranian texts.
     */
    public static function normalizeNumbers(string $value): string
    {
        $value = str_replace(
            [
                '۰', '۱', '۲', '۳', '۴',
                '۵', '۶', '۷', '۸', '۹',
                '٠', '١', '٢', '٣', '٤',
                '٥', '٦', '٧', '٨', '٩',
            ],
            [
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
                '0', '1', '2', '3', '4', '5', '6', '7', '8', '9',
            ],
            $value
        );

        /*
         * ٬  = Arabic thousands separator
         * ٫  = Arabic decimal separator
         * ‌  = zero width non joiner
         */
        return str_replace(
            ['٬', '،', '٫', "\u{200C}", "\u{00A0}"],
            ['', '', '.', '', ' '],
            $value
        );
    }
}
