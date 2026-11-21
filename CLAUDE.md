# AirPlay-Steuerung — Hinweise für die Arbeit an diesem Repository

## Einordnung

- **Eigenständiges DG65-Modul**, weder NRG-Stack noch DG65-Toolkit (20.11.2026). `library.json→name`
  = „AirPlay-Steuerung", Repo `DG65/AirPlaySteuerung`. Push, Release und Store-Einreichung nur mit Freigabe des
  Autors. Die handwerklichen Regeln des DG65-Verbunds (SUITE.md: Store-Review-Checkliste, Formular-Konvention,
  Zweisprachigkeit) gelten.
- Module/Präfixe: `AirPlayGeraet` (AIRP, `{2BB8B63A-9A5A-4704-8230-C18259F9F3E7}`), `AirPlayKonfigurator`
  (AIRPC, `{B3546B70-328B-4640-B8D2-90C2F2D52DDB}`), Bibliothek `{F4937700-A1EF-41E6-B9B4-9F375903FB30}`.
  Klassen/Präfixe/GUIDs eingefroren, sobald eine Instanz existiert. Prüfung 20.11.2026: kein Symcon-Modul
  mit AirPlay/Apple/HomePod im Namen, Präfixe frei.
- Abgrenzung: KaiS' Store-Modul „AppleTV" (pyatv-mqtt-bridge) in der README fair erwähnt; vor der
  Veröffentlichung geht eine Nachricht an KaiS. LG webOS gehört nicht hierher
  (PeterChrisben baut ein Modul).

## Aufbau (libs/)

- `AirPlayKrypto` (bplist, TLV8, SRP, ChaCha20, HKDF, UUID) → `AirPlayVerbindung` (TCP + HAP-Rahmen) →
  `AirPlayRtsp` (HTTP/RTSP, CSeq, Warte-Haken) → `AirPlayKopplung` (transient, liefert K) →
  `AirPlayEreigniskanal` (Event-Kanal, gemeinsam) → `AirPlayAudioStrom` (Echtzeit-PCM) bzw.
  `AirPlayFernsteuerung` (MRP-Tunnel: `AirPlayDatenkanal` + `AirPlayProtobuf` + `AirPlayMrp`) →
  `AirPlaySitzung` (Fassade) → `AirPlayAblauf` (je Aktion eine kurze Sitzung: Zustand, Befehl, Lautstärke,
  Durchsage mit Fortsetzen). Alles ohne Symcon, prüfbar mit `.tools/test-airplay.php`.
- Fehler der Bibliotheken als `AirPlayFehler` (englische Vorlage + Werte), übersetzt im Modul über
  `AirPlayText::fehler()`. Texte: `.tools/locale-de.php` erzeugt alle locale.json, `.tools/texte-sammeln.php` prüft.
- Anzeige per Timer-Abfrage statt Dauerverbindung (Entscheidung 20.11.2026): je Abfrage ~2 s, Standard 15 s.

## Protokoll — belegte Fakten (20.11.2026, HomePod 1. Gen. + mini, tvOS 27.2.1)

- Referenz pyatv 0.18.0 (MIT), nur als Werkzeug im Notizbereich installiert, nicht Teil des Moduls.
- Kopplung „transient": POST /pair-pin-start, /pair-setup M1 (Method 0, Seq 1, Flags 0x10), M3 (A, M1);
  PIN 3939, SRP-6a 3072/SHA-512 wie srptools (Salz als rohe Bytes, sonst minimal kodiert). Danach
  RTSP verschlüsselt mit HKDF-SHA512(K, Control-Salt, Control-Write/Read-Encryption-Key).
- SETUP 1 → eventPort; Event-Kanal mit Events-Salt (aus = Read-Key, ein = Write-Key); Gerät schickt dort
  sofort `POST /command` type updateInfo, wir antworten 200.
- SETUP 2: type 0x60, ct 1 (rohes PCM, kein ALAC nötig), audioFormat 0x800, spf 352, sr 44100,
  shk = HKDF(Events-Salt, Events-Write)[0:32]. Pakete: RTP-Kopf (12 Byte) + ChaCha20(PCM Big Endian,
  AAD = Kopf[4:12]) + 8 Byte Nonce.
- Live: 4,5-s-TTS an einen HomePod (1. Gen.), 747 Pakete in 5,96 s, 0 verspätet, sauber hörbar.
- Live-Tests über `.tools/test-airplay.php --live <IP>`.

## MRP im AirPlay-Tunnel (20.11.2026, HomePod 1. Gen.)

- Nach der Kopplung: SETUP `isRemoteControlOnly` → eventPort (Event-Kanal), RECORD, SETUP Stream-Typ 130
  (`seed`, `clientTypeUUID` 1910A70F-…) → dataPort; Datenkanal mit HKDF(K, "DataStream-Salt"+seed,
  DataStream-Output/Input-Encryption-Key).
- Datenkanal-Rahmen: 32-Byte-Kopf (Größe, „sync"/„rply", „comm", Folgenummer) + bplist {params:{data}};
  `data` = Protobuf ProtocolMessage mit Varint-Längenpräfix. Anfragen „sync" mit „rply" bestätigen.
- Begrüßung: DEVICE_INFO (Antwort abwarten) → SET_CONNECTION_STATE → CLIENT_UPDATES_CONFIG. Danach liefert
  das Gerät DeviceInfo (deviceUID = Ziel für SET_VOLUME), VolumeDidChange, SetState (Titel im playbackQueue),
  Cover nur nach PLAYBACK_QUEUE_REQUEST (bis ~1 s Verzögerung).
- Live: Titel/Interpret/Album/App/Cover gelesen, Pause/Play, Lautstärke 20 % ↔ 25,6 %, Durchsage pausiert
  die Musik, danach Play + alte Lautstärke → Musik lief hörbar weiter.

## Apple Music starten (20.11.2026, live am HomePod 1. Gen.)

- SEND_COMMAND 48 (SetPlaybackQueue), CommandOptions Feld 15 `systemAppPlaybackQueueData` = **Binär-Plist** nach
  MediaRemote `MRSystemAppPlaybackQueueCreateExternalRepresentation` (dekompiliertes iOS 26.1, GitHub
  EthanArbuckle/iPhone18-3_26.1_23B85_Restore, MediaRemote_05.mm): PQty 3 = Titelliste, PQip sofort, PQri 2,
  PQfn, PQst Song-IDs (Strings), PQfg erster Titel, PQts Zufall (1 aus, 3 Titel), PQtr Wiederholen (1 aus).
  PQis (archiviertes MPIdentifierSet = echte Playlist) nicht nachgebaut.
- Fehlversuche zur Erinnerung: contextID/stationURL → Status 404; Plist mit erfundenen Schlüsseln → Apple Music
  startet irgendeinen Sender (Status 0!) — Status 0 allein beweist nichts, immer den ersten Titel vergleichen.
- Song-IDs: music.apple.com, script#serialized-server-data, contentDescriptor.kind = song → identifiers.storeAdamID.
- Der HomePod nennt seine Musik-App intern „AirMusic" (Bundle com.apple.Music) → Anzeige über APP_NAMEN.

## AirPlay-Klima (Stufe 3, 20.11.2026)

- Modul `AirPlayKlima` (AIRPK, `{EC8A60AD-33DB-41D6-B7B1-0C5363E5DC3A}`), Lib `AirPlayKlimaDaten` (JSON lesen, Zahlen mit
  Einheit/Komma, Kennung aus Name). Auslöse-Variable „Trigger" (Bool, Aktion) → paresys HomeKit-Bridge (Zubehör Switch)
  → Home-Automation „Gerät wird gesteuert/eingeschaltet" → Kurzbefehl „Status abrufen" + „Inhalte von URL abrufen"
  POST an `/hook/airplayklima<ID>?key=…`. WebHook-Eintrag wie RoboHub (WebHook-Control-Property `Hooks`).
- Vorbild: vdamboeck/homepod-ha-sensors (Home Assistant). Alternativen bewertet: Thermostat-Zieltemperatur über die
  Brücke (10–38 °C, 0,1) wäre möglich, für Feuchte gibt es kein beschreibbares Merkmal → WebHook.

## Sprachausgabe mit Piper (0.5.0, 21.11.2026)

- `libs/AirPlayPiper.php`: Wyoming-Protokoll (OHF-Voice/wyoming event.py) — JSON-Zeile {type, data_length,
  payload_length}, dann Daten, dann Nutzlast. `synthesize {text, voice:{name}}` → audio-start/-chunk (PCM LE)/-stop;
  `describe` → info (nur installed=true anzeigen). wyoming-piper lädt angeforderte Stimmen selbst nach
  (handler.py ensure_voice_exists). Ton als WAV → bestehender Durchsage-Weg.
- Docker `rhasspy/wyoming-piper` (amd64+arm64), `docker_run.sh`: `--uri tcp://0.0.0.0:10200 --data-dir /data` + Argumente.
- Prüfstand mit `.tools/wyoming-attrappe.php`; Mac-Test mit piper-tts 1.8 + wyoming 1.10.2 (Python 3.9) — espeak-ng
  verträgt keinen langen Datenpfad, daher relativ laden.
- Abgelehnt: Symcons TTS-Modul nachbauen (Kern, nutzt Windows-SAPI; Linux hat keine eingebaute Sprachausgabe).

## Geplante Ansagen (0.6.0)

- `libs/AirPlayZeitplan.php`: AirPlayZeitplan (passt/naechste/faellig, minutengenau, Horizont 8 Tage) und
  AirPlayPlatzhalter. Timer „Schedule" wird auf die nächste fällige Minute gestellt (Attribut ScheduleNext),
  RunSchedule spielt fällige Einträge, > 10 min Verspätung wird übersprungen. Gong + Sprache = ein PCM-Strom.
- `.tools/texte-sammeln.php` nimmt auch Vorlagen ohne Buchstaben auf, wenn sie `%1$s` enthalten.

## Warteschlange (0.7.0)

- `AIRP_SayAsync(id, text, volume, chime)` auf Wunsch von WarnHub (Zieltyp „Durchsage", 21.11.2026). Schlange im
  Attribut `Queue`, Semaphore `AIRP_Q_<id>` fürs Einreihen/Entnehmen, `AIRP_QRUN_<id>` gegen zwei gleichzeitige Läufe
  (zweiter Lauf stellt den Timer auf 1 s und kommt wieder). Keine Standardwerte in AIRP_-Funktionen (Symcon-Verhalten
  nicht geprüft).

## Geräteerkennung (`libs/AirPlayGeraete.php`)

- Aus TXT von `_airplay._tcp`: flags 0x8/0x200 → PIN nötig (LG OLED C9: 0x644), acl=1, act=2, pw/0x80,
  features Bit 43/48 = Kopplung ohne PIN möglich; fehlen beide → nur AirPlay 1 (Apple TV 3).
- Testgeräte: HomePod (1. Gen.), HomePod mini, Apple TV 3, LG OLED C9, Marantz-AV-Receiver. Kein tvOS-Apple-TV
  vorhanden → Companion/MRP fürs Apple TV nur nach Vorlage, Test über Forum-Tester.

## Offen / nächste Stufen

- MRP (Fernsteuerkanal über AirPlay): Zustand vor der Durchsage lesen, danach Musik fortsetzen und
  Lautstärke zurück; Play/Pause, Titel, Cover. Prüfauftrag: Apple-Music-Playlist starten.
- WarnHub als Push-Ziel (`AIRP_Say`)
  — WarnHub-Änderung als Anfrage an dessen Sitzung.
- Wiedergabe per URL (`/play`): MP3/AAC und Internetradio, das Gerät dekodiert selbst.
- Temperatur/Feuchte HomePod mini über HomeKit-Brücke (Stufe 3).
