<?php

declare(strict_types=1);

require_once __DIR__ . '/AirPlayText.php';

// ===========================================================================
// Gemeinsame Formular-Bausteine nach der DG65-Formular-Konvention:
// „Wozu dieses Modul?" → „Neu bis Version" → „Dokumentation & Hilfe" →
// Fachpanels → Rückmeldung → „Über dieses Modul".
// Das Panel „Formular: Aufbau" (SUITE.md 3a) entfällt: höchstens zwei
// Fachpanels je Modul.
//
// Trait mit Präfix im Namen, weil Klassen- und Trait-Namen in Symcon
// prozessweit global sind. airplayPrefix() liefert den Funktionspräfix des
// Moduls, damit die Knöpfe die richtige globale Funktion aufrufen.
// ===========================================================================

trait AirPlayFormular
{
    // „Was ist neu": Schlüssel = Version ohne Beta-/Build-Suffix.
    private static $AIRPLAY_NEWS_VERSIONS = [
        '0.1.0' => [
            '• First release: announcements on HomePods via AirPlay 2 — pure PHP, without Home Assistant, Python or Docker.',
            '• AirPlay configurator: finds HomePods, Apple TVs and other AirPlay receivers on the network and shows which of them can play announcements.',
        ],
        '0.2.0' => [
            '• ▶️ New: shows what the HomePod is playing — state, title, artist, album, app, cover and volume — and controls it (play, pause, next/previous track, volume).',
            '• 📢 After an announcement, music that was playing continues and the volume is restored.',
        ],
        '0.3.0' => [
            '• 🎵 New: start Apple Music on the HomePod — paste the share link of a playlist or album, optionally shuffled (AIRP_PlayPlaylist).',
            '• The app name now shows "Apple Music" instead of the internal name "AirMusic".',
        ],
        '0.4.0' => [
            '• 🌡️ New module "AirPlay-Klima": temperature and humidity of HomePod mini / HomePod (2nd generation) as real variables — via an Apple Home automation, triggered by the HomeKit bridge of Symcon. Step-by-step guide in the form.',
        ],
        '0.4.2' => [
            '• AirPlay-Klima: no error status during setup anymore — until a HomePod has sent values, the form shows a hint instead.',
        ],
        '0.4.3' => [
            '• AirPlay-Klima: the shortcut now sends the values as JSON (method POST) — this way temperature and humidity both arrive reliably. Please adjust your shortcut as described in the form.',
        ],
        '0.5.0' => [
            '• 🗣️ New: speech output — AIRP_Say(id, text) reads text aloud on the HomePod. The voice comes from a Piper server in your own network (free, offline, no account), e.g. as a Docker container. Set it up in the panel "Speech output (Piper)".',
        ],
    ];

    private static $AIRPLAY_REPO_URL     = 'https://github.com/DG65/AirPlaySteuerung';
    private static $AIRPLAY_ISSUES_URL   = 'https://github.com/DG65/AirPlaySteuerung/issues';
    private static $AIRPLAY_LICENSE_URL  = 'https://github.com/DG65/AirPlaySteuerung/blob/beta/LICENSE';
    private static $AIRPLAY_PAYPAL_URL   = 'https://paypal.me/DietmarGureth';
    private static $AIRPLAY_LIBRARY_GUID = '{F4937700-A1EF-41E6-B9B4-9F375903FB30}';

    abstract protected function airplayPrefix(): string;

    /** Zweck-Text (2–3 Sätze) je Modul. */
    abstract protected function airplayZweck(): array;

    /** Inhalt des Doku-Panels je Modul (ohne Versionszeile). */
    abstract protected function airplayDoku(): array;

    protected function registerFormAttributes(): void
    {
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean('FeedbackHintGone', false);
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    public function AckNews(): void
    {
        // Gespeichert wird die tatsächlich INSTALLIERTE Version (SUITE.md Formular-Optik Punkt 1).
        $this->WriteAttributeString('SeenNews', $this->airplayInstallierteVersion());
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function AckFeedbackHint(): void
    {
        $this->WriteAttributeBoolean('FeedbackHintGone', true);
        $this->UpdateFormField('FeedbackHintPanel', 'visible', false);
    }

    protected function airplayBibliothek(): array
    {
        $lib = function_exists('IPS_GetLibrary') ? @IPS_GetLibrary(self::$AIRPLAY_LIBRARY_GUID) : false;
        return is_array($lib) ? $lib : [];
    }

    protected function airplayInstallierteVersion(): string
    {
        $lib = $this->airplayBibliothek();
        if (!empty($lib['Version'])) {
            return (string)preg_replace('/-.*$/', '', (string)$lib['Version']);
        }
        $keys = array_keys(self::$AIRPLAY_NEWS_VERSIONS);
        usort($keys, 'version_compare');
        return (string)end($keys);
    }

    private function formZweck(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        $items = [];
        foreach ($this->airplayZweck() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->airplayPrefix() . '_AckPurposeIntro($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true, 'caption' => '👋  What is this module for?', 'items' => $items];
    }

    private function formNeu(): ?array
    {
        $gesehen = $this->ReadAttributeString('SeenNews') ?: '0';
        $offen = [];
        foreach (self::$AIRPLAY_NEWS_VERSIONS as $version => $zeilen) {
            if (version_compare((string)$version, $gesehen, '>')) {
                $offen[(string)$version] = $zeilen;
            }
        }
        if ($offen === []) {
            return null;
        }
        uksort($offen, 'version_compare');
        $items = [];
        foreach ($offen as $version => $zeilen) {
            $items[] = ['type' => 'Label', 'caption' => AirPlayText::t('Version %s:', $version)];
            foreach ($zeilen as $zeile) {
                $items[] = ['type' => 'Label', 'caption' => $zeile];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->airplayPrefix() . '_AckNews($id);'];
        $keys = array_keys($offen);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'expanded' => true, 'caption' => AirPlayText::t('🆕  New up to version %s', end($keys)), 'items' => $items];
    }

    private function formDoku(): array
    {
        $lib = $this->airplayBibliothek();
        $ver = isset($lib['Version'])
            ? AirPlayText::t('ℹ️ AirPlay-Steuerung version %s (build %s)', $lib['Version'], $lib['Build'] ?? '?')
            : 'ℹ️ AirPlay-Steuerung';
        $items = [['type' => 'Label', 'caption' => $ver]];
        foreach ($this->airplayDoku() as $zeile) {
            $items[] = ['type' => 'Label', 'caption' => $zeile];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Manual on GitHub', 'onClick' => "echo '" . self::$AIRPLAY_REPO_URL . "';", 'link' => true];
        return ['type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📖  Documentation & help', 'items' => $items];
    }

    private function formRueckmeldung(): ?array
    {
        if ($this->ReadAttributeBoolean('FeedbackHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'FeedbackHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback',
            'items' => [
                ['type' => 'Label', 'caption' => '🧪 AirPlay-Steuerung is new. Does it work with your device? Is something missing? Please report it — with the device type and the column "Announcement" from the configurator.'],
                ['type' => 'Button', 'caption' => 'Report a bug on GitHub', 'onClick' => "echo '" . self::$AIRPLAY_ISSUES_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Got it – don\'t show again', 'onClick' => $this->airplayPrefix() . '_AckFeedbackHint($id);'],
            ],
        ];
    }

    /** Wortlaut verbundweit identisch (SUITE.md Formular-Optik Punkt 5, Variante A). */
    private function formLizenz(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  About this module',
            'items' => [
                ['type' => 'Label', 'caption' => 'Born out of real enthusiasm for my own setup — and quite a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.'],
                ['type' => 'Label', 'caption' => 'License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use requires a separate license from the rights holder.'],
                ['type' => 'Button', 'caption' => 'View license text', 'onClick' => "echo '" . self::$AIRPLAY_LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'You like the module and would like to leave something anyway? I am happy about a small donation — completely voluntary, nothing required in return.'],
                ['type' => 'Button', 'caption' => '☕  Donate via PayPal', 'onClick' => "echo '" . self::$AIRPLAY_PAYPAL_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'AirPlay, HomePod and Apple TV are trademarks of Apple Inc. This module is not affiliated with or endorsed by Apple.'],
            ],
        ];
    }

    /** Baut das komplette Formular aus Rahmen + Fachpanels. */
    protected function airplayFormular(array $fachpanels, array $aktionen, array $status): string
    {
        $elements = array_values(array_filter(array_merge(
            [$this->formZweck(), $this->formNeu(), $this->formDoku()],
            $fachpanels,
            [$this->formRueckmeldung(), $this->formLizenz()]
        )));
        // Feste Überschriften bleiben englisch, die Konsole übersetzt sie über locale.json (SUITE.md 25).
        return (string)json_encode(['elements' => $elements, 'actions' => $aktionen, 'status' => $status]);
    }
}
