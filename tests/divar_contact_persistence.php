<?php

require __DIR__ . '/bootstrap.php';

use Src\Divar\AccommodationRepository;
use Src\Divar\DivarAdMapper;
use Src\Divar\DivarSearchAdStore;

$failures = 0;
$tests = [];
$row = DivarAdMapper::toRow(testAd(), testContext());

$tests['search snapshots retain the complete listing and context'] = static function (): void {
    $_SESSION = [];
    DivarSearchAdStore::remember([testAd()], testContext());
    $stored = DivarSearchAdStore::find('test-token');
    expectSame('اجاره روزانه آپارتمان', $stored['title'], 'Title');
    expectSame('تهران', $stored['province'], 'Province');
    expectSame('تهران', $stored['city'], 'City');
    expectSame('temporary-rent', $stored['category'], 'Category');
    expectSame('تهران، ونک', $stored['address'], 'Address');
    expectSame(1500000.0, $stored['price'], 'Parsed price');
    expectSame(testAd()['raw'], json_decode($stored['raw_data'], true), 'Raw ad');
    expectSame(2, json_decode($stored['provider_data'], true)['harvest']['page'], 'Page');
    expectSame(null, $stored['contact_id'], 'No contact until phone is fetched');

    DivarSearchAdStore::remember([testAd('other-token')], ['city' => 'شیراز']);
    expectSame('تهران', DivarSearchAdStore::find('test-token')['city'], 'Another search must not lose the old ad');
    expectSame(null, DivarSearchAdStore::find('unknown'), 'Unknown token');
};

$tests['snapshot cache stays bounded and retains recently revisited ads'] = static function (): void {
    $_SESSION = [];
    for ($page = 0; $page < 10; $page++) {
        $ads = [];
        for ($i = 0; $i < 24; $i++) {
            $ads[] = testAd('token-' . ($page * 24 + $i));
        }
        DivarSearchAdStore::remember($ads, testContext());
    }
    DivarSearchAdStore::remember([testAd('token-0'), testAd('token-240'), []], testContext());
    expectSame(240, count($_SESSION['divar_search_ads']), 'Cache size');
    expectSame(null, DivarSearchAdStore::find('token-1'), 'Oldest ad is evicted');
    expectSame('token-0', DivarSearchAdStore::find('token-0')['external_id'], 'Revisited ad survives');
    expectSame('token-240', DivarSearchAdStore::find('token-240')['external_id'], 'Newest ad survives');
};

$tests['saving a phone atomically upserts its contact and listing'] = static function () use ($row): void {
    $pdo = new RecordingPDO();
    $contactId = (new AccommodationRepository($pdo))->upsertWithContact($row, '۰۹۱۲ ۳۴۵ ۶۷۸۹');
    expectSame(42, $contactId, 'Returned contact ID');
    expectSame(['begin', 'commit'], $pdo->events, 'Transaction');
    expectSame(2, count($pdo->executions), 'Two writes');
    expectSame(['09123456789'], $pdo->executions[0]['params'], 'Normalized phone');
    expectSame(
        'INSERT INTO contacts (phone) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
        $pdo->executions[0]['sql'],
        'Reuse the unique phone without overwriting contact names/notes'
    );
    $params = $pdo->executions[1]['params'];
    expectSame(42, $params[0], 'Listing contact link');
    expectSame($row['title'], $params[1], 'Listing title');
    expectSame('divar', $params[10], 'Provider');
    expectSame('test-token', $params[11], 'Unique ad token');
    expectSame($row['raw_data'], $params[14], 'Full ad payload');
    expectSame(
        'contact_id = VALUES(contact_id)',
        explode('ON DUPLICATE KEY UPDATE ', $pdo->executions[1]['sql'])[1],
        'Existing listings must update only contact, preserving enriched fields'
    );
};

$tests['existing contact IDs are reused for repeated phones and different ads'] = static function () use ($row): void {
    $pdo = new RecordingPDO();
    $pdo->contactId = '17';
    $repository = new AccommodationRepository($pdo);
    expectSame(17, $repository->upsertWithContact($row, '09123456789'), 'Existing ID');
    expectSame(17, $repository->upsertWithContact($row, '09123456789'), 'Repeated ad');
    $otherRow = DivarAdMapper::toRow(testAd('other-token'), testContext());
    expectSame(17, $repository->upsertWithContact($otherRow, '09123456789'), 'Shared contact');
    expectSame(17, $pdo->executions[5]['params'][0], 'Other ad links to the existing contact');
};

$tests['local, Persian, Arabic and international phone formats share one phone key'] = static function () use ($row): void {
    foreach (['09123456789', '۰۹۱۲۳۴۵۶۷۸۹', '٠٩١٢٣٤٥٦٧٨٩', '+98 (912) 345-6789', '00989123456789', '989123456789'] as $phone) {
        $pdo = new RecordingPDO();
        (new AccommodationRepository($pdo))->upsertWithContact($row, $phone);
        expectSame(['09123456789'], $pdo->executions[0]['params'], 'Canonical phone');
    }
};

$tests['invalid phones and missing ad identities do not start a write'] = static function () use ($row): void {
    foreach (['', '---', 'not a phone', '<script>09123456789</script>', str_repeat('9', 21)] as $phone) {
        $pdo = new RecordingPDO();
        try {
            (new AccommodationRepository($pdo))->upsertWithContact($row, $phone);
            throw new RuntimeException('Invalid phone was accepted');
        } catch (InvalidArgumentException $e) {
            expectSame([], $pdo->events, 'No transaction for invalid data');
            expectSame([], $pdo->executions, 'No writes for invalid data');
        }
    }
    $pdo = new RecordingPDO();
    try {
        (new AccommodationRepository($pdo))->upsertWithContact([], '09123456789');
        throw new RuntimeException('Missing identity was accepted');
    } catch (InvalidArgumentException $e) {
        expectSame([], $pdo->executions, 'No writes without identity');
    }
};

$tests['contact, listing and commit failures roll back instead of partially saving'] = static function () use ($row): void {
    foreach (['INSERT INTO contacts', 'INSERT INTO accommodations', 'commit'] as $failure) {
        $pdo = new RecordingPDO();
        $pdo->failOn = $failure;
        try {
            (new AccommodationRepository($pdo))->upsertWithContact($row, '09123456789');
            throw new RuntimeException('Database failure was swallowed');
        } catch (PDOException $e) {
            expectSame(['begin', 'rollback'], $pdo->events, 'Rollback on ' . $failure);
            expectSame(false, $pdo->inTransaction(), 'No open transaction after failure');
        }
    }
};

$tests['collector reruns still preserve contacts, coordinates and prices'] = static function () use ($row): void {
    $pdo = new RecordingPDO();
    $result = (new AccommodationRepository($pdo))->upsertMany([$row]);
    expectSame(true, $result['ok'], 'Collector compatibility');
    foreach (['contact_id', 'latitude', 'longitude', 'price'] as $column) {
        expectSame(true, str_contains($pdo->executions[0]['sql'],
            "$column = COALESCE(VALUES($column), $column)"), 'Keep existing ' . $column);
    }
};

foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS: $name\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL: $name — {$e->getMessage()}\n";
    }
}

exit($failures === 0 ? 0 : 1);
