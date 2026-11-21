<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';
require_once __DIR__ . '/AirPlayPiper.php';

// ===========================================================================
// Sprachdienste für die Sprachausgabe, rein in PHP ohne Symcon. Alle liefern
// eine WAV-Datei (PCM), weil PHP kein MP3 dekodiert.
//
// Belegt an den offiziellen Dokus (21.11.2026):
// - Azure: POST https://{region}.tts.speech.microsoft.com/cognitiveservices/v1, Ocp-Apim-Subscription-Key,
//   Content-Type application/ssml+xml, X-Microsoft-OutputFormat riff-24khz-16bit-mono-pcm, User-Agent Pflicht;
//   Stimmen GET …/cognitiveservices/voices/list (ShortName, Locale, Gender, LocalName).
// - ElevenLabs: POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}?output_format=wav_22050, xi-api-key,
//   JSON {text, model_id}; Stimmen GET https://api.elevenlabs.io/v2/voices?page_size=100 (voice_id, name, labels).
//   44,1 kHz braucht den Pro-Tarif, 22,05 kHz nicht.
// - Google: POST https://texttospeech.googleapis.com/v1/text:synthesize, X-Goog-Api-Key, JSON {input:{text},
//   voice:{languageCode, name}, audioConfig:{audioEncoding:LINEAR16}} → audioContent (Base64, „For LINEAR16 audio,
//   we include the WAV header"); Stimmen GET …/v1/voices?languageCode=de-DE (name, languageCodes, ssmlGender).
// ===========================================================================

interface AirPlaySprachdienst
{
    /** Text sprechen; Ergebnis ist eine WAV-Datei. */
    public function wav(string $text): string;

    /** @return list<array{name:string, info:string}> verfügbare Stimmen (name = Wert fürs Feld „Stimme") */
    public function stimmen(): array;
}

final class AirPlayStimmen
{
    public const DIENSTE = ['piper', 'azure', 'elevenlabs', 'google'];

    /**
     * Sprachdienst aus den Einstellungen bauen.
     * @param array{dienst:string, stimme:string, server?:string, region?:string, schluessel?:string, modell?:string} $e
     */
    public static function dienst(array $e): AirPlaySprachdienst
    {
        $stimme = trim((string)($e['stimme'] ?? ''));
        return match ((string)$e['dienst']) {
            'piper' => new AirPlayPiperDienst((string)($e['server'] ?? ''), $stimme),
            'azure' => new AirPlayAzure((string)($e['schluessel'] ?? ''), (string)($e['region'] ?? ''), $stimme),
            'elevenlabs' => new AirPlayElevenLabs((string)($e['schluessel'] ?? ''), $stimme, (string)($e['modell'] ?? '')),
            'google' => new AirPlayGoogle((string)($e['schluessel'] ?? ''), $stimme),
            default => throw new AirPlayFehler('unknown speech service %s', (string)$e['dienst']),
        };
    }

    /** Text vor dem Senden bereinigen und begrenzen (gilt für alle Dienste). */
    public static function text(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            throw new AirPlayFehler('the text is empty');
        }
        if (mb_strlen($text) > AirPlayPiper::MAX_ZEICHEN) {
            throw new AirPlayFehler('the text is longer than %s characters', AirPlayPiper::MAX_ZEICHEN);
        }
        return $text;
    }

    /** Schlüssel für den Zwischenspeicher: gleicher Dienst, gleiche Stimme, gleicher Text → gleiche Aufnahme. */
    public static function speicherKennung(string $dienst, string $stimme, string $text): string
    {
        return substr(hash('sha256', $dienst . "\n" . $stimme . "\n" . self::text($text)), 0, 24);
    }

    /**
     * HTTP-Anfrage ohne cURL (stream context), wie AirPlayAppleMusic.
     * @param list<string> $kopf
     * @return array{0:int, 1:string} [Status, Antworttext]
     */
    public static function http(string $methode, string $url, array $kopf, ?string $koerper = null, int $zeitlimit = 20): array
    {
        $kopf[] = 'User-Agent: Symcon-AirPlay-Steuerung';
        $optionen = ['method' => $methode, 'timeout' => $zeitlimit, 'ignore_errors' => true, 'header' => implode("\r\n", $kopf) . "\r\n"];
        if ($koerper !== null) {
            $optionen['content'] = $koerper;
        }
        $antwort = @file_get_contents($url, false, stream_context_create(['http' => $optionen]));
        $zeilen = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = isset($zeilen[0]) && preg_match('/\s(\d{3})(\s|$)/', (string)$zeilen[0], $m) === 1 ? (int)$m[1] : 0;
        if ($antwort === false && $status === 0) {
            throw new AirPlayFehler('%s not reachable', (string)parse_url($url, PHP_URL_HOST));
        }
        return [$status, (string)$antwort];
    }

    /** Einheitliche Fehlermeldung für HTTP-Antworten der Dienste. */
    public static function httpFehler(string $dienst, int $status, string $antwort): AirPlayFehler
    {
        $detail = '';
        $json = json_decode($antwort, true);
        if (is_array($json)) {
            $detail = (string)($json['error']['message'] ?? $json['detail']['message'] ?? (is_string($json['detail'] ?? null) ? $json['detail'] : '') ?? '');
        }
        if ($detail === '') {
            $detail = mb_substr(trim(strip_tags($antwort)), 0, 160);
        }
        return match (true) {
            ($status === 401 || $status === 403) && $dienst === 'Azure' => new AirPlayFehler('%s rejects the key (HTTP %s) — check key and region', $dienst, $status),
            $status === 401 || $status === 403 => new AirPlayFehler('%s rejects the key (HTTP %s)', $dienst, $status),
            $status === 429 => new AirPlayFehler('%s: quota or request limit reached (HTTP 429)', $dienst),
            default => new AirPlayFehler('%s answers HTTP %s: %s', $dienst, $status, $detail !== '' ? $detail : '–'),
        };
    }

    /** Prüft grob, ob eine Antwort eine WAV-Datei ist. */
    public static function istWav(string $daten): bool
    {
        return strlen($daten) > 44 && str_starts_with($daten, 'RIFF') && substr($daten, 8, 4) === 'WAVE';
    }
}

final class AirPlayPiperDienst implements AirPlaySprachdienst
{
    public function __construct(private string $server, private string $stimme)
    {
    }

    public function wav(string $text): string
    {
        return (new AirPlayPiper($this->server))->wav($text, $this->stimme);
    }

    public function stimmen(): array
    {
        return array_map(fn (array $s): array => ['name' => $s['name'], 'info' => $s['sprachen']], (new AirPlayPiper($this->server, 10.0))->stimmen());
    }
}

final class AirPlayAzure implements AirPlaySprachdienst
{
    public const STANDARD_STIMME = 'de-DE-KatjaNeural';

    public function __construct(private string $schluessel, private string $region, private string $stimme)
    {
        if (trim($schluessel) === '') {
            throw new AirPlayFehler('no key saved for %s', 'Azure');
        }
        if (preg_match('/^[a-z0-9]+$/', $this->region = strtolower(trim($region))) !== 1) {
            throw new AirPlayFehler('Azure region missing or invalid (e.g. germanywestcentral, westeurope)');
        }
    }

    public function wav(string $text): string
    {
        $stimme = $this->stimme !== '' ? $this->stimme : self::STANDARD_STIMME;
        $sprache = preg_match('/^([a-z]{2,3}-[A-Z]{2})-/', $stimme, $m) === 1 ? $m[1] : 'de-DE';
        $ssml = sprintf(
            "<speak version='1.0' xml:lang='%s'><voice name='%s'>%s</voice></speak>",
            $sprache,
            htmlspecialchars($stimme, ENT_QUOTES | ENT_XML1),
            htmlspecialchars(AirPlayStimmen::text($text), ENT_QUOTES | ENT_XML1)
        );
        [$status, $antwort] = AirPlayStimmen::http('POST', "https://{$this->region}.tts.speech.microsoft.com/cognitiveservices/v1", ['Ocp-Apim-Subscription-Key: ' . trim($this->schluessel), 'Content-Type: application/ssml+xml', 'X-Microsoft-OutputFormat: riff-24khz-16bit-mono-pcm'], $ssml);
        if ($status !== 200 || !AirPlayStimmen::istWav($antwort)) {
            throw AirPlayStimmen::httpFehler('Azure', $status, $antwort);
        }
        return $antwort;
    }

    public function stimmen(): array
    {
        [$status, $antwort] = AirPlayStimmen::http('GET', "https://{$this->region}.tts.speech.microsoft.com/cognitiveservices/voices/list", ['Ocp-Apim-Subscription-Key: ' . trim($this->schluessel)]);
        $liste = json_decode($antwort, true);
        if ($status !== 200 || !is_array($liste)) {
            throw AirPlayStimmen::httpFehler('Azure', $status, $antwort);
        }
        $out = [];
        foreach ($liste as $v) {
            if (is_array($v) && str_starts_with((string)($v['Locale'] ?? ''), 'de-') && isset($v['ShortName'])) {
                $out[] = ['name' => (string)$v['ShortName'], 'info' => trim(($v['LocalName'] ?? '') . ', ' . ($v['Gender'] ?? '') . ', ' . ($v['Locale'] ?? ''), ', ')];
            }
        }
        return $out !== [] ? $out : array_values(array_map(fn ($v) => ['name' => (string)($v['ShortName'] ?? ''), 'info' => (string)($v['Locale'] ?? '')], array_filter($liste, 'is_array')));
    }
}

final class AirPlayElevenLabs implements AirPlaySprachdienst
{
    /** Flash v2.5: Deutsch, halber Zeichenpreis gegenüber Multilingual v2 — schont das Freikontingent. */
    public const STANDARD_MODELL = 'eleven_flash_v2_5';

    public function __construct(private string $schluessel, private string $stimme, private string $modell)
    {
        if (trim($schluessel) === '') {
            throw new AirPlayFehler('no key saved for %s', 'ElevenLabs');
        }
    }

    public function wav(string $text): string
    {
        if (preg_match('/^[A-Za-z0-9]{10,40}$/', $this->stimme) !== 1) {
            throw new AirPlayFehler('ElevenLabs needs a voice ID — click "Show voices" and copy one into the field "Voice"');
        }
        $koerper = (string)json_encode(['text' => AirPlayStimmen::text($text), 'model_id' => trim($this->modell) !== '' ? trim($this->modell) : self::STANDARD_MODELL], JSON_UNESCAPED_UNICODE);
        [$status, $antwort] = AirPlayStimmen::http('POST', 'https://api.elevenlabs.io/v1/text-to-speech/' . $this->stimme . '?output_format=wav_22050', ['xi-api-key: ' . trim($this->schluessel), 'Content-Type: application/json', 'Accept: audio/wav'], $koerper, 30);
        if ($status !== 200 || !AirPlayStimmen::istWav($antwort)) {
            throw AirPlayStimmen::httpFehler('ElevenLabs', $status, $antwort);
        }
        return $antwort;
    }

    public function stimmen(): array
    {
        [$status, $antwort] = AirPlayStimmen::http('GET', 'https://api.elevenlabs.io/v2/voices?page_size=100', ['xi-api-key: ' . trim($this->schluessel)]);
        $json = json_decode($antwort, true);
        if ($status !== 200 || !is_array($json['voices'] ?? null)) {
            throw AirPlayStimmen::httpFehler('ElevenLabs', $status, $antwort);
        }
        $out = [];
        foreach ($json['voices'] as $v) {
            if (is_array($v) && isset($v['voice_id'])) {
                $merkmale = array_filter([(string)($v['name'] ?? ''), (string)($v['labels']['gender'] ?? ''), (string)($v['labels']['accent'] ?? '')]);
                $out[] = ['name' => (string)$v['voice_id'], 'info' => implode(', ', $merkmale)];
            }
        }
        return $out;
    }
}

final class AirPlayGoogle implements AirPlaySprachdienst
{
    public function __construct(private string $schluessel, private string $stimme)
    {
        if (trim($schluessel) === '') {
            throw new AirPlayFehler('no key saved for %s', 'Google');
        }
    }

    public function wav(string $text): string
    {
        // Leere Stimme: Google wählt eine deutsche Standardstimme; sonst Sprache aus dem Stimmnamen (de-DE-…).
        $sprache = preg_match('/^([a-z]{2,3}-[A-Z]{2})-/', $this->stimme, $m) === 1 ? $m[1] : 'de-DE';
        $stimme = ['languageCode' => $sprache] + ($this->stimme !== '' ? ['name' => $this->stimme] : []);
        $koerper = (string)json_encode(['input' => ['text' => AirPlayStimmen::text($text)], 'voice' => $stimme, 'audioConfig' => ['audioEncoding' => 'LINEAR16']], JSON_UNESCAPED_UNICODE);
        [$status, $antwort] = AirPlayStimmen::http('POST', 'https://texttospeech.googleapis.com/v1/text:synthesize', ['X-Goog-Api-Key: ' . trim($this->schluessel), 'Content-Type: application/json'], $koerper);
        $json = json_decode($antwort, true);
        $wav = $status === 200 && is_array($json) ? base64_decode((string)($json['audioContent'] ?? ''), true) : false;
        if ($wav === false || !AirPlayStimmen::istWav($wav)) {
            throw AirPlayStimmen::httpFehler('Google', $status, $antwort);
        }
        return $wav;
    }

    public function stimmen(): array
    {
        [$status, $antwort] = AirPlayStimmen::http('GET', 'https://texttospeech.googleapis.com/v1/voices?languageCode=de-DE', ['X-Goog-Api-Key: ' . trim($this->schluessel)]);
        $json = json_decode($antwort, true);
        if ($status !== 200 || !is_array($json['voices'] ?? null)) {
            throw AirPlayStimmen::httpFehler('Google', $status, $antwort);
        }
        $out = [];
        foreach ($json['voices'] as $v) {
            if (is_array($v) && isset($v['name'])) {
                $out[] = ['name' => (string)$v['name'], 'info' => trim((string)($v['ssmlGender'] ?? '') . ', ' . implode('/', (array)($v['languageCodes'] ?? [])), ', ')];
            }
        }
        return $out;
    }
}
