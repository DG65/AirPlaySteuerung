<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayZeitplan.php';
require_once __DIR__ . '/AirPlayLautstaerke.php';

// ===========================================================================
// AirPlay-Gerät: geplante Ansagen und Wecker. Beide Listen teilen sich den
// Timer „Schedule", der genau zur nächsten fälligen Minute weckt (Attribut
// ScheduleNext). Ein Wecker startet die Musik leise und hebt die Lautstärke
// über den Timer „Ramp" in Schritten an (Zustand im Attribut Ramp); danach
// folgt auf Wunsch ein Gruß als Durchsage.
// ===========================================================================

trait AirPlayGeraetZeitplan
{
    /** Abstand der Lautstärke-Schritte beim Wecker (Sekunden) — je Schritt eine kurze Sitzung am Gerät. */
    private const RAMPE_TAKT = 20;
    /** Ältere Termine (Symcon war beschäftigt oder neu gestartet) werden übersprungen statt nachgeholt. */
    private const VERSPAETUNG_MAX = 600;

    /** Timer „Schedule" auf den nächsten fälligen Termin ab $ab stellen; null = aus. */
    private function zeitplanStellen(?int $ab): void
    {
        $naechste = $ab !== null ? AirPlayZeitplan::naechste($this->termine(), $ab) : null;
        $this->WriteAttributeInteger('ScheduleNext', $naechste ?? 0);
        $this->SetTimerInterval('Schedule', $naechste !== null ? max(1, $naechste - time()) * 1000 : 0);
    }

    /** @return list<array> gültige Einträge beider Listen mit ihrer Art (ansage | wecker) */
    private function termine(): array
    {
        return AirPlayZeitplan::eintraege($this->liste('Schedule'), $this->liste('Alarms'));
    }

    /** Fällige Ansagen und Wecker ausführen und den nächsten Termin stellen (Timer „Schedule"). */
    private function zeitplanAusfuehren(): void
    {
        $geplant = $this->ReadAttributeInteger('ScheduleNext');
        if ($geplant <= 0 || time() < $geplant - 5) {
            $this->zeitplanStellen(time() + 1);   // zu früh oder ohne Plan geweckt: neu planen
            return;
        }
        $this->SetTimerInterval('Schedule', 0);
        foreach (AirPlayZeitplan::faellig($this->termine(), $geplant) as $e) {
            if (time() - $geplant > self::VERSPAETUNG_MAX) {
                $this->LogMessage(AirPlayText::t('Scheduled announcement skipped: more than 10 minutes late.'), KL_WARNING);
                break;
            }
            if ($e['Art'] === 'wecker') {
                $this->weckerStarten($e);
            } else {
                $this->sprechen((string)$e['Text'], (int)($e['Volume'] ?? -1), self::klangVon($e), 'schedule', $geplant);
            }
        }
        $this->zeitplanStellen(max(time() + 1, $geplant + 60));
    }

    /** Klang vor einer geplanten Ansage: Spalte „Sound"; ältere Listen (0.6.0) hatten nur „Chime" = Gong. */
    private static function klangVon(array $e): string
    {
        $klang = (string)($e['Sound'] ?? (!empty($e['Chime']) ? 'gong' : ''));
        return isset(AirPlayAudio::KLAENGE[$klang]) ? $klang : '';
    }

    // ------------------------------------------------------------------ Wecker

    private function weckerStarten(array $e): void
    {
        $start = max(0, min(100, (int)($e['StartVolume'] ?? 5)));
        $ziel = max(0, min(100, (int)($e['EndVolume'] ?? 30)));
        if (!$this->SetVolume($start) || !$this->PlayPlaylist((string)$e['Link'], !empty($e['Shuffle']))) {
            $this->protokollieren('alarm', (string)$e['Link'], false, $this->letzterFehler());
            return;
        }
        $this->protokollieren('alarm', AirPlayText::t('Alarm clock %1$s: music at %2$s %%, rising to %3$s %%', (string)$e['Time'], $start, $ziel), true, '');
        $this->WriteAttributeString('Ramp', (string)json_encode([
            'start' => $start, 'ziel' => $ziel, 'beginn' => time(), 'dauer' => max(0, (int)($e['Minutes'] ?? 10)) * 60,
            'text' => trim((string)($e['Text'] ?? '')),
        ]));
        $this->SetTimerInterval('Ramp', self::RAMPE_TAKT * 1000);
    }

    /** Nächster Schritt des Lautstärke-Anstiegs (Timer „Ramp"); endet mit dem Gruß oder wenn die Musik angehalten wurde. */
    private function rampeWeiter(): void
    {
        $r = json_decode($this->ReadAttributeString('Ramp'), true);
        if (!is_array($r)) {
            $this->rampeBeenden();
            return;
        }
        // Hat jemand die Musik angehalten, ist der Wecker erledigt — nicht wieder lauter drehen.
        if ($this->fernsteuerungAn() && $this->wert('Zustand') !== null && $this->wert('Zustand') !== self::ZUSTAND_SPIELT) {
            $this->rampeBeenden();
            return;
        }
        $verstrichen = time() - (int)$r['beginn'];
        $this->SetVolume((int)AirPlayLautstaerke::rampe((float)$r['start'], (float)$r['ziel'], (int)$r['dauer'], $verstrichen));
        if ($verstrichen >= (int)$r['dauer']) {
            $this->rampeBeenden();
            if ($r['text'] !== '') {
                $this->sprechen($r['text'], (int)$r['ziel'], 'gong', 'alarm');
            }
        }
    }

    private function rampeBeenden(): void
    {
        $this->SetTimerInterval('Ramp', 0);
        $this->WriteAttributeString('Ramp', '');
    }
}
