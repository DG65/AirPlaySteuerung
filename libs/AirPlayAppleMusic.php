<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayKrypto.php';
require_once __DIR__ . '/AirPlayHttp.php';

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
            return self::abTitel(self::songIdsAusSeite(self::seiteLaden($eingabe)), self::startTitel($eingabe));
        }
        $ids = preg_split('/[\s,;]+/', $eingabe, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($ids as $id) {
            if (preg_match('/^\d{4,15}$/', $id) !== 1) {
                throw new AirPlayFehler('neither an Apple Music link nor a list of song IDs');
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Ausgewähltes Lied eines Album-Links (…/album/…/1440771262?i=1440771270 — so teilt Apple Music ein einzelnes
     * Lied); null = kein Lied ausgewählt.
     */
    public static function startTitel(string $link): ?string
    {
        parse_str((string)parse_url(trim($link), PHP_URL_QUERY), $werte);
        $id = (string)($werte['i'] ?? '');
        return preg_match('/^\d{4,15}$/', $id) === 1 ? $id : null;
    }

    /**
     * Titelliste so drehen, dass sie mit dem ausgewählten Lied beginnt und danach dem Album folgt (die Titel davor
     * kommen ans Ende) — wie beim Antippen eines Lieds in der Apple-Music-App.
     * @param list<string> $ids
     * @return list<string>
     */
    public static function abTitel(array $ids, ?string $start): array
    {
        if ($start === null) {
            return $ids;
        }
        $pos = array_search($start, $ids, true);
        return $pos === false ? array_merge([$start], $ids) : array_merge(array_slice($ids, $pos), array_slice($ids, 0, $pos));
    }

    public static function seiteLaden(string $link): string
    {
        [$status, $html] = AirPlayHttp::anfrage('GET', $link, ['Accept-Language: de,en'], null, 15);
        if ($status !== 200) {
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
     * @param bool $ersterFest true = mit dem ersten Titel beginnen, auch bei Zufall (Link auf ein bestimmtes Lied)
     */
    public static function warteschlange(array $ids, bool $zufall, bool $ersterFest = false): string
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
            'PQfg' => $zufall && !$ersterFest ? $ids[array_rand($ids)] : $ids[0],
            'PQts' => $zufall ? self::ZUFALL_TITEL : self::ZUFALL_AUS,
            'PQtr' => self::WIEDERHOLEN_AUS,
        ]);
    }
}
