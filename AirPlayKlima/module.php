<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayKlimaDaten.php';

// ===========================================================================
// AirPlay-Klima — Temperatur und Luftfeuchte der HomePods (mini, 2. Gen.) als
// echte Symcon-Variablen.
//
// Apple gibt die Sensorwerte nicht über das Netzwerk heraus. Weg (nach
// vdamboeck/homepod-ha-sensors, für Symcon umgesetzt):
//  1. Diese Instanz schaltet im eingestellten Abstand die Variable
//     „Messung anstoßen" kurz ein. paresys HomeKit-Bridge bietet sie in
//     Apple Home als Schalter an.
//  2. Eine Home-Automation „wenn der Schalter eingeschaltet wird", in einen
//     Kurzbefehl umgewandelt, liest per „Status abrufen" die Sensoren und
//     schickt sie per „Inhalte von URL abrufen" (POST, JSON) an den WebHook
//     dieser Instanz.
//  3. Der WebHook legt je HomePod Temperatur- und Feuchte-Variablen an und
//     schreibt die Werte.
// Der WebHook ist ohne Symcon-Anmeldung erreichbar und verlangt deshalb den
// geheimen Schlüssel aus dem Formular.
// ===========================================================================

class AirPlayKlima extends IPSModule
{
    use AirPlayFormular;

    private const WEBHOOK_GUID = '{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const STATUS_KEIN_WEBHOOK = 201;
    private const STATUS_VERALTET = 202;
    /** So lange bleibt der Auslöse-Schalter eingeschaltet (Sekunden). */
    private const SCHALTDAUER = 5;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        AirPlayText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('Interval', 10);
        $this->RegisterAttributeString('Key', '');
        $this->RegisterAttributeInteger('LastReceived', 0);
        $this->RegisterAttributeString('Rooms', '{}');
        $this->RegisterTimer('Trigger', 0, 'AIRPK_Trigger($_IPS[\'TARGET\']);');
        $this->RegisterTimer('TriggerOff', 0, 'AIRPK_TriggerOff($_IPS[\'TARGET\']);');
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        if ($this->ReadAttributeString('Key') === '') {
            $this->WriteAttributeString('Key', bin2hex(random_bytes(12)));
        }
        if (@$this->GetIDForIdent('Trigger') === false) {
            $this->MaintainVariable('Trigger', AirPlayText::t('Trigger measurement'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 1, true);
        }
        $this->EnableAction('Trigger');
        $minuten = max(0, (int)$this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Trigger', $minuten > 0 ? max(2, $minuten) * 60000 : 0);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELMESSAGE);
            return;
        }
        $this->hookRegistrieren();
        $this->statusSetzen();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELMESSAGE && ($Data[0] ?? null) === KR_READY) {
            $this->hookRegistrieren();
            $this->statusSetzen();
        }
    }

    public function Destroy()
    {
        // WebHook nur austragen, wenn die Instanz wirklich gelöscht wird (nicht beim Neuladen des Moduls)
        if (!IPS_InstanceExists($this->InstanceID)) {
            $this->hookAustragen();
        }
        parent::Destroy();
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident !== 'Trigger') {
            throw new Exception('Invalid Ident');
        }
        $this->SetValue('Trigger', (bool)$Value);
        if ((bool)$Value) {
            $this->SetTimerInterval('TriggerOff', self::SCHALTDAUER * 1000);
        }
    }

    // ------------------------------------------------------------------ öffentlich

    /** Auslöse-Schalter kurz einschalten: Apple Home startet daraufhin die Messung (Timer und Formular). */
    public function Trigger(): void
    {
        $this->SetValue('Trigger', false);
        $this->SetValue('Trigger', true);
        $this->SetTimerInterval('TriggerOff', self::SCHALTDAUER * 1000);
    }

    /** Auslöse-Schalter wieder aus (intern, Timer). */
    public function TriggerOff(): void
    {
        $this->SetTimerInterval('TriggerOff', 0);
        $this->SetValue('Trigger', false);
        $this->statusSetzen();
    }

    /** Zuletzt empfangene Werte je HomePod als JSON. */
    public function GetReadings(): string
    {
        return (string)json_encode($this->raeume());
    }

    public function UITrigger(): void
    {
        $this->Trigger();
        echo AirPlayText::t('Switch turned on. If the Apple Home automation is set up, the values arrive within a few seconds — then reopen this form.');
    }

    public function UINewKey(): void
    {
        $this->WriteAttributeString('Key', bin2hex(random_bytes(12)));
        $this->UpdateFormField('HookUrl', 'value', $this->hookUrl());
        echo AirPlayText::t('New key created. Update the URL in the Apple Home shortcut, otherwise the values are rejected.');
    }

    // ------------------------------------------------------------------ WebHook

    public function ProcessHookData()
    {
        header('Content-Type: application/json; charset=utf-8');
        $schluessel = (string)($_GET['key'] ?? '');
        if ($schluessel === '' || !hash_equals($this->ReadAttributeString('Key'), $schluessel)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'text' => 'key missing or wrong']);
            return;
        }
        // Rohdaten (ohne Schlüssel) fürs Debug-Fenster — Kurzbefehle schreiben Messwerte je nach iOS-Sprache unterschiedlich.
        $roh = $_GET;
        unset($roh['key']);
        $this->SendDebug('WebHook roh', json_encode($roh, JSON_UNESCAPED_UNICODE) . ' | ' . substr((string)file_get_contents('php://input'), 0, 500), 0);
        try {
            $werte = AirPlayKlimaDaten::ausAufruf($_GET, (string)file_get_contents('php://input'));
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'text' => $e->getMessage()]);
            $this->SendDebug('WebHook', 'abgelehnt: ' . $e->getMessage(), 0);
            return;
        }
        $raeume = $this->raeume();
        foreach ($werte as $w) {
            $kennung = AirPlayKlimaDaten::kennung($w['name']);
            if ($w['temperatur'] !== null) {
                $this->wertSchreiben('T' . $kennung, AirPlayText::t('%s temperature', $w['name']), round($w['temperatur'], 1),
                    ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1]);
            }
            if ($w['feuchte'] !== null) {
                $this->wertSchreiben('H' . $kennung, AirPlayText::t('%s humidity', $w['name']), round($w['feuchte'], 1),
                    ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0]);
            }
            $raeume[$w['name']] = ['temperatur' => $w['temperatur'], 'feuchte' => $w['feuchte'], 'zeit' => time()];
        }
        $this->WriteAttributeString('Rooms', (string)json_encode($raeume));
        $this->WriteAttributeInteger('LastReceived', time());
        $this->statusSetzen();
        $this->SendDebug('WebHook', json_encode($werte), 0);
        echo json_encode(['ok' => true, 'received' => count($werte)]);
    }

    /**
     * Zuletzt empfangene Werte je HomePod — nur Räume, deren Variablen noch existieren
     * (löscht der Nutzer die Variablen eines Raums, verschwindet er auch aus der Liste).
     */
    private function raeume(): array
    {
        $raeume = json_decode($this->ReadAttributeString('Rooms'), true) ?: [];
        return array_filter($raeume, function ($r, $name): bool {
            $k = AirPlayKlimaDaten::kennung((string)$name);
            return @$this->GetIDForIdent('T' . $k) !== false || @$this->GetIDForIdent('H' . $k) !== false;
        }, ARRAY_FILTER_USE_BOTH);
    }

    /** Variable anlegen, falls sie fehlt (Darstellung und Position gehören danach dem Nutzer), und Wert setzen. */
    private function wertSchreiben(string $ident, string $name, float $wert, array $darstellung): void
    {
        if (@$this->GetIDForIdent($ident) === false) {
            $this->MaintainVariable($ident, $name, VARIABLETYPE_FLOAT, $darstellung, 10 + count(IPS_GetChildrenIDs($this->InstanceID)), true);
        }
        $this->SetValue($ident, $wert);
    }

    private function hookPfad(): string
    {
        return '/hook/airplayklima' . $this->InstanceID;
    }

    private function hookUrl(): string
    {
        $ip = '<Symcon-IP>';
        foreach (function_exists('Sys_GetNetworkInfo') ? (array)@Sys_GetNetworkInfo() : [] as $netz) {
            $kandidat = (string)($netz['IP'] ?? '');
            if (filter_var($kandidat, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($kandidat, '127.') && !str_starts_with($kandidat, '172.17.')) {
                $ip = $kandidat;
                break;
            }
        }
        return 'http://' . $ip . ':3777' . $this->hookPfad() . '?key=' . $this->ReadAttributeString('Key');
    }

    /** WebHook beim WebHook-Control eintragen (Verbund-Muster wie RoboHub/NRGDashboard). */
    private function hookRegistrieren(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if ($ids === []) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        $hooks = is_array($hooks) ? $hooks : [];
        foreach ($hooks as $i => $hook) {
            if (($hook['Hook'] ?? '') === $this->hookPfad()) {
                if ((int)($hook['TargetID'] ?? 0) === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => $this->hookPfad(), 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    private function hookAustragen(): void
    {
        $ids = IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID);
        if ($ids === []) {
            return;
        }
        $hooks = json_decode((string)IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            return;
        }
        $behalten = array_values(array_filter($hooks, fn ($h) => !(($h['Hook'] ?? '') === $this->hookPfad() && (int)($h['TargetID'] ?? 0) === $this->InstanceID)));
        if (count($behalten) !== count($hooks)) {
            IPS_SetProperty($ids[0], 'Hooks', json_encode($behalten));
            IPS_ApplyChanges($ids[0]);
        }
    }

    /**
     * 102, solange Werte im erwarteten Abstand kommen; 202, wenn seit drei Abständen nichts kam; 201 ohne WebHook-Control.
     * Solange noch kein HomePod gemeldet hat (Einrichtung läuft), nie 202 — das Formular zeigt dann einen Hinweis.
     */
    private function statusSetzen(): void
    {
        if (IPS_GetInstanceListByModuleID(self::WEBHOOK_GUID) === []) {
            $this->SetStatus(self::STATUS_KEIN_WEBHOOK);
            return;
        }
        $letzter = $this->ReadAttributeInteger('LastReceived');
        $minuten = (int)$this->ReadPropertyInteger('Interval');
        $veraltet = $this->raeume() !== [] && $letzter > 0 && $minuten > 0 && time() - $letzter > 3 * max(2, $minuten) * 60;
        $this->SetStatus($veraltet ? self::STATUS_VERALTET : IS_ACTIVE);
    }

    // ------------------------------------------------------------------ Formular

    protected function airplayPrefix(): string
    {
        return 'AIRPK';
    }

    protected function airplayZweck(): array
    {
        return [
            'Brings the temperature and humidity of your HomePods (HomePod mini, HomePod 2nd generation) into Symcon as real variables.',
            'Apple does not hand out these values over the network. An Apple Home automation reads them and sends them to this instance; the HomeKit bridge of Symcon triggers it at the set interval. Set it up once with the steps below.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'Only HomePod mini and HomePod (2nd generation) have temperature and humidity sensors. The first-generation HomePod has none.',
            'For each HomePod, the instance creates "<name> temperature" and "<name> humidity" on the first reception. The name is the one you enter in the shortcut.',
            'Status "No values for a while" means: a HomePod has reported before, but nothing arrived for three intervals. Check the Apple Home automation (it needs a home hub; after iOS updates, automations sometimes have to be switched off and on again).',
            'Accepted JSON: {"name": "...", "temperature": ..., "humidity": ...} — German keys (temperatur, feuchte) and values with unit ("21,5 °C", "44 %") work as well, also a list of such objects.',
        ];
    }

    public function GetConfigurationForm()
    {
        $letzter = $this->ReadAttributeInteger('LastReceived');
        $eingerichtet = $this->raeume() !== [];
        $zeilen = [];
        foreach ($this->raeume() as $name => $r) {
            $zeilen[] = [
                'name' => (string)$name,
                'temperatur' => $r['temperatur'] !== null ? number_format((float)$r['temperatur'], 1, ',', '') . ' °C' : '–',
                'feuchte' => $r['feuchte'] !== null ? number_format((float)$r['feuchte'], 0, ',', '') . ' %' : '–',
                'zeit' => date('d.m.Y H:i', (int)$r['zeit']),
            ];
        }
        $trigger = @$this->GetIDForIdent('Trigger');
        $empfang = [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🌡️  Reception & trigger',
            'items' => [
                ['type' => 'Label', 'caption' => $eingerichtet && $letzter > 0
                    ? AirPlayText::t('✅ Last values received: %s', date('d.m.Y H:i', $letzter))
                    : AirPlayText::t('ℹ️ No values received yet — set up the Apple Home automation (panel below).')],
                ['type' => 'List', 'name' => 'Readings', 'caption' => 'HomePods', 'rowCount' => max(2, count($zeilen)), 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Temperature', 'name' => 'temperatur', 'width' => '130px'],
                        ['caption' => 'Humidity', 'name' => 'feuchte', 'width' => '110px'],
                        ['caption' => 'Received', 'name' => 'zeit', 'width' => '150px'],
                    ],
                    'values' => $zeilen],
                ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Measure every', 'suffix' => 'min', 'minimum' => 0, 'maximum' => 1440],
                ['type' => 'Label', 'caption' => '0 = only when you switch "Trigger measurement" yourself. Values below 2 minutes are raised to 2.'],
                ['type' => 'Button', 'caption' => '📡 Trigger measurement now', 'onClick' => 'AIRPK_UITrigger($id);'],
            ],
        ];
        $anleitung = [
            'type' => 'ExpansionPanel', 'expanded' => !$eingerichtet, 'caption' => '📱  Set up Apple Home (once)',
            'items' => [
                ['type' => 'Label', 'bold' => true, 'caption' => 'A — Symcon: HomeKit bridge'],
                ['type' => 'Label', 'caption' => 'A1. Open the instance "HomeKit Bridge". In the list "Devices", click "Add".'],
                ['type' => 'Label', 'caption' => AirPlayText::t('A2. Name: "Klima-Messung". Type: "Switch". VariableID: the variable "Trigger measurement" (ID %s) below this instance. Confirm with OK.', $trigger !== false ? $trigger : '–')],
                ['type' => 'Label', 'caption' => 'A3. The column "Status" of the new row must show "OK". Then click "Apply changes". After a short while, "Klima-Messung" appears in the Home app as a switch.'],
                ['type' => 'Label', 'bold' => true, 'caption' => 'B — iPhone/iPad: Home app'],
                ['type' => 'Label', 'caption' => 'B1. Tab "Automation" → "+" → "An Accessory is Controlled" → select "Klima-Messung" → Next → "Turns On" → Next.'],
                ['type' => 'Label', 'caption' => 'B2. On the page for scenes and accessories, scroll to the very bottom → "Convert to Shortcut". (Names can differ slightly between iOS versions — if something looks different, send a screenshot.)'],
                ['type' => 'Label', 'bold' => true, 'caption' => 'C — iPhone/iPad: the shortcut'],
                ['type' => 'Label', 'caption' => 'C1. Add the Home action that reads a state: accessory "Temperature Sensor" in the room of your HomePod → "Current Temperature". The shortcut then shows "Temperature Sensor  Current Temperature". If there are several sensors in the room (e.g. from IKEA): in the Home app, the HomePod sensor shows "Apple Inc." as manufacturer and the model of your HomePod.'],
                ['type' => 'Label', 'caption' => 'C2. Below it, the same action a second time: "Humidity Sensor" in the same room → "Current Relative Humidity".'],
                ['type' => 'Label', 'caption' => 'C3. Below it, the action "Get Contents of URL". Paste the URL from the field below into the URL field — nothing after it. Expand the action (arrow "›"): Method "POST", Request Body "JSON".'],
                ['type' => 'ValidationTextBox', 'name' => 'HookUrl', 'caption' => 'URL for the shortcut (copy)', 'value' => $this->hookUrl(), 'width' => '720px'],
                ['type' => 'Label', 'caption' => 'The URL contains a secret key — treat it like a password. If the IP address is wrong, use the one under which iPhone and home hub reach Symcon (port 3777).'],
                ['type' => 'Label', 'caption' => 'C4. Add three fields with "Add new field" → "Text" (key on the left, value on the right): key name → value: your room name, e.g. Schlafzimmer; key temperature → value: the variable from C1; key humidity → value: the variable from C2. Insert variables via the bar above the keyboard or right-click → "Insert Variable"; they appear as coloured tokens. Type the keys exactly like this, without "=".'],
                ['type' => 'Label', 'caption' => 'Do not append the values to the URL: the shortcut inserts them with unit and space ("22,4 °C"), the URL ends at the space and the humidity is lost.'],
                ['type' => 'Label', 'caption' => 'C5. Tap "Done" and make sure the automation is switched on. More HomePods: repeat C1 to C4 in the same shortcut with another room name.'],
                ['type' => 'Label', 'bold' => true, 'caption' => 'D — Test'],
                ['type' => 'Label', 'caption' => 'D1. Click "📡 Trigger measurement now" in the panel above, wait about 10 seconds and reopen this form: the HomePod appears in the list, and its variables are created.'],
                ['type' => 'Label', 'caption' => 'D2. Nothing arrives? Open the debug window of this instance and run "Test this automation" in the Home app: the line "WebHook roh" (raw) shows exactly what the shortcut sent.'],
                ['type' => 'Button', 'caption' => '🔑 Create new key', 'onClick' => 'AIRPK_UINewKey($id);', 'confirm' => 'The old URL stops working. Create a new key?'],
            ],
        ];
        return $this->airplayFormular([$empfang, $anleitung], [], [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Ready'],
            ['code' => self::STATUS_KEIN_WEBHOOK, 'icon' => 'error', 'caption' => 'No WebHook Control instance found (core instances)'],
            ['code' => self::STATUS_VERALTET, 'icon' => 'inactive', 'caption' => 'No values for a while'],
        ]);
    }
}
