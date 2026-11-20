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

        // Samples links/rechts als int16 einlesen
        $links = [];
        $rechts = [];
        if ($bits === 16) {
            $alle = array_values(unpack('v*', substr($daten, 0, $frames * 2 * $kanaele)));
            for ($i = 0, $j = 0; $i < $frames; $i++, $j += $kanaele) {
                $l = $alle[$j] >= 32768 ? $alle[$j] - 65536 : $alle[$j];
                $r = $kanaele > 1 ? ($alle[$j + 1] >= 32768 ? $alle[$j + 1] - 65536 : $alle[$j + 1]) : $l;
                $links[] = $l;
                $rechts[] = $r;
            }
        } else {
            for ($i = 0; $i < $frames; $i++) {
                $o = $i * $bytes * $kanaele;
                $l = self::sample($daten, $o, $bits);
                $links[] = $l;
                $rechts[] = $kanaele > 1 ? self::sample($daten, $o + $bytes, $bits) : $l;
            }
        }

        $ausgabe = '';
        if ($rate === self::ZIELRATE) {
            for ($i = 0; $i < $frames; $i++) {
                $ausgabe .= pack('nn', $links[$i] & 0xFFFF, $rechts[$i] & 0xFFFF);
            }
        } else {
            $zielFrames = (int)floor($frames * self::ZIELRATE / $rate);
            $schritt = $rate / self::ZIELRATE;
            $teile = [];
            for ($i = 0; $i < $zielFrames; $i++) {
                $p = $i * $schritt;
                $a = (int)$p;
                $b = min($a + 1, $frames - 1);
                $t = $p - $a;
                $l = (int)round($links[$a] + ($links[$b] - $links[$a]) * $t);
                $r = (int)round($rechts[$a] + ($rechts[$b] - $rechts[$a]) * $t);
                $teile[] = pack('nn', $l & 0xFFFF, $r & 0xFFFF);
            }
            $ausgabe = implode('', $teile);
        }
        return ['pcm' => $ausgabe, 'sekunden' => $frames / $rate, 'quelle' => $quelle];
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

    /** Zweiklang-Gong (ca. 1,2 s) als Strom-PCM, z. B. für Tests oder vor einer Durchsage. */
    public static function gong(): string
    {
        $teile = [];
        $toene = [[659.25, 0.0], [523.25, 0.45]];
        $dauer = 1.2;
        $frames = (int)($dauer * self::ZIELRATE);
        for ($i = 0; $i < $frames; $i++) {
            $t = $i / self::ZIELRATE;
            $wert = 0.0;
            foreach ($toene as [$frequenz, $start]) {
                if ($t >= $start) {
                    $dt = $t - $start;
                    $huelle = min(1.0, $dt / 0.005) * exp(-3.5 * $dt);
                    $wert += $huelle * (sin(2 * M_PI * $frequenz * $dt) + 0.3 * sin(4 * M_PI * $frequenz * $dt));
                }
            }
            $s = (int)max(-32767, min(32767, round($wert * 9000)));
            $teile[] = pack('nn', $s & 0xFFFF, $s & 0xFFFF);
        }
        return implode('', $teile);
    }
}
