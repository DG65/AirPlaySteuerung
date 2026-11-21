<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Messwerte der HomePod-Sensoren aus dem Aufruf eines Apple-Kurzbefehls lesen,
// rein in PHP ohne Symcon.
//
// Der Kurzbefehl („Inhalte von URL abrufen", Methode POST, Anfragetext JSON)
// schickt je HomePod ein Objekt:
//   {"name": "Schlafzimmer", "temperatur": "21,5 °C", "feuchte": "44 %"}
// Akzeptiert wird auch eine Liste solcher Objekte, englische Schlüssel
// (temperature/humidity) und Zahlen mit Einheit, Komma oder Punkt — Kurzbefehle
// schreiben Messwerte je nach Sprache des iPhones unterschiedlich.
// ===========================================================================

final class AirPlayKlimaDaten
{
    private const NAMEN = ['name', 'raum', 'room', 'homepod'];
    private const TEMPERATUR = ['temperatur', 'temperature', 'temp', 't'];
    private const FEUCHTE = ['feuchte', 'luftfeuchte', 'humidity', 'h'];

    /**
     * Werte aus dem Aufruf: zuerst URL-Parameter (?name=…&temperature=…&humidity=…, der einfachste Kurzbefehl),
     * sonst JSON im Anfragetext.
     * @param array<string, mixed> $parameter $_GET ohne „key"
     * @return list<array{name:string, temperatur:?float, feuchte:?float}>
     */
    public static function ausAufruf(array $parameter, string $koerper): array
    {
        $parameter = self::schluesselGlaetten($parameter);
        unset($parameter['key']);
        if (self::erster($parameter, self::TEMPERATUR) !== null || self::erster($parameter, self::FEUCHTE) !== null) {
            return self::lesen((string)json_encode($parameter));
        }
        return self::lesen($koerper);
    }

    /**
     * @return list<array{name:string, temperatur:?float, feuchte:?float}>
     */
    public static function lesen(string $koerper): array
    {
        $daten = json_decode(trim($koerper), true);
        if (!is_array($daten)) {
            throw new AirPlayFehler('the request does not contain JSON');
        }
        $eintraege = array_is_list($daten) ? $daten : [$daten];
        $out = [];
        foreach ($eintraege as $e) {
            if (!is_array($e)) {
                continue;
            }
            $e = self::schluesselGlaetten($e);
            $name = trim((string)self::erster($e, self::NAMEN));
            $t = self::zahl(self::erster($e, self::TEMPERATUR));
            $h = self::zahl(self::erster($e, self::FEUCHTE));
            if ($name === '' || ($t === null && $h === null)) {
                continue;
            }
            if ($t !== null && ($t < -40 || $t > 80)) {
                $t = null;
            }
            if ($h !== null && ($h < 0 || $h > 100)) {
                $h = null;
            }
            $out[] = ['name' => mb_substr($name, 0, 60), 'temperatur' => $t, 'feuchte' => $h];
        }
        if ($out === []) {
            throw new AirPlayFehler('no readings found (expected name plus temperature and/or humidity)');
        }
        return $out;
    }

    /**
     * Schlüssel klein, ohne Leerzeichen und ohne angehängtes „=" oder „:" — im Kurzbefehl-Editor tippt man
     * schnell „temperature =" statt „temperature" (live 21.11.2026).
     */
    private static function schluesselGlaetten(array $e): array
    {
        $out = [];
        foreach ($e as $k => $v) {
            $out[strtolower(rtrim(trim((string)$k), " \t=:"))] = $v;
        }
        return $out;
    }

    private static function erster(array $e, array $schluessel): mixed
    {
        foreach ($schluessel as $s) {
            if (array_key_exists($s, $e)) {
                return $e[$s];
            }
        }
        return null;
    }

    /** Zahl aus „21,5 °C", „21.5", „44 %" oder 21.5. */
    public static function zahl(mixed $wert): ?float
    {
        if (is_int($wert) || is_float($wert)) {
            return (float)$wert;
        }
        if (!is_string($wert) || preg_match('/-?\d+(?:[.,]\d+)?/', $wert, $m) !== 1) {
            return null;
        }
        return (float)str_replace(',', '.', $m[0]);
    }

    /** Ident-Teil aus einem Namen: ASCII, Buchstaben/Ziffern, höchstens 30 Zeichen. */
    public static function kennung(string $name): string
    {
        $ascii = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss']);
        $ascii = (string)preg_replace('/[^A-Za-z0-9]/', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ascii));
        return substr($ascii !== '' ? $ascii : substr(md5($name), 0, 8), 0, 30);
    }
}
