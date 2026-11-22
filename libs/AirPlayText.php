<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für die Bibliotheken. Quelltexte sind englisch, Deutsch steht in
// libs/locale.json (in jeden Modulordner kopiert: .tools/locale-de.php).
// Jedes Modul setzt im Konstruktor seinen Übersetzer ($this->Translate); ohne
// Übersetzer bleibt der englische Text stehen. Je Text wird nur einmal
// übersetzt (Merkliste, SUITE.md Stolperstein 24).
// ===========================================================================

final class AirPlayText
{
    /** Höchstlänge eines Sprechtexts (Zeichen) — für alle Sprachdienste gleich. */
    public const MAX_SPRECHTEXT = 1000;

    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;
    /** @var array<string, string> */
    private static array $merkliste = [];

    /**
     * Übersetzer setzen — der jeweils neueste zählt, weil er sicher zu einer lebenden Instanz gehört (ein früher
     * gesetzter könnte zu einer inzwischen gelöschten gehören, SUITE.md/OCPPHub). Die Merkliste bleibt: alle
     * Module der Bibliothek haben dieselbe locale.json.
     */
    public static function setzen(callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s, %1$s). */
    public static function t(string $text, mixed ...$args): string
    {
        $uebersetzt = self::$merkliste[$text] ??= self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(fn ($a) => is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a), $args));
        return $aus === false ? $uebersetzt : $aus;
    }

    /** Fehlertext übersetzt: AirPlayFehler über seine Vorlage, sonst die Meldung selbst. */
    public static function fehler(Throwable $e): string
    {
        return $e instanceof AirPlayFehler ? self::t($e->vorlage, ...$e->werte) : self::t($e->getMessage());
    }

    /** Sprechtext bereinigen (Leerraum zusammenfassen) und begrenzen — gemeinsame Regel aller Sprachdienste. */
    public static function sprechtext(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            throw new AirPlayFehler('the text is empty');
        }
        if (mb_strlen($text) > self::MAX_SPRECHTEXT) {
            throw new AirPlayFehler('the text is longer than %s characters', self::MAX_SPRECHTEXT);
        }
        return $text;
    }
}

/**
 * Fehler der Bibliotheken: englische Vorlage + Werte getrennt, damit das Modul die Vorlage über
 * locale.json übersetzen kann (AirPlayText::fehler()). getMessage() liefert den englischen Text.
 */
final class AirPlayFehler extends RuntimeException
{
    public array $werte;

    public function __construct(public string $vorlage, mixed ...$werte)
    {
        $this->werte = $werte;
        parent::__construct($werte === [] ? $vorlage : (string)@vsprintf($vorlage, array_map('strval', $werte)));
    }
}
