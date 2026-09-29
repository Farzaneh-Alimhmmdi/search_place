<?php
namespace Src\Divar;

use RuntimeException;
use JsonException;
use Src\Http\CurlHttpClient;

/**
 * Divar search service.
 *
 * IMPORTANT:
 * - One call to searchPage() fetches only a small number of Divar pages.
 * - The default is ONE Divar page.
 * - The returned next_cursor is used for the next request.
 * - This prevents PHP timeout when thousands of ads exist.
 */
final class DivarSearchService
{
    private CurlHttpClient $http;

    private ?DivarAuthManager $authManager = null;

    private ?DivarContactFetcher $contactFetcher = null;

    private string $query;

    /**
     * Divar city ID.
     *
     * Example:
     * Tehran = "1"
     *
     * Do NOT silently default this to Tehran.
     */
    private string $cityId;

    private string $category;

    /**
     * Optional bounding box.
     *
     * IMPORTANT:
     * Do not put Tehran's bbox here for every city.
     *
     * If null, bbox is not sent.
     */
    private ?array $bbox;

    private const SEARCH_ENDPOINT =
        'https://api.divar.ir/v8/postlist/w/search';

    /**
     * Number of Divar pages fetched during ONE PHP request.
     *
     * 1 = safest.
     *
     * Since the frontend itself is paginated, we normally want 1.
     */
    private const MAX_DIVAR_PAGES_PER_REQUEST = 1;

    public function __construct(
        CurlHttpClient $http,
        string $query,
        string $cityId,
        ?DivarAuthManager $authManager = null,
        string $category = 'temporary-rent',
        ?array $bbox = null
    ) {
        $this->http = $http;
        $this->query = trim($query);
        $this->cityId = trim($cityId);
        $this->authManager = $authManager;
        $this->category = trim($category);
        $this->bbox = $bbox;

        if ($this->cityId === '') {
            throw new RuntimeException(
                'Divar city ID is required.'
            );
        }

        if ($this->category === '') {
            throw new RuntimeException(
                'Divar category is required.'
            );
        }
    }

    /**
     * Fetch ONE application page.
     *
     * First request:
     *
     *     searchPage(null)
     *
     * Next request:
     *
     *     searchPage($previousNextCursor)
     *
     * This method never tries to download thousands of pages.
     */
    public function searchPage(
        ?string $cursor = null,
        int $limit = 24
    ): array {
        /*
         * We intentionally use 24 because Divar normally returns
         * approximately one page of POST_ROW results.
         *
         * More importantly, we NEVER stop halfway through a Divar
         * response. Therefore we don't accidentally skip the remaining
         * rows of that response.
         */
        $limit = max(1, min($limit, 24));

        $paginationData = null;

        /*
         * If a cursor exists, it contains the exact PaginationData
         * returned by Divar on the previous request.
         */
        if ($cursor !== null && trim($cursor) !== '') {
            $paginationData = $this->decodeCursor($cursor);
        }

        $adsByToken = [];

        $pagesProcessed = 0;

        $hasNextPage = false;

        $nextPaginationData = null;

        while (
            $pagesProcessed < self::MAX_DIVAR_PAGES_PER_REQUEST
        ) {
            $payload = $this->buildSearchPayload(
                $paginationData
            );

            $response = $this->divarRequest($payload);

            $body = $response['body'] ?? '';

            if ($body === '') {
                throw new RuntimeException(
                    'Divar returned an empty response.'
                );
            }

            /*
             * Decode as OBJECT first.
             *
             * This is important because JSON:
             *
             *     {}
             *
             * becomes an empty array with json_decode(..., true).
             *
             * Divar's protobuf-style payload expects some of these
             * values to remain objects.
             */
            try {
                $decodedObject = json_decode(
                    $body,
                    false,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (JsonException $e) {
                throw new RuntimeException(
                    'Invalid JSON returned by Divar: ' .
                    $e->getMessage()
                );
            }

            $decoded = $this->jsonObjectToArray(
                $decodedObject
            );

            /*
             * Extract listings.
             */
            $rows = $this->extractPostRows($decoded);

            foreach ($rows as $row) {
                $ad = $this->normalizePostRow($row);

                if ($ad === null) {
                    continue;
                }

                $token = $ad['token'];

                if ($token === '') {
                    continue;
                }

                /*
                 * Deduplicate listings.
                 */
                $adsByToken[$token] = $ad;
            }

            $pagesProcessed++;

            /*
             * Divar tells us whether another page exists.
             */
            $hasNextPage = $this->extractHasNextPage(
                $decoded
            );

            /*
             * Find the EXACT PaginationData returned by Divar.
             */
            $nextPaginationData =
                $this->extractPaginationData($decoded);

            /*
             * If Divar says another page exists but did not give us
             * pagination data, continuing would be unsafe.
             */
            if (
                $hasNextPage &&
                $nextPaginationData === null
            ) {
                throw new RuntimeException(
                    'Divar reported another page but no PaginationData was returned.'
                );
            }

            break;
        }

        /*
         * Create cursor only when another page exists.
         */
        $nextCursor = null;

        if (
            $hasNextPage &&
            is_array($nextPaginationData)
        ) {
            $nextCursor = $this->encodeCursor(
                $nextPaginationData
            );
        }

        $ads = array_values($adsByToken);

        return [
            'success' => true,

            'query' => $this->query,

            'city_id' => $this->cityId,

            'category' => $this->category,

            'count' => count($ads),

            'ads' => $ads,

            'pagination' => [
                'has_next_page' => $hasNextPage,

                'next_cursor' => $nextCursor,

                'divar_pages_fetched' => $pagesProcessed,
            ],
        ];
    }

    /**
     * Build the Divar search payload.
     */
    private function buildSearchPayload(
        ?array $paginationData
    ): array {
        /*
         * Divar's captured browser requests use four spaces
         * when there is no text query.
         *
         * When the user actually entered a query, send that query.
         */
        $query = $this->query !== ''
            ? $this->query
            : '    ';

        $payload = [
            'source_view' => 'MAP_DISCOVERY_MAP',

            'disable_recommendation' => false,

            'map_state' => [
                'camera_info' => [
                    'bbox' => new \stdClass(),
                ],
            ],

            'search_data' => [
                'form_data' => [
                    'data' => [
                        /*
                         * IMPORTANT:
                         *
                         * We only add bbox if one was actually supplied.
                         *
                         * Do NOT use the old Tehran bbox for all cities.
                         */
                        'map_free_roaming' => [
                            'boolean' => [
                                'value' => true,
                            ],
                        ],

                        'category' => [
                            'str' => [
                                'value' => $this->category,
                            ],
                        ],
                    ],
                ],

                'server_payload' => [
                    '@type' =>
                        'type.googleapis.com/widgets.SearchData.ServerPayload',

                    'additional_form_data' => [
                        'data' => [
                            'sort' => [
                                'str' => [
                                    'value' => 'relevance',
                                ],
                            ],
                        ],
                    ],
                ],
            ],

            /*
             * The captured Divar request uses this field.
             */
            'query' => $query,

            /*
             * This must be the selected city's Divar ID.
             */
            'city_ids' => [
                $this->cityId,
            ],

            'user_selected_location' => [
                'places' => [
                    [
                        'place_id' => $this->cityId,
                    ],
                ],
            ],

            'previous_user_selected_location' => [
                'places' => [],
            ],
        ];

        /*
         * Only add bbox when it is explicitly provided.
         */
        if (
            is_array($this->bbox) &&
            count($this->bbox) === 4
        ) {
            $payload['search_data']['form_data']['data']['bbox'] = [
                'repeated_float' => [
                    'value' => array_map(
                        static fn ($value) => [
                            'value' => (float) $value,
                        ],
                        $this->bbox
                    ),
                ],
            ];
        }

        /*
         * Pagination:
         *
         * For page 1 there is no pagination_data.
         *
         * For page 2+ we send EXACTLY what Divar returned before.
         */
        if ($paginationData !== null) {
            $payload['pagination_data'] = $paginationData;
        }

        return $payload;
    }

    /**
     * Send request to Divar.
     */
    private function divarRequest(
        array $payload
    ): array {
        try {
            $json = json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Could not encode Divar request: ' .
                $e->getMessage()
            );
        }

        $headers = [
            'Accept: application/json, text/plain, */*',
            'Accept-Language: en-US,en;q=0.9',
            'Content-Type: application/json',
            'Origin: https://divar.ir',
            'Referer: https://divar.ir/',
            'X-Render-Type: CSR',
            'X-Screen-Size: 1920x233',
            'X-Standard-Divar-Error: true',
            'X-Web-Serving-Mode: desktop',

            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) ' .
            'AppleWebKit/537.36 (KHTML, like Gecko) ' .
            'Chrome/153.0.0.0 Safari/537.36',
        ];

        $maxAttempts = 4;

        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init(self::SEARCH_ENDPOINT);

            if ($ch === false) {
                throw new RuntimeException(
                    'Could not initialize cURL.'
                );
            }

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_POST => true,

                    CURLOPT_POSTFIELDS => $json,

                    CURLOPT_HTTPHEADER => $headers,

                    CURLOPT_RETURNTRANSFER => true,

                    CURLOPT_FOLLOWLOCATION => false,

                    CURLOPT_CONNECTTIMEOUT => 15,

                    CURLOPT_TIMEOUT => 60,

                    /*
                     * Important for the intermittent SSL error
                     * encountered previously.
                     */
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

                    CURLOPT_HTTP_VERSION =>
                        CURL_HTTP_VERSION_2TLS,

                    CURLOPT_SSL_VERIFYPEER => true,

                    CURLOPT_SSL_VERIFYHOST => 2,
                ]
            );

            $body = curl_exec($ch);

            $errno = curl_errno($ch);

            $error = curl_error($ch);

            $httpCode = (int) curl_getinfo(
                $ch,
                CURLINFO_HTTP_CODE
            );

            $info = curl_getinfo($ch);

            curl_close($ch);

            /*
             * Successful HTTP response.
             */
            if (
                $errno === 0 &&
                $httpCode >= 200 &&
                $httpCode < 300
            ) {
                return [
                    'body' => (string) $body,

                    'http_code' => $httpCode,

                    'info' => $info,
                ];
            }

            $lastError = sprintf(
                'Divar request failed. HTTP=%d cURL=%d error=%s',
                $httpCode,
                $errno,
                $error
            );

            /*
             * Retry only the SSL connection error.
             */
            if ($errno === 35) {
                usleep(
                    300000 * $attempt
                );

                continue;
            }

            /*
             * Other cURL errors should fail immediately.
             */
            if ($errno !== 0) {
                throw new RuntimeException(
                    $lastError
                );
            }

            /*
             * HTTP error.
             */
            throw new RuntimeException(
                sprintf(
                    'Divar returned HTTP %d. Response: %s',
                    $httpCode,
                    mb_substr(
                        (string) $body,
                        0,
                        1000
                    )
                )
            );
        }

        throw new RuntimeException(
            $lastError ??
            'Unknown Divar request error.'
        );
    }

    /**
     * Convert JSON objects recursively to arrays.
     */
    private function jsonObjectToArray(
        mixed $value
    ): mixed {
        if (is_object($value)) {
            $result = [];

            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] =
                    $this->jsonObjectToArray($item);
            }

            return $result;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] =
                    $this->jsonObjectToArray($item);
            }

            return $value;
        }

        return $value;
    }

    /**
     * Find POST_ROW widgets recursively.
     */
    private function extractPostRows(
        mixed $data
    ): array {
        $rows = [];

        $this->findPostRowsRecursive(
            $data,
            $rows
        );

        return $rows;
    }

    private function findPostRowsRecursive(
        mixed $data,
        array &$rows
    ): void {
        if (!is_array($data)) {
            return;
        }

        if (
            isset($data['widget_type']) &&
            $data['widget_type'] === 'POST_ROW'
        ) {
            $rows[] = $data;
        }

        if (
            isset($data['widget_type']) &&
            $data['widget_type'] === 'POST_ROW' &&
            isset($data['data'])
        ) {
            /*
             * Some Divar responses structure the widget differently.
             */
            $rows[] = $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->findPostRowsRecursive(
                    $value,
                    $rows
                );
            }
        }
    }

    /**
     * Extract has_next_page recursively.
     */
    private function extractHasNextPage(
        mixed $data
    ): bool {
        $result = null;

        $this->findHasNextPageRecursive(
            $data,
            $result
        );

        return $result === true;
    }

    private function findHasNextPageRecursive(
        mixed $data,
        ?bool &$result
    ): void {
        if ($result !== null) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        if (array_key_exists('has_next_page', $data)) {
            if (is_bool($data['has_next_page'])) {
                $result = $data['has_next_page'];
                return;
            }

            if (
                is_string($data['has_next_page']) &&
                in_array(
                    strtolower($data['has_next_page']),
                    ['true', 'false'],
                    true
                )
            ) {
                $result =
                    strtolower($data['has_next_page']) === 'true';

                return;
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->findHasNextPageRecursive(
                    $value,
                    $result
                );

                if ($result !== null) {
                    return;
                }
            }
        }
    }

    /**
     * Find Divar's exact PaginationData.
     */
    private function extractPaginationData(
        mixed $data
    ): ?array {
        $result = null;

        $this->findPaginationDataRecursive(
            $data,
            $result
        );

        if (!is_array($result)) {
            return null;
        }

        /*
         * Important:
         *
         * json_decode(..., true) converted empty protobuf
         * objects {} into [].
         *
         * Divar expects:
         *
         *     bookmark_state: {}
         *     alert_state: {}
         *
         * rather than:
         *
         *     bookmark_state: []
         *     alert_state: []
         */
        $this->preservePaginationObjects(
            $result
        );

        return $result;
    }

    private function findPaginationDataRecursive(
        mixed $data,
        ?array &$result
    ): void {
        if ($result !== null) {
            return;
        }

        if (!is_array($data)) {
            return;
        }

        if (
            isset($data['@type']) &&
            $data['@type'] ===
            'type.googleapis.com/post_list.PaginationData'
        ) {
            $result = $data;
            return;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->findPaginationDataRecursive(
                    $value,
                    $result
                );

                if ($result !== null) {
                    return;
                }
            }
        }
    }

    /**
     * Restore protobuf empty objects.
     */
    private function preservePaginationObjects(
        array &$pagination
    ): void {
        if (
            isset($pagination['search_bookmark_info']) &&
            is_array(
                $pagination['search_bookmark_info']
            )
        ) {
            if (
                array_key_exists(
                    'bookmark_state',
                    $pagination['search_bookmark_info']
                ) &&
                $pagination['search_bookmark_info']['bookmark_state'] === []
            ) {
                $pagination['search_bookmark_info']['bookmark_state'] =
                    new \stdClass();
            }

            if (
                array_key_exists(
                    'alert_state',
                    $pagination['search_bookmark_info']
                ) &&
                $pagination['search_bookmark_info']['alert_state'] === []
            ) {
                $pagination['search_bookmark_info']['alert_state'] =
                    new \stdClass();
            }
        }
    }

    /**
     * Encode exact Divar pagination data.
     *
     * URL-safe Base64.
     */
    private function encodeCursor(
        array $paginationData
    ): string {
        try {
            $json = json_encode(
                $paginationData,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Could not create pagination cursor: ' .
                $e->getMessage()
            );
        }

        return rtrim(
            strtr(
                base64_encode($json),
                '+/',
                '-_'
            ),
            '='
        );
    }

    /**
     * Decode URL-safe Base64 pagination cursor.
     */
    private function decodeCursor(
        string $cursor
    ): array {
        $cursor = trim($cursor);

        /*
         * Prevent accidentally processing an enormous request.
         */
        if (strlen($cursor) > 200000) {
            throw new RuntimeException(
                'Pagination cursor is too large.'
            );
        }

        $padding = strlen($cursor) % 4;

        if ($padding !== 0) {
            $cursor .= str_repeat(
                '=',
                4 - $padding
            );
        }

        $decoded = base64_decode(
            strtr(
                $cursor,
                '-_',
                '+/'
            ),
            true
        );

        if ($decoded === false) {
            throw new RuntimeException(
                'Invalid Divar pagination cursor.'
            );
        }

        try {
            $data = json_decode(
                $decoded,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException(
                'Invalid pagination cursor JSON.'
            );
        }

        if (!is_array($data)) {
            throw new RuntimeException(
                'Invalid Divar pagination data.'
            );
        }

        $this->preservePaginationObjects(
            $data
        );

        return $data;
    }

    /**
     * Normalize a Divar POST_ROW.
     */
    private function normalizePostRow(
        array $row
    ): ?array {
        $data = $row['data'] ?? null;

        if (!is_array($data)) {
            return null;
        }

        /*
         * Normal token location.
         */
        $token = '';

        if (
            isset($data['token']) &&
            is_string($data['token'])
        ) {
            $token = trim($data['token']);
        }

        /*
         * Fallback token location.
         */
        if ($token === '') {
            $token =
                $data['action']['payload']['token']
                ?? '';
        }

        if (!is_string($token)) {
            $token = '';
        }

        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $title =
            $data['title'] ??
            $data['title_text'] ??
            '';

        if (!is_string($title)) {
            $title = '';
        }

        return [
            // Common fields expected by SearchView
            'id' => $token,
            'token' => $token,

            'name' => $title,
            'title' => $title,

            'address' =>
                $data['bottom_description_text']
                ?? null,

            'description' =>
                $data['top_description_text']
                ?? null,

            'price' =>
                $data['middle_description_text']
                ?? null,

            'telephone' => null,
            'phone' => null,

            'website' => null,

            'rating' => null,

            'instagram_id' => null,

            'image_preview' =>
                $data['image_url']
                ?? null,

            'divar_url' =>
                'https://divar.ir/v/' . $token,

            // Keep the original Divar-specific fields too
            'url' =>
                'https://divar.ir/v/' . $token,

            'price_text' =>
                $data['middle_description_text']
                ?? null,

            'location' =>
                $data['bottom_description_text']
                ?? null,

            'top_description' =>
                $data['top_description_text']
                ?? null,

            'middle_description' =>
                $data['middle_description_text']
                ?? null,

            'bottom_description' =>
                $data['bottom_description_text']
                ?? null,

            'red_text' =>
                $data['red_text']
                ?? null,

            'image_count' =>
                $data['image_count']
                ?? null,

            'layout_type' =>
                $data['layout_type']
                ?? null,

            // Keep raw data for debugging/future use
            'raw' => $data,
        ];
    }
}