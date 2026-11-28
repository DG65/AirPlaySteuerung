<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/AirPlayFormular.php';
require_once __DIR__ . '/../libs/AirPlayGeraete.php';
require_once __DIR__ . '/../libs/AirPlaySitzung.php';

// ===========================================================================
// AirPlay-Konfigurator — findet AirPlay-Empfänger im Netz und legt sie an.
//
// Suche über Symcons DNS-SD-Instanz (mDNS, Dienst _airplay._tcp). Der TXT-
// Eintrag liefert Modell, Geräte-ID, Fähigkeiten und Kopplungsbedarf; daraus
// ordnet libs/AirPlayGeraete.php ein, ob das Gerät Durchsagen annimmt.
// Geräte, die eine PIN-Kopplung verlangen (z. B. viele Fernseher), werden
// angezeigt, aber nicht zum Anlegen angeboten.
// Vorhandene Instanzen erkennt der Konfigurator an der Geräte-ID.
// Adressen von Hand (ab 0.12.0, Forum 145020 #7): für Geräte, die die Suche
// nicht erreicht (anderes VLAN, VPN, Docker ohne Host-Netz). Das Gerät wird
// direkt per GET /info gefragt; dessen Feld txtAirPlay ist derselbe Bonjour-
// Eintrag wie bei der Suche. Gemerkt im Attribut ManualHosts.
// ===========================================================================

class AirPlayKonfigurator extends IPSModule
{
    use AirPlayFormular;

    private const DNSSD = '{780B2D48-916C-4D59-AD35-5A429B2355A5}';
    private const GERAET = '{2BB8B63A-9A5A-4704-8230-C18259F9F3E7}';
    private const DIENST = '_airplay._tcp';
    /** Zeitlimit je Adresse von Hand beim Öffnen des Formulars (Sekunden), damit es nicht hängt. */
    private const ZEITLIMIT_HAND = 2.0;

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
        $this->RegisterAttributeString('ManualHosts', '[]');
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

    /** Formular: Gerät per IP-Adresse oder Host-Name hinzufügen; es wird sofort gefragt, ob es antwortet. */
    public function AddAddress(string $Address): void
    {
        $adresse = trim($Address);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,252}$/', $adresse) !== 1) {
            echo AirPlayText::t('❌ Please enter an IP address or host name, e.g. 192.168.20.15.');
            return;
        }
        try {
            $g = self::abfragen($adresse, 5.0);
        } catch (Throwable $e) {
            echo AirPlayText::t('❌ %s does not answer as AirPlay device: %s', $adresse, $e instanceof AirPlayFehler ? AirPlayText::fehler($e) : $e->getMessage());
            return;
        }
        $hosts = $this->handAdressen();
        if (!in_array($adresse, $hosts, true)) {
            $hosts[] = $adresse;
            $this->WriteAttributeString('ManualHosts', (string)json_encode($hosts));
        }
        $this->Search();
        echo "\n" . AirPlayText::t('✅ %s (%s) added — create it in the list.', $g['name'] !== '' ? $g['name'] : $adresse, self::typText($g));
    }

    /** Formular: Adresse von Hand wieder entfernen (eine angelegte Instanz bleibt bestehen). */
    public function RemoveAddress(string $Address): void
    {
        $hosts = $this->handAdressen();
        $rest = array_values(array_filter($hosts, fn (string $h): bool => $h !== trim($Address)));
        if (count($rest) === count($hosts)) {
            echo AirPlayText::t('ℹ️ %s is not in the list of manual addresses.', trim($Address));
            return;
        }
        $this->WriteAttributeString('ManualHosts', (string)json_encode($rest));
        $this->Search();
        echo "\n" . AirPlayText::t('Address %s removed.', trim($Address));
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
        foreach ($this->handAdressen() as $adresse) {
            try {
                $g = self::abfragen($adresse, self::ZEITLIMIT_HAND);
            } catch (Throwable $e) {
                $g = AirPlayGeraete::einordnen([]) + ['name' => $adresse, 'adresse' => $adresse, 'port' => AirPlaySitzung::PORT];
                $g['durchsage'] = 'nein';
                $g['grund'] = 'not reachable';
            }
            $schluessel = $g['deviceId'] !== '' ? $g['deviceId'] : 'hand:' . $adresse;
            $gefunden[$schluessel] ??= $g;   // Findet die Suche das Gerät auch, gilt ihr Eintrag
        }
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->WriteAttributeInteger('LastDiscoveryCount', count($gefunden));
        return $gefunden;
    }

    /** @return list<string> */
    private function handAdressen(): array
    {
        $liste = json_decode($this->ReadAttributeString('ManualHosts'), true);
        return is_array($liste) ? array_values(array_filter($liste, 'is_string')) : [];
    }

    /** Gerät direkt fragen (GET /info, ohne Kopplung und ohne Ton). */
    private static function abfragen(string $adresse, float $zeitlimit): array
    {
        $s = new AirPlaySitzung($adresse, null, AirPlaySitzung::PORT, $zeitlimit);
        try {
            $info = $s->info();
        } finally {
            $s->schliessen();
        }
        return AirPlayGeraete::ausInfo($info) + ['adresse' => $adresse, 'port' => AirPlaySitzung::PORT];
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
            'The search uses the DNS-SD instance of Symcon to look for the service _airplay._tcp. That only reaches the same network segment: behind VLAN boundaries, Docker without host network or VPN the search finds nothing. In that case add the device below with its IP address — the configurator then asks the device directly.',
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
                ['type' => 'Label', 'caption' => 'Device not found (other VLAN, VPN, Docker without host network)? Enter its IP address — a fixed address (DHCP reservation) is recommended. Between VLANs the firewall must allow traffic between Symcon and the device in both directions (TCP 7000 and changing TCP/UDP ports).'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'ManualAddress', 'caption' => 'IP address or host name', 'width' => '260px'],
                    ['type' => 'Button', 'caption' => '➕ Add', 'onClick' => 'AIRPC_AddAddress($id, $ManualAddress);'],
                    ['type' => 'Button', 'caption' => '➖ Remove', 'onClick' => 'AIRPC_RemoveAddress($id, $ManualAddress);'],
                ]],
                ['type' => 'Label', 'caption' => $this->handAdressen() !== []
                    ? AirPlayText::t('Manual addresses: %s', implode(', ', $this->handAdressen()))
                    : AirPlayText::t('No manual addresses.')],
            ],
        ];
        return $this->airplayFormular([$suche], [], [['code' => 102, 'icon' => 'active', 'caption' => 'Ready']]);
    }
}
