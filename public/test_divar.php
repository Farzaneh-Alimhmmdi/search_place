<?php

declare(strict_types=1);

/**
 * Divar Search - Full Pagination Test
 *
 * PHP:
 *   8.2+
 *
 * Run:
 *   C:\xampp3\php\php.exe test_divar.php
 *
 * This script:
 *   - Searches Divar
 *   - Follows pagination until has_next_page = false
 *   - Deduplicates by listing token
 *   - Preserves useful POST_ROW fields
 *   - Preserves raw POST_ROW data
 *   - Preserves protobuf-style empty objects ({})
 */

const DIVAR_SEARCH_URL = 'https://api.divar.ir/v8/postlist/w/search';

const DIVAR_BASE_URL = 'https://divar.ir/v/';

const MAX_PAGES = 1000;


/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Change these values for your search.
|
*/

$query = 'ویلا';

$cityId = '1';

/*
 * Category used by the captured Divar request.
 *
 * temporary-rent = اجاره کوتاه مدت / اقامتگاه
 *
 * If you want another category later, change this.
 */
$category = 'temporary-rent';

/*
 * Bounding box from the captured browser request.
 *
 * [minLng, minLat, maxLng, maxLat]
 */
$bbox = [
    50.5240746,
    35.593174,
    52.0425568,
    35.7663078,
];


/*
|--------------------------------------------------------------------------
| Main
|--------------------------------------------------------------------------
*/

try {

    echo PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
    echo "              DIVAR FULL SEARCH" . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;

    echo "Query    : {$query}" . PHP_EOL;
    echo "City ID  : {$cityId}" . PHP_EOL;
    echo "Category : {$category}" . PHP_EOL;
    echo PHP_EOL;

    $result = searchDivar(
        query: $query,
        cityId: $cityId,
        category: $category,
        bbox: $bbox
    );

    echo PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
    echo "SEARCH FINISHED" . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;

    echo "Total unique ads: " . count($result['ads']) . PHP_EOL;
    echo "Pages processed : " . $result['pages'] . PHP_EOL;

    /*
     * Save complete result.
     */
    $outputFile = __DIR__ . DIRECTORY_SEPARATOR . 'divar_results.json';

    file_put_contents(
        $outputFile,
        json_encode(
            $result,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_PRETTY_PRINT |
            JSON_THROW_ON_ERROR
        )
    );

    echo "Saved to: {$outputFile}" . PHP_EOL;

    /*
     * Also print the simplified ads.
     */
    echo PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
    echo "ADS" . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;

    echo json_encode(
        $result['ads'],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_THROW_ON_ERROR
    );

    echo PHP_EOL;

} catch (Throwable $e) {

    http_response_code(500);

    echo PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;
    echo "ERROR" . PHP_EOL;
    echo str_repeat('=', 70) . PHP_EOL;

    echo json_encode(
        [
            'status' => 'error',
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );

    echo PHP_EOL;
}


/*
|--------------------------------------------------------------------------
| Search Divar
|--------------------------------------------------------------------------
*/

function searchDivar(
    string $query,
    string $cityId,
    string $category,
    array $bbox
): array {

    $paginationData = null;

    /*
     * token => ad
     *
     * This prevents duplicates if Divar returns an ad again
     * on another page.
     */
    $adsByToken = [];

    $pagesProcessed = 0;

    /*
     * These values belong to the search session.
     *
     * We generate them once and keep them stable.
     */
    $searchUid = generateUuid();

    $firstPageViewedAt = gmdate('Y-m-d\TH:i:s.') .
        sprintf('%06d', (int)((microtime(true) * 1000000) % 1000000)) .
        'Z';

    /*
     * Divar normally returns these values in the first response.
     * We don't invent them for later pages if we don't have them.
     */
    $searchHash = null;
    $filtersHash = null;
    $lastPostDate = null;

    for ($page = 1; $page <= MAX_PAGES; $page++) {

        $pagesProcessed++;

        cliLog("Requesting page {$page}...");

        $payload = buildSearchPayload(
            query: $query,
            cityId: $cityId,
            category: $category,
            bbox: $bbox,
            paginationData: $paginationData,
            searchUid: $searchUid,
            firstPageViewedAt: $firstPageViewedAt,
            searchHash: $searchHash,
            filtersHash: $filtersHash,
            lastPostDate: $lastPostDate
        );

        $response = divarRequest($payload);

        $body = $response['body'];

        /*
         * Decode as OBJECT first.
         *
         * This is extremely important because:
         *
         * {}
         *
         * must remain an object.
         *
         * json_decode(..., true) would convert {} into [].
         */
        $objectResponse = json_decode(
            $body,
            false,
            512,
            JSON_THROW_ON_ERROR
        );

        /*
         * Convert to arrays for easier processing.
         */
        $responseArray = jsonObjectToArray($objectResponse);

        /*
         * Get POST_ROW widgets.
         */
        $rows = extractPostRows($responseArray);

        $newAds = 0;

        foreach ($rows as $row) {

            $ad = normalizePostRow($row);

            if ($ad === null) {
                continue;
            }

            $token = $ad['token'];

            if (!isset($adsByToken[$token])) {

                $adsByToken[$token] = $ad;

                $newAds++;
            }
        }

        $hasNextPage = findHasNextPage($responseArray);

        /*
         * Find Divar's exact PaginationData object.
         */
        $newPaginationData = findPaginationData($responseArray);

        /*
         * Preserve protobuf empty objects before sending
         * the pagination object back.
         */
        if ($newPaginationData !== null) {

            preservePaginationObjects($newPaginationData);

            $paginationData = $newPaginationData;

            /*
             * Keep the stable search fields returned by Divar.
             */
            if (
                isset($paginationData['search_bookmark_info'])
                && is_array($paginationData['search_bookmark_info'])
            ) {

                $bookmarkInfo =
                    $paginationData['search_bookmark_info'];

                if (
                    isset($bookmarkInfo['search_hash'])
                    && is_string($bookmarkInfo['search_hash'])
                ) {
                    $searchHash = $bookmarkInfo['search_hash'];
                }
            }

            if (
                isset($paginationData['filters_hash'])
                && is_string($paginationData['filters_hash'])
            ) {
                $filtersHash = $paginationData['filters_hash'];
            }

            if (
                isset($paginationData['last_post_date'])
                && is_string($paginationData['last_post_date'])
            ) {
                $lastPostDate = $paginationData['last_post_date'];
            }
        }

        cliLog(
            "Page {$page}: " .
            count($rows) .
            " rows, " .
            $newAds .
            " new, " .
            count($adsByToken) .
            " total, has_next_page=" .
            ($hasNextPage ? 'true' : 'false')
        );

        /*
         * Stop when Divar says there is no next page.
         */
        if (!$hasNextPage) {
            break;
        }

        /*
         * If Divar says there is another page but did not give us
         * pagination data, continuing would risk requesting the
         * wrong page.
         */
        if ($paginationData === null) {

            throw new RuntimeException(
                "Divar returned has_next_page=true but " .
                "no PaginationData was found."
            );
        }
    }

    return [
        'status' => 'success',
        'query' => $query,
        'city_id' => $cityId,
        'category' => $category,
        'pages' => $pagesProcessed,
        'total' => count($adsByToken),
        'ads' => array_values($adsByToken),
    ];
}


/*
|--------------------------------------------------------------------------
| Build Search Payload
|--------------------------------------------------------------------------
*/

function buildSearchPayload(
    string $query,
    string $cityId,
    string $category,
    array $bbox,
    ?array $paginationData,
    string $searchUid,
    string $firstPageViewedAt,
    ?string $searchHash,
    ?string $filtersHash,
    ?string $lastPostDate
): array {

    /*
     * IMPORTANT:
     *
     * The browser request uses:
     *
     * "str": {
     *     "value": "relevance"
     * }
     *
     * NOT:
     *
     * "str": "relevance"
     */
    $payload = [

        'source_view' => 'MAP_DISCOVERY_MAP',

        'disable_recommendation' => false,

        'map_state' => [
            'camera_info' => [
                'bbox' => new stdClass(),
            ],
        ],

        'search_data' => [

            'form_data' => [

                'data' => [

                    'bbox' => [

                        'repeated_float' => [

                            'value' => [

                                [
                                    'value' => $bbox[0],
                                ],

                                [
                                    'value' => $bbox[1],
                                ],

                                [
                                    'value' => $bbox[2],
                                ],

                                [
                                    'value' => $bbox[3],
                                ],
                            ],
                        ],
                    ],

                    'map_free_roaming' => [

                        'boolean' => [
                            'value' => true,
                        ],
                    ],

                    'category' => [

                        'str' => [
                            'value' => $category,
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
         * IMPORTANT:
         *
         * The captured browser page-2 request contained four spaces
         * here.
         *
         * The actual search state is carried by search_data/session
         * state in the browser request.
         */
        'query' => '    ',

        'city_ids' => [
            $cityId,
        ],

        'user_selected_location' => [

            'places' => [

                [
                    'place_id' => $cityId,
                ],
            ],
        ],

        'previous_user_selected_location' => [

            'places' => [],
        ],
    ];

    /*
     * Page 2+.
     *
     * Send Divar's exact PaginationData back.
     */
    if ($paginationData !== null) {

        $payload['pagination_data'] = $paginationData;
    }

    return $payload;
}


/*
|--------------------------------------------------------------------------
| Divar HTTP Request
|--------------------------------------------------------------------------
*/

//function divarRequest(array $payload): array
//{
//    $json = json_encode(
//        $payload,
//        JSON_UNESCAPED_UNICODE |
//        JSON_UNESCAPED_SLASHES |
//        JSON_THROW_ON_ERROR
//    );
//
//    $ch = curl_init(DIVAR_SEARCH_URL);
//
//    curl_setopt_array($ch, [
//
//        CURLOPT_POST => true,
//
//        CURLOPT_POSTFIELDS => $json,
//
//        CURLOPT_RETURNTRANSFER => true,
//
//        CURLOPT_HEADER => false,
//
//        CURLOPT_CONNECTTIMEOUT => 15,
//
//        CURLOPT_TIMEOUT => 60,
//
//        CURLOPT_HTTPHEADER => [
//
//            'Accept: application/json, text/plain, */*',
//
//            'Accept-Language: en-US,en;q=0.9',
//
//            'Content-Type: application/json',
//
//            'Origin: https://divar.ir',
//
//            'Referer: https://divar.ir/',
//
//            'X-Render-Type: CSR',
//
//            'X-Screen-Size: 1920x233',
//
//            'X-Standard-Divar-Error: true',
//
//            'X-Web-Serving-Mode: desktop',
//
//            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
//        ],
//    ]);
//
//    $body = curl_exec($ch);
//
//    $errno = curl_errno($ch);
//
//    $error = curl_error($ch);
//
//    $info = curl_getinfo($ch);
//
//    curl_close($ch);
//
//    if ($body === false) {
//
//        throw new RuntimeException(
//            'Divar cURL error #' .
//            $errno .
//            ': ' .
//            $error
//        );
//    }
//
//    $httpCode = (int)($info['http_code'] ?? 0);
//
//    if ($httpCode < 200 || $httpCode >= 300) {
//
//        throw new RuntimeException(
//            "Divar HTTP {$httpCode}: {$body}"
//        );
//    }
//
//    return [
//        'body' => $body,
//        'info' => $info,
//    ];
//}

function divarRequest(array $payload): array
{
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    $maxAttempts = 4;
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

        $ch = curl_init(DIVAR_SEARCH_URL);

        curl_setopt_array($ch, [

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $json,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HEADER => false,

            CURLOPT_CONNECTTIMEOUT => 15,

            CURLOPT_TIMEOUT => 60,

            /*
             * Force IPv4.
             *
             * This avoids switching between IPv4/IPv6
             * connection paths.
             */
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

            /*
             * Force HTTP/2 over TLS.
             *
             * Do not allow cURL to negotiate HTTP/3 here.
             */
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,

            CURLOPT_HTTPHEADER => [

                'Accept: application/json, text/plain, */*',

                'Accept-Language: en-US,en;q=0.9',

                'Content-Type: application/json',

                'Origin: https://divar.ir',

                'Referer: https://divar.ir/',

                'X-Render-Type: CSR',

                'X-Screen-Size: 1920x233',

                'X-Standard-Divar-Error: true',

                'X-Web-Serving-Mode: desktop',

                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.0.0 Safari/537.36',
            ],
        ]);

        $body = curl_exec($ch);

        $errno = curl_errno($ch);

        $error = curl_error($ch);

        $info = curl_getinfo($ch);

        curl_close($ch);

        /*
         * Success.
         */
        if ($body !== false && $errno === 0) {

            $httpCode = (int)($info['http_code'] ?? 0);

            /*
             * Retry transient server/protocol errors.
             */
            if ($httpCode >= 200 && $httpCode < 300) {

                return [
                    'body' => $body,
                    'info' => $info,
                ];
            }

            /*
             * HTTP response from Divar.
             *
             * This is not a cURL/TLS failure.
             */
            throw new RuntimeException(
                "Divar HTTP {$httpCode}: {$body}"
            );
        }

        $lastError = sprintf(
            'Divar cURL error #%d: %s',
            $errno,
            $error
        );

        /*
         * cURL error 35 = SSL connect/protocol problem.
         *
         * Retry because this has already demonstrated itself
         * to be intermittent.
         */
        if ($errno === CURLE_SSL_CONNECT_ERROR) {

            cliLog(
                "Transient SSL error on attempt {$attempt}/{$maxAttempts}: {$error}"
            );

            if ($attempt < $maxAttempts) {

                /*
                 * Small increasing delay.
                 */
                usleep(
                    300000 * $attempt
                );

                continue;
            }
        }

        /*
         * Any other cURL error.
         */
        throw new RuntimeException(
            $lastError
        );
    }

    throw new RuntimeException(
        $lastError ?? 'Unknown Divar cURL error'
    );
}
/*
|--------------------------------------------------------------------------
| Normalize POST_ROW
|--------------------------------------------------------------------------
*/

function normalizePostRow(array $row): ?array
{
    $data = $row['data'] ?? null;

    if (!is_array($data)) {
        return null;
    }

    $token = null;

    /*
     * Usually token is directly here.
     */
    if (
        isset($data['token']) &&
        is_string($data['token']) &&
        $data['token'] !== ''
    ) {

        $token = $data['token'];
    }

    /*
     * Fallback to action.payload.token.
     */
    if ($token === null) {

        $token =
            $data['action']['payload']['token']
            ?? null;
    }

    if (!is_string($token) || $token === '') {
        return null;
    }

    return [

        'title' =>
            $data['title']
            ?? null,

        'url' =>
            DIVAR_BASE_URL . $token,

        'token' =>
            $token,

        'price' =>
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

        /*
         * Keep everything Divar returned.
         */
        'raw' => $data,
    ];
}


/*
|--------------------------------------------------------------------------
| Extract POST_ROW widgets
|--------------------------------------------------------------------------
*/

function extractPostRows(array $data): array
{
    $rows = [];

    recursiveFindPostRows($data, $rows);

    return $rows;
}


function recursiveFindPostRows(
    mixed $value,
    array &$rows
): void {

    if (!is_array($value)) {
        return;
    }

    if (
        isset($value['widget_type']) &&
        $value['widget_type'] === 'POST_ROW'
    ) {

        $rows[] = $value;
    }

    foreach ($value as $child) {

        if (is_array($child)) {

            recursiveFindPostRows(
                $child,
                $rows
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| Find has_next_page
|--------------------------------------------------------------------------
*/

function findHasNextPage(array $data): bool
{
    $result = null;

    recursiveFindHasNextPage(
        $data,
        $result
    );

    return $result ?? false;
}


function recursiveFindHasNextPage(
    mixed $value,
    mixed &$result
): void {

    if ($result !== null) {
        return;
    }

    if (!is_array($value)) {
        return;
    }

    if (array_key_exists('has_next_page', $value)) {

        $result = (bool)$value['has_next_page'];

        return;
    }

    foreach ($value as $child) {

        if (is_array($child)) {

            recursiveFindHasNextPage(
                $child,
                $result
            );

            if ($result !== null) {
                return;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Find PaginationData
|--------------------------------------------------------------------------
*/

function findPaginationData(array $data): ?array
{
    $result = null;

    recursiveFindPaginationData(
        $data,
        $result
    );

    return $result;
}


function recursiveFindPaginationData(
    mixed $value,
    mixed &$result
): void {

    if ($result !== null) {
        return;
    }

    if (!is_array($value)) {
        return;
    }

    /*
     * Exact protobuf type from Divar.
     */
    if (
        isset($value['@type']) &&
        $value['@type'] ===
        'type.googleapis.com/post_list.PaginationData'
    ) {

        $result = $value;

        return;
    }

    foreach ($value as $child) {

        if (is_array($child)) {

            recursiveFindPaginationData(
                $child,
                $result
            );

            if ($result !== null) {
                return;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Preserve empty protobuf objects
|--------------------------------------------------------------------------
|
| PHP's json_decode(..., true) changes:
|
| {}
|
| into:
|
| []
|
| Divar expects:
|
| "bookmark_state": {}
| "alert_state": {}
|
| and NOT:
|
| "bookmark_state": []
| "alert_state": []
|
*/

function preservePaginationObjects(array &$pagination): void
{
    if (
        isset($pagination['search_bookmark_info']) &&
        is_array($pagination['search_bookmark_info'])
    ) {

        if (
            array_key_exists(
                'bookmark_state',
                $pagination['search_bookmark_info']
            ) &&
            is_array(
                $pagination['search_bookmark_info']['bookmark_state']
            ) &&
            $pagination['search_bookmark_info']['bookmark_state'] === []
        ) {

            $pagination['search_bookmark_info']['bookmark_state']
                = new stdClass();
        }

        if (
            array_key_exists(
                'alert_state',
                $pagination['search_bookmark_info']
            ) &&
            is_array(
                $pagination['search_bookmark_info']['alert_state']
            ) &&
            $pagination['search_bookmark_info']['alert_state'] === []
        ) {

            $pagination['search_bookmark_info']['alert_state']
                = new stdClass();
        }
    }
}


/*
|--------------------------------------------------------------------------
| Convert JSON objects to arrays
|--------------------------------------------------------------------------
*/

function jsonObjectToArray(mixed $value): mixed
{
    if (is_object($value)) {

        $result = [];

        foreach (get_object_vars($value) as $key => $child) {

            $result[$key] =
                jsonObjectToArray($child);
        }

        return $result;
    }

    if (is_array($value)) {

        $result = [];

        foreach ($value as $key => $child) {

            $result[$key] =
                jsonObjectToArray($child);
        }

        return $result;
    }

    return $value;
}


/*
|--------------------------------------------------------------------------
| UUID
|--------------------------------------------------------------------------
*/

function generateUuid(): string
{
    $data = random_bytes(16);

    $data[6] = chr(
        ord($data[6]) & 0x0f | 0x40
    );

    $data[8] = chr(
        ord($data[8]) & 0x3f | 0x80
    );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($data),
            4
        )
    );
}


/*
|--------------------------------------------------------------------------
| CLI logging
|--------------------------------------------------------------------------
*/

function cliLog(string $message): void
{
    echo '[' .
        date('H:i:s') .
        '] ' .
        $message .
        PHP_EOL;
}
