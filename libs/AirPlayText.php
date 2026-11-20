<?php

declare(strict_types=1);

// ===========================================================================
// Übersetzung für die Bibliotheken. Quelltexte sind englisch, Deutsch steht in
// libs/locale.json (kopiert in beide Modulordner: .tools/locale-bauen.php).
// Jedes Modul setzt im Konstruktor seinen Übersetzer ($this->Translate), ohne
// Übersetzer bleibt der englische Text stehen.
// ===========================================================================

final class AirPlayText
{
    /** @var callable|null fn(string): string */
    private static $uebersetzer = null;

    public static function setzen(?callable $uebersetzer): void
    {
        self::$uebersetzer = $uebersetzer;
    }

    /** Text übersetzen; mit Argumenten wie sprintf (Platzhalter %s). */
    /** Fehlertext übersetzt: AirPlayFehler über seine Vorlage, sonst die Meldung selbst. */
    public static function fehler(Throwable $e): string
    {
        return $e instanceof AirPlayFehler ? self::t($e->vorlage, ...$e->werte) : self::t($e->getMessage());
    }

    public static function t(string $text, ...$args): string
    {
        $uebersetzt = self::$uebersetzer !== null ? (string)(self::$uebersetzer)($text) : $text;
        if ($args === []) {
            return $uebersetzt;
        }
        $aus = @vsprintf($uebersetzt, array_map(fn ($a) => is_scalar($a) || $a === null ? (string)$a : (string)json_encode($a), $args));
        return $aus === false ? $uebersetzt : $aus;
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
