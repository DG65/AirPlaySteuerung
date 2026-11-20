<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlaySitzung.php';
require_once __DIR__ . '/../libs/AirPlayAudio.php';
require_once __DIR__ . '/../libs/AirPlayGeraete.php';

// ===========================================================================
// AirPlay-Gerät — ein AirPlay-Empfänger (HomePod, Apple TV, Fremdgerät).
//
// 0.1.0: Durchsagen. Eine WAV-Datei (Medienobjekt oder Bytes) wird per
// AirPlay 2 an das Gerät gestreamt; der Aufruf blockiert für die Dauer der
// Durchsage plus etwa 1,5 s. Verbindungen bestehen nur während der Durchsage
// (eigene Sockets, Begründung in libs/AirPlayVerbindung.php).
// Fernsteuerung und Anzeige (MRP) folgen in einer späteren Version.
// ===========================================================================

class AirPlayGeraet extends IPSModule
{
    use AirPlayFormular;

    private const STATUS_ADRESSE_FEHLT = 201;
    private const STATUS_PHP_FEHLT = 202;

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
        $this->RegisterPropertyBoolean('SetVolume', true);
        $this->RegisterPropertyInteger('Volume', 30);
        $this->RegisterAttributeString('LastResult', '');
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->fehlendeErweiterungen() !== []) {
            $this->SetStatus(self::STATUS_PHP_FEHLT);
        } elseif (trim((string)$this->ReadPropertyString('Host')) === '') {
            $this->SetStatus(self::STATUS_ADRESSE_FEHLT);
        } else {
            $this->SetStatus(IS_ACTIVE);
        }
        $modell = (string)$this->ReadPropertyString('Model');
        $this->SetSummary(trim((string)$this->ReadPropertyString('Host') . ($modell !== '' ? ' · ' . $modell : '')));
    }

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
        return $this->abspielen($audio['pcm'], $Volume);
    }

    /** Kurzer Zweiklang-Gong, z. B. zum Testen. */
    public function PlayChime(): bool
    {
        return $this->abspielen(AirPlayAudio::gong(), -1);
    }

    /** Nur koppeln und Geräteinfo lesen, ohne Ton. */
    public function TestConnection(): bool
    {
        $sitzung = null;
        try {
            $sitzung = $this->sitzungOeffnen();
            $sitzung->koppeln();
            $info = $sitzung->info();
            $text = AirPlayText::t('✅ Connected to "%s" (%s, software %s).', (string)($info['name'] ?? '?'), (string)($info['model'] ?? '?'), (string)($info['osBuildVersion'] ?? $info['sourceVersion'] ?? '?'));
            $this->ergebnis($text);
            return true;
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Connection failed: %s', AirPlayText::fehler($e)));
        } finally {
            $sitzung?->schliessen();
        }
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

    // ------------------------------------------------------------------ intern

    private function fehlendeErweiterungen(): array
    {
        return array_values(array_filter(['gmp', 'sodium'], fn ($e) => !extension_loaded($e)));
    }

    private function sitzungOeffnen(): AirPlaySitzung
    {
        if (($fehlt = $this->fehlendeErweiterungen()) !== []) {
            throw new RuntimeException(AirPlayText::t('PHP extension missing: %s', implode(', ', $fehlt)));
        }
        $host = trim((string)$this->ReadPropertyString('Host'));
        if ($host === '') {
            throw new RuntimeException(AirPlayText::t('No address entered.'));
        }
        return new AirPlaySitzung($host, fn (string $t) => $this->SendDebug('AirPlay', $t, 0));
    }

    private function abspielen(string $pcm, int $lautstaerke): bool
    {
        if ($lautstaerke < 0) {
            $wert = $this->ReadPropertyBoolean('SetVolume') ? (float)$this->ReadPropertyInteger('Volume') : null;
        } else {
            $wert = (float)min(100, $lautstaerke);
        }
        $sperre = 'AIRP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 100)) {
            return $this->fehler(AirPlayText::t('An announcement is already running on this device.'));
        }
        $sitzung = null;
        try {
            $sitzung = $this->sitzungOeffnen();
            $sitzung->koppeln();
            $sitzung->audioEinrichten();
            $r = $sitzung->abspielen($pcm, $wert);
            $sekunden = strlen($pcm) / 4 / AirPlayAudioStrom::ABTASTRATE;
            $text = $wert === null
                ? AirPlayText::t('✅ Announcement played (%s s, volume unchanged).', number_format($sekunden, 1, ',', ''))
                : AirPlayText::t('✅ Announcement played (%s s, volume %s %%).', number_format($sekunden, 1, ',', ''), (int)$wert);
            if ($r['verspaetet'] > 10) {
                $text .= ' ' . AirPlayText::t('⚠️ %s packets were late — the system was busy, there may have been dropouts.', $r['verspaetet']);
            }
            $this->ergebnis($text);
            return true;
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t('Announcement failed: %s', AirPlayText::fehler($e)));
        } finally {
            $sitzung?->schliessen();
            IPS_SemaphoreLeave($sperre);
        }
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
            'Plays announcements on a HomePod via AirPlay 2 — directly from Symcon, without additional software.',
            'Use it from scripts: AIRP_AnnounceMedia(<instance>, <media object with a WAV file>); — the call returns when the announcement has finished.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'Audio format: WAV (PCM) with any sample rate, mono or stereo. The module converts it to 44.1 kHz stereo. MP3 is not supported yet. Maximum length: 120 seconds.',
            'Functions: AIRP_AnnounceMedia(id, mediaID), AIRP_AnnounceMediaEx(id, mediaID, volume), AIRP_AnnounceWave(id, wavBytes), AIRP_AnnounceWaveEx(id, wavBytes, volume), AIRP_PlayChime(id), AIRP_TestConnection(id). Volume 0–100, -1 = setting of the instance. All return true or false; the result is shown in the panel "Announcements".',
            'An announcement interrupts what the HomePod is playing at that moment. In this version, music does not resume automatically afterwards, and the volume stays at the announcement volume.',
            'The device must be reachable from Symcon on TCP port 7000; for the audio, the device sends to UDP ports that Symcon opens for the duration of the announcement. A firewall on the Symcon host must allow that.',
        ];
    }

    public function GetConfigurationForm()
    {
        $letztes = (string)$this->ReadAttributeString('LastResult');
        $fehlt = $this->fehlendeErweiterungen();
        $verbindung = [
            'type' => 'ExpansionPanel', 'expanded' => trim((string)$this->ReadPropertyString('Host')) === '',
            'caption' => '🔌  Connection',
            'items' => array_values(array_filter([
                $fehlt !== [] ? ['type' => 'Label', 'caption' => AirPlayText::t('⚠️ PHP extension missing: %s — announcements are not possible on this system.', implode(', ', $fehlt))] : null,
                ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP address or host name', 'width' => '400px'],
                ['type' => 'Label', 'caption' => 'Usually filled in by the AirPlay configurator. A fixed IP address (DHCP reservation) for the device is recommended.'],
                ['type' => 'ValidationTextBox', 'name' => 'DeviceID', 'caption' => 'Device ID (from the network search)', 'width' => '400px', 'enabled' => false],
                ['type' => 'ValidationTextBox', 'name' => 'Model', 'caption' => 'Device type', 'width' => '400px', 'enabled' => false],
                ['type' => 'Button', 'caption' => '🔗 Test connection (no sound)', 'onClick' => 'AIRP_UITest($id);'],
            ])),
        ];
        $durchsagen = [
            'type' => 'ExpansionPanel', 'expanded' => true,
            'caption' => '📢  Announcements',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'SetVolume', 'caption' => 'Set the volume for announcements'],
                ['type' => 'NumberSpinner', 'name' => 'Volume', 'caption' => 'Volume for announcements', 'suffix' => '%', 'minimum' => 0, 'maximum' => 100],
                ['type' => 'Label', 'caption' => 'Without the checkbox, the announcement plays at the volume currently set on the device.'],
                ['type' => 'Button', 'caption' => '🔔 Play chime', 'onClick' => 'AIRP_UIChime($id);'],
                ['type' => 'Label', 'name' => 'LastResult', 'caption' => $letztes !== '' ? $letztes : AirPlayText::t('ℹ️ No announcement played yet.')],
            ],
        ];
        return $this->airplayFormular([$verbindung, $durchsagen], [], [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Ready'],
            ['code' => self::STATUS_ADRESSE_FEHLT, 'icon' => 'inactive', 'caption' => 'Please enter the address of the device'],
            ['code' => self::STATUS_PHP_FEHLT, 'icon' => 'error', 'caption' => 'PHP extension gmp or sodium missing'],
        ]);
    }
}
