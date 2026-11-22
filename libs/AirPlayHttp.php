<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// HTTP(S)-Anfragen ohne cURL (Stream-Kontext) für Apple Music und die
// Online-Sprachdienste. Liefert Status und Antworttext; die Auswertung bleibt
// beim Aufrufer, weil jeder Dienst Fehler anders meldet.
// ===========================================================================

final class AirPlayHttp
{
    private const KENNUNG = 'Symcon-AirPlay-Steuerung';

    /**
     * @param list<string> $kopf zusätzliche Kopfzeilen („Name: Wert")
     * @return array{0:int, 1:string} [Status (0 = keine Antwort), Antworttext]
     */
    public static function anfrage(string $methode, string $url, array $kopf = [], ?string $koerper = null, int $zeitlimit = 20): array
    {
        $kopf[] = 'User-Agent: ' . self::KENNUNG;
        $optionen = ['method' => $methode, 'timeout' => $zeitlimit, 'ignore_errors' => true, 'header' => implode("\r\n", $kopf) . "\r\n"];
        if ($koerper !== null) {
            $optionen['content'] = $koerper;
        }
        $antwort = @file_get_contents($url, false, stream_context_create(['http' => $optionen]));
        // ab PHP 8.4 Funktion; $http_response_header ist ab 8.5 veraltet
        $zeilen = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($zeilen[0]) && preg_match('/\s(\d{3})(\s|$)/', (string)$zeilen[0], $m) === 1 ? (int)$m[1] : 0;
        if ($antwort === false && $status === 0) {
            throw new AirPlayFehler('%s not reachable', (string)parse_url($url, PHP_URL_HOST));
        }
        return [$status, (string)$antwort];
    }
}
