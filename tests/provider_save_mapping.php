<?php

// Save-mapping tests for the new providers: pure mapping, no database.
require_once __DIR__ . '/bootstrap.php';

use Src\Support\ProviderAccommodationMapper;

expectSame(true, in_array('makanchi', ProviderAccommodationMapper::PROVIDERS, true), 'makanchi allowed');
expectSame(true, in_array('vilayar', ProviderAccommodationMapper::PROVIDERS, true), 'vilayar allowed');

$makanchiPlace = [
    'id' => 'makanchi_242',
    'token' => 'makanchi_242',
    'name' => 'سوئیت مبله',
    'address' => 'تهران',
    'telephone' => '09127197303',
    'price' => 'قیمت برای هر شب ۱,۳۴۴,۰۰۰ تومان',
    'image_preview' => 'https://cdn.example.test/x.jpg',
    'makanchi_url' => 'https://makanchi.com/Apartment/242-x',
    'category' => 'آپارتمان',
];
$row = ProviderAccommodationMapper::toRow('makanchi', $makanchiPlace, ['city' => 'تهران', 'category' => 'apartments', 'page' => 1]);
expectSame('makanchi', $row['provider'], 'provider stored');
expectSame('makanchi_242', $row['external_id'], 'external id');
expectSame('https://makanchi.com/Apartment/242-x', $row['url'], 'detail url kept');
expectSame('سوئیت مبله', $row['title'], 'title kept');
expectSame(null, $row['contact_id'], 'no contact yet');
echo "PASS: makanchi save mapping\n";

$vilayarPlace = [
    'id' => 'vilayar_2188',
    'token' => 'vilayar_2188',
    'name' => 'اجاره ویلای دوبلکس',
    'address' => 'مازندران - سرخرود',
    'telephone' => '09369611987',
    'price' => '۶,۵۰۰,۰۰۰ تومان',
    'image_preview' => 'https://vilayar.com/x.jpg',
    'vilayar_url' => 'https://vilayar.com/VillaDetails/2188',
    'category' => 'استخردار',
    'rating' => 4.8,
];
$row = ProviderAccommodationMapper::toRow('vilayar', $vilayarPlace, ['city' => 'مازندران', 'category' => '7', 'page' => 1]);
expectSame('vilayar', $row['provider'], 'provider stored');
expectSame('vilayar_2188', $row['external_id'], 'external id');
expectSame('https://vilayar.com/VillaDetails/2188', $row['url'], 'detail url kept');
expectSame('مازندران - سرخرود', $row['address'], 'address kept');
echo "PASS: vilayar save mapping\n";

expectSame('makanchi_242', ProviderAccommodationMapper::externalId('makanchi', $makanchiPlace), 'externalId helper');
expectSame('vilayar_2188', ProviderAccommodationMapper::externalId('vilayar', $vilayarPlace), 'externalId helper');
echo "PASS: external id helpers\n";

$behtarinoPlace = [
    'id' => 'behtarino_wezvcbqqbq',
    'token' => 'behtarino_wezvcbqqbq',
    'name' => 'مهمانپذیر شیرون',
    'address' => 'محله مهران، سید خندان، تهران',
    'telephone' => '02126722161',
    'price' => null,
    'image_preview' => 'https://hs3.behtarino.com/media/x.jpeg',
    'behtarino_url' => 'https://behtarino.com/p/wezvcbqqbq~x',
    'category' => 'اقامتگاه',
    'rating' => 2.0,
];
$row = ProviderAccommodationMapper::toRow('behtarino', $behtarinoPlace, ['city' => 'تهران', 'category' => 'اقامتگاه', 'page' => 1]);
expectSame('behtarino', $row['provider'], 'provider stored');
expectSame('behtarino_wezvcbqqbq', $row['external_id'], 'external id');
expectSame('https://behtarino.com/p/wezvcbqqbq~x', $row['url'], 'detail url kept');
expectSame('محله مهران، سید خندان، تهران', $row['address'], 'address kept');
expectSame('behtarino_wezvcbqqbq', ProviderAccommodationMapper::externalId('behtarino', $behtarinoPlace), 'externalId helper');
echo "PASS: behtarino save mapping\n";

echo "All provider save-mapping tests passed.\n";
