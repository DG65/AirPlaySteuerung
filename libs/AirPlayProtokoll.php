<?php

declare(strict_types=1);

// ===========================================================================
// Durchsage-Protokoll: die letzten Einträge, neueste zuerst.
// ===========================================================================

final class AirPlayProtokoll
{
    public const MAX = 20;

    /**
     * @param list<array> $liste
     * @param array{zeit:int, quelle:string, text:string, ok:bool, info:string} $eintrag
     * @return list<array>
     */
    public static function eintragen(array $liste, array $eintrag): array
    {
        $eintrag['text'] = mb_substr($eintrag['text'], 0, 120);
        return array_slice(array_merge([$eintrag], array_values(array_filter($liste, 'is_array'))), 0, self::MAX);
    }
}
