<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayEreigniskanal.php';
require_once __DIR__ . '/AirPlayDatenkanal.php';
require_once __DIR__ . '/AirPlayMrp.php';

// ===========================================================================
// Fernsteuerung über MRP im AirPlay-Tunnel (nach erfolgter Kopplung), nach
// pyatv ap2_session.py / mrp_connection.py:
//
//  1. SETUP mit isRemoteControlOnly → eventPort; Event-Kanal öffnen.
//  2. RECORD.
//  3. SETUP Stream-Typ 130 (Datenkanal) mit zufälligem Seed → dataPort;
//     Datenkanal öffnen (Schlüssel aus K, „DataStream-Salt" + Seed).
//  4. MRP: DEVICE_INFO (Antwort abwarten), SET_CONNECTION_STATE,
//     CLIENT_UPDATES_CONFIG. Danach schickt das Gerät Zustand, Lautstärke,
//     aktuelle App und Titel von selbst.
//  5. Alle 2 s POST /feedback auf der Steuerverbindung, sonst trennt das Gerät.
//
// Eine Fernsteuer-Sitzung unterbricht die Wiedergabe nicht.
// ===========================================================================

final class AirPlayFernsteuerung
{
    private const FEEDBACK_ABSTAND = 2.0;
    private const CLIENT_TYP = '1910A70F-DBC0-4242-AF95-115DB30604E1';

    private ?AirPlayEreigniskanal $ereignisse = null;
    private ?AirPlayDatenkanal $daten = null;
    private float $naechstesFeedback = 0.0;
    public readonly AirPlayMrp $mrp;
    /** @var callable fn(string): void */
    private $log;

    public function __construct(private AirPlayRtsp $rtsp, private string $host, private string $k, callable $log)
    {
        $this->log = $log;
        $this->mrp = new AirPlayMrp();
        $this->rtsp->setzeWarten(fn (float $s) => $this->bedienen($s));
    }

    // ------------------------------------------------------------------ Aufbau

    public function verbinden(): void
    {
        $sitzung = AirPlayRtsp::plist($this->rtsp->austausch('SETUP', null, [], [
            'isRemoteControlOnly' => true,
            'osName' => 'iPhone OS',
            'sourceVersion' => '550.10',
            'timingProtocol' => 'None',
            'model' => 'iPhone14,3',
            'deviceID' => 'AA:BB:CC:DD:EE:FF',
            'osVersion' => '16.5',
            'osBuildVersion' => '20F66',
            'macAddress' => 'AA:BB:CC:DD:EE:FF',
            'sessionUUID' => AirPlayZufall::uuid(),
            'name' => 'Symcon',
        ]));
        $ereignisPort = (int)($sitzung['eventPort'] ?? 0);
        if ($ereignisPort === 0) {
            throw new AirPlayFehler('the device did not open an event channel');
        }
        $this->ereignisse = AirPlayEreigniskanal::oeffnen($this->host, $ereignisPort, $this->k, $this->log);
        $this->rtsp->austausch('RECORD');

        $seed = random_int(1, PHP_INT_MAX);
        $strom = AirPlayRtsp::plist($this->rtsp->austausch('SETUP', null, [], [
            'streams' => [[
                'controlType' => 2,
                'channelID' => AirPlayZufall::uuid(),
                'seed' => $seed,
                'clientUUID' => AirPlayZufall::uuid(),
                'type' => 130,
                'wantsDedicatedSocket' => true,
                'clientTypeUUID' => self::CLIENT_TYP,
            ]],
        ]))['streams'][0] ?? [];
        $datenPort = (int)($strom['dataPort'] ?? 0);
        if ($datenPort === 0) {
            throw new AirPlayFehler('the device did not open a remote control channel');
        }
        $this->daten = AirPlayDatenkanal::mitSchluessel($this->host, $datenPort, $this->k, $seed);
        ($this->log)(sprintf('Fernsteuerkanal offen: Event %d, Daten %d', $ereignisPort, $datenPort));

        // MRP-Begrüßung: DEVICE_INFO muss die erste Nachricht sein
        [$info, $id] = AirPlayMrp::geraeteInfo('Symcon', AirPlayZufall::uuid());
        $this->daten->senden($info);
        $this->warteAufAntwort($id, 5.0);
        $this->daten->senden(AirPlayMrp::verbindungsZustand()[0]);
        $this->daten->senden(AirPlayMrp::aktualisierungen()[0]);
        $this->naechstesFeedback = microtime(true) + self::FEEDBACK_ABSTAND;
    }

    private function warteAufAntwort(string $id, float $sekunden): void
    {
        $ende = microtime(true) + $sekunden;
        while (!$this->mrp->antwortErhalten($id)) {
            if (microtime(true) > $ende) {
                throw new AirPlayFehler('the device did not answer on the remote control channel');
            }
            $this->bedienen(0.1);
            $this->rtsp->antwortenVerwerfen(); // Antworten auf /feedback
        }
    }

    // ------------------------------------------------------------------ Bedienung

    /** Wartet bis zu $sekunden und verarbeitet dabei alles, was das Gerät schickt. */
    public function warten(float $sekunden): void
    {
        $ende = microtime(true) + $sekunden;
        do {
            $this->bedienen(min(0.2, max(0.0, $ende - microtime(true))));
            $this->rtsp->antwortenVerwerfen(); // Antworten auf /feedback
        } while (microtime(true) < $ende);
    }

    /** Befehl senden (play, pause, toggle, stop, next, previous …). */
    public function befehl(string $name): void
    {
        [$nachricht, $id] = AirPlayMrp::befehl($name);
        $this->daten?->senden($nachricht);
        $this->warteAufAntwort($id, 3.0);
    }

    /** Lautstärke 0–100 % setzen; wartet kurz auf die Bestätigung. */
    public function lautstaerke(float $prozent): void
    {
        $this->daten?->senden($this->mrp->lautstaerkeSetzen($prozent)[0]);
        $ende = microtime(true) + 3.0;
        while (($this->mrp->lautstaerke === null || abs($this->mrp->lautstaerke - $prozent) > 1.0) && microtime(true) < $ende) {
            $this->bedienen(0.1);
        }
    }

    /** Wiedergabeliste mit Cover anfordern (Antwort kommt als SET_STATE). */
    public function coverAnfordern(int $hoehe = 400): void
    {
        $this->daten?->senden(AirPlayMrp::warteschlange($hoehe)[0]);
    }

    public function bedienen(float $sekunden): void
    {
        if (microtime(true) >= $this->naechstesFeedback && $this->naechstesFeedback > 0) {
            $this->rtsp->sendenOhneAntwort('POST', '/feedback');
            $this->naechstesFeedback += self::FEEDBACK_ABSTAND;
        }
        $lesen = array_values(array_filter([$this->rtsp->verbindung->socket, $this->ereignisse?->verbindung->socket, $this->daten?->verbindung->socket]));
        $schreiben = null;
        $fehler = null;
        $mikro = max(0, (int)($sekunden * 1e6));
        if (@stream_select($lesen, $schreiben, $fehler, intdiv($mikro, 1000000), $mikro % 1000000) < 1) {
            return;
        }
        foreach ($lesen as $s) {
            if ($s === $this->rtsp->verbindung->socket) {
                if (!$this->rtsp->verbindung->einlesen()) {
                    throw new AirPlayFehler('the device closed the connection');
                }
            } elseif ($this->ereignisse !== null && $s === $this->ereignisse->verbindung->socket) {
                if (!$this->ereignisse->bedienen()) {
                    $this->ereignisse->schliessen();
                    $this->ereignisse = null;
                }
            } elseif ($this->daten !== null) {
                foreach ($this->daten->empfangen() as $nachricht) {
                    $typ = $this->mrp->verarbeiten($nachricht);
                    ($this->log)('MRP empfangen: Typ ' . $typ);
                }
            }
        }
    }

    /** Kanäle schließen. Wirft nie. */
    public function schliessen(): void
    {
        $this->daten?->schliessen();
        $this->ereignisse?->schliessen();
        $this->daten = null;
        $this->ereignisse = null;
        $this->rtsp->setzeWarten(null);
    }
}
