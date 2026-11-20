<?php
/**
 * Sammelt alle englischen Quelltexte (caption/label/suffix im Formular, AirPlayText::t(), Status-Captions,
 * Fehlertexte der Bibliotheken) und prüft, ob libs/locale.json für jeden eine deutsche Fassung hat.
 * Aufruf: php .tools/texte-sammeln.php [--fehlende]
 */
declare(strict_types=1);
$basis = dirname(__DIR__);
$dateien = array_merge(glob($basis . '/libs/*.php'), glob($basis . '/*/module.php'));
$texte = [];
$str = "'((?:[^'\\\\]|\\\\.)*)'";
foreach ($dateien as $d) {
    $code = (string)file_get_contents($d);
    $muster = [
        "/'(?:caption|label|suffix)'\\s*=>\\s*$str/",
        "/AirPlayText::t\\(\\s*$str/",
        "/AirPlayText::t\\(\\s*\\\$\\w+\\s*===\\s*1\\s*\\?\\s*$str\\s*:\\s*$str/",
        "/AirPlayFehler\\(\\s*$str/",
        "/\\}, $str\\);/",
        "/\\\$t\\(\\s*$str/",
        "/'(?:play|pause|stop|next|previous)' => $str/",
        "/InvalidArgumentException\\(\\s*$str/",
        "/UnexpectedValueException\\(\\s*$str/",
        "/RuntimeException\\(sprintf\\(\\s*$str/",
        "/InvalidArgumentException\\(sprintf\\(\\s*$str/",
        "/\\['(?:ja|nein)',\\s*$str\\]/",
        "/\\[\\s*'(?:homepod|appletv|appletv_alt|airport)',\\s*$str\\]/",
        "/^\\s*$str,\\s*$/m",
    ];
    foreach ($muster as $m) {
        preg_match_all($m, $code, $treffer);
        for ($g = 1; $g < count($treffer); $g++) {
            foreach ($treffer[$g] as $t) {
                $t = stripcslashes(str_replace("\\'", "'", $t));
                if ($t !== '' && preg_match('/[A-Za-z]{2}/', $t) && !preg_match('/^[a-z_]+$/', $t) && !preg_match('/^(https?:|[A-Z_]+$|AIRP|\{)/', $t)) {
                    $texte[$t] = true;
                }
            }
        }
    }
}
$locale = json_decode((string)@file_get_contents($basis . '/libs/locale.json'), true)['translations']['de'] ?? [];
$fehlend = array_diff(array_keys($texte), array_keys($locale));
$ueberzaehlig = array_diff(array_keys($locale), array_keys($texte));
if (in_array('--fehlende', $argv, true)) {
    foreach ($fehlend as $t) echo json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}
printf("%d Texte, %d fehlen in locale.json, %d dort überzählig\n", count($texte), count($fehlend), count($ueberzaehlig));
foreach ($ueberzaehlig as $t) echo "  überzählig: $t\n";
exit($fehlend === [] ? 0 : 1);
