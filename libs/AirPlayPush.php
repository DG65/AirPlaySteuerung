<?php

declare(strict_types=1);

// ===========================================================================
// Push-Mitteilung an eine vom Nutzer gewählte Oberfläche. Symcon hat zwei
// Oberflächen mit verschiedenen Funktionen (SUITE.md Stolperstein 22):
//   WebFront                  WFC_PushNotification(id, Titel, Text, Ton, Ziel)
//   Kachel-Visualisierung     VISU_PostNotificationEx(id, Titel, Text, Icon, Ton, Ziel)
// Titel höchstens 32, Text höchstens 256 — byte-sicher gekürzt, weil
// unklar ist, ob Symcon Zeichen oder Bytes zählt.
// ===========================================================================

final class AirPlayPush
{
    public const GUID_WEBFRONT = '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}';
    public const GUID_KACHEL = '{B5B875BB-9B76-45FD-4E67-2607E45B3AC4}';
    private const TITEL_MAX = 32;
    private const TEXT_MAX = 256;

    /** Mitteilung senden; false, wenn keine passende Oberfläche gewählt ist oder Symcon ablehnt. */
    public static function senden(int $oberflaeche, string $titel, string $text, int $ziel): bool
    {
        if ($oberflaeche <= 0 || !@IPS_InstanceExists($oberflaeche)) {
            return false;
        }
        $titel = self::kuerzen($titel, self::TITEL_MAX);
        $text = self::kuerzen($text, self::TEXT_MAX);
        return match (IPS_GetInstance($oberflaeche)['ModuleInfo']['ModuleID']) {
            self::GUID_KACHEL => function_exists('VISU_PostNotificationEx') && (bool)@VISU_PostNotificationEx($oberflaeche, $titel, $text, 'Warning', 'alarm', $oberflaeche),
            self::GUID_WEBFRONT => function_exists('WFC_PushNotification') && (bool)@WFC_PushNotification($oberflaeche, $titel, $text, 'alarm', $ziel),
            default => false,
        };
    }

    /** Auf höchstens $bytes Bytes kürzen, ohne ein UTF-8-Zeichen zu zerschneiden. */
    public static function kuerzen(string $text, int $bytes): string
    {
        if (strlen($text) <= $bytes) {
            return $text;
        }
        return mb_strcut($text, 0, $bytes - 3, 'UTF-8') . '...';
    }
}
