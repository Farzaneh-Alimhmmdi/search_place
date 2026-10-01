<?php

// Exercise the real get_phone controller action; replace only DB and Divar I/O.
namespace Src\Support {
    final class Db
    {
        public static function connect(string $host, int $port, string $database, string $username, string $password): void {}
        public static function getConnection(): \PDO { return $GLOBALS['test_pdo']; }
    }
}

namespace Src\Controller {
    function curl_init(string $url): object
    {
        $GLOBALS['divar_requests'][] = $url;
        return new \stdClass();
    }
    function curl_setopt(object $handle, int $option, mixed $value): bool { return true; }
    function curl_exec(object $handle): string|false { return $GLOBALS['divar_response']; }
    function curl_getinfo(object $handle, ?int $option = null): int|array
    {
        return $option === null ? ['http_code' => $GLOBALS['divar_status']] : $GLOBALS['divar_status'];
    }
    function curl_error(object $handle): string { return $GLOBALS['divar_error'] ?? ''; }
    function curl_close(object $handle): void {}
}

namespace Src\Divar {
    function curl_init(string $url): object { return \Src\Controller\curl_init($url); }
    function curl_setopt_array(object $handle, array $options): bool { return true; }
    function curl_exec(object $handle): string|false { return \Src\Controller\curl_exec($handle); }
    function curl_errno(object $handle): int { return 0; }
    function curl_getinfo(object $handle, ?int $option = null): int|array { return \Src\Controller\curl_getinfo($handle, $option); }
    function curl_error(object $handle): string { return ''; }
    function curl_close(object $handle): void {}
}

namespace {
    require __DIR__ . '/bootstrap.php';

    use Src\Divar\DivarSearchAdStore;
    use Src\Support\Schema;

    $scenario = $argv[1] ?? 'saved';
    $statuses = [
        'search' => 200,
        'search_stored' => 200,
        'saved' => 200,
        'payload_phone' => 200,
        'already_stored' => 200,
        'new_schema' => 200,
        'db_failure' => 500,
        'schema_failure' => 500,
        'no_phone' => 404,
        'unauthenticated' => 401,
        'expired_auth' => 401,
        'captcha_blocked' => 401,
        'rate_limited' => 429,
        'server_error' => 503,
        'invalid_response' => 500,
        'transport_error' => 500,
        'unknown_ad' => 409,
        'missing_id' => 400,
        'malformed_id' => 400,
    ];
    if (!isset($statuses[$scenario])) {
        throw new \RuntimeException('Unknown test scenario: ' . $scenario);
    }

    session_start();
    $_SESSION = ['divar_schema_ready' => Schema::VERSION, 'divar_cookies' => ['did' => 'test-only']];
    if ($scenario !== 'search') {
        DivarSearchAdStore::remember([testAd()], testContext());
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REQUEST_URI'] = '/search_place';
    $_POST = ['provider' => 'divar', 'action' => 'get_phone', 'place_id' => 'test-token'];
    $GLOBALS['test_pdo'] = new RecordingPDO();
    $GLOBALS['divar_requests'] = [];
    $GLOBALS['divar_status'] = 200;
    $GLOBALS['divar_response'] = json_encode([
        'widget_list' => [[
            'widget_type' => 'UNEXPANDABLE_ROW',
            'data' => ['title' => 'شمارهٔ موبایل', 'value' => '09123456789'],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    switch ($scenario) {
        case 'search':
        case 'search_stored':
            $_POST = ['provider' => 'divar', 'province' => 'تهران', 'city' => 'تهران',
                'place' => 'temporary-rent', 'query' => 'آپارتمان'];
            $GLOBALS['divar_response'] = json_encode([
                'widget_list' => [['widget_type' => 'POST_ROW',
                    'data' => testAd()['raw'] + ['token' => 'test-token']]],
                'has_next_page' => false,
            ], JSON_UNESCAPED_UNICODE);
            if ($scenario === 'search_stored') {
                $GLOBALS['test_pdo']->queryResult = [
                    ['external_id' => 'test-token', 'phone' => '09123456789'],
                ];
            }
            break;
        case 'payload_phone':
            $GLOBALS['divar_response'] = json_encode([
                'widget_list' => [[
                    'widget_type' => 'UNEXPANDABLE_ROW',
                    'data' => [
                        'title' => 'شماره موبایل',
                        'action' => ['payload' => ['phone_number' => '۰۹۱۲۳۴۵۶۷۸۹']],
                    ],
                ]],
            ], JSON_UNESCAPED_UNICODE);
            break;
        case 'already_stored':
            unset($_SESSION['divar_cookies']);
            $GLOBALS['test_pdo']->queryResult = [
                ['phone' => '09123456789'],
            ];
            break;
        case 'new_schema':
        case 'schema_failure':
            unset($_SESSION['divar_schema_ready']);
            if ($scenario === 'schema_failure') {
                $GLOBALS['test_pdo']->failOn = 'CREATE TABLE';
            }
            break;
        case 'db_failure':
            $GLOBALS['test_pdo']->failOn = 'INSERT INTO accommodations';
            break;
        case 'no_phone':
            $GLOBALS['divar_response'] = '{"widget_list":[]}';
            break;
        case 'unauthenticated':
            unset($_SESSION['divar_cookies']);
            break;
        case 'expired_auth':
            $GLOBALS['divar_status'] = 401;
            break;
        case 'captcha_blocked':
            $GLOBALS['divar_status'] = 403;
            break;
        case 'rate_limited':
            $GLOBALS['divar_status'] = 429;
            break;
        case 'server_error':
            $GLOBALS['divar_status'] = 503;
            break;
        case 'invalid_response':
            $GLOBALS['divar_response'] = '<html>Captcha required</html>';
            break;
        case 'transport_error':
            $GLOBALS['divar_response'] = false;
            $GLOBALS['divar_error'] = 'Simulated connection failure';
            break;
        case 'unknown_ad':
            $_POST['place_id'] = 'unknown-token';
            // Browser-supplied listing fields must never become a DB row.
            $_POST['title'] = 'Untrusted browser title';
            break;
        case 'missing_id':
            unset($_POST['place_id']);
            break;
        case 'malformed_id':
            $_POST['place_id'] = ['not-a-token'];
            break;
    }

    ob_start();
    register_shutdown_function(static function () use ($scenario, $statuses): void {
        $body = ob_get_clean();
        try {
            if ($scenario === 'search' || $scenario === 'search_stored') {
                $stored = DivarSearchAdStore::find('test-token');
                expectSame('اجاره روزانه آپارتمان', $stored['title'], 'Snapshot from the actual search');
                expectSame('تهران', $stored['city'], 'Search context');
                expectSame('temporary-rent', $stored['category'], 'Search category');
                expectSame(null, $stored['contact_id'], 'Search does not collect phones');
                expectSame(false, str_contains($body, 'class="call-btn'), 'Divar does not log calls');
                expectSame(true, str_contains($body,
                    "const divarPhoneFailureMessage = 'وارد سایت دیوار شوید و کپجا را حل کنیدتا دسترسی شما باز شود';"),
                    'Frontend failure fallback uses the requested message');
                expectSame(true, str_contains($body, 'data.authentication_required && !data.phone_fetch_failed'),
                    'Provider failures display the message instead of reopening the OTP modal');
                expectSame([], $GLOBALS['test_pdo']->events, 'Search alone starts no DB transaction');
                expectSame(['https://api.divar.ir/v8/postlist/w/search'], $GLOBALS['divar_requests'], 'One search page');

                if ($scenario === 'search_stored') {
                    expectSame(true, str_contains($body, '09123456789'), 'Stored phone is shown in the result');
                    expectSame(true, str_contains($body, '✅ ذخیره شده'), 'Stored listing is marked saved');
                    expectSame(false, str_contains($body, 'class="divar-phone-btn'), 'Stored listing has no fetch button');
                    echo "PASS: Divar search shows a stored phone instead of the fetch button\n";
                    return;
                }

                expectSame(true, str_contains($body, 'class="divar-phone-btn"'), 'Phone button is rendered');
                expectSame(true, str_contains($body, 'data-place-id="test-token"'), 'Phone button targets the ad');
                expectSame(false, str_contains($body, 'class="save-place-btn'), 'Divar has no separate save button');
                echo "PASS: Divar search remembers the listing for get_phone\n";
                return;
            }

            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            expectSame($statuses[$scenario], http_response_code() ?: 200, 'HTTP status');
            expectSame($statuses[$scenario] === 200, $response['success'], 'JSON success');

            $fetchFailed = in_array($scenario, ['no_phone', 'expired_auth', 'captcha_blocked',
                'rate_limited', 'server_error', 'invalid_response', 'transport_error'], true);
            expectSame($fetchFailed, $response['phone_fetch_failed'] ?? false, 'Provider failure flag');
            if ($fetchFailed) {
                expectSame('وارد سایت دیوار شوید و کپجا را حل کنیدتا دسترسی شما باز شود',
                    $response['message'], 'Exact phone-fetch failure message');
                expectSame(false, isset($response['response']), 'Do not expose the raw Divar error response');
            }
            if ($scenario === 'unauthenticated') {
                expectSame(true, $response['authentication_required'], 'Initial OTP login is unchanged');
            }
            if (in_array($scenario, ['db_failure', 'schema_failure'], true)) {
                expectSame('شماره تماس دریافت شد، اما ذخیره آگهی و مخاطب ناموفق بود. لطفاً دوباره تلاش کنید.',
                    $response['message'], 'Persistence errors keep their specific message');
            }

            $pdo = $GLOBALS['test_pdo'];
            $writes = array_values(array_filter($pdo->executions,
                static fn (array $execution): bool => str_starts_with($execution['sql'], 'INSERT INTO')));

            if ($response['success']) {
                expectSame('09123456789', $response['phone_number'], 'Phone response');
                expectSame(true, $response['already_saved'] ?? false, 'Fetched phones are stored');
                if ($scenario === 'already_stored') {
                    expectSame([], $writes, 'Reuse the stored phone without writing again');
                    expectSame([], $pdo->events, 'Stored lookup is not a write transaction');
                    expectSame([], $GLOBALS['divar_requests'], 'Do not call Divar again for a stored phone');
                } else {
                    expectSame(2, count($writes), 'Save both the contact and ad before success');
                    expectSame(['begin', 'commit'], $pdo->events, 'Committed save');
                    expectSame('test-token', $writes[1]['params'][11], 'Save the searched ad');
                    if ($scenario === 'new_schema') {
                        expectSame(Schema::VERSION, $_SESSION['divar_schema_ready'], 'Schema prepared');
                        expectSame(2, count(array_filter($pdo->executions,
                            static fn (array $execution): bool => str_starts_with($execution['sql'], 'CREATE TABLE'))),
                            'Create both tables automatically');
                    }
                }
            } elseif ($scenario === 'db_failure') {
                expectSame(2, count($writes), 'Attempted both writes');
                expectSame(['begin', 'rollback'], $pdo->events, 'Rollback failed save');
                expectSame(false, isset($response['phone_number']), 'Do not report an unsaved success');
            } else {
                expectSame([], $writes, 'No persistence after an invalid/unsuccessful fetch');
            }

            if (in_array($scenario, ['unauthenticated', 'unknown_ad', 'missing_id', 'malformed_id', 'already_stored'], true)) {
                expectSame([], $GLOBALS['divar_requests'], 'No unnecessary Divar request');
            } else {
                expectSame(['https://api.divar.ir/v8/postcontact/web/contact_info_v2/test-token'],
                    $GLOBALS['divar_requests'], 'One phone request for the correct ad');
            }
            echo "PASS: get_phone $scenario\n";
        } catch (\Throwable $e) {
            echo "FAIL: get_phone $scenario — {$e->getMessage()}\n";
            exit(1);
        }
    });

    require dirname(__DIR__) . '/src/Controller/DivarController.php';
}
