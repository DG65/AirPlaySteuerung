<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Text zu Sprache über einen Piper-Server im eigenen Netz (Wyoming-Protokoll),
// rein in PHP ohne Symcon.
//
// Piper (OHF-Voice/piper1-gpl) läuft z. B. als Docker-Container
// rhasspy/wyoming-piper auf TCP 10200. Protokoll (OHF-Voice/wyoming, event.py):
// je Ereignis eine JSON-Zeile {"type", "data_length", "payload_length"}, danach
// data_length Bytes JSON-Daten und payload_length Bytes Nutzlast.
//   → synthesize {text, voice:{name}}
//   ← audio-start {rate, width, channels}, audio-chunk (PCM Little Endian), audio-stop
//   → describe   ← info {tts:[{voices:[{name, languages, installed}]}]}
// ===========================================================================

final class AirPlayPiper
{
    public const STANDARD_PORT = 10200;
    /** Höchstlänge eines Ansagetexts (Zeichen). */
    public const MAX_ZEICHEN = 1000;
    /** Größte angenommene Einzelnutzlast (Bytes) — Schutz gegen kaputte Antworten. */
    private const MAX_NUTZLAST = 16 * 1024 * 1024;

    /** @param string $server „host" oder „host:port" */
    public function __construct(private string $server, private float $zeitlimit = 30.0)
    {
    }

    /** Text sprechen lassen; Ergebnis ist eine WAV-Datei (PCM 16 Bit, Abtastrate der Stimme). */
    public function wav(string $text, string $stimme = ''): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            throw new AirPlayFehler('the text is empty');
        }
        if (mb_strlen($text) > self::MAX_ZEICHEN) {
            throw new AirPlayFehler('the text is longer than %s characters', self::MAX_ZEICHEN);
        }
        $daten = ['text' => $text];
        if (trim($stimme) !== '') {
            $daten['voice'] = ['name' => trim($stimme)];
        }
        $sock = $this->verbinden();
        try {
            self::senden($sock, 'synthesize', $daten);
            $format = null;
            $pcm = '';
            while (true) {
                $e = $this->lesen($sock);
                if ($e['type'] === 'audio-start') {
                    $format = $e['data'];
                } elseif ($e['type'] === 'audio-chunk') {
                    $format ??= $e['data'];
                    $pcm .= $e['payload'];
                    if (strlen($pcm) > self::MAX_NUTZLAST) {
                        throw new AirPlayFehler('the speech output is too long');
                    }
                } elseif ($e['type'] === 'audio-stop') {
                    break;
                } elseif ($e['type'] === 'error') {
                    throw new AirPlayFehler('Piper reports: %s', (string)($e['data']['text'] ?? '?'));
                }
            }
        } finally {
            fclose($sock);
        }
        if ($format === null || $pcm === '') {
            throw new AirPlayFehler('Piper returned no audio (unknown voice?)');
        }
        return self::wavKopf((int)($format['rate'] ?? 22050), (int)($format['width'] ?? 2), (int)($format['channels'] ?? 1), $pcm);
    }

    /**
     * Installierte Stimmen des Servers.
     * @return list<array{name:string, sprachen:string}>
     */
    public function stimmen(): array
    {
        $sock = $this->verbinden();
        try {
            self::senden($sock, 'describe', []);
            do {
                $e = $this->lesen($sock);
            } while ($e['type'] !== 'info');
        } finally {
            fclose($sock);
        }
        $out = [];
        foreach ((array)($e['data']['tts'] ?? []) as $programm) {
            foreach ((array)($programm['voices'] ?? []) as $v) {
                if (($v['installed'] ?? true) === false || !isset($v['name'])) {
                    continue;
                }
                $out[] = ['name' => (string)$v['name'], 'sprachen' => implode(', ', array_map('strval', (array)($v['languages'] ?? [])))];
            }
        }
        return $out;
    }

    /** @return resource */
    private function verbinden()
    {
        [$host, $port] = self::adresse($this->server);
        $sock = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, 5.0);
        if ($sock === false) {
            throw new AirPlayFehler('Piper server %s not reachable: %s', $host . ':' . $port, $text !== '' ? $text : (string)$nr);
        }
        stream_set_timeout($sock, (int)ceil($this->zeitlimit));
        return $sock;
    }

    /** @return array{0:string, 1:int} */
    public static function adresse(string $server): array
    {
        $server = trim($server);
        if ($server === '') {
            throw new AirPlayFehler('no Piper server set');
        }
        // „host:port", „[IPv6]:port"; eine nackte IPv6-Adresse (mehrere Doppelpunkte) bleibt ohne Port.
        if (preg_match('/^\[([^\]]+)\](?::(\d{1,5}))?$/', $server, $m) === 1) {
            return [$m[1], isset($m[2]) ? (int)$m[2] : self::STANDARD_PORT];
        }
        if (substr_count($server, ':') === 1) {
            [$host, $port] = explode(':', $server);
            return [$host, ctype_digit($port) ? (int)$port : self::STANDARD_PORT];
        }
        return [$server, self::STANDARD_PORT];
    }

    /** @param resource $sock */
    private static function senden($sock, string $typ, array $daten): void
    {
        $kopf = ['type' => $typ];
        $json = '';
        if ($daten !== []) {
            $json = (string)json_encode($daten, JSON_UNESCAPED_UNICODE);
            $kopf['data_length'] = strlen($json);
        }
        $nachricht = json_encode($kopf, JSON_UNESCAPED_UNICODE) . "\n" . $json;
        if (@fwrite($sock, $nachricht) !== strlen($nachricht)) {
            throw new AirPlayFehler('sending to the Piper server failed');
        }
    }

    /**
     * @param resource $sock
     * @return array{type:string, data:array, payload:string}
     */
    private function lesen($sock): array
    {
        $zeile = fgets($sock, 65536);
        if ($zeile === false) {
            self::abgebrochen($sock);
        }
        $kopf = json_decode($zeile, true);
        if (!is_array($kopf) || !isset($kopf['type'])) {
            throw new AirPlayFehler('invalid answer from the Piper server');
        }
        $daten = is_array($kopf['data'] ?? null) ? $kopf['data'] : [];
        $laenge = (int)($kopf['data_length'] ?? 0);
        if ($laenge > 0) {
            $zusatz = json_decode(self::genau($sock, $laenge), true);
            if (is_array($zusatz)) {
                $daten = array_merge($daten, $zusatz);
            }
        }
        $nutzlast = (int)($kopf['payload_length'] ?? 0);
        return ['type' => (string)$kopf['type'], 'data' => $daten, 'payload' => $nutzlast > 0 ? self::genau($sock, $nutzlast) : ''];
    }

    /** @param resource $sock */
    private static function genau($sock, int $n): string
    {
        if ($n > self::MAX_NUTZLAST) {
            throw new AirPlayFehler('invalid answer from the Piper server');
        }
        $out = '';
        while (strlen($out) < $n) {
            $teil = fread($sock, $n - strlen($out));
            if ($teil === false || $teil === '') {
                self::abgebrochen($sock);
            }
            $out .= $teil;
        }
        return $out;
    }

    /** @param resource $sock */
    private static function abgebrochen($sock): never
    {
        if (stream_get_meta_data($sock)['timed_out']) {
            throw new AirPlayFehler('Piper server did not answer in time');
        }
        throw new AirPlayFehler('Piper server closed the connection');
    }

    public static function wavKopf(int $rate, int $breite, int $kanaele, string $pcm): string
    {
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, $kanaele, $rate, $rate * $breite * $kanaele, $breite * $kanaele, $breite * 8)
            . 'data' . pack('V', strlen($pcm)) . $pcm;
    }
}
