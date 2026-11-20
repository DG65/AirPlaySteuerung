<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayKrypto.php';

// ===========================================================================
// Apple-Music-Inhalte für die Wiedergabe auf HomePod/Apple TV vorbereiten.
//
// Song-IDs: aus einem Teilen-Link (Playlist oder Album) von music.apple.com.
// Die öffentliche Seite bettet ihre Daten als JSON ein
// (script#serialized-server-data); jeder Titel steht dort mit
// contentDescriptor.kind = "song" und identifiers.storeAdamID.
//
// Warteschlange: Binär-Plist für CommandOptions.systemAppPlaybackQueueData,
// Format nach MediaRemote MRSystemAppPlaybackQueueCreateExternalRepresentation
// (dekompiliertes iOS, 20.11.2026): PQty Typ (3 = Titelliste), PQip sofort
// abspielen, PQri Ersetzungsart, PQfn Feature-Name, PQst Song-IDs, PQfg erster
// Titel, PQts Zufall, PQtr Wiederholen. Der HomePod spielt damit eine Kopie
// der Titelliste ab, nicht die Playlist selbst.
// ===========================================================================

final class AirPlayAppleMusic
{
    /** Höchstzahl Titel je Warteschlange (Größe der Nachricht bleibt so klein). */
    public const MAX_TITEL = 300;

    private const TYP_TITELLISTE = 3;
    private const ERSETZEN_WARTESCHLANGE_LEEREN = 2;
    // MRMediaRemoteShuffleMode / RepeatMode
    private const ZUFALL_AUS = 1;
    private const ZUFALL_TITEL = 3;
    private const WIEDERHOLEN_AUS = 1;

    /** Prüft, ob ein Text ein Apple-Music-Link ist (nur https://music.apple.com/…). */
    public static function istLink(string $text): bool
    {
        $teile = parse_url(trim($text));
        return is_array($teile) && ($teile['scheme'] ?? '') === 'https' && ($teile['host'] ?? '') === 'music.apple.com';
    }

    /**
     * Song-IDs aus einem Eingabetext: Apple-Music-Link (wird abgerufen) oder Liste von IDs (Komma/Leerzeichen).
     * @return list<string>
     */
    public static function songIds(string $eingabe): array
    {
        $eingabe = trim($eingabe);
        if (self::istLink($eingabe)) {
            return self::songIdsAusSeite(self::seiteLaden($eingabe));
        }
        $ids = preg_split('/[\s,;]+/', $eingabe, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($ids as $id) {
            if (preg_match('/^\d{4,15}$/', $id) !== 1) {
                throw new AirPlayFehler('neither an Apple Music link nor a list of song IDs');
            }
        }
        return array_values(array_unique($ids));
    }

    public static function seiteLaden(string $link): string
    {
        $kontext = stream_context_create(['http' => [
            'timeout' => 15,
            'header' => "User-Agent: Mozilla/5.0 (Symcon AirPlay-Steuerung)\r\nAccept-Language: de,en\r\n",
            'ignore_errors' => true,
        ]]);
        $html = @file_get_contents($link, false, $kontext);
        // ab PHP 8.4 Funktion, $http_response_header ist ab 8.5 veraltet
        $kopf = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($kopf[0]) && preg_match('/\s(\d{3})(\s|$)/', (string)$kopf[0], $m) === 1 ? (int)$m[1] : 0;
        if ($html === false || $status !== 200) {
            throw new AirPlayFehler('Apple Music page could not be loaded (HTTP %s)', $status ?: '–');
        }
        return $html;
    }

    /** @return list<string> Song-IDs in der Reihenfolge der Seite, ohne Doppelte */
    public static function songIdsAusSeite(string $html): array
    {
        if (preg_match('/<script[^>]*id="serialized-server-data"[^>]*>(.*?)<\/script>/s', $html, $m) !== 1) {
            throw new AirPlayFehler('the Apple Music page has an unknown format');
        }
        $daten = json_decode($m[1], true);
        if (!is_array($daten)) {
            throw new AirPlayFehler('the Apple Music page has an unknown format');
        }
        $ids = [];
        $suche = function (mixed $knoten) use (&$suche, &$ids): void {
            if (!is_array($knoten)) {
                return;
            }
            $cd = $knoten['contentDescriptor'] ?? null;
            if (is_array($cd) && ($cd['kind'] ?? '') === 'song') {
                $id = (string)($cd['identifiers']['storeAdamID'] ?? '');
                if ($id !== '' && !isset($ids[$id])) {
                    $ids[$id] = true;
                }
            }
            foreach ($knoten as $kind) {
                $suche($kind);
            }
        };
        $suche($daten);
        if ($ids === []) {
            throw new AirPlayFehler('no songs found on the Apple Music page');
        }
        return array_map('strval', array_keys($ids));
    }

    /**
     * Binär-Plist der Warteschlange (CommandOptions.systemAppPlaybackQueueData).
     * @param list<string> $ids Song-IDs, höchstens MAX_TITEL werden übernommen
     */
    public static function warteschlange(array $ids, bool $zufall): string
    {
        $ids = array_slice(array_values(array_map('strval', $ids)), 0, self::MAX_TITEL);
        if ($ids === []) {
            throw new AirPlayFehler('no songs found on the Apple Music page');
        }
        return AirPlayBplist::encode([
            'PQty' => self::TYP_TITELLISTE,
            'PQip' => true,
            'PQri' => self::ERSETZEN_WARTESCHLANGE_LEEREN,
            'PQfn' => 'Symcon',
            'PQst' => $ids,
            'PQfg' => $zufall ? $ids[array_rand($ids)] : $ids[0],
            'PQts' => $zufall ? self::ZUFALL_TITEL : self::ZUFALL_AUS,
            'PQtr' => self::WIEDERHOLEN_AUS,
        ]);
    }
}
