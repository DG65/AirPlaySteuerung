<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayEreigniskanal.php';
require_once __DIR__ . '/AirPlayAbsender.php';

// ===========================================================================
// Echtzeit-Audiostrom über AirPlay 2 (nach erfolgter Kopplung):
//
//  1. SETUP (Sitzung): eigener Zeit-Port (NTP) → Gerät nennt eventPort;
//     Event-Kanal öffnen (Events-Salt) und dort jede Anfrage mit 200 beantworten.
//  2. SETUP (Strom): type 0x60 = Echtzeit, ct 1 = rohes PCM (kein ALAC),
//     44,1 kHz, 16 Bit, Stereo, 352 Frames je Paket → dataPort, controlPort.
//  3. RECORD, FLUSH, Lautstärke (SET_PARAMETER volume in dB, −30…0, −144 = stumm).
//  4. Pakete im Echtzeittakt: RTP-Kopf (12 Byte) + ChaCha20(PCM Big Endian,
//     AAD = Kopf[4:12]) + 8 Byte Nonce. Nebenbei Sync-Pakete (1 s),
//     /feedback (2 s) und Zeitabfragen des Geräts sofort beantworten.
//  5. Nach dem Ende Stille für die Latenz nachschieben, dann TEARDOWN.
//
// Getestet (20.11.2026) an HomePod (1. Gen.) und HomePod mini, tvOS 27.2.
// ===========================================================================

final class AirPlayAudioStrom
{
    public const ABTASTRATE = 44100;
    public const FRAMES_JE_PAKET = 352;
    private const BYTES_JE_FRAME = 4;
    private const LATENZ = 22050 + 44100;
    private const NTP_EPOCHE = 0x83AA7E80;
    private const SYNC_ABSTAND = 1.0;
    private const FEEDBACK_ABSTAND = 2.0;

    /** @var resource|null */
    private $zeit = null;
    /** @var resource|null */
    private $steuerung = null;
    /** @var resource|null */
    private $audio = null;
    private ?AirPlayEreigniskanal $ereignisse = null;
    private ?AirPlayChaCha $chiffre = null;
    private int $steuerPort = 0;
    private int $rtpSeq = 0;
    private int $gesendeteFrames = 0;
    private int $startMikro = 0;
    private bool $ersteSync = true;
    /** @var callable fn(string): void */
    private $log;

    /** @param string $k gemeinsamer Schlüssel aus der Kopplung */
    public function __construct(private AirPlayRtsp $rtsp, private string $host, private string $k, callable $log)
    {
        $this->log = $log;
        $this->rtsp->setzeWarten(fn (float $s) => $this->bedienen($s));
    }

    /** Lautstärke 0–100 % in AirPlay-dB (−30…0, 0 % = stumm). */
    public static function lautstaerkeDb(float $prozent): float
    {
        return $prozent <= 0 ? -144.0 : -30.0 + 30.0 * min(100.0, $prozent) / 100.0;
    }

    // ------------------------------------------------------------------ Aufbau

    public function einrichten(): void
    {
        $this->zeit = self::udpOeffnen($this->rtsp->verbindung->lokaleIp);
        $this->steuerung = self::udpOeffnen($this->rtsp->verbindung->lokaleIp);

        $sitzung = AirPlayRtsp::plist($this->rtsp->austausch('SETUP', null, [], self::sitzungsBeschreibung(self::port($this->zeit))));
        $ereignisPort = (int)($sitzung['eventPort'] ?? 0);
        if ($ereignisPort > 0) {
            $this->ereignisse = AirPlayEreigniskanal::oeffnen($this->host, $ereignisPort, $this->k, $this->log);
        }

        // Der Schlüssel des Audiostroms ist frei wählbar; wie bei pyatv aus K abgeleitet
        $shk = AirPlayHkdf::sha512('Events-Salt', 'Events-Write-Encryption-Key', $this->k);
        $strom = AirPlayRtsp::plist($this->rtsp->austausch('SETUP', null, [], [
            'streams' => [[
                'audioFormat' => 0x800,
                'audioMode' => 'default',
                'controlPort' => self::port($this->steuerung),
                'ct' => 1,
                'isMedia' => true,
                'latencyMax' => 88200,
                'latencyMin' => 11025,
                'shk' => new AirPlayBplistData($shk),
                'spf' => self::FRAMES_JE_PAKET,
                'sr' => self::ABTASTRATE,
                'type' => 0x60,
                'supportsDynamicStreamID' => false,
                'streamConnectionID' => $this->rtsp->sitzungsId,
            ]],
        ]))['streams'][0] ?? [];
        $datenPort = (int)($strom['dataPort'] ?? 0);
        $this->steuerPort = (int)($strom['controlPort'] ?? 0);
        if ($datenPort === 0 || $this->steuerPort === 0) {
            throw new AirPlayFehler('the device did not assign audio ports');
        }
        $audio = @stream_socket_client('udp://' . $this->host . ':' . $datenPort, $nr, $text);
        if ($audio === false) {
            throw new AirPlayFehler('could not open the audio channel (%s)', $text);
        }
        $this->audio = $audio;
        $this->chiffre = new AirPlayChaCha($shk, $shk);
        ($this->log)(sprintf('Strom eingerichtet: Daten %d, Steuerung %d, Event %d', $datenPort, $this->steuerPort, $ereignisPort));
    }

    /** Angaben zum Absender im ersten SETUP (wie ein iPhone, so wie pyatv). */
    private static function sitzungsBeschreibung(int $zeitPort): array
    {
        return AirPlayAbsender::setup(AirPlayAbsender::VERSION_AUDIO, [
            'timingPort' => $zeitPort,
            'timingProtocol' => 'NTP',
            'isMultiSelectAirPlay' => true,
            'groupContainsGroupLeader' => false,
            'senderSupportsRelay' => false,
            'statsCollectionEnabled' => false,
        ]);
    }

    // ------------------------------------------------------------------ Wiedergabe

    /**
     * PCM abspielen (44,1 kHz, 16 Bit, Stereo, Big Endian). Blockiert bis zum Ende.
     * @param float|null $lautstaerke 0–100 %, null = Lautstärke des Geräts nicht ändern
     * @return array{pakete:int, dauer:float, verspaetet:int}
     */
    public function abspielen(string $pcm, ?float $lautstaerke): array
    {
        if ($this->chiffre === null) {
            throw new LogicException('stream not set up');
        }
        $this->starten($lautstaerke);

        $paketBytes = self::FRAMES_JE_PAKET * self::BYTES_JE_FRAME;
        $pakete = $pcm === '' ? [] : str_split($pcm, $paketBytes);
        $gesamt = count($pakete) + (int)ceil(self::LATENZ / self::FRAMES_JE_PAKET);
        $stille = str_repeat("\0", $paketBytes);
        $t0 = hrtime(true);
        $naechsteSync = self::SYNC_ABSTAND;
        $naechstesFeedback = self::FEEDBACK_ABSTAND;
        $verspaetet = 0;
        for ($i = 0; $i < $gesamt; $i++) {
            $this->paketSenden(str_pad($pakete[$i] ?? $stille, $paketBytes, "\0"), $i === 0);

            $vergangen = (hrtime(true) - $t0) / 1e9;
            if ($vergangen >= $naechsteSync) {
                $this->sync();
                $naechsteSync += self::SYNC_ABSTAND;
            }
            if ($vergangen >= $naechstesFeedback) {
                $this->rtsp->sendenOhneAntwort('POST', '/feedback');
                $naechstesFeedback += self::FEEDBACK_ABSTAND;
            }
            $faellig = ($i + 1) * self::FRAMES_JE_PAKET / self::ABTASTRATE;
            if ($faellig <= $vergangen) {
                $verspaetet++;
            } else {
                $this->bedienen($faellig - $vergangen);
                $rest = $faellig - (hrtime(true) - $t0) / 1e9;
                if ($rest > 0) {
                    usleep((int)($rest * 1e6));
                }
            }
            $this->rtsp->antwortenVerwerfen();
        }
        $dauer = (hrtime(true) - $t0) / 1e9;
        ($this->log)(sprintf('%d Pakete in %.2f s gesendet, %d verspätet', $gesamt, $dauer, $verspaetet));
        return ['pakete' => $gesamt, 'dauer' => $dauer, 'verspaetet' => $verspaetet];
    }

    private function starten(?float $lautstaerke): void
    {
        $this->rtpSeq = random_int(0, 0xFFFF);
        $this->startMikro = self::jetztMikro();
        $this->gesendeteFrames = 0;
        $this->ersteSync = true;
        $this->sync();
        $this->rtsp->sendenOhneAntwort('POST', '/feedback');
        $this->rtsp->austausch('RECORD');
        $this->rtsp->austausch('FLUSH', null, ['Range' => 'npt=0-', 'Session' => 0, 'RTP-Info' => 'seq=' . $this->rtpSeq . ';rtptime=' . $this->rtpZeit()]);
        if ($lautstaerke !== null) {
            $this->rtsp->austausch('SET_PARAMETER', null, [], sprintf('volume: %.6f', self::lautstaerkeDb($lautstaerke)), 'text/parameters');
        }
    }

    private function paketSenden(string $frames, bool $erstes): void
    {
        $kopf = pack('CCnNN', 0x80, $erstes ? 0xE0 : 0x60, $this->rtpSeq, $this->rtpZeit(), $this->rtsp->sitzungsId);
        $nonce = $this->chiffre->naechsteNonce();
        @fwrite($this->audio, $kopf . $this->chiffre->encrypt($frames, substr($kopf, 4, 8)) . substr($nonce, -8));
        $this->rtpSeq = ($this->rtpSeq + 1) & 0xFFFF;
        $this->gesendeteFrames += self::FRAMES_JE_PAKET;
    }

    private function rtpZeit(): int
    {
        return ($this->gesendeteFrames + self::LATENZ) & 0xFFFFFFFF;
    }

    private function sync(): void
    {
        [$sek, $bruch] = self::ntp($this->startMikro + intdiv($this->gesendeteFrames * 1000000, self::ABTASTRATE));
        $paket = pack('CCnNNNN', $this->ersteSync ? 0x90 : 0x80, 0xD4, 7, ($this->rtpZeit() - self::LATENZ) & 0xFFFFFFFF, $sek, $bruch, $this->rtpZeit());
        $this->ersteSync = false;
        stream_socket_sendto($this->steuerung, $paket, 0, $this->host . ':' . $this->steuerPort);
    }

    // ------------------------------------------------------------------ Nebenkanäle

    /** Wartet höchstens $sekunden auf Daten und bedient Steuerverbindung, Zeit-, Steuer- und Event-Kanal. */
    public function bedienen(float $sekunden): void
    {
        $lesen = array_values(array_filter([$this->rtsp->verbindung->socket, $this->zeit, $this->steuerung, $this->ereignisse?->verbindung->socket]));
        $schreiben = null;
        $fehler = null;
        $mikro = max(0, (int)($sekunden * 1e6));
        if (@stream_select($lesen, $schreiben, $fehler, intdiv($mikro, 1000000), $mikro % 1000000) < 1) {
            return;
        }
        foreach ($lesen as $s) {
            match (true) {
                $s === $this->rtsp->verbindung->socket => $this->steuerverbindungLesen(),
                $s === $this->zeit => $this->zeitAbfrageBeantworten(),
                $s === $this->steuerung => $this->steuerPaketLesen(),
                default => $this->ereignisseBedienen(),
            };
        }
    }

    private function steuerverbindungLesen(): void
    {
        if (!$this->rtsp->verbindung->einlesen()) {
            throw new AirPlayFehler('the device closed the connection');
        }
    }

    private function steuerPaketLesen(): void
    {
        $paket = (string)stream_socket_recvfrom($this->steuerung, 2048);
        if (strlen($paket) > 1 && (ord($paket[1]) & 0x7F) === 0x55) {
            ($this->log)('Gerät fordert verlorene Pakete nach (nicht unterstützt)');
        }
    }

    private function zeitAbfrageBeantworten(): void
    {
        $paket = (string)stream_socket_recvfrom($this->zeit, 128, 0, $von);
        if (strlen($paket) < 32) {
            return;
        }
        $a = unpack('Cproto/Ctyp/nseq/Nfrei/Nref_s/Nref_f/Nemp_s/Nemp_f/Nsend_s/Nsend_f', $paket);
        [$sek, $bruch] = self::ntp(self::jetztMikro());
        stream_socket_sendto($this->zeit, pack('CCnNNNNNNN', $a['proto'], 0xD3, 7, 0, $a['send_s'], $a['send_f'], $sek, $bruch, $sek, $bruch), 0, $von);
    }

    private function ereignisseBedienen(): void
    {
        if ($this->ereignisse !== null && !$this->ereignisse->bedienen()) {
            $this->ereignisse->schliessen();
            $this->ereignisse = null;
        }
    }

    // ------------------------------------------------------------------ Abbau

    /** Strom beenden und alle Kanäle schließen. Wirft nie. */
    public function schliessen(): void
    {
        try {
            if ($this->audio !== null) {
                $this->bedienen(0.05);
                $this->rtsp->antwortenVerwerfen();
                $this->rtsp->austausch('TEARDOWN', null, ['Session' => 0]);
            }
        } catch (Throwable $e) {
            ($this->log)('TEARDOWN: ' . $e->getMessage());
        }
        foreach ([$this->audio, $this->zeit, $this->steuerung] as $s) {
            if (is_resource($s)) {
                @fclose($s);
            }
        }
        $this->audio = $this->zeit = $this->steuerung = null;
        $this->ereignisse?->schliessen();
        $this->ereignisse = null;
        $this->rtsp->setzeWarten(null);
    }

    // ------------------------------------------------------------------ Hilfen

    /** @return resource */
    private static function udpOeffnen(string $lokaleIp)
    {
        $s = @stream_socket_server('udp://' . $lokaleIp . ':0', $nr, $text, STREAM_SERVER_BIND);
        if ($s === false) {
            throw new AirPlayFehler('could not open a UDP port (%s)', $text);
        }
        stream_set_blocking($s, false);
        return $s;
    }

    private static function port($socket): int
    {
        $name = (string)stream_socket_get_name($socket, false);
        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    /** NTP-Zeit (Sekunden seit 1900, Bruchteil) zu einer Unix-Zeit in Mikrosekunden. */
    private static function ntp(int $mikro): array
    {
        $sek = intdiv($mikro, 1000000);
        return [($sek + self::NTP_EPOCHE) & 0xFFFFFFFF, intdiv(($mikro - $sek * 1000000) << 32, 1000000) & 0xFFFFFFFF];
    }

    private static function jetztMikro(): int
    {
        return (int)(microtime(true) * 1e6);
    }
}
