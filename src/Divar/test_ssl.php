<?php

echo "PHP VERSION: " . PHP_VERSION . PHP_EOL;
echo "PHP BINARY: " . PHP_BINARY . PHP_EOL;
echo "cURL: " . curl_version()['version'] . PHP_EOL;
echo "SSL: " . curl_version()['ssl_version'] . PHP_EOL;
echo str_repeat("=", 60) . PHP_EOL;

$ch = curl_init('https://api.divar.ir/');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HEADER => false,
]);

$result = curl_exec($ch);

echo "RESULT:" . PHP_EOL;
var_dump($result);

echo PHP_EOL . "ERROR:" . PHP_EOL;
var_dump(curl_error($ch));

echo PHP_EOL . "ERRNO:" . PHP_EOL;
var_dump(curl_errno($ch));

echo PHP_EOL . "INFO:" . PHP_EOL;
print_r(curl_getinfo($ch));

curl_close($ch);