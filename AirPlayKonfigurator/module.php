<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayGeraete.php';

// ===========================================================================
// AirPlay-Konfigurator — findet AirPlay-Empfänger im Netz und legt sie an.
//
// Suche über Symcons DNS-SD-Instanz (mDNS, Dienst _airplay._tcp). Der TXT-
// Eintrag liefert Modell, Geräte-ID, Fähigkeiten und Kopplungsbedarf; daraus
// ordnet libs/AirPlayGeraete.php ein, ob das Gerät Durchsagen annimmt.
// Geräte, die eine PIN-Kopplung verlangen (z. B. viele Fernseher), werden
// angezeigt, aber nicht zum Anlegen angeboten.
// Vorhandene Instanzen erkennt der Konfigurator an der Geräte-ID.
// ===========================================================================

class AirPlayKonfigurator extends IPSModule
{
    use AirPlayFormular;

    private const DNSSD = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const GERAET = '{2BB8B63A-9A5A-4704-8230-C18259F9F3E7}';
    private const DIENST = '_airplay._tcp';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        AirPlayText::setzen(fn (string $text): string => $this->Translate($text));
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeInteger('LastDiscoveryCount', 0);
        $this->registerFormAttributes();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus(IS_ACTIVE);
    }

    // ------------------------------------------------------------------ öffentlich

    /** Formular-Knopf: neu suchen und Liste samt Kopfzeile auffrischen. */
    public function Search(): void
    {
        $gefunden = $this->suchen();
        $this->UpdateFormField('Kopfzeile', 'caption', $this->kopfzeile());
        $this->UpdateFormField('Geraete', 'values', (string)json_encode($this->zeilen($gefunden)));
        $n = count($gefunden);
        echo AirPlayText::t($n === 1 ? '🔍 %s AirPlay device found.' : '🔍 %s AirPlay devices found.', $n);
    }

    /** Gefundene Geräte als JSON (Name, Adresse, Modell, Einordnung). */
    public function GetDevices(): string
    {
        return (string)json_encode(array_values($this->suchen()));
    }

    // ------------------------------------------------------------------ Suche

    /** @return array<string, array> Schlüssel = Geräte-ID bzw. Name */
    private function suchen(): array
    {
        $gefunden = [];
        $dnssd = IPS_GetInstanceListByModuleID(self::DNSSD);
        if ($dnssd !== []) {
            $dienste = @ZC_QueryServiceType($dnssd[0], self::DIENST, '');
            foreach (is_array($dienste) ? $dienste : [] as $d) {
                $antwort = @ZC_QueryService($dnssd[0], (string)$d['Name'], (string)($d['Type'] ?? self::DIENST), (string)($d['Domain'] ?? 'local'));
                $a = is_array($antwort) && isset($antwort[0]) ? $antwort[0] : null;
                if ($a === null) {
                    continue;
                }
                $einordnung = AirPlayGeraete::einordnen(AirPlayGeraete::txt((array)($a['TXTRecords'] ?? [])));
                $name = (string)preg_replace('/\._airplay\._tcp.*$/', '', (string)$d['Name']);
                $g = $einordnung + [
                    'name' => $name,
                    'adresse' => (string)($a['IPv4'][0] ?? rtrim((string)($a['Host'] ?? ''), '.')),
                    'port' => (int)($a['Port'] ?? 7000),
                ];
                $gefunden[$g['deviceId'] !== '' ? $g['deviceId'] : $name] = $g;
            }
        }
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->WriteAttributeInteger('LastDiscoveryCount', count($gefunden));
        return $gefunden;
    }

    private static function typText(array $g): string
    {
        return match ($g['familie']) {
            'homepod', 'appletv', 'appletv_alt', 'airport' => AirPlayText::t($g['modellText']),
            'mac' => 'Mac',
            default => trim(($g['hersteller'] !== '' ? $g['hersteller'] . ' ' : '') . $g['modellText']) ?: AirPlayText::t('AirPlay receiver'),
        };
    }

    private static function durchsageText(array $g): string
    {
        return match ($g['durchsage']) {
            'ja' => AirPlayText::t('✅ yes'),
            'ungeprueft' => AirPlayText::t('⚠️ untested'),
            default => AirPlayText::t('❌ no (%s)', AirPlayText::t($g['grund'])),
        };
    }

    // ------------------------------------------------------------------ Formular

    private function zeilen(array $gefunden): array
    {
        $instanzen = [];
        foreach (IPS_GetInstanceListByModuleID(self::GERAET) as $id) {
            $instanzen[$id] = [
                'deviceId' => strtoupper(trim((string)IPS_GetProperty($id, 'DeviceID'))),
                'adresse' => trim((string)IPS_GetProperty($id, 'Host')),
            ];
        }
        $zeilen = [];
        foreach ($gefunden as $g) {
            $treffer = 0;
            foreach ($instanzen as $id => $i) {
                if (($g['deviceId'] !== '' && $g['deviceId'] === $i['deviceId']) || ($i['deviceId'] === '' && $i['adresse'] === $g['adresse'])) {
                    $treffer = $id;
                    break;
                }
            }
            if ($treffer !== 0) {
                unset($instanzen[$treffer]);
            }
            $zeile = [
                'name' => $treffer !== 0 ? IPS_GetName($treffer) : $g['name'],
                'typ' => self::typText($g),
                'adresse' => $g['adresse'],
                'software' => $g['os'],
                'durchsage' => self::durchsageText($g),
                'instanceID' => $treffer,
            ];
            if ($g['durchsage'] !== 'nein' || $treffer !== 0) {
                $zeile['create'] = [
                    'moduleID' => self::GERAET,
                    'configuration' => ['Host' => $g['adresse'], 'Port' => $g['port'], 'DeviceID' => $g['deviceId'], 'Model' => self::typText($g), 'RemoteControl' => $g['steuerung']],
                    'name' => $g['name'],
                ];
            }
            $zeilen[] = $zeile;
        }
        foreach ($instanzen as $id => $i) {
            $zeilen[] = [
                'name' => IPS_GetName($id), 'typ' => (string)IPS_GetProperty($id, 'Model'), 'adresse' => $i['adresse'],
                'software' => '', 'durchsage' => AirPlayText::t('not found'), 'instanceID' => $id,
            ];
        }
        return $zeilen;
    }

    private function kopfzeile(): string
    {
        if (IPS_GetInstanceListByModuleID(self::DNSSD) === []) {
            return AirPlayText::t('⚠️ No DNS-SD instance found — the network search needs it (core instances).');
        }
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return AirPlayText::t('ℹ️ Not searched yet.');
        }
        $n = $this->ReadAttributeInteger('LastDiscoveryCount');
        return ($n > 0 ? '✅ ' : 'ℹ️ ') . AirPlayText::t($n === 1 ? '%s AirPlay device found (last search %s).' : '%s AirPlay devices found (last search %s).', $n, date('H:i:s', $ts));
    }

    protected function airplayPrefix(): string
    {
        return 'AIRPC';
    }

    protected function airplayZweck(): array
    {
        return [
            'Finds HomePods, Apple TVs and other AirPlay receivers on the home network and creates them with one click as AirPlay device.',
            'The column "Announcement" shows which devices can play announcements. Devices that require pairing with a PIN (e.g. many TVs) are listed but cannot be created.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'The search uses the DNS-SD instance of Symcon to look for the service _airplay._tcp. That only reaches the same network segment: behind VLAN boundaries, Docker without host network or VPN the search finds nothing. In that case create an AirPlay device manually and enter the address.',
            '"✅ yes" means tested on real hardware. "⚠️ untested" means the device offers the required AirPlay 2 pairing, but nobody has tried it yet — feedback is welcome.',
            'The configurator recognises existing instances by the device ID, so a changed IP address does not lead to a second entry. Enter the new address in the instance.',
        ];
    }

    public function GetConfigurationForm()
    {
        $gefunden = $this->suchen();
        $suche = [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '🔍  AirPlay devices on the network',
            'items' => [
                ['type' => 'Button', 'caption' => '🔍 Search again', 'onClick' => 'AIRPC_Search($id);'],
                ['type' => 'Label', 'name' => 'Kopfzeile', 'caption' => $this->kopfzeile()],
                [
                    'type' => 'Configurator', 'name' => 'Geraete', 'caption' => 'AirPlay devices', 'rowCount' => 12,
                    'add' => false, 'delete' => true, 'sort' => ['column' => 'name', 'direction' => 'ascending'],
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Device type', 'name' => 'typ', 'width' => '230px'],
                        ['caption' => 'Address', 'name' => 'adresse', 'width' => '140px'],
                        ['caption' => 'Software', 'name' => 'software', 'width' => '100px'],
                        ['caption' => 'Announcement', 'name' => 'durchsage', 'width' => '300px'],
                    ],
                    'values' => $this->zeilen($gefunden),
                ],
            ],
        ];
        return $this->airplayFormular([$suche], [], [['code' => 102, 'icon' => 'active', 'caption' => 'Ready']]);
    }
}
