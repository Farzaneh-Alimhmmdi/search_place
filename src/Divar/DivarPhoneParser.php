<?php

namespace Src\Divar;

use Src\Support\Str;

/**
 * Pull a phone number out of a Divar contact_info response.
 *
 * Divar has used several shapes: data.value with a Persian title,
 * action.payload.phone_number, and the same widgets nested under page.
 * The controller used to require the exact title «شمارهٔ موبایل», so a
 * hamza/yeh difference or a payload-only response looked like "no phone".
 */
final class DivarPhoneParser
{
    /**
     * @param array<string,mixed> $result decoded Divar JSON
     */
    public static function parse(array $result): ?string
    {
        foreach (self::widgets($result) as $widget) {
            $data = is_array($widget['data'] ?? null) ? $widget['data'] : [];
            $payload = $data['action']['payload']['phone_number']
                ?? $data['payload']['phone_number']
                ?? null;
            $fromPayload = self::normalize(self::stringify($payload));

            if ($fromPayload !== null) {
                return $fromPayload;
            }
        }

        foreach (self::widgets($result) as $widget) {
            $data = is_array($widget['data'] ?? null) ? $widget['data'] : [];
            $normalized = self::normalize(self::stringify($data['value'] ?? null));

            if ($normalized === null) {
                continue;
            }

            $title = (string) ($data['title'] ?? '');
            $type = (string) ($widget['widget_type'] ?? '');

            if (self::titleLooksLikePhone($title) || $type === 'UNEXPANDABLE_ROW') {
                return $normalized;
            }
        }

        return self::findNamedPhone($result);
    }

    /**
     * Compact widget list for logs (no phone values).
     *
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    public static function widgetSummary(array $result): array
    {
        $summary = [];

        foreach (self::widgets($result) as $widget) {
            $data = is_array($widget['data'] ?? null) ? $widget['data'] : [];
            $summary[] = [
                'type' => $widget['widget_type'] ?? null,
                'title' => $data['title'] ?? null,
                'has_value' => array_key_exists('value', $data),
                'has_payload_phone' => isset($data['action']['payload']['phone_number'])
                    || isset($data['payload']['phone_number']),
            ];
        }

        return $summary;
    }

    /**
     * @param array<string,mixed> $result
     * @return list<array<string,mixed>>
     */
    private static function widgets(array $result): array
    {
        foreach ([
            $result['widget_list'] ?? null,
            $result['page']['widget_list'] ?? null,
            $result['data']['widget_list'] ?? null,
        ] as $list) {
            if (is_array($list) && $list !== []) {
                return array_values(array_filter($list, 'is_array'));
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $node
     */
    private static function findNamedPhone(array $node): ?string
    {
        foreach (['phone_number', 'phone', 'mobile', 'tel'] as $key) {
            if (array_key_exists($key, $node)) {
                $normalized = self::normalize(self::stringify($node[$key]));

                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }

            $found = self::findNamedPhone($value);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private static function titleLooksLikePhone(string $title): bool
    {
        $title = str_replace(['ٔ', '‌', ' ', 'ة', 'ي'], ['', '', '', 'ه', 'ی'], $title);

        return $title !== '' && preg_match('/موبایل|تماس|تلفن|phone|mobile/iu', $title) === 1;
    }

    private static function stringify(mixed $value): ?string
    {
        if (is_string($value) || is_int($value)) {
            $text = trim((string) $value);

            return $text === '' ? null : $text;
        }

        return null;
    }

    private static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $phone = Str::normalizeNumbers(trim($raw));
        $phone = (string) preg_replace('/[\s().-]+/u', '', $phone);
        $phone = (string) preg_replace('/^(?:\+98|0098|98)([1-9][0-9]{9})$/D', '0$1', $phone);

        if (preg_match('/^0?9[0-9]{9}$/D', $phone) === 1) {
            return str_starts_with($phone, '0') ? $phone : '0' . $phone;
        }

        if (preg_match('/^\+?[0-9]{7,15}$/D', $phone) === 1) {
            return $phone;
        }

        return null;
    }
}
