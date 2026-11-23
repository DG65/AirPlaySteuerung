# Changelog

## 0.11.2 (unveröffentlicht)

- Store-Vorbereitung: englische Suchnamen der Module (AirPlay Device, AirPlay Climate, AirPlay Speech Output,
  AirPlay Configurator), Erklärung der WebHook-Anmeldung im Code, Raumklima-Meldungen erst nach vollständigem
  Symcon-Start, README-Status nennt, was bisher nur im Prüfstand lief.

## 0.11.1 (23.11.2026)

- Korrektur Apple Music: Ein Album-Link mit ausgewähltem Lied (`…/album/…?i=1440771270`, so teilt die Apple-Music-App
  ein einzelnes Lied) startet jetzt genau dieses Lied, danach folgt das Album. Bisher lief das ganze Album, mit „Zufall“
  ab einem beliebigen Titel. Zufall gilt jetzt erst ab dem zweiten Titel (live geprüft am HomePod, 23.11.2026).

## 0.11.0 (23.11.2026)

- Neu in der Kachel: **Als Nächstes** — die nächste geplante Ansage (Platzhalter für diesen Zeitpunkt ersetzt) oder der
  nächste Wecker mit Uhrzeit („heute 14:00", „morgen 06:30").
- Neu in der Kachel: **Raumklima** — Temperatur und Luftfeuchte aus zwei wählbaren Variablen (z. B. AirPlay-Klima)
  rechts neben dem Titel; neue Werte erscheinen sofort (Meldung VM_UPDATE), nicht erst bei der nächsten Abfrage.
- Neu: **Cover-Größe** einstellbar (ausgeblendet, klein, mittel, groß; Standard klein wie bisher).
- Apple Music: Hinweis, dass das Abspielfeld nichts speichert — Favoriten gehören in die Liste darüber.

## 0.10.1 (23.11.2026)

- Kachel: übernimmt ab Symcon 9.1 die Abstände der Kachel-Visualisierung (`--margin-*`). Der Kacheltitel überdeckt
  den Liedtitel nicht mehr, und bei ausgeblendetem Titel rückt der Inhalt nach oben. Ältere Versionen: 14 px wie bisher.

## 0.10.0 (22.11.2026)

- Neu: **Anwesenheit** im AirPlay-Gerät — Anwesenheitsvariable (wahr oder > 0 = jemand zu Hause). Geplante Ansagen
  und Wecker entfallen bei Abwesenheit (Haken, Standard an); Durchsagen anderer Module über `AIRP_SayAsync`
  (WarnHub, Automat) nur auf Wunsch (Haken, Standard aus — Warnungen kommen sonst immer). Skript, Formular und
  Kachel sprechen immer. Ausgelassene Durchsagen stehen mit ⏸️ im Protokoll.
- Neu: **AirPlay-Klima meldet einen Ausfall** — beim Wechsel auf „Seit einiger Zeit keine Werte" einmal ein Eintrag im
  Meldungen-Fenster und, wenn gewählt, eine Push-Mitteilung an eine Kachel-Visualisierung (`VISU_PostNotificationEx`)
  oder ein WebFront (`WFC_PushNotification`); ebenso einmal, wenn die Werte wieder kommen.

## 0.9.1 (22.11.2026)

- Fix: AirPlay-Klima wählt für die Kurzbefehl-URL die Kabel-Adresse von Symcon vor WLAN und übergeht virtuelle Netze
  (Docker, libvirt, VPN). Vorher die erste Adresse der Liste — live fiel eine WLAN-Adresse weg, und der Kurzbefehl lief
  ins Leere.
- Neu: Bei „Seit einiger Zeit keine Werte" nennt das Formular die häufigste Ursache (geänderte Adresse) und die URL,
  die der Kurzbefehl verwenden muss.

## 0.9.0 (22.11.2026)

- Neu: **Wecker** — Liste im Gerät: Uhrzeit, Tage, Playlist (Link oder Favorit), Lautstärke steigt von Start auf Ende
  über einstellbare Minuten (Schritt alle 20 s), danach optionaler Gruß. Pausieren beendet den Anstieg.
- Neu: **Klänge** Gong, Türklingel, Hinweiston, Alarm (`AIRP_PlaySound`); geplante Ansagen wählen den Klang davor
  (Spalte „Klang" ersetzt „Gong" — bestehende Zeilen mit Gong bleiben beim Gong, bis die Liste neu gespeichert wird).
- Neu: **Nachtgrenze** für alle Durchsagen (Stundenfenster, Höchstlautstärke).
- Neu: **Gruppen-Durchsage** `AIRPS_SayGroup(id, gruppe, text, lautstärke, gong)` und Feld „Gruppen" im Gerät;
  Sprache wird einmal erzeugt und je Gerät eingereiht.
- Neu: **Player-Kachel** (Cover, Titel, Steuerung, Lautstärke, Favoriten, schnelle Ansage) und Favoritenliste.
- Neu: **Durchsage-Protokoll** (letzte 20) im Formular.
- Umbau: AirPlay-Gerät in Klasse + vier Traits (Durchsage, Wiedergabe, Zeitplan, Oberfläche); gemeinsamer
  HTTP-Baustein; Sprechtext-Regel an einer Stelle; Übersetzungen mit Merkliste. Interne Timer laufen über
  `RequestAction` — die öffentlichen Funktionen `AIRP_RunQueue` und `AIRP_RunSchedule` entfallen (nur intern genutzt).
- Prüfstand: neuer Modulprüfstand `.tools/test-module.php` mit Symcon-Attrappe (Gerät und Sprachausgabe offline).
- Fix (Code-Prüfung): WAV-Umrechnung blockweise — eine 60-s-Ansage brauchte bis zu ~300 MB Speicher, jetzt ~24 MB;
  Ergebnis bitgenau wie vorher (36 Formate verglichen).
- Fix: Scheitert nach einer Durchsage das Fortsetzen oder Zurückstellen der Lautstärke, gilt die Durchsage trotzdem
  als gespielt (Hinweis statt Fehler).
- Fix: Titel ohne Cover (Radio, Podcast) lösten bei jeder Abfrage eine Cover-Anfrage mit bis zu 3 s Wartezeit aus.
- Fix: RTSP-Antworten werden über die CSeq zugeordnet; Rahmen-Entschlüsselung ohne quadratisches Umkopieren; ohne
  Fernsteuerkanal sofort ein klarer Fehler statt Warten.
- Neu: Port je Gerät (aus der Netzwerksuche übernommen, Standard 7000) für AirPlay-Empfänger anderer Hersteller.
- Umbau: Absender-Angaben in `AirPlayAbsender` (Werte unverändert), letztes Ergebnis strukturiert gespeichert,
  alter Piper-Weg über denselben Sprachdienst wie die Sprachausgabe, Kachel nur bei Änderung senden, AirPlay-Klima
  schaltet intern über `RequestAction` (`AIRPK_TriggerOff` entfällt; `AIRPK_Trigger` bleibt).

## 0.8.0 (21.11.2026)

- Neu: Modul „AirPlay-Sprachausgabe" (`AIRPS`) — eine Instanz für alle HomePods: Sprachdienst Piper (eigenes Netz),
  Microsoft Azure, ElevenLabs oder Google Cloud; Schlüssel als Attribut (nicht im Formular, nicht im Export);
  „Stimmen anzeigen", Probeerzeugung; Zwischenspeicher für gleiche Texte (versteckte Ton-Medien, Höchstzahl
  einstellbar). Skriptfunktionen `AIRPS_GetSpeech`, `AIRPS_GetLastError`, `AIRPS_ClearCache`.
- Geändert: Das AirPlay-Gerät wählt im Panel „Sprachausgabe" eine AirPlay-Sprachausgabe-Instanz. Die direkte
  Piper-Einstellung aus 0.5.0–0.7.0 gilt weiter, solange keine Instanz gewählt ist, und wird nur noch angezeigt,
  wenn sie ausgefüllt ist.
- Fix: Die Rückfrage „Neuen Schlüssel erzeugen?" bei AirPlay-Klima war nicht übersetzt (Textprüfung erfasst jetzt
  auch `confirm`).

## 0.7.0 (21.11.2026)

- Neu: `AIRP_SayAsync(id, text, lautstärke, gong)` — Durchsage einreihen und sofort zurückkehren. Je Gerät eine
  Warteschlange (höchstens 10, ältere als 10 Minuten verfallen), abgearbeitet der Reihe nach vom Timer „Queue";
  mit Gong davor auf Wunsch. Anlass: WarnHub soll Warnungen ansagen, ohne in seinem Abfragelauf zu warten.

## 0.6.0 (21.11.2026)

- Neu: Geplante Ansagen — Liste im HomePod-Gerät: jede volle oder halbe Stunde in einem Stundenfenster (auch über
  Mitternacht) oder täglich zu einer Uhrzeit; jeden Tag, Mo–Fr oder Sa–So; eigene Lautstärke, Gong davor. Ein
  Timer der Instanz weckt genau zur nächsten Ansage (kein Ereignis, kein Minutentakt).
- Neu: Platzhalter in Ansagetexten, auch für `AIRP_Say`: `{uhrzeit}`/`{time}`, `{wochentag}`/`{weekday}`,
  `{datum}`/`{date}`, `{var:ID}` (formatierter Wert einer Variable).

## 0.5.0 (21.11.2026)

- Neu: Sprachausgabe — `AIRP_Say(id, text)` und `AIRP_SayEx(id, text, lautstärke)` lesen Text auf dem HomePod vor.
  Die Stimme liefert ein Piper-Server im eigenen Netz (Wyoming-Protokoll, z. B. Docker-Container
  rhasspy/wyoming-piper), rein in PHP angebunden, ohne Konto und ohne Cloud. Panel „Sprachausgabe (Piper)" mit
  Server, Stimme, „Stimmen anzeigen" und Testtext; Anleitung für Portainer in der README.

## 0.4.3 (21.11.2026)

- Geändert: Anleitung für den Kurzbefehl auf POST mit JSON-Anfragetext umgestellt (live erprobt am HomePod mini).
  Werte als URL-Parameter gingen verloren: Der Kurzbefehl setzt „22,4 °C" mit Leerzeichen ein, die URL endete dort.
  Das URL-Feld im Formular enthält jetzt nur noch die Adresse mit Schlüssel. Neuer Schritt D2: Fehlersuche über das
  Debug-Fenster.
- Fix: Schlüssel im JSON werden ohne Leerzeichen und angehängtes „=“/„:“ gelesen (`"temperature ="` zählt
  wie `"temperature"`).

## 0.4.2 (21.11.2026)

- Geändert: AirPlay-Klima meldet während der Einrichtung keinen Fehler mehr. Solange noch kein HomePod Werte
  geschickt hat (oder alle Raum-Variablen gelöscht sind), zeigt das Formular den Hinweis „Noch keine Werte
  empfangen" und die Anleitung bleibt aufgeklappt; „Seit einiger Zeit keine Werte" erst, wenn ein HomePod schon
  gemeldet hat.
- Neu: Das Debug-Fenster von AirPlay-Klima zeigt jeden WebHook-Aufruf im Rohformat (ohne Schlüssel) — hilft,
  wenn ein Messwert nicht ankommt.

## 0.4.1 (21.11.2026)

- Neu: Der WebHook von AirPlay-Klima nimmt die Werte auch als URL-Parameter an
  (`…&name=Schlafzimmer&temperature=…&humidity=…`) — der Kurzbefehl braucht damit nur noch eine Aktion
  „Inhalte von URL abrufen" ohne JSON.
- Geändert: Anleitung im Formular Schritt für Schritt mit den echten Bezeichnungen der HomeKit-Brücke
  (Liste „Geräte", Typ „Zwischenstecker", „VariablenID").
- Fix: Die Liste der HomePods zeigt nur noch Räume, deren Variablen existieren (gelöschte Räume verschwinden).

## 0.4.0 (21.11.2026)

- Neu: Modul „AirPlay-Klima" (`AIRPK`) — Temperatur und Luftfeuchte von HomePod mini / HomePod (2. Gen.) als
  Variablen. Auslöse-Schalter „Messung anstoßen" (über die HomeKit-Brücke in Apple Home), WebHook mit geheimem
  Schlüssel, Variablen je HomePod beim ersten Empfang, Status „Seit einiger Zeit keine Werte" nach drei Abständen.
  Anleitung für die Apple-Home-Automation im Formular.

## 0.3.0 (20.11.2026)

- Neu: `AIRP_PlayPlaylist(id, link, zufällig)` startet Apple Music auf dem HomePod — Teilen-Link einer Playlist oder
  eines Albums (music.apple.com) oder Song-IDs; Titelliste wird von der öffentlichen Seite gelesen (höchstens 300).
  Formular: Feld „Apple-Music-Link" mit „Zufällig" und „🎵 Abspielen".
- Geändert: App-Anzeige „Apple Music" statt des internen HomePod-Namens „AirMusic"; bei mehreren Playern zählt die
  jüngste Meldung.

## 0.2.0 (20.11.2026)

- Neu: Anzeige der Wiedergabe über MRP im AirPlay-Tunnel — Zustand, Titel, Interpret, Album, App, Lautstärke
  und Cover als Variablen/Medienobjekt, Abfrage im einstellbaren Abstand (Standard 15 s).
- Neu: Steuerung — `AIRP_Play/Pause/Stop/Next/Previous`, `AIRP_SetVolume`, `AIRP_Update`; Variablen „Steuerung"
  und „Lautstärke" im WebFront bedienbar.
- Neu: Nach einer Durchsage wird vorher laufende Musik fortgesetzt und die Lautstärke zurückgestellt
  (abschaltbar).
- Neu: Status 203 „Gerät nicht erreichbar", Meldung nur beim Wechsel.

## 0.1.0 (20.11.2026)

- Neu: Durchsagen auf HomePods über AirPlay 2 in reinem PHP (`AIRP_AnnounceMedia`, `AIRP_AnnounceWave`,
  jeweils mit `…Ex` für eigene Lautstärke), Gong (`AIRP_PlayChime`), Verbindungstest ohne Ton.
- Neu: WAV-Umrechnung in PHP (beliebige Abtastrate, Mono/Stereo, 8–32 Bit → 44,1 kHz Stereo).
- Neu: AirPlay-Konfigurator mit Einordnung je Gerät (HomePod, Apple TV, Fremdgerät) und Spalte „Durchsage".
