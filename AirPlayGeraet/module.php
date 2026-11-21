<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayAblauf.php';
require_once __DIR__ . '/../libs/AirPlayAppleMusic.php';
require_once __DIR__ . '/../libs/AirPlayAudio.php';
require_once __DIR__ . '/../libs/AirPlayPiper.php';
require_once __DIR__ . '/../libs/AirPlayZeitplan.php';

// ===========================================================================
// AirPlay-Gerät — ein AirPlay-Empfänger (HomePod, Apple TV, Fremdgerät).
//
// Durchsagen: WAV (Medienobjekt oder Bytes) per AirPlay 2 an das Gerät; der
// Aufruf blockiert für die Dauer der Durchsage plus 1,5–5 s. Laufende Musik
// wird danach fortgesetzt und die Lautstärke zurückgestellt.
// Fernsteuerung und Anzeige (MRP): Zustand, Titel, Interpret, Album, App,
// Cover, Lautstärke per Timer-Abfrage; Play/Pause/Weiter/Zurück, Lautstärke.
// Jede Aktion öffnet eine eigene kurze Sitzung (libs/AirPlayAblauf.php),
// eigene Sockets, Begründung in libs/AirPlayVerbindung.php.
// ===========================================================================

class AirPlayGeraet extends IPSModule
{
    use AirPlayFormular;

    private const STATUS_ADRESSE_FEHLT = 201;
    private const STATUS_PHP_FEHLT = 202;
    private const STATUS_NICHT_ERREICHBAR = 203;

    // Werte der Variable „Zustand"
    private const ZUSTAND = ['unknown' => 0, 'playing' => 1, 'paused' => 2, 'stopped' => 3, 'interrupted' => 2, 'seeking' => 1];
    // Werte der Variable „Steuerung" → MRP-Befehl
    private const STEUERUNG = [0 => 'previous', 1 => 'play', 2 => 'pause', 3 => 'next'];
    private const BEFEHL_TEXT = ['play' => 'Play', 'pause' => 'Pause', 'stop' => 'Stop', 'next' => 'Next track', 'previous' => 'Previous track'];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        AirPlayText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Model', '');
        $this->RegisterPropertyBoolean('RemoteControl', true);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('SetVolume', true);
        $this->RegisterPropertyInteger('Volume', 30);
        $this->RegisterPropertyBoolean('ResumeAfterAnnouncement', true);
        $this->RegisterPropertyString('PiperServer', '');
        $this->RegisterPropertyString('PiperVoice', '');
        $this->RegisterPropertyString('Schedule', '[]');
        $this->RegisterAttributeString('LastResult', '');
        $this->RegisterAttributeString('CoverKey', '');
        $this->RegisterAttributeBoolean('Reachable', true);
        $this->RegisterTimer('Update', 0, 'AIRP_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Schedule', 0, 'AIRP_RunSchedule($_IPS[\'TARGET\']);');
        $this->RegisterAttributeInteger('ScheduleNext', 0);
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $bereit = false;
        if ($this->fehlendeErweiterungen() !== []) {
            $this->SetStatus(self::STATUS_PHP_FEHLT);
        } elseif ($this->host() === '') {
            $this->SetStatus(self::STATUS_ADRESSE_FEHLT);
        } else {
            $this->SetStatus($this->ReadAttributeBoolean('Reachable') ? IS_ACTIVE : self::STATUS_NICHT_ERREICHBAR);
            $bereit = true;
        }
        if ($this->fernsteuerungAn()) {
            $this->variablenSicherstellen();
        }
        $sekunden = $this->fernsteuerungAn() && $bereit ? max(0, (int)$this->ReadPropertyInteger('UpdateInterval')) : 0;
        $this->SetTimerInterval('Update', $sekunden > 0 ? max(5, $sekunden) * 1000 : 0);
        $this->zeitplanStellen($bereit ? time() + 1 : null);
        $modell = (string)$this->ReadPropertyString('Model');
        $this->SetSummary(trim($this->host() . ($modell !== '' ? ' · ' . $modell : '')));
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Steuerung':
                $befehl = self::STEUERUNG[(int)$Value] ?? null;
                if ($befehl !== null) {
                    $this->befehlAusfuehren($befehl);
                }
                break;
            case 'Lautstaerke':
                $this->SetVolume((int)$Value);
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    // ------------------------------------------------------------------ öffentlich: Durchsagen

    /** Durchsage aus einem Medienobjekt (Typ Ton, WAV) mit der eingestellten Lautstärke. */
    public function AnnounceMedia(int $MediaID): bool
    {
        return $this->AnnounceMediaEx($MediaID, -1);
    }

    /** Durchsage aus einem Medienobjekt; $Volume 0–100 %, -1 = Einstellung der Instanz. */
    public function AnnounceMediaEx(int $MediaID, int $Volume): bool
    {
        if (!@IPS_MediaExists($MediaID)) {
            return $this->fehler(AirPlayText::t('Media object %s does not exist.', $MediaID));
        }
        $inhalt = base64_decode((string)IPS_GetMediaContent($MediaID), true);
        if ($inhalt === false || $inhalt === '') {
            return $this->fehler(AirPlayText::t('Media object %s is empty.', $MediaID));
        }
        return $this->AnnounceWaveEx($inhalt, $Volume);
    }

    /** Durchsage aus WAV-Bytes (z. B. Rückgabe eines TTS-Moduls). */
    public function AnnounceWave(string $Wave): bool
    {
        return $this->AnnounceWaveEx($Wave, -1);
    }

    public function AnnounceWaveEx(string $Wave, int $Volume): bool
    {
        $format = AirPlayAudio::format($Wave);
        if ($format !== 'wav') {
            return $this->fehler(AirPlayText::t('Only WAV files are supported so far (detected: %s).', $format));
        }
        try {
            $audio = AirPlayAudio::wavZuPcm($Wave);
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Audio could not be read: %s', AirPlayText::fehler($e)));
        }
        $this->SendDebug('Audio', sprintf('%s, %.1f s', $audio['quelle'], $audio['sekunden']), 0);
        return $this->durchsageAbspielen($audio['pcm'], $Volume);
    }

    /** Text vorlesen lassen (Piper-Server aus den Einstellungen) mit der eingestellten Lautstärke. */
    public function Say(string $Text): bool
    {
        return $this->SayEx($Text, -1);
    }

    /** Text vorlesen lassen; $Volume 0–100 %, -1 = Einstellung der Instanz. Platzhalter wie {uhrzeit} werden ersetzt. */
    public function SayEx(string $Text, int $Volume): bool
    {
        return $this->sprechen($Text, $Volume, false);
    }

    /** Fällige geplante Ansagen abspielen und den Timer auf die nächste stellen (vom Timer „Schedule" aufgerufen). */
    public function RunSchedule(): void
    {
        $geplant = $this->ReadAttributeInteger('ScheduleNext');
        if ($geplant <= 0 || time() < $geplant - 5) {
            $this->zeitplanStellen(time() + 1);   // zu früh oder ohne Plan geweckt: neu planen
            return;
        }
        $this->SetTimerInterval('Schedule', 0);
        foreach (AirPlayZeitplan::faellig($this->zeitplan(), $geplant) as $e) {
            if (time() - $geplant > 600) {
                $this->LogMessage(AirPlayText::t('Scheduled announcement skipped: more than 10 minutes late.'), KL_WARNING);
                break;
            }
            $this->sprechen((string)$e['Text'], (int)($e['Volume'] ?? -1), !empty($e['Chime']), $geplant);
        }
        $this->zeitplanStellen(max(time() + 1, $geplant + 60));
    }

    /** Text mit Platzhaltern sprechen lassen, auf Wunsch mit Gong davor (eine Durchsage, eine Pause der Musik). */
    private function sprechen(string $text, int $lautstaerke, bool $gong, ?int $zeit = null): bool
    {
        $text = AirPlayPlatzhalter::ersetzen($text, $zeit ?? time(), fn (int $id): ?string => @IPS_VariableExists($id) ? (string)GetValueFormatted($id) : null);
        try {
            $wav = (new AirPlayPiper($this->ReadPropertyString('PiperServer')))->wav($text, $this->ReadPropertyString('PiperVoice'));
            $audio = AirPlayAudio::wavZuPcm($wav);
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Speech output failed: %s', AirPlayText::fehler($e)));
        }
        $this->SendDebug('Sprache', $text . sprintf(' (%.1f s)', $audio['sekunden']), 0);
        return $this->durchsageAbspielen(($gong ? AirPlayAudio::gong() : '') . $audio['pcm'], $lautstaerke);
    }

    /** @return list<array> Einträge der Liste „Geplante Ansagen" */
    private function zeitplan(): array
    {
        $liste = json_decode($this->ReadPropertyString('Schedule'), true);
        return is_array($liste) ? array_values(array_filter($liste, 'is_array')) : [];
    }

    /** Timer „Schedule" auf die nächste fällige Ansage ab $ab stellen; null = aus. */
    private function zeitplanStellen(?int $ab): void
    {
        $naechste = $ab !== null ? AirPlayZeitplan::naechste($this->zeitplan(), $ab) : null;
        $this->WriteAttributeInteger('ScheduleNext', $naechste ?? 0);
        $this->SetTimerInterval('Schedule', $naechste !== null ? max(1, $naechste - time()) * 1000 : 0);
    }

    /** Kurzer Zweiklang-Gong, z. B. zum Testen. */
    public function PlayChime(): bool
    {
        return $this->durchsageAbspielen(AirPlayAudio::gong(), -1);
    }

    /** Nur koppeln und Geräteinfo lesen, ohne Ton. */
    public function TestConnection(): bool
    {
        return $this->mitSperre(function (): bool {
            $info = $this->ablauf()->info();
            $this->ergebnis(AirPlayText::t('✅ Connected to "%s" (%s, software %s).', (string)($info['name'] ?? '?'), (string)($info['model'] ?? '?'), (string)($info['osBuildVersion'] ?? $info['sourceVersion'] ?? '?')));
            return true;
        }, 'Connection failed: %s');
    }

    // ------------------------------------------------------------------ öffentlich: Fernsteuerung

    /** Zustand, Titel, Lautstärke und Cover abfragen und in die Variablen schreiben (Timer). */
    public function Update(): bool
    {
        if (!$this->fernsteuerungAn() || $this->host() === '') {
            return false;
        }
        $sperre = 'AIRP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 0)) {
            return false; // eine Durchsage oder ein Befehl läuft gerade
        }
        try {
            $w = $this->ablauf()->zustand($this->ReadAttributeString('CoverKey'));
            $this->erreichbar(true);
            $this->wiedergabeSchreiben($w);
            return true;
        } catch (Throwable $e) {
            $this->erreichbar(false, AirPlayText::fehler($e));
            return false;
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    public function Play(): bool
    {
        return $this->befehlAusfuehren('play');
    }

    public function Pause(): bool
    {
        return $this->befehlAusfuehren('pause');
    }

    public function Stop(): bool
    {
        return $this->befehlAusfuehren('stop');
    }

    public function Next(): bool
    {
        return $this->befehlAusfuehren('next');
    }

    public function Previous(): bool
    {
        return $this->befehlAusfuehren('previous');
    }

    /**
     * Apple-Music-Titel starten: Teilen-Link einer Playlist oder eines Albums (music.apple.com) oder
     * Song-IDs (durch Komma getrennt). Gespielt wird eine Kopie der Titelliste (höchstens 300 Titel).
     */
    public function PlayPlaylist(string $Link, bool $Shuffle): bool
    {
        return $this->mitSperre(function () use ($Link, $Shuffle): bool {
            $ids = AirPlayAppleMusic::songIds($Link);
            $w = $this->ablauf()->titelliste($ids, $Shuffle);
            $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
            $this->ergebnis(AirPlayText::t('✅ Apple Music started with %s songs: %s', min(count($ids), AirPlayAppleMusic::MAX_TITEL), trim($w['interpret'] . ' – ' . $w['titel'], ' –')));
            return true;
        }, 'Playlist could not be started: %s');
    }

    /** Lautstärke 0–100 % am Gerät setzen. */
    public function SetVolume(int $Volume): bool
    {
        $Volume = max(0, min(100, $Volume));
        return $this->mitSperre(function () use ($Volume): bool {
            $ist = $this->ablauf()->lautstaerke((float)$Volume);
            if ($ist !== null) {
                $this->wertSetzen('Lautstaerke', (int)round($ist));
            }
            $this->ergebnis(AirPlayText::t('✅ Volume set to %s %%.', $Volume));
            return true;
        }, 'Volume could not be set: %s');
    }

    // ------------------------------------------------------------------ Formular-Knöpfe

    public function UIChime(): void
    {
        $this->PlayChime();
        echo $this->ReadAttributeString('LastResult');
    }

    public function UITest(): void
    {
        $this->TestConnection();
        echo $this->ReadAttributeString('LastResult');
    }

    public function UISay(string $Text): void
    {
        $this->Say($Text);
        echo $this->ReadAttributeString('LastResult');
    }

    /** Stimmen des eingetragenen Piper-Servers anzeigen (Formular-Knopf). */
    public function UIVoices(string $Server): void
    {
        try {
            $stimmen = (new AirPlayPiper($Server, 10.0))->stimmen();
        } catch (Throwable $e) {
            echo AirPlayText::t('❌ %s', AirPlayText::fehler($e));
            return;
        }
        if ($stimmen === []) {
            echo AirPlayText::t('The Piper server reports no installed voice.');
            return;
        }
        echo AirPlayText::t('Installed voices (copy the name into the field "Voice"):') . "\n\n"
            . implode("\n", array_map(fn (array $s): string => '• ' . $s['name'] . ($s['sprachen'] !== '' ? ' (' . $s['sprachen'] . ')' : ''), $stimmen));
    }

    public function UIPlaylist(string $Link, bool $Shuffle): void
    {
        $this->PlayPlaylist($Link, $Shuffle);
        echo $this->ReadAttributeString('LastResult');
    }

    public function UIUpdate(): void
    {
        echo $this->Update()
            ? AirPlayText::t('✅ Updated: %s', $this->zusammenfassung())
            : AirPlayText::t('❌ Update failed — see the message log.');
    }

    // ------------------------------------------------------------------ intern: Abläufe

    private function ablauf(): AirPlayAblauf
    {
        if (($fehlt = $this->fehlendeErweiterungen()) !== []) {
            throw new AirPlayFehler('PHP extension missing: %s', implode(', ', $fehlt));
        }
        if ($this->host() === '') {
            throw new AirPlayFehler('No address entered.');
        }
        return new AirPlayAblauf($this->host(), fn (string $t) => $this->SendDebug('AirPlay', $t, 0));
    }

    /** Arbeit unter der Instanz-Sperre ausführen; Fehler als Ergebnis melden. */
    private function mitSperre(callable $arbeit, string $fehlerVorlage): bool
    {
        $sperre = 'AIRP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 15000)) {
            return $this->fehler(AirPlayText::t('An announcement is already running on this device.'));
        }
        try {
            return (bool)$arbeit();
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t($fehlerVorlage, AirPlayText::fehler($e)));
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function befehlAusfuehren(string $befehl): bool
    {
        return $this->mitSperre(function () use ($befehl): bool {
            $w = $this->ablauf()->befehl($befehl);
            $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
            $this->ergebnis(AirPlayText::t('✅ Command "%s" sent.', AirPlayText::t(self::BEFEHL_TEXT[$befehl] ?? $befehl)));
            return true;
        }, 'Command failed: %s');
    }

    private function durchsageAbspielen(string $pcm, int $lautstaerke): bool
    {
        if ($lautstaerke < 0) {
            $wert = $this->ReadPropertyBoolean('SetVolume') ? (float)$this->ReadPropertyInteger('Volume') : null;
        } else {
            $wert = (float)min(100, $lautstaerke);
        }
        $fortsetzen = $this->fernsteuerungAn() && $this->ReadPropertyBoolean('ResumeAfterAnnouncement');
        return $this->mitSperre(function () use ($pcm, $wert, $fortsetzen): bool {
            $r = $this->ablauf()->durchsage($pcm, $wert, $fortsetzen);
            $sekunden = number_format(strlen($pcm) / 4 / AirPlayAudioStrom::ABTASTRATE, 1, ',', '');
            $text = $wert === null
                ? AirPlayText::t('✅ Announcement played (%s s, volume unchanged).', $sekunden)
                : AirPlayText::t('✅ Announcement played (%s s, volume %s %%).', $sekunden, (int)$wert);
            if ($r['fortgesetzt']) {
                $text .= ' ' . AirPlayText::t('Playback resumed.');
            }
            if ($r['verspaetet'] > 10) {
                $text .= ' ' . AirPlayText::t('⚠️ %s packets were late — the system was busy, there may have been dropouts.', $r['verspaetet']);
            }
            $this->ergebnis($text);
            return true;
        }, 'Announcement failed: %s');
    }

    // ------------------------------------------------------------------ intern: Variablen

    private function fernsteuerungAn(): bool
    {
        return $this->ReadPropertyBoolean('RemoteControl');
    }

    private function host(): string
    {
        return trim((string)$this->ReadPropertyString('Host'));
    }

    private static function optionen(array $werte): string
    {
        $o = [];
        foreach ($werte as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return (string)json_encode($o);
    }

    /**
     * Variablen nur anlegen, wenn sie fehlen: Darstellung und Position gehören danach dem Nutzer
     * (Store-Review, SUITE.md 5/9m/28). Nie löschen.
     */
    private function variablenSicherstellen(): void
    {
        $t = fn (string $s) => AirPlayText::t($s);
        $anzeige = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $liste = [
            ['Zustand', $t('State'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS' => self::optionen([0 => $t('Unknown'), 1 => $t('Playing'), 2 => $t('Paused'), 3 => $t('Stopped')])], false],
            ['Steuerung', $t('Control'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS' => self::optionen([0 => '⏮', 1 => '▶', 2 => '⏸', 3 => '⏭'])], true],
            ['Lautstaerke', $t('Volume'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %'], true],
            ['Titel', $t('Title'), VARIABLETYPE_STRING, $anzeige, false],
            ['Interpret', $t('Artist'), VARIABLETYPE_STRING, $anzeige, false],
            ['Album', $t('Album'), VARIABLETYPE_STRING, $anzeige, false],
            ['App', $t('App'), VARIABLETYPE_STRING, $anzeige, false],
        ];
        foreach ($liste as $pos => [$ident, $name, $typ, $darstellung, $aktion]) {
            if (@$this->GetIDForIdent($ident) === false) {
                $this->MaintainVariable($ident, $name, $typ, $darstellung, ($pos + 1) * 10, true);
            }
            if ($aktion) {
                $this->EnableAction($ident);
            }
        }
        if (@IPS_GetObjectIDByIdent('Cover', $this->InstanceID) === false) {
            $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, 'Cover');
            IPS_SetName($id, $t('Cover'));
            IPS_SetPosition($id, 80);
            IPS_SetMediaCached($id, true);
            IPS_SetMediaFile($id, 'AirPlayCover_' . $this->InstanceID . '.jpg', false);
        }
    }

    private function wertSetzen(string $ident, mixed $wert): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    private function wiedergabeSchreiben(array $w): void
    {
        $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
        $this->wertSetzen('Titel', $w['titel']);
        $this->wertSetzen('Interpret', $w['interpret']);
        $this->wertSetzen('Album', $w['album']);
        $this->wertSetzen('App', $w['app']);
        if ($w['lautstaerke'] !== null) {
            $this->wertSetzen('Lautstaerke', (int)round($w['lautstaerke']));
        }
        $schluessel = (string)$w['coverSchluessel'];
        if ($schluessel !== $this->ReadAttributeString('CoverKey') && ($w['cover'] !== '' || $schluessel === '')) {
            $cover = @IPS_GetObjectIDByIdent('Cover', $this->InstanceID);
            if ($cover !== false) {
                IPS_SetMediaContent($cover, base64_encode($w['cover']));
            }
            $this->WriteAttributeString('CoverKey', $schluessel);
        }
    }

    /** Erreichbarkeit merken; Status und Meldung nur beim Wechsel (kein Log-Rauschen bei ausgeschaltetem Gerät). */
    private function erreichbar(bool $ja, string $grund = ''): void
    {
        if ($ja === $this->ReadAttributeBoolean('Reachable')) {
            return;
        }
        $this->WriteAttributeBoolean('Reachable', $ja);
        $this->SetStatus($ja ? IS_ACTIVE : self::STATUS_NICHT_ERREICHBAR);
        $this->LogMessage($ja ? AirPlayText::t('Device reachable again.') : AirPlayText::t('Device not reachable: %s', $grund), $ja ? KL_MESSAGE : KL_WARNING);
    }

    private function zusammenfassung(): string
    {
        $titel = @$this->GetIDForIdent('Titel');
        $interpret = @$this->GetIDForIdent('Interpret');
        $text = trim(($interpret !== false ? GetValueString($interpret) : '') . ' – ' . ($titel !== false ? GetValueString($titel) : ''), ' –');
        return $text !== '' ? $text : AirPlayText::t('nothing playing');
    }

    // ------------------------------------------------------------------ intern: Ergebnis

    private function fehlendeErweiterungen(): array
    {
        return array_values(array_filter(['gmp', 'sodium'], fn ($e) => !extension_loaded($e)));
    }

    private function ergebnis(string $text): void
    {
        $this->WriteAttributeString('LastResult', date('d.m.Y H:i:s') . ' ' . $text);
        $this->SendDebug('Ergebnis', $text, 0);
    }

    private function fehler(string $text): bool
    {
        $this->WriteAttributeString('LastResult', date('d.m.Y H:i:s') . ' ❌ ' . $text);
        $this->LogMessage($text, KL_WARNING);
        return false;
    }

    // ------------------------------------------------------------------ Formular

    protected function airplayPrefix(): string
    {
        return 'AIRP';
    }

    protected function airplayZweck(): array
    {
        return [
            'Plays announcements on a HomePod via AirPlay 2 and shows and controls what it is playing — directly from Symcon, without additional software.',
            'Use it from scripts: AIRP_AnnounceMedia(<instance>, <media object with a WAV file>); — the call returns when the announcement has finished. Music that was playing continues afterwards.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'Audio format: WAV (PCM) with any sample rate, mono or stereo. The module converts it to 44.1 kHz stereo. MP3 is not supported yet. Maximum length: 120 seconds.',
            'Announcement functions: AIRP_AnnounceMedia(id, mediaID), AIRP_AnnounceMediaEx(id, mediaID, volume), AIRP_AnnounceWave(id, wavBytes), AIRP_AnnounceWaveEx(id, wavBytes, volume), AIRP_PlayChime(id). Volume 0–100, -1 = setting of the instance.',
            'Remote control functions: AIRP_Play(id), AIRP_Pause(id), AIRP_Stop(id), AIRP_Next(id), AIRP_Previous(id), AIRP_SetVolume(id, volume), AIRP_Update(id), AIRP_TestConnection(id). All return true or false; the result is shown in the panel "Announcements".',
            'Scheduled announcements: list in the panel "Scheduled announcements" — every full or half hour within an hour window, or daily at a set time; every day, Mon–Fri or Sat–Sun. Placeholders such as {time} also work in AIRP_Say.',
            'Speech: AIRP_Say(id, text) and AIRP_SayEx(id, text, volume) read a text aloud (at most 1000 characters). The speech comes from the Piper server set in the panel "Speech output"; the call returns when the announcement has finished.',
            'Apple Music: AIRP_PlayPlaylist(id, link, shuffle) starts the songs of a playlist or album from its share link (music.apple.com), or a comma-separated list of song IDs. The module reads the song list from the public Apple Music page; the HomePod plays it from its own Apple Music account.',
            'Resume: before an announcement, the module remembers whether something was playing and the volume; afterwards it restores the volume and continues playback. This adds about 3–5 seconds to the call.',
            'Display: the HomePod only reports changes while a connection is open. The module therefore asks at the set interval (a query takes about 2 seconds); changes can appear with that delay.',
            'The device must be reachable from Symcon on TCP port 7000 and on the ports it assigns per session; for the audio, the device sends time requests to UDP ports that Symcon opens for the duration of the announcement. A firewall on the Symcon host must allow that.',
        ];
    }

    public function GetConfigurationForm()
    {
        $letztes = (string)$this->ReadAttributeString('LastResult');
        $fehlt = $this->fehlendeErweiterungen();
        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => $this->host() === '',
            'caption' => '🔌  Connection & display',
            'items' => array_values(array_filter([
                $fehlt !== [] ? ['type' => 'Label', 'caption' => AirPlayText::t('⚠️ PHP extension missing: %s — announcements are not possible on this system.', implode(', ', $fehlt))] : null,
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP address or host name', 'width' => '400px'],
                ['type' => 'Label', 'caption' => 'Usually filled in by the AirPlay configurator. A fixed IP address (DHCP reservation) for the device is recommended.'],
                ['type' => 'ValidationTextBox', 'name' => 'DeviceID', 'caption' => 'Device ID (from the network search)', 'width' => '400px', 'enabled' => false],
                ['type' => 'ValidationTextBox', 'name' => 'Model', 'caption' => 'Device type', 'width' => '400px', 'enabled' => false],
                ['type' => 'Button', 'caption' => '🔗 Test connection (no sound)', 'onClick' => 'AIRP_UITest($id);'],
                ['type' => 'CheckBox', 'name' => 'RemoteControl', 'caption' => 'Show and control playback (HomePod, Apple TV)'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateInterval', 'caption' => 'Update interval', 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'Label', 'caption' => '0 = no automatic update. Values below 5 seconds are raised to 5.'],
                ['type' => 'Button', 'caption' => '🔄 Update now', 'onClick' => 'AIRP_UIUpdate($id);'],
                ['type' => 'Label', 'caption' => 'Start Apple Music: paste the share link of a playlist or album (Apple Music app: … → Share → Copy link). The HomePod plays a copy of the song list; later changes to the playlist are not included.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'PlaylistLink', 'caption' => 'Apple Music link', 'width' => '420px'],
                    ['type' => 'CheckBox', 'name' => 'PlaylistShuffle', 'caption' => 'Shuffle'],
                    ['type' => 'Button', 'caption' => '🎵 Play', 'onClick' => 'AIRP_UIPlaylist($id, $PlaylistLink, $PlaylistShuffle);'],
                ]],
            ])),
        ];
        $durchsagen = [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '📢  Announcements',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'SetVolume', 'caption' => 'Set the volume for announcements'],
                ['type' => 'NumberSpinner', 'name' => 'Volume', 'caption' => 'Volume for announcements', 'suffix' => '%', 'minimum' => 0, 'maximum' => 100],
                ['type' => 'Label', 'caption' => 'Without the checkbox, the announcement plays at the volume currently set on the device.'],
                ['type' => 'CheckBox', 'name' => 'ResumeAfterAnnouncement', 'caption' => 'Afterwards, restore the volume and continue playback'],
                ['type' => 'Button', 'caption' => '🔔 Play chime', 'onClick' => 'AIRP_UIChime($id);'],
                ['type' => 'Label', 'name' => 'LastResult', 'caption' => $letztes !== '' ? $letztes : AirPlayText::t('ℹ️ No announcement played yet.')],
            ],
        ];
        $sprache = [
            'type' => 'ExpansionPanel', 'expanded' => $this->ReadPropertyString('PiperServer') === '',
            'caption' => '🗣️  Speech output (Piper)',
            'items' => [
                ['type' => 'Label', 'caption' => 'For AIRP_Say: the text is turned into speech by a Piper server in your own network (free, offline, no account) — e.g. the Docker container rhasspy/wyoming-piper. Guide in the documentation.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'PiperServer', 'caption' => 'Piper server (host or host:port, port 10200)', 'width' => '320px'],
                    ['type' => 'ValidationTextBox', 'name' => 'PiperVoice', 'caption' => 'Voice (empty = default of the server)', 'width' => '280px'],
                    ['type' => 'Button', 'caption' => '🔍 Show voices', 'onClick' => 'AIRP_UIVoices($id, $PiperServer);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'SayText', 'caption' => 'Test text', 'value' => AirPlayText::t('This is a test of the speech output.'), 'width' => '420px'],
                    ['type' => 'Button', 'caption' => '🗣️ Speak', 'onClick' => 'AIRP_UISay($id, $SayText);'],
                ]],
                ['type' => 'Label', 'caption' => 'Apply changes first, then test. The volume and resume settings of the panel "Announcements" apply.'],
            ],
        ];
        $naechste = $this->ReadAttributeInteger('ScheduleNext');
        $plan = [
            'type' => 'ExpansionPanel', 'expanded' => $this->zeitplan() !== [],
            'caption' => '⏰  Scheduled announcements',
            'items' => [
                ['type' => 'Label', 'caption' => 'Spoken via Piper (panel "Speech output"). Placeholders in the text: {uhrzeit} or {time} ("14 o\'clock", "14:30"), {wochentag} or {weekday}, {datum} or {date}, {var:12345} = value of a variable with its unit.'],
                ['type' => 'List', 'name' => 'Schedule', 'caption' => 'Announcements', 'rowCount' => 5, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Active', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'When', 'name' => 'When', 'width' => '190px', 'add' => 'hourly', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Every full hour', 'value' => 'hourly'],
                            ['caption' => 'Every half hour', 'value' => 'halfhourly'],
                            ['caption' => 'Daily at the time', 'value' => 'daily'],
                        ]]],
                        ['caption' => 'Time (daily)', 'name' => 'Time', 'width' => '110px', 'add' => '07:00', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Days', 'name' => 'Days', 'width' => '130px', 'add' => 'all', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Every day', 'value' => 'all'],
                            ['caption' => 'Mon–Fri', 'value' => 'weekdays'],
                            ['caption' => 'Sat–Sun', 'value' => 'weekend'],
                        ]]],
                        ['caption' => 'From', 'name' => 'From', 'width' => '80px', 'add' => 7, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 23, 'suffix' => 'h']],
                        ['caption' => 'To', 'name' => 'To', 'width' => '80px', 'add' => 22, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 23, 'suffix' => 'h']],
                        ['caption' => 'Text', 'name' => 'Text', 'width' => 'auto', 'add' => AirPlayText::t('It is {time}.'), 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Volume', 'name' => 'Volume', 'width' => '90px', 'add' => -1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1, 'maximum' => 100, 'suffix' => '%']],
                        ['caption' => 'Chime', 'name' => 'Chime', 'width' => '70px', 'add' => false, 'edit' => ['type' => 'CheckBox']],
                    ]],
                ['type' => 'Label', 'caption' => '"From/To" limits hourly and half-hourly announcements to these hours (e.g. 7 to 22 h; 22 to 6 h also works). Volume -1 = setting of the panel "Announcements".'],
                ['type' => 'Label', 'caption' => $naechste > 0
                    ? AirPlayText::t('⏰ Next scheduled announcement: %s', date('d.m.Y H:i', $naechste))
                    : AirPlayText::t('ℹ️ No scheduled announcement active.')],
            ],
        ];
        return $this->airplayFormular([$verbindung, $durchsagen, $sprache, $plan], [], [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Ready'],
            ['code' => self::STATUS_ADRESSE_FEHLT, 'icon' => 'inactive', 'caption' => 'Please enter the address of the device'],
            ['code' => self::STATUS_PHP_FEHLT, 'icon' => 'error', 'caption' => 'PHP extension gmp or sodium missing'],
            ['code' => self::STATUS_NICHT_ERREICHBAR, 'icon' => 'error', 'caption' => 'Device not reachable'],
        ]);
    }
}
