<?php

// Vilayar HTML parser tests: fixtures only, no network, no database.
require_once __DIR__ . '/bootstrap.php';

use Src\Vilayar\VilayarClient;

$cardFixture = <<<'HTML'
<article class="vila" onclick="window.location='https://vilayar.com/VillaDetails/2188';">
    <figure><img src="https://vilayar.com/images/users/user-uploads/user-villa/116959920743649.jpg" alt="اجاره ویلای دوبلکس سه خواب" title="اجاره ویلای دوبلکس سه خواب"/>
        <span class="special">ویژه</span>
    </figure>
    <div class="data">
        <h3 class="title"><a href="https://vilayar.com/VillaDetails/2188" title="اجاره ویلای دوبلکس سه خواب">اجاره ویلای دوبلکس سه خواب</a></h3>
        <p class="place pull-right">مازندران - سرخرود</p>
        <div class="my-rating-8 pull-left" data-score="4.8"><span class="d-lg-none">4.8</span></div>
        <h4 class="price-cont">
            <span class="price ">
                  6,500,000
                  تومان
              </span>
        </h4>
    </div>
    <ul>
        <li><img src="https://vilayar.com/users/img/icon/icon088.png"/><span>0 تخت خواب</span></li>
        <li><img src="https://vilayar.com/users/img/icon/icon087.png"/><span>تا 8 مهمان</span></li>
        <li><img src="https://vilayar.com/users/img/icon/icon086.png"/><span>3 اتاق خواب</span></li>
        <li><img src="https://vilayar.com/users/img/icon/icon085.png"/><span>220 متر زیربنا</span></li>
    </ul>
</article>
HTML;

$client = new VilayarClient(
    new \Src\Http\CurlHttpClient(['timeout' => 5]),
    'https://vilayar.com',
    [['value' => '7', 'label' => 'استخردار']],
    ['مازندران' => '89']
);

expectSame('استخردار', $client->getCategoryLabel('7'), 'Category label');
expectSame(null, $client->getCategoryLabel('nope'), 'Unknown category');
expectSame('89', $client->getStateId('مازندران'), 'State id');
expectSame(null, $client->getStateId('ناشناس'), 'Unknown province');

$cards = VilayarClient::parseListCards('<div>' . $cardFixture . '</div>');
expectSame(1, count($cards), 'One card parsed');
$card = $cards[0];
expectSame('2188', $card['numeric_id'], 'Numeric id');
expectSame('https://vilayar.com/VillaDetails/2188', $card['url'], 'Detail URL');
expectSame('اجاره ویلای دوبلکس سه خواب', $card['title'], 'Title');
expectSame('مازندران - سرخرود', $card['place'], 'Place');
expectSame(4.8, $card['rating'], 'Rating');
expectSame(
    'https://vilayar.com/images/users/user-uploads/user-villa/116959920743649.jpg',
    $card['image'],
    'Image URL'
);
echo "PASS: list card parsing\n";

expectSame([], VilayarClient::parseListCards('<html><body>no results</body></html>'), 'Empty page');
echo "PASS: empty page\n";

$detailFixture = '<html><body><p class="title-author">بهشت گمشده</p>'
    . '<a href="tel://09369611987">تماس با مالک</a></body></html>';
expectSame('09369611987', VilayarClient::parseDetailPhone($detailFixture), 'tel:// link');
expectSame('بهشت گمشده', VilayarClient::parseDetailOwner($detailFixture), 'Owner name');
expectSame(null, VilayarClient::parseDetailPhone('<html><body>no phone</body></html>'), 'Missing phone');
echo "PASS: detail parsing\n";

expectSame(4, VilayarClient::parsePageCount('<a href="/search?state=89&page=2">2</a><a href="/search?state=89&page=4">4</a>', 1), 'Highest page wins');
expectSame(2, VilayarClient::parsePageCount('<html></html>', 2), 'No links keeps current');
echo "PASS: page count parsing\n";

expectSame('۶,۵۰۰,۰۰۰ تومان', VilayarClient::faPrice('6,500,000 تومان'), 'Persian digits');
expectSame(6500000.0, VilayarClient::numericPrice('6,500,000 تومان'), 'Numeric price');
expectSame(null, VilayarClient::numericPrice('توافقی'), 'Agreement has no number');
echo "PASS: price helpers\n";

echo "All Vilayar parser tests passed.\n";
