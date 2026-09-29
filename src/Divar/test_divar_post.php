<?php


$url = 'https://api.divar.ir/v8/postlist/w/search';

$payload = [
    'source_view' => 'MAP_DISCOVERY_MAP',
    'disable_recommendation' => false,
    'map_state' => [
        'camera_info' => [
            'bbox' => new stdClass()
        ]
    ],
    'search_data' => [
        'form_data' => [
            'data' => [
                'bbox' => [
                    'repeated_float' => [
                        'value' => [
                            ['value' => 50.5240746],
                            ['value' => 35.593174],
                            ['value' => 52.0425568],
                            ['value' => 35.7663078],
                        ]
                    ]
                ],
                'map_free_roaming' => [
                    'boolean' => [
                        'value' => true
                    ]
                ],
                'category' => [
                    'str' => [
                        'value' => 'temporary-rent'
                    ]
                ]
            ]
        ],
        'server_payload' => [
            '@type' => 'type.googleapis.com/widgets.SearchData.ServerPayload',
            'additional_form_data' => [
                'data' => [
                    'sort' => [
                        'str' => 'relevance'
                    ]
                ]
            ]
        ]
    ],
    'query' => '    ',
    'city_ids' => ['1'],
    'user_selected_location' => [
        'places' => [
            ['place_id' => '1']
        ]
    ],
    'previous_user_selected_location' => [
        'places' => []
    ]
];

$json = json_encode(
    $payload,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_THROW_ON_ERROR
);

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $json,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,

    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,

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

echo "================ CURL ERROR ================\n";
echo "errno: " . curl_errno($ch) . "\n";
echo "error: " . curl_error($ch) . "\n";

echo "\n================ CURL INFO =================\n";
print_r(curl_getinfo($ch));

echo "\n================ RESPONSE ==================\n";
echo $body;

curl_close($ch);