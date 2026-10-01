<?php

namespace Src\Controller;

use Src\Support\Logger;
use Src\Support\SelectedPlaceService;
use Throwable;

/**
 * Shared JSON endpoint handler used by each provider's selected-save action.
 */
final class SelectedPlaceAction
{
    public static function handle(string $provider): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            $result = SelectedPlaceService::save(
                $provider,
                $_POST['result_set_key'] ?? null,
                $_POST['place_id'] ?? null
            );
            $statusCode = (int) ($result['http_status'] ?? 200);
            unset($result['http_status']);

            http_response_code($statusCode);
            echo json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (Throwable $e) {
            Logger::error('Selected accommodation could not be saved', [
                'provider' => $provider,
                'place_id' => is_string($_POST['place_id'] ?? null)
                    ? $_POST['place_id']
                    : null,
                'error' => $e->getMessage(),
            ]);

            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'ذخیره اقامتگاه انتخاب‌شده ناموفق بود.',
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        exit;
    }
}
