<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayVerbindung.php';
require_once __DIR__ . '/AirPlayProtobuf.php';

// ===========================================================================
// AirPlay-Datenkanal (Stream-Typ 130) für MRP-Nachrichten, nach pyatv
// channels.py: Jede Nachricht hat einen 32-Byte-Kopf
//   Größe (4, BE, inkl. Kopf) | Typ (12, z. B. „sync" + 8 × 0) | Befehl (4,
//   z. B. „comm") | Folgenummer (8, BE) | Füllung (4)
// und eine Binär-Plist {"params": {"data": <MRP-Nachrichten>}}. Die
// MRP-Nachrichten sind Protobuf, jede mit Varint-Längenpräfix.
// Anfragen des Geräts („sync…") werden mit „rply" bestätigt.
// Verschlüsselt wie alle HAP-Kanäle, Schlüssel aus K mit „DataStream-Salt"
// plus dem im SETUP gesendeten Seed.
// ===========================================================================

final class AirPlayDatenkanal
{
    private const KOPF = 32;

    private int $folgenummer;
    private string $puffer = '';

    public function __construct(public readonly AirPlayVerbindung $verbindung)
    {
        // wie pyatv: zufälliger Startwert, bleibt für alle eigenen Nachrichten gleich
        $this->folgenummer = random_int(0x100000000, 0x1FFFFFFFF);
    }

    public static function mitSchluessel(string $host, int $port, string $k, int $seed): self
    {
        $v = new AirPlayVerbindung($host, $port);
        $salz = 'DataStream-Salt' . $seed;
        $v->verschluesseln(
            AirPlayHkdf::sha512($salz, 'DataStream-Output-Encryption-Key', $k),
            AirPlayHkdf::sha512($salz, 'DataStream-Input-Encryption-Key', $k)
        );
        return new self($v);
    }

    /** Eine MRP-Nachricht (Protobuf ProtocolMessage) senden. */
    public function senden(string $protobuf): void
    {
        $nutzdaten = AirPlayBplist::encode(['params' => ['data' => new AirPlayBplistData(AirPlayProtobuf::varint(strlen($protobuf)) . $protobuf)]]);
        $this->verbindung->schreiben(self::rahmen('sync', 'comm', $this->folgenummer, $nutzdaten));
    }

    private static function rahmen(string $typ, string $befehl, int $folge, string $nutzdaten): string
    {
        return pack('N', self::KOPF + strlen($nutzdaten)) . str_pad($typ, 12, "\0") . str_pad($befehl, 4, "\0")
            . pack('J', $folge) . pack('N', 0) . $nutzdaten;
    }

    /**
     * Liest, was anliegt, und liefert die empfangenen MRP-Nachrichten (je Protobuf-Bytes).
     * Bestätigt Anfragen des Geräts. Wirft, wenn das Gerät den Kanal schließt.
     * @return list<string>
     */
    public function empfangen(): array
    {
        if (!$this->verbindung->einlesen()) {
            throw new AirPlayFehler('the device closed the remote control channel');
        }
        $this->puffer .= $this->verbindung->klarAbholen();
        $nachrichten = [];
        while (strlen($this->puffer) >= self::KOPF) {
            $groesse = unpack('N', substr($this->puffer, 0, 4))[1];
            if ($groesse < self::KOPF) {
                throw new AirPlayFehler('remote control channel: invalid frame');
            }
            if (strlen($this->puffer) < $groesse) {
                break;
            }
            $typ = substr($this->puffer, 4, 12);
            $folge = unpack('J', substr($this->puffer, 20, 8))[1];
            $nutzdaten = substr($this->puffer, self::KOPF, $groesse - self::KOPF);
            $this->puffer = substr($this->puffer, $groesse);
            array_push($nachrichten, ...self::protobufsAus($nutzdaten));
            if (str_starts_with($typ, 'sync')) {
                $this->verbindung->schreiben(self::rahmen('rply', '', $folge, ''));
            }
        }
        return $nachrichten;
    }

    /** @return list<string> */
    private static function protobufsAus(string $nutzdaten): array
    {
        if (!str_starts_with($nutzdaten, 'bplist00')) {
            return [];
        }
        $plist = AirPlayBplist::decode($nutzdaten);
        $daten = $plist['params']['data'] ?? null;
        if (!$daten instanceof AirPlayBplistData) {
            return [];
        }
        $roh = $daten->bytes;
        $out = [];
        while ($roh !== '') {
            // Feld 1 (type) hat den Schlüssel 0x08; ohne Längenpräfix (kommt vor) ist es die ganze Nachricht
            if (ord($roh[0]) === 0x08) {
                $out[] = $roh;
                break;
            }
            [$laenge, $pos] = AirPlayProtobuf::varintLesen($roh, 0);
            $out[] = substr($roh, $pos, $laenge);
            $roh = (string)substr($roh, $pos + $laenge);
        }
        return $out;
    }

    public function schliessen(): void
    {
        $this->verbindung->schliessen();
    }
}
