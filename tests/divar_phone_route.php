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
    function curl_exec(object $handle): string { return $GLOBALS['divar_response']; }
    function curl_getinfo(object $handle, ?int $option = null): int|array
    {
        return $option === null ? ['http_code' => $GLOBALS['divar_status']] : $GLOBALS['divar_status'];
    }
    function curl_error(object $handle): string { return ''; }
    function curl_close(object $handle): void {}
}

namespace Src\Divar {
    function curl_init(string $url): object { return \Src\Controller\curl_init($url); }
    function curl_setopt_array(object $handle, array $options): bool { return true; }
    function curl_exec(object $handle): string { return \Src\Controller\curl_exec($handle); }
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
        'saved' => 200,
        'new_schema' => 200,
        'db_failure' => 500,
        'schema_failure' => 500,
        'no_phone' => 404,
        'unauthenticated' => 401,
        'expired_auth' => 401,
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
            $_POST = ['provider' => 'divar', 'province' => 'تهران', 'city' => 'تهران',
                'place' => 'temporary-rent', 'query' => 'آپارتمان'];
            $GLOBALS['divar_response'] = json_encode([
                'widget_list' => [['widget_type' => 'POST_ROW',
                    'data' => testAd()['raw'] + ['token' => 'test-token']]],
                'has_next_page' => false,
            ], JSON_UNESCAPED_UNICODE);
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
            if ($scenario === 'search') {
                $stored = DivarSearchAdStore::find('test-token');
                expectSame('اجاره روزانه آپارتمان', $stored['title'], 'Snapshot from the actual search');
                expectSame('تهران', $stored['city'], 'Search context');
                expectSame('temporary-rent', $stored['category'], 'Search category');
                expectSame(null, $stored['contact_id'], 'Search does not collect phones');
                expectSame(true, str_contains($body, 'data-place-id="test-token"'), 'Phone button is rendered');
                expectSame([], $GLOBALS['test_pdo']->events, 'Search alone starts no DB transaction');
                expectSame(['https://api.divar.ir/v8/postlist/w/search'], $GLOBALS['divar_requests'], 'One search page');
                echo "PASS: Divar search remembers the listing for get_phone\n";
                return;
            }

            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            expectSame($statuses[$scenario], http_response_code() ?: 200, 'HTTP status');
            expectSame($statuses[$scenario] === 200, $response['success'], 'JSON success');
            $pdo = $GLOBALS['test_pdo'];
            $writes = array_values(array_filter($pdo->executions,
                static fn (array $execution): bool => str_starts_with($execution['sql'], 'INSERT INTO')));

            if ($response['success']) {
                expectSame('09123456789', $response['phone_number'], 'Phone response');
                expectSame(2, count($writes), 'Save both the contact and ad before success');
                expectSame(['begin', 'commit'], $pdo->events, 'Committed save');
                expectSame('test-token', $writes[1]['params'][11], 'Save the searched ad');
                if ($scenario === 'new_schema') {
                    expectSame(Schema::VERSION, $_SESSION['divar_schema_ready'], 'Schema prepared');
                    expectSame(2, count(array_filter($pdo->executions,
                        static fn (array $execution): bool => str_starts_with($execution['sql'], 'CREATE TABLE'))),
                        'Create both tables automatically');
                }
            } elseif ($scenario === 'db_failure') {
                expectSame(2, count($writes), 'Attempted both writes');
                expectSame(['begin', 'rollback'], $pdo->events, 'Rollback failed save');
                expectSame(false, isset($response['phone_number']), 'Do not report an unsaved success');
            } else {
                expectSame([], $writes, 'No persistence after an invalid/unsuccessful fetch');
            }

            if (in_array($scenario, ['unauthenticated', 'unknown_ad', 'missing_id', 'malformed_id'], true)) {
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
