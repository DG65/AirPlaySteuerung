<?php
/**
 * Prüfstand AirPlay-Steuerung (ohne Symcon).
 *   php .tools/test-airplay.php                 Offline-Prüfungen
 *   php .tools/test-airplay.php --live <IP>     zusätzlich Kopplung + Gong am echten Gerät (hörbar!)
 *   php .tools/test-airplay.php --koppeln <IP>  zusätzlich nur Kopplung am echten Gerät (ohne Ton)
 */
declare(strict_types=1);

$basis = dirname(__DIR__);
require_once $basis . '/libs/AirPlayAblauf.php';
require_once $basis . '/libs/AirPlayRtsp.php';
require_once $basis . '/libs/AirPlayAudio.php';
require_once $basis . '/libs/AirPlayGeraete.php';
require_once $basis . '/libs/AirPlayKlimaDaten.php';
require_once $basis . '/libs/AirPlayPiper.php';
require_once $basis . '/libs/AirPlayZeitplan.php';
require_once $basis . '/libs/AirPlayWarteschlange.php';
require_once $basis . '/libs/AirPlayStimmen.php';
require_once $basis . '/libs/AirPlayLautstaerke.php';
require_once $basis . '/libs/AirPlayProtokoll.php';
require_once $basis . '/libs/AirPlayGruppe.php';
require_once $basis . '/libs/AirPlayNetz.php';

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, bool $ok, string $info = ''): void
{
    global $fehler, $anzahl;
    $anzahl++;
    if (!$ok) {
        $fehler++;
    }
    echo ($ok ? '  ok    ' : '  FEHLER ') . $name . ($info !== '' ? ' — ' . $info : '') . "\n";
}

echo "SRP gegen srptools\n";
foreach (json_decode((string)file_get_contents(__DIR__ . '/fixture-srp.json'), true) as $i => $f) {
    $srp = new AirPlaySrp('Pair-Setup', '3939', hex2bin($f['a']));
    $srp->verarbeiten(hex2bin($f['salz']), hex2bin($f['b']));
    pruefe("Fall $i", bin2hex($srp->oeffentlichA) === $f['A'] && bin2hex($srp->k) === $f['K'] && bin2hex($srp->m) === $f['M']);
}

echo "Binär-Plist\n";
$py = base64_decode(json_decode((string)file_get_contents(__DIR__ . '/fixture-bplist.json'), true)['bplist']);
$d = AirPlayBplist::decode($py);
pruefe('plistlib lesen', $d['streams'][0]['name'] === 'Küche' && $d['streams'][0]['shk']->bytes === implode('', array_map('chr', range(0, 31)))
    && $d['streams'][0]['streamConnectionID'] === 4000000000 && $d['streams'][0]['isMedia'] === true && $d['eventPort'] === 51234
    && count($d['liste']) === 20 && abs($d['streams'][0]['x'] - 1.5) < 1e-9);
$r = AirPlayBplist::decode(AirPlayBplist::encode($d));
pruefe('eigenes Schreiben und Lesen', json_encode($r['streams'][0]['shk']) === json_encode($d['streams'][0]['shk']) && $r['liste'] === $d['liste'] && $r['streams'][0]['name'] === 'Küche');

echo "TLV8\n";
$lang = random_bytes(600);
$t = AirPlayTlv8::decode(AirPlayTlv8::encode([[0x03, $lang], [0x06, "\x03"], [0x04, '']]));
pruefe('über 255 Bytes geteilt und zusammengesetzt', $t[0x03] === $lang && $t[0x06] === "\x03" && $t[0x04] === '');

echo "ChaCha20 / HKDF\n";
$s = random_bytes(32);
$c1 = new AirPlayChaCha($s, $s);
$c2 = new AirPlayChaCha($s, $s);
pruefe('Ver- und Entschlüsseln mit Zähler', $c2->decrypt($c1->encrypt('eins', 'x'), 'x') === 'eins' && $c2->decrypt($c1->encrypt('zwei'), '') === 'zwei');
pruefe('HKDF-Länge', strlen(AirPlayHkdf::sha512('Control-Salt', 'Control-Write-Encryption-Key', random_bytes(64))) === 32);

echo "Protobuf\n";
$pb = AirPlayProtobuf::feld(1, 'varint', 300) . AirPlayProtobuf::feld(2, 'varint', -1) . AirPlayProtobuf::feld(3, 'string', 'Küche')
    . AirPlayProtobuf::feld(4, 'float', 0.25) . AirPlayProtobuf::feld(5, 'double', 194.064) . AirPlayProtobuf::feld(6, 'bool', true)
    . AirPlayProtobuf::feld(7, 'msg', AirPlayProtobuf::feld(1, 'string', 'innen'));
$d = AirPlayProtobuf::decode($pb);
pruefe('Varint, negative Zahl (10 Byte), Text', AirPlayProtobuf::int($d, 1) === 300 && AirPlayProtobuf::int($d, 2) === -1 && strlen(AirPlayProtobuf::varint(-1)) === 10 && AirPlayProtobuf::text($d, 3) === 'Küche');
pruefe('float, double, bool, Untermeldung', AirPlayProtobuf::float($d, 4) === 0.25 && abs(AirPlayProtobuf::double($d, 5) - 194.064) < 1e-9
    && AirPlayProtobuf::bool($d, 6) === true && AirPlayProtobuf::text(AirPlayProtobuf::msg($d, 7), 1) === 'innen');

echo "MRP-Zustand\n";
$f = fn (int $n, string $t, mixed $w) => AirPlayProtobuf::feld($n, $t, $w);
$pfad = $f(2, 'msg', $f(2, 'string', 'com.apple.Music')) . $f(3, 'msg', $f(1, 'string', 'MediaRemote-DefaultPlayer'));
$meta = $f(1, 'string', 'Ragnarok') . $f(6, 'string', 'Single') . $f(7, 'string', 'Ship of Odin') . $f(14, 'double', 194.0) . $f(31, 'string', 'image/jpeg');
$item = $f(1, 'string', 'ID1') . $f(2, 'msg', $meta) . $f(3, 'bytes', 'JPEGDATEN');
$setState = $f(3, 'msg', $f(2, 'msg', $item)) . $f(5, 'string', 'Musik') . $f(6, 'varint', 1) . $f(9, 'msg', $pfad);
$mrp = new AirPlayMrp();
$mrp->verarbeiten($f(1, 'varint', AirPlayMrp::SET_NOW_PLAYING_PLAYER) . $f(51, 'msg', $f(1, 'msg', $pfad)));
$mrp->verarbeiten($f(1, 'varint', AirPlayMrp::SET_STATE) . $f(9, 'msg', $setState));
$mrp->verarbeiten($f(1, 'varint', AirPlayMrp::VOLUME_DID_CHANGE) . $f(56, 'msg', $f(1, 'float', 0.256)));
$w = $mrp->wiedergabe();
pruefe('SetState → Titel, Interpret, Album, Zustand, App, Cover', $w['titel'] === 'Ragnarok' && $w['interpret'] === 'Ship of Odin' && $w['album'] === 'Single'
    && $w['zustand'] === 'playing' && $w['app'] === 'Apple Music' && $w['cover'] === 'JPEGDATEN' && $w['dauer'] === 194.0, json_encode(array_diff_key($w, ['cover' => 1])));
pruefe('VolumeDidChange → Lautstärke in %', $w['lautstaerke'] === 25.6);
[$befehl] = AirPlayMrp::befehl('pause');
$b = AirPlayProtobuf::decode($befehl);
pruefe('Befehl pause kodiert (Typ 1, Erweiterung 6, Command 2)', AirPlayProtobuf::int($b, 1) === 1 && AirPlayProtobuf::int(AirPlayProtobuf::msg($b, 6), 1) === 2 && AirPlayProtobuf::text($b, 2) !== null);

echo "Apple Music\n";
$seite = '<html><script type="application/json" id="serialized-server-data">' . json_encode([['data' => ['sections' => [
    ['items' => [
        ['title' => 'A', 'contentDescriptor' => ['kind' => 'song', 'identifiers' => ['storeAdamID' => '1440820524']]],
        ['contentDescriptor' => ['kind' => 'song', 'identifiers' => ['storeAdamID' => '1440820524']]],
        ['title' => 'B', 'contentDescriptor' => ['kind' => 'song', 'identifiers' => ['storeAdamID' => '1440816455']]],
        ['title' => 'Album', 'contentDescriptor' => ['kind' => 'album', 'identifiers' => ['storeAdamID' => '999']]],
    ]],
]]]]) . '</script></html>';
pruefe('Song-IDs aus Seite (Reihenfolge, ohne Doppelte, ohne Alben)', AirPlayAppleMusic::songIdsAusSeite($seite) === ['1440820524', '1440816455']);
pruefe('Song-IDs als Liste', AirPlayAppleMusic::songIds('1440820524, 1440816455 1440820524') === ['1440820524', '1440816455']);
pruefe('nur https://music.apple.com gilt als Link', AirPlayAppleMusic::istLink('https://music.apple.com/de/playlist/x/pl.u-1')
    && !AirPlayAppleMusic::istLink('http://music.apple.com/x') && !AirPlayAppleMusic::istLink('https://music.apple.com.example.org/x'));
try {
    AirPlayAppleMusic::songIds('file:///etc/passwd');
    pruefe('Fremde Eingabe abgelehnt', false);
} catch (AirPlayFehler $e) {
    pruefe('Fremde Eingabe abgelehnt', $e->vorlage === 'neither an Apple Music link nor a list of song IDs');
}
$q = AirPlayBplist::decode(AirPlayAppleMusic::warteschlange(['111', '222'], false));
pruefe('Warteschlange: PQty 3, sofort, Titel, erster Titel, Zufall aus', $q['PQty'] === 3 && $q['PQip'] === true && $q['PQst'] === ['111', '222'] && $q['PQfg'] === '111' && $q['PQts'] === 1);
$q = AirPlayBplist::decode(AirPlayAppleMusic::warteschlange(['111', '222'], true));
pruefe('Warteschlange mit Zufall', $q['PQts'] === 3 && in_array($q['PQfg'], ['111', '222'], true));
$link = 'https://music.apple.com/de/album/give-it-to-me/1440771262?i=1440771270';
pruefe('Album-Link mit Liedauswahl: Lied erkannt, ohne Auswahl null', AirPlayAppleMusic::startTitel($link) === '1440771270'
    && AirPlayAppleMusic::startTitel('https://music.apple.com/de/album/x/1440771262') === null);
pruefe('Titelliste beginnt beim ausgewählten Lied, Titel davor ans Ende', AirPlayAppleMusic::abTitel(['1', '2', '3', '4'], '3') === ['3', '4', '1', '2']
    && AirPlayAppleMusic::abTitel(['1', '2'], null) === ['1', '2'] && AirPlayAppleMusic::abTitel(['1', '2'], '9') === ['9', '1', '2']);
$q = AirPlayBplist::decode(AirPlayAppleMusic::warteschlange(['3', '4', '1', '2'], true, true));
pruefe('Zufall mit festem ersten Titel: beginnt mit dem ausgewählten Lied', $q['PQts'] === 3 && $q['PQfg'] === '3');
[$n] = AirPlayMrp::warteschlangeSetzen('PLIST');
$b = AirPlayProtobuf::msg(AirPlayProtobuf::decode($n), 6);
pruefe('SetPlaybackQueue: Befehl 48, Optionen Feld 15', AirPlayProtobuf::int($b, 1) === 48 && AirPlayProtobuf::text(AirPlayProtobuf::msg($b, 2), 15) === 'PLIST');
$mrp = new AirPlayMrp();
$mrp->verarbeiten($f(1, 'varint', AirPlayMrp::SEND_COMMAND_RESULT) . $f(2, 'string', 'X1') . $f(7, 'msg', $f(1, 'varint', 0) . $f(2, 'varint', 404)));
pruefe('Befehlsergebnis gelesen', $mrp->befehlsErgebnis('X1') === ['sendError' => 0, 'status' => 404] && $mrp->befehlsErgebnis('X2') === null);

echo "Klima-Empfang\n";
$k = AirPlayKlimaDaten::lesen('{"name": "Schlafzimmer", "temperature": "21,5 °C", "humidity": "44 %"}');
pruefe('Kurzbefehl-JSON mit Einheiten und Komma', $k === [['name' => 'Schlafzimmer', 'temperatur' => 21.5, 'feuchte' => 44.0]]);
$k = AirPlayKlimaDaten::lesen('[{"Name":"Küche","Temperatur":22.25},{"raum":"Bad","feuchte":"61"},{"name":"leer"}]');
pruefe('Liste, deutsche Schlüssel, nur ein Wert, leere Einträge übersprungen', count($k) === 2 && $k[0]['temperatur'] === 22.25 && $k[0]['feuchte'] === null && $k[1]['feuchte'] === 61.0);
$k = AirPlayKlimaDaten::lesen('{"name":"X","temperature":"-5.5","humidity":"150"}');
pruefe('Minusgrade gelten, unplausible Feuchte verworfen', $k[0]['temperatur'] === -5.5 && $k[0]['feuchte'] === null);
try {
    AirPlayKlimaDaten::lesen('kein json');
    pruefe('Kein JSON abgelehnt', false);
} catch (AirPlayFehler $e) {
    pruefe('Kein JSON abgelehnt', $e->vorlage === 'the request does not contain JSON');
}
$k = AirPlayKlimaDaten::ausAufruf(['key' => 'geheim', 'name' => 'Schlafzimmer', 'temperature' => '21,5 °C', 'humidity' => '44 %'], '');
pruefe('URL-Parameter statt JSON (Schlüssel ignoriert)', $k === [['name' => 'Schlafzimmer', 'temperatur' => 21.5, 'feuchte' => 44.0]]);
pruefe('ohne Messwert-Parameter gilt der JSON-Text', AirPlayKlimaDaten::ausAufruf(['key' => 'x'], '{"name":"A","t":1}')[0]['temperatur'] === 1.0);
$k = AirPlayKlimaDaten::lesen('{"temperature =":"22,4 °C","humidity =":"38","name =":"Schlafzimmer"}');
pruefe('Schlüssel mit „ =" vom Kurzbefehl-Editor', $k[0]['name'] === 'Schlafzimmer' && $k[0]['temperatur'] === 22.4 && $k[0]['feuchte'] === 38.0);
pruefe('Kennung aus Namen (Umlaute, Leerzeichen)', AirPlayKlimaDaten::kennung('Küche oben') === 'Kuecheoben' && AirPlayKlimaDaten::kennung('!!!') !== '');

echo "Geräteerkennung (TXT wie im Netz gesehen, Kennungen ersetzt)\n";
$faelle = [
    'HomePod 1. Gen.' => [['model=AudioAccessory1,1', 'features=0x4A7FCA00,0x3C356BD0', 'flags=0x98404', 'acl=0', 'deviceid=AA:00:00:00:00:01', 'osvers=27.2.1'], 'homepod', 'ja'],
    'HomePod mini' => [['model=AudioAccessory5,1', 'features=0x4A7FCA00,0x3C354BD0', 'flags=0x98404', 'acl=0'], 'homepod', 'ja'],
    'LG-Fernseher' => [['model=OLED77C9PLA', 'manufacturer=LG Electronics', 'features=0x7F8AD0,0x38BCB46', 'flags=0x644', 'acl=0'], 'fremd', 'nein'],
    'Apple TV 3' => [['model=AppleTV3,1', 'features=0x5A7FFFF7,0xE', 'flags=0x44'], 'appletv_alt', 'nein'],
    'Marantz' => [['model=Marantz', 'features=0x445F8A00,0x801C340', 'flags=0x404'], 'fremd', 'ungeprueft'],
    'Apple TV 4K' => [['model=AppleTV11,1', 'features=0x4A7FDFD5,0x3C155FDE', 'flags=0x18644'], 'appletv', 'nein'],
    'nur Zuhause' => [['model=AudioAccessory6,1', 'features=0x4A7FCA00,0x3C356BD0', 'flags=0x98404', 'acl=1'], 'homepod', 'nein'],
];
foreach ($faelle as $name => [$txt, $familie, $durchsage]) {
    $e = AirPlayGeraete::einordnen(AirPlayGeraete::txt($txt));
    pruefe($name, $e['familie'] === $familie && $e['durchsage'] === $durchsage, $e['familie'] . '/' . $e['durchsage'] . ($e['grund'] ? ' (' . $e['grund'] . ')' : ''));
}

echo "Audio\n";
function wav(int $rate, int $kanaele, int $bits, string $daten): string
{
    $fmt = pack('vvVVvv', 1, $kanaele, $rate, $rate * $kanaele * $bits / 8, $kanaele * $bits / 8, $bits);
    return 'RIFF' . pack('V', 4 + 8 + 16 + 8 + strlen($daten)) . 'WAVE' . 'fmt ' . pack('V', 16) . $fmt . 'data' . pack('V', strlen($daten)) . $daten;
}
$stereo = wav(44100, 2, 16, pack('v*', 0x0102, 0xFFFE, 1000, 2000));
$p = AirPlayAudio::wavZuPcm($stereo);
pruefe('44,1 kHz Stereo → Big Endian unverändert', $p['pcm'] === pack('n*', 0x0102, 0xFFFE, 1000, 2000));
$mono = wav(16000, 1, 16, str_repeat(pack('v', 1234), 16000));
$p = AirPlayAudio::wavZuPcm($mono);
pruefe('16 kHz Mono → 44,1 kHz Stereo', strlen($p['pcm']) === 44100 * 4 && substr($p['pcm'], 0, 4) === pack('nn', 1234, 1234), sprintf('%d Bytes', strlen($p['pcm'])));
$acht = wav(22050, 1, 8, str_repeat(chr(128 + 64), 100));
$p = AirPlayAudio::wavZuPcm($acht);
pruefe('8 Bit Mono', substr($p['pcm'], 0, 4) === pack('nn', 64 << 8, 64 << 8));
pruefe('MP3 erkannt', AirPlayAudio::format("ID3\x04\0\0") === 'mp3' && AirPlayAudio::format("\xFF\xFB\x90") === 'mp3');
try {
    AirPlayAudio::wavZuPcm('kein wav');
    pruefe('Nicht-WAV abgelehnt', false);
} catch (AirPlayFehler $e) {
    pruefe('Nicht-WAV abgelehnt', $e->vorlage === 'not a WAV file');
}
$laengen = array_map(fn (string $k) => strlen(AirPlayAudio::klang($k)), array_keys(AirPlayAudio::KLAENGE));
pruefe('Klänge: Länge = Dauer × 44,1 kHz × 4 Byte, Gong 1,2 s', $laengen === array_map(fn ($k) => (int)($k['dauer'] * 44100) * 4, array_values(AirPlayAudio::KLAENGE)) && $laengen[0] === (int)(1.2 * 44100) * 4);
$alarm = AirPlayAudio::klang('alarm');
$spitze = max(array_map('abs', array_map(fn ($w) => $w >= 32768 ? $w - 65536 : $w, unpack('n*', $alarm))));
pruefe('Klänge ohne Übersteuerung', $spitze < 32767 && $spitze > 5000, (string)$spitze);
try {
    AirPlayAudio::klang('hupe');
    pruefe('unbekannter Klang abgelehnt', false);
} catch (AirPlayFehler $e) {
    pruefe('unbekannter Klang abgelehnt', $e->vorlage === 'unknown sound %s');
}
pruefe('Lautstärke in dB', AirPlayAudioStrom::lautstaerkeDb(30) === -21.0 && AirPlayAudioStrom::lautstaerkeDb(0) === -144.0 && AirPlayAudioStrom::lautstaerkeDb(100) === 0.0);

echo "Store-Regeln (statisch)\n";
$lib = json_decode((string)file_get_contents($basis . '/library.json'), true);
$felder = array_keys($lib);
sort($felder);
pruefe('library.json genau 8 Felder', $felder === ['author', 'build', 'compatibility', 'date', 'id', 'name', 'url', 'version'] && isset($lib['compatibility']['version']));
foreach (array_map('basename', array_map('dirname', glob("$basis/*/module.json"))) as $m) {
    // Modulcode = module.php plus die Traits, die es aus libs/ einbindet
    $code = (string)file_get_contents("$basis/$m/module.php");
    preg_match_all("/libs\/($m\w+)\.php/", $code, $traits);
    foreach ($traits[1] as $t) {
        $code .= (string)file_get_contents("$basis/libs/$t.php");
    }
    pruefe("$m: kein IPS_LogMessage", !str_contains($code, 'IPS_LogMessage'));
    pruefe("$m: keine Selbstpersistenz (IPS_SetProperty/ApplyChanges auf die eigene Instanz)", !preg_match('/IPS_(SetProperty|ApplyChanges)\(\$this->InstanceID/', $code));
    pruefe("$m: locale.json = libs/locale.json", file_get_contents("$basis/$m/locale.json") === file_get_contents("$basis/libs/locale.json"));
    $mj = json_decode((string)file_get_contents("$basis/$m/module.json"), true);
    pruefe("$m: vendor Apple, Präfix gesetzt", $mj['vendor'] === 'Apple' && in_array($mj['prefix'], ['AIRP', 'AIRPC', 'AIRPK', 'AIRPS'], true));
}
echo "RTSP\n";
$server = stream_socket_server('tcp://127.0.0.1:0');
$port = (int)substr((string)stream_socket_get_name($server, false), strrpos((string)stream_socket_get_name($server, false), ':') + 1);
$rtsp = new AirPlayRtsp(new AirPlayVerbindung('127.0.0.1', $port));
$gegenueber = stream_socket_accept($server, 2);
// liegen gebliebene Antwort auf /feedback (CSeq 0) vor der eigentlichen Antwort (CSeq 1)
fwrite($gegenueber, "RTSP/1.0 200 OK\r\nCSeq: 0\r\nContent-Length: 0\r\n\r\n" . "RTSP/1.0 200 OK\r\nCSeq: 1\r\nContent-Length: 5\r\n\r\nrecht");
$rtsp->sendenOhneAntwort('POST', '/feedback');
$antwort = $rtsp->austausch('GET_PARAMETER');
pruefe('RTSP-Antwort per CSeq zugeordnet (fremde Antwort übersprungen)', $antwort['koerper'] === 'recht' && $antwort['kopf']['cseq'] === '1');
fclose($gegenueber);
// verschlüsselte Rahmen (je 1 KB), in zwei ungeraden Stücken angeliefert
$schluesselA = str_repeat('a', 32);
$schluesselB = str_repeat('b', 32);
$v = new AirPlayVerbindung('127.0.0.1', $port);
$gegenueber = stream_socket_accept($server, 2);
$v->verschluesseln($schluesselA, $schluesselB);
$sender = new AirPlayChaCha($schluesselB, $schluesselA);
$klar = random_bytes(5000);
$roh = '';
foreach (str_split($klar, 1024) as $block) {
    $laenge = pack('v', strlen($block));
    $roh .= $laenge . $sender->encrypt($block, $laenge);
}
foreach ([substr($roh, 0, 1500), substr($roh, 1500)] as $stueck) {
    fwrite($gegenueber, $stueck);
    usleep(20000);
    $v->einlesen();
}
pruefe('verschlüsselte Rahmen über Stückgrenzen entschlüsselt', $v->klarAbholen() === $klar);
fclose($gegenueber);

echo "Piper (Wyoming)\n";
pruefe('Adresse host / host:port / [IPv6]:port / IPv6',
    AirPlayPiper::adresse('nas') === ['nas', 10200] && AirPlayPiper::adresse('192.168.2.5:10300') === ['192.168.2.5', 10300]
    && AirPlayPiper::adresse('[fe80::1]:10201') === ['fe80::1', 10201] && AirPlayPiper::adresse('fe80::1') === ['fe80::1', 10200]);
$a = AirPlayAudio::wavZuPcm(AirPlayPiper::wavKopf(22050, 2, 1, str_repeat("\x00\x10", 22050)));
pruefe('WAV-Kopf aus Piper-PCM lesbar', abs($a['sekunden'] - 1.0) < 0.01, $a['quelle']);
$attrappe = proc_open(['php', __DIR__ . '/wyoming-attrappe.php', '10299', '3'], [1 => ['pipe', 'w']], $rohre);
fgets($rohre[1]);
try {
    $p = new AirPlayPiper('127.0.0.1:10299', 5.0);
    $st = $p->stimmen();
    pruefe('Stimmen (nur installierte)', count($st) === 1 && $st[0]['name'] === 'de_DE-thorsten-medium');
    $wav = $p->wav("  Es ist 14 Uhr.\n ");
    $a = AirPlayAudio::wavZuPcm($wav);
    pruefe('Sprechen: Ton aus mehreren Stücken, Text bereinigt', strlen($wav) === 44 + mb_strlen("Es ist 14 Uhr.") * 100 * 2, sprintf('%d Bytes, %s', strlen($wav), $a['quelle']));
    try {
        $p->wav('x', 'falsch');
        pruefe('Fehlermeldung des Servers', false);
    } catch (AirPlayFehler $e) {
        pruefe('Fehlermeldung des Servers', $e->getMessage() === 'Piper reports: unknown voice');
    }
} catch (Throwable $e) {
    pruefe('Piper-Attrappe', false, $e->getMessage());
} finally {
    proc_terminate($attrappe);
}
try {
    (new AirPlayPiper(''))->wav('x');
    pruefe('ohne Server klare Meldung', false);
} catch (AirPlayFehler $e) {
    pruefe('ohne Server klare Meldung', $e->vorlage === 'no Piper server set');
}

echo "Geplante Ansagen\n";
date_default_timezone_set('Europe/Berlin');
$sa = mktime(14, 0, 0, 11, 21, 2026);   // Samstag, 21.11.2026 14:00
$stuendlich = ['Active' => true, 'When' => 'hourly', 'Days' => 'all', 'From' => 7, 'To' => 22, 'Text' => 'Es ist {uhrzeit}.'];
pruefe('stündlich: volle Stunde im Fenster', AirPlayZeitplan::passt($stuendlich, $sa) && !AirPlayZeitplan::passt($stuendlich, $sa + 1800));
pruefe('stündlich: außerhalb des Fensters still', !AirPlayZeitplan::passt($stuendlich, mktime(23, 0, 0, 11, 21, 2026)) && AirPlayZeitplan::passt($stuendlich, mktime(22, 0, 0, 11, 21, 2026)));
$nacht = ['From' => 22, 'To' => 6] + $stuendlich;
pruefe('Fenster über Mitternacht', AirPlayZeitplan::passt($nacht, mktime(3, 0, 0, 11, 21, 2026)) && !AirPlayZeitplan::passt($nacht, $sa));
pruefe('halbstündlich', AirPlayZeitplan::passt(['When' => 'halfhourly'] + $stuendlich, $sa + 1800));
$werktags = ['When' => 'daily', 'Time' => '7:30', 'Days' => 'weekdays'] + $stuendlich;
pruefe('täglich Mo–Fr: Samstag still, nächster Termin Montag 07:30', !AirPlayZeitplan::passt($werktags, mktime(7, 30, 0, 11, 21, 2026))
    && AirPlayZeitplan::naechste([$werktags], $sa) === mktime(7, 30, 0, 11, 23, 2026));
$gueltig = AirPlayZeitplan::eintraege([['Active' => false] + $stuendlich, ['Text' => ' '] + $stuendlich, $stuendlich], [
    ['Active' => true, 'Time' => '06:30', 'Days' => 'all', 'Link' => ''], ['Active' => true, 'Time' => '06:30', 'Days' => 'all', 'Link' => 'Morgen'],
]);
pruefe('eintraege: aus / ohne Text / ohne Link fallen weg, Art und täglich gesetzt', count($gueltig) === 2 && $gueltig[0]['Art'] === 'ansage'
    && $gueltig[1]['Art'] === 'wecker' && $gueltig[1]['When'] === 'daily' && AirPlayZeitplan::passt($gueltig[1], mktime(6, 30, 0, 11, 21, 2026)));
pruefe('falsche Uhrzeit zählt nicht', AirPlayZeitplan::uhrzeit('25:00') === null
    && AirPlayZeitplan::naechste([['When' => 'daily', 'Time' => 'x'] + $stuendlich], $sa) === null);
pruefe('nächster Termin: laufende Minute nur exakt auf :00', AirPlayZeitplan::naechste([$stuendlich], $sa) === $sa
    && AirPlayZeitplan::naechste([$stuendlich], $sa + 1) === $sa + 3600);
pruefe('fällig: nur passende Einträge', count(AirPlayZeitplan::faellig([$stuendlich, $werktags, 'kaputt'], $sa)) === 1);
$text = AirPlayPlatzhalter::ersetzen('{uhrzeit}|{time}|{Wochentag}|{datum}|{var:42}|{var:7}|{unbekannt}', $sa + 1800, fn (int $id) => $id === 42 ? '21,5 °C' : null);
pruefe('Platzhalter', $text === '14:30|14:30|Saturday|21 November|21,5 °C|{var:7}|{unbekannt}', $text);
pruefe('volle Stunde ohne Minuten', AirPlayPlatzhalter::ersetzen('{uhrzeit}', $sa, fn ($i) => null) === "14 o'clock");

echo "Warteschlange (AIRP_SayAsync)\n";
$q = [];
for ($n = 1; $n <= AirPlayWarteschlange::MAX; $n++) {
    $q = AirPlayWarteschlange::anhaengen($q, "Text $n", 150, $n === 1, 1000 + $n);
}
pruefe('einreihen: Lautstärke begrenzt, Gong gemerkt', $q[0]['volume'] === 100 && $q[0]['chime'] === true && $q[1]['chime'] === false);
try {
    AirPlayWarteschlange::anhaengen($q, 'zu viel', -1, false, 2000);
    pruefe('höchstens MAX wartend', false);
} catch (AirPlayFehler $e) {
    pruefe('höchstens MAX wartend', $e->vorlage === '%s announcements are already waiting');
}
try {
    AirPlayWarteschlange::anhaengen([], '  ', -1, false, 2000);
    pruefe('leerer Text abgelehnt', false);
} catch (AirPlayFehler $e) {
    pruefe('leerer Text abgelehnt', true);
}
[$erster, $rest] = AirPlayWarteschlange::entnehmen($q);
pruefe('entnehmen in Reihenfolge', $erster['text'] === 'Text 1' && count($rest) === AirPlayWarteschlange::MAX - 1 && $rest[0]['text'] === 'Text 2');
pruefe('leere Schlange', AirPlayWarteschlange::entnehmen([]) === [null, []]);
pruefe('Verfall nach 10 Minuten', !AirPlayWarteschlange::verfallen(['time' => 1000], 1600) && AirPlayWarteschlange::verfallen(['time' => 1000], 1601));

echo "Sprachdienste\n";
$k1 = AirPlayStimmen::speicherKennung('azure', 'de-DE-KatjaNeural', "Es ist  14 Uhr.\n");
pruefe('Zwischenspeicher: gleicher Text (bereinigt) = gleiche Kennung, andere Stimme/Dienst = andere',
    $k1 === AirPlayStimmen::speicherKennung('azure', 'de-DE-KatjaNeural', 'Es ist 14 Uhr.')
    && $k1 !== AirPlayStimmen::speicherKennung('azure', 'de-DE-ConradNeural', 'Es ist 14 Uhr.')
    && $k1 !== AirPlayStimmen::speicherKennung('google', 'de-DE-KatjaNeural', 'Es ist 14 Uhr.') && strlen($k1) === 24);
$fehlt = function (array $e): string {
    try {
        AirPlayStimmen::dienst($e)->wav('x');
        return '';
    } catch (AirPlayFehler $f) {
        return $f->vorlage;
    }
};
pruefe('ohne Schlüssel klare Meldung (Azure, ElevenLabs, Google)', $fehlt(['dienst' => 'azure', 'stimme' => '', 'region' => 'westeurope']) === 'no key saved for %s'
    && $fehlt(['dienst' => 'elevenlabs', 'stimme' => '']) === 'no key saved for %s' && $fehlt(['dienst' => 'google', 'stimme' => '']) === 'no key saved for %s');
pruefe('Azure-Region wird geprüft', $fehlt(['dienst' => 'azure', 'stimme' => '', 'schluessel' => 'k', 'region' => 'west europe']) === 'Azure region missing or invalid (e.g. germanywestcentral, westeurope)');
pruefe('ElevenLabs verlangt eine Stimmen-ID (ohne Netz)', str_starts_with($fehlt(['dienst' => 'elevenlabs', 'stimme' => 'Rachel!', 'schluessel' => 'k']), 'ElevenLabs needs a voice ID'));
pruefe('unbekannter Dienst', $fehlt(['dienst' => 'siri', 'stimme' => '']) === 'unknown speech service %s');
pruefe('HTTP-Fehler: 401 Azure mit Region, 401 sonst, 429, Text aus JSON',
    AirPlayStimmen::httpFehler('Azure', 401, '')->vorlage === '%s rejects the key (HTTP %s) — check key and region'
    && AirPlayStimmen::httpFehler('Google', 403, '')->vorlage === '%s rejects the key (HTTP %s)'
    && AirPlayStimmen::httpFehler('ElevenLabs', 429, '')->vorlage === '%s: quota or request limit reached (HTTP 429)'
    && AirPlayStimmen::httpFehler('Google', 400, '{"error":{"message":"API key not valid."}}')->getMessage() === 'Google answers HTTP 400: API key not valid.');
pruefe('WAV-Erkennung', AirPlayStimmen::istWav(AirPlayPiper::wavKopf(24000, 2, 1, str_repeat("\0", 100))) && !AirPlayStimmen::istWav('{"detail":"x"}'));

echo "Lautstärke, Protokoll, Gruppen\n";
$nacht = ['aktiv' => true, 'max' => 15, 'von' => 22, 'bis' => 7];
$um = fn (int $h) => mktime($h, 0, 0, 11, 21, 2026);
pruefe('Nachtgrenze: nachts begrenzt, unverändert → Maximum, tagsüber frei, aus = frei',
    AirPlayLautstaerke::nachtgrenze(40.0, $nacht, $um(23)) === 15.0 && AirPlayLautstaerke::nachtgrenze(10.0, $nacht, $um(3)) === 10.0
    && AirPlayLautstaerke::nachtgrenze(null, $nacht, $um(6)) === 15.0 && AirPlayLautstaerke::nachtgrenze(40.0, $nacht, $um(12)) === 40.0
    && AirPlayLautstaerke::nachtgrenze(40.0, ['aktiv' => false] + $nacht, $um(23)) === 40.0);
pruefe('Rampe: Anfang, Mitte, Ende, danach, ohne Dauer',
    AirPlayLautstaerke::rampe(5, 30, 600, 0) === 5.0 && AirPlayLautstaerke::rampe(5, 30, 600, 300) === 18.0
    && AirPlayLautstaerke::rampe(5, 30, 600, 600) === 30.0 && AirPlayLautstaerke::rampe(5, 30, 600, 9999) === 30.0 && AirPlayLautstaerke::rampe(5, 30, 0, 0) === 30.0);
$p = [];
for ($n = 1; $n <= 25; $n++) {
    $p = AirPlayProtokoll::eintragen($p, ['zeit' => $n, 'quelle' => 'script', 'text' => str_repeat('x', 200), 'ok' => true, 'info' => '']);
}
pruefe('Protokoll: neueste zuerst, höchstens 20, Text gekürzt', count($p) === AirPlayProtokoll::MAX && $p[0]['zeit'] === 25 && mb_strlen($p[0]['text']) === 120);
pruefe('Gruppen: leer = alle, ohne Groß-/Kleinschreibung, Leerzeichen egal, keine Teilwörter',
    AirPlayGruppe::enthaelt('', '') && AirPlayGruppe::enthaelt('Erdgeschoss, Küche', ' küche ') && !AirPlayGruppe::enthaelt('Küchenzeile', 'Küche') && !AirPlayGruppe::enthaelt('', 'Bad'));

echo "Symcon-Adresse für den Kurzbefehl\n";
$netz = fn (string $name, string $ip) => ['Description' => $name, 'IP' => $ip];
pruefe('WLAN zuerst gelistet, Kabel gewinnt (Fall 22.11.2026: WLAN .8 fiel weg)', AirPlayNetz::symconAdresse([$netz('wlan0', '192.168.2.8'), $netz('br0', '192.168.2.7')]) === '192.168.2.7');
pruefe('Docker/libvirt/Loopback werden übergangen (Liste wie live)', AirPlayNetz::symconAdresse([
    $netz('lo', '127.0.0.1'), $netz('docker0', '172.17.0.1'), $netz('br-ad3ff5560f22', '172.24.0.1'), $netz('virbr0', '192.168.122.1'), $netz('br0', '192.168.2.7'),
]) === '192.168.2.7');
pruefe('nur WLAN: WLAN wird genommen; Windows-Namen', AirPlayNetz::symconAdresse([$netz('Wi-Fi', '10.0.0.5')]) === '10.0.0.5'
    && AirPlayNetz::symconAdresse([$netz('WLAN', '10.0.0.5'), $netz('Ethernet', '10.0.0.6')]) === '10.0.0.6');
pruefe('keine taugliche Adresse: null (VPN, Link-Local, IPv6)', AirPlayNetz::symconAdresse([$netz('tailscale0', '100.64.0.2'), $netz('eth0', '169.254.1.2'), $netz('eth1', 'fe80::1')]) === null);

exec('php ' . escapeshellarg(__DIR__ . '/texte-sammeln.php'), $aus, $rc);
pruefe('alle Texte in locale.json', $rc === 0, implode(' ', $aus));

// ------------------------------------------------------------------ live
$i = array_search('--live', $argv, true);
$k = array_search('--koppeln', $argv, true);
if ($i !== false || $k !== false) {
    $host = $argv[($i !== false ? $i : $k) + 1] ?? '';
    echo "Live gegen $host\n";
    $sitzung = new AirPlaySitzung($host, fn (string $t) => print("        · $t\n"));
    try {
        $info = $sitzung->info();
        $sitzung->koppeln();
        $info2 = $sitzung->info();
        pruefe('Kopplung und verschlüsseltes /info', ($info2['name'] ?? '') !== '' && ($info2['name'] ?? '') === ($info['name'] ?? ''), (string)($info2['name'] ?? ''));
        if ($i !== false) {
            $sitzung->audioEinrichten();
            $r = $sitzung->abspielen(AirPlayAudio::klang('gong'), 25.0);
            pruefe('Gong gestreamt', $r['verspaetet'] < 10, sprintf('%d Pakete, %.2f s, %d verspätet', $r['pakete'], $r['dauer'], $r['verspaetet']));
        }
    } catch (Throwable $e) {
        pruefe('Live', false, $e->getMessage());
    } finally {
        $sitzung->schliessen();
    }
}

echo "\n$anzahl Prüfungen, $fehler Fehler\n";
exit($fehler === 0 ? 0 : 1);
