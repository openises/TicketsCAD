# Digital Voice Bridges (DVMProject / USRP) — Setup and Operation Guide

**Audience:** administrators who want a P25, DMR or analog talkgroup that a bridge
program already carries to appear on the Communications Console, and to be patched
to other channels. **Status in this version: listen-only.**

This guide covers the *Digital Voice Bridges* page (Settings > Communications &
Integrations > Digital Voice Bridges), the two channel types it creates
(`dvmproject` and `usrp_bridge`), and the generic USRP leg in the audio-matrix
service that serves them. It was written for GitHub issues #151 (DVM Host in the
Comms Console) and #129 (P25 bridge).

## 1. What it is, and what it is not

- **It is** a UDP socket in the audio-matrix service that receives 8 kHz audio in
  the *USRP* format from a **bridge program you install and run yourself**, and
  presents it as a console strip you can listen to and patch to other channels.
  USRP is the framing that DVMProject's `dvmbridge` (with `udpUsrp: true`),
  DVSwitch's Analog_Bridge and AllStar's `chan_usrp` all use, so one leg serves them.
- **It is not** a DVMProject, P25, DMR or FNE implementation. TicketsCAD speaks no
  radio-network protocol, bundles **no vocoder and no DVMProject software**, and
  never talks to a radio. The bridge program does the vocoding and joins the network.
- **It is not (yet) able to transmit.** Nothing in this version can key a digital
  voice channel: the leg never sends a packet, a patch *into* one of these channels is
  refused, and no setting turns transmit on. See section 12.

## 2. What has and has not been tested

Be clear about this before relying on it.

**Tested (automatically, on every build):**

- The USRP leg's handling of the USRP wire format — source filtering, voice
  recognised by length, end-of-call by a bare header or by silence, malformed and
  non-voice packets dropped, exclusive port binding — against a **simulator**
  (`services/audio-matrix/tests/usrp_sim.py`) that generates USRP packets.
- The PHP admin API, the live attach/re-attach/detach of a leg in a real
  (test-started) audio-matrix control plane, UDP audio arriving, and the `rx_state`
  notification reaching the web endpoint over real HTTP.
- The console strip and RX lamp, by running the real `console.js` in a DOM
  simulator.
- The optional FNE status client, against a **simulator** (`tests/_fake_dvm_fne.php`)
  written from DVMProject's FNE REST documentation.

**Not tested:** any real `dvmbridge`, `dvmfne`, FNE, Analog_Bridge or `chan_usrp`;
any real radio or P25/DMR traffic; the FNE REST interface of a real FNE; audio
quality, latency, or a headless `dvmbridge` on your OS. The USRP header behaviour
the leg tolerates (unused bytes that may hold garbage; an end-of-transmission that is
just a bare header) was recorded from **reading** `dvmbridge`'s source on 2026-10-02,
not from running it. If your bridge behaves differently, please say so.

## 3. DVMProject's usage guidelines, and your responsibility

This is the same statement the admin page shows before you can create or enable a
`dvmproject` channel. It is a summary, not the authority: read the current
`usage_guidelines.md` in the DVMProject repository yourself.

> DVMProject's published usage guidelines say that its software is not intended for
> public safety, fire, EMS, law enforcement or emergency management use, including in
> a backup, interoperability or support role, and not for dispatch integration, and
> that the project does not give support for such use. They also say passive hobby
> monitoring of public radio traffic is allowed.
>
> The software is released under the GPL-2.0 licence, and the guidelines state that
> they do not restrict the rights that licence grants. TicketsCAD bundles and
> redistributes no DVMProject software. This adapter only exchanges audio with a
> bridge program (`dvmbridge`) that you install, configure and operate yourself.
>
> Using this adapter is your decision and your responsibility, as is compliance with
> the DVMProject guidelines, the licence of every program involved, the rules of the
> radio service your frequencies are in, and your own agency's policies. TicketsCAD
> cannot check any of that for you.

- The **DVMProject** channel type is **opt-in**: an administrator must click *I have
  read this and acknowledge it*. The click records their user name, the time and the
  statement version (setting `dvm_policy_ack`) and writes an audit-log entry.
- Until it is recorded a `dvmproject` channel can be neither created nor enabled; the
  audio-matrix service independently refuses to bind its socket without it.
- *Withdraw acknowledgment* disables every DVMProject channel and detaches its leg at
  once.
- The generic **USRP voice bridge** type (for DVSwitch Analog_Bridge, AllStar
  `chan_usrp` and the like) does not use this gate.

## 4. How it fits together

```
 radio network  <->  bridge program you run   <-- UDP, USRP, 8 kHz s16le -->   audio-matrix service
 (FNE, RF, ...)      (dvmbridge, Analog_Bridge)                                 (legs/usrp.py)
                       vocoder lives HERE                                             |
                       not in TicketsCAD                                       patch rail / Patch Matrix
                                                                                      |
                                                              console strip "Listen", other channels' strips

 optional, status only:  TicketsCAD PHP --HTTP(S), read-only--> the FNE's REST interface
```

The bridge sends audio to the **listen address : port** you configure. TicketsCAD
accepts datagrams only from the **bridge address**. In this version the "bridge
receive port" is recorded (and written into the generated bridge configuration) but
unused, because nothing is sent back.

## 5. Before you start

1. The audio-matrix service must be installed and running, with `matrix_control_url`
   and `matrix_control_token` configured — see `docs/AUDIO-MATRIX-SETUP.md`. Without it
   creating an *enabled* channel fails (and rolls back); you can still stage a disabled one.
2. To **hear** a channel in the browser, the console's browser leg must be exposed
   (`matrix_ws_url`; same guide, "Expose the browser leg").
3. You need `action.manage_voice_bridges` (Super Admin and Org Admin by default; **not**
   Dispatcher). Patching uses the existing `action.manage_matrix` / `action.patch_create`;
   listening uses `screen.console`.
4. Install and configure the bridge program yourself. For `dvmbridge` that means a
   headless build/run on the same host as the audio-matrix service is the safe
   arrangement (section 9). How to install it is DVMProject's documentation, not ours.

## 6. Setup, step by step

1. **Settings > Communications & Integrations > Digital Voice Bridges.**
2. DVMProject only: read the statement and click **I have read this and acknowledge it**.
3. **New Channel.** Fill in the fields (section 7). Defaults are safe: everything on
   `127.0.0.1`, listen port 34001 (the next free one for each new channel).
4. Click **Save Channel**. The channel attaches in the running service immediately — no
   restart. The list shows *Link: unknown* and *attached, listening*.
5. Click the **file icon** on the channel's row (*Bridge configuration*). Copy the text
   into the bridge program's own configuration, replacing the `<…>` placeholders.
   The FNE password is a placeholder: TicketsCAD never has it. Compare the generated
   lines with the example configuration that ships with **your** bridge version.
6. Start (or restart) the bridge. When audio arrives the row reads *attached, receiving
   now* and the console strip's **RX** lamp lights.
7. **Patch it.** Settings > Audio Matrix Patches: create a patch *from* this channel
   *to* the channel that should hear it (for example a Zello strip). A patch *into* a
   digital voice channel is refused: it is listen-only.
8. **Listen on the console.** Open the Console; the strip says *Listen-only · P25 · TG …*.
   Tick **Listen** on the strip. An operator without console TX permission can do this
   and is not asked for the microphone.

## 7. Field reference

| Field | Default | Meaning |
|---|---|---|
| Type | — | *DVMProject* (needs the acknowledgment) or *USRP voice bridge*. Fixed after creation. |
| Channel key | — | `dvm:` or `usrp:` plus lower-case letters, digits, `-`, `_`. It is the live matrix's channel id and cannot change. |
| Label | — | Shown on the strip. |
| Regulatory class | Amateur | **Amateur** or **Commercial**. Choose the class of the *radio network the bridge joins*. A Part 90 network must be Commercial. There is no "internal" choice (section 10). |
| Mode | P25 | P25, DMR, Analog, Other. A description, and it fills in `txMode` in the generated text. The real mode is whatever the bridge is configured for. |
| Talkgroup | blank | Description only (and used for the optional "does the FNE know this talkgroup" check). Cannot change the bridge's talkgroup. |
| FNE peer ID | blank | DVMProject only. Lets the optional link check find this bridge. |
| End-of-call silence (ms) | 400 | A call also ends after this much silence on the socket, in case the bridge never sends a clean end (100–5000). A value that is too small chops one call into several. |
| Bridge address | 127.0.0.1 | Where the bridge runs. Audio is accepted **only** from this address. IPv4, loopback or private network only. |
| Bridge receive port | 32001 | The bridge's own UDP receive port. Unused in this version; recorded and written into the generated text. |
| Listen address | 127.0.0.1 | The address of *this* host the leg binds. IPv4, loopback or private only; `0.0.0.0` is refused. |
| Listen port | 34001 | The UDP port the bridge sends audio to. Each channel needs its own. |
| Enabled | on | While enabled the leg binds its port. |

Everything above is validated twice, once by PHP and once by the Python service, and a
test feeds the PHP output to the Python builder so the two cannot drift apart.

## 8. What operators see

- **Strip:** the label, a status light, an **RX** lamp, and the line
  *Listen-only · P25 · TG 9001*. No PTT button, ever; it is never a foot-switch target.
- **Link light:** green / amber / red / grey. Grey (*unknown*) is the honest default. The
  light turns green **only** when the FNE itself says the peer is running (section 9a); a
  quiet channel never turns it green.
- **RX lamp:** lit while audio is arriving, labelled "RX" so it does not rely on colour.
  A watchdog clears it after 10 minutes if an "ended" notice was lost.
- **Hidden from operators:** the bridge's address and ports. Only people who administer
  the bridges (and hold `action.manage_voice_bridges`) receive them from the API.

## 9. Link status from the FNE (optional)

On the Digital Voice Bridges page, *Network link status* takes the FNE's REST address and
password. **Leave it blank unless your FNE has REST enabled.** With it set:

- Only these read-only requests are ever made: `PUT /auth`, `GET /status`,
  `GET /peer/query`, `GET /tg/query`. Nothing can change the FNE.
- A channel with an FNE peer ID reads **connected** (connected, running, recent ping),
  **degraded** (connected but not running, or no/old ping — the threshold is a setting,
  default 30 s), or **down** (not connected, or the peer ID is not known). If the REST
  call itself fails the answer is **unknown**, never connected.
- The REST address must be an IPv4 address on this host or your private network.
  Plain `http://` across the network is accepted with a warning: the password is sent as
  its SHA-256 digest (that is the protocol), and anyone who can capture it can replay it.
  Redirects are not followed.
- The FNE gives each client one token and **invalidates it when that client authenticates
  again**, and all web workers on this host share one address. TicketsCAD therefore caches
  one token on disk (in the runtime-state directory above the web root, mode 0600),
  reuses it, and refreshes it under a lock after a 401 — so concurrent requests do not
  invalidate each other. Running `dvmcmd` from the same host at the same time may still
  invalidate it (not tested against a real FNE); the next request simply re-authenticates once.
- **Check now** shows reachability, peers connected, each channel's state and whether its
  talkgroup exists on the FNE. The **Status** page also reports whether each enabled
  channel's leg is attached in the running service.

This client has only been run against a simulator built from the FNE REST document.

## 10. Regulatory and licensing notes (not legal advice)

- **Class.** A digital voice bridge is a radio network, so it is always **amateur** or
  **commercial**. `internal` and `pstn` are refused (the page, the API, the Console
  Designer's reclassify action and the service), because those classes are exempt from
  the cross-class patch guard. A Part 90 / public-safety land-mobile network must be
  **commercial**.
- **Cross-class patches.** Amateur ↔ commercial and amateur ↔ PSTN patches are blocked
  by the FCC Part 97.113 guard unless created with the audited override, which also
  needs an expiry. The check is transitive: a path through an intermediate channel counts.
  A digital voice channel obeys exactly the same guard as a DMR or Zello one.
- **Station identification.** This version does not transmit, so it makes no
  transmission that needs identification. If you patch a digital voice channel's audio
  *to* a channel that does transmit (for example amateur DMR), **that** channel's own
  transmit path and ID rules apply. P25 carries a radio ID but no callsign.
- **Encryption** is not permitted on amateur frequencies. Leave the bridge's encryption
  keys unset on an amateur network.
- **Vocoders.** P25 Phase 1 uses IMBE; DMR and P25 Phase 2 use AMBE+2. Patent status
  differs by codec and country and changes with time; open-source decoders carry their own
  "check patents yourself" notices. TicketsCAD bundles **no** vocoder in any release: the
  codec lives in the bridge program you install. You are responsible for your jurisdiction.
- **Licences.** `dvmhost` is GPL-2.0 and TicketsCAD talks to it only over UDP and HTTP, so
  no code is shared. Some other DVMProject components are AGPL-3.0; none is used here.

## 11. Network safety

- **The bridge's UDP port has no authentication.** Anything that can send a datagram to
  the listen port *from the bridge address* is heard on the strip and in every patch from
  it. The leg drops all other source addresses, but a source address on a shared network
  can be forged.
- So: run the bridge on the **same host** as the audio-matrix service with `127.0.0.1`.
  If it must be elsewhere, use a private address, restrict the port with the host
  firewall to that one address, and keep it off any flat network you do not trust. The page
  warns whenever either address is not loopback.
- Public (non-private) addresses are refused everywhere.
- The audio is clear; there is no TLS on the USRP link.

## 12. Limits, and what comes next

- **Listen-only.** Release B (transmit) needs, first: the FCC station-ID / unattended-keying
  gate attached to these channels, a strip-level operator ID gate, and a lab proof against a
  real FNE with two bridges. None of it exists yet and **no setting enables it**. The
  `tx_enabled` field is stored as `false` and refused if set to true.
- **One talkgroup per `dvmbridge` instance** (as read from its source); run one instance
  and one channel per talkgroup. Each needs its own listen port.
- **P25 → DMR patches** transmit on the DMR side per utterance: the DMR leg buffers a whole
  utterance, so audio patched into a DMR channel is delayed by its length. DMR → P25 is
  real time. Tandem vocoding degrades audio.
- **No transcripts, no TTS-to-radio, no caller ID/alias, no call log** for these channels
  yet. They are not part of "same as the DMR bridge".
- **TLV, DTMF and text packets** that a USRP bridge may send are ignored and counted, never
  played.
- Supported **framing** is `usrp` only.

## 13. Trying it without a bridge

`services/audio-matrix/tests/usrp_sim.py` plays a test tone to a leg from a simulated
bridge, for checking your install end to end:

```
python services/audio-matrix/tests/usrp_sim.py send --port 34001 --seconds 3
```

(`--bind` must equal the channel's *Bridge address*; `--style dvmbridge` mimics the
header quirks described in section 2.) The channel's row should read *receiving now* and
the strip's RX lamp should light. This proves the TicketsCAD side only.

## 14. Troubleshooting

| Symptom | Likely cause |
|---|---|
| Creating an enabled channel fails with *audio-matrix service did not accept the channel* | The service is not running, or `matrix_control_url`/`matrix_control_token` are not set (docs/AUDIO-MATRIX-SETUP.md). The row is rolled back. Stage it disabled, or fix the service. |
| *…port … is already used* / *could not start leg* | Another channel (or program) holds that UDP port. Pick a free listen port. |
| Row says *NOT attached in the audio-matrix service* | The service restarted without it (check its log: an unacknowledged DVMProject channel, a bad address or a busy port is skipped with a WARNING), or it was started before the channel existed. Re-save the channel. |
| Attached but never *receiving* | The bridge is not sending to the **listen address:port**, or is sending from an address other than **Bridge address** (the row then shows *N datagram(s) dropped: not from the bridge address*), or its UDP/USRP flags do not match the generated text. |
| Audio arrives but the strip says nothing to hear | Tick **Listen** on the strip; the console's browser leg must be exposed (`matrix_ws_url`). |
| Calls are chopped into pieces | Raise *End-of-call silence*. |
| A call never ends on the lamp | The bridge sent no end marker and audio keeps trickling; or the lamp's 10-minute watchdog has not fired yet. |
| Link light is grey | By design without FNE REST (or without this channel's peer ID). Configure the status check, or accept *unknown*. |
| Link *degraded: last ping N s ago* | The FNE has not heard from the peer lately, or the two hosts' clocks differ by more than the threshold. |
| `dvmproject` channel cannot be created or enabled | The usage-policy statement has not been acknowledged (section 3). |

## 15. See also

- `docs/AUDIO-MATRIX-SETUP.md` — the service this runs in.
- `docs/COMMS-CONSOLE-GUIDE.md` — the console itself.
- `docs/DVSWITCH-ADMIN-GUIDE.md` — the existing DMR bridge (a different path from this one).
- `docs/FCC-STATION-ID-COMPLIANCE.md` — station-ID rules for channels that do transmit.
- `specs/phase-155-community-backlog/151-dvm-host-and-129-p25-bridge.md` — the design,
  the open decisions, and the build log.
