<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayProtobuf.php';

// ===========================================================================
// MRP (Media Remote Protocol): Nachrichten bauen und den Wiedergabezustand
// aus eingehenden Nachrichten mitführen. Rein in PHP ohne Symcon.
// Feldnummern nach den .proto-Dateien von pyatv (ProtocolMessage,
// DeviceInfoMessage, SetStateMessage, ContentItemMetadata …).
//
// ProtocolMessage: type = 1, identifier = 2, errorCode = 4,
// uniqueIdentifier = 85; die eigentliche Nachricht steckt in einer
// Erweiterung mit eigener Feldnummer (z. B. setStateMessage = 9).
// ===========================================================================

final class AirPlayMrp
{
    // Nachrichtentypen (ProtocolMessage.Type)
    public const SEND_COMMAND = 1;
    public const SEND_COMMAND_RESULT = 2;
    public const SET_STATE = 4;
    /** Befehl SetPlaybackQueue (CommandInfo.proto) */
    private const BEFEHL_WARTESCHLANGE = 48;
    public const DEVICE_INFO = 15;
    public const CLIENT_UPDATES_CONFIG = 16;
    public const PLAYBACK_QUEUE_REQUEST = 32;
    public const SET_CONNECTION_STATE = 38;
    public const SET_NOW_PLAYING_CLIENT = 46;
    public const SET_NOW_PLAYING_PLAYER = 47;
    public const SET_VOLUME = 51;
    public const VOLUME_DID_CHANGE = 52;
    public const UPDATE_CLIENT = 55;
    public const UPDATE_CONTENT_ITEM = 56;

    // Feldnummern der Erweiterungen in ProtocolMessage
    private const EXT = [
        self::SEND_COMMAND => 6,
        self::SEND_COMMAND_RESULT => 7,
        self::SET_STATE => 9,
        self::DEVICE_INFO => 20,
        self::CLIENT_UPDATES_CONFIG => 21,
        self::PLAYBACK_QUEUE_REQUEST => 37,
        self::SET_CONNECTION_STATE => 42,
        self::SET_NOW_PLAYING_CLIENT => 50,
        self::SET_NOW_PLAYING_PLAYER => 51,
        self::SET_VOLUME => 55,
        self::VOLUME_DID_CHANGE => 56,
        self::UPDATE_CLIENT => 59,
        self::UPDATE_CONTENT_ITEM => 60,
    ];

    // Befehle (CommandInfo.proto, enum Command)
    public const BEFEHLE = [
        'play' => 1, 'pause' => 2, 'toggle' => 3, 'stop' => 4, 'next' => 5, 'previous' => 6,
        'shuffle' => 7, 'repeat' => 8, 'skipForward' => 18, 'skipBackward' => 19,
    ];

    // Anzeigenamen für Apple-eigene Apps (der HomePod nennt seine Musik-App intern „AirMusic")
    private const APP_NAMEN = [
        'com.apple.Music' => 'Apple Music',
        'com.apple.podcasts' => 'Podcasts',
        'com.apple.TVMusic' => 'Apple Music',
        'com.apple.TVWatchList' => 'Apple TV',
    ];

    // PlaybackState.Enum
    public const ZUSTAND = [0 => 'unknown', 1 => 'playing', 2 => 'paused', 3 => 'stopped', 4 => 'interrupted', 5 => 'seeking'];

    /** @var array<string, array> Player je Pfad „Bundle|Player-ID" */
    private array $player = [];
    /** @var array<string, string> Anzeigename je Bundle */
    private array $clients = [];
    private string $aktiverClient = '';
    private string $aktiverPlayer = '';
    /** Laufende Nummer der letzten Zustandsmeldung je Player (neueste gewinnt) */
    private int $folge = 0;
    public ?float $lautstaerke = null;
    public string $geraeteUid = '';
    public string $geraeteName = '';
    /** @var array<string, bool> eingegangene Antworten je identifier */
    private array $antworten = [];
    /** @var array<string, array{sendError:int, status:?int}> Ergebnisse von Befehlen je identifier */
    private array $befehlsErgebnisse = [];

    // ------------------------------------------------------------------ Bauen

    /** ProtocolMessage mit innerer Nachricht; liefert [Bytes, identifier]. */
    private static function nachricht(int $typ, string $innen, bool $mitId = true): array
    {
        $id = $mitId ? AirPlayZufall::uuid() : '';
        $p = AirPlayProtobuf::feld(1, 'varint', $typ);
        if ($mitId) {
            $p .= AirPlayProtobuf::feld(2, 'string', $id);
        }
        $p .= AirPlayProtobuf::feld(4, 'varint', 0)
            . AirPlayProtobuf::feld(self::EXT[$typ], 'msg', $innen)
            . AirPlayProtobuf::feld(85, 'string', AirPlayZufall::uuid());
        return [$p, $id];
    }

    public static function geraeteInfo(string $name, string $kennung): array
    {
        $f = fn (int $n, string $t, mixed $w) => AirPlayProtobuf::feld($n, $t, $w);
        return self::nachricht(self::DEVICE_INFO,
            $f(1, 'string', $kennung) . $f(2, 'string', $name) . $f(3, 'string', 'iPhone') . $f(4, 'string', '18G82')
            . $f(5, 'string', 'com.apple.TVRemote') . $f(6, 'string', '344.28') . $f(7, 'varint', 1) . $f(8, 'varint', 108)
            . $f(9, 'bool', true) . $f(10, 'bool', true) . $f(12, 'string', 'com.apple.TVMusic') . $f(13, 'bool', true)
            . $f(14, 'bool', true) . $f(15, 'bool', true) . $f(17, 'varint', 2) . $f(21, 'varint', 1) . $f(22, 'varint', 1));
    }

    public static function verbindungsZustand(): array
    {
        return self::nachricht(self::SET_CONNECTION_STATE, AirPlayProtobuf::feld(1, 'varint', 2), false); // Connected
    }

    public static function aktualisierungen(): array
    {
        $f = fn (int $n, bool $w) => AirPlayProtobuf::feld($n, 'bool', $w);
        // artwork, nowPlaying (wie pyatv aus; SetState kommt trotzdem), volume, keyboard, outputDevices
        return self::nachricht(self::CLIENT_UPDATES_CONFIG, $f(1, true) . $f(2, false) . $f(3, true) . $f(4, false) . $f(5, true));
    }

    public static function befehl(string $name): array
    {
        if (!isset(self::BEFEHLE[$name])) {
            throw new AirPlayFehler('unknown command: %s', $name);
        }
        return self::nachricht(self::SEND_COMMAND, AirPlayProtobuf::feld(1, 'varint', self::BEFEHLE[$name]));
    }

    /**
     * Warteschlange setzen (SetPlaybackQueue mit systemAppPlaybackQueueData, siehe AirPlayAppleMusic).
     */
    public static function warteschlangeSetzen(string $warteschlange): array
    {
        $optionen = AirPlayProtobuf::feld(15, 'bytes', $warteschlange);
        return self::nachricht(self::SEND_COMMAND, AirPlayProtobuf::feld(1, 'varint', self::BEFEHL_WARTESCHLANGE) . AirPlayProtobuf::feld(2, 'msg', $optionen));
    }

    /** @param float $prozent 0–100 */
    public function lautstaerkeSetzen(float $prozent): array
    {
        if ($this->geraeteUid === '') {
            throw new AirPlayFehler('the device did not report an output device');
        }
        return self::nachricht(self::SET_VOLUME, AirPlayProtobuf::feld(1, 'float', max(0.0, min(100.0, $prozent)) / 100.0)
            . AirPlayProtobuf::feld(2, 'string', $this->geraeteUid));
    }

    /** Wiedergabeliste anfordern (mit Cover in der gewünschten Höhe). */
    public static function warteschlange(int $coverHoehe = 400): array
    {
        $f = fn (int $n, string $t, mixed $w) => AirPlayProtobuf::feld($n, $t, $w);
        return self::nachricht(self::PLAYBACK_QUEUE_REQUEST,
            $f(1, 'varint', 0) . $f(2, 'varint', 1) . $f(3, 'bool', true) . $f(4, 'double', -1.0) . $f(5, 'double', (float)$coverHoehe) . $f(13, 'bool', true));
    }

    // ------------------------------------------------------------------ Verarbeiten

    /** Eingehende ProtocolMessage auswerten; liefert den Nachrichtentyp. */
    public function verarbeiten(string $bytes): int
    {
        $p = AirPlayProtobuf::decode($bytes);
        $typ = (int)AirPlayProtobuf::int($p, 1, 0);
        $id = AirPlayProtobuf::text($p, 2);
        if ($id !== null && $id !== '') {
            $this->antworten[$id] = true;
        }
        $innen = isset(self::EXT[$typ]) ? AirPlayProtobuf::msg($p, self::EXT[$typ]) : [];
        switch ($typ) {
            case self::SEND_COMMAND_RESULT:
                if ($id !== null && $id !== '') {
                    $this->befehlsErgebnisse[$id] = ['sendError' => (int)AirPlayProtobuf::int($innen, 1, 0), 'status' => AirPlayProtobuf::int($innen, 2)];
                }
                break;
            case self::DEVICE_INFO:
                $this->geraeteUid = (string)(AirPlayProtobuf::text($innen, 35) ?: AirPlayProtobuf::text($innen, 19) ?: '');
                $this->geraeteName = (string)AirPlayProtobuf::text($innen, 2);
                break;
            case self::VOLUME_DID_CHANGE:
                $v = AirPlayProtobuf::float($innen, 1);
                if ($v !== null) {
                    $this->lautstaerke = round($v * 100, 1);
                }
                break;
            case self::SET_NOW_PLAYING_CLIENT:
                $this->aktiverClient = (string)AirPlayProtobuf::text(AirPlayProtobuf::msg($innen, 1), 2);
                break;
            case self::SET_NOW_PLAYING_PLAYER:
                $this->aktiverPlayer = self::pfad(AirPlayProtobuf::msg($innen, 1));
                $this->aktiverClient = explode('|', $this->aktiverPlayer)[0];
                break;
            case self::UPDATE_CLIENT:
                $c = AirPlayProtobuf::msg($innen, 1);
                $bundle = (string)AirPlayProtobuf::text($c, 2);
                if ($bundle !== '') {
                    $this->clients[$bundle] = (string)(AirPlayProtobuf::text($c, 7) ?? $bundle);
                }
                break;
            case self::SET_STATE:
                $this->zustandSetzen($innen);
                break;
            case self::UPDATE_CONTENT_ITEM:
                $pfad = self::pfad(AirPlayProtobuf::msg($innen, 2));
                foreach (AirPlayProtobuf::msgs($innen, 1) as $item) {
                    $this->inhaltUebernehmen($pfad, $item);
                }
                break;
        }
        return $typ;
    }

    public function antwortErhalten(string $identifier): bool
    {
        return isset($this->antworten[$identifier]);
    }

    /** @return array{sendError:int, status:?int}|null Ergebnis eines Befehls, null = keins erhalten */
    public function befehlsErgebnis(string $identifier): ?array
    {
        return $this->befehlsErgebnisse[$identifier] ?? null;
    }

    /** „Bundle|Player-ID" aus einem PlayerPath. */
    private static function pfad(array $playerPath): string
    {
        $client = AirPlayProtobuf::msg($playerPath, 2);
        $player = AirPlayProtobuf::msg($playerPath, 3);
        return (string)AirPlayProtobuf::text($client, 2) . '|' . (string)(AirPlayProtobuf::text($player, 1) ?? 'MediaRemote-DefaultPlayer');
    }

    private function zustandSetzen(array $s): void
    {
        $pfad = self::pfad(AirPlayProtobuf::msg($s, 9));
        $p = $this->player[$pfad] ?? [];
        $p['folge'] = ++$this->folge;
        $zustand = AirPlayProtobuf::int($s, 6);
        if ($zustand !== null) {
            $p['zustand'] = self::ZUSTAND[$zustand] ?? 'unknown';
        }
        $anzeige = AirPlayProtobuf::text($s, 5);
        if ($anzeige !== null) {
            $p['app'] = $anzeige;
        }
        // ältere Geräte: NowPlayingInfo
        $npi = AirPlayProtobuf::msg($s, 1);
        if ($npi !== []) {
            $p['titel'] = AirPlayProtobuf::text($npi, 9) ?? ($p['titel'] ?? '');
            $p['interpret'] = AirPlayProtobuf::text($npi, 2) ?? ($p['interpret'] ?? '');
            $p['album'] = AirPlayProtobuf::text($npi, 1) ?? ($p['album'] ?? '');
            $p['dauer'] = AirPlayProtobuf::double($npi, 3) ?? ($p['dauer'] ?? null);
        }
        $this->player[$pfad] = $p;
        $warteschlange = AirPlayProtobuf::msg($s, 3);
        foreach (AirPlayProtobuf::msgs($warteschlange, 2) as $i => $item) {
            if ($i === 0) {
                $this->inhaltUebernehmen($pfad, $item, true);
            }
        }
    }

    /** ContentItem (Metadaten, Cover) in den Player übernehmen. */
    private function inhaltUebernehmen(string $pfad, array $item, bool $ersetzen = false): void
    {
        $p = $this->player[$pfad] ?? [];
        $id = (string)AirPlayProtobuf::text($item, 1);
        if (!$ersetzen && isset($p['inhalt']) && $p['inhalt'] !== $id && $id !== '') {
            return; // Aktualisierung eines anderen Eintrags der Liste
        }
        $p['inhalt'] = $id !== '' ? $id : ($p['inhalt'] ?? '');
        $m = AirPlayProtobuf::msg($item, 2);
        foreach ([1 => 'titel', 7 => 'interpret', 6 => 'album'] as $nr => $schluessel) {
            $wert = AirPlayProtobuf::text($m, $nr);
            if ($wert !== null) {
                $p[$schluessel] = $wert;
            }
        }
        foreach ([14 => 'dauer', 35 => 'position'] as $nr => $schluessel) {
            $wert = AirPlayProtobuf::double($m, $nr);
            if ($wert !== null) {
                $p[$schluessel] = $wert;
            }
        }
        $cover = AirPlayProtobuf::text($item, 3);
        if ($cover !== null && $cover !== '') {
            $p['cover'] = $cover;
            $p['coverTyp'] = (string)(AirPlayProtobuf::text($m, 31) ?? 'image/jpeg');
        }
        $this->player[$pfad] = $p;
    }

    /** Pfad des zuletzt gemeldeten Players, bevorzugt einer, der gerade spielt. */
    private function juengsterPlayer(): string
    {
        $bester = '';
        $besterRang = [-1, -1];
        foreach ($this->player as $pfad => $p) {
            $rang = [($p['zustand'] ?? '') === 'playing' ? 1 : 0, (int)($p['folge'] ?? 0)];
            if ($rang > $besterRang) {
                [$bester, $besterRang] = [$pfad, $rang];
            }
        }
        return $bester;
    }

    /**
     * Zustand des aktiven Players (oder des zuletzt spielenden).
     * @return array{zustand:string, app:string, titel:string, interpret:string, album:string, dauer:?float, position:?float, cover:string, coverTyp:string, lautstaerke:?float}
     */
    public function wiedergabe(): array
    {
        $pfad = $this->aktiverPlayer !== '' && isset($this->player[$this->aktiverPlayer]) ? $this->aktiverPlayer : $this->juengsterPlayer();
        $p = $this->player[$pfad] ?? [];
        $bundle = explode('|', $pfad)[0];
        return [
            'zustand' => (string)($p['zustand'] ?? 'unknown'),
            'app' => (string)(self::APP_NAMEN[$bundle] ?? $p['app'] ?? $this->clients[$bundle] ?? $bundle),
            'titel' => (string)($p['titel'] ?? ''),
            'interpret' => (string)($p['interpret'] ?? ''),
            'album' => (string)($p['album'] ?? ''),
            'dauer' => $p['dauer'] ?? null,
            'position' => $p['position'] ?? null,
            'cover' => (string)($p['cover'] ?? ''),
            'coverTyp' => (string)($p['coverTyp'] ?? ''),
            'lautstaerke' => $this->lautstaerke,
        ];
    }
}
