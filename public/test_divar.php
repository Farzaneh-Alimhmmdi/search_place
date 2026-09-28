<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);


/*
|--------------------------------------------------------------------------
| CONFIG
|--------------------------------------------------------------------------
*/

$query = 'ویلا';

$cityId = '1';

$category = 'temporary-rent';

$bbox = [
    50.5240746,
    35.593174,
    52.0425568,
    35.7663078
];

$maxPages = 100;

$delayMilliseconds = 500;


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

try {

    $result = searchDivar(
        $query,
        $cityId,
        $category,
        $bbox,
        $maxPages,
        $delayMilliseconds
    );

    echo json_encode(
        [
            'status' => 'finished',

            'query' => $query,

            'city_id' => $cityId,

            'category' => $category,

            'total_ads' =>
                count($result['ads']),

            'pages_requested' =>
                $result['pages_requested'],

            'has_next_page' =>
                $result['has_next_page'],

            'ads' =>
                array_values($result['ads'])
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

} catch (Throwable $e) {

    http_response_code(500);

    echo json_encode(
        [
            'status' => 'error',

            'message' =>
                $e->getMessage(),

            'file' =>
                $e->getFile(),

            'line' =>
                $e->getLine()
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );
}


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

function searchDivar(
    string $query,
    string $cityId,
    string $category,
    array $bbox,
    int $maxPages,
    int $delayMilliseconds
): array {

    $ads = [];

    /*
     * IMPORTANT:
     *
     * This stays as the exact JSON-ready representation
     * returned by Divar.
     */
    $paginationData = null;

    $page = 1;

    $hasNextPage = true;


    while (
        $hasNextPage &&
        $page <= $maxPages
    ) {

        if ($page > 1) {

            usleep(
                $delayMilliseconds * 1000
            );
        }


        cliLog(
            "Requesting page {$page}..."
        );


        /*
         * Build request.
         */

        $payload = buildSearchPayload(
            $query,
            $cityId,
            $category,
            $bbox,
            $paginationData
        );


        /*
         * Request.
         */

        $response =
            divarRequest(
                $payload
            );


        /*
         * Extract listings.
         */

        $rows = [];

        extractPostRows(
            $response,
            $rows
        );


        $newAds = 0;


        foreach ($rows as $row) {

            if (
                empty($row['token'])
            ) {
                continue;
            }


            $token =
                $row['token'];


            /*
             * Only deduplicate by token.
             *
             * We do NOT filter:
             *
             * viewed
             * unviewed
             * promoted
             * seller
             * price
             * title
             */

            if (
                !isset(
                    $ads[$token]
                )
            ) {

                $ads[$token] =
                    $row;

                $newAds++;
            }
        }


        /*
         * Get the exact PaginationData.
         */

        $newPagination =
            findPaginationData(
                $response
            );


        if ($newPagination !== null) {

            $paginationData =
                $newPagination;


            cliLog(
                'PaginationData: '
                . 'page='
                . (
                    $paginationData['page']
                    ?? '?'
                )
                . ' search_uid='
                . (
                    $paginationData[
                    'search_uid'
                    ]
                    ?? '?'
                )
            );

        } else {

            cliLog(
                'WARNING: PaginationData not found'
            );
        }


        /*
         * Get has_next_page.
         */

        $next =
            findHasNextPage(
                $response
            );


        if ($next === null) {

            $hasNextPage = false;

            cliLog(
                'WARNING: has_next_page not found'
            );

        } else {

            $hasNextPage = $next;
        }


        cliLog(
            "Page {$page}: "
            . count($rows)
            . " rows, "
            . $newAds
            . " new, total "
            . count($ads)
            . ", has_next_page="
            . (
            $hasNextPage
                ? 'true'
                : 'false'
            )
        );


        $page++;
    }


    return [

        'ads' =>
            $ads,

        'pages_requested' =>
            $page - 1,

        'has_next_page' =>
            $hasNextPage
    ];
}


/*
|--------------------------------------------------------------------------
| BUILD PAYLOAD
|--------------------------------------------------------------------------
*/

function buildSearchPayload(
    string $query,
    string $cityId,
    string $category,
    array $bbox,
    ?array $paginationData
): array {

    $payload = [

        'source_view' =>
            'MAP_DISCOVERY_MAP',


        'disable_recommendation' =>
            false,


        'map_state' => [

            'camera_info' => [

                'bbox' =>
                    new stdClass()
            ]
        ],


        'search_data' => [

            'form_data' => [

                'data' => [

                    'bbox' => [

                        'repeated_float' => [

                            'value' => [

                                [
                                    'value' =>
                                        $bbox[0]
                                ],

                                [
                                    'value' =>
                                        $bbox[1]
                                ],

                                [
                                    'value' =>
                                        $bbox[2]
                                ],

                                [
                                    'value' =>
                                        $bbox[3]
                                ]
                            ]
                        ]
                    ],


                    'map_free_roaming' => [

                        'boolean' => [

                            'value' =>
                                true
                        ]
                    ],


                    'category' => [

                        'str' => [

                            'value' =>
                                $category
                        ]
                    ]
                ]
            ],


            'server_payload' => [

                '@type' =>
                    'type.googleapis.com/widgets.SearchData.ServerPayload',


                'additional_form_data' => [

                    'data' => [

                        'sort' => [

                            'str' => [

                                'value' =>
                                    'relevance'
                            ]
                        ]
                    ]
                ]
            ]
        ],


        /*
         * This is exactly what was present
         * in the captured request.
         */

        'query' => '    ',


        'city_ids' => [

            $cityId
        ],


        'user_selected_location' => [

            'places' => [

                [
                    'place_id' =>
                        $cityId
                ]
            ]
        ],


        'previous_user_selected_location' => [

            'places' => []
        ]
    ];


    /*
     * Page 2+.
     */

    if ($paginationData !== null) {

        $payload['pagination_data'] =
            $paginationData;
    }


    return $payload;
}


/*
|--------------------------------------------------------------------------
| HTTP REQUEST
|--------------------------------------------------------------------------
*/

function divarRequest(
    array $payload
): array {

    $url =
        'https://api.divar.ir/v8/postlist/w/search';


    $json =
        json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_THROW_ON_ERROR
        );


    $ch =
        curl_init(
            $url
        );


    curl_setopt_array(
        $ch,
        [

            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_POST =>
                true,

            CURLOPT_POSTFIELDS =>
                $json,

            CURLOPT_HTTPHEADER => [

                'accept: application/json, text/plain, */*',

                'accept-language: en-US,en;q=0.9',

                'cache-control: no-cache',

                'content-type: application/json',

                'origin: https://divar.ir',

                'pragma: no-cache',

                'referer: https://divar.ir/',

                'x-render-type: CSR',

                'x-screen-size: 1920x233',

                'x-standard-divar-error: true',

                'x-web-serving-mode: desktop',

                'user-agent: Mozilla/5.0 '
                . '(Windows NT 10.0; Win64; x64) '
                . 'AppleWebKit/537.36 '
                . '(KHTML, like Gecko) '
                . 'Chrome/153.0.0.0 '
                . 'Safari/537.36'
            ],

            CURLOPT_CONNECTTIMEOUT =>
                20,

            CURLOPT_TIMEOUT =>
                60,

            CURLOPT_ENCODING =>
                ''
        ]
    );


    $body =
        curl_exec(
            $ch
        );


    $curlError =
        curl_error(
            $ch
        );


    $httpCode =
        curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $ch
    );


    if ($body === false) {

        throw new RuntimeException(
            'cURL error: '
            . $curlError
        );
    }


    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        throw new RuntimeException(
            "Divar HTTP {$httpCode}: {$body}"
        );
    }


    /*
     * IMPORTANT:
     *
     * Decode as OBJECT first.
     *
     * This preserves:
     *
     * {}
     *
     * as objects instead of:
     *
     * []
     */

    $objectResponse =
        json_decode(
            $body,
            false,
            512,
            JSON_THROW_ON_ERROR
        );


    /*
     * Convert to arrays for our application.
     *
     * Empty JSON objects remain distinguishable while
     * the conversion below explicitly fixes the protobuf
     * pagination structures.
     */

    $arrayResponse =
        jsonObjectToArray(
            $objectResponse
        );


    /*
     * Fix protobuf objects which MUST remain objects.
     */

    preservePaginationObjects(
        $arrayResponse
    );


    return $arrayResponse;
}


/*
|--------------------------------------------------------------------------
| JSON OBJECT -> ARRAY
|--------------------------------------------------------------------------
*/

function jsonObjectToArray(
    mixed $value
): mixed {

    if (is_object($value)) {

        $result = [];

        foreach (
            get_object_vars($value)
            as $key => $child
        ) {

            $result[$key] =
                jsonObjectToArray(
                    $child
                );
        }

        return $result;
    }


    if (is_array($value)) {

        $result = [];

        foreach (
            $value
            as $key => $child
        ) {

            $result[$key] =
                jsonObjectToArray(
                    $child
                );
        }

        return $result;
    }


    return $value;
}


/*
|--------------------------------------------------------------------------
| PRESERVE PAGINATION OBJECTS
|--------------------------------------------------------------------------
|
| PHP turns {} into [].
|
| Divar's protobuf parser expects:
|
| "bookmark_state": {}
|
| not:
|
| "bookmark_state": []
|
|--------------------------------------------------------------------------
*/

function preservePaginationObjects(
    array &$value
): void {

    /*
     * Search recursively for search_bookmark_info.
     */

    if (
        isset(
            $value['search_bookmark_info']
        ) &&
        is_array(
            $value['search_bookmark_info']
        )
    ) {

        $bookmark =
            &$value[
        'search_bookmark_info'
        ];


        /*
         * These are protobuf message objects,
         * not repeated fields.
         *
         * Therefore they must be encoded as {}.
         */

        if (
            isset(
                $bookmark['bookmark_state']
            ) &&
            is_array(
                $bookmark[
                'bookmark_state'
                ]
            ) &&
            count(
                $bookmark[
                'bookmark_state'
                ]
            ) === 0
        ) {

            $bookmark[
            'bookmark_state'
            ] =
                new stdClass();
        }


        if (
            isset(
                $bookmark['alert_state']
            ) &&
            is_array(
                $bookmark[
                'alert_state'
                ]
            ) &&
            count(
                $bookmark[
                'alert_state'
                ]
            ) === 0
        ) {

            $bookmark[
            'alert_state'
            ] =
                new stdClass();
        }


        unset(
            $bookmark
        );
    }


    /*
     * Continue recursively.
     */

    foreach (
        $value
        as &$child
    ) {

        if (is_array($child)) {

            preservePaginationObjects(
                $child
            );
        }
    }

    unset($child);
}


/*
|--------------------------------------------------------------------------
| FIND POST ROWS
|--------------------------------------------------------------------------
*/

function extractPostRows(
    mixed $value,
    array &$rows
): void {

    if (!is_array($value)) {

        return;
    }


    if (
        isset(
            $value['widget_type']
        ) &&
        $value['widget_type'] ===
        'POST_ROW' &&
        isset(
            $value['data']
        ) &&
        is_array(
            $value['data']
        )
    ) {

        $data =
            $value['data'];


        $token = null;


        if (
            isset(
                $data['token']
            ) &&
            $data['token'] !== ''
        ) {

            $token =
                $data['token'];

        } elseif (
            isset(
                $data['action']
            ) &&
            isset(
                $data['action']['payload']
            ) &&
            isset(
                $data['action']['payload']['token']
            )
        ) {

            $token =
                $data[
                'action'
                ][
                'payload'
                ][
                'token'
                ];
        }


        if ($token !== null) {

            $rows[] = [

                'title' =>
                    $data['title']
                    ?? null,

                'url' =>
                    'https://divar.ir/v/'
                    . $token,

                'token' =>
                    $token,

                'price' =>
                    $data[
                    'middle_description_text'
                    ]
                    ?? null,

                'location' =>
                    $data[
                    'bottom_description_text'
                    ]
                    ?? null,

                'top_description' =>
                    $data[
                    'top_description_text'
                    ]
                    ?? null,

                'middle_description' =>
                    $data[
                    'middle_description_text'
                    ]
                    ?? null,

                'bottom_description' =>
                    $data[
                    'bottom_description_text'
                    ]
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
                 * Preserve everything.
                 */

                'raw' =>
                    $data
            ];
        }
    }


    foreach (
        $value
        as $child
    ) {

        if (is_array($child)) {

            extractPostRows(
                $child,
                $rows
            );
        }
    }
}


/*
|--------------------------------------------------------------------------
| FIND HAS NEXT PAGE
|--------------------------------------------------------------------------
*/

function findHasNextPage(
    mixed $value
): ?bool {

    if (!is_array($value)) {

        return null;
    }


    if (
        array_key_exists(
            'has_next_page',
            $value
        )
    ) {

        $v =
            $value[
            'has_next_page'
            ];


        if (is_bool($v)) {

            return $v;
        }


        if (
            $v === true ||
            $v === 1 ||
            $v === '1' ||
            $v === 'true'
        ) {

            return true;
        }


        if (
            $v === false ||
            $v === 0 ||
            $v === '0' ||
            $v === 'false'
        ) {

            return false;
        }
    }


    foreach (
        $value
        as $child
    ) {

        if (!is_array($child)) {

            continue;
        }


        $result =
            findHasNextPage(
                $child
            );


        if ($result !== null) {

            return $result;
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| FIND EXACT PAGINATION DATA
|--------------------------------------------------------------------------
*/

function findPaginationData(
    mixed $value
): ?array {

    if (!is_array($value)) {

        return null;
    }


    /*
     * Require the exact protobuf type.
     */

    if (
        isset(
            $value['@type']
        ) &&
        $value['@type'] ===
        'type.googleapis.com/post_list.PaginationData'
    ) {

        /*
         * Before returning it, make sure the
         * protobuf message objects are represented
         * as objects.
         */

        if (
            isset(
                $value[
                'search_bookmark_info'
                ]
            ) &&
            is_array(
                $value[
                'search_bookmark_info'
                ]
            )
        ) {

            $bookmark =
                &$value[
            'search_bookmark_info'
            ];


            if (
                isset(
                    $bookmark[
                    'bookmark_state'
                    ]
                ) &&
                is_array(
                    $bookmark[
                    'bookmark_state'
                    ]
                ) &&
                count(
                    $bookmark[
                    'bookmark_state'
                    ]
                ) === 0
            ) {

                $bookmark[
                'bookmark_state'
                ] =
                    new stdClass();
            }


            if (
                isset(
                    $bookmark[
                    'alert_state'
                    ]
                ) &&
                is_array(
                    $bookmark[
                    'alert_state'
                    ]
                ) &&
                count(
                    $bookmark[
                    'alert_state'
                    ]
                ) === 0
            ) {

                $bookmark[
                'alert_state'
                ] =
                    new stdClass();
            }


            unset(
                $bookmark
            );
        }


        return $value;
    }


    foreach (
        $value
        as $child
    ) {

        if (!is_array($child)) {

            continue;
        }


        $result =
            findPaginationData(
                $child
            );


        if ($result !== null) {

            return $result;
        }
    }


    return null;
}


/*
|--------------------------------------------------------------------------
| LOG
|--------------------------------------------------------------------------
*/

function cliLog(
    string $message
): void {

    if (
        PHP_SAPI === 'cli' &&
        defined('STDERR')
    ) {

        fwrite(
            STDERR,
            $message . PHP_EOL
        );

        return;
    }


    error_log(
        $message
    );
}