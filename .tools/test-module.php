<?php
/**
 * Modulprüfstand: AirPlay-Gerät und AirPlay-Sprachausgabe in der Symcon-Attrappe (ohne Symcon, ohne HomePod).
 * Sprache kommt von der Wyoming-Attrappe; das „Gerät" ist ein geschlossener Port auf 127.0.0.1, sodass jede
 * Durchsage bis zum Netzwerk durchläuft und dort sauber scheitert.
 *   php .tools/test-module.php
 */
declare(strict_types=1);

require __DIR__ . '/symcon-attrappe.php';
$basis = dirname(__DIR__);
require $basis . '/AirPlayGeraet/module.php';
require $basis . '/AirPlaySprache/module.php';
require $basis . '/AirPlayKlima/module.php';
Attrappe::$module = ['AirPlayGeraet' => '{2BB8B63A-9A5A-4704-8230-C18259F9F3E7}', 'AirPlaySprache' => '{1995F9C5-57BE-4CCB-8980-07D0D4E1867E}'];

// Funktionen, die Symcon aus den öffentlichen Methoden erzeugt
final class AirPlayVisuAttrappe extends IPSModule
{
    public static array $mitteilungen = [];
}
final class AirPlayWebHookAttrappe extends IPSModule
{
}
function VISU_PostNotificationEx($id, $titel, $text, $icon, $ton, $ziel) { AirPlayVisuAttrappe::$mitteilungen[] = [$id, $titel, $text, $icon, $ton, $ziel]; return true; }
function AIRP_SayAsync($id, $text, $vol, $gong) { return Attrappe::$instanzen[$id]->SayAsync($text, $vol, $gong); }
function AIRPS_GetSpeech($id, $text) { return Attrappe::$instanzen[$id]->GetSpeech($text); }
function AIRPS_GetLastError($id) { return Attrappe::$instanzen[$id]->GetLastError(); }

$fehler = 0;
$anzahl = 0;
function pruefe(string $name, bool $ok, string $info = ''): void
{
    global $fehler, $anzahl;
    $anzahl++;
    $fehler += $ok ? 0 : 1;
    echo ($ok ? '  ok    ' : '  FEHLER ') . $name . ($info !== '' ? ' — ' . $info : '') . "\n";
}
function privat(object $o, string $methode, mixed ...$args): mixed
{
    $m = new ReflectionMethod($o, $methode);
    return $m->invoke($o, ...$args);
}

$attrappe = proc_open(['php', __DIR__ . '/wyoming-attrappe.php', '10298', '20'], [1 => ['pipe', 'w']], $rohre);
fgets($rohre[1]);

echo "Sprachausgabe\n";
$s = new AirPlaySprache(100);
$s->Create();
$s->setzen('PiperServer', '127.0.0.1:10298');
$s->ApplyChanges();
pruefe('Piper mit Server: Status aktiv', $s->status === IS_ACTIVE);
$wav = base64_decode($s->GetSpeech('Es ist 14 Uhr.'));
$s->GetSpeech("Es ist  14 Uhr.\n");
$medien = array_filter(Attrappe::$objekte, fn ($o) => $o['typ'] === 'media' && $o['parent'] === 100);
pruefe('Sprache erzeugt und zwischengespeichert (gleicher Text nur einmal)', str_starts_with($wav, 'RIFF') && count($medien) === 1 && current($medien)['versteckt']);
pruefe('Fehler landet in GetLastError', $s->GetSpeech('   ') === '' && $s->GetLastError() !== '');
$s->setzen('Service', 'azure');
$s->ApplyChanges();
pruefe('Online-Dienst ohne Schlüssel: Status 201', $s->status === 201);
$s->setzen('Service', 'piper');
$s->ApplyChanges();
$form = json_decode($s->GetConfigurationForm(), true);
pruefe('Formular der Sprachausgabe ist gültiges JSON', is_array($form) && count($form['elements']) >= 4);

echo "Gerät\n";
$g = new AirPlayGeraet(200);
$g->Create();
$g->ApplyChanges();
pruefe('ohne Adresse: Status 201, keine Timer', $g->status === 201 && $g->timer['Update'] === 0 && $g->timer['Schedule'] === 0);
$g->setzen('Host', '127.0.0.1');
$g->setzen('SpeechInstance', 100);
$g->setzen('Groups', 'Erdgeschoss, Küche');
$g->setzen('Schedule', json_encode([['Active' => true, 'When' => 'hourly', 'Days' => 'all', 'From' => 0, 'To' => 23, 'Text' => 'Es ist {uhrzeit}.', 'Volume' => -1, 'Sound' => 'gong']]));
$g->setzen('Favorites', json_encode([['Name' => 'Morgen', 'Link' => 'https://music.apple.com/de/playlist/x/pl.u-abc', 'Shuffle' => true]]));
$g->ApplyChanges();
$naechste = $g->ReadAttributeInteger('ScheduleNext');
pruefe('mit Adresse: Variablen, Abfrage-Timer, nächste volle Stunde geplant',
    $g->status === IS_ACTIVE && $g->timer['Update'] === 15000 && Attrappe::kind(200, 'Titel') !== false && Attrappe::kind(200, 'Cover') !== false
    && (int)date('i', $naechste) === 0 && $naechste > time() && $naechste - time() <= 3600);
$cover = Attrappe::kind(200, 'Cover');
pruefe('Cover-Medium hat sofort eine Datei (Platzhalter), Kachel zeigt trotzdem kein Bild',
    IPS_GetMedia($cover)['MediaIsAvailable'] && privat($g, 'kachelDaten', false, true)['cover'] === '');
Attrappe::$objekte[$cover]['wert'] = '';   // Instanz aus 0.11.5: Medium ohne Datei
$g->ApplyChanges();
pruefe('Ältere Instanz ohne Cover-Datei bekommt beim Übernehmen den Platzhalter', IPS_GetMedia($cover)['MediaIsAvailable']);
$form = json_decode($g->GetConfigurationForm(), true);
$panels = array_column(array_filter($form['elements'], fn ($e) => ($e['type'] ?? '') === 'ExpansionPanel'), 'caption');
pruefe('Formular: alle Fachpanels', count(array_intersect(['🔌  Connection & display', '📢  Announcements', '🗣️  Speech output', '⏰  Scheduled announcements', '⏰  Alarm clock', '🎵  Apple Music', '📜  Announcement log'], $panels)) === 7);
$kachel = $g->GetVisualizationTile();
pruefe('Kachel: HTML mit Startdaten, Texte und Favoriten', str_contains($kachel, 'function handleMessage') && str_contains($kachel, '"favoriten":["Morgen"]') && str_contains($kachel, '"texte"'));

$regel = fn (bool $aktiv) => [$g->setzen('NightLimit', $aktiv), $g->setzen('NightFrom', 0), $g->setzen('NightTo', 23), $g->setzen('NightVolume', 15)];
$regel(false);
pruefe('Lautstärke: Instanz 30 %, Wunsch 50 %', privat($g, 'durchsageLautstaerke', -1) === 30.0 && privat($g, 'durchsageLautstaerke', 50) === 50.0);
$regel(true);
pruefe('Nachtgrenze ganztags 15 %: 50 → 15, 10 bleibt 10', privat($g, 'durchsageLautstaerke', 50) === 15.0 && privat($g, 'durchsageLautstaerke', 10) === 10.0);
$g->setzen('NightLimit', false);

pruefe('SayAsync reiht ein und stellt den Timer', $g->SayAsync('Hallo {wochentag}', -1, true) && $g->timer['Queue'] === 100 && count(json_decode($g->ReadAttributeString('Queue'), true)) === 1);
$g->RequestAction('RunQueue', 0);
$protokoll = json_decode($g->ReadAttributeString('History'), true);
pruefe('Warteschlange abgearbeitet: Platzhalter ersetzt, Gerät nicht erreichbar → Protokoll mit Fehler',
    json_decode($g->ReadAttributeString('Queue'), true) === [] && $g->timer['Queue'] === 0 && $protokoll[0]['quelle'] === 'queue'
    && !str_contains($protokoll[0]['text'], '{') && $protokoll[0]['ok'] === false, $protokoll[0]['text'] . ' / ' . $protokoll[0]['info']);
$g->setzen('SpeechInstance', 0);
pruefe('SayAsync ohne Sprachausgabe: false', !$g->SayAsync('x', -1, false));
$g->setzen('SpeechInstance', 100);

$tile = count($g->kachel);
$g->RequestAction('Say', 'Von der Kachel');
pruefe('Kachel-Aktion „Say" landet in der Warteschlange', count(json_decode($g->ReadAttributeString('Queue'), true)) === 1);
$g->RequestAction('RunQueue', 0);
pruefe('Kachel zeigt die Meldung der letzten Durchsage', $g->kachel !== [] && str_contains(json_decode(end($g->kachel), true)['meldung'], 'Announcement failed'));
$vorher = count($g->kachel);
privat($g, 'kachelAktualisieren');
privat($g, 'kachelAktualisieren');
pruefe('Kachel wird nur bei geändertem Inhalt gesendet', count($g->kachel) === $vorher);
try {
    $g->RequestAction('Unsinn', 0);
    pruefe('unbekannter Ident wird abgelehnt', false);
} catch (Exception $e) {
    pruefe('unbekannter Ident wird abgelehnt', $e->getMessage() === 'Invalid Ident');
}
pruefe('Favoritenname wird zum Link', privat($g, 'favoritLink', 'morgen') === 'https://music.apple.com/de/playlist/x/pl.u-abc' && privat($g, 'favoritLink', 'pl.x') === 'pl.x');

echo "Gruppen\n";
$g2 = new AirPlayGeraet(300);
$g2->Create();
$g2->setzen('Host', '127.0.0.1');
$g2->setzen('SpeechInstance', 100);
$g2->setzen('Groups', 'Obergeschoss');
$g2->ApplyChanges();
$queue = fn (AirPlayGeraet $x) => count(json_decode($x->ReadAttributeString('Queue'), true));
pruefe('Gruppe „küche" trifft nur Gerät 1', $s->SayGroup('küche', 'Essen ist fertig', -1, true) === 1 && $queue($g) === 1 && $queue($g2) === 0);
pruefe('leere Gruppe trifft alle Geräte dieser Sprachausgabe', $s->SayGroup('', 'Alle', -1, false) === 2 && $queue($g2) === 1);
pruefe('unbekannte Gruppe: 0 und Log-Hinweis', $s->SayGroup('Garage', 'x', -1, false) === 0 && str_contains(end($s->log), 'Garage'));

echo "Wecker\n";
$g->setzen('Alarms', json_encode([['Active' => true, 'Time' => '06:30', 'Days' => 'all', 'Link' => 'Morgen', 'Shuffle' => true, 'StartVolume' => 5, 'EndVolume' => 30, 'Minutes' => 10, 'Text' => '']]));
$termine = privat($g, 'termine');
pruefe('Wecker erscheint im gemeinsamen Zeitplan', count($termine) === 2 && $termine[1]['Art'] === 'wecker' && $termine[1]['When'] === 'daily');
privat($g, 'weckerStarten', $termine[1]);
$protokoll = json_decode($g->ReadAttributeString('History'), true);
pruefe('Wecker ohne erreichbares Gerät: kein Anstieg, Fehler im Protokoll', $g->timer['Ramp'] === 0 && $protokoll[0]['quelle'] === 'alarm' && !$protokoll[0]['ok']);
$g->WriteAttributeString('Ramp', json_encode(['start' => 5, 'ziel' => 30, 'beginn' => time() - 60, 'dauer' => 600, 'text' => '']));
Attrappe::$objekte[Attrappe::kind(200, 'Zustand')]['wert'] = 2;   // pausiert
$g->RequestAction('RunRamp', 0);
pruefe('Anstieg endet, wenn die Musik angehalten wurde', $g->ReadAttributeString('Ramp') === '' && $g->timer['Ramp'] === 0);

echo "Anwesenheit\n";
pruefe('ohne Anwesenheitsvariable wird nichts ausgelassen', !privat($g, 'wegenAbwesenheitAuslassen', 'schedule', 'x'));
$anwesend = Attrappe::neu('var', 0, '', false);
$g->setzen('PresenceVariable', $anwesend);
pruefe('niemand da: Ansage und Wecker ausgelassen, Skript/Formular/Kachel nicht, Warteschlange nur auf Wunsch',
    privat($g, 'wegenAbwesenheitAuslassen', 'schedule', 'x') && privat($g, 'wegenAbwesenheitAuslassen', 'alarm', 'x')
    && !privat($g, 'wegenAbwesenheitAuslassen', 'script', 'x') && !privat($g, 'wegenAbwesenheitAuslassen', 'form', 'x')
    && !privat($g, 'wegenAbwesenheitAuslassen', 'tile', 'x') && !privat($g, 'wegenAbwesenheitAuslassen', 'queue', 'x'));
$g->setzen('PresenceQueue', true);
$g->WriteAttributeString('Queue', '[]');
$g->SayAsync('Unwetterwarnung', -1, false);
$g->RequestAction('RunQueue', 0);
$protokoll = json_decode($g->ReadAttributeString('History'), true);
pruefe('Warteschlange bei Abwesenheit: ausgelassen und als ausgelassen protokolliert',
    $protokoll[0]['text'] === 'Unwetterwarnung' && $protokoll[0]['skip'] === true && $protokoll[0]['info'] === 'skipped: nobody at home');
pruefe('Protokoll zeigt ausgelassene Durchsage mit Pausenzeichen', str_contains(json_encode(json_decode($g->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE), '⏸️ skipped: nobody at home'));
Attrappe::$objekte[$anwesend]['wert'] = 1;
pruefe('jemand da (Wert > 0): nichts ausgelassen', !privat($g, 'wegenAbwesenheitAuslassen', 'queue', 'x') && !privat($g, 'wegenAbwesenheitAuslassen', 'schedule', 'x'));
unset(Attrappe::$objekte[$anwesend]);
pruefe('gelöschte Anwesenheitsvariable: nichts ausgelassen', !privat($g, 'wegenAbwesenheitAuslassen', 'schedule', 'x'));
$g->setzen('PresenceVariable', 0);
$g->setzen('PresenceQueue', false);

echo "Rückmeldeweg\n";
$forum = fn () => substr_count($g->GetConfigurationForm(), '145020');
pruefe('Forum-Thread im Hinweis und im Doku-Panel', $forum() === 2 && str_contains($g->GetConfigurationForm(), 'FeedbackHintPanel'));
$g->AckFeedbackHint();
pruefe('Hinweis weggeklickt: Forum-Thread bleibt im Doku-Panel erreichbar', $forum() === 1 && !str_contains($g->GetConfigurationForm(), 'FeedbackHintPanel'));

echo "Kachel: nächster Termin und Raumklima\n";
$g->setzen('Alarms', '[]');
$g->ApplyChanges();
$daten = privat($g, 'kachelDaten', false, false);
pruefe('nächster Termin: Ansage zur vollen Stunde, Platzhalter für diesen Zeitpunkt ersetzt',
    $daten['naechste']['zeit'] === $g->ReadAttributeInteger('ScheduleNext') && $daten['naechste']['wecker'] === false
    && !str_contains($daten['naechste']['text'], '{') && str_contains($daten['naechste']['text'], date('G', $daten['naechste']['zeit'])),
    json_encode($daten['naechste'], JSON_UNESCAPED_UNICODE));
$g->setzen('Schedule', '[]');
$g->setzen('Alarms', json_encode([['Active' => true, 'Time' => '06:30', 'Days' => 'all', 'Link' => 'Morgen', 'Text' => '']]));
$g->ApplyChanges();
$daten = privat($g, 'kachelDaten', false, false);
pruefe('nächster Termin: Wecker mit Favoritenname', $daten['naechste']['wecker'] === true && $daten['naechste']['text'] === 'Alarm clock · Morgen' && date('H:i', $daten['naechste']['zeit']) === '06:30');
$g->setzen('Alarms', '[]');
$g->ApplyChanges();
pruefe('ohne Termin: kein Eintrag', privat($g, 'kachelDaten', false, false)['naechste'] === null);
pruefe('ohne Klima-Variablen: kein Raumklima', privat($g, 'kachelDaten', false, false)['klima'] === []);
$temp = Attrappe::neu('var', 0, '', '21,5 °C');
$feuchte = Attrappe::neu('var', 0, '', '48 %');
$g->setzen('TemperatureVariable', $temp);
$g->setzen('HumidityVariable', $feuchte);
$g->ApplyChanges();
pruefe('Raumklima: formatierte Werte, Meldungen beider Variablen angemeldet',
    privat($g, 'kachelDaten', false, false)['klima'] === ['temperatur' => '21,5 °C', 'feuchte' => '48 %']
    && ($g->meldungen[$temp] ?? []) === [VM_UPDATE] && ($g->meldungen[$feuchte] ?? []) === [VM_UPDATE]);
$vorher = count($g->kachel);
Attrappe::$objekte[$temp]['wert'] = '22,0 °C';
$g->MessageSink(time(), $temp, VM_UPDATE, []);
pruefe('neuer Klimawert: Kachel sofort aktualisiert', count($g->kachel) === $vorher + 1 && json_decode(end($g->kachel), true)['klima']['temperatur'] === '22,0 °C');
$g->setzen('HumidityVariable', 0);
$g->ApplyChanges();
pruefe('abgewählte Variable: Meldung abgemeldet', ($g->meldungen[$feuchte] ?? []) === [] && ($g->meldungen[$temp] ?? []) === [VM_UPDATE]);
$g->setzen('TemperatureVariable', 0);
$g->ApplyChanges();
pruefe('Cover-Größe: Standard klein (72 px), groß 160 px, ausgeblendet 0', privat($g, 'kachelDaten', false, false)['coverGroesse'] === 72
    && [$g->setzen('TileCover', 'large'), privat($g, 'kachelDaten', false, false)['coverGroesse']][1] === 160
    && [$g->setzen('TileCover', 'off'), privat($g, 'kachelDaten', false, false)['coverGroesse']][1] === 0);
$g->setzen('TileCover', 'small');

echo "AirPlay-Klima\n";
Attrappe::$netz = [['Description' => 'wlan0', 'IP' => '192.168.2.8'], ['Description' => 'docker0', 'IP' => '172.17.0.1'], ['Description' => 'br0', 'IP' => '192.168.2.7']];
$k = new AirPlayKlima(400);
$k->Create();
$k->ApplyChanges();
$form = json_decode($k->GetConfigurationForm(), true);
$url = '';
array_walk_recursive($form, function ($wert, $schluessel) use (&$url) {
    if ($schluessel === 'value' && is_string($wert) && str_contains($wert, '/hook/airplayklima400')) {
        $url = $wert;
    }
});
pruefe('Kurzbefehl-URL mit Kabel-Adresse statt WLAN', str_starts_with($url, 'http://192.168.2.7:3777/hook/airplayklima400?key='), $url);
$k->WriteAttributeString('Rooms', json_encode(['Schlafzimmer' => ['temperatur' => 22.0, 'feuchte' => 40.0, 'zeit' => time() - 7200]]));
Attrappe::neu('var', 400, 'TSchlafzimmer', 22.0);
$k->WriteAttributeInteger('LastReceived', time() - 7200);
$text = json_encode(json_decode($k->GetConfigurationForm(), true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
pruefe('Seit drei Abständen nichts: Formular nennt Ursache und aktuelle URL', str_contains($text, 'Most common cause') && str_contains($text, 'http://192.168.2.7:3777/hook/airplayklima400'));

$visu = new AirPlayVisuAttrappe(500);
Attrappe::$module['AirPlayVisuAttrappe'] = AirPlayPush::GUID_KACHEL;
$webhook = new AirPlayWebHookAttrappe(510);   // nur vorhanden sein: sonst Status 201 statt Ausfall-Prüfung
Attrappe::$module['AirPlayWebHookAttrappe'] = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
$k->setzen('NotifyInstance', 500);
$k->RequestAction('TriggerOff', 0);
pruefe('Ausfall: Status 202, Warnung im Meldungen-Fenster und Push an die Kachel-Visualisierung',
    $k->status === 202 && str_contains((string)end($k->log), 'No HomePod values since') && count(AirPlayVisuAttrappe::$mitteilungen) === 1
    && AirPlayVisuAttrappe::$mitteilungen[0][0] === 500 && strlen(AirPlayVisuAttrappe::$mitteilungen[0][2]) <= 256);
$k->RequestAction('TriggerOff', 0);
pruefe('Ausfall wird nur einmal gemeldet', count(AirPlayVisuAttrappe::$mitteilungen) === 1);
$k->WriteAttributeInteger('LastReceived', time());
$k->RequestAction('TriggerOff', 0);
pruefe('Werte wieder da: Status 102 und Entwarnung', $k->status === IS_ACTIVE && count(AirPlayVisuAttrappe::$mitteilungen) === 2 && str_contains(AirPlayVisuAttrappe::$mitteilungen[1][2], 'again'));
pruefe('Push-Text byte-sicher gekürzt (Umlaut nicht zerschnitten)', AirPlayPush::kuerzen(str_repeat('ä', 20), 32) === str_repeat('ä', 14) . '...' && mb_check_encoding(AirPlayPush::kuerzen(str_repeat('ä', 20), 32), 'UTF-8'));

proc_terminate($attrappe);
echo "\n$anzahl Prüfungen, $fehler Fehler\n";
exit($fehler === 0 ? 0 : 1);
