<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayKrypto.php';

// ===========================================================================
// Wie sich Symcon gegenüber dem Gerät ausgibt: als iPhone, mit den Angaben,
// die pyatv an der jeweiligen Stelle sendet. Die Versionsnummern unterscheiden
// sich je Kanal (Kopplung, RTSP/Fernsteuerung, Audio) — genau so am HomePod
// (1. Gen. und mini) live bestätigt; nicht ohne Gerätetest vereinheitlichen.
// ===========================================================================

final class AirPlayAbsender
{
    public const NAME = 'Symcon';
    public const MODELL = 'iPhone14,3';
    public const OS_NAME = 'iPhone OS';
    public const OS_VERSION = '16.5';
    public const OS_BUILD = '20F66';
    /** Feste Kennung: transient gekoppelt wird ohnehin bei jeder Sitzung neu. */
    public const GERAETE_ID = 'AA:BB:CC:DD:EE:FF';

    public const USER_AGENT_KOPPLUNG = 'AirPlay/320.20';
    public const USER_AGENT = 'AirPlay/550.10';
    public const VERSION_FERNSTEUERUNG = '550.10';
    public const VERSION_AUDIO = '690.7.1';

    /**
     * Absender-Teil eines SETUP (Sitzung) plus kanaleigene Felder.
     * @param array<string, mixed> $zusatz
     */
    public static function setup(string $version, array $zusatz): array
    {
        return [
            'deviceID' => self::GERAETE_ID,
            'macAddress' => self::GERAETE_ID,
            'model' => self::MODELL,
            'name' => self::NAME,
            'osName' => self::OS_NAME,
            'osVersion' => self::OS_VERSION,
            'osBuildVersion' => self::OS_BUILD,
            'sourceVersion' => $version,
            'sessionUUID' => AirPlayZufall::uuid(),
        ] + $zusatz;
    }
}
