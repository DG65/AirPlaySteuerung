<?php

declare(strict_types=1);

// ===========================================================================
// Unter welcher Adresse erreichen iPhone und Steuerzentrale Symcon? Aus den
// Netzwerkschnittstellen (Sys_GetNetworkInfo) die beständigste wählen:
// Kabel vor WLAN — WLAN-Verbindungen eines Servers reißen eher ab (live
// 22.11.2026: LAN .7 + WLAN .8, die WLAN-Adresse verschwand, der Kurzbefehl
// lief ins Leere). Virtuelle Netze (Docker, libvirt, VPN) kommen nie in Frage.
// ===========================================================================

final class AirPlayNetz
{
    /** Schnittstellen, die nie ins Heimnetz zeigen (Name, Kleinschreibung). */
    private const VIRTUELL = '/^(lo|docker|br-|veth|virbr|vmnet|vboxnet|tun|tap|wg|zt|tailscale|utun|vethernet)/';
    private const WLAN = '/^(wl|wlan|wifi|wi-fi|wireless|ath|ra\d)|wi-?fi|wlan|wireless/';
    /** Standardnetze von libvirt und Docker-Brücken — ohne erkennbaren Namen trotzdem auslassen. */
    private const VIRTUELLE_NETZE = ['192.168.122.', '172.17.'];

    /**
     * Beste Adresse für Aufrufe aus dem Heimnetz, null wenn keine taugt.
     * @param list<array{IP?:string, Description?:string}> $schnittstellen Ausgabe von Sys_GetNetworkInfo()
     */
    public static function symconAdresse(array $schnittstellen): ?string
    {
        $beste = null;
        $besteRang = PHP_INT_MAX;
        foreach ($schnittstellen as $s) {
            $ip = (string)($s['IP'] ?? '');
            $rang = self::rang($ip, mb_strtolower(trim((string)($s['Description'] ?? ''))));
            if ($rang !== null && $rang < $besteRang) {
                [$beste, $besteRang] = [$ip, $rang];
            }
        }
        return $beste;
    }

    /** 0 = Kabel/Brücke ins Heimnetz, 1 = WLAN, null = ungeeignet. */
    private static function rang(string $ip, string $name): ?int
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            return null;
        }
        if (preg_match(self::VIRTUELL, $name) === 1) {
            return null;
        }
        foreach (self::VIRTUELLE_NETZE as $netz) {
            if (str_starts_with($ip, $netz)) {
                return null;
            }
        }
        return preg_match(self::WLAN, $name) === 1 ? 1 : 0;
    }
}
