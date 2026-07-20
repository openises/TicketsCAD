"""
Fixture test for the DMR leg (Phase 114c) — NO radio, NO bridge, NO socket.

Drives DmrLeg through both network seams with fakes:
  * RX: a canned list of NDJSON lines (real audio events + a tx_origin
    loopback event + a keepalive) fed through _open_stream, asserting the
    right frames reach the matrix and the tx_origin event is dropped.
  * TX: the matrix delivers frames to the leg via outbound(); we assert the
    VOX buffer keys ONE utterance per speech burst, flushes on the hang
    timer, trims the trailing silence, and POSTs a valid WAV.

    python3 services/audio-matrix/tests/test_dmr_leg.py
"""

import base64
import io
import os
import sys
import wave

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "legs"))

import json  # noqa: E402

from frame import BYTES_PER_FRAME, SILENCE  # noqa: E402
from matrix_core import Channel, MatrixCore, RegClass  # noqa: E402
from dmr import DmrLeg, pcm_to_wav  # noqa: E402

_pass = 0
_fail = 0


def check(name, cond):
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"[PASS] {name}")
    else:
        _fail += 1
        print(f"[FAIL] {name}")


def tone_frame(val=1000):
    """A non-silent 20 ms frame (constant sample value)."""
    import struct
    return struct.pack("<160h", *([val] * 160))


# ══ RX path ═══════════════════════════════════════════════════════════
core = MatrixCore()
core.add_channel(Channel("dmr_bm", "TG 3127", RegClass.AMATEUR))
core.add_channel(Channel("disp", "Dispatch", RegClass.INTERNAL))

# 960 bytes = 3 frames of real RF audio; then an identical chunk tagged
# tx_origin (our own loopback — must be dropped); then a keepalive.
rf_pcm = tone_frame(1200) + tone_frame(1200) + tone_frame(1200)
canned = [
    (json.dumps({"event": "call_start", "call_id": "aa", "src_id": 3104410,
                 "talkgroup": 3127}) + "\n").encode(),
    (json.dumps({"event": "audio", "call_id": "aa", "seq": 1,
                 "pcm": base64.b64encode(rf_pcm).decode()}) + "\n").encode(),
    (json.dumps({"event": "audio", "call_id": "bb", "seq": 2, "tx_origin": True,
                 "pcm": base64.b64encode(rf_pcm).decode()}) + "\n").encode(),
    b'{"event":"keepalive"}\n',
    (json.dumps({"event": "call_end", "call_id": "aa"}) + "\n").encode(),
]

leg = DmrLeg(core, channel_id="dmr_bm", open_stream=lambda: iter(canned))
# Drive the RX ingest synchronously (no thread) for deterministic assertions.
for _line in canned:
    leg._ingest_line(_line)

check("RX ingested 3 real frames", leg.rx_frames == 3)
check("RX dropped the tx_origin loopback event", leg.rx_skipped_tx_origin == 1)

# Those 3 frames should be sitting in the matrix's dmr_bm jitter buffer.
check("frames queued on the matrix channel", len(core.channel("dmr_bm")._inq) == 3)

# A tick pulls one frame; route dmr_bm -> disp so we can see it flow.
cap = []
core.channel("disp").leg = type("L", (), {"outbound": lambda self, f: cap.append(f)})()
core.add_route(__import__("matrix_core").Route(src="dmr_bm", dst="disp"))
core.tick()
check("tick delivered dmr audio to dispatch", cap and cap[-1] != SILENCE)


# ══ TX path (VOX buffering) ═══════════════════════════════════════════
posted = []


def fake_post(wav):
    posted.append(wav)
    return 202, {"accepted": True, "tx_id": "deadbeef"}


txleg = DmrLeg(core, channel_id="dmr_bm", hang_ms=60, post_audio=fake_post)
# hang_ms=60 -> 3 silent frames closes an utterance.

# Utterance 1: 5 speech frames, then 3 silent frames (triggers flush).
for _ in range(5):
    txleg.outbound(tone_frame(2000))
check("utterance open, not yet flushed", txleg.tx_utterances == 0 and len(posted) == 0)
for _ in range(3):
    txleg.outbound(SILENCE)
check("hang timer flushed one utterance", txleg.tx_utterances == 1 and len(posted) == 1)

# The flushed WAV should contain exactly the 5 speech frames (trailing
# silence trimmed), as 8 kHz / 16-bit / mono.
with wave.open(io.BytesIO(posted[0]), "rb") as w:
    check("WAV is 8k mono 16-bit", w.getframerate() == 8000 and w.getnchannels() == 1
          and w.getsampwidth() == 2)
    n = w.getnframes()
    check("WAV holds 5 frames of speech (silence trimmed)", n == 5 * 160)

# More silence must NOT key another empty call.
for _ in range(10):
    txleg.outbound(SILENCE)
check("silence alone never keys a call", txleg.tx_utterances == 1)

# Utterance 2: interleaved short gap inside speech stays ONE utterance.
txleg.outbound(tone_frame(2000))
txleg.outbound(SILENCE)              # 1-frame internal gap (< hang)
txleg.outbound(tone_frame(2000))
for _ in range(3):
    txleg.outbound(SILENCE)          # hang -> flush
check("internal short gap stays one utterance", txleg.tx_utterances == 2)

# max_utterance cap forces a flush even with no silence.
capleg = DmrLeg(core, channel_id="dmr_bm", hang_ms=10000,
                max_utterance_ms=100, post_audio=fake_post)  # 100 ms = 5 frames
for _ in range(5):
    capleg.outbound(tone_frame(2000))
check("max-length cap forced a flush", capleg.tx_utterances == 1)

# stop() flushes a half-open utterance so no audio is lost on shutdown.
stopleg = DmrLeg(core, channel_id="dmr_bm", hang_ms=10000, post_audio=fake_post)
before = len(posted)
stopleg.outbound(tone_frame(2000))
stopleg.outbound(tone_frame(2000))
stopleg.stop()
check("stop() flushes the open utterance", len(posted) == before + 1)


# ══ helper: pcm_to_wav round-trip ═════════════════════════════════════
pcm = tone_frame(500) * 4
wav = pcm_to_wav(pcm)
with wave.open(io.BytesIO(wav), "rb") as w:
    check("pcm_to_wav round-trips samples", w.getnframes() == 4 * 160)
    check("pcm_to_wav preserves PCM bytes", w.readframes(4 * 160) == pcm)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
