<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayAblauf.php';
require_once __DIR__ . '/AirPlayAudio.php';
require_once __DIR__ . '/AirPlayStimmen.php';
require_once __DIR__ . '/AirPlayZeitplan.php';
require_once __DIR__ . '/AirPlayLautstaerke.php';
require_once __DIR__ . '/AirPlayWarteschlange.php';
require_once __DIR__ . '/AirPlayProtokoll.php';

// ===========================================================================
// AirPlay-Gerät: Durchsagen. Alle Wege — WAV, Medienobjekt, Text, Klang,
// Warteschlange, Zeitplan, Wecker-Gruß — enden in durchsageAbspielen():
// Lautstärke (Instanz, Nachtgrenze) bestimmen, unter der Instanz-Sperre
// spielen, Ergebnis melden und protokollieren.
// ===========================================================================

trait AirPlayGeraetDurchsage
{
    // ------------------------------------------------------------------ öffentlich

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
        return $this->wavAbspielen($inhalt, $Volume, IPS_GetName($MediaID));
    }

    /** Durchsage aus WAV-Bytes (z. B. Rückgabe eines TTS-Moduls). */
    public function AnnounceWave(string $Wave): bool
    {
        return $this->AnnounceWaveEx($Wave, -1);
    }

    public function AnnounceWaveEx(string $Wave, int $Volume): bool
    {
        return $this->wavAbspielen($Wave, $Volume, AirPlayText::t('WAV file'));
    }

    /** Text vorlesen lassen mit der eingestellten Lautstärke; wartet, bis die Durchsage beendet ist. */
    public function Say(string $Text): bool
    {
        return $this->SayEx($Text, -1);
    }

    /** Text vorlesen lassen; $Volume 0–100 %, -1 = Einstellung der Instanz. Platzhalter wie {uhrzeit} werden ersetzt. */
    public function SayEx(string $Text, int $Volume): bool
    {
        return $this->sprechen($Text, $Volume, '', 'script');
    }

    /**
     * Text vorlesen lassen, ohne zu warten: reiht die Durchsage ein und kehrt sofort zurück; die Instanz spricht
     * eingereihte Durchsagen der Reihe nach. false, wenn der Text leer ist, keine Sprachausgabe gewählt ist oder
     * schon 10 warten. $Volume 0–100 %, -1 = Einstellung der Instanz; $Chime = Gong davor.
     * Aufrufform und Bedeutung von true/false sind fest (Abnehmer: WarnHub, Automat).
     */
    public function SayAsync(string $Text, int $Volume, bool $Chime): bool
    {
        if (!$this->spracheEingerichtet()) {
            return $this->fehler(AirPlayText::t('Speech output failed: %s', AirPlayText::t('no speech output selected (panel "Speech output")')));
        }
        try {
            $this->exklusiv('Q', function () use ($Text, $Volume, $Chime): void {
                $schlange = json_decode($this->ReadAttributeString('Queue'), true) ?: [];
                $this->WriteAttributeString('Queue', (string)json_encode(AirPlayWarteschlange::anhaengen($schlange, $Text, $Volume, $Chime, time())));
            });
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Announcement not queued: %s', AirPlayText::fehler($e)));
        }
        $this->SetTimerInterval('Queue', 100);
        return true;
    }

    /** Gong (Zweiklang), z. B. zum Testen. */
    public function PlayChime(): bool
    {
        return $this->PlaySound('gong');
    }

    /** Klang abspielen: gong, doorbell, notice oder alarm. */
    public function PlaySound(string $Sound): bool
    {
        try {
            $pcm = AirPlayAudio::klang($Sound);
        } catch (AirPlayFehler $e) {
            return $this->fehler(AirPlayText::fehler($e));
        }
        return $this->durchsageAbspielen($pcm, -1, 'script', '♪ ' . $this->klangName($Sound));
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

    // ------------------------------------------------------------------ Formular-Knöpfe

    public function UISound(string $Sound): void
    {
        $this->PlaySound($Sound);
        $this->rueckmeldung();
    }

    public function UITest(): void
    {
        $this->TestConnection();
        $this->rueckmeldung();
    }

    public function UISay(string $Text): void
    {
        $this->sprechen($Text, -1, '', 'form');
        $this->rueckmeldung();
    }

    /** Stimmen des direkt eingetragenen Piper-Servers (Einstellung vor 0.8.0). */
    public function UIVoices(string $Server): void
    {
        try {
            $stimmen = $this->direktesPiper($Server, '')->stimmen();
        } catch (Throwable $e) {
            echo AirPlayText::t('❌ %s', AirPlayText::fehler($e));
            return;
        }
        echo $stimmen === []
            ? AirPlayText::t('The Piper server reports no installed voice.')
            : AirPlayText::t('Installed voices (copy the name into the field "Voice"):') . "\n\n"
                . implode("\n", array_map(fn (array $s): string => '• ' . $s['name'] . ($s['info'] !== '' ? ' (' . $s['info'] . ')' : ''), $stimmen));
    }

    // ------------------------------------------------------------------ Warteschlange

    /** Eingereihte Durchsagen der Reihe nach sprechen (Timer „Queue"). */
    private function warteschlangeAbarbeiten(): void
    {
        $lauf = 'AIRP_QRUN_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lauf, 0)) {
            $this->SetTimerInterval('Queue', 1000);   // ein anderer Lauf arbeitet noch — gleich noch einmal nachsehen
            return;
        }
        $this->SetTimerInterval('Queue', 0);
        try {
            while (($eintrag = $this->ausSchlange()) !== null) {
                if (AirPlayWarteschlange::verfallen($eintrag, time())) {
                    $this->LogMessage(AirPlayText::t('Queued announcement skipped: more than 10 minutes old.'), KL_WARNING);
                    continue;
                }
                $this->sprechen((string)$eintrag['text'], (int)$eintrag['volume'], $eintrag['chime'] ? 'gong' : '', 'queue', (int)$eintrag['time']);
            }
        } finally {
            IPS_SemaphoreLeave($lauf);
        }
    }

    private function ausSchlange(): ?array
    {
        try {
            return $this->exklusiv('Q', function (): ?array {
                [$eintrag, $rest] = AirPlayWarteschlange::entnehmen(json_decode($this->ReadAttributeString('Queue'), true) ?: []);
                $this->WriteAttributeString('Queue', (string)json_encode($rest));
                return $eintrag;
            });
        } catch (AirPlayFehler) {
            $this->SetTimerInterval('Queue', 1000);
            return null;
        }
    }

    // ------------------------------------------------------------------ Sprache

    /**
     * Text mit Platzhaltern sprechen, auf Wunsch mit Klang davor — als eine Durchsage (eine Pause der Musik).
     * $zeit: Bezugszeit der Platzhalter (Einreihen bzw. geplanter Termin).
     */
    private function sprechen(string $text, int $lautstaerke, string $klang, string $quelle, ?int $zeit = null): bool
    {
        $text = AirPlayPlatzhalter::ersetzen($text, $zeit ?? time(), fn (int $id): ?string => @IPS_VariableExists($id) ? (string)GetValueFormatted($id) : null);
        try {
            $audio = AirPlayAudio::wavZuPcm($this->spracheErzeugen($text));
            $vorspann = $klang !== '' ? AirPlayAudio::klang($klang) : '';
        } catch (Throwable $e) {
            $this->protokollieren($quelle, $text, false, AirPlayText::fehler($e));
            return $this->fehler(AirPlayText::t('Speech output failed: %s', AirPlayText::fehler($e)));
        }
        return $this->durchsageAbspielen($vorspann . $audio['pcm'], $lautstaerke, $quelle, $text);
    }

    /** Sprachausgabe-Instanz gewählt — oder noch die direkte Piper-Einstellung aus 0.5.0–0.7.0? */
    private function spracheEingerichtet(): bool
    {
        return $this->spracheInstanz() > 0 || trim($this->ReadPropertyString('PiperServer')) !== '';
    }

    private function spracheInstanz(): int
    {
        $id = $this->ReadPropertyInteger('SpeechInstance');
        return $id > 0 && @IPS_InstanceExists($id) ? $id : 0;
    }

    /** WAV für den Text: über die gewählte Sprachausgabe-Instanz, sonst über den direkt eingetragenen Piper-Server. */
    private function spracheErzeugen(string $text): string
    {
        $instanz = $this->spracheInstanz();
        if ($instanz > 0 && function_exists('AIRPS_GetSpeech')) {
            $wav = base64_decode(AIRPS_GetSpeech($instanz, $text), true);
            if ($wav === false || $wav === '') {
                throw new AirPlayFehler('%s', AIRPS_GetLastError($instanz));
            }
            return $wav;
        }
        if (trim($this->ReadPropertyString('PiperServer')) === '') {
            throw new AirPlayFehler('no speech output selected (panel "Speech output")');
        }
        return $this->direktesPiper($this->ReadPropertyString('PiperServer'), $this->ReadPropertyString('PiperVoice'))->wav($text);
    }

    /** Direkt eingetragener Piper-Server (Einstellung vor 0.8.0) — derselbe Dienst wie in der Sprachausgabe-Instanz. */
    private function direktesPiper(string $server, string $stimme): AirPlaySprachdienst
    {
        return AirPlayStimmen::dienst(['dienst' => 'piper', 'server' => $server, 'stimme' => $stimme]);
    }

    // ------------------------------------------------------------------ Abspielen

    private function wavAbspielen(string $wav, int $lautstaerke, string $bezeichnung): bool
    {
        $format = AirPlayAudio::format($wav);
        if ($format !== 'wav') {
            return $this->fehler(AirPlayText::t('Only WAV files are supported so far (detected: %s).', $format));
        }
        try {
            $audio = AirPlayAudio::wavZuPcm($wav);
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Audio could not be read: %s', AirPlayText::fehler($e)));
        }
        $this->SendDebug('Audio', sprintf('%s, %.1f s', $audio['quelle'], $audio['sekunden']), 0);
        return $this->durchsageAbspielen($audio['pcm'], $lautstaerke, 'script', '♪ ' . $bezeichnung);
    }

    /** Lautstärke einer Durchsage: Wunsch (-1 = Instanz), dann Nachtgrenze; null = am Gerät nicht verändern. */
    private function durchsageLautstaerke(int $wunsch): ?float
    {
        $wert = $wunsch >= 0
            ? (float)min(100, $wunsch)
            : ($this->ReadPropertyBoolean('SetVolume') ? (float)$this->ReadPropertyInteger('Volume') : null);
        return AirPlayLautstaerke::nachtgrenze($wert, [
            'aktiv' => $this->ReadPropertyBoolean('NightLimit'),
            'max' => $this->ReadPropertyInteger('NightVolume'),
            'von' => $this->ReadPropertyInteger('NightFrom'),
            'bis' => $this->ReadPropertyInteger('NightTo'),
        ], time());
    }

    /** Gemeinsames Ende aller Durchsagen: spielen, melden, protokollieren. */
    private function durchsageAbspielen(string $pcm, int $lautstaerke, string $quelle, string $text): bool
    {
        $wert = $this->durchsageLautstaerke($lautstaerke);
        $fortsetzen = $this->fernsteuerungAn() && $this->ReadPropertyBoolean('ResumeAfterAnnouncement');
        $ok = $this->mitSperre(function () use ($pcm, $wert, $fortsetzen): bool {
            $r = $this->ablauf()->durchsage($pcm, $wert, $fortsetzen);
            $sekunden = number_format(strlen($pcm) / 4 / AirPlayAudioStrom::ABTASTRATE, 1, ',', '');
            $meldung = $wert === null
                ? AirPlayText::t('✅ Announcement played (%s s, volume unchanged).', $sekunden)
                : AirPlayText::t('✅ Announcement played (%s s, volume %s %%).', $sekunden, (int)$wert);
            if ($r['fortgesetzt']) {
                $meldung .= ' ' . AirPlayText::t('Playback resumed.');
            }
            if ($r['wiederherstellenFehler'] !== '') {
                $meldung .= ' ' . AirPlayText::t('⚠️ Volume or playback could not be restored: %s', $r['wiederherstellenFehler']);
            }
            if ($r['verspaetet'] > 10) {
                $meldung .= ' ' . AirPlayText::t('⚠️ %s packets were late — the system was busy, there may have been dropouts.', $r['verspaetet']);
            }
            $this->ergebnis($meldung);
            return true;
        }, 'Announcement failed: %s');
        $this->protokollieren($quelle, $text, $ok, $ok && $wert !== null ? ((int)$wert) . ' %' : ($ok ? '' : $this->letzterFehler()));
        $this->kachelAktualisieren();
        return $ok;
    }

    // ------------------------------------------------------------------ Protokoll und Klänge

    private function protokollieren(string $quelle, string $text, bool $ok, string $info): void
    {
        try {
            $this->exklusiv('H', function () use ($quelle, $text, $ok, $info): void {
                $liste = json_decode($this->ReadAttributeString('History'), true) ?: [];
                $eintrag = ['zeit' => time(), 'quelle' => $quelle, 'text' => $text, 'ok' => $ok, 'info' => $info];
                $this->WriteAttributeString('History', (string)json_encode(AirPlayProtokoll::eintragen($liste, $eintrag)));
            });
        } catch (AirPlayFehler) {
            // Protokoll ist Komfort — eine belegte Sperre darf keine Durchsage scheitern lassen
        }
    }

    /** Text der letzten Fehlermeldung fürs Protokoll. */
    private function letzterFehler(): string
    {
        $e = $this->letztesErgebnis();
        return $e !== null && !$e['ok'] ? $e['text'] : '';
    }

    private function klangName(string $klang): string
    {
        return match ($klang) {
            'gong' => AirPlayText::t('Chime'),
            'doorbell' => AirPlayText::t('Doorbell'),
            'notice' => AirPlayText::t('Notice'),
            'alarm' => AirPlayText::t('Alarm'),
            default => $klang,
        };
    }

    private function quelleName(string $quelle): string
    {
        return match ($quelle) {
            'script' => AirPlayText::t('Script'),
            'queue' => AirPlayText::t('Queue'),
            'schedule' => AirPlayText::t('Schedule'),
            'alarm' => AirPlayText::t('Alarm clock'),
            'form' => AirPlayText::t('Form'),
            'tile' => AirPlayText::t('Tile'),
            default => $quelle,
        };
    }
}
