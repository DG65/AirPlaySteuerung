<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Warteschlange für nicht blockierende Durchsagen (AIRP_SayAsync), rein in PHP.
// Die Schlange liegt als JSON in einem Attribut der Instanz; Einreihen und
// Entnehmen sichert das Modul mit einer Semaphore ab. Diese Klasse rechnet nur.
// ===========================================================================

final class AirPlayWarteschlange
{
    /** Höchstzahl wartender Durchsagen je Gerät. */
    public const MAX = 10;
    /** Älter als das (Sekunden) wird nicht mehr gesprochen — sonst käme eine Warnung womöglich Stunden zu spät. */
    public const VERFALL = 600;

    /**
     * @param list<array> $schlange
     * @return list<array>
     */
    public static function anhaengen(array $schlange, string $text, int $lautstaerke, bool $gong, int $zeit): array
    {
        if (trim($text) === '') {
            throw new AirPlayFehler('the text is empty');
        }
        if (count($schlange) >= self::MAX) {
            throw new AirPlayFehler('%s announcements are already waiting', self::MAX);
        }
        $schlange[] = ['text' => $text, 'volume' => max(-1, min(100, $lautstaerke)), 'chime' => $gong, 'time' => $zeit];
        return $schlange;
    }

    /**
     * Erste Durchsage entnehmen.
     * @param list<array> $schlange
     * @return array{0: ?array, 1: list<array>} [Eintrag oder null, Rest]
     */
    public static function entnehmen(array $schlange): array
    {
        $schlange = array_values(array_filter($schlange, 'is_array'));
        if ($schlange === []) {
            return [null, []];
        }
        $erster = array_shift($schlange);
        return [$erster, $schlange];
    }

    public static function verfallen(array $eintrag, int $jetzt): bool
    {
        return $jetzt - (int)($eintrag['time'] ?? 0) > self::VERFALL;
    }
}
