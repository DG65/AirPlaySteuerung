<?php

declare(strict_types=1);

// ===========================================================================
// Gruppen-Durchsage: Welche Geräte gehören zu einer Gruppe? Ein Gerät trägt
// seine Gruppen kommagetrennt ein („Erdgeschoss, Küche"); eine leere Gruppe
// meint alle Geräte. Vergleich ohne Groß-/Kleinschreibung.
// ===========================================================================

final class AirPlayGruppe
{
    public static function enthaelt(string $geraetGruppen, string $gruppe): bool
    {
        $gruppe = mb_strtolower(trim($gruppe));
        if ($gruppe === '') {
            return true;
        }
        foreach (explode(',', $geraetGruppen) as $g) {
            if (mb_strtolower(trim($g)) === $gruppe) {
                return true;
            }
        }
        return false;
    }
}
