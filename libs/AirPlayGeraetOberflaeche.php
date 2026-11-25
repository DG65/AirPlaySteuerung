<?php

declare(strict_types=1);

// ===========================================================================
// AirPlay-Gerät: Konfigurationsformular und Kachel (HTML-SDK, module.html).
// Formulartexte bleiben englisch und werden von der Konsole über locale.json
// übersetzt (SUITE.md 25); selbst übersetzt wird nur, was die Konsole nicht
// sieht: Listenzellen, Labels mit eingesetzten Werten, die Kachel.
// ===========================================================================

trait AirPlayGeraetOberflaeche
{
    // ------------------------------------------------------------------ Kachel

    public function GetVisualizationTile()
    {
        return file_get_contents(dirname(__DIR__) . '/AirPlayGeraet/module.html')
            . '<script>handleMessage(' . json_encode($this->kachelDaten(true, true)) . ');</script>';
    }

    /**
     * Kachel auf den neuesten Stand bringen — nur, wenn sich ihr Inhalt geändert hat (die Abfrage läuft alle
     * paar Sekunden); das Cover nur bei Titelwechsel (große Nachricht).
     */
    private function kachelAktualisieren(bool $mitCover = false): void
    {
        $json = (string)json_encode($this->kachelDaten(false, $mitCover));
        $stand = md5($json);
        if ($mitCover || $stand !== $this->GetBuffer('Kachel')) {
            $this->SetBuffer('Kachel', $stand);
            $this->UpdateVisualizationValue($json);
        }
    }

    private function kachelDaten(bool $mitTexten, bool $mitCover): array
    {
        $daten = [
            'erreichbar' => $this->host() !== '' && $this->ReadAttributeBoolean('Reachable'),
            'steuerbar' => $this->fernsteuerungAn(),
            'zustand' => (int)($this->wert('Zustand') ?? 0),
            'titel' => (string)($this->wert('Titel') ?? ''),
            'interpret' => (string)($this->wert('Interpret') ?? ''),
            'album' => (string)($this->wert('Album') ?? ''),
            'app' => (string)($this->wert('App') ?? ''),
            'lautstaerke' => (int)($this->wert('Lautstaerke') ?? 0),
            'favoriten' => array_map(fn (array $f): string => (string)($f['Name'] ?? ''), $this->liste('Favorites')),
            'sprache' => $this->spracheEingerichtet(),
            'meldung' => $this->ergebnisAnzeige(false),
            'coverGroesse' => self::COVER_GROESSEN[$this->ReadPropertyString('TileCover')] ?? 72,
            'naechste' => $this->naechsteFuerKachel(),
            'klima' => $this->klimaFuerKachel(),
        ];
        if ($mitCover) {
            $daten['cover'] = $this->coverDatenUrl();
        }
        if ($mitTexten) {
            $daten['texte'] = [
                'playing' => AirPlayText::t('Playing'), 'paused' => AirPlayText::t('Paused'), 'stopped' => AirPlayText::t('Stopped'),
                'unknown' => AirPlayText::t('Unknown'), 'offline' => AirPlayText::t('Device not reachable'),
                'nothing' => AirPlayText::t('nothing playing'), 'volume' => AirPlayText::t('Volume'), 'favorites' => AirPlayText::t('Favorites'),
                'say' => AirPlayText::t('Announcement'), 'sayHint' => AirPlayText::t('Text to speak …'), 'send' => AirPlayText::t('Speak'),
                'next' => AirPlayText::t('Up next'), 'today' => AirPlayText::t('today'), 'tomorrow' => AirPlayText::t('tomorrow'),
                'temperature' => AirPlayText::t('Temperature'), 'humidity' => AirPlayText::t('Humidity'),
            ];
        }
        return $daten;
    }

    /**
     * Nächster Termin aus Ansagen und Wecker für die Kachel: Zeitpunkt, Art und Text (Platzhalter mit Blick auf
     * diesen Zeitpunkt ersetzt). null = nichts geplant.
     */
    private function naechsteFuerKachel(): ?array
    {
        $zeit = $this->ReadAttributeInteger('ScheduleNext');
        $faellig = $zeit > 0 ? AirPlayZeitplan::faellig($this->termine(), $zeit) : [];
        if ($faellig === []) {
            return null;
        }
        $e = $faellig[0];
        if ($e['Art'] === 'wecker') {
            $link = trim((string)$e['Link']);
            $text = AirPlayText::t('Alarm clock') . (preg_match('~^https?://~i', $link) ? '' : ' · ' . $link);
        } else {
            $text = AirPlayPlatzhalter::ersetzen((string)$e['Text'], $zeit, fn (int $id): ?string => @IPS_VariableExists($id) ? (string)GetValueFormatted($id) : null);
        }
        return ['zeit' => $zeit, 'wecker' => $e['Art'] === 'wecker', 'text' => mb_strimwidth($text, 0, 120, '…', 'UTF-8')];
    }

    /** Raumklima für die Kachel: formatierte Werte der gewählten Variablen (fehlende Variablen entfallen). */
    private function klimaFuerKachel(): array
    {
        $werte = [];
        foreach (self::KLIMA_VARIABLEN as $eigenschaft => $schluessel) {
            $id = $this->ReadPropertyInteger($eigenschaft);
            if ($id > 0 && @IPS_VariableExists($id)) {
                $werte[$schluessel] = (string)GetValueFormatted($id);
            }
        }
        return $werte;
    }

    /** true, wenn das Medienobjekt existiert und seine Datei vorhanden ist (sonst warnt IPS_GetMediaContent). */
    private static function medienDateiDa(int $id): bool
    {
        return @IPS_MediaExists($id) && !empty(IPS_GetMedia($id)['MediaIsAvailable']);
    }

    private function coverDatenUrl(): string
    {
        $id = @IPS_GetObjectIDByIdent('Cover', $this->InstanceID);
        // Neue Instanz: das Cover-Medium hat noch keine Datei, IPS_GetMediaContent würde warnen und das Anlegen
        // abbrechen (Forum 145020 #2, Symcon 9.0/9.1).
        $inhalt = $id !== false && self::medienDateiDa($id) ? (string)IPS_GetMediaContent($id) : '';
        if ($inhalt === '' || $inhalt === self::COVER_PLATZHALTER) {
            return '';
        }
        return 'data:' . (str_starts_with($inhalt, 'iVBOR') ? 'image/png' : 'image/jpeg') . ';base64,' . $inhalt;
    }

    // ------------------------------------------------------------------ Formular

    protected function airplayPrefix(): string
    {
        return 'AIRP';
    }

    protected function airplayZweck(): array
    {
        return [
            'Plays announcements on a HomePod via AirPlay 2 and shows and controls what it is playing — directly from Symcon, without additional software.',
            'Use it from scripts: AIRP_Say(<instance>, "Text"); or AIRP_AnnounceMedia(<instance>, <media object with a WAV file>); — music that was playing continues afterwards.',
        ];
    }

    protected function airplayDoku(): array
    {
        return [
            'Speech: AIRP_Say(id, text) and AIRP_SayEx(id, text, volume) read a text aloud (at most 1000 characters) and return when the announcement has finished. The voice comes from the AirPlay-Sprachausgabe instance selected in the panel "Speech output".',
            'Without waiting: AIRP_SayAsync(id, text, volume, chime) queues the announcement and returns at once; the instance speaks queued announcements one after the other (at most 10 waiting, older than 10 minutes are skipped). chime = true plays the chime first.',
            'Placeholders in texts: {uhrzeit}/{time}, {wochentag}/{weekday}, {datum}/{date}, {var:12345} = formatted value of a variable.',
            'Several HomePods at once: AIRPS_SayGroup(<speech output instance>, "group", text, volume, chime) — enter the groups of this device in the panel "Announcements" (empty group = all devices).',
            'Audio and sounds: AIRP_AnnounceMedia(id, mediaID), AIRP_AnnounceMediaEx(id, mediaID, volume), AIRP_AnnounceWave(id, wavBytes), AIRP_AnnounceWaveEx(id, wavBytes, volume), AIRP_PlaySound(id, "gong" | "doorbell" | "notice" | "alarm"), AIRP_PlayChime(id). WAV (PCM) with any sample rate, at most 120 seconds.',
            'Night limit: between the set hours, no announcement plays louder than the night volume — also for announcements from WarnHub, Automat or the schedule.',
            'Presence: with a presence variable, scheduled announcements and the alarm clock — and on request also announcements from other modules (AIRP_SayAsync, e.g. WarnHub) — are skipped while nobody is at home. Leave the second checkbox off if warnings should always play.',
            'Remote control: AIRP_Play(id), AIRP_Pause(id), AIRP_Stop(id), AIRP_Next(id), AIRP_Previous(id), AIRP_SetVolume(id, volume), AIRP_Update(id), AIRP_TestConnection(id). All return true or false; the result is shown in the panel "Announcements".',
            'Apple Music: AIRP_PlayPlaylist(id, link, shuffle) starts the songs of a playlist or album from its share link (music.apple.com), a comma-separated list of song IDs or the name of a favorite. The HomePod plays them from its own Apple Music account.',
            'Alarm clock: starts a playlist quietly at the set time and raises the volume step by step over the set minutes; afterwards an optional greeting. Pausing the music ends the alarm.',
            'Resume: before an announcement, the module remembers whether something was playing and the volume; afterwards it restores the volume and continues playback. This adds about 3–5 seconds.',
            'Display: the HomePod only reports changes while a connection is open. The module therefore asks at the set interval (a query takes about 2 seconds).',
            'The device must be reachable from Symcon on TCP port 7000 and on the ports it assigns per session; for the audio, the device sends time requests to UDP ports that Symcon opens for the duration of the announcement. A firewall on the Symcon host must allow that.',
        ];
    }

    public function GetConfigurationForm()
    {
        return $this->airplayFormular([
            $this->panelVerbindung(),
            $this->panelDurchsagen(),
            $this->panelSprache(),
            $this->panelAnsagen(),
            $this->panelWecker(),
            $this->panelAppleMusic(),
            $this->panelProtokoll(),
        ], [], [
            ['code' => 102, 'icon' => 'active', 'caption' => 'Ready'],
            ['code' => self::STATUS_ADRESSE_FEHLT, 'icon' => 'inactive', 'caption' => 'Please enter the address of the device'],
            ['code' => self::STATUS_PHP_FEHLT, 'icon' => 'error', 'caption' => 'PHP extension gmp or sodium missing'],
            ['code' => self::STATUS_NICHT_ERREICHBAR, 'icon' => 'error', 'caption' => 'Device not reachable'],
        ]);
    }

    private function panelVerbindung(): array
    {
        $fehlt = $this->fehlendeErweiterungen();
        return [
            'type' => 'ExpansionPanel', 'expanded' => $this->host() === '', 'caption' => '🔌  Connection & display',
            'items' => array_values(array_filter([
                $fehlt !== [] ? ['type' => 'Label', 'caption' => AirPlayText::t('⚠️ PHP extension missing: %s — announcements are not possible on this system.', implode(', ', $fehlt))] : null,
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP address or host name', 'width' => '300px'],
                    ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Port', 'minimum' => 1, 'maximum' => 65535],
                ]],
                ['type' => 'Label', 'caption' => 'Usually filled in by the AirPlay configurator. A fixed IP address (DHCP reservation) for the device is recommended.'],
                ['type' => 'ValidationTextBox', 'name' => 'DeviceID', 'caption' => 'Device ID (from the network search)', 'width' => '400px', 'enabled' => false],
                ['type' => 'ValidationTextBox', 'name' => 'Model', 'caption' => 'Device type', 'width' => '400px', 'enabled' => false],
                ['type' => 'Button', 'caption' => '🔗 Test connection (no sound)', 'onClick' => 'AIRP_UITest($id);'],
                ['type' => 'CheckBox', 'name' => 'RemoteControl', 'caption' => 'Show and control playback (HomePod, Apple TV)'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateInterval', 'caption' => 'Update interval', 'suffix' => 's', 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'Label', 'caption' => '0 = no automatic update. Values below 5 seconds are raised to 5.'],
                ['type' => 'Button', 'caption' => '🔄 Update now', 'onClick' => 'AIRP_UIUpdate($id);'],
                ['type' => 'Select', 'name' => 'TileCover', 'caption' => 'Cover in the tile', 'options' => [
                    ['caption' => 'Hidden', 'value' => 'off'],
                    ['caption' => 'Small', 'value' => 'small'],
                    ['caption' => 'Medium', 'value' => 'medium'],
                    ['caption' => 'Large', 'value' => 'large'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'SelectVariable', 'name' => 'TemperatureVariable', 'caption' => 'Temperature in the tile', 'width' => '300px'],
                    ['type' => 'SelectVariable', 'name' => 'HumidityVariable', 'caption' => 'Humidity in the tile', 'width' => '300px'],
                ]],
                ['type' => 'Label', 'caption' => 'Optional: the tile shows these values next to the title, e.g. the values of AirPlay-Klima for this HomePod.'],
            ])),
        ];
    }

    private function panelDurchsagen(): array
    {
        $letztes = $this->ergebnisAnzeige();
        return [
            'type' => 'ExpansionPanel', 'expanded' => true, 'caption' => '📢  Announcements',
            'items' => [
                ['type' => 'CheckBox', 'name' => 'SetVolume', 'caption' => 'Set the volume for announcements'],
                ['type' => 'NumberSpinner', 'name' => 'Volume', 'caption' => 'Volume for announcements', 'suffix' => '%', 'minimum' => 0, 'maximum' => 100],
                ['type' => 'Label', 'caption' => 'Without the checkbox, the announcement plays at the volume currently set on the device.'],
                ['type' => 'CheckBox', 'name' => 'ResumeAfterAnnouncement', 'caption' => 'Afterwards, restore the volume and continue playback'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'NightLimit', 'caption' => 'Night limit'],
                    ['type' => 'NumberSpinner', 'name' => 'NightVolume', 'caption' => 'at most', 'suffix' => '%', 'minimum' => 0, 'maximum' => 100],
                    ['type' => 'NumberSpinner', 'name' => 'NightFrom', 'caption' => 'from', 'suffix' => 'h', 'minimum' => 0, 'maximum' => 23],
                    ['type' => 'NumberSpinner', 'name' => 'NightTo', 'caption' => 'to', 'suffix' => 'h', 'minimum' => 0, 'maximum' => 23],
                ]],
                ['type' => 'Label', 'caption' => 'In these hours (e.g. 22 to 7 h), no announcement plays louder — including WarnHub, Automat and the schedule.'],
                ['type' => 'ValidationTextBox', 'name' => 'Groups', 'caption' => 'Groups (comma-separated, e.g. Ground floor, Kitchen)', 'width' => '420px'],
                ['type' => 'SelectVariable', 'name' => 'PresenceVariable', 'caption' => 'Presence (variable: true or > 0 = somebody at home)', 'width' => '420px'],
                ['type' => 'CheckBox', 'name' => 'PresenceSchedule', 'caption' => 'Scheduled announcements and alarm clock only when somebody is at home'],
                ['type' => 'CheckBox', 'name' => 'PresenceQueue', 'caption' => 'Announcements from other modules (WarnHub, Automat) only when somebody is at home'],
                ['type' => 'Label', 'caption' => 'Without a variable, announcements always play. Announcements from scripts, this form and the tile always play. Skipped announcements appear in the announcement log.'],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'Select', 'name' => 'TestSound', 'caption' => 'Sound', 'value' => 'gong', 'options' => self::klangOptionen(false)],
                    ['type' => 'Button', 'caption' => '🔔 Play sound', 'onClick' => 'AIRP_UISound($id, $TestSound);'],
                ]],
                ['type' => 'Label', 'name' => 'LastResult', 'caption' => $letztes !== '' ? $letztes : AirPlayText::t('ℹ️ No announcement played yet.')],
            ],
        ];
    }

    private function panelSprache(): array
    {
        $altesPiper = trim($this->ReadPropertyString('PiperServer')) !== '';
        return [
            'type' => 'ExpansionPanel', 'expanded' => !$this->spracheEingerichtet(), 'caption' => '🗣️  Speech output',
            'items' => [
                ['type' => 'Label', 'caption' => 'For AIRP_Say, AIRP_SayAsync and the scheduled announcements: choose an "AirPlay-Sprachausgabe" instance. It turns the text into speech — with Piper in your own network or an online service (Microsoft Azure, ElevenLabs, Google Cloud) — and serves all AirPlay devices.'],
                ['type' => 'SelectInstance', 'name' => 'SpeechInstance', 'caption' => 'Speech output instance', 'validModules' => [self::GUID_SPRACHE], 'width' => '420px'],
                ['type' => 'Label', 'visible' => $altesPiper, 'caption' => 'Older direct Piper setting (before version 0.8.0) — used only while no speech output instance is selected. Recommended: create an AirPlay-Sprachausgabe instance with the same server, select it above and clear the field below.'],
                ['type' => 'RowLayout', 'visible' => $altesPiper, 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'PiperServer', 'caption' => 'Piper server (host or host:port, port 10200)', 'width' => '320px'],
                    ['type' => 'ValidationTextBox', 'name' => 'PiperVoice', 'caption' => 'Voice (empty = default of the server)', 'width' => '280px'],
                    ['type' => 'Button', 'caption' => '🔍 Show voices', 'onClick' => 'AIRP_UIVoices($id, $PiperServer);'],
                ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'SayText', 'caption' => 'Test text', 'value' => AirPlayText::t('This is a test of the speech output.'), 'width' => '420px'],
                    ['type' => 'Button', 'caption' => '🗣️ Speak', 'onClick' => 'AIRP_UISay($id, $SayText);'],
                ]],
                ['type' => 'Label', 'caption' => 'Apply changes first, then test. The volume and resume settings of the panel "Announcements" apply.'],
            ],
        ];
    }

    private function panelAnsagen(): array
    {
        $naechste = $this->ReadAttributeInteger('ScheduleNext');
        return [
            'type' => 'ExpansionPanel', 'expanded' => $this->liste('Schedule') !== [], 'caption' => '⏰  Scheduled announcements',
            'items' => [
                ['type' => 'Label', 'caption' => 'Spoken via the speech output (panel "Speech output"). Placeholders in the text: {uhrzeit} or {time} ("14 o\'clock", "14:30"), {wochentag} or {weekday}, {datum} or {date}, {var:12345} = value of a variable with its unit.'],
                ['type' => 'List', 'name' => 'Schedule', 'caption' => 'Announcements', 'rowCount' => 5, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Active', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'When', 'name' => 'When', 'width' => '190px', 'add' => 'hourly', 'edit' => ['type' => 'Select', 'options' => [
                            ['caption' => 'Every full hour', 'value' => 'hourly'],
                            ['caption' => 'Every half hour', 'value' => 'halfhourly'],
                            ['caption' => 'Daily at the time', 'value' => 'daily'],
                        ]]],
                        ['caption' => 'Time (daily)', 'name' => 'Time', 'width' => '110px', 'add' => '07:00', 'edit' => ['type' => 'ValidationTextBox']],
                        self::tageSpalte(),
                        ['caption' => 'From', 'name' => 'From', 'width' => '80px', 'add' => 7, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 23, 'suffix' => 'h']],
                        ['caption' => 'To', 'name' => 'To', 'width' => '80px', 'add' => 22, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 23, 'suffix' => 'h']],
                        ['caption' => 'Text', 'name' => 'Text', 'width' => 'auto', 'add' => AirPlayText::t('It is {time}.'), 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Volume', 'name' => 'Volume', 'width' => '90px', 'add' => -1, 'edit' => ['type' => 'NumberSpinner', 'minimum' => -1, 'maximum' => 100, 'suffix' => '%']],
                        ['caption' => 'Sound', 'name' => 'Sound', 'width' => '130px', 'add' => '', 'edit' => ['type' => 'Select', 'options' => self::klangOptionen(true)]],
                    ]],
                ['type' => 'Label', 'caption' => '"From/To" limits hourly and half-hourly announcements to these hours (e.g. 7 to 22 h; 22 to 6 h also works). Volume -1 = setting of the panel "Announcements".'],
                ['type' => 'Label', 'caption' => $naechste > 0
                    ? AirPlayText::t('⏰ Next scheduled event: %s', date('d.m.Y H:i', $naechste))
                    : AirPlayText::t('ℹ️ No scheduled announcement active.')],
            ],
        ];
    }

    private function panelWecker(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '⏰  Alarm clock',
            'items' => [
                ['type' => 'Label', 'caption' => 'Starts Apple Music quietly at the set time and raises the volume step by step; afterwards an optional greeting (with placeholders). Pausing the music ends the alarm. Playlist: share link or the name of a favorite.'],
                ['type' => 'List', 'name' => 'Alarms', 'caption' => 'Alarms', 'rowCount' => 3, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Active', 'name' => 'Active', 'width' => '70px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Time', 'name' => 'Time', 'width' => '90px', 'add' => '06:30', 'edit' => ['type' => 'ValidationTextBox']],
                        self::tageSpalte(),
                        ['caption' => 'Playlist', 'name' => 'Link', 'width' => '220px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Shuffle', 'name' => 'Shuffle', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                        ['caption' => 'Start', 'name' => 'StartVolume', 'width' => '80px', 'add' => 5, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100, 'suffix' => '%']],
                        ['caption' => 'End', 'name' => 'EndVolume', 'width' => '80px', 'add' => 30, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 100, 'suffix' => '%']],
                        ['caption' => 'Minutes', 'name' => 'Minutes', 'width' => '90px', 'add' => 10, 'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 60]],
                        ['caption' => 'Greeting', 'name' => 'Text', 'width' => 'auto', 'add' => AirPlayText::t('Good morning, it is {time}.'), 'edit' => ['type' => 'ValidationTextBox']],
                    ]],
            ],
        ];
    }

    private function panelAppleMusic(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '🎵  Apple Music',
            'items' => [
                ['type' => 'Label', 'caption' => 'Share link of a playlist or album (Apple Music app: … → Share → Copy link). The HomePod plays a copy of the song list; later changes to the playlist are not included. Favorites appear on the tile and can be used by name in the alarm clock and in AIRP_PlayPlaylist.'],
                ['type' => 'List', 'name' => 'Favorites', 'caption' => 'Favorites', 'rowCount' => 4, 'add' => true, 'delete' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '200px', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Link', 'name' => 'Link', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Shuffle', 'name' => 'Shuffle', 'width' => '80px', 'add' => true, 'edit' => ['type' => 'CheckBox']],
                    ]],
                ['type' => 'RowLayout', 'items' => [
                    ['type' => 'ValidationTextBox', 'name' => 'PlaylistLink', 'caption' => 'Apple Music link or favorite', 'width' => '420px'],
                    ['type' => 'CheckBox', 'name' => 'PlaylistShuffle', 'caption' => 'Shuffle'],
                    ['type' => 'Button', 'caption' => '🎵 Play', 'onClick' => 'AIRP_UIPlaylist($id, $PlaylistLink, $PlaylistShuffle);'],
                ]],
                ['type' => 'Label', 'caption' => 'This field only plays once. To keep a playlist as a favorite, add a row to the list above and click "Apply changes".'],
            ],
        ];
    }

    private function panelProtokoll(): array
    {
        // Listenzellen übersetzt die Konsole nicht (SUITE.md 25) — Quelle und Ergebnis hier selbst.
        $zeilen = array_map(fn (array $e): array => [
            'zeit' => date('d.m. H:i:s', (int)$e['zeit']),
            'quelle' => $this->quelleName((string)$e['quelle']),
            'text' => (string)$e['text'],
            'ergebnis' => (!empty($e['skip']) ? '⏸️ ' : ($e['ok'] ? '✅ ' : '❌ ')) . (string)$e['info'],
        ], json_decode($this->ReadAttributeString('History'), true) ?: []);
        return [
            'type' => 'ExpansionPanel', 'expanded' => false, 'caption' => '📜  Announcement log',
            'items' => [
                ['type' => 'List', 'name' => 'History', 'caption' => 'Last announcements', 'rowCount' => 8, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['caption' => 'Time', 'name' => 'zeit', 'width' => '130px'],
                        ['caption' => 'Source', 'name' => 'quelle', 'width' => '120px'],
                        ['caption' => 'Text', 'name' => 'text', 'width' => 'auto'],
                        ['caption' => 'Result', 'name' => 'ergebnis', 'width' => '220px'],
                    ],
                    'values' => $zeilen],
            ],
        ];
    }

    private static function tageSpalte(): array
    {
        return ['caption' => 'Days', 'name' => 'Days', 'width' => '130px', 'add' => 'all', 'edit' => ['type' => 'Select', 'options' => [
            ['caption' => 'Every day', 'value' => 'all'],
            ['caption' => 'Mon–Fri', 'value' => 'weekdays'],
            ['caption' => 'Sat–Sun', 'value' => 'weekend'],
        ]]];
    }

    private static function klangOptionen(bool $ohne): array
    {
        return array_merge($ohne ? [['caption' => '–', 'value' => '']] : [], [
            ['caption' => 'Chime', 'value' => 'gong'],
            ['caption' => 'Doorbell', 'value' => 'doorbell'],
            ['caption' => 'Notice', 'value' => 'notice'],
            ['caption' => 'Alarm', 'value' => 'alarm'],
        ]);
    }
}
