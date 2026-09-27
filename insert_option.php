<?php
$file = 'C:/xampp3/htdocs/my_search_place/search_place/src/View/SearchView.php';
$c = file_get_contents($file);
$line88 = '                             <option value="neshan" <?= ($this->provider === \'neshan\') ? \'selected\' : \'\' ?>>نشان (Neshan)</option>';
$line89 = '                         </select>';
$newOption = '                             <option value="google_map" <?= ($this->provider === \'google_map\') ? \'selected\' : \'\' ?>>گوگلMap</option>';
$old = $line88 . "\n" . $line89;
$new = $line88 . "\n" . $newOption . "\n" . $line89;
if (strpos($c, $old) === false) {
    echo "Old string not found. Dumping snippet:\n";
    $snip = substr($c, strpos($c, $line88)-20, 200);
    var_dump($snip);
    exit;
}
$c = str_replace($old, $new, $c);
file_put_contents($file, $c);
echo "Option inserted.\n";
?>