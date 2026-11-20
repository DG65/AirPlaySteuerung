<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Minimales Protocol-Buffers-Format (proto2-Drahtformat), rein in PHP.
// Kodieren: Felder einzeln mit feld() erzeugen und aneinanderhängen.
// Dekodieren: decode() liefert je Feldnummer die Liste der Rohwerte
// (Varint → int, Länge → string, fixed32/fixed64 → 4/8 Rohbytes); die
// Lesehilfen wandeln in den erwarteten Typ und dekodieren Untermeldungen.
// Ein Schema braucht es dafür nicht: die Feldnummern stehen bei den Nutzern
// (AirPlayMrp), nach den .proto-Dateien von pyatv.
// ===========================================================================

final class AirPlayProtobuf
{
    private const VARINT = 0;
    private const FIXED64 = 1;
    private const LAENGE = 2;
    private const FIXED32 = 5;

    // ------------------------------------------------------------------ Kodieren

    public static function varint(int $wert): string
    {
        $out = '';
        do {
            $byte = $wert & 0x7F;
            $wert = ($wert >> 7) & (PHP_INT_MAX >> 6); // logisch schieben (negative int32/int64 → 10 Byte)
            $out .= chr($wert !== 0 ? $byte | 0x80 : $byte);
        } while ($wert !== 0);
        return $out;
    }

    /**
     * Ein Feld kodieren.
     * @param string $typ varint|bool|string|bytes|msg|float|double
     */
    public static function feld(int $nummer, string $typ, mixed $wert): string
    {
        return match ($typ) {
            'varint' => self::varint(($nummer << 3) | self::VARINT) . self::varint((int)$wert),
            'bool' => self::varint(($nummer << 3) | self::VARINT) . ($wert ? "\x01" : "\x00"),
            'string', 'bytes', 'msg' => self::varint(($nummer << 3) | self::LAENGE) . self::varint(strlen((string)$wert)) . $wert,
            'float' => self::varint(($nummer << 3) | self::FIXED32) . pack('g', (float)$wert),
            'double' => self::varint(($nummer << 3) | self::FIXED64) . pack('e', (float)$wert),
            default => throw new AirPlayFehler('protobuf: unknown type %s', $typ),
        };
    }

    // ------------------------------------------------------------------ Dekodieren

    /** @return array{0:int,1:int} Wert und neue Position */
    public static function varintLesen(string $d, int $pos): array
    {
        $wert = 0;
        $schiebe = 0;
        $n = strlen($d);
        while ($pos < $n) {
            $b = ord($d[$pos++]);
            $wert |= ($b & 0x7F) << $schiebe;
            if (($b & 0x80) === 0) {
                return [$wert, $pos];
            }
            $schiebe += 7;
            if ($schiebe > 63) {
                break;
            }
        }
        throw new AirPlayFehler('protobuf: truncated varint');
    }

    /** @return array<int, list<int|string>> */
    public static function decode(string $daten): array
    {
        $out = [];
        $pos = 0;
        $n = strlen($daten);
        while ($pos < $n) {
            [$schluessel, $pos] = self::varintLesen($daten, $pos);
            $nummer = $schluessel >> 3;
            switch ($schluessel & 7) {
                case self::VARINT:
                    [$wert, $pos] = self::varintLesen($daten, $pos);
                    break;
                case self::FIXED64:
                    $wert = substr($daten, $pos, 8);
                    $pos += 8;
                    break;
                case self::LAENGE:
                    [$laenge, $pos] = self::varintLesen($daten, $pos);
                    $wert = substr($daten, $pos, $laenge);
                    $pos += $laenge;
                    break;
                case self::FIXED32:
                    $wert = substr($daten, $pos, 4);
                    $pos += 4;
                    break;
                default:
                    throw new AirPlayFehler('protobuf: unsupported wire type');
            }
            if ($pos > $n) {
                throw new AirPlayFehler('protobuf: truncated message');
            }
            $out[$nummer][] = $wert;
        }
        return $out;
    }

    // ------------------------------------------------------------------ Lesehilfen (erstes Vorkommen)

    public static function int(array $d, int $nr, ?int $standard = null): ?int
    {
        return isset($d[$nr][0]) && is_int($d[$nr][0]) ? $d[$nr][0] : $standard;
    }

    public static function bool(array $d, int $nr): ?bool
    {
        $v = self::int($d, $nr);
        return $v === null ? null : $v !== 0;
    }

    public static function text(array $d, int $nr): ?string
    {
        return isset($d[$nr][0]) && is_string($d[$nr][0]) ? $d[$nr][0] : null;
    }

    public static function float(array $d, int $nr): ?float
    {
        return isset($d[$nr][0]) && is_string($d[$nr][0]) && strlen($d[$nr][0]) === 4 ? (float)unpack('g', $d[$nr][0])[1] : null;
    }

    public static function double(array $d, int $nr): ?float
    {
        return isset($d[$nr][0]) && is_string($d[$nr][0]) && strlen($d[$nr][0]) === 8 ? (float)unpack('e', $d[$nr][0])[1] : null;
    }

    /** Untermeldung dekodieren (leer, wenn nicht vorhanden). */
    public static function msg(array $d, int $nr): array
    {
        $roh = self::text($d, $nr);
        return $roh === null ? [] : self::decode($roh);
    }

    /** Alle Vorkommen eines wiederholten Untermeldungs-Felds dekodieren. */
    public static function msgs(array $d, int $nr): array
    {
        return array_map(fn ($roh) => self::decode((string)$roh), $d[$nr] ?? []);
    }
}
