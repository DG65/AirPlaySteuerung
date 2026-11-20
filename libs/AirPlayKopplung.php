<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayRtsp.php';

// ===========================================================================
// Kopplung ohne PIN-Eingabe („transient pairing", HAP Pair-Setup M1–M4):
// Das Gerät kennt dafür die feste PIN 3939. Ergebnis ist der gemeinsame
// Schlüssel K (64 Byte), aus dem alle weiteren Schlüssel per HKDF abgeleitet
// werden. Danach ist die Steuerverbindung verschlüsselt (Control-Salt).
// Eine dauerhafte Kopplung (M5/M6, Pair-Verify) braucht es dafür nicht.
// ===========================================================================

final class AirPlayKopplung
{
    private const PIN = '3939';

    // TLV8-Kennungen nach HAP
    private const TLV_METHODE = 0x00;
    private const TLV_SALZ = 0x02;
    private const TLV_OEFFENTLICH = 0x03;
    private const TLV_BEWEIS = 0x04;
    private const TLV_SCHRITT = 0x06;
    private const TLV_FEHLER = 0x07;
    private const TLV_FLAGS = 0x13;
    private const FLAG_TRANSIENT = 0x10;

    private const KOPF = [
        'User-Agent' => 'AirPlay/320.20',
        'Connection' => 'keep-alive',
        'X-Apple-HKP' => 4,
        'Content-Type' => 'application/octet-stream',
    ];

    /** Koppelt, verschlüsselt die Steuerverbindung und liefert K. */
    public static function transient(AirPlayRtsp $rtsp): string
    {
        $rtsp->http('POST', '/pair-pin-start', self::KOPF);

        $m2 = self::schritt($rtsp, 'M2', [
            [self::TLV_METHODE, "\x00"],
            [self::TLV_SCHRITT, "\x01"],
            [self::TLV_FLAGS, chr(self::FLAG_TRANSIENT)],
        ]);
        if (!isset($m2[self::TLV_SALZ], $m2[self::TLV_OEFFENTLICH])) {
            throw new AirPlayFehler('pairing refused by the device (step %s, code %s)', 'M2', '?');
        }

        $srp = new AirPlaySrp('Pair-Setup', self::PIN);
        $srp->verarbeiten($m2[self::TLV_SALZ], $m2[self::TLV_OEFFENTLICH]);
        self::schritt($rtsp, 'M4', [
            [self::TLV_SCHRITT, "\x03"],
            [self::TLV_OEFFENTLICH, $srp->oeffentlichA],
            [self::TLV_BEWEIS, $srp->m],
        ]);

        $rtsp->verbindung->verschluesseln(
            AirPlayHkdf::sha512('Control-Salt', 'Control-Write-Encryption-Key', $srp->k),
            AirPlayHkdf::sha512('Control-Salt', 'Control-Read-Encryption-Key', $srp->k)
        );
        return $srp->k;
    }

    /** Einen Pair-Setup-Schritt senden; wirft bei einer Fehlermeldung des Geräts. */
    private static function schritt(AirPlayRtsp $rtsp, string $antwortSchritt, array $tlv): array
    {
        $antwort = AirPlayTlv8::decode($rtsp->http('POST', '/pair-setup', self::KOPF, AirPlayTlv8::encode($tlv))['koerper']);
        if (isset($antwort[self::TLV_FEHLER])) {
            throw new AirPlayFehler('pairing refused by the device (step %s, code %s)', $antwortSchritt, bin2hex($antwort[self::TLV_FEHLER]));
        }
        return $antwort;
    }
}
