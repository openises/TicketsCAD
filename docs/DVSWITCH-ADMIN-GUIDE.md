# DVSwitch DMR Bridge — Administrator Guide

**Audience:** sysadmin setting up DMR voice + transcription on TicketsCAD.
**Goal:** dispatcher can speak text onto a DMR talkgroup, and incoming DMR voice gets transcribed into the incident log.
**Time estimate:** 90 minutes for the first install (including hardware/account prerequisites). 20 minutes per additional channel.

This guide covers Phase 73i–73o of the TicketsCAD build. If you're researching whether to use DVSwitch at all vs. a future direct-to-network protocol (ODMRTP), read specs/odmrtp-2026-06/research.md first.

---

## What you're building

```
 ┌──────────────────┐    HTTPS    ┌────────────────────────┐
 │  TicketsCAD VM   │◄───────────►│  TicketsCAD NewUI      │
 │  (training, etc) │             │  /api/dvswitch.php     │
 └──────────────────┘             │  /api/dmr-ingest.php   │
                                  └──────────┬─────────────┘
                                             │
                                             │ HTTP (bearer-auth)
                                             ▼
 ┌─────────────────────────────────────────────────────────────┐
 │  dvswitch-01 VM (e.g. Proxmox VMID 943, 10.0.0.10)      │
 │                                                              │
 │  ┌──────────────┐ USRP ┌────────────────┐  DMR  ┌─────────┐ │
 │  │  bridge.py   │◄────►│ Analog_Bridge  │◄─────►│ MMDVM   │ │
 │  │  (TicketsCAD)│      └────────────────┘       │ _Bridge │ │
 │  │              │      AMBE↕ md380-emu          └────┬────┘ │
 │  │  ↕ Piper TTS │                                    │      │
 │  │  ↕ Vosk STT  │                                    │ DMR  │
 │  └──────────────┘                                    │ over │
 │       per-channel systemd unit                       │ IP   │
 └──────────────────────────────────────────────────────┴──────┘
                                                        │
                                                        ▼
                                              ┌─────────────────┐
                                              │  BrandMeister / │
                                              │  TGIF / etc.    │
                                              │  DMR network    │
                                              └─────────────────┘
```

You'll set up the right-hand side (`dvswitch-01` VM) once. Then you create one or more channels in the TicketsCAD admin UI, each binding to one DMR talkgroup. Each channel runs its own systemd unit so they're independently restartable.

---

## What you'll need before starting

- [ ] A **TicketsCAD install** that's running and you can log in as admin
- [ ] A **separate Linux VM** (Debian 13 recommended; 2 vCPU, 2 GB RAM, 20 GB disk) reachable from the TicketsCAD VM on whatever port the bridge HTTP control listens on (default 18091+).
- [ ] A **DMR ID** issued to you by [radioid.net](https://radioid.net) — you (or your org) needs one for MMDVM_Bridge auth.
- [ ] **BrandMeister (or TGIF) account credentials** for the DMR network — the password MMDVM_Bridge will authenticate with.
- [ ] A **talkgroup** chosen for your first test. **TG 9990** is the Parrot talkgroup — it echoes back what you say, perfect for verification. Use it before you go live on a real talkgroup.

You do NOT need:

- A physical hotspot or MMDVM modem — this entire stack is software.
- A DMR radio — testing is done via the admin UI's TX-text composer (which Piper synthesises) and the transcripts table (which Vosk populates).
- AMBE hardware — md380-emu does AMBE in software (covered below).

---

## Section 1 — Provision the dvswitch-01 VM

If you have Proxmox (recommended), the playbook at [`proxmox-playbook.md`](your provisioning docs) provisions in 5 minutes.

Without Proxmox: any 2 vCPU / 2 GB RAM Debian 13 VM is fine. Anything older than Debian 12 or Ubuntu 22.04 will pull older Python and may need backports.

After provisioning:

```bash
ssh ejosterberg@dvswitch-01
sudo apt-get update && sudo apt-get install -y \
  build-essential ca-certificates curl git wget gnupg \
  python3-venv python3-pip ffmpeg unzip
```

- [ ] VM reachable via SSH
- [ ] ffmpeg installed (`ffmpeg -version`)
- [ ] Python 3 ≥ 3.10 (`python3 --version`)

---

## Section 2 — Install Analog_Bridge + MMDVM_Bridge + md380-emu

DVSwitch publishes a community repository. The APT component is **`hamradio`**, not `main` — easy mistake.

```bash
# Set up the DVSwitch repo (Debian 13 / "trixie")
curl -fsSL https://download.opensuse.org/repositories/home:dvswitch/Debian_12/Release.key \
  | sudo gpg --dearmor -o /etc/apt/trusted.gpg.d/dvswitch.gpg

echo "deb http://download.opensuse.org/repositories/home:/dvswitch/Debian_12/ ./" \
  | sudo tee /etc/apt/sources.list.d/dvswitch.list

sudo apt-get update
sudo apt-get install -y analog_bridge mmdvm_bridge md380-emu
```

- [ ] `/opt/Analog_Bridge` exists with `Analog_Bridge` binary
- [ ] `/opt/MMDVM_Bridge` exists with `MMDVM_Bridge` binary
- [ ] `which md380-emu` returns `/usr/bin/md380-emu` (or `/opt/md380-emu`)

> **Cloud-init dpkg lock:** if the VM was just provisioned via cloud-init, the apt lock may be held briefly. Use `cloud-init status --wait` before `apt-get install` if you see "Could not get lock".

---

## Section 3 — Install bridge.py + dependencies

The TicketsCAD-specific bridge daemon lives in the TicketsCAD repo. Either clone the repo on dvswitch-01 or just copy the one file over.

```bash
# Create the install directory and venv
sudo mkdir -p /opt/ticketscad-dvswitch/{voices,models}
sudo chown -R ejosterberg:ejosterberg /opt/ticketscad-dvswitch
cd /opt/ticketscad-dvswitch

# Python venv
python3 -m venv venv
source venv/bin/activate
pip install --upgrade pip
pip install piper-tts vosk
deactivate
```

- [ ] `/opt/ticketscad-dvswitch/venv/bin/python3` exists
- [ ] `/opt/ticketscad-dvswitch/venv/bin/piper --help` works
- [ ] `pip list | grep -i vosk` shows `vosk` installed

### Download the Piper voice

The standard voice is `en_US-lessac-medium` (22 MB ONNX + 1 KB JSON).

```bash
cd /opt/ticketscad-dvswitch/voices
wget https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx
wget https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx.json
```

Verify quick synthesis:

```bash
echo "Test of the emergency broadcast system" | \
  /opt/ticketscad-dvswitch/venv/bin/piper \
    -m /opt/ticketscad-dvswitch/voices/en_US-lessac-medium.onnx \
    --output-raw | aplay -r 22050 -f S16_LE -c 1 -
```

(If no audio device on the VM, redirect to `/dev/null` instead of `aplay` — just check exit code is 0.)

### Download the Vosk model

The small English model (40 MB) is the default; faster-whisper can be added later for higher accuracy.

```bash
cd /opt/ticketscad-dvswitch/models
wget https://alphacephei.com/vosk/models/vosk-model-small-en-us-0.15.zip
unzip vosk-model-small-en-us-0.15.zip
rm vosk-model-small-en-us-0.15.zip
```

- [ ] `/opt/ticketscad-dvswitch/models/vosk-model-small-en-us-0.15/` exists

### Copy bridge.py

```bash
cd /opt/ticketscad-dvswitch
# From your TicketsCAD repo on your workstation:
scp services/dvswitch/bridge.py dvswitch-01:/opt/ticketscad-dvswitch/bridge.py
# OR clone the repo on the VM and copy the file.
```

Smoke-test (catch syntax errors):

```bash
/opt/ticketscad-dvswitch/venv/bin/python3 -m py_compile /opt/ticketscad-dvswitch/bridge.py
```

Should exit 0 with no output.

---

## Section 4 — Configure MMDVM_Bridge

```bash
sudo nano /opt/MMDVM_Bridge/MMDVM_Bridge.ini
```

Key sections to edit:

```ini
[General]
Callsign=YOURCALL
Id=1234567       ; YOUR 7-digit DMR ID

[Info]
RXFrequency=438800000
TXFrequency=438800000
Power=1
Latitude=0.0
Longitude=0.0
Height=0
Location=...
Description=TicketsCAD bridge
URL=https://your-domain.tld

[DMR Network]
Enable=1
Address=master.brandmeister.network   ; or your TGIF / IPSC2 master
Port=62031
Password=YOUR_BM_PASSWORD              ; ← from your BrandMeister account
```

For Analog_Bridge:

```bash
sudo nano /opt/Analog_Bridge/Analog_Bridge.ini
```

```ini
[GENERAL]
tlvPort = 36000
tlvHost = 127.0.0.1
toRadioIP = 127.0.0.1
fromRadioIP = 127.0.0.1
ambeMode = DMR_3600
```

These ports (36000) are internal to dvswitch-01 — they connect Analog_Bridge to MMDVM_Bridge. They're different from bridge.py's USRP ports (33000-range) which connect bridge.py to Analog_Bridge.

- [ ] Both `.ini` files edited
- [ ] DMR ID is YOUR ID, not a default
- [ ] BrandMeister password is correct
- [ ] You picked a callsign you actually have

---

## Section 5 — Create the channel in TicketsCAD

In your browser, log in to TicketsCAD as admin.

1. **Settings → Communications → DMR (DVSwitch)** → click **New Channel**.
2. Fill in:
   - **Label:** something short like `tg9990` (used in env file name)
   - **Talkgroup:** `9990` (Parrot, for first test)
   - **Network:** `BrandMeister`
   - **Bridge host:** `dvswitch-01` (or its IP if not resolvable)
   - **Bridge port:** `18091` (will become `18000 + (channel index * 100) + 91` for multi-channel)
   - **USRP listen port:** `33001`
   - **USRP send port:** `33000`
   - **Link mode:** `rx_and_tx` (or `rx_only` for listen-only)
   - **Chat channel:** `dispatch` (routing destination if you enable forwarding)
3. Click **Save**.
4. A **bearer token** appears in a modal. **Copy it immediately** — you won't see it again. The server stores SHA-256(token), not the plaintext.

- [ ] Channel created
- [ ] Token saved to a temporary file (you'll paste into env file next)

---

## Section 6 — Configure the systemd unit

The repo ships a systemd template unit: [`services/dvswitch/ticketscad-dvswitch@.service`](../services/dvswitch/ticketscad-dvswitch@.service).

```bash
# On dvswitch-01:
sudo cp /opt/ticketscad-dvswitch/services/dvswitch/ticketscad-dvswitch@.service \
        /etc/systemd/system/
sudo systemctl daemon-reload
```

Now create the per-instance env file. The template loads `/etc/ticketscad/dvswitch-<instance>.env`.

```bash
sudo mkdir -p /etc/ticketscad
sudo cp /opt/ticketscad-dvswitch/services/dvswitch/example.env \
        /etc/ticketscad/dvswitch-tg9990.env
sudo nano /etc/ticketscad/dvswitch-tg9990.env
```

Set:

```env
DMR_INSTANCE=tg9990

DMR_USRP_LISTEN_PORT=33001
DMR_USRP_SEND_PORT=33000
DMR_USRP_SEND_HOST=127.0.0.1

DMR_HTTP_PORT=18091

# PASTE the bearer token from the admin UI in BOTH of these.
DMR_BEARER_TOKEN=PASTE_THE_TOKEN_HERE
DMR_INGEST_TOKEN=PASTE_THE_SAME_TOKEN_HERE

DMR_AUDIT_DIR=/var/log/ticketscad-dvswitch
DMR_LOG_LEVEL=INFO

# Ingest URL — points back at YOUR TicketsCAD install.
DMR_INGEST_URL=https://cad.example.org/api/dmr-ingest.php

# Piper TTS (already installed in Section 3).
DMR_PIPER_BIN=/opt/ticketscad-dvswitch/venv/bin/piper
DMR_PIPER_VOICE=/opt/ticketscad-dvswitch/voices/en_US-lessac-medium.onnx
DMR_FFMPEG_BIN=ffmpeg

# Vosk STT (already installed in Section 3).
DMR_VOSK_MODEL=/opt/ticketscad-dvswitch/models/vosk-model-small-en-us-0.15
```

- [ ] Env file exists at `/etc/ticketscad/dvswitch-tg9990.env`
- [ ] `DMR_BEARER_TOKEN` matches `DMR_INGEST_TOKEN` (same value, pasted twice)
- [ ] `DMR_INGEST_URL` is your TicketsCAD's real URL (https, not http)

Set permissions so only root + the daemon user read it:

```bash
sudo chmod 600 /etc/ticketscad/dvswitch-tg9990.env
sudo chown root:root /etc/ticketscad/dvswitch-tg9990.env
```

Enable + start:

```bash
sudo systemctl enable --now ticketscad-dvswitch@tg9990
sudo systemctl enable --now Analog_Bridge
sudo systemctl enable --now MMDVM_Bridge

# Watch the logs.
sudo journalctl -u ticketscad-dvswitch@tg9990 -f
```

You should see something like:

```
2026-06-15 10:00:00 INFO [dvswitch.bridge] Bridge tg9990 started: listen=33001 send=127.0.0.1:33000
2026-06-15 10:00:00 INFO [dvswitch.bridge] HTTP control listening on :18091 (instance=tg9990)
2026-06-15 10:00:00 INFO [dvswitch.bridge] Piper TTS ready: voice=en_US-lessac-medium.onnx
2026-06-15 10:00:00 INFO [dvswitch.bridge] Vosk STT ready: model=vosk-model-small-en-us-0.15
```

- [ ] All four log lines present
- [ ] No errors / tracebacks

---

## Section 7 — Verify end to end

Back in the TicketsCAD admin UI: **Settings → Communications → DMR (DVSwitch) → row → Test**.

### Test 1: /health probe

1. Paste the bearer token into the test modal.
2. Click **Test /health**.
3. Response should be HTTP 200 with `{"ok": true, "instance": "tg9990", ...}`.

If you get `{"error": "bad bearer"}` → token mismatch. Re-mint and re-paste both sides. See [TROUBLESHOOTING.md § dmr-bad-bearer](TROUBLESHOOTING.md#dmr-bad-bearer).

### Test 2: TX 0.5 s 1 kHz tone

Click **TX 0.5s 1 kHz tone**. Response: `{"ok": true, "frames": 5}`.

This sends 5 USRP packets containing a 1 kHz sine wave to Analog_Bridge, which encodes them as AMBE and forwards to MMDVM_Bridge, which keys the talkgroup. If you have a DMR radio on TG 9990, you should hear a brief beep.

If you don't have a DMR radio: connect to BrandMeister LastHeard or a similar tool and watch for your DMR ID transmitting on TG 9990. Or wait — Parrot will echo it back.

### Test 3: TX text via Piper

In the test modal, type **"All clear at scene"** into the "Speak text on the talkgroup" field and click **Send text**.

Response: `{"ok": true, "frames": 73, "samples": 11332, "duration_ms": 1416}`.

This synthesises the phrase via Piper, downsamples 22050→8000 Hz via ffmpeg, frames into USRP packets, sends to Analog_Bridge which AMBE-encodes via md380-emu, and MMDVM_Bridge keys the talkgroup.

On Parrot: wait ~30 s, then check Recent Transcripts (next test).

### Test 4: RX → Vosk transcript

Click **Recent transcripts**. After a few seconds (and assuming Parrot echoed your TX or someone keyed the talkgroup), you should see rows with:

| Time | Dir | TG | From | Transcript |
|---|---|---|---|---|
| 2026-06-15 10:01:23 | (RX icon) | 9990 | YOURCALL | all clear at scene |
| 2026-06-15 10:01:18 | (TX icon) | 9990 | — | All clear at scene |

The TX row shows your Piper-synthesised text (engine=piper). The RX row shows what Vosk transcribed from the Parrot echo (engine=vosk). They should approximately match — Vosk is small-model so it sometimes drops articles or misrecognises proper nouns, but tactical short phrases work fine.

- [ ] Test 1 passes
- [ ] Test 2 passes (tone keyed)
- [ ] Test 3 passes (Piper text keyed)
- [ ] Test 4 passes (Vosk transcribed the echo)

If all four pass: **the DMR bridge is operationally complete**. Move to production decisions.

---

## Section 8 — Operational decisions

These are the questions in `specs/dvswitch-proxy-2026-06/setup-log.md`. Answer them now, before you go live on a real talkgroup.

### 1. Which DMR ID?

If your org has multiple AuxComm/CERT/fire members each with their own DMR IDs, pick one ID for the bridge. Recommended: a dedicated "TicketsCAD" ID that's distinct from any individual's ID, so audit trails are clear ("did Bob talk, or did the bridge talk on Bob's behalf?").

### 2. Which production talkgroup?

Parrot (9990) for testing. For production:

- A private talkgroup for inter-agency dispatch
- A statewide ARES TG (varies by state)
- A local TG used for incident command

**Whatever you pick, get permission from the talkgroup admin before keying.** Bridges that key without coordination get banned.

### 3. Audio archival policy

The bridge writes a JSONL audit per call to `/var/log/ticketscad-dvswitch/<instance>.jsonl`. As of Phase 77b, the bridge also writes the full raw PCM of every finalised RX call as a WAV file under `DMR_RECORDINGS_DIR`, which gives the dispatcher a DVR-style playback experience in the admin panel — rewind, scrub, and play back recent transmissions at 0.75× through 2× speed.

To enable, set these in `/etc/ticketscad/dvswitch-<instance>.env`:

```env
# DVR-style audio recording. Each finalised RX call becomes a single-
# channel 8 kHz 16-bit WAV under
# /var/lib/ticketscad-dvswitch/recordings/<instance>/YYYY/MM/DD/.
DMR_RECORDINGS_DIR=/var/lib/ticketscad-dvswitch/recordings
DMR_RECORDING_RETENTION_HOURS=168
```

Storage budget: a 30-second call is ~480 KB. 100 calls/hour ≈ 50 MB/h. At the default 168 h (7-day) retention, plan for ~8 GB per busy talkgroup; less for quieter ones.

The bridge prunes WAVs older than `DMR_RECORDING_RETENTION_HOURS` on an hourly sweep — no cron needed.

The dispatcher reaches playback through the Settings → Communications → DMR panel. Each row in the **Recent transcripts** table shows a play button when its call has an `audio_path`; clicking opens an inline HTML5 audio player with a speed dropdown. The bridge bearer token is asked for once per session and reused via `sessionStorage` for subsequent plays.

#### When DVR-style playback is useful

- **Mid-call rewind.** A dispatcher is multitasking and misses a license plate the officer just called out. They open the player on the in-progress (now just-finished) call, drag back 2 seconds, and hear it cleanly — no need to ask the officer to repeat.
- **Shift catch-up.** A new dispatcher arrives 30 minutes after a busy stretch. They open the Recent transcripts panel, play the last hour at 1.5× speed, and are caught up in ~40 minutes of wall-clock listening — with transcripts in the same row for skimming.
- **Post-incident review.** Every WAV referenced by `dmr_messages.audio_path` is a verbatim record. Pair with the existing JSONL audit for a complete after-action artefact.

#### Talkgroup addressing (ETSI TS 102 361)

Per ETSI TS 102 361, DMR addresses occupy a uniform 24-bit space (1 to 16,777,215) for both individual radios and groups. The bridge does not distinguish between them at the wire layer — they are both `talkgroup` integers in the USRP frame.

The functional difference matters at the network:

- **Direct (private) talkgroups** (e.g. parrot 9990) route a single call to a single endpoint. Useful for the test loop because your TX comes back as RX with no other listeners.
- **Group talkgroups** (e.g. statewide ARES 31xxx) replicate the call to every subscribed repeater + hotspot. The audio path is identical from `bridge.py`'s perspective; the network's behaviour differs.

When testing, parrot is the right first choice — you hear yourself, no other listeners, no risk of accidentally keying a populated channel. For production, get permission from the talkgroup admin before keying any group TG.

### 4. TX/RX preemption rule

The behaviour depends on the network you're bridging into:

- **BrandMeister** (the most common DMR-over-internet network) does NOT support TX preempting an active RX call. While someone is talking, you simply cannot key onto the same talkgroup. The bridge enforces this at the seam: with `DMR_PREEMPT_ACTIVE_RX=false` (default), any `send_voice_burst` during an active RX returns a 409 `rx_busy` to the admin panel instead of silently dropping the TX into the void.
- **Local hotspots / private repeaters** can sometimes support preemption (firmware-dependent). On those, set `DMR_PREEMPT_ACTIVE_RX=true` in the env file and the bridge will key through.

```env
# Phase 77c. Leave false unless you're on a network/hardware that
# physically supports TX over active RX. BrandMeister: always false.
DMR_PREEMPT_ACTIVE_RX=false
```

When a future TicketsCAD radio-control panel ships, it will read this flag to decide whether to expose a "cut in" button or grey it out.

---

## Section 9 — Add a second channel

For each additional talkgroup:

1. **In the admin UI:** Settings → Communications → DMR → New Channel. Use different USRP ports (next free range): listen=33101, send=33100. HTTP port: 18191.
2. **On dvswitch-01:** create `/etc/ticketscad/dvswitch-<newinstance>.env` mirroring the first one with the new ports and a fresh bearer token.
3. **Start the unit:**

```bash
sudo systemctl enable --now ticketscad-dvswitch@<newinstance>
```

4. **Verify** with the same /health → /tx/test → /tx/text → transcripts walkthrough.

Each channel runs independently. Restart one without affecting the others.

---

## Section 10 — Day-to-day operation

### Dispatch text → talkgroup

Settings → Communications → DMR → row → Test modal → "Speak text on the talkgroup" composer. Type, send. Audit-logged with the dispatcher's username + message body.

### Receive radio → log

Anyone keying the talkgroup will land as a row in `dmr_messages` with:

- `direction = 'rx'`
- `talkgroup`, `radio_id`, `radio_callsign` (looked up via member.callsign join)
- `transcript` (from Vosk)
- `transcript_engine = 'vosk'`
- `duration_ms`, `call_started_at`, `call_ended_at`

To surface RX traffic in real-time on the dashboard, configure a route in **Settings → Routing** that forwards `dmr:rx` → `chat:dispatch`. Then transcripts post into the chat feed automatically.

### Restart a channel after config change

```bash
sudo systemctl restart ticketscad-dvswitch@tg9990
```

### Roll the bearer token

If a token leaks:

1. Admin UI → row → **Rotate Token**.
2. Copy the new token.
3. SSH to dvswitch-01, update the env file, restart the unit.

The old token is invalidated immediately on rotate; the bridge will start failing /health calls until the env is updated.

---

## Troubleshooting

| Symptom | Likely cause | See |
|---|---|---|
| `/health` returns `{"error":"bad bearer"}` | Token mismatch | [TROUBLESHOOTING.md § dmr-bad-bearer](TROUBLESHOOTING.md#dmr-bad-bearer) |
| `/tx/text` returns `503 TTS not configured` | Piper env vars missing or files unreachable | Re-check `DMR_PIPER_BIN` + `DMR_PIPER_VOICE` paths exist on dvswitch-01 |
| Transcripts panel empty after real RX | Vosk model missing or `DMR_VOSK_MODEL` wrong path | `ls /opt/ticketscad-dvswitch/models/vosk-model-*` |
| Bridge log: `Failed to process waveform` | Vosk got 8 kHz audio but expects 16 kHz | Phase 73n fix: the bridge upsamples via ffmpeg. Confirm ffmpeg is on PATH on dvswitch-01 |
| journalctl shows `Connection refused` to TicketsCAD | TicketsCAD URL unreachable from bridge VM | Check firewall, DNS, TLS cert validity from the bridge VM |
| Slow STT (multi-second delay) | Vosk model too big OR no CPU on dvswitch-01 | Stick with `vosk-model-small-en-us-0.15`; provision 2+ vCPU |

---

## Future: ODMRTP direct-to-network

The current bridge architecture (bridge.py ↔ Analog_Bridge ↔ MMDVM_Bridge ↔ BrandMeister) has multiple moving parts. The Open DMR Terminal Protocol (ODMRTP) would let TicketsCAD connect directly to BrandMeister as a software terminal, bypassing MMDVM_Bridge and Analog_Bridge entirely.

This is researched but not yet implemented — see `specs/odmrtp-2026-06/research.md` and `spec.md` for the design and decision criteria.

For now: the DVSwitch stack works, is well-understood, and supports any digital-radio network MMDVM_Bridge handles (not just BrandMeister DMR — also P25, NXDN, YSF). ODMRTP is BrandMeister-specific.

---

## Where the code lives

| What | Path |
|---|---|
| Bridge daemon | [`services/dvswitch/bridge.py`](../services/dvswitch/bridge.py) |
| systemd template | [`services/dvswitch/ticketscad-dvswitch@.service`](../services/dvswitch/ticketscad-dvswitch@.service) |
| Sample env file | [`services/dvswitch/example.env`](../services/dvswitch/example.env) |
| Admin endpoint | [`api/dvswitch.php`](../api/dvswitch.php) |
| Ingest endpoint | [`api/dmr-ingest.php`](../api/dmr-ingest.php) |
| Admin JS | [`assets/js/dvswitch-admin.js`](../assets/js/dvswitch-admin.js) |
| Schema migration | [`sql/run_phase73i_dvswitch_schema.php`](../sql/run_phase73i_dvswitch_schema.php) |
| Architectural spec | `specs/dvswitch-proxy-2026-06/spec.md` |
| Setup log (operational record) | `specs/dvswitch-proxy-2026-06/setup-log.md` |

---

This guide is maintained alongside the code. If a step here doesn't match what the code does, the code is right and the doc is wrong — patch the doc.
