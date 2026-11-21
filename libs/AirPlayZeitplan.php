<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Geplante Ansagen und Platzhalter, rein in PHP ohne Symcon.
//
// Ein Eintrag (Zeile der Formularliste „Geplante Ansagen"):
//   Active  bool    eingeschaltet
//   When    string  hourly (volle Stunde) | halfhourly (volle und halbe Stunde) | daily (Uhrzeit)
//   Time    string  „HH:MM", nur bei daily
//   Days    string  all | weekdays (Mo–Fr) | weekend (Sa–So)
//   From/To int     Stundenfenster 0–23 für hourly/halfhourly (über Mitternacht erlaubt, z. B. 22 → 6)
//   Text    string  Ansagetext mit Platzhaltern
//   Volume  int     0–100, -1 = Einstellung der Instanz
//   Chime   bool    Gong vor der Ansage
// Zeiten sind Unix-Sekunden in der Zeitzone des Systems; geplant wird minutengenau.
// ===========================================================================

final class AirPlayZeitplan
{
    /** So weit sucht naechste() voraus (Sekunden) — mehr als eine Woche, damit Wochenend-Einträge gefunden werden. */
    private const HORIZONT = 8 * 86400;

    /** Erlaubte Werte für „When" und „Days" (Formular und Prüfung). */
    public const WANN = ['hourly', 'halfhourly', 'daily'];
    public const TAGE = ['all', 'weekdays', 'weekend'];

    /** Passt der Eintrag zur Minute $zeit? */
    public static function passt(array $e, int $zeit): bool
    {
        if (empty($e['Active']) || trim((string)($e['Text'] ?? '')) === '') {
            return false;
        }
        $wochentag = (int)date('N', $zeit);
        $tage = (string)($e['Days'] ?? 'all');
        if (($tage === 'weekdays' && $wochentag > 5) || ($tage === 'weekend' && $wochentag < 6)) {
            return false;
        }
        $stunde = (int)date('G', $zeit);
        $minute = (int)date('i', $zeit);
        switch ((string)($e['When'] ?? '')) {
            case 'daily':
                $uhrzeit = self::uhrzeit((string)($e['Time'] ?? ''));
                return $uhrzeit !== null && $uhrzeit === [$stunde, $minute];
            case 'halfhourly':
                return ($minute === 0 || $minute === 30) && self::imFenster($e, $stunde);
            case 'hourly':
                return $minute === 0 && self::imFenster($e, $stunde);
        }
        return false;
    }

    /** Nächste Minute ab $ab (einschließlich der laufenden, falls sie genau auf :00 Sekunden steht), zu der ein Eintrag passt. */
    public static function naechste(array $eintraege, int $ab): ?int
    {
        $eintraege = array_values(array_filter($eintraege, fn ($e) => is_array($e) && !empty($e['Active'])));
        if ($eintraege === []) {
            return null;
        }
        $t = (int)(ceil($ab / 60) * 60);
        for ($ende = $t + self::HORIZONT; $t <= $ende; $t += 60) {
            foreach ($eintraege as $e) {
                if (self::passt($e, $t)) {
                    return $t;
                }
            }
        }
        return null;
    }

    /**
     * Einträge, die zur Minute $zeit fällig sind.
     * @return list<array>
     */
    public static function faellig(array $eintraege, int $zeit): array
    {
        return array_values(array_filter($eintraege, fn ($e) => is_array($e) && self::passt($e, $zeit)));
    }

    /** „7:30" / „07:30" → [7, 30]; ungültig → null. */
    public static function uhrzeit(string $text): ?array
    {
        if (preg_match('/^\s*(\d{1,2})[:.](\d{2})\s*$/', $text, $m) !== 1 || (int)$m[1] > 23 || (int)$m[2] > 59) {
            return null;
        }
        return [(int)$m[1], (int)$m[2]];
    }

    private static function imFenster(array $e, int $stunde): bool
    {
        $von = max(0, min(23, (int)($e['From'] ?? 0)));
        $bis = max(0, min(23, (int)($e['To'] ?? 23)));
        return $von <= $bis ? ($stunde >= $von && $stunde <= $bis) : ($stunde >= $von || $stunde <= $bis);
    }
}

final class AirPlayPlatzhalter
{
    /**
     * Platzhalter im Ansagetext ersetzen (deutsch oder englisch geschrieben):
     *   {uhrzeit}/{time} „14 Uhr" bzw. „14 Uhr 30", {stunde}/{hour}, {minute}, {wochentag}/{weekday},
     *   {datum}/{date} „21. November", {var:12345} formatierter Wert einer Symcon-Variable.
     * @param callable(int): ?string $variable liefert den formatierten Wert oder null, wenn es die Variable nicht gibt
     */
    public static function ersetzen(string $text, int $zeit, callable $variable): string
    {
        $stunde = (int)date('G', $zeit);
        $minute = (int)date('i', $zeit);
        $uhrzeit = $minute === 0 ? AirPlayText::t('%s o\'clock', $stunde) : AirPlayText::t('%1$s:%2$s', $stunde, sprintf('%02d', $minute));
        $tage = [
            AirPlayText::t('Monday'),
            AirPlayText::t('Tuesday'),
            AirPlayText::t('Wednesday'),
            AirPlayText::t('Thursday'),
            AirPlayText::t('Friday'),
            AirPlayText::t('Saturday'),
            AirPlayText::t('Sunday'),
        ];
        $monate = [
            AirPlayText::t('January'),
            AirPlayText::t('February'),
            AirPlayText::t('March'),
            AirPlayText::t('April'),
            AirPlayText::t('May'),
            AirPlayText::t('June'),
            AirPlayText::t('July'),
            AirPlayText::t('August'),
            AirPlayText::t('September'),
            AirPlayText::t('October'),
            AirPlayText::t('November'),
            AirPlayText::t('December'),
        ];
        $datum = AirPlayText::t('%1$s %2$s', (int)date('j', $zeit), $monate[(int)date('n', $zeit) - 1]);
        $werte = [
            'uhrzeit' => $uhrzeit, 'time' => $uhrzeit,
            'stunde' => (string)$stunde, 'hour' => (string)$stunde,
            'minute' => (string)$minute,
            'wochentag' => $tage[(int)date('N', $zeit) - 1], 'weekday' => $tage[(int)date('N', $zeit) - 1],
            'datum' => $datum, 'date' => $datum,
        ];
        return (string)preg_replace_callback('/\{\s*(var:\s*(\d+)|[a-z]+)\s*\}/i', function (array $m) use ($werte, $variable): string {
            if (isset($m[2]) && $m[2] !== '') {
                return $variable((int)$m[2]) ?? $m[0];
            }
            return $werte[strtolower($m[1])] ?? $m[0];
        }, $text);
    }
}
