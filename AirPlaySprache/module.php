<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayStimmen.php';

// ===========================================================================
// AirPlay-Sprachausgabe — macht aus Text eine WAV-Datei für die HomePods.
//
// Eine Instanz für alle Geräte: Dienst (Piper im eigenen Netz, Microsoft Azure,
// ElevenLabs, Google Cloud), Stimme, Schlüssel und Zwischenspeicher liegen hier
// einmal; die AirPlay-Geräte wählen diese Instanz nur aus und holen sich den
// Ton über AIRPS_GetSpeech.
//
// Schlüssel stehen in einem Attribut, nicht in einer Eigenschaft (NRG-Stack-Regel
// „Credentials"): sie landen so nicht in Konfigurations-Exporten und im Formular.
// Zwischenspeicher: je Text eine versteckte Ton-Mediendatei unter der Instanz
// (Kennung aus Dienst + Stimme + Text); wiederkehrende Ansagen wie die stündliche
// Uhrzeit kosten so nur beim ersten Mal Kontingent.
// ===========================================================================

class AirPlaySprache extends IPSModule
{
    use AirPlayFormular;

    private const STATUS_SCHLUESSEL_FEHLT = 201;
    private const STATUS_SERVER_FEHLT = 202;
    /** Stimmen-Kennung für den Zwischenspeicher, wenn das Feld leer ist (Standard des Dienstes). */
    private const STANDARD = '(default)';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        AirPlayText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Service', 'piper');
        $this->RegisterPropertyString('PiperServer', '');
        $this->RegisterPropertyString('AzureRegion', 'germanywestcentral');
        $this->RegisterPropertyString('ElevenLabsModel', AirPlayElevenLabs::STANDARD_MODELL);
        $this->RegisterPropertyString('Voice', '');
        $this->RegisterPropertyBoolean('Cache', true);
        $this->RegisterPropertyInteger('CacheMax', 100);
        $this->RegisterAttributeString('Keys', '{}');
        $this->RegisterAttributeString('LastError', '');
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->statusSetzen();
        $this->SetSummary($this->dienstName($this->ReadPropertyString('Service')));
    }

    private function statusSetzen(): void
    {
        $dienst = $this->ReadPropertyString('Service');
        if ($dienst === 'piper') {
            $this->SetStatus(trim($this->ReadPropertyString('PiperServer')) === '' ? self::STATUS_SERVER_FEHLT : IS_ACTIVE);
        } else {
            $this->SetStatus($this->schluessel($dienst) === '' ? self::STATUS_SCHLUESSEL_FEHLT : IS_ACTIVE);
        }
    }

    // ------------------------------------------------------------------ öffentlich

    /**
     * Text in Sprache umwandeln. Rückgabe: WAV-Datei als Base64, leerer Text bei Fehlern (Grund über
     * AIRPS_GetLastError und im Meldungslog). Gleiche Texte kommen aus dem Zwischenspeicher.
     */
    public function GetSpeech(string $Text): string
    {
        try {
            $dienst = $this->ReadPropertyString('Service');
            $kennung = AirPlayStimmen::speicherKennung($dienst, $this->stimme() !== '' ? $this->stimme() : self::STANDARD, $Text);
            $gespeichert = $this->ausSpeicher($kennung);
            if ($gespeichert !== null) {
                $this->SendDebug('Zwischenspeicher', $Text, 0);
                $this->WriteAttributeString('LastError', '');
                return $gespeichert;
            }
            $wav = $this->dienst()->wav($Text);
            $this->inSpeicher($kennung, $Text, $wav);
            $this->SendDebug($this->dienstName($dienst), sprintf('%s (%d Bytes)', $Text, strlen($wav)), 0);
            $this->WriteAttributeString('LastError', '');
            return base64_encode($wav);
        } catch (Throwable $e) {
            $grund = AirPlayText::fehler($e);
            $this->WriteAttributeString('LastError', $grund);
            $this->LogMessage(AirPlayText::t('Speech output failed: %s', $grund), KL_WARNING);
            return '';
        }
    }

    /** Grund des letzten Fehlers von GetSpeech (leer, wenn alles geklappt hat). */
    public function GetLastError(): string
    {
        return $this->ReadAttributeString('LastError');
    }

    /** Alle zwischengespeicherten Aufnahmen löschen. */
    public function ClearCache(): int
    {
        $anzahl = 0;
        foreach ($this->speicherEintraege() as $id => $_) {
            IPS_DeleteMedia($id, true);
            $anzahl++;
        }
        return $anzahl;
    }

    // ------------------------------------------------------------------ Formular-Knöpfe

    public function UISaveKey(string $Service, string $Key): void
    {
        if (!in_array($Service, ['azure', 'elevenlabs', 'google'], true)) {
            echo AirPlayText::t('Piper needs no key.');
            return;
        }
        $schluessel = json_decode($this->ReadAttributeString('Keys'), true) ?: [];
        $schluessel[$Service] = trim($Key);
        $this->WriteAttributeString('Keys', (string)json_encode($schluessel));
        $this->UpdateFormField('KeyInput', 'value', '');
        $this->UpdateFormField('KeyState', 'caption', $this->schluesselText($Service));
        $this->statusSetzen();
        echo trim($Key) === '' ? AirPlayText::t('Key removed.') : AirPlayText::t('Key saved. Click "Apply changes" if you have not done so yet, then test.');
    }

    public function UIVoices(): void
    {
        try {
            $stimmen = $this->dienst()->stimmen();
        } catch (Throwable $e) {
            echo AirPlayText::t('❌ %s', AirPlayText::fehler($e));
            return;
        }
        if ($stimmen === []) {
            echo AirPlayText::t('The service reports no voice.');
            return;
        }
        echo AirPlayText::t('Voices (copy the name into the field "Voice" and apply changes):') . "\n\n"
            . implode("\n", array_map(fn (array $s): string => '• ' . $s['name'] . ($s['info'] !== '' ? ' — ' . $s['info'] : ''), array_slice($stimmen, 0, 60)));
    }

    public function UITest(string $Text): void
    {
        $start = microtime(true);
        $b64 = $this->GetSpeech($Text);
        if ($b64 === '') {
            echo AirPlayText::t('❌ %s', $this->GetLastError());
            return;
        }
        $wav = (string)base64_decode($b64);
        $kopf = @unpack('vkanaele/Vrate', substr($wav, 22, 6));
        $bytes = (int)@unpack('V', substr($wav, 40, 4))[1];
        $sekunden = !empty($kopf['rate']) ? $bytes / 2 / max(1, (int)$kopf['kanaele']) / $kopf['rate'] : 0;
        echo AirPlayText::t('✅ Speech created: %1$s s of audio in %2$s s. Play it with "🗣️ Speak" in an AirPlay device.', number_format($sekunden, 1, ',', ''), number_format(microtime(true) - $start, 1, ',', ''));
    }

    public function UIClearCache(): void
    {
        echo AirPlayText::t('%s recordings deleted.', $this->ClearCache());
        $this->UpdateFormField('CacheState', 'caption', $this->speicherText());
    }

    // ------------------------------------------------------------------ intern

    private function dienst(): AirPlaySprachdienst
    {
        $dienst = $this->ReadPropertyString('Service');
        return AirPlayStimmen::dienst([
            'dienst' => $dienst,
            'stimme' => $this->stimme(),
            'server' => $this->ReadPropertyString('PiperServer'),
            'region' => $this->ReadPropertyString('AzureRegion'),
            'modell' => $this->ReadPropertyString('ElevenLabsModel'),
            'schluessel' => $this->schluessel($dienst),
        ]);
    }

    private function stimme(): string
    {
        return trim($this->ReadPropertyString('Voice'));
    }

    private function schluessel(string $dienst): string
    {
        return (string)((json_decode($this->ReadAttributeString('Keys'), true) ?: [])[$dienst] ?? '');
    }

    private function schluesselText(string $dienst): string
    {
        if ($dienst === 'piper') {
            return AirPlayText::t('Piper needs no key.');
        }
        $s = $this->schluessel($dienst);
        return $s === '' ? AirPlayText::t('⚠️ No key saved for %s yet.', $this->dienstName($dienst))
            : AirPlayText::t('🔑 Key for %1$s saved (ends with …%2$s).', $this->dienstName($dienst), substr($s, -4));
    }

    private function dienstName(string $dienst): string
    {
        return match ($dienst) {
            'azure' => 'Microsoft Azure',
            'elevenlabs' => 'ElevenLabs',
            'google' => 'Google Cloud',
            default => 'Piper',
        };
    }

    /** @return array<int, int> Medien-ID → letzte Änderung (Unixzeit) aller Aufnahmen im Zwischenspeicher */
    private function speicherEintraege(): array
    {
        $out = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $o = IPS_GetObject($id);
            if ($o['ObjectType'] === OBJECTTYPE_MEDIA && str_starts_with($o['ObjectIdent'], 'C')) {
                $out[$id] = (int)(IPS_GetMedia($id)['MediaUpdated'] ?? 0);
            }
        }
        return $out;
    }

    private function ausSpeicher(string $kennung): ?string
    {
        if (!$this->ReadPropertyBoolean('Cache')) {
            return null;
        }
        $id = @IPS_GetObjectIDByIdent('C' . $kennung, $this->InstanceID);
        if ($id === false) {
            return null;
        }
        $inhalt = (string)IPS_GetMediaContent($id);
        return $inhalt !== '' ? $inhalt : null;
    }

    private function inSpeicher(string $kennung, string $text, string $wav): void
    {
        if (!$this->ReadPropertyBoolean('Cache')) {
            return;
        }
        $sperre = 'AIRPS_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 5000)) {
            return;   // Speichern ist nur eine Ersparnis — lieber auslassen als warten
        }
        try {
            if (@IPS_GetObjectIDByIdent('C' . $kennung, $this->InstanceID) !== false) {
                return;
            }
            $eintraege = $this->speicherEintraege();
            asort($eintraege);
            while (count($eintraege) >= max(1, $this->ReadPropertyInteger('CacheMax'))) {
                IPS_DeleteMedia((int)array_key_first($eintraege), true);
                array_shift($eintraege);
            }
            $id = IPS_CreateMedia(MEDIATYPE_SOUND);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, 'C' . $kennung);
            IPS_SetName($id, mb_substr($text, 0, 60));
            IPS_SetHidden($id, true);
            IPS_SetMediaCached($id, false);
            IPS_SetMediaFile($id, 'AirPlaySprache_' . $this->InstanceID . '_' . $kennung . '.wav', false);
            IPS_SetMediaContent($id, base64_encode($wav));
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function speicherText(): string
    {
        $eintraege = $this->speicherEintraege();
        return AirPlayText::t('💾 %1$s of at most %2$s recordings stored.', count($eintraege), $this->ReadPropertyInteger('CacheMax'));
    }

    // ------------------------------------------------------------------ Formular

    protected function airplayPrefix(): string
    {
        return 'AIRPS';
    }

    protected function airplayZweck(): array
    {
        return [
            'Turns text into speech for the HomePods — with Piper in your own network or an online service (Microsoft Azure, ElevenLabs, Google Cloud). One instance serves all AirPlay devices.',
            'Select this instance in each AirPlay device (panel "Speech output"). Then AIRP_Say, AIRP_SayAsync and the scheduled announcements speak with the voice set here.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'Piper: free and offline, needs a Piper server in your network (e.g. the Docker container rhasspy/wyoming-piper, guide in the README). No key.',
            'Microsoft Azure: in the Azure portal create a resource "Speech" (free tier F0 includes 500,000 characters per month). Copy "Key 1" and the region (e.g. germanywestcentral). German voices e.g. de-DE-KatjaNeural, de-DE-ConradNeural.',
            'ElevenLabs: sign up on elevenlabs.io, create an API key in the profile. The free plan includes about 10,000 characters per month. Enter a voice ID ("Show voices").',
            'Google Cloud: create a project, enable "Cloud Text-to-Speech API", set up billing and create an API key (restrict it to this API). Without a voice, Google picks a German standard voice.',
            'Cache: identical texts with the same voice are created only once and then played from Symcon — the hourly time announcement, for example, costs quota only on the first day. The recordings are hidden sound media below this instance.',
            'Scripts: AIRPS_GetSpeech(id, text) returns the WAV file as Base64 (empty on error, reason via AIRPS_GetLastError(id)); AIRPS_ClearCache(id) deletes all recordings.',
        ];
    }

    public function GetConfigurationForm()
    {
        $dienst = $this->ReadPropertyString('Service');
        $dienste = [
            ['caption' => 'Piper (own network, free)', 'value' => 'piper'],
            ['caption' => 'Microsoft Azure', 'value' => 'azure'],
            ['caption' => 'ElevenLabs', 'value' => 'elevenlabs'],
            ['caption' => 'Google Cloud', 'value' => 'google'],
        ];
        $einstellungen = [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🗣️  Speech service',
            'items' => [
                ['type' => 'Select', 'name' => 'Service', 'caption' => 'Service', 'options' => $dienste, 'width' => '320px'],
                ['type' => 'Label', 'caption' => 'After changing the service, click "Apply changes" — the fields below adapt.'],
                ['type' => 'ValidationTextBox', 'name' => 'PiperServer', 'caption' => 'Piper server (host or host:port, port 10200)', 'width' => '320px', 'visible' => $dienst === 'piper'],
                ['type' => 'ValidationTextBox', 'name' => 'AzureRegion', 'caption' => 'Azure region (e.g. germanywestcentral, westeurope)', 'width' => '320px', 'visible' => $dienst === 'azure'],
                ['type' => 'ValidationTextBox', 'name' => 'ElevenLabsModel', 'caption' => 'ElevenLabs model', 'width' => '320px', 'visible' => $dienst === 'elevenlabs'],
                ['type' => 'Label', 'name' => 'KeyState', 'caption' => $this->schluesselText($dienst)],
                ['type' => 'RowLayout', 'visible' => $dienst !== 'piper', 'items' => [
                    ['type' => 'PasswordTextBox', 'name' => 'KeyInput', 'caption' => 'API key (stored separately, not shown again)', 'width' => '420px'],
                    ['type' => 'Button', 'caption' => '🔑 Save key', 'onClick' => 'AIRPS_UISaveKey($id, $Service, $KeyInput);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Voice', 'caption' => 'Voice (empty = default of the service)', 'width' => '320px'],
                    ['type' => 'Button', 'caption' => '🔍 Show voices', 'onClick' => 'AIRPS_UIVoices($id);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'TestText', 'caption' => 'Test text', 'value' => AirPlayText::t('This is a test of the speech output.'), 'width' => '420px'],
                    ['type' => 'Button', 'caption' => '▶️ Create speech', 'onClick' => 'AIRPS_UITest($id, $TestText);'],
                ]],
            ],
        ];
        $speicher = [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '💾  Cache',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'Cache', 'caption' => 'Store recordings and reuse them for identical texts'],
                ['type' => 'NumberSpinner', 'name' => 'CacheMax', 'caption' => 'Keep at most (recordings)', 'minimum' => 1, 'maximum' => 1000],
                ['type' => 'Label', 'name' => 'CacheState', 'caption' => $this->speicherText()],
                ['type' => 'Button', 'caption' => '🗑️ Clear cache', 'onClick' => 'AIRPS_UIClearCache($id);', 'confirm' => 'Delete all stored recordings?'],
            ],
        ];
        return $this->airplayFormular([$einstellungen, $speicher], [], [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Ready'],
            ['code' => self::STATUS_SCHLUESSEL_FEHLT, 'icon' => 'inactive', 'caption' => 'Please save the API key'],
            ['code' => self::STATUS_SERVER_FEHLT, 'icon' => 'inactive', 'caption' => 'Please enter the Piper server'],
        ]);
    }
}
