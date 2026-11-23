<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayGeraetDurchsage.php';
require_once __DIR__ . '/../libs/AirPlayGeraetWiedergabe.php';
require_once __DIR__ . '/../libs/AirPlayGeraetZeitplan.php';
require_once __DIR__ . '/../libs/AirPlayGeraetOberflaeche.php';

// ===========================================================================
// AirPlay-Gerät — ein AirPlay-Empfänger (HomePod, Apple TV, Fremdgerät).
//
// Die Klasse hält nur Lebenszyklus, Aktionen und die gemeinsame Infrastruktur
// (Sperre, Sitzung, Ergebnis). Die Fachlogik liegt in Traits:
//   AirPlayGeraetDurchsage   Durchsagen, Sprache, Klänge, Warteschlange, Nachtgrenze, Protokoll
//   AirPlayGeraetWiedergabe  Anzeige und Steuerung (MRP), Apple Music, Favoriten, Variablen
//   AirPlayGeraetZeitplan    geplante Ansagen und Wecker (ein Timer), Lautstärke-Anstieg
//   AirPlayGeraetOberflaeche Konfigurationsformular und Kachel
// Jede Aktion am Gerät öffnet eine eigene kurze Sitzung (libs/AirPlayAblauf.php);
// eine Instanz-Sperre verhindert, dass zwei Sitzungen gleichzeitig laufen.
// ===========================================================================

class AirPlayGeraet extends IPSModule
{
    use AirPlayFormular;
    use AirPlayGeraetDurchsage;
    use AirPlayGeraetWiedergabe;
    use AirPlayGeraetZeitplan;
    use AirPlayGeraetOberflaeche;

    private const STATUS_ADRESSE_FEHLT = 201;
    private const STATUS_PHP_FEHLT = 202;
    private const STATUS_NICHT_ERREICHBAR = 203;
    private const GUID_SPRACHE = '{1995F9C5-57BE-4CCB-8980-07D0D4E1867E}';
    /** Raumklima in der Kachel: Eigenschaft → Schlüssel in den Kacheldaten */
    /** Cover in der Kachel: Einstellung → Kantenlänge in px (0 = ausgeblendet) */
    private const COVER_GROESSEN = ['off' => 0, 'small' => 72, 'medium' => 112, 'large' => 160];
    private const KLIMA_VARIABLEN = ['TemperatureVariable' => 'temperatur', 'HumidityVariable' => 'feuchte'];

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        AirPlayText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();
        // Verbindung und Anzeige
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', AirPlaySitzung::PORT);
        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('Model', '');
        $this->RegisterPropertyBoolean('RemoteControl', true);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyString('Favorites', '[]');
        $this->RegisterPropertyString('TileCover', 'small');
        $this->RegisterPropertyInteger('TemperatureVariable', 0);
        $this->RegisterPropertyInteger('HumidityVariable', 0);
        // Durchsagen
        $this->RegisterPropertyBoolean('SetVolume', true);
        $this->RegisterPropertyInteger('Volume', 30);
        $this->RegisterPropertyBoolean('ResumeAfterAnnouncement', true);
        $this->RegisterPropertyBoolean('NightLimit', false);
        $this->RegisterPropertyInteger('NightVolume', 15);
        $this->RegisterPropertyInteger('NightFrom', 22);
        $this->RegisterPropertyInteger('NightTo', 7);
        $this->RegisterPropertyString('Groups', '');
        $this->RegisterPropertyInteger('PresenceVariable', 0);
        $this->RegisterPropertyBoolean('PresenceSchedule', true);
        $this->RegisterPropertyBoolean('PresenceQueue', false);
        // Sprache (PiperServer/PiperVoice: direkte Einstellung aus 0.5.0–0.7.0, Rückfall ohne Sprachausgabe-Instanz)
        $this->RegisterPropertyInteger('SpeechInstance', 0);
        $this->RegisterPropertyString('PiperServer', '');
        $this->RegisterPropertyString('PiperVoice', '');
        // Zeitplan
        $this->RegisterPropertyString('Schedule', '[]');
        $this->RegisterPropertyString('Alarms', '[]');

        $this->RegisterAttributeString('LastResult', '');
        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('CoverKey', '');
        $this->RegisterAttributeBoolean('Reachable', true);
        $this->RegisterAttributeInteger('ScheduleNext', 0);
        $this->RegisterAttributeString('Queue', '[]');
        $this->RegisterAttributeString('Ramp', '');

        // Interne Abläufe über RequestAction statt öffentlicher Funktionen (siehe RequestAction)
        $this->RegisterTimer('Update', 0, 'AIRP_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Schedule', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'RunSchedule\', 0);');
        $this->RegisterTimer('Queue', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'RunQueue\', 0);');
        $this->RegisterTimer('Ramp', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'RunRamp\', 0);');
        $this->SetVisualizationType(1);
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $bereit = $this->statusPruefen();
        if ($this->fernsteuerungAn()) {
            $this->variablenSicherstellen();
        }
        $sekunden = $this->fernsteuerungAn() && $bereit ? max(0, $this->ReadPropertyInteger('UpdateInterval')) : 0;
        $this->SetTimerInterval('Update', $sekunden > 0 ? max(5, $sekunden) * 1000 : 0);
        $this->zeitplanStellen($bereit ? time() + 1 : null);
        $modell = $this->ReadPropertyString('Model');
        $this->SetSummary(trim($this->host() . ($modell !== '' ? ' · ' . $modell : '')));
        $this->klimaBeobachten();
        $this->kachelAktualisieren(true);
    }

    /** Ändert sich ein Raumklima-Wert, sofort die Kachel nachziehen (nicht erst bei der nächsten Abfrage). */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Während des Starts oder beim Modul-Update liefern die SDK-Aufrufe noch keine gültigen Werte (SUITE.md 9c)
        if ($Message === VM_UPDATE && IPS_GetKernelRunlevel() === KR_READY && IPS_InstanceExists($this->InstanceID)) {
            $this->kachelAktualisieren();
        }
    }

    /** Meldungen nur von den aktuell gewählten Raumklima-Variablen empfangen. */
    private function klimaBeobachten(): void
    {
        foreach ($this->GetMessageList() as $sender => $meldungen) {
            if (in_array(VM_UPDATE, $meldungen, true)) {
                $this->UnregisterMessage($sender, VM_UPDATE);
            }
        }
        foreach (array_keys(self::KLIMA_VARIABLEN) as $eigenschaft) {
            $id = $this->ReadPropertyInteger($eigenschaft);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }
    }

    /** Variablen der Fernsteuerung, Kachel-Aktionen und interne Timer-Abläufe. */
    public function RequestAction($Ident, $Value)
    {
        match ($Ident) {
            'Steuerung' => $this->steuern((int)$Value),
            'Lautstaerke' => $this->SetVolume((int)$Value),
            'Favorite' => $this->favoritSpielen((int)$Value),
            'Say' => $this->SayAsync((string)$Value, -1, false),
            'RunQueue' => $this->warteschlangeAbarbeiten(),
            'RunSchedule' => $this->zeitplanAusfuehren(),
            'RunRamp' => $this->rampeWeiter(),
            default => throw new Exception('Invalid Ident'),
        };
    }

    // ------------------------------------------------------------------ gemeinsame Infrastruktur

    /** Status aus Erweiterungen, Adresse und Erreichbarkeit; true = Gerät ansprechbar. */
    private function statusPruefen(): bool
    {
        if ($this->fehlendeErweiterungen() !== []) {
            $this->SetStatus(self::STATUS_PHP_FEHLT);
            return false;
        }
        if ($this->host() === '') {
            $this->SetStatus(self::STATUS_ADRESSE_FEHLT);
            return false;
        }
        $this->SetStatus($this->ReadAttributeBoolean('Reachable') ? IS_ACTIVE : self::STATUS_NICHT_ERREICHBAR);
        return true;
    }

    private function ablauf(): AirPlayAblauf
    {
        if (($fehlt = $this->fehlendeErweiterungen()) !== []) {
            throw new AirPlayFehler('PHP extension missing: %s', implode(', ', $fehlt));
        }
        if ($this->host() === '') {
            throw new AirPlayFehler('No address entered.');
        }
        return new AirPlayAblauf($this->host(), fn (string $t) => $this->SendDebug('AirPlay', $t, 0), $this->ReadPropertyInteger('Port'));
    }

    /**
     * Arbeit unter der Instanz-Sperre ausführen (eine Sitzung je Gerät); Fehler als Ergebnis melden.
     * $warten = 0: nicht warten (Timer-Abfrage), sonst höchstens so viele Millisekunden.
     */
    private function mitSperre(callable $arbeit, string $fehlerVorlage, int $warten = 15000): bool
    {
        $sperre = 'AIRP_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, $warten)) {
            return $warten === 0 ? false : $this->fehler(AirPlayText::t('An announcement is already running on this device.'));
        }
        try {
            return (bool)$arbeit();
        } catch (Throwable $e) {
            return $this->fehler(AirPlayText::t($fehlerVorlage, AirPlayText::fehler($e)));
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    /** Kurzer kritischer Abschnitt unter einer benannten Sperre (Attribute, die mehrere Threads schreiben). */
    private function exklusiv(string $name, callable $arbeit): mixed
    {
        $sperre = 'AIRP_' . $name . '_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($sperre, 5000)) {
            throw new AirPlayFehler('the instance is busy, please try again');
        }
        try {
            return $arbeit();
        } finally {
            IPS_SemaphoreLeave($sperre);
        }
    }

    private function host(): string
    {
        return trim($this->ReadPropertyString('Host'));
    }

    private function fernsteuerungAn(): bool
    {
        return $this->ReadPropertyBoolean('RemoteControl');
    }

    private function fehlendeErweiterungen(): array
    {
        return array_values(array_filter(['gmp', 'sodium'], fn ($e) => !extension_loaded($e)));
    }

    /** @return list<array> Zeilen einer Listen-Eigenschaft (JSON), kaputte Zeilen ausgelassen */
    private function liste(string $eigenschaft): array
    {
        $liste = json_decode($this->ReadPropertyString($eigenschaft), true);
        return is_array($liste) ? array_values(array_filter($liste, 'is_array')) : [];
    }

    // ------------------------------------------------------------------ Ergebnis der letzten Aktion
    // Gespeichert als {zeit, ok, text}; die Anzeige formatiert erst beim Lesen (Formular, Kachel, Protokoll).

    private function ergebnis(string $text): void
    {
        $this->WriteAttributeString('LastResult', (string)json_encode(['zeit' => time(), 'ok' => true, 'text' => $text]));
        $this->SendDebug('Ergebnis', $text, 0);
    }

    private function fehler(string $text): bool
    {
        $this->WriteAttributeString('LastResult', (string)json_encode(['zeit' => time(), 'ok' => false, 'text' => $text]));
        $this->LogMessage($text, KL_WARNING);
        return false;
    }

    /** @return array{zeit:int, ok:bool, text:string}|null letztes Ergebnis; null = noch keins */
    private function letztesErgebnis(): ?array
    {
        $e = json_decode($this->ReadAttributeString('LastResult'), true);
        return is_array($e) && isset($e['text']) ? $e : null;   // Format vor 0.9.0 (Text) zählt als „noch keins"
    }

    /** Letztes Ergebnis zum Anzeigen: Zeit, ❌ bei Fehlern, Text. */
    private function ergebnisAnzeige(bool $mitZeit = true): string
    {
        $e = $this->letztesErgebnis();
        if ($e === null) {
            return '';
        }
        return ($mitZeit ? date('d.m.Y H:i:s', (int)$e['zeit']) . ' ' : '') . ($e['ok'] ? '' : '❌ ') . $e['text'];
    }

    /** Ergebnis der letzten Aktion als Rückmeldung für Formular-Knöpfe. */
    private function rueckmeldung(): void
    {
        echo $this->ergebnisAnzeige();
    }
}
