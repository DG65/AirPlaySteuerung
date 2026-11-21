# Changelog

## 0.4.2 (unveröffentlicht)

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
