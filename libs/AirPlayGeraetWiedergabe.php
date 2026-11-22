<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayAblauf.php';
require_once __DIR__ . '/AirPlayAppleMusic.php';

// ===========================================================================
// AirPlay-Gerät: Anzeige und Steuerung der Wiedergabe über MRP, Apple Music
// (Teilen-Link, Song-IDs oder Favorit) und die Variablen der Instanz.
// ===========================================================================

trait AirPlayGeraetWiedergabe
{
    // Werte der Variable „Zustand"
    private const ZUSTAND = ['unknown' => 0, 'playing' => 1, 'paused' => 2, 'stopped' => 3, 'interrupted' => 2, 'seeking' => 1];
    private const ZUSTAND_SPIELT = 1;
    // Werte der Variable „Steuerung" → MRP-Befehl
    private const STEUERUNG = [0 => 'previous', 1 => 'play', 2 => 'pause', 3 => 'next'];
    private const COVER_LEER = '#leer';
    private const BEFEHL_TEXT = ['play' => 'Play', 'pause' => 'Pause', 'stop' => 'Stop', 'next' => 'Next track', 'previous' => 'Previous track'];

    // ------------------------------------------------------------------ öffentlich

    /** Zustand, Titel, Lautstärke und Cover abfragen und in die Variablen schreiben (Timer „Update"). */
    public function Update(): bool
    {
        if (!$this->fernsteuerungAn() || $this->host() === '') {
            return false;
        }
        return $this->mitSperre(function (): bool {
            try {
                $this->wiedergabeSchreiben($this->ablauf()->zustand(self::coverTitel($this->ReadAttributeString('CoverKey'))));
                $this->erreichbar(true);
                return true;
            } catch (Throwable $e) {
                $this->erreichbar(false, AirPlayText::fehler($e));   // bei ausgeschaltetem Gerät kein Log-Rauschen
                return false;
            }
        }, '%s', 0);   // läuft gerade eine Durchsage oder ein Befehl, fällt diese Abfrage aus
    }

    public function Play(): bool
    {
        return $this->befehl('play');
    }

    public function Pause(): bool
    {
        return $this->befehl('pause');
    }

    public function Stop(): bool
    {
        return $this->befehl('stop');
    }

    public function Next(): bool
    {
        return $this->befehl('next');
    }

    public function Previous(): bool
    {
        return $this->befehl('previous');
    }

    /** Lautstärke 0–100 % am Gerät setzen. */
    public function SetVolume(int $Volume): bool
    {
        $Volume = max(0, min(100, $Volume));
        return $this->mitSperre(function () use ($Volume): bool {
            $ist = $this->ablauf()->lautstaerke((float)$Volume);
            if ($ist !== null) {
                $this->wertSetzen('Lautstaerke', (int)round($ist));
            }
            $this->ergebnis(AirPlayText::t('✅ Volume set to %s %%.', $Volume));
            $this->kachelAktualisieren();
            return true;
        }, 'Volume could not be set: %s');
    }

    /**
     * Apple Music starten: Teilen-Link einer Playlist oder eines Albums (music.apple.com), Song-IDs (durch Komma
     * getrennt) oder der Name eines Favoriten. Gespielt wird eine Kopie der Titelliste (höchstens 300 Titel).
     */
    public function PlayPlaylist(string $Link, bool $Shuffle): bool
    {
        return $this->mitSperre(function () use ($Link, $Shuffle): bool {
            $ids = AirPlayAppleMusic::songIds($this->favoritLink($Link));
            $w = $this->ablauf()->titelliste($ids, $Shuffle);
            $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
            $this->ergebnis(AirPlayText::t('✅ Apple Music started with %s songs: %s', min(count($ids), AirPlayAppleMusic::MAX_TITEL), trim($w['interpret'] . ' – ' . $w['titel'], ' –')));
            $this->kachelAktualisieren();
            return true;
        }, 'Playlist could not be started: %s');
    }

    // ------------------------------------------------------------------ Formular-Knöpfe

    public function UIPlaylist(string $Link, bool $Shuffle): void
    {
        $this->PlayPlaylist($Link, $Shuffle);
        $this->rueckmeldung();
    }

    public function UIUpdate(): void
    {
        echo $this->Update()
            ? AirPlayText::t('✅ Updated: %s', $this->zusammenfassung())
            : AirPlayText::t('❌ Update failed — see the message log.');
    }

    // ------------------------------------------------------------------ Befehle und Favoriten

    private function steuern(int $wert): void
    {
        if (isset(self::STEUERUNG[$wert])) {
            $this->befehl(self::STEUERUNG[$wert]);
        }
    }

    private function befehl(string $befehl): bool
    {
        return $this->mitSperre(function () use ($befehl): bool {
            $w = $this->ablauf()->befehl($befehl);
            $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
            $this->ergebnis(AirPlayText::t('✅ Command "%s" sent.', AirPlayText::t(self::BEFEHL_TEXT[$befehl] ?? $befehl)));
            $this->kachelAktualisieren();
            return true;
        }, 'Command failed: %s');
    }

    private function favoritSpielen(int $index): void
    {
        $f = $this->liste('Favorites')[$index] ?? null;
        if ($f !== null) {
            $this->PlayPlaylist((string)($f['Link'] ?? ''), !empty($f['Shuffle']));
        }
    }

    /** Favoriten-Name → Link; alles andere (Link, Song-IDs) unverändert. */
    private function favoritLink(string $eingabe): string
    {
        foreach ($this->liste('Favorites') as $f) {
            if (strcasecmp(trim((string)($f['Name'] ?? '')), trim($eingabe)) === 0 && trim((string)($f['Link'] ?? '')) !== '') {
                return (string)$f['Link'];
            }
        }
        return $eingabe;
    }

    // ------------------------------------------------------------------ Variablen

    /**
     * Variablen nur anlegen, wenn sie fehlen: Darstellung und Position gehören danach dem Nutzer
     * (Store-Review, SUITE.md 5/9m/28). Nie löschen.
     */
    private function variablenSicherstellen(): void
    {
        $t = fn (string $s) => AirPlayText::t($s);
        $anzeige = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $liste = [
            ['Zustand', $t('State'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS' => self::optionen([0 => $t('Unknown'), 1 => $t('Playing'), 2 => $t('Paused'), 3 => $t('Stopped')])], false],
            ['Steuerung', $t('Control'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
                'OPTIONS' => self::optionen([0 => '⏮', 1 => '▶', 2 => '⏸', 3 => '⏭'])], true],
            ['Lautstaerke', $t('Volume'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
                'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %'], true],
            ['Titel', $t('Title'), VARIABLETYPE_STRING, $anzeige, false],
            ['Interpret', $t('Artist'), VARIABLETYPE_STRING, $anzeige, false],
            ['Album', $t('Album'), VARIABLETYPE_STRING, $anzeige, false],
            ['App', $t('App'), VARIABLETYPE_STRING, $anzeige, false],
        ];
        foreach ($liste as $pos => [$ident, $name, $typ, $darstellung, $aktion]) {
            if (@$this->GetIDForIdent($ident) === false) {
                $this->MaintainVariable($ident, $name, $typ, $darstellung, ($pos + 1) * 10, true);
            }
            if ($aktion) {
                $this->EnableAction($ident);
            }
        }
        if (@IPS_GetObjectIDByIdent('Cover', $this->InstanceID) === false) {
            $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($id, $this->InstanceID);
            IPS_SetIdent($id, 'Cover');
            IPS_SetName($id, $t('Cover'));
            IPS_SetPosition($id, 80);
            IPS_SetMediaCached($id, true);
            IPS_SetMediaFile($id, 'AirPlayCover_' . $this->InstanceID . '.jpg', false);
        }
    }

    private static function optionen(array $werte): string
    {
        $o = [];
        foreach ($werte as $wert => $text) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        return (string)json_encode($o);
    }

    private function wert(string $ident): mixed
    {
        $id = @$this->GetIDForIdent($ident);
        return $id !== false ? GetValue($id) : null;
    }

    private function wertSetzen(string $ident, mixed $wert): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id !== false && GetValue($id) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    private function wiedergabeSchreiben(array $w): void
    {
        $this->wertSetzen('Zustand', self::ZUSTAND[$w['zustand']] ?? 0);
        $this->wertSetzen('Titel', $w['titel']);
        $this->wertSetzen('Interpret', $w['interpret']);
        $this->wertSetzen('Album', $w['album']);
        $this->wertSetzen('App', $w['app']);
        if ($w['lautstaerke'] !== null) {
            $this->wertSetzen('Lautstaerke', (int)round($w['lautstaerke']));
        }
        // CoverKey = Titel-Schlüssel, mit Zusatz „#leer", solange zum Titel kein Bild kam: so wird ein Titel ohne
        // Cover (Radio, Podcast) nicht bei jeder Abfrage erneut angefragt, ein später gemeldetes Bild aber übernommen.
        $gespeichert = $this->ReadAttributeString('CoverKey');
        $schluessel = (string)$w['coverSchluessel'];
        $neuesCover = $schluessel !== self::coverTitel($gespeichert) || (str_ends_with($gespeichert, self::COVER_LEER) && $w['cover'] !== '');
        if ($neuesCover) {
            $cover = @IPS_GetObjectIDByIdent('Cover', $this->InstanceID);
            if ($cover !== false) {
                IPS_SetMediaContent($cover, base64_encode($w['cover']));
            }
            $this->WriteAttributeString('CoverKey', $schluessel . ($w['cover'] === '' ? self::COVER_LEER : ''));
        }
        $this->kachelAktualisieren($neuesCover);
    }

    private static function coverTitel(string $coverKey): string
    {
        return str_ends_with($coverKey, self::COVER_LEER) ? substr($coverKey, 0, -strlen(self::COVER_LEER)) : $coverKey;
    }

    /** Erreichbarkeit merken; Status und Meldung nur beim Wechsel (kein Log-Rauschen bei ausgeschaltetem Gerät). */
    private function erreichbar(bool $ja, string $grund = ''): void
    {
        if ($ja === $this->ReadAttributeBoolean('Reachable')) {
            return;
        }
        $this->WriteAttributeBoolean('Reachable', $ja);
        $this->SetStatus($ja ? IS_ACTIVE : self::STATUS_NICHT_ERREICHBAR);
        $this->LogMessage($ja ? AirPlayText::t('Device reachable again.') : AirPlayText::t('Device not reachable: %s', $grund), $ja ? KL_MESSAGE : KL_WARNING);
        $this->kachelAktualisieren();
    }

    private function zusammenfassung(): string
    {
        $text = trim($this->wert('Interpret') . ' – ' . $this->wert('Titel'), ' –');
        return $text !== '' ? $text : AirPlayText::t('nothing playing');
    }
}
