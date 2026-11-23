# AirPlay-Steuerung

Announcements on your HomePod, plus now-playing display and playback control — straight from Symcon, in pure PHP. No Home Assistant, no Python,
no Docker, no additional software on the Symcon host.

[Deutsche Version](README.md)

> **Status: beta 0.11.4.** Announcements, resuming afterwards, display and control have been tested on
> HomePod (1st generation) and HomePod mini. Apple TV with tvOS follows the same reference, but has not
> been tested on real hardware yet. So far only covered by the test bench, not yet proven in daily use: alarm clock,
> scheduled announcements, night limit, groups, presence and the push notification of AirPlay-Klima. The online voices
> (Azure, ElevenLabs, Google) have not been tested with real keys — feedback on these is especially welcome.

## Goals and scope

**What the module is for:** making HomePods usable in Symcon — announcements and speech output, scheduled
announcements, alarm clock, now-playing display and music control, temperature and humidity of the HomePod mini.
Everything in PHP inside Symcon, without additional software.

**What it is explicitly not meant to become:** an Apple TV remote. Like any AirPlay receiver, an Apple TV can play
announcements and shows what is playing; power, remote buttons, apps or inputs are not planned — not in later
versions either.

**For Apple TV control**, the Symcon store offers the module **"Apple TV" by KaiS**
([forum](https://community.symcon.de/t/modul-appletv/52341), [documentation](https://deinsmarthome.info/module/apple-tv/)).
It connects an Apple TV via MQTT — with power, remote buttons and playback — and is the right choice if you want to
control an Apple TV completely. Both modules can run side by side. I recommend it here on
purpose and see AirPlay-Steuerung as a complement, not a replacement.

Siri control of Symcon devices still runs through Symcon's HomeKit bridge; the HomePod mini as a Thread border
router for Matter does not need this module.

## Features

- **Announcements:** a WAV file from a Symcon media object (or as raw bytes, e.g. from a TTS module) is
  streamed to a HomePod via AirPlay 2, with a configurable announcement volume.
- **Resume:** if music was playing before the announcement, it continues afterwards and the volume is
  restored.
- **Display:** state, title, artist, album, app, volume and artwork as variables and a media object,
  polled at an adjustable interval (default 15 s).
- **Control:** play, pause, next/previous track and volume — through the variables in the WebFront or
  from scripts.
- **Start Apple Music:** paste the share link of a playlist or album — the HomePod plays the songs from
  its own Apple Music account, optionally shuffled.
- **Speech output:** `AIRP_Say(id, 'It is 2 pm')` reads text aloud. The voice comes from the **AirPlay-Sprachausgabe**
  instance: Piper in your own network (free, offline) or an online service — Microsoft Azure, ElevenLabs, Google
  Cloud. Identical texts are cached (section below).
- **Scheduled announcements:** list in the form — every full or half hour (e.g. "It is {time}." from 7 am to
  10 pm) or daily at a set time, every day, Mon–Fri or Sat–Sun, optionally with a chime first.
- **Alarm clock:** starts an Apple Music playlist quietly at the set time and raises the volume over a few minutes,
  followed by an optional greeting ("Good morning, it is {time}").
- **Sounds:** chime, doorbell, notice and alarm — purely synthetic, no audio file; selectable as a lead-in.
- **Night limit:** during set hours, no announcement plays louder than X % — including WarnHub, Automat and the
  schedule.
- **Presence:** with a presence variable, scheduled announcements and the alarm clock are skipped while nobody is
  at home — on request also announcements from other modules (WarnHub, Automat). Skipped ones appear in the log.
- **Several HomePods:** groups per device ("Ground floor, Kitchen"); `AIRPS_SayGroup` speaks on all devices of a
  group.
- **Player tile:** artwork (adjustable size or hidden), title, ⏮ ⏯ ⏭, volume, favorite playlists, the next scheduled
  announcement or alarm, optionally temperature and humidity (e.g. from AirPlay-Klima) and a field for quick
  announcements. From Symcon 9.1 it leaves room for the tile title.
- **Announcement log:** the last 20 announcements with source (script, queue, schedule, alarm clock, tile) and
  result in the form.
- **AirPlay-Klima:** temperature and humidity of HomePod mini and HomePod (2nd generation) as real
  variables — through an Apple Home automation that Symcon triggers via its HomeKit bridge at a set interval
  (guide in the form and below). If values stop arriving, the instance reports it once in the message window and,
  on request, as a push notification to a tile visualization or WebFront.
- **AirPlay configurator:** finds HomePods, Apple TVs and other AirPlay receivers on the network. The
  column "Announcement" shows which devices can play announcements. Devices that require pairing with a
  PIN (e.g. many TVs) are listed but cannot be created.

## Requirements

- Symcon 9.0 or later
- PHP extensions `gmp` and `sodium` (included in current Symcon versions; if one is missing, the
  instance shows it in its form and status)
- Device and Symcon on the same network segment (mDNS for the search); the device must be reachable
  from Symcon on TCP port 7000, and Symcon opens UDP ports for the duration of an announcement

## Setup

1. Module Store: search for **AirPlay Control** in the management console and install it. The module is listed under
   **Beta** and is only found by its exact name.
2. Create an **AirPlay configurator** instance, check the list, click "Create" for the HomePod.
3. In the **AirPlay device**, click "🔗 Test connection (no sound)", then "🔔 Play chime".

## Scripting

```php
// Announcement from a media object (type sound, WAV) at the configured volume
AIRP_AnnounceMedia(12345 /* AirPlay device */, 23456 /* media object */);

// with an explicit volume (0–100 %, -1 = instance setting)
AIRP_AnnounceMediaEx(12345, 23456, 40);

// WAV bytes directly, e.g. from a TTS module
AIRP_AnnounceWave(12345, $wavBytes);

// Read text aloud (Piper server in the panel "Speech output"), with volume
AIRP_Say(12345, 'It is ' . date('g A') . '.');
AIRP_SayEx(12345, 'The washing machine has finished.', 40);
// without waiting: queue and return at once, the instance speaks one after the other (true = chime first)
AIRP_SayAsync(12345, 'The front door is open.', -1, true);
// Placeholders: {time}, {weekday}, {date}, {var:ID} (value with unit)
AIRP_Say(12345, 'Good morning, today is {weekday}, {date}. Outside it is {var:23456}.');

AIRP_PlaySound(12345, 'doorbell');   // sound: gong, doorbell, notice, alarm
AIRP_PlayChime(12345);               // chime
AIRP_TestConnection(12345);          // pair only, no sound

// Group: all devices of speech output instance 34567 in group "Ground floor" (empty = all)
AIRPS_SayGroup(34567, 'Ground floor', 'Dinner is ready.', -1, true);

// Control
AIRP_Play(12345); AIRP_Pause(12345); AIRP_Stop(12345);
AIRP_Next(12345); AIRP_Previous(12345);
AIRP_SetVolume(12345, 25);    // 0–100 %
AIRP_Update(12345);           // refresh the display now

// Apple Music: share link of a playlist/album or song IDs, true = shuffle
AIRP_PlayPlaylist(12345, 'https://music.apple.com/us/playlist/…/pl.u-…', true);
AIRP_PlayPlaylist(12345, 'Morning music', true);   // name of a favorite
```

**Apple Music:** the module reads the song list from the public Apple Music page of the link and hands it
to the HomePod as a queue (at most 300 songs). It plays a copy: if the playlist changes later, simply call
`AIRP_PlayPlaylist` again. The HomePod needs an Apple Music subscription.

All functions return `true` or `false`; the result is also shown in the panel "Announcements". An announcement
returns once it has finished (duration plus about 1.5 s, with resume plus about 3–5 s).

**Audio format:** WAV (PCM), any sample rate, mono or stereo, 8/16/24/32 bit, at most 120 seconds. The
module converts it to 44.1 kHz stereo itself. MP3/AAC are not supported yet.

## Speech output

Spoken announcements need a speech service that turns text into sound. This is done by a separate
**AirPlay-Sprachausgabe** (speech output) instance; all HomePods can share the same one.

**Setup in four steps:**

1. Create an **AirPlay-Sprachausgabe** instance.
2. Panel "🗣️ Speech service" → choose the **Service** → "Apply changes". The matching fields then appear.
3. Depending on the service, enter the Piper server or the API key (guides below), optionally a **Voice**
   (empty = default of the service), then "▶️ Create speech" — a success message means the service works.
4. In every **AirPlay device**, panel "🗣️ Speech output", select this instance under **Speech output instance** and apply.

| Service | Cost | Internet | Sound |
|---|---|---|---|
| **Piper** | free | only once to download the voice | good, slightly technical |
| **Microsoft Azure** | tier F0: 500,000 characters per month free | yes | very natural |
| **ElevenLabs** | about 10,000 characters per month free | yes | very natural, many voices |
| **Google Cloud** | free tier per voice type, billing account required | yes | natural |

The providers set their free tiers themselves and change them now and then — check before setting up. Their web
menus also change from time to time; the steps below describe the state of November 2026.

**API keys** go into the field "API key", then click "🔑 Save key". The key is stored separately from the settings,
never shown again and not exported with the configuration. To change it, simply save a new one.

"🔍 Show voices" lists the German voices for Azure and Google. For other languages, enter the voice name directly
(e.g. `en-US-AvaNeural` for Azure); Google takes the language from the voice name.

### Piper (local, free)

[Piper](https://github.com/OHF-Voice/piper1-gpl) is a free text-to-speech engine with natural-sounding voices that
runs on your own network — no account, no cloud. Piper runs as a separate service (Wyoming protocol, TCP 10200),
e.g. as a Docker container on a NAS, server or Raspberry Pi; Symcon only sends the text and gets the sound back.
Nothing is installed on the Symcon computer.

**Create the container in Portainer:** Stacks → "Add stack", name `piper`, in the web editor:

```yaml
services:
  piper:
    image: rhasspy/wyoming-piper:latest
    container_name: piper
    command: --voice en_US-lessac-high
    ports:
      - "10200:10200"
    volumes:
      - piper-data:/data
    restart: unless-stopped
volumes:
  piper-data:
```

"Deploy the stack". On first start the container downloads the voice once (about 100 MB, it briefly needs internet
for that). Without Portainer, `docker compose up -d` with the same file is enough, or in one line:

```bash
docker run -d --name piper --restart unless-stopped -p 10200:10200 -v piper-data:/data rhasspy/wyoming-piper --voice en_US-lessac-high
```

The image is available for Intel/AMD (amd64) and ARM (arm64, e.g. Raspberry Pi 4/5 or many NAS devices).

**In the speech output instance:** service "Piper (own network, free)" → apply → **Piper server** = IP address of the
Docker host (port 10200 is the default, another one as `host:port`) → apply → "▶️ Create speech". "🔍 Show voices"
lists the installed voices.

**Voices:** English e.g. `en_US-lessac-high`, `en_GB-alan-medium`; German e.g. `de_DE-thorsten-high` (male, best
quality), `de_DE-thorsten-medium` (faster, for weak computers such as a Raspberry Pi), `de_DE-kerstin-low` or
`de_DE-ramona-low` (female). More voices need no entry in the container: it downloads any voice entered in the field
"Voice" on first use. Samples: [rhasspy.github.io/piper-samples](https://rhasspy.github.io/piper-samples/).

The container [rhasspy/wyoming-piper](https://github.com/rhasspy/wyoming-piper) is also used by Home Assistant; an
existing Piper service from there can be entered as well.

### Microsoft Azure

1. Sign in at [portal.azure.com](https://portal.azure.com) (a free Microsoft account is enough; Azure asks for a credit
   card to verify your identity at sign-up, tier F0 costs nothing).
2. "Create a resource" → search for **Speech** → **Speech service** (Azure AI Speech) → "Create".
3. Create a resource group, choose a **Region** (e.g. *West Europe*), any name, **Pricing tier Free F0** → "Review +
   create" → "Create".
4. Open the resource → "Keys and Endpoint": copy **Key 1** and read the **Location/Region** (e.g. `westeurope`).
5. In the speech output instance: service "Microsoft Azure" → apply → enter the **Azure region** → apply → paste Key 1
   → "🔑 Save key" → "▶️ Create speech".

Empty voice = German `de-DE-KatjaNeural`; English e.g. `en-US-AvaNeural` or `en-GB-SoniaNeural`. If Azure reports "key
rejected", key and region do not match.

### ElevenLabs

1. Create an account at [elevenlabs.io](https://elevenlabs.io) (the free plan is enough to try it).
2. Open **API Keys** in your account → "Create API Key". If you restrict the permissions, the module needs
   text-to-speech and read access to voices. Copy the key right away, it is shown only once.
3. In the speech output instance: service "ElevenLabs" → apply → paste the key → "🔑 Save key".
4. "🔍 Show voices" lists the voices of your account with their ID. Copy a **voice ID** into the field "Voice"
   (ElevenLabs always needs one) → apply → "▶️ Create speech".

Add further voices from the ElevenLabs *Voice Library* to "My Voices" there first; then they appear in the list. The
**ElevenLabs model** is set to `eleven_flash_v2_5`: multilingual and uses only half the quota of
`eleven_multilingual_v2` (a bit more natural, also possible).

### Google Cloud

1. Create a project in the [Google Cloud Console](https://console.cloud.google.com).
2. Set up a **billing account** for the project (required, even if you stay within the free tier).
3. "APIs & Services" → "Library" → **Cloud Text-to-Speech API** → "Enable".
4. "APIs & Services" → "Credentials" → "Create credentials" → **API key**. Then edit the key and restrict it under
   "API restrictions" to the *Cloud Text-to-Speech API*.
5. In the speech output instance: service "Google Cloud" → apply → paste the key → "🔑 Save key" → "▶️ Create speech".

Empty voice = Google's German default voice. The voice names show the type (Standard, WaveNet, Neural2, Chirp …);
the free tiers apply per type.

### Cache

The cache (panel "💾 Cache") plays identical texts with the same service and voice from Symcon instead of creating
them again: the hourly time announcement only costs quota on the first day and also works without internet. By
default at most 100 recordings are kept; "🗑️ Clear cache" deletes all of them.

## Temperature and humidity (AirPlay-Klima)

Apple does not hand out the HomePod sensor values over the network, so the route goes through Apple Home:

1. Create an **AirPlay-Klima** instance. Its variable "Trigger measurement" is switched on briefly at the set
   interval (default 10 min).
2. In Symcon's **HomeKit Bridge** instance, add a device of type "Switch" with this variable in the list
   "Devices".
3. In the Home app: Automation → "An Accessory is Controlled" → the switch → "Turns On" → "Convert to
   Shortcut". In it, for each HomePod, read the state twice (Temperature Sensor → Current Temperature, Humidity
   Sensor → Current Relative Humidity) and "Get Contents of URL" once with the URL from the form, method
   **POST**, request body **JSON** with the fields `name`, `temperature` and `humidity`. The detailed
   step-by-step guide is in the instance form.
   Do not append the values to the URL: the shortcut inserts them with a space ("22.4 °C"), the URL ends there
   and the humidity is lost.
4. On the first reception, the instance creates "<name> temperature" and "<name> humidity" per HomePod.

The URL contains a secret key. The automation needs a home hub (HomePod or Apple TV). After iOS updates,
Home automations sometimes stop running; switching them off and on again helps. The idea comes from
[homepod-ha-sensors](https://github.com/vdamboeck/homepod-ha-sensors) for Home Assistant.

## Limitations of this version

- The display is polled, not pushed: changes appear with up to one polling interval of delay. A query
  keeps a PHP thread busy for about 2 seconds.
- Apple TV (tvOS): display and playback control follow the reference but are untested. Power, remote
  buttons and apps are not planned (see "Goals and scope").
- AirPlay 2 receivers from other manufacturers (e.g. AV receivers) are shown as "⚠️ untested": they
  offer the required pairing, but nobody has tried them yet. Feedback is welcome.
- Stereo pairs have not been tested yet. Group announcements play on all devices at the same time, but not
  sample-synchronous (each device gets its own stream).

## How it works

The module speaks AirPlay 2 itself: pairing without PIN entry (SRP-6a, 3072 bit), an encrypted RTSP
session (ChaCha20-Poly1305) and the audio stream as raw PCM in real time. Display and control use the Media
Remote Protocol (MRP) inside the AirPlay tunnel. Connections exist only for the duration of an action. The protocol flow is reimplemented in PHP after the open library
[pyatv](https://github.com/postlund/pyatv) (MIT), see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Feedback

Questions, experiences, wishes and bug reports please in the
[forum thread on AirPlay-Steuerung](https://community.symcon.de/t/beta-modul-airplay-steuerung-durchsagen-wecker-und-player-kachel-fuer-homepods-direkt-ueber-airplay-2/145020)
(German forum, English posts are fine).

## License

PolyForm Noncommercial 1.0.0 — free for private and non-commercial use, see [LICENSE](LICENSE).

AirPlay, HomePod and Apple TV are trademarks of Apple Inc. This module is not affiliated with or
endorsed by Apple.
