<?php
/**
 * Wyoming-TTS-Attrappe für den Prüfstand: beantwortet <anzahl> Verbindungen auf 127.0.0.1:<port>.
 * Rahmen wie OHF-Voice/wyoming event.py: JSON-Zeile (type, data_length, payload_length), dann Daten, dann Nutzlast.
 *   php .tools/wyoming-attrappe.php <port> <anzahl>
 */
declare(strict_types=1);

[$port, $anzahl] = [(int)($argv[1] ?? 10299), (int)($argv[2] ?? 1)];
$server = stream_socket_server("tcp://127.0.0.1:$port", $nr, $text);
echo "bereit\n";
function ereignis($c, string $typ, array $daten = [], string $nutzlast = ''): void
{
    $kopf = ['type' => $typ, 'version' => '1.10.2'];
    $json = $daten !== [] ? json_encode($daten, JSON_UNESCAPED_UNICODE) : '';
    if ($json !== '') {
        $kopf['data_length'] = strlen($json);
    }
    if ($nutzlast !== '') {
        $kopf['payload_length'] = strlen($nutzlast);
    }
    fwrite($c, json_encode($kopf) . "\n" . $json . $nutzlast);
}
for ($i = 0; $i < $anzahl; $i++) {
    $c = stream_socket_accept($server, 10);
    $kopf = json_decode((string)fgets($c), true);
    $daten = ($kopf['data_length'] ?? 0) > 0 ? json_decode((string)fread($c, $kopf['data_length']), true) : [];
    if ($kopf['type'] === 'describe') {
        ereignis($c, 'info', ['tts' => [['name' => 'piper', 'voices' => [
            ['name' => 'de_DE-thorsten-medium', 'languages' => ['de_DE'], 'installed' => true],
            ['name' => 'en_US-nicht-da', 'languages' => ['en_US'], 'installed' => false],
        ]]]]);
    } elseif (($daten['voice']['name'] ?? '') === 'falsch') {
        ereignis($c, 'error', ['text' => 'unknown voice']);
    } else {
        // Ton-Länge = Textlänge × 100 Abtastwerte, damit der Prüfstand die Übergabe des Texts sieht
        $pcm = str_repeat(pack('v', 1000), mb_strlen($daten['text']) * 100);
        $format = ['rate' => 22050, 'width' => 2, 'channels' => 1];
        ereignis($c, 'audio-start', $format);
        foreach (str_split($pcm, 1000) as $teil) {
            ereignis($c, 'audio-chunk', $format, $teil);
        }
        ereignis($c, 'audio-stop');
    }
    fclose($c);
}
