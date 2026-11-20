<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayRtsp.php';

// ===========================================================================
// Event-Kanal einer AirPlay-2-Sitzung: zweite TCP-Verbindung zum Port, den
// das Gerät im ersten SETUP nennt, verschlüsselt mit „Events-Salt". Das Gerät
// schickt dort RTSP-Anfragen (z. B. POST /command, type updateInfo), die mit
// 200 beantwortet werden müssen, sonst beendet es die Sitzung.
// ===========================================================================

final class AirPlayEreigniskanal
{
    /** Vom Gerät gemeldete Daten (updateInfo). */
    public array $geraeteInfo = [];

    private function __construct(public readonly AirPlayVerbindung $verbindung, private $log)
    {
    }

    public static function oeffnen(string $host, int $port, string $k, callable $log): self
    {
        $v = new AirPlayVerbindung($host, $port);
        $v->verschluesseln(
            AirPlayHkdf::sha512('Events-Salt', 'Events-Read-Encryption-Key', $k),
            AirPlayHkdf::sha512('Events-Salt', 'Events-Write-Encryption-Key', $k)
        );
        return new self($v, $log);
    }

    /** Anliegende Anfragen lesen und beantworten. false = Gerät hat den Kanal geschlossen. */
    public function bedienen(): bool
    {
        if (!$this->verbindung->einlesen()) {
            ($this->log)('Event-Kanal vom Gerät geschlossen');
            return false;
        }
        while (($n = $this->verbindung->nachricht()) !== null) {
            $this->merken($n);
            $antwort = "RTSP/1.0 200 OK\r\nContent-Length: 0\r\nAudio-Latency: 0\r\n";
            foreach (['server' => 'Server', 'cseq' => 'CSeq'] as $klein => $name) {
                if (isset($n['kopf'][$klein])) {
                    $antwort .= $name . ': ' . $n['kopf'][$klein] . "\r\n";
                }
            }
            $this->verbindung->schreiben($antwort . "\r\n");
        }
        return true;
    }

    private function merken(array $nachricht): void
    {
        try {
            $daten = AirPlayRtsp::plist($nachricht);
        } catch (Throwable) {
            $daten = [];
        }
        if (($daten['type'] ?? '') === 'updateInfo' && is_array($daten['value'] ?? null)) {
            $this->geraeteInfo = $daten['value'];
        }
        ($this->log)('Event: ' . $nachricht['erste'] . ' ' . (string)($daten['type'] ?? ''));
    }

    public function schliessen(): void
    {
        $this->verbindung->schliessen();
    }
}
