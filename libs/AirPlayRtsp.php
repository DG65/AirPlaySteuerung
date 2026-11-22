<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayVerbindung.php';
require_once __DIR__ . '/AirPlayAbsender.php';

// ===========================================================================
// Anfragen auf der Steuerverbindung zu einem AirPlay-Gerät (TCP 7000):
// HTTP/1.1 für die Kopplung, RTSP/1.0 für Sitzung und Strom. Führt CSeq und
// die Kennungen, die das Gerät in jeder RTSP-Anfrage erwartet.
//
// Während auf eine Antwort gewartet wird, ruft die Klasse den Warte-Haken auf.
// Ohne Haken wartet sie nur auf der eigenen Verbindung; der Audiostrom setzt
// einen Haken, der zusätzlich Zeitabfragen und den Event-Kanal bedient.
// ===========================================================================

final class AirPlayRtsp
{
    private const ZEITLIMIT = 5.0;

    private int $cseq = 0;
    private string $dacpId;
    private int $activeRemote;
    /** Kennung dieser Sitzung (RTSP-URI, streamConnectionID, RTP-SSRC). */
    public readonly int $sitzungsId;
    /** @var callable|null fn(float $sekunden): void */
    private $warten = null;

    public function __construct(public readonly AirPlayVerbindung $verbindung)
    {
        $this->sitzungsId = random_int(1, 0xFFFFFFFF);
        $this->dacpId = strtoupper(bin2hex(random_bytes(8)));
        $this->activeRemote = random_int(1, 0xFFFFFFFF);
    }

    /** @param callable|null $warten fn(float $sekunden): void — muss die Steuerverbindung mit einlesen */
    public function setzeWarten(?callable $warten): void
    {
        $this->warten = $warten;
    }

    public function uri(): string
    {
        return 'rtsp://' . $this->verbindung->lokaleIp . '/' . $this->sitzungsId;
    }

    /** HTTP/1.1-Anfrage (Kopplung). Liefert die Antwort ohne Statusprüfung. */
    public function http(string $methode, string $pfad, array $kopf, string $koerper = ''): array
    {
        return $this->anfrage($methode, $pfad, 'HTTP/1.1', $kopf, $koerper);
    }

    /**
     * RTSP-Anfrage; ein Array als Körper wird als Binär-Plist gesendet.
     * Wirft AirPlayFehler, wenn das Gerät nicht mit 200 antwortet.
     */
    public function austausch(string $methode, ?string $uri = null, array $zusatz = [], array|string|null $koerper = null, string $typ = ''): array
    {
        $kopf = $this->rtspKopf() + $zusatz;
        $roh = '';
        if (is_array($koerper)) {
            $kopf['Content-Type'] = 'application/x-apple-binary-plist';
            $roh = AirPlayBplist::encode($koerper);
        } elseif (is_string($koerper)) {
            $roh = $koerper;
            if ($typ !== '') {
                $kopf['Content-Type'] = $typ;
            }
        }
        $antwort = $this->anfrage($methode, $uri ?? $this->uri(), 'RTSP/1.0', $kopf, $roh);
        if ($antwort['code'] !== 200) {
            throw new AirPlayFehler('%s rejected by the device (%s)', $methode, $antwort['erste']);
        }
        return $antwort;
    }

    /** Antwortkörper als Binär-Plist lesen (leer, wenn keine Plist). */
    public static function plist(array $antwort): array
    {
        return str_starts_with($antwort['koerper'], 'bplist00') ? (array)AirPlayBplist::decode($antwort['koerper']) : [];
    }

    /** RTSP-Anfrage senden, ohne auf die Antwort zu warten (z. B. /feedback während des Stroms). */
    public function sendenOhneAntwort(string $methode, string $uri): void
    {
        $this->verbindung->schreiben(self::nachricht($methode, $uri, 'RTSP/1.0', ['User-Agent' => AirPlayAbsender::USER_AGENT] + $this->rtspKopf(), ''));
    }

    /**
     * Liegen gebliebene Antworten (z. B. auf sendenOhneAntwort) verwerfen — hält den Puffer während langer
     * Ströme klein; die Zuordnung der eigenen Antwort macht anfrage() über die CSeq.
     */
    public function antwortenVerwerfen(): void
    {
        while ($this->verbindung->nachricht() !== null) {
        }
    }

    private function rtspKopf(): array
    {
        return [
            'CSeq' => $this->cseq++,
            'DACP-ID' => $this->dacpId,
            'Active-Remote' => $this->activeRemote,
            'Client-Instance' => $this->dacpId,
        ];
    }

    private static function nachricht(string $methode, string $uri, string $protokoll, array $kopf, string $koerper): string
    {
        if (!isset($kopf['User-Agent'])) {
            $kopf = ['User-Agent' => AirPlayAbsender::USER_AGENT] + $kopf;
        }
        if ($koerper !== '') {
            $kopf['Content-Length'] = strlen($koerper);
        }
        $text = $methode . ' ' . $uri . ' ' . $protokoll . "\r\n";
        foreach ($kopf as $name => $wert) {
            $text .= $name . ': ' . $wert . "\r\n";
        }
        return $text . "\r\n" . $koerper;
    }

    /**
     * Anfrage senden und die zugehörige Antwort liefern. RTSP-Antworten werden über die CSeq zugeordnet:
     * Antworten auf frühere Anfragen ohne Warten (/feedback) werden übersprungen. HTTP (Kopplung) hat keine CSeq.
     */
    private function anfrage(string $methode, string $uri, string $protokoll, array $kopf, string $koerper): array
    {
        $this->verbindung->schreiben(self::nachricht($methode, $uri, $protokoll, $kopf, $koerper));
        $cseq = isset($kopf['CSeq']) ? (string)$kopf['CSeq'] : null;
        $ende = microtime(true) + self::ZEITLIMIT;
        while (true) {
            while (($antwort = $this->verbindung->nachricht()) !== null) {
                if ($cseq === null || ($antwort['kopf']['cseq'] ?? $cseq) === $cseq) {
                    return $antwort;
                }
            }
            if (microtime(true) > $ende) {
                throw new AirPlayFehler('no answer to %s %s', $methode, $uri);
            }
            $this->warte(0.05);
        }
    }

    private function warte(float $sekunden): void
    {
        if ($this->warten !== null) {
            ($this->warten)($sekunden);
            return;
        }
        $lesen = [$this->verbindung->socket];
        $schreiben = null;
        $fehler = null;
        if (@stream_select($lesen, $schreiben, $fehler, 0, (int)($sekunden * 1e6)) > 0 && !$this->verbindung->einlesen()) {
            throw new AirPlayFehler('the device closed the connection');
        }
    }
}
