<?php

// Behtarino parser tests: fixtures only, no network, no database.
require_once __DIR__ . '/bootstrap.php';

use Src\Behtarino\BehtarinoClient;

$listFixture = <<<'HTML'
<html><body>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"SearchResultsPage","mainEntity":{"@type":"ItemList","itemListElement":[{"@type":"ListItem","position":1,"item":{"@context":"https://schema.org","@type":"LocalBusiness","name":"مهمانپذیر شیرون","image":["https://hs3.behtarino.com/media/x.jpeg"],"address":{"@type":"PostalAddress","addressCountry":"IR","streetAddress":"محله مهران، بزرگراه شهید سلیمانی","addressLocality":"سید خندان","addressRegion":"تهران"},"geo":{"@type":"GeoCoordinates","latitude":35.7413945,"longitude":51.4563049},"aggregateRating":{"@type":"AggregateRating","bestRating":"5","reviewCount":4,"ratingValue":2},"url":"https://behtarino.com/p/wezvcbqqbq~x"}}]}}</script>
</body></html>
HTML;

$client = new BehtarinoClient(
    new \Src\Http\CurlHttpClient(['timeout' => 5]),
    'https://behtarino.com',
    [['value' => 'اقامتگاه', 'label' => 'اقامتگاه']],
    ['تهران' => ['تهران']]
);

expectSame('اقامتگاه', $client->getCategoryLabel('اقامتگاه'), 'Category label');
expectSame(null, $client->getCategoryLabel('nope'), 'Unknown category');
expectSame(['تهران'], $client->getCitiesForProvince('تهران'), 'Cities for province');
expectSame([], $client->getCitiesForProvince('ناشناس'), 'Unknown province');

$cards = BehtarinoClient::parseListCards($listFixture);
expectSame(1, count($cards), 'One card parsed');
$card = $cards[0];
expectSame('behtarino_wezvcbqqbq', $card['external_id'], 'External id from detail hash');
expectSame('مهمانپذیر شیرون', $card['name'], 'Name');
expectSame('محله مهران، بزرگراه شهید سلیمانی، سید خندان، تهران', $card['address'], 'Address joined');
expectSame(35.7413945, $card['latitude'], 'Latitude');
expectSame(51.4563049, $card['longitude'], 'Longitude');
expectSame(2.0, $card['rating'], 'Rating');
expectSame(4, $card['review_count'], 'Review count');
expectSame('https://hs3.behtarino.com/media/x.jpeg', $card['image'], 'First image');
echo "PASS: list card parsing\n";

expectSame([], BehtarinoClient::parseListCards('<html><body>no results</body></html>'), 'Empty page');
echo "PASS: empty page\n";

$detailJson = '<html><body>"phoneNumbers":["09384096996","09125134229"]</body></html>';
expectSame('09384096996', BehtarinoClient::parseDetailPhone($detailJson), 'phoneNumbers data first');
$detailMeta = '<html><head><meta name="description" content="مشاهده اطلاعات - اقامتگاه | تهران | تلفن: 02126722161 - بهترینو"></head></html>';
expectSame('02126722161', BehtarinoClient::parseDetailPhone($detailMeta), 'Meta description fallback');
expectSame(null, BehtarinoClient::parseDetailPhone('<html><body>no phone</body></html>'), 'Missing phone');
echo "PASS: detail phone parsing\n";

expectSame(5, BehtarinoClient::parsePageCount('<a href="/r/x/y?page=2">2</a><a href="/r/x/y?page=5">5</a>', 1), 'Highest page wins');
expectSame(2, BehtarinoClient::parsePageCount('<html></html>', 2), 'No links keeps current');
echo "PASS: page count parsing\n";

echo "All Behtarino parser tests passed.\n";
