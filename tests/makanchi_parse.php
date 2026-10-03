<?php

// Makanchi HTML parser tests: fixtures only, no network, no database.
require_once __DIR__ . '/bootstrap.php';

use Src\Makanchi\MakanchiClient;

$cardFixture = <<<'HTML'
<li class="col-md-4 col-lg-6 col-xl-4 col-sm-6 mb-4 place" data-item-index="242">
    <a href="/Apartment/242-Studio-SeyedKhandan">
        <div class="vilaList-item">
            <div class="vilaListImage vilaList2Image listPage-list">
                <div class="listPage-image-holder">
                    <img src="//cdn.makanchi.com/Images/Place/20200730167/400_300/Makanchi_2914wuv86D4.jpg" />
                </div>
                <div class="d-inline-block eghamatBtn redBtn listPagelist-btn"> تهران </div>
            </div>
            <div class="list-item-holder">
                <h2 class="vilalist-title-666 mb-2">
                    سوئیت(1) مبله در سیدخندان
                </h2>
                <div class="vilaList-2line mb-2">
                    اجاره روزانه سوئیت 40 متری در محدوده سیدخندان شهر تهران.
                </div>
                <div class="big-vilalist-title mb-3">
                        قیمت برای هر شب 1,344,000 تومان
                </div>
                <div class="vilalist-title-666 mb-3">
                        <span>
                            ظرفیت 2 نفر
                        </span>
                            <i class="fas fa-ellipsis-v mx-1 vilalist-title-999"></i>
                        <span>
                            0 اتاق
                        </span>
                </div>
            </div>
        </div>
    </a>
</li>
HTML;

$cards = MakanchiClient::parseListCards('<ul>' . $cardFixture . '</ul>');
expectSame(1, count($cards), 'One card parsed');
$card = $cards[0];
expectSame('242', $card['numeric_id'], 'Numeric id from detail URL');
expectSame('/Apartment/242-Studio-SeyedKhandan', $card['href'], 'Detail path');
expectSame('https://cdn.makanchi.com/Images/Place/20200730167/400_300/Makanchi_2914wuv86D4.jpg', $card['image'], 'Image absolute URL');
expectSame('تهران', $card['city'], 'City badge');
expectSame('سوئیت(1) مبله در سیدخندان', $card['title'], 'Title trimmed');
expectSame('قیمت برای هر شب 1,344,000 تومان', $card['price_text'], 'Price text');
echo "PASS: list card parsing\n";

expectSame([], MakanchiClient::parseListCards('<html><body>no results</body></html>'), 'Empty page');
echo "PASS: empty page\n";

$detailFixture = '<html><body><a href="tel:09127197303">تماس بگیرید</a><a href="tel:09127197303">x</a></body></html>';
expectSame('09127197303', MakanchiClient::parseDetailPhone($detailFixture), 'First tel: link');
expectSame(null, MakanchiClient::parseDetailPhone('<html><body>no phone</body></html>'), 'Missing phone');
echo "PASS: detail phone parsing\n";

expectSame(5, MakanchiClient::parsePageCount('<a href="/List-Tehran-1?صفحه=2">2</a><a href="/List-Tehran-1?صفحه=5">5</a>', 1), 'Highest page wins');
expectSame(3, MakanchiClient::parsePageCount('<html></html>', 3), 'No links keeps current');
echo "PASS: page count parsing\n";

expectSame('قیمت برای هر شب ۱,۳۴۴,۰۰۰ تومان', MakanchiClient::faPrice('قیمت برای هر شب 1,344,000 تومان'), 'Persian digits');
expectSame(1344000.0, MakanchiClient::numericPrice('قیمت برای هر شب 1,344,000 تومان'), 'Numeric price');
expectSame(null, MakanchiClient::numericPrice('قیمت: توافقی'), 'Agreement has no number');
echo "PASS: price helpers\n";

echo "All Makanchi parser tests passed.\n";
