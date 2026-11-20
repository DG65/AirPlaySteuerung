# Changelog

## 0.3.0 (unveröffentlicht)

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
