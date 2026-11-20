<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlaySitzung.php';

// ===========================================================================
// Abläufe, wie das Modul sie braucht — je Aufruf eine eigene, kurze Sitzung
// (Verbindung, Kopplung, Kanal, schließen). Rein in PHP ohne Symcon.
//
// Für die Anzeige bleibt keine Verbindung offen: das Modul fragt per Timer ab
// (Dietmars Entscheidung 20.11.2026). Eine Abfrage dauert etwa 1,5–3 s.
// ===========================================================================

final class AirPlayAblauf
{
    /** Wartezeit, bis das Gerät nach dem Verbinden Zustand und Titel geschickt hat. */
    private const LESEZEIT = 1.5;
    private const COVERZEIT = 3.0;

    /** @param callable $log fn(string): void */
    public function __construct(private string $host, private $log)
    {
    }

    /**
     * Wiedergabezustand lesen; Cover nur anfordern, wenn $coverSchluessel nicht zum aktuellen Titel passt.
     * @return array AirPlayMrp::wiedergabe() + ['coverSchluessel' => string]
     */
    public function zustand(string $coverSchluessel = ''): array
    {
        return $this->mitFernsteuerung(function (AirPlayFernsteuerung $f) use ($coverSchluessel): array {
            $f->warten(self::LESEZEIT);
            $w = $f->mrp->wiedergabe();
            $schluessel = self::coverSchluessel($w);
            if ($schluessel !== '' && $schluessel !== $coverSchluessel && $w['cover'] === '') {
                $f->coverAnfordern();
                $ende = microtime(true) + self::COVERZEIT;
                do {
                    $f->warten(0.25);
                    $w = $f->mrp->wiedergabe();
                } while ($w['cover'] === '' && microtime(true) < $ende);
            }
            return $w + ['coverSchluessel' => $schluessel];
        });
    }

    /** Schlüssel, an dem ein Titelwechsel erkannt wird. */
    public static function coverSchluessel(array $w): string
    {
        return $w['titel'] === '' ? '' : md5($w['titel'] . '|' . $w['interpret'] . '|' . $w['album']);
    }

    /** Befehl senden (play, pause, next, previous …) und den neuen Zustand liefern. */
    public function befehl(string $name): array
    {
        return $this->mitFernsteuerung(function (AirPlayFernsteuerung $f) use ($name): array {
            $f->warten(0.5);
            $f->befehl($name);
            $f->warten(1.0);
            return $f->mrp->wiedergabe();
        });
    }

    /** Lautstärke 0–100 % setzen; liefert die vom Gerät bestätigte Lautstärke. */
    public function lautstaerke(float $prozent): ?float
    {
        return $this->mitFernsteuerung(function (AirPlayFernsteuerung $f) use ($prozent): ?float {
            $f->warten(0.5);
            $f->lautstaerke($prozent);
            return $f->mrp->lautstaerke;
        });
    }

    /**
     * Durchsage abspielen. Mit $fortsetzen werden vorher Zustand und Lautstärke gemerkt und danach
     * wiederhergestellt (Play nur, wenn vorher etwas spielte; die Durchsage pausiert die Wiedergabe).
     * @param float|null $lautstaerke 0–100 %, null = Lautstärke des Geräts nicht ändern
     * @return array{verspaetet:int, fortgesetzt:bool, lautstaerkeZurueck:bool}
     */
    public function durchsage(string $pcm, ?float $lautstaerke, bool $fortsetzen): array
    {
        $vorher = null;
        if ($fortsetzen) {
            try {
                $vorher = $this->mitFernsteuerung(function (AirPlayFernsteuerung $f): array {
                    $f->warten(self::LESEZEIT);
                    return $f->mrp->wiedergabe();
                });
            } catch (Throwable $e) {
                ($this->log)('Zustand vor der Durchsage nicht lesbar: ' . $e->getMessage());
            }
        }

        $sitzung = new AirPlaySitzung($this->host, $this->log);
        try {
            $sitzung->koppeln();
            $sitzung->audioEinrichten();
            $ergebnis = $sitzung->abspielen($pcm, $lautstaerke);
        } finally {
            $sitzung->schliessen();
        }

        $fortgesetzt = false;
        $lautZurueck = false;
        if ($vorher !== null) {
            $wiederSpielen = $vorher['zustand'] === 'playing';
            $alteLautstaerke = $lautstaerke !== null ? $vorher['lautstaerke'] : null;
            if ($wiederSpielen || $alteLautstaerke !== null) {
                $this->mitFernsteuerung(function (AirPlayFernsteuerung $f) use ($wiederSpielen, $alteLautstaerke, &$fortgesetzt, &$lautZurueck): void {
                    $f->warten(0.5);
                    if ($alteLautstaerke !== null) {
                        $f->lautstaerke((float)$alteLautstaerke);
                        $lautZurueck = true;
                    }
                    if ($wiederSpielen) {
                        $f->befehl('play');
                        $fortgesetzt = true;
                    }
                });
            }
        }
        return ['verspaetet' => $ergebnis['verspaetet'], 'fortgesetzt' => $fortgesetzt, 'lautstaerkeZurueck' => $lautZurueck];
    }

    /** Nur koppeln und Geräteinfo lesen. */
    public function info(): array
    {
        $sitzung = new AirPlaySitzung($this->host, $this->log);
        try {
            $sitzung->koppeln();
            return $sitzung->info();
        } finally {
            $sitzung->schliessen();
        }
    }

    private function mitFernsteuerung(callable $arbeit): mixed
    {
        $sitzung = new AirPlaySitzung($this->host, $this->log);
        try {
            $sitzung->koppeln();
            return $arbeit($sitzung->fernsteuerung());
        } finally {
            $sitzung->schliessen();
        }
    }
}
