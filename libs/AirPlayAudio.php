<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Audio für den AirPlay-Strom vorbereiten, rein in PHP (kein ffmpeg):
// WAV (PCM 8/16/24/32 Bit, Mono oder Stereo, beliebige Abtastrate) wird in
// 44,1 kHz, 16 Bit, Stereo, Big Endian umgerechnet (lineare Interpolation).
// MP3/AAC/OGG dekodiert PHP nicht; dafür ist später die Wiedergabe per URL
// vorgesehen, bei der das Gerät selbst dekodiert.
// ===========================================================================

final class AirPlayAudio
{
    public const ZIELRATE = 44100;
    /** Höchstdauer einer Durchsage in Sekunden (Laufzeit des aufrufenden Skripts). */
    public const MAX_SEKUNDEN = 120;
    /** Frames je Umrechnungsblock (begrenzt den Speicher unabhängig von der Länge). */
    private const BLOCK = 4096;

    /** Erkennt das Dateiformat grob anhand der ersten Bytes. */
    public static function format(string $daten): string
    {
        return match (true) {
            str_starts_with($daten, 'RIFF') && substr($daten, 8, 4) === 'WAVE' => 'wav',
            str_starts_with($daten, 'ID3') || (strlen($daten) > 1 && ord($daten[0]) === 0xFF && (ord($daten[1]) & 0xE0) === 0xE0) => 'mp3',
            str_starts_with($daten, 'OggS') => 'ogg',
            substr($daten, 4, 4) === 'ftyp' => 'mp4',
            default => 'unbekannt',
        };
    }

    /**
     * WAV-Datei in Strom-PCM umwandeln.
     * @return array{pcm: string, sekunden: float, quelle: string}
     */
    public static function wavZuPcm(string $wav): array
    {
        if (self::format($wav) !== 'wav') {
            throw new AirPlayFehler('not a WAV file');
        }
        $pos = 12;
        $n = strlen($wav);
        $fmt = null;
        $daten = null;
        while ($pos + 8 <= $n) {
            $id = substr($wav, $pos, 4);
            $groesse = unpack('V', substr($wav, $pos + 4, 4))[1];
            $inhalt = substr($wav, $pos + 8, $groesse);
            if ($id === 'fmt ') {
                $fmt = unpack('vformat/vkanaele/Vrate/Vbyterate/vblock/vbits', $inhalt);
                if ($fmt['format'] === 0xFFFE && strlen($inhalt) >= 26) {
                    $fmt['format'] = unpack('v', substr($inhalt, 24, 2))[1]; // WAVE_FORMAT_EXTENSIBLE: Untertyp
                }
            } elseif ($id === 'data') {
                $daten = $inhalt;
                break;
            }
            $pos += 8 + $groesse + ($groesse & 1);
        }
        if ($fmt === null || $daten === null) {
            throw new AirPlayFehler('WAV file without fmt or data chunk');
        }
        if ($fmt['format'] !== 1) {
            throw new AirPlayFehler('WAV encoding %d is not supported, only PCM', $fmt['format']);
        }
        $kanaele = (int)$fmt['kanaele'];
        $bits = (int)$fmt['bits'];
        $rate = (int)$fmt['rate'];
        if ($kanaele < 1 || $kanaele > 8 || !in_array($bits, [8, 16, 24, 32], true) || $rate < 4000 || $rate > 192000) {
            throw new AirPlayFehler('WAV format not supported (%d channels, %d bit, %d Hz)', $kanaele, $bits, $rate);
        }
        $bytes = intdiv($bits, 8);
        $frames = intdiv(strlen($daten), $bytes * $kanaele);
        if ($frames / $rate > self::MAX_SEKUNDEN) {
            throw new AirPlayFehler('audio is longer than %d seconds', self::MAX_SEKUNDEN);
        }
        $quelle = sprintf('%d Hz, %d bit, %d ch', $rate, $bits, $kanaele);

        $form = ['daten' => $daten, 'bits' => $bits, 'kanaele' => $kanaele, 'frames' => $frames];
        // Blockweise umrechnen: Speicher bleibt bei einem Block (sonst je Sample ein PHP-Array-Eintrag —
        // eine 60-s-Ansage bräuchte mehrere hundert MB).
        $pcm = '';
        if ($rate === self::ZIELRATE) {
            for ($a = 0; $a < $frames; $a += self::BLOCK) {
                [$l, $r] = self::block($form, $a, min(self::BLOCK, $frames - $a));
                $pcm .= self::stereoBigEndian($l, $r);
            }
        } else {
            // lineare Interpolation; je Ausgabeblock nur den nötigen Eingangsausschnitt lesen
            $zielFrames = (int)floor($frames * self::ZIELRATE / $rate);
            $schritt = $rate / self::ZIELRATE;
            for ($i0 = 0; $i0 < $zielFrames; $i0 += self::BLOCK) {
                $i1 = min($i0 + self::BLOCK, $zielFrames);
                $von = (int)($i0 * $schritt);
                [$l, $r] = self::block($form, $von, min($frames - $von, (int)(($i1 - 1) * $schritt) - $von + 2));
                $letzter = count($l) - 1;
                $zl = [];
                $zr = [];
                for ($i = $i0; $i < $i1; $i++) {
                    $p = $i * $schritt - $von;
                    $a = (int)$p;
                    $b = min($a + 1, $letzter);
                    $t = $p - $a;
                    $zl[] = (int)round($l[$a] + ($l[$b] - $l[$a]) * $t);
                    $zr[] = (int)round($r[$a] + ($r[$b] - $r[$a]) * $t);
                }
                $pcm .= self::stereoBigEndian($zl, $zr);
            }
        }
        return ['pcm' => $pcm, 'sekunden' => $frames / $rate, 'quelle' => $quelle];
    }

    /**
     * Frames [$start, $start + $anzahl) als int16 links/rechts (Mono wird verdoppelt).
     * @param array{daten:string, bits:int, kanaele:int, frames:int} $f
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function block(array $f, int $start, int $anzahl): array
    {
        $bytes = intdiv($f['bits'], 8);
        $k = $f['kanaele'];
        $links = [];
        $rechts = [];
        if ($f['bits'] === 16) {
            $alle = array_values(unpack('v*', substr($f['daten'], $start * 2 * $k, $anzahl * 2 * $k)));
            for ($j = 0, $n = count($alle); $j + $k <= $n; $j += $k) {
                $l = $alle[$j] >= 32768 ? $alle[$j] - 65536 : $alle[$j];
                $links[] = $l;
                $rechts[] = $k > 1 ? ($alle[$j + 1] >= 32768 ? $alle[$j + 1] - 65536 : $alle[$j + 1]) : $l;
            }
            return [$links, $rechts];
        }
        for ($i = $start, $ende = $start + $anzahl; $i < $ende; $i++) {
            $o = $i * $bytes * $k;
            $l = self::sample($f['daten'], $o, $f['bits']);
            $links[] = $l;
            $rechts[] = $k > 1 ? self::sample($f['daten'], $o + $bytes, $f['bits']) : $l;
        }
        return [$links, $rechts];
    }

    /** @param list<int> $l @param list<int> $r */
    private static function stereoBigEndian(array $l, array $r): string
    {
        $werte = [];
        foreach ($l as $i => $wert) {
            $werte[] = $wert & 0xFFFF;
            $werte[] = $r[$i] & 0xFFFF;
        }
        return pack('n*', ...$werte);
    }

    private static function sample(string $d, int $o, int $bits): int
    {
        switch ($bits) {
            case 8:
                return (ord($d[$o]) - 128) << 8;
            case 24:
                $v = ord($d[$o]) | (ord($d[$o + 1]) << 8) | (ord($d[$o + 2]) << 16);
                return ($v >= 0x800000 ? $v - 0x1000000 : $v) >> 8;
            case 32:
                $v = unpack('V', substr($d, $o, 4))[1];
                return ($v >= 0x80000000 ? $v - 0x100000000 : $v) >> 16;
        }
        $v = ord($d[$o]) | (ord($d[$o + 1]) << 8);
        return $v >= 32768 ? $v - 65536 : $v;
    }

    /**
     * Mitgelieferte Klänge, rein synthetisch (kein Audiofile): je Ton Frequenz (Hz) und Einsatz (s),
     * dazu Gesamtdauer (s) und Abklingrate (1/s) der Hüllkurve.
     */
    public const KLAENGE = [
        'gong' => ['dauer' => 1.2, 'abfall' => 3.5, 'toene' => [[659.25, 0.0], [523.25, 0.45]]],
        'doorbell' => ['dauer' => 1.8, 'abfall' => 2.2, 'toene' => [[783.99, 0.0], [659.25, 0.6]]],
        'notice' => ['dauer' => 0.7, 'abfall' => 6.0, 'toene' => [[987.77, 0.0]]],
        'alarm' => ['dauer' => 1.9, 'abfall' => 9.0, 'toene' => [[880.0, 0.0], [660.0, 0.3], [880.0, 0.6], [660.0, 0.9], [880.0, 1.2], [660.0, 1.5]]],
    ];

    /** Klang als Strom-PCM (44,1 kHz, Stereo, Big Endian), z. B. vor einer Durchsage. */
    public static function klang(string $name): string
    {
        $k = self::KLAENGE[$name] ?? throw new AirPlayFehler('unknown sound %s', $name);
        $frames = (int)($k['dauer'] * self::ZIELRATE);
        $teile = [];
        for ($i = 0; $i < $frames; $i++) {
            $t = $i / self::ZIELRATE;
            $wert = 0.0;
            foreach ($k['toene'] as [$frequenz, $start]) {
                if ($t >= $start) {
                    $dt = $t - $start;
                    // 5 ms Einschwingen gegen Knacken, danach exponentielles Abklingen; Oberton für Glockenklang
                    $huelle = min(1.0, $dt / 0.005) * exp(-$k['abfall'] * $dt);
                    $wert += $huelle * (sin(2 * M_PI * $frequenz * $dt) + 0.3 * sin(4 * M_PI * $frequenz * $dt));
                }
            }
            $s = (int)max(-32767, min(32767, round($wert * 9000))) & 0xFFFF;
            $teile[] = pack('nn', $s, $s);
        }
        return implode('', $teile);
    }
}
