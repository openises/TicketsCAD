# TicketsCAD DMR/BrandMeister — codec + protocol lessons

Concrete knowledge from Phases 82-85 building the native Python HBP client (`services/bridge/hbp_client.py`) that transmits PCM audio through the DMR codec chain to BrandMeister's Homebrew Protocol.

For the general browser-audio-to-voice-service pattern, see `~/.claude/skills/browser-audio-to-voice-service/SKILL.md` and the sibling `newui-dev/newui/docs/ZELLO-PROXY-LESSONS.md`.

## The stack

```
Browser (Radio widget in ES5)
  ├─ AudioWorklet capture PCM @ 48 kHz mono
  └─ WebSocket → ws(s)://<host>:8091 (bridge, not proxy)
     ↓
Python bridge (services/bridge/bridge.py — systemd)
  ├─ Piper TTS (also generates PCM here for Claude-on-radio replies)
  ├─ md380-emu subprocess: PCM → AMBE+2 49-bit voice frames
  ├─ hbp_client.py: assemble DMR TX state machine
  │   ├─ Encoders (all ports from MMDVMHost C++):
  │   │   BPTC(196,96)  — voice header parity
  │   │   Golay(20,8)   — Slot Type
  │   │   RS(12,9) Reed-Solomon — LC parity (NOT the fake CRC-24 many guides show)
  │   │   EMB           — voice bursts B-F
  │   │   Embedded LC   — variable-length BPTC for bursts B-E
  │   ├─ 3× LC headers (BM demands 3, most guides say 1)
  │   ├─ Voice superframe A-F (A always sync, B-F EMB carriers)
  │   ├─ Terminator with continuous seq num
  │   └─ Paced send (60 ms superframe cadence)
  └─ UDP → BrandMeister HBP master (bm.pd0zry.nl or a regional master)
     ↓
   BrandMeister network → TG 3127 (or whatever routed) → Pi-Star / MMDVM hotspots → RF
```

## Why native Python and not DVSwitch

DVSwitch is a stack of userland C tools (Analog_Bridge + MMDVM_Bridge + USRP) chained via UDP. It works, but:

1. Each hop adds ~30-60 ms latency
2. Configuration is spread across 6+ files
3. It runs its own AMBE encoder that fights our Piper output timing
4. BrandMeister preemption behavior (see below) is baked into MMDVM_Bridge and hard to disable

Native Python (Phase 84) replaced this with a single process. Cost: reimplement every DMR codec layer. Benefit: full control, half the latency.

## The codec layers (in the order the packet is built)

**Voice header** (sent 3× at TX start):
```
Sync pattern (48 bits)
  Slot Type (5 bits data type + 5 bits color code)  — Golay(20,8)
  Voice LC header:                                    — RS(12,9)
    FLCO   (6 bits)
    Feature ID (8 bits)
    Service Options (8 bits)
    Destination address (24 bits)
    Source address (24 bits)
  All above wrapped in                                — BPTC(196,96)
```

**Voice bursts A through F** (33-byte payload each, 60 ms apart):
```
Burst A: SYNC pattern           (audio-only, no embedded signaling)
Bursts B-E: EMB + Embedded LC   — variable-length BPTC encoded across 4 bursts
Burst F: EMB null               (terminator marker)
```

**Terminator** (1× at TX end):
```
Sync pattern
Slot Type            — Golay(20,8)
Terminator LC        — RS(12,9), wrapped in BPTC(196,96)
```

## Codec pitfalls we hit and fixed

### Pitfall 1: Fake CRC-24 instead of real RS(12,9)

Many DMR guides — including some MMDVMHost forks — use a placeholder CRC-24 for LC parity. It happens to satisfy the *format* but fails BrandMeister's LC validity check. BM will accept your voice header, refuse your voice bursts, and disconnect.

**Fix (Phase 84k):** port the actual RS(12,9) Reed-Solomon encoder from MMDVMHost's `RS129.cpp`. This is a specific parameterization — not any RS(n,k) library will do. Look at `services/bridge/rs129.py` for the port.

**Symptom:** BM logs show "LC parity FAIL" or "voice burst rejected." You'll see your TX get header-only.

### Pitfall 2: One LC header, not three

Docs and reference implementations often show one voice LC header at TX start. BrandMeister *demands* three — sent consecutively at frame 0, 1, 2 with the same content. Without all three, BM's hoseline decoder gets confused about stream state and either drops audio or reports the wrong caller.

**Fix (Phase 84l):** hardcode 3× LC headers in the TX state machine. Sequence numbers must be continuous through all three (0, 1, 2), not reset.

### Pitfall 3: BM stream_id format ≠ MMDVMHost stream_id format

BrandMeister expects `stream_id` as a **random 32-bit unsigned integer** that stays constant for the whole TX. MMDVMHost historically used it as a monotonic counter. If you send a monotonic counter, BM sees each burst as a NEW stream and truncates constantly.

**Fix (Phase 84l):** `stream_id = random.randint(0, 0xFFFFFFFF)` at TX start, constant across all bursts of that TX. New random each TX.

### Pitfall 4: DTX-like empty frames confuse BM

If your PCM input has silence gaps and your AMBE encoder produces "silence" frames (all-zero payloads), BM assumes end-of-stream and terminates. Piper's TTS output has natural gaps between words, which trips this.

**Fix (Phase 84f):** never send an all-zero AMBE payload. If you're between words, either send a synthetic low-noise frame or delay the whole TX until you have continuous audio to send.

### Pitfall 5: Preemption ON by default

MMDVM_Bridge (part of DVSwitch) enables **stream preemption** by default — meaning if a higher-priority DMR TX starts, yours gets cut off mid-word. For a TicketsCAD dispatcher this is bad UX.

**Fix (Phase 77c):** made preemption configurable, default OFF. Setting: `dmr_preempt_enabled`. Ignored by hbp_client.py (which doesn't preempt anyway).

### Pitfall 6: 75-vs-30 frame anomaly

Early testing (Phase 83) had TX consistently sending 75 frames when we expected 30 for a 3-second message. Root cause: we were sending EVERY superframe including bursts A-F individually, but the TX pacing was superframe-based (60 ms). So we sent 6 bursts per superframe → 6× the expected frame count.

**Fix:** clarified that a "superframe" is A-F together (60 ms of audio), not 6 independent transmissions. Send one A + one B + one C ... one F per 60ms tick.

### Pitfall 7: BrandMeister vs DVSwitch RPTC classification (DMR TX bridge)

BrandMeister classifies each connected repeater as either **DMO** (Direct Mode Operation, simplex/hotspot) or **duplex** (real repeater with separate TX/RX freq + slots). Only duplex-classified sessions can forward INTO talkgroups; DMO sessions receive but not TX to TG.

BM classifies based on THREE RPTC config fields sent at MMDVM connection time:
1. `TX freq` and `RX freq` must differ
2. `Slots` field must include `"3"` (both slots enabled)
3. `Hardware` field must be `MMDVM_MMDVM_HS` (recognized as bridge/repeater), not `Pi-Star_Hostpot_XXX`

Only when all three conditions are met does BM allow your session to originate voice into a TG. Missing any one and your voice reaches BM but never routes to the TG (nobody hears you).

**See** `[[project_dmr_bridge_routing]]` memory for the full deep-dive.

## FCC compliance (auto-TX systems)

DMR TX on amateur frequencies is regulated by FCC §97 (US). Key rules:

- **§97.103** — station must have a control operator physically able to supervise. Fully unattended auto-TX is not a legal mode for general voice.
- **§97.113** — no music, no obscene language, no codes/ciphers, no business content, no broadcasting.
- **§97.119** — station ID. See the sibling skill `fcc-amateur-station-id` for the correct rules. Common misinterpretation: bookending EVERY PTT with callsign. Correct: 10-minute conversation rule, ID at start of conversation OR at 10-min mark whichever comes first. See Phase 85e fix.

Radio AI (Phase 85f) implements operator-in-the-loop approval before any TX. See `~/.claude/skills/claude-on-amateur-radio/SKILL.md`.

## Debugging quick reference

**"BrandMeister receives our TX header but rejects audio frames" (Phase 82a):**
- Almost certainly bad LC parity — CRC-24 placeholder instead of real RS(12,9).
- Confirm: BM log shows "hoseline received" for header timestamp but no audio in the log.
- Fix: RS(12,9) port (Phase 84k).

**"BM shows caller as random-numeric-id, not our DMR ID":**
- LC source address bytes wrong endianness. DMR uses big-endian; if your Python code uses `struct.pack('<I', dmr_id)`, you sent little-endian. Use `'>I'` or manual byte-shift.

**"Audio plays but sounds robotic/glitchy":**
- Almost always an AMBE encoder issue. md380-emu is picky about PCM format: **8 kHz, 16-bit signed, mono, little-endian**. Any deviation garbles.
- Piper produces 22.05 kHz by default. Downsample before feeding md380-emu.

**"TX truncates after ~1 second":**
- Likely stream_id not constant across bursts. See Pitfall 3.
- Or: DTX-like empty AMBE frames. See Pitfall 4.

**"TX never reaches the hoseline":**
- Check DMR ID is registered on radioid.net (Eric: 3104410).
- Check TG routing (BM dashboard → "Currently active connections").
- Check RPTC classification (see Pitfall 7).

**"Delayed audio, ~500 ms latency":**
- MMDVM buffers 4 superframes at hotspot level. Not our bug.
- If it's more than 500 ms, check UDP path to BM master — regional master vs global affects latency.

## Recovery procedures

**md380-emu process crashed:**
- systemd will restart bridge.py (Phase 85f-10). If not, manually:
  ```
  ssh training-ticketscad "sudo systemctl restart newui-bridge.service"
  ```

**BM rejects auth:**
- Check hbp_client.py is using the right BM master URL AND the correct passphrase for that master.
- Passphrases per-master, live in bridge config (not memory in this doc — check config).

**TX works but no audio flows:**
- Likely a codec issue. Enable verbose log in hbp_client.py, look for "AMBE encode failed" or "voice burst constructed with 0 bytes payload."
- Test path: `POST /tx/text?dry_run=1&text=hello` — synthesizes the burst chain without sending to BM. Watch log for burst counts.

## Related resources

- Reference code: `services/bridge/hbp_client.py`, `services/bridge/bridge.py`, `services/bridge/rs129.py`, `services/bridge/bptc.py`, `services/bridge/golay.py`
- Global playbook: `~/.claude/skills/browser-audio-to-voice-service/SKILL.md`
- Zello companion doc: `newui-dev/newui/docs/ZELLO-PROXY-LESSONS.md`
- FCC §97.119: `~/.claude/skills/fcc-amateur-station-id/SKILL.md`
- Claude on radio: `~/.claude/skills/claude-on-amateur-radio/SKILL.md`
- Real-time proxy architecture: `~/.claude/skills/realtime-streaming-proxy/SKILL.md`

## Origin

Distilled from Phases 82-85 (approximately 6 weeks of work in 2026-04 through 2026-06). Consolidated 2026-06-30 alongside the Zello proxy documentation to make future audio/voice work smoother.
