<?php

declare(strict_types=1);

// ===========================================================================
// Einordnung eines AirPlay-Empfängers aus seinem mDNS-TXT-Eintrag
// (_airplay._tcp), rein in PHP ohne Symcon.
//
// Gerätefamilie aus „model" (Tabelle nach pyatv device_info.py), Kopplungs-
// bedarf aus „flags" (0x8 PIN nötig, 0x200 Legacy-Kopplung, 0x80 Kennwort),
// „acl" (1 = nur Mitglieder des Zuhauses) und „act" (2 = nur der aktuelle
// Benutzer), Kopplung ohne PIN aus „features" Bit 43 (System-Pairing) oder
// Bit 48 (CoreUtils-Pairing). Ohne diese Bits ist es ein reiner
// AirPlay-1-Empfänger, den diese Version nicht bespielt.
//
// Durchsage-Status: 'ja' (an echter Hardware geprüft), 'ungeprueft' (Ablauf
// sollte passen, noch nie probiert), 'nein' (mit Grund in 'grund').
// ===========================================================================

final class AirPlayGeraete
{
    private const MODELLE = [
        'AudioAccessory1,1' => ['homepod', 'HomePod (1st generation)'],
        'AudioAccessory1,2' => ['homepod', 'HomePod (1st generation)'],
        'AudioAccessory5,1' => ['homepod', 'HomePod mini'],
        'AudioAccessorySingle5,1' => ['homepod', 'HomePod mini'],
        'AudioAccessory6,1' => ['homepod', 'HomePod (2nd generation)'],
        'AudioAccessory11,1' => ['homepod', 'HomePod mini (2nd generation)'],   // Okt. 2026 (EveryMac, homey-apple #56)
        'AppleTV1,1' => ['appletv_alt', 'Apple TV (1st generation)'],
        'AppleTV2,1' => ['appletv_alt', 'Apple TV (2nd generation)'],
        'AppleTV3,1' => ['appletv_alt', 'Apple TV (3rd generation)'],
        'AppleTV3,2' => ['appletv_alt', 'Apple TV (3rd generation)'],
        'AppleTV5,3' => ['appletv', 'Apple TV HD'],
        'AppleTV6,2' => ['appletv', 'Apple TV 4K (1st generation)'],
        'AppleTV11,1' => ['appletv', 'Apple TV 4K (2nd generation)'],
        'AppleTV14,1' => ['appletv', 'Apple TV 4K (3rd generation)'],
        'AirPort4,107' => ['airport', 'AirPort Express'],
        'AirPort10,115' => ['airport', 'AirPort Express (2nd generation)'],
    ];

    /**
     * Am echten Gerät geprüft (Durchsage, Anzeige, Steuerung): HomePod 1. Generation und HomePod mini. Andere
     * HomePods laufen über dasselbe Protokoll, stehen aber auf „ungeprüft", bis ein Tester sie bestätigt.
     */
    private const GEPRUEFT = ['AudioAccessory1,1', 'AudioAccessory1,2', 'AudioAccessory5,1', 'AudioAccessorySingle5,1'];

    /** TXT-Einträge „schluessel=wert" → Feld (Schlüssel klein). */
    public static function txt(array $eintraege): array
    {
        $out = [];
        foreach ($eintraege as $e) {
            $teile = explode('=', (string)$e, 2);
            $out[strtolower(trim($teile[0]))] = trim($teile[1] ?? '');
        }
        return $out;
    }

    /** DNS-TXT-Rohbytes (je Eintrag ein Längenbyte + Text) → Liste „schluessel=wert". */
    public static function txtBytes(string $roh): array
    {
        $out = [];
        for ($i = 0, $n = strlen($roh); $i < $n;) {
            $laenge = ord($roh[$i]);
            if ($laenge > 0) {
                $out[] = substr($roh, $i + 1, $laenge);
            }
            $i += 1 + $laenge;
        }
        return $out;
    }

    /**
     * Einordnung aus der Antwort auf GET /info (Gerät von Hand per Adresse, Suche erreicht es nicht).
     * Bevorzugt der mitgelieferte Bonjour-Eintrag txtAirPlay — derselbe wie bei der Suche —, sonst die
     * Einzelfelder model, deviceID, features und statusFlags.
     */
    public static function ausInfo(array $info): array
    {
        $roh = $info['txtAirPlay'] ?? null;
        $txt = is_object($roh) && isset($roh->bytes) ? self::txt(self::txtBytes((string)$roh->bytes)) : [];
        if (!isset($txt['model'])) {
            $f = (int)($info['features'] ?? 0);
            $txt += [
                'model' => (string)($info['model'] ?? ''),
                'deviceid' => (string)($info['deviceID'] ?? ''),
                'features' => sprintf('0x%X,0x%X', $f & 0xFFFFFFFF, ($f >> 32) & 0xFFFFFFFF),
                'flags' => sprintf('0x%X', (int)($info['statusFlags'] ?? 0)),
            ];
        }
        return self::einordnen($txt) + ['name' => (string)($info['name'] ?? '')];
    }

    /** „0xLOW,0xHIGH" → 64-Bit-Zahl. */
    public static function features(string $wert): int
    {
        if (preg_match('/^0x([0-9a-f]{1,8})(?:,0x([0-9a-f]{1,8}))?$/i', trim($wert), $m) !== 1) {
            return 0;
        }
        return (isset($m[2]) ? hexdec($m[2]) << 32 : 0) | hexdec($m[1]);
    }

    /**
     * @return array{familie:string, modell:string, modellText:string, hersteller:string,
     *   durchsage:string, grund:string, steuerung:bool, deviceId:string, os:string}
     */
    public static function einordnen(array $txt): array
    {
        $modell = (string)($txt['model'] ?? $txt['am'] ?? '');
        $flags = (int)hexdec(ltrim((string)($txt['flags'] ?? $txt['sf'] ?? '0x0'), '0x') ?: '0');
        $features = self::features((string)($txt['features'] ?? $txt['ft'] ?? ''));
        $hersteller = (string)($txt['manufacturer'] ?? '');

        if (isset(self::MODELLE[$modell])) {
            [$familie, $modellText] = self::MODELLE[$modell];
            $hersteller = 'Apple';
        } elseif (preg_match('/^(Mac|MacBook|iMac|MacPro|Macmini)/', $modell) === 1) {
            [$familie, $modellText] = ['mac', 'Mac'];
            $hersteller = 'Apple';
        } else {
            $familie = 'fremd';
            $modellText = $modell;
        }

        $transient = (($features >> 43) & 1) === 1 || (($features >> 48) & 1) === 1;
        $durchsage = 'ungeprueft';
        $grund = '';
        if (($txt['pw'] ?? '') === 'true' || ($flags & 0x80) !== 0) {
            [$durchsage, $grund] = ['nein', 'password protected'];
        } elseif (($flags & (0x8 | 0x200)) !== 0) {
            [$durchsage, $grund] = ['nein', 'requires pairing with a PIN'];
        } elseif (($txt['acl'] ?? '0') === '1') {
            [$durchsage, $grund] = ['nein', 'AirPlay limited to members of the home'];
        } elseif (($txt['act'] ?? '0') === '2') {
            [$durchsage, $grund] = ['nein', 'AirPlay limited to the current user'];
        } elseif (!$transient) {
            [$durchsage, $grund] = ['nein', 'AirPlay 1 only'];
        } elseif ($familie === 'homepod' && in_array($modell, self::GEPRUEFT, true)) {
            $durchsage = 'ja';
        }

        return [
            'familie' => $familie,
            'modell' => $modell,
            'modellText' => $modellText,
            'hersteller' => $hersteller,
            'durchsage' => $durchsage,
            'grund' => $grund,
            // Fernsteuerung (MRP/Companion) erst in Stufe 2; nur HomePod und tvOS-Apple-TV kennen sie
            'steuerung' => in_array($familie, ['homepod', 'appletv'], true),
            'deviceId' => strtoupper((string)($txt['deviceid'] ?? '')),
            'os' => (string)($txt['osvers'] ?? ''),
        ];
    }
}
