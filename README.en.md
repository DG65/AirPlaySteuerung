# AirPlay-Steuerung

Announcements on your HomePod, plus now-playing display and playback control — straight from Symcon, in pure PHP. No Home Assistant, no Python,
no Docker, no additional software on the Symcon host.

[Deutsche Version](README.md)

> **Status: beta 0.4.2.** Announcements, resuming afterwards, display and control have been tested on
> HomePod (1st generation) and HomePod mini. Apple TV with tvOS follows the same reference, but has not
> been tested on real hardware yet.

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
- **Chime:** a two-tone chime for testing or as a lead-in, no audio file needed.
- **AirPlay-Klima:** temperature and humidity of HomePod mini and HomePod (2nd generation) as real
  variables — through an Apple Home automation that Symcon triggers via its HomeKit bridge at a set interval
  (guide in the form and below).
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

AIRP_PlayChime(12345);        // chime
AIRP_TestConnection(12345);   // pair only, no sound

// Control
AIRP_Play(12345); AIRP_Pause(12345); AIRP_Stop(12345);
AIRP_Next(12345); AIRP_Previous(12345);
AIRP_SetVolume(12345, 25);    // 0–100 %
AIRP_Update(12345);           // refresh the display now

// Apple Music: share link of a playlist/album or song IDs, true = shuffle
AIRP_PlayPlaylist(12345, 'https://music.apple.com/us/playlist/…/pl.u-…', true);
```

**Apple Music:** the module reads the song list from the public Apple Music page of the link and hands it
to the HomePod as a queue (at most 300 songs). It plays a copy: if the playlist changes later, simply call
`AIRP_PlayPlaylist` again. The HomePod needs an Apple Music subscription.

All functions return `true` or `false`; the result is also shown in the panel "Announcements". An announcement
returns once it has finished (duration plus about 1.5 s, with resume plus about 3–5 s).

**Audio format:** WAV (PCM), any sample rate, mono or stereo, 8/16/24/32 bit, at most 120 seconds. The
module converts it to 44.1 kHz stereo itself. MP3/AAC are not supported yet.

## Temperature and humidity (AirPlay-Klima)

Apple does not hand out the HomePod sensor values over the network, so the route goes through Apple Home:

1. Create an **AirPlay-Klima** instance. Its variable "Trigger measurement" is switched on briefly at the set
   interval (default 10 min).
2. In Symcon's **HomeKit Bridge** instance, add a device of type "Switch" with this variable in the list
   "Devices".
3. In the Home app: Automation → "An Accessory is Controlled" → the switch → "Turns On" → "Convert to
   Shortcut". In it, for each HomePod, "Get the state of" twice (current temperature, current relative
   humidity) and "Get Contents of URL" once with the URL from the form
   (`…&name=Bedroom&temperature=<variable>&humidity=<variable>`). The detailed step-by-step guide is in the
   instance form.
4. On the first reception, the instance creates "<name> temperature" and "<name> humidity" per HomePod.

The URL contains a secret key. The automation needs a home hub (HomePod or Apple TV). After iOS updates,
Home automations sometimes stop running; switching them off and on again helps. The idea comes from
[homepod-ha-sensors](https://github.com/vdamboeck/homepod-ha-sensors) for Home Assistant.

## Limitations of this version

- The display is polled, not pushed: changes appear with up to one polling interval of delay. A query
  keeps a PHP thread busy for about 2 seconds.
- Apple TV (tvOS): display and control follow the reference but are untested; power, remote buttons and
  apps are still missing.
- AirPlay 2 receivers from other manufacturers (e.g. AV receivers) are shown as "⚠️ untested": they
  offer the required pairing, but nobody has tried them yet. Feedback is welcome.
- Stereo pairs and groups of several HomePods have not been tested yet.

## How it works

The module speaks AirPlay 2 itself: pairing without PIN entry (SRP-6a, 3072 bit), an encrypted RTSP
session (ChaCha20-Poly1305) and the audio stream as raw PCM in real time. Display and control use the Media
Remote Protocol (MRP) inside the AirPlay tunnel. Connections exist only for the duration of an action. The protocol flow is reimplemented in PHP after the open library
[pyatv](https://github.com/postlund/pyatv) (MIT), see [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

## Related modules

For **Apple TV control**, the Symcon store offers the module **"AppleTV" by KaiS**
([forum](https://community.symcon.de/t/52341)). It connects pyatv through the pyatv-mqtt-bridge and is a
good choice if an MQTT server and Docker/Python are running anyway. AirPlay-Steuerung takes a different
route: everything in PHP inside Symcon, with HomePod announcements at its core.

Siri control of Symcon devices still runs through Symcon's HomeKit bridge; the HomePod mini as a Thread
border router for Matter does not need this module.

## License

PolyForm Noncommercial 1.0.0 — free for private and non-commercial use, see [LICENSE](LICENSE).

AirPlay, HomePod and Apple TV are trademarks of Apple Inc. This module is not affiliated with or
endorsed by Apple.
