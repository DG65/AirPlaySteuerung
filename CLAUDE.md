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

- `AirPlayKrypto` (bplist, TLV8, SRP, ChaCha20, HKDF) → `AirPlayVerbindung` (TCP + HAP-Rahmen) → `AirPlayRtsp`
  (HTTP/RTSP, CSeq, Warte-Haken) → `AirPlayKopplung` (transient, liefert K) → `AirPlayAudioStrom` (SETUP, Echtzeit-
  PCM, Nebenkanäle) → `AirPlaySitzung` (Fassade). Alles ohne Symcon, prüfbar mit `.tools/test-airplay.php`.
- Fehler der Bibliotheken als `AirPlayFehler` (englische Vorlage + Werte), übersetzt im Modul über
  `AirPlayText::fehler()`. Texte: `.tools/locale-de.php` erzeugt alle locale.json, `.tools/texte-sammeln.php` prüft.
- MRP (Stufe 2) kommt als eigener Baustein neben `AirPlayAudioStrom` und nutzt Kopplung + Steuerverbindung.

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

## Geräteerkennung (`libs/AirPlayGeraete.php`)

- Aus TXT von `_airplay._tcp`: flags 0x8/0x200 → PIN nötig (LG OLED C9: 0x644), acl=1, act=2, pw/0x80,
  features Bit 43/48 = Kopplung ohne PIN möglich; fehlen beide → nur AirPlay 1 (Apple TV 3).
- Testgeräte: HomePod (1. Gen.), HomePod mini, Apple TV 3, LG OLED C9, Marantz-AV-Receiver. Kein tvOS-Apple-TV
  vorhanden → Companion/MRP fürs Apple TV nur nach Vorlage, Test über Forum-Tester.

## Offen / nächste Stufen

- MRP (Fernsteuerkanal über AirPlay): Zustand vor der Durchsage lesen, danach Musik fortsetzen und
  Lautstärke zurück; Play/Pause, Titel, Cover. Prüfauftrag: Apple-Music-Playlist starten.
- Wiedergabe per URL (`/play`): MP3/AAC und Internetradio, das Gerät dekodiert selbst.
- Temperatur/Feuchte HomePod mini über HomeKit-Brücke (Stufe 3).
