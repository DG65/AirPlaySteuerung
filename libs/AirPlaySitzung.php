<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayKopplung.php';
require_once __DIR__ . '/AirPlayAudioStrom.php';

// ===========================================================================
// Eine Sitzung mit einem AirPlay-Gerät, rein in PHP ohne Symcon.
// Fassade über die Bausteine:
//   AirPlayVerbindung  TCP (+ HAP-Verschlüsselung)
//   AirPlayRtsp        Anfragen auf der Steuerverbindung
//   AirPlayKopplung    Kopplung ohne PIN → gemeinsamer Schlüssel K
//   AirPlayAudioStrom  Echtzeit-Audio (Durchsagen)
// Spätere Stufen (Fernsteuerung über MRP) setzen ebenfalls auf Kopplung und
// Steuerverbindung auf und kommen als weiterer Baustein daneben.
//
// Ablauf: new → koppeln() → audioEinrichten() → abspielen() → schliessen().
// ===========================================================================

final class AirPlaySitzung
{
    private const PORT = 7000;

    private AirPlayRtsp $rtsp;
    private ?AirPlayAudioStrom $strom = null;
    private string $k = '';
    /** @var callable fn(string): void */
    private $log;

    /** @param callable|null $log fn(string $text): void für Debug-Ausgaben */
    public function __construct(private string $host, ?callable $log = null)
    {
        $this->log = $log ?? static function (string $text): void {
        };
        $this->rtsp = new AirPlayRtsp(new AirPlayVerbindung($host, self::PORT));
    }

    /** Geräteinfo per GET /info (vor oder nach der Kopplung). */
    public function info(): array
    {
        return AirPlayRtsp::plist($this->rtsp->austausch('GET', '/info'));
    }

    public function koppeln(): void
    {
        $this->k = AirPlayKopplung::transient($this->rtsp);
        ($this->log)('Kopplung erfolgreich, Verbindung verschlüsselt');
    }

    public function audioEinrichten(): void
    {
        if ($this->k === '') {
            throw new LogicException('not paired');
        }
        $this->strom = new AirPlayAudioStrom($this->rtsp, $this->host, $this->k, $this->log);
        $this->strom->einrichten();
    }

    /**
     * PCM abspielen (44,1 kHz, 16 Bit, Stereo, Big Endian). Blockiert bis zum Ende.
     * @param float|null $lautstaerke 0–100 %, null = Lautstärke des Geräts nicht ändern
     * @return array{pakete:int, dauer:float, verspaetet:int}
     */
    public function abspielen(string $pcm, ?float $lautstaerke): array
    {
        if ($this->strom === null) {
            throw new LogicException('audio not set up');
        }
        return $this->strom->abspielen($pcm, $lautstaerke);
    }

    /** Sitzung beenden und alle Verbindungen schließen. Wirft nie. */
    public function schliessen(): void
    {
        $this->strom?->schliessen();
        $this->strom = null;
        $this->rtsp->verbindung->schliessen();
    }
}
