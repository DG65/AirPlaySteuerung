# AirPlay-Steuerung

Announcements on your HomePod, plus now-playing display and playback control — straight from Symcon, in pure PHP. No Home Assistant, no Python,
no Docker, no additional software on the Symcon host.

[Deutsche Version](README.md)

> **Status: beta 0.11.0.** Announcements, resuming afterwards, display and control have been tested on
> HomePod (1st generation) and HomePod mini. Apple TV with tvOS follows the same reference, but has not
> been tested on real hardware yet.

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

1. Module control → add library: `https://github.com/DG65/AirPlaySteuerung`
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

Create an **AirPlay-Sprachausgabe** instance, choose the service and select it in every AirPlay device (panel
"Speech output"). Keys are stored separately with "🔑 Save key" and never shown again.

| Service | Cost | Setup |
|---|---|---|
| **Piper** | free, offline | Docker container in your network (below) |
| **Microsoft Azure** | tier F0: 500,000 characters per month free | Azure portal → resource "Speech" → Key 1 + region, e.g. `westeurope`; voice e.g. `en-US-AvaNeural` (default is German `de-DE-KatjaNeural`) |
| **ElevenLabs** | about 10,000 characters per month free | elevenlabs.io → profile → API key; voice ID via "🔍 Show voices" |
| **Google Cloud** | free tier per voice type, billing account required | project → enable "Cloud Text-to-Speech API" → API key (restrict it to this API); without a voice, a German standard voice is used |

The providers set their free tiers themselves and change them now and then — check before setting up.
The **cache** plays identical texts (same service, same voice) from Symcon instead of creating them again: the
hourly time announcement only costs quota on the first day and also works without internet.

### Piper

The text for `AIRP_Say` is spoken by [Piper](https://github.com/OHF-Voice/piper1-gpl), a free text-to-speech
engine with natural-sounding voices that runs locally — no account, no cloud. Piper runs as a separate service on
the network (Wyoming protocol, TCP 10200), e.g. as a Docker container on a NAS or server; Symcon only sends the text
and receives the audio. Nothing is installed on the Symcon host.

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

"Deploy the stack". On first start, the container downloads the voice once (about 100 MB, it needs internet
briefly). Without Portainer, `docker compose up -d` with the same file is enough.

**In the module:** panel "Speech output (Piper)" → Piper server = IP address of the Docker host → "Apply changes" →
"🗣️ Speak". "🔍 Show voices" lists the installed voices.

**Voices:** English e.g. `en_US-lessac-high`, `en_GB-alba-medium`; German e.g. `de_DE-thorsten-high`. Voices with
`medium` or `low` are faster on weak hosts such as a Raspberry Pi. Listen to samples at
[rhasspy.github.io/piper-samples](https://rhasspy.github.io/piper-samples/).

The container [rhasspy/wyoming-piper](https://github.com/rhasspy/wyoming-piper) is also used by Home Assistant; an
existing Piper service from there can be entered as well.

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

## License

PolyForm Noncommercial 1.0.0 — free for private and non-commercial use, see [LICENSE](LICENSE).

AirPlay, HomePod and Apple TV are trademarks of Apple Inc. This module is not affiliated with or
endorsed by Apple.
