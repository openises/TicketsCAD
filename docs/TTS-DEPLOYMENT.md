# Text-to-Speech (TTS) — deployment & service options

TicketsCAD speaks in two places: the **DMR radio bridge** (weather bulletins,
operator-approved radio-AI replies, dispatch announcements) and the **Zello
proxy** (weather bulletins + routed TTS onto Zello channels). Both use Piper.
This document covers how the shipped TTS works, how to deploy it on a new
install, and the design for plugging in hosted TTS services.

---

## 1. What ships today: Piper (self-hosted, free, offline)

The DMR bridge (`services/dvswitch/` — `bridge.py` / `hbp_client.py`) synthesizes
with **[Piper](https://github.com/rhasspy/piper)**, a fast local neural TTS:

- **Free, no signup, no API key, no per-character billing.**
- **Offline** — keeps working when your internet is down, which matters for a
  public-safety tool. Latency is milliseconds on any modern CPU (no GPU needed).
- Runs on the same VM as the bridge (reference install: `dvswitch-01`,
  10.0.0.10, systemd-managed).

### Installing Piper on a new bridge VM (Debian/Ubuntu)

```bash
# 1. Piper binary (or: pip install piper-tts)
wget https://github.com/rhasspy/piper/releases/latest/download/piper_linux_x86_64.tar.gz
tar -xzf piper_linux_x86_64.tar.gz -C /opt/

# 2. A voice model (.onnx + .json pair). Browse samples: https://rhasspy.github.io/piper-samples/
mkdir -p /opt/piper/voices
cd /opt/piper/voices
wget https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx
wget https://huggingface.co/rhasspy/piper-voices/resolve/main/en/en_US/lessac/medium/en_US-lessac-medium.onnx.json

# 3. Smoke test
echo "Weather bulletin test." | /opt/piper/piper \
    --model /opt/piper/voices/en_US-lessac-medium.onnx --output_file /tmp/t.wav
```

Voice guidance for radio: **medium**-quality voices are the sweet spot — the
audio ends up in 8 kHz AMBE anyway, so high-quality models buy nothing. `lessac`
and `ryan` (en_US) are clear through a repeater; test through YOUR audio path,
not just headphones.

### Where it's configured

- **Per-channel (DMR):** the `dmr_channels` table carries `tts_engine` +
  `tts_voice` columns (Settings → Communications → DMR). Today `piper` is the
  implemented engine; the voice value is the model name the bridge loads.
- **Weather bulletins:** the read-out voice is `weather_tts_voice` on the
  Weather Alerts settings page (blank = the channel's default voice).

### Zello proxy TTS (weather read-outs + routed TTS to Zello)

The Zello proxy has its **own** Piper install — it runs on the web VM (where
the proxy daemon lives), not the DMR bridge VM. It drains `zello_outbox`
rows with `kind='tts'`: synthesize with Piper → resample with ffmpeg →
Opus-encode → key onto the Zello channel. Settings (in the `settings` table,
seeded by `sql/run_zello_tts.php`):

| Setting | Meaning | Default |
|---|---|---|
| `zello_tts_piper_bin` | Path to the piper binary on the proxy host | *(required)* |
| `zello_tts_piper_voice` | Path to the `.onnx` voice model | *(required)* |
| `zello_tts_piper_rate` | Piper's output sample rate | 22050 |
| `zello_tts_ffmpeg_bin` | ffmpeg path (blank = PATH lookup) | `ffmpeg` |
| `zello_tts_sample_rate` | Zello stream rate | 16000 |
| `zello_tts_frame_ms` | Opus frame duration | 20 |

Install Piper on the proxy host with the same steps as above; `apt install
ffmpeg` if it's missing. Restart the proxy service after changing these —
the daemon caches its config at startup.

---

## 2. Hosted TTS services (design + free-tier survey)

Some installs would rather sign up for a hosted voice than run Piper. The
integration hook already exists — `dmr_channels.tts_engine` — and the bridge's
synthesis step is a single function boundary (`text → 16-bit PCM`), so hosted
adapters slot in per-channel without touching the TX pipeline.

**Status: design.** Piper is the only implemented engine today. The adapters
below are specified so any of them can be added as a bridge-side module.

| Service | Free tier (verify current terms at signup) | Notes for radio use |
|---|---|---|
| **Piper** (default) | Unlimited, forever, offline | No signup. Recommended baseline. |
| **Google Cloud TTS** | ~4M chars/mo Standard, ~1M chars/mo WaveNet (ongoing) | Excellent voices; needs a GCP account + API key |
| **Amazon Polly** | ~5M chars/mo for the first 12 months | Neural voices; AWS account; pay-as-you-go after year 1 |
| **Microsoft Azure Speech** | ~500K chars/mo neural (ongoing free tier) | Very natural; Azure account |
| **ElevenLabs** | Small monthly free allowance | Most natural, but the free tier is thin for automated alerts |

Scale check: a weather bulletin is ~300 characters. Even a violent-weather month
with 200 bulletins is ~60K characters — **every free tier above covers it with
room to spare.** The real trade-offs are (a) internet dependency during exactly
the storms you're alerting about, and (b) an API key to manage. That's why Piper
stays the default and hosted engines are per-channel opt-ins.

### How the engine choice reaches live audio (Phase 113e)

The **Voice & Speech** page (`voice-speech.php`) maps each *speech application*
to an engine. That choice now flows to the live paths:

- **Zello read-outs** — the Zello proxy resolves the `zello_readout`
  application through the registry (any engine: Piper, Kokoro, OpenAI-compatible,
  Deepgram), and always falls back to its own inline Piper if the registry path
  is unavailable, so read-outs never go silent.
- **DMR read-outs** (weather bulletins, radio-AI) — DMR audio is 8 kHz AMBE, so
  hosted/neural voices buy nothing through the vocoder; the DMR path stays on
  **Piper by design** and only the *voice model* is selectable. `weather_radio.php`
  resolves the `weather_bulletin` application's Piper voice and passes it to the
  bridge's `/tx/text` (which ignores a blank/unreadable path and uses its
  configured default).
- **Test — Listen**, and future **announcements / SIP callouts** (Phase 114) use
  the registry directly, where hosted quality actually pays off.

### Deepgram (hosted, telephony-native)

Add a Deepgram engine on the Voice & Speech page (driver **Deepgram Aura**):
enter the Aura model as the *voice* (e.g. `aura-2-thalia-en`), paste the API
key. It returns headerless audio at the exact rate we ask for — `linear16` for
PCM, or `mulaw` for the future SIP path — so there's no resample step. Best free
tier of the hosted engines and the most telephony-literate API.

### Adapter contract (for implementers)

A bridge TTS engine is a module exposing:

```python
def synthesize(text: str, voice: str, config: dict) -> bytes:
    """Return mono 16-bit PCM at 8000 Hz (the AMBE encoder's input rate).
    Raise TTSError on failure — the caller falls back to the channel's
    default engine (Piper) and logs the failover."""
```

Rules: (1) hosted engines MUST fall back to Piper on network/API failure so a
bulletin is never lost to a cloud outage; (2) API keys live in the bridge's
environment file (mode 0640, never in git — same policy as BrandMeister
passwords); (3) resample to 8 kHz server-side (most services return 22–24 kHz);
(4) cap request length to the weather `max_seconds` budget before calling out.

---

## 3. Troubleshooting

- **Silent TX / empty audio:** run the Piper smoke test above on the bridge VM;
  check the model + .json are both present and readable by the service user.
- **Robotic or clipped audio:** confirm the resample to 8 kHz mono happens once
  (double-resampling sounds underwater); keep bulletin text inside the
  `weather_tts_max_seconds` budget.
- **Which engine actually spoke?** The bridge log lines tag the engine + voice
  per TX (`journalctl -u <bridge service> -n 100`).
