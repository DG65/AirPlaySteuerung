# AirPlay-Steuerung

Durchsagen auf dem HomePod, Anzeige und Steuerung der Wiedergabe — direkt aus Symcon, in reinem PHP. Ohne Home Assistant, ohne Python,
ohne Docker, ohne Zusatzsoftware auf dem Symcon-Rechner.

[English version](README.en.md)

> **Status: Beta 0.12.0.** Durchsagen, Fortsetzen danach, Anzeige und Steuerung sind an HomePod
> (1. Generation) und HomePod mini geprüft. Apple TV mit tvOS ist nach derselben Vorlage gebaut, aber
> noch nicht an echter Hardware getestet. Bisher nur im Prüfstand, noch nicht im Alltag erprobt: Wecker, geplante
> Ansagen, Nachtgrenze, Gruppen, Anwesenheit und die Push-Mitteilung von AirPlay-Klima. Die Online-Stimmen (Azure,
> ElevenLabs, Google) sind ohne echten Schlüssel nicht geprüft — Rückmeldungen dazu sind besonders willkommen.

## Ziele und Abgrenzung

**Wofür das Modul da ist:** HomePods in Symcon nutzbar machen — Durchsagen und Sprachausgabe, geplante Ansagen,
Wecker, Anzeige und Steuerung der Musik, Temperatur und Luftfeuchte des HomePod mini. Alles in PHP innerhalb von
Symcon, ohne Zusatzsoftware.

**Was es ausdrücklich nicht werden soll:** eine Apple-TV-Fernbedienung. Ein Apple TV kann wie jeder AirPlay-Empfänger
Durchsagen abspielen und zeigt an, was läuft; Einschalten, Fernbedienungstasten, Apps oder Eingänge sind nicht
vorgesehen — auch nicht in späteren Versionen.

**Für die Apple-TV-Steuerung** gibt es im Symcon-Store das Modul **„Apple TV" von KaiS**
([Forum](https://community.symcon.de/t/modul-appletv/52341), [Dokumentation](https://deinsmarthome.info/module/apple-tv/)).
Es bindet ein Apple TV über MQTT an — mit Einschalten, Fernbedienungstasten und Wiedergabe — und ist die richtige
Wahl, wenn ein Apple TV vollständig gesteuert werden soll. Beide Module lassen sich nebeneinander betreiben. Ich
empfehle es hier bewusst und verstehe AirPlay-Steuerung als Ergänzung, nicht als Ersatz.

Siri-Steuerung von Symcon-Geräten läuft weiterhin über die HomeKit-Brücke von Symcon; der HomePod mini als
Thread-Border-Router für Matter braucht dieses Modul nicht.

## Was das Modul kann

- **Durchsagen:** Eine WAV-Datei aus einem Symcon-Medienobjekt (oder als Bytes, z. B. aus einem
  TTS-Modul) wird per AirPlay 2 an einen HomePod gestreamt. Lautstärke für Durchsagen einstellbar.
- **Fortsetzen:** Lief vor der Durchsage Musik, geht sie danach weiter, und die Lautstärke wird
  zurückgestellt.
- **Anzeige:** Zustand, Titel, Interpret, Album, App, Lautstärke und Cover als Variablen bzw.
  Medienobjekt, per Abfrage im einstellbaren Abstand (Standard 15 s).
- **Steuerung:** Abspielen, Pause, nächster/vorheriger Titel und Lautstärke — über die Variablen im
  WebFront oder per Skript.
- **Apple Music starten:** Teilen-Link einer Playlist oder eines Albums einfügen — der HomePod spielt
  die Titel über sein eigenes Apple-Music-Konto, auf Wunsch zufällig.
- **Sprachausgabe:** `AIRP_Say(id, 'Es ist 14 Uhr')` liest Text vor. Die Stimme kommt aus der Instanz
  **AirPlay-Sprachausgabe**: Piper im eigenen Netz (kostenlos, ohne Internet) oder ein Online-Dienst — Microsoft
  Azure, ElevenLabs, Google Cloud. Gleiche Texte werden zwischengespeichert (Abschnitt unten).
- **Geplante Ansagen:** Liste im Formular — jede volle oder halbe Stunde (z. B. „Es ist {uhrzeit}." von 7 bis 22 Uhr)
  oder täglich zu einer Uhrzeit, jeden Tag, Mo–Fr oder Sa–So, auf Wunsch mit Gong davor.
- **Wecker:** startet zur eingestellten Zeit eine Apple-Music-Playlist leise und hebt die Lautstärke über einige
  Minuten an, danach auf Wunsch ein Gruß („Guten Morgen, es ist {uhrzeit}").
- **Klänge:** Gong, Türklingel, Hinweiston und Alarm — rein synthetisch, ohne Audiodatei; als Vorspann vor Ansagen
  wählbar.
- **Nachtgrenze:** In einstellbaren Stunden spielt keine Durchsage lauter als X % — auch nicht von WarnHub, Automat
  oder dem Zeitplan.
- **Anwesenheit:** Mit einer Anwesenheitsvariable entfallen geplante Ansagen und Wecker, solange niemand zu Hause
  ist — auf Wunsch auch Durchsagen anderer Module (WarnHub, Automat). Ausgelassene stehen im Protokoll.
- **Mehrere HomePods:** Gruppen je Gerät („Erdgeschoss, Küche"); `AIRPS_SayGroup` spricht auf allen Geräten einer
  Gruppe.
- **Player-Kachel:** Cover (Größe einstellbar oder ausgeblendet), Titel, ⏮ ⏯ ⏭, Lautstärke, Favoriten-Playlists,
  die nächste geplante Ansage bzw. der nächste Wecker, auf Wunsch Temperatur und Luftfeuchte (z. B. von AirPlay-Klima)
  und ein Feld für schnelle Ansagen. Ab Symcon 9.1 hält sie Platz für den Kacheltitel frei.
- **Durchsage-Protokoll:** die letzten 20 Durchsagen mit Quelle (Skript, Warteschlange, Zeitplan, Wecker, Kachel)
  und Ergebnis im Formular.
- **AirPlay-Klima:** Temperatur und Luftfeuchte von HomePod mini und HomePod (2. Generation) als echte
  Variablen — über eine Apple-Home-Automation, die Symcon über die HomeKit-Brücke im eingestellten Abstand
  anstößt (Anleitung im Formular, Abschnitt unten). Bleiben die Werte aus, meldet die Instanz das einmal im
  Meldungen-Fenster und auf Wunsch als Push-Mitteilung an eine Kachel-Visualisierung oder ein WebFront.
- **AirPlay-Konfigurator:** Findet HomePods, Apple TVs und andere AirPlay-Empfänger im Netz und zeigt
  in der Spalte „Durchsage", welche Geräte Durchsagen abspielen können. Geräte, die eine PIN-Kopplung
  verlangen (z. B. viele Fernseher), werden angezeigt, aber nicht zum Anlegen angeboten.

## Voraussetzungen

- Symcon ab 9.0
- PHP-Erweiterungen `gmp` und `sodium` (bei aktuellen Symcon-Versionen enthalten; fehlt eine, zeigt
  die Instanz das im Formular und im Status)
- Das Gerät muss von Symcon aus über TCP-Port 7000 erreichbar sein; für den Ton öffnet Symcon während der Durchsage
  UDP-Ports. Die Suche im Konfigurator (mDNS) reicht nur bis zum eigenen Netzsegment, Geräte in anderen VLANs werden
  per IP-Adresse hinzugefügt (siehe Einrichtung).

## Einrichtung

1. Module Store: in der Verwaltungskonsole nach **AirPlay-Steuerung** suchen und installieren. Das Modul steht im
   Bereich **Beta** und wird nur mit genauem Namen gefunden.
2. Instanz **AirPlay-Konfigurator** anlegen, Liste prüfen, beim gewünschten HomePod „Erstellen".
3. Im **AirPlay-Gerät** „🔗 Verbindung testen (ohne Ton)" und danach „🔔 Gong abspielen".

**HomePod in einem anderen VLAN** (oder hinter VPN, Symcon in Docker ohne Host-Netz): Die Suche findet ihn nicht,
weil mDNS im eigenen Netzsegment bleibt. Im Konfigurator unter der Liste die IP-Adresse des HomePods eintragen und
„➕ Hinzufügen" klicken. Der Konfigurator fragt das Gerät direkt und zeigt es danach wie ein gefundenes an, mit
„Erstellen". Eine feste Adresse (DHCP-Reservierung) ist sinnvoll. Die Firewall zwischen den VLANs muss Verkehr
zwischen Symcon und dem HomePod in beide Richtungen erlauben: TCP 7000 und wechselnde TCP-/UDP-Ports, die der HomePod
je Sitzung zuweist; während einer Durchsage schickt der HomePod außerdem Zeitanfragen per UDP an Symcon. Am
einfachsten ist eine Regel, die zwischen der Symcon-IP und den HomePod-IPs allen Verkehr erlaubt.

## Verwendung in Skripten

```php
// Durchsage aus einem Medienobjekt (Typ Ton, WAV) mit der eingestellten Lautstärke
AIRP_AnnounceMedia(12345 /* AirPlay-Gerät */, 23456 /* Medienobjekt */);

// mit eigener Lautstärke (0–100 %, -1 = Einstellung der Instanz)
AIRP_AnnounceMediaEx(12345, 23456, 40);

// WAV-Bytes direkt, z. B. aus einem TTS-Modul
AIRP_AnnounceWave(12345, $wavBytes);

// Text vorlesen (Piper-Server im Panel „Sprachausgabe"), mit Lautstärke
AIRP_Say(12345, 'Es ist ' . date('G') . ' Uhr.');
AIRP_SayEx(12345, 'Die Waschmaschine ist fertig.', 40);
// ohne Warten: einreihen und sofort zurück, die Instanz spricht der Reihe nach (true = Gong davor)
AIRP_SayAsync(12345, 'Die Haustür ist offen.', -1, true);
// Platzhalter: {uhrzeit}, {wochentag}, {datum}, {var:ID} (Wert mit Einheit)
AIRP_Say(12345, 'Guten Morgen, heute ist {wochentag}, der {datum}. Draußen sind {var:23456}.');

AIRP_PlaySound(12345, 'doorbell');   // Klang: gong, doorbell, notice, alarm
AIRP_PlayChime(12345);               // Gong
AIRP_TestConnection(12345);          // nur koppeln, ohne Ton

// Gruppe: alle Geräte der Sprachausgabe-Instanz 34567 mit Gruppe „Erdgeschoss" (leer = alle)
AIRPS_SayGroup(34567, 'Erdgeschoss', 'Das Essen ist fertig.', -1, true);

// Steuerung
AIRP_Play(12345); AIRP_Pause(12345); AIRP_Stop(12345);
AIRP_Next(12345); AIRP_Previous(12345);
AIRP_SetVolume(12345, 25);    // 0–100 %
AIRP_Update(12345);           // Anzeige sofort auffrischen

// Apple Music: Teilen-Link einer Playlist/eines Albums, Song-IDs oder Name eines Favoriten, true = zufällig
AIRP_PlayPlaylist(12345, 'https://music.apple.com/de/playlist/…/pl.u-…', true);
AIRP_PlayPlaylist(12345, 'Morgenmusik', true);
```

**Apple Music:** Das Modul liest die Titelliste von der öffentlichen Apple-Music-Seite des Links und
übergibt sie dem HomePod als Warteschlange (höchstens 300 Titel). Gespielt wird eine Kopie: Ändert sich
die Playlist später, ruft man `AIRP_PlayPlaylist` einfach erneut auf. Der HomePod braucht ein
Apple-Music-Abo.

Alle Funktionen liefern `true` oder `false`, das Ergebnis steht zusätzlich im Panel „Durchsagen".
Eine Durchsage kehrt erst zurück, wenn sie fertig ist (Dauer + etwa 1,5 s, mit Fortsetzen + etwa
3–5 s).

**Audioformat:** WAV (PCM), beliebige Abtastrate, Mono oder Stereo, 8/16/24/32 Bit, höchstens
120 Sekunden. Das Modul rechnet selbst auf 44,1 kHz Stereo um. MP3/AAC werden noch nicht unterstützt.

## Sprachausgabe

Für gesprochene Durchsagen braucht es einen Sprachdienst, der aus Text Ton macht. Das übernimmt eine eigene
Instanz **AirPlay-Sprachausgabe**; alle HomePods können dieselbe benutzen.

**Einrichten in vier Schritten:**

1. Instanz **AirPlay-Sprachausgabe** anlegen.
2. Panel „🗣️ Sprachdienst" → **Dienst** wählen → „Änderungen übernehmen". Danach erscheinen die passenden Felder.
3. Je nach Dienst den Piper-Server bzw. den API-Schlüssel eintragen (Anleitungen unten), optional eine **Stimme**
   (leer = Standard des Dienstes), dann „▶️ Sprache erzeugen" — kommt eine Erfolgsmeldung, steht der Dienst.
4. In jedem **AirPlay-Gerät** im Panel „🗣️ Sprachausgabe" unter **Sprachausgabe-Instanz** diese Instanz wählen und übernehmen.

| Dienst | Kosten | Internet | Klang |
|---|---|---|---|
| **Piper** | kostenlos | nur einmal zum Laden der Stimme | gut, etwas technisch |
| **Microsoft Azure** | Tarif F0: 500.000 Zeichen im Monat frei | ja | sehr natürlich |
| **ElevenLabs** | etwa 10.000 Zeichen im Monat frei | ja | sehr natürlich, viele Stimmen |
| **Google Cloud** | Freikontingent je Stimmtyp, Abrechnungskonto nötig | ja | natürlich |

Die Freikontingente legen die Anbieter selbst fest und ändern sie gelegentlich — vor der Einrichtung dort nachsehen.
Die Menüs der Anbieter-Webseiten ändern sich ebenfalls ab und zu; die Wege unten beschreiben den Stand vom November 2026.

**API-Schlüssel** gibt man in das Feld „API-Schlüssel" ein und klickt „🔑 Schlüssel speichern". Der Schlüssel wird
getrennt von den Einstellungen abgelegt, nicht wieder angezeigt und nicht mit der Konfiguration exportiert. Zum
Wechseln einfach einen neuen speichern.

### Piper (lokal, kostenlos)

[Piper](https://github.com/OHF-Voice/piper1-gpl) ist eine freie Sprachausgabe mit natürlich klingenden Stimmen, die im
eigenen Netz läuft — kein Konto, keine Cloud. Piper läuft als eigener Dienst (Wyoming-Protokoll, TCP 10200), z. B.
als Docker-Container auf einem NAS, Server oder Raspberry Pi; Symcon schickt nur den Text hin und bekommt den Ton
zurück. Auf dem Symcon-Rechner wird nichts installiert.

**Container in Portainer anlegen:** Stacks → „Add stack", Name `piper`, in den Web-Editor:

```yaml
services:
  piper:
    image: rhasspy/wyoming-piper:latest
    container_name: piper
    command: --voice de_DE-thorsten-high
    ports:
      - "10200:10200"
    volumes:
      - piper-data:/data
    restart: unless-stopped
volumes:
  piper-data:
```

„Deploy the stack". Beim ersten Start lädt der Container die Stimme einmalig herunter (gut 100 MB, dafür braucht er
kurz Internet). Ohne Portainer genügt `docker compose up -d` mit derselben Datei, oder in einer Zeile:

```bash
docker run -d --name piper --restart unless-stopped -p 10200:10200 -v piper-data:/data rhasspy/wyoming-piper --voice de_DE-thorsten-high
```

Das Image gibt es für Intel/AMD (amd64) und ARM (arm64, z. B. Raspberry Pi 4/5 oder viele NAS).

**In der Sprachausgabe-Instanz:** Dienst „Piper (eigenes Netz, kostenlos)" → übernehmen → **Piper-Server** = IP-Adresse
des Docker-Rechners (Port 10200 ist Standard, ein anderer als `host:port`) → übernehmen → „▶️ Sprache erzeugen".
„🔍 Stimmen anzeigen" listet die installierten Stimmen.

**Stimmen:** Deutsch z. B. `de_DE-thorsten-high` (männlich, beste Qualität), `de_DE-thorsten-medium` (schneller, für
schwache Rechner wie einen Raspberry Pi), `de_DE-kerstin-low` oder `de_DE-ramona-low` (weiblich). Weitere Stimmen
brauchen keinen Eintrag im Container: Er lädt jede Stimme, die im Feld „Stimme" steht, beim ersten Gebrauch nach.
Hörproben: [rhasspy.github.io/piper-samples](https://rhasspy.github.io/piper-samples/).

Den Container [rhasspy/wyoming-piper](https://github.com/rhasspy/wyoming-piper) nutzt auch Home Assistant; ein
vorhandener Piper-Dienst von dort kann ebenso eingetragen werden.

### Microsoft Azure

1. Auf [portal.azure.com](https://portal.azure.com) anmelden (ein kostenloses Microsoft-Konto genügt; Azure fragt bei
   der Anmeldung nach einer Kreditkarte zur Identitätsprüfung, der Tarif F0 kostet nichts).
2. „Ressource erstellen" → nach **Speech** suchen → **Speech service** (Azure AI Speech) → „Erstellen".
3. Ressourcengruppe anlegen, **Region** wählen (z. B. *Germany West Central*), Name frei, **Tarif Free F0** → „Überprüfen
   und erstellen" → „Erstellen".
4. Ressource öffnen → „Schlüssel und Endpunkt": **Schlüssel 1** kopieren und den **Speicherort/Region** ablesen
   (z. B. `germanywestcentral`).
5. In der Sprachausgabe-Instanz: Dienst „Microsoft Azure" → übernehmen → **Azure-Region** eintragen → übernehmen →
   Schlüssel 1 einfügen → „🔑 Schlüssel speichern" → „▶️ Sprache erzeugen".

Stimme leer = `de-DE-KatjaNeural`; männlich z. B. `de-DE-ConradNeural`. „🔍 Stimmen anzeigen" listet alle deutschen
Stimmen. Meldet Azure „Schlüssel abgelehnt", passen Schlüssel und Region nicht zusammen.

### ElevenLabs

1. Auf [elevenlabs.io](https://elevenlabs.io) ein Konto anlegen (der kostenlose Tarif genügt zum Ausprobieren).
2. Im Konto den Bereich **API Keys** öffnen → „Create API Key". Werden die Rechte eingeschränkt, braucht das Modul
   Text-to-Speech und das Lesen der Stimmen. Den Schlüssel sofort kopieren, er wird nur einmal angezeigt.
3. In der Sprachausgabe-Instanz: Dienst „ElevenLabs" → übernehmen → Schlüssel einfügen → „🔑 Schlüssel speichern".
4. „🔍 Stimmen anzeigen" listet die Stimmen des Kontos mit ihrer ID. Eine **Stimmen-ID** in das Feld „Stimme" kopieren
   (ElevenLabs braucht immer eine) → übernehmen → „▶️ Sprache erzeugen".

Weitere Stimmen aus der *Voice Library* von ElevenLabs erst dort zu „My Voices" hinzufügen, dann erscheinen sie in der
Liste. Das **ElevenLabs-Modell** steht auf `eleven_flash_v2_5`: spricht Deutsch und verbraucht nur halb so viel
Kontingent wie `eleven_multilingual_v2` (etwas natürlicher, auch möglich).

### Google Cloud

1. In der [Google Cloud Console](https://console.cloud.google.com) ein Projekt anlegen.
2. Für das Projekt ein **Abrechnungskonto** einrichten (Pflicht, auch wenn man im Freikontingent bleibt).
3. „APIs und Dienste" → „Bibliothek" → **Cloud Text-to-Speech API** → „Aktivieren".
4. „APIs und Dienste" → „Anmeldedaten" → „Anmeldedaten erstellen" → **API-Schlüssel**. Den Schlüssel anschließend
   bearbeiten und unter „API-Einschränkungen" auf die *Cloud Text-to-Speech API* beschränken.
5. In der Sprachausgabe-Instanz: Dienst „Google Cloud" → übernehmen → Schlüssel einfügen → „🔑 Schlüssel speichern" →
   „▶️ Sprache erzeugen".

Stimme leer = deutsche Standardstimme von Google. „🔍 Stimmen anzeigen" listet alle deutschen Stimmen; die Namen
verraten den Typ (Standard, WaveNet, Neural2, Chirp …), die Freikontingente gelten je Typ.

### Zwischenspeicher

Der Zwischenspeicher (Panel „💾 Zwischenspeicher") spielt gleiche Texte mit gleichem Dienst und gleicher Stimme aus
Symcon ab, statt sie neu zu erzeugen: Die stündliche Zeitansage kostet so nur am ersten Tag Kontingent und klingt
auch ohne Internet. Standard sind höchstens 100 Aufnahmen; „🗑️ Zwischenspeicher leeren" löscht alle.

## Temperatur und Luftfeuchte (AirPlay-Klima)

Apple gibt die Sensorwerte der HomePods nicht über das Netzwerk heraus. Der Weg führt deshalb über Apple Home:

1. Instanz **AirPlay-Klima** anlegen. Sie hat eine Variable „Messung anstoßen", die sie im eingestellten
   Abstand (Standard 10 min) kurz einschaltet.
2. In der Instanz **HomeKit Bridge** von Symcon in der Liste „Geräte" ein Gerät vom Typ „Zwischenstecker"
   mit dieser Variable anlegen.
3. In der Home-App: Automation → „Ein Gerät wird gesteuert" → der Schalter → „Wird eingeschaltet" →
   „In Kurzbefehl umwandeln". Darin je HomePod zweimal den Status abrufen (Temperatursensor → Aktuelle
   Temperatur, Feuchtigkeitssensor → Akt. relative Luftfeuchtigkeit) und einmal „Inhalte von URL abrufen" mit der
   URL aus dem Formular, Methode **POST**, Anfragetext **JSON** mit den Feldern `name`, `temperature` und
   `humidity`. Die genaue Schritt-für-Schritt-Anleitung steht im Formular der Instanz.
   Die Werte nicht an die URL anhängen: Der Kurzbefehl setzt sie mit Leerzeichen ein („22,4 °C"), die URL endet
   dort und die Luftfeuchte geht verloren.
4. Beim ersten Empfang legt die Instanz je HomePod „<Name> Temperatur" und „<Name> Luftfeuchte" an.

Die URL enthält einen geheimen Schlüssel. Die Automation braucht eine Steuerzentrale (HomePod oder Apple TV).
Nach iOS-Updates laufen Home-Automationen erfahrungsgemäß manchmal nicht mehr an; dann hilft
Aus- und Wiedereinschalten. Die Idee stammt aus [homepod-ha-sensors](https://github.com/vdamboeck/homepod-ha-sensors)
für Home Assistant.

## Grenzen dieser Version

- Die Anzeige wird abgefragt, nicht laufend gemeldet: Änderungen erscheinen mit bis zu einem
  Abfrage-Abstand Verzögerung. Eine Abfrage hält einen PHP-Thread etwa 2 Sekunden.
- Apple TV (tvOS): Anzeige und Steuerung der Wiedergabe sind nach Vorlage gebaut, aber ungeprüft. Ein/Aus,
  Fernbedienungstasten und Apps sind nicht vorgesehen (siehe „Ziele und Abgrenzung").
- Geräte anderer Hersteller mit AirPlay 2 (z. B. AV-Receiver) stehen auf „⚠️ ungeprüft": Sie bieten
  die nötige Kopplung, ausprobiert hat es aber noch niemand. Rückmeldungen sind willkommen.
- Stereopaare sind noch nicht geprüft. Gruppen-Durchsagen laufen auf allen Geräten gleichzeitig, aber nicht
  taktgenau synchron (jedes Gerät bekommt seinen eigenen Strom).

## Wie es funktioniert

Das Modul spricht AirPlay 2 selbst: Kopplung ohne PIN-Eingabe (SRP-6a, 3072 Bit), verschlüsselte
RTSP-Sitzung (ChaCha20-Poly1305), Audiostrom als rohes PCM in Echtzeit. Anzeige und Steuerung laufen über das Media Remote
Protocol (MRP) im AirPlay-Tunnel. Verbindungen bestehen nur für die Dauer einer Aktion. Der Ablauf ist nach der freien Bibliothek
[pyatv](https://github.com/postlund/pyatv) (MIT) in PHP nachgebaut, siehe
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Rückmeldungen

Fragen, Erfahrungen, Wünsche und Fehlerberichte bitte im
[Forum-Thread zur AirPlay-Steuerung](https://community.symcon.de/t/beta-modul-airplay-steuerung-durchsagen-wecker-und-player-kachel-fuer-homepods-direkt-ueber-airplay-2/145020).

## Lizenz

PolyForm Noncommercial 1.0.0 — frei für private und nicht-kommerzielle Nutzung, siehe [LICENSE](LICENSE).

AirPlay, HomePod und Apple TV sind Marken der Apple Inc. Dieses Modul steht in keiner Verbindung zu
Apple und wird von Apple nicht unterstützt.
