<?php
$file = 'C:/xampp3/htdocs/my_search_place/search_place/src/View/SearchView.php';
$c = file_get_contents($file);
$block = "                         <label>پارامتر جستجو</label>\n                         <select name=\"provider\" onchange=\"this.form.submit()\">\n                             <option value=\"balad\" <?= (\$this->provider === 'balad') ? 'selected' : '' ?>>بلد (Balad)</option>\n                             <option value=\"neshan\" <?= (\$this->provider === 'neshan') ? 'selected' : '' ?>>نشان (Neshan)</option>\n                         </select>";
$newBlock = "                         <label>پارامتر جستجو</label>\n                         <select name=\"provider\" onchange=\"this.form.submit()\">\n                             <option value=\"balad\" <?= (\$this->provider === 'balad') ? 'selected' : '' ?>>بلد (Balad)</option>\n                             <option value=\"neshan\" <?= (\$this->provider === 'neshan') ? 'selected' : '' ?>>نشان (Neshan)</option>\n                             <option value=\"google_map\" <?= (\$this->provider === 'google_map') ? 'selected' : '' ?>>گوگلMap</option>\n                         </select>";
if (strpos($c, $block) === false) {
    echo "Block not found.\n";
    // Show surrounding text
    $pos = strpos($c, '<label>پارامتر جستجو</label>');
    if ($pos !== false) {
        echo substr($c, $pos-30, 100);
    }
    exit;
}
$c = str_replace($block, $newBlock, $c);
file_put_contents($file, $c);
echo "Block replaced.\n";
?>