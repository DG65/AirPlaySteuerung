<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayKrypto.php';

// ===========================================================================
// TCP-Verbindung zu einem AirPlay-Gerät mit optionaler HAP-Verschlüsselung:
// nach der Kopplung wird in Blöcken zu höchstens 1024 Bytes gesendet, jeder
// Block mit 2 Byte Länge (Little Endian, zugleich AAD) und 16 Byte Prüfsumme.
//
// Eigener Socket statt Symcon-I/O (SUITE.md Review-Punkt 21): Eine Durchsage
// braucht gleichzeitig die RTSP-Verbindung, einen Event-Kanal (zweite TCP-
// Verbindung zu einem vom Gerät genannten Port) und drei UDP-Kanäle (Audio,
// Steuerung, Zeitabfragen, die während des Stroms im Takt von Millisekunden
// beantwortet werden müssen). Die Ports vergibt das Gerät erst im SETUP, die
// Verschlüsselung hängt an der vorangegangenen Kopplung. Eine Client-Socket-
// Instanz bildet weder die dynamischen Ports noch das Zeitverhalten ab; die
// Verbindungen bestehen nur für die Dauer einer Durchsage.
// ===========================================================================

final class AirPlayVerbindung
{
    /** @var resource */
    public $socket;
    public string $lokaleIp;
    private ?AirPlayChaCha $chiffre = null;
    private string $rohEin = '';
    private string $klarEin = '';

    public function __construct(public string $host, int $port, float $zeitlimit = 5.0)
    {
        $s = @stream_socket_client('tcp://' . $host . ':' . $port, $nr, $text, $zeitlimit);
        if ($s === false) {
            throw new AirPlayFehler('connection to %s:%d failed (%s)', $host, $port, $text);
        }
        stream_set_blocking($s, false);
        $this->socket = $s;
        $name = (string)stream_socket_get_name($s, false);
        $this->lokaleIp = substr($name, 0, (int)strrpos($name, ':'));
    }

    public function verschluesseln(string $schluesselAus, string $schluesselEin): void
    {
        $this->chiffre = new AirPlayChaCha($schluesselAus, $schluesselEin);
    }

    public function schreiben(string $daten): void
    {
        if ($this->chiffre !== null) {
            $out = '';
            foreach (str_split($daten, 1024) as $block) {
                $laenge = pack('v', strlen($block));
                $out .= $laenge . $this->chiffre->encrypt($block, $laenge);
            }
            $daten = $out;
        }
        $ende = microtime(true) + 5.0;
        while ($daten !== '') {
            $n = @fwrite($this->socket, $daten);
            if ($n === false) {
                throw new AirPlayFehler('write failed');
            }
            if ($n === 0) {
                if (microtime(true) > $ende) {
                    throw new AirPlayFehler('write timed out');
                }
                usleep(1000);
                continue;
            }
            $daten = substr($daten, $n);
        }
    }

    /** Liest, was anliegt (nicht blockierend). false = Gegenstelle hat geschlossen. */
    public function einlesen(): bool
    {
        $stueck = @fread($this->socket, 65536);
        if ($stueck === false || ($stueck === '' && feof($this->socket))) {
            return false;
        }
        if ($stueck === '') {
            return true;
        }
        if ($this->chiffre === null) {
            $this->klarEin .= $stueck;
            return true;
        }
        $this->rohEin .= $stueck;
        while (strlen($this->rohEin) >= 2) {
            $laenge = unpack('v', substr($this->rohEin, 0, 2))[1];
            if (strlen($this->rohEin) < 2 + $laenge + 16) {
                break;
            }
            $this->klarEin .= $this->chiffre->decrypt(substr($this->rohEin, 2, $laenge + 16), substr($this->rohEin, 0, 2));
            $this->rohEin = substr($this->rohEin, 2 + $laenge + 16);
        }
        return true;
    }

    /** Alle bisher empfangenen Klartext-Bytes abholen (für Kanäle ohne HTTP-Rahmen). */
    public function klarAbholen(): string
    {
        $daten = $this->klarEin;
        $this->klarEin = '';
        return $daten;
    }

    /** Eine vollständige HTTP-/RTSP-Nachricht aus dem Puffer nehmen, sonst null. */
    public function nachricht(): ?array
    {
        $p = strpos($this->klarEin, "\r\n\r\n");
        if ($p === false) {
            return null;
        }
        $zeilen = explode("\r\n", substr($this->klarEin, 0, $p));
        $erste = (string)array_shift($zeilen);
        $kopf = [];
        foreach ($zeilen as $z) {
            $teile = explode(':', $z, 2);
            $kopf[strtolower(trim($teile[0]))] = trim($teile[1] ?? '');
        }
        $laenge = (int)($kopf['content-length'] ?? 0);
        if (strlen($this->klarEin) < $p + 4 + $laenge) {
            return null;
        }
        $koerper = substr($this->klarEin, $p + 4, $laenge);
        $this->klarEin = substr($this->klarEin, $p + 4 + $laenge);
        $teile = explode(' ', $erste, 3);
        return ['erste' => $erste, 'code' => (int)($teile[1] ?? 0), 'kopf' => $kopf, 'koerper' => $koerper];
    }

    public function schliessen(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
    }
}
