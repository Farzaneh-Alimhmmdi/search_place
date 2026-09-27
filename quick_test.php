<?php
require_once 'vendor/autoload.php';
use Src\Neshan\NeshanClient;

$client = new NeshanClient('src/Neshan/neshan_search.py', 'python', 180);
foreach (['apartment', 'villa', 'guest-house'] as $cat) {
    $result = $client->search('tehran', $cat, 1);
    echo $cat . ': ' . $result['total'] . ' results' . "\n";
}