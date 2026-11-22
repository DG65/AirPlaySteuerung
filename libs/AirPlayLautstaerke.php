<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayZeitplan.php';

// ===========================================================================
// Lautstärke-Regeln, rein in PHP: Nachtgrenze für Durchsagen und Anstieg
// (Rampe) für den Wecker. Lautstärken in Prozent 0–100; null = „am Gerät
// nicht verändern".
// ===========================================================================

final class AirPlayLautstaerke
{
    /**
     * Wunsch-Lautstärke einer Durchsage nach der Nachtgrenze. Im Fenster wird auf das Maximum begrenzt;
     * soll die Lautstärke unverändert bleiben (null), wird nachts das Maximum gesetzt — die aktuelle
     * Gerätelautstärke könnte höher sein.
     * @param array{aktiv:bool, max:int, von:int, bis:int} $regel
     */
    public static function nachtgrenze(?float $wunsch, array $regel, int $zeit): ?float
    {
        if (!$regel['aktiv'] || !AirPlayZeitplan::stundeImFenster((int)date('G', $zeit), $regel['von'], $regel['bis'])) {
            return $wunsch;
        }
        $max = (float)max(0, min(100, $regel['max']));
        return $wunsch === null ? $max : min($wunsch, $max);
    }

    /** Lautstärke im Anstieg von $start auf $ziel über $dauer Sekunden nach $verstrichen Sekunden (gerundet). */
    public static function rampe(float $start, float $ziel, int $dauer, int $verstrichen): float
    {
        if ($dauer <= 0 || $verstrichen >= $dauer) {
            return round($ziel);
        }
        return round($start + ($ziel - $start) * max(0, $verstrichen) / $dauer);
    }
}
