# AirPlay-Steuerung

Durchsagen auf dem HomePod — direkt aus Symcon, in reinem PHP. Ohne Home Assistant, ohne Python,
ohne Docker, ohne Zusatzsoftware auf dem Symcon-Rechner.

[English version](README.en.md)

> **Status: Beta 0.1.0.** Durchsagen auf HomePod (1. Generation) und HomePod mini sind an echter
> Hardware geprüft. Fernsteuerung und Anzeige (Play/Pause, Titel, Cover) folgen in einer späteren
> Version.

## Was das Modul kann

- **Durchsagen:** Eine WAV-Datei aus einem Symcon-Medienobjekt (oder als Bytes, z. B. aus einem
  TTS-Modul) wird per AirPlay 2 an einen HomePod gestreamt. Lautstärke für Durchsagen einstellbar.
- **Gong:** Zweiklang zum Testen oder als Ankündigung, ganz ohne Audiodatei.
- **AirPlay-Konfigurator:** Findet HomePods, Apple TVs und andere AirPlay-Empfänger im Netz und zeigt
  in der Spalte „Durchsage", welche Geräte Durchsagen abspielen können. Geräte, die eine PIN-Kopplung
  verlangen (z. B. viele Fernseher), werden angezeigt, aber nicht zum Anlegen angeboten.

## Voraussetzungen

- Symcon ab 9.0
- PHP-Erweiterungen `gmp` und `sodium` (bei aktuellen Symcon-Versionen enthalten; fehlt eine, zeigt
  die Instanz das im Formular und im Status)
- Gerät und Symcon im selben Netzsegment (mDNS für die Suche); das Gerät muss von Symcon aus über
  TCP-Port 7000 erreichbar sein, für den Ton öffnet Symcon während der Durchsage UDP-Ports

## Einrichtung

1. Modulverwaltung → Bibliothek hinzufügen: `https://github.com/DG65/AirPlaySteuerung`
2. Instanz **AirPlay-Konfigurator** anlegen, Liste prüfen, beim gewünschten HomePod „Erstellen".
3. Im **AirPlay-Gerät** „🔗 Verbindung testen (ohne Ton)" und danach „🔔 Gong abspielen".

## Verwendung in Skripten

```php
// Durchsage aus einem Medienobjekt (Typ Ton, WAV) mit der eingestellten Lautstärke
AIRP_AnnounceMedia(12345 /* AirPlay-Gerät */, 23456 /* Medienobjekt */);

// mit eigener Lautstärke (0–100 %, -1 = Einstellung der Instanz)
AIRP_AnnounceMediaEx(12345, 23456, 40);

// WAV-Bytes direkt, z. B. aus einem TTS-Modul
AIRP_AnnounceWave(12345, $wavBytes);

AIRP_PlayChime(12345);        // Gong
AIRP_TestConnection(12345);   // nur koppeln, ohne Ton
```

Alle Funktionen liefern `true` oder `false`, das Ergebnis steht zusätzlich im Panel „Durchsagen".
Der Aufruf kehrt erst zurück, wenn die Durchsage fertig ist (Dauer + etwa 1,5 s).

**Audioformat:** WAV (PCM), beliebige Abtastrate, Mono oder Stereo, 8/16/24/32 Bit, höchstens
120 Sekunden. Das Modul rechnet selbst auf 44,1 kHz Stereo um. MP3/AAC werden noch nicht unterstützt.

## Grenzen dieser Version

- Eine Durchsage unterbricht, was der HomePod gerade spielt. Musik läuft danach **nicht** von selbst
  weiter, und die Lautstärke bleibt auf dem Durchsage-Wert. Beides braucht den Fernsteuerkanal (MRP),
  der in einer späteren Version kommt.
- Geräte anderer Hersteller mit AirPlay 2 (z. B. AV-Receiver) stehen auf „⚠️ ungeprüft": Sie bieten
  die nötige Kopplung, ausprobiert hat es aber noch niemand. Rückmeldungen sind willkommen.
- Stereopaare und Gruppen mehrerer HomePods sind noch nicht geprüft.

## Wie es funktioniert

Das Modul spricht AirPlay 2 selbst: Kopplung ohne PIN-Eingabe (SRP-6a, 3072 Bit), verschlüsselte
RTSP-Sitzung (ChaCha20-Poly1305), Audiostrom als rohes PCM in Echtzeit. Die Verbindungen bestehen
nur während einer Durchsage. Der Ablauf ist nach der freien Bibliothek
[pyatv](https://github.com/postlund/pyatv) (MIT) in PHP nachgebaut, siehe
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Abgrenzung

Für die **Apple-TV-Steuerung** gibt es im Symcon-Store das Modul **„AppleTV" von KaiS**
([Forum](https://community.symcon.de/t/52341)). Es bindet pyatv über die pyatv-mqtt-bridge an und ist
eine gute Wahl, wenn ohnehin ein MQTT-Server und Docker/Python laufen. AirPlay-Steuerung verfolgt einen
anderen Weg: alles in PHP innerhalb von Symcon, mit Durchsagen auf dem HomePod als Kern.

Siri-Steuerung von Symcon-Geräten läuft weiterhin über die HomeKit-Brücke von Symcon; der HomePod mini
als Thread-Border-Router für Matter braucht dieses Modul nicht.

## Lizenz

PolyForm Noncommercial 1.0.0 — frei für private und nicht-kommerzielle Nutzung, siehe [LICENSE](LICENSE).

AirPlay, HomePod und Apple TV sind Marken der Apple Inc. Dieses Modul steht in keiner Verbindung zu
Apple und wird von Apple nicht unterstützt.
