"""
Native audio frame primitives for the TicketsCAD audio matrix (Phase 114c).

The internal format is the SAME one the DMR bridge's AudioPump already
speaks (see services/dvswitch/hbp_client.py): 16-bit signed little-endian
mono PCM at 8000 Hz, in 20 ms / 320-byte / 160-sample frames. That format
is natively identical to USRP payloads, AudioSocket audio frames, and the
md380-emu feed, so resampling is only ever needed at Opus edges
(Zello / WebRTC / Mumble, 48 kHz) — never inside the matrix.

Mixing/gain are implemented with the stdlib `array` module and manual
int16 math, NOT `audioop`: audioop is removed in Python 3.13 (training
runs 3.13.5) and deprecated from 3.11. `array`-based math works
identically on 3.9 → 3.13+ with no external dependency.
"""

from __future__ import annotations

from array import array

SAMPLE_RATE = 8000          # Hz
FRAME_MS = 20               # milliseconds per frame
SAMPLES_PER_FRAME = SAMPLE_RATE * FRAME_MS // 1000   # 160
BYTES_PER_FRAME = SAMPLES_PER_FRAME * 2              # 320 (int16)

SILENCE = b"\x00" * BYTES_PER_FRAME

_INT16_MIN = -32768
_INT16_MAX = 32767


def _clamp(v: int) -> int:
    """Saturate an int to the int16 range (prevents mix wrap-around)."""
    if v > _INT16_MAX:
        return _INT16_MAX
    if v < _INT16_MIN:
        return _INT16_MIN
    return v


def is_silence(frame: bytes) -> bool:
    """True if the frame is all-zero (idle). Cheap fast-path check."""
    return frame == SILENCE or not frame


def rms(frame: bytes) -> float:
    """
    Root-mean-square amplitude of a frame (0..32767). Used for voice-
    activity detection when a leg does not provide an explicit keyed flag.
    """
    if not frame:
        return 0.0
    samples = array("h")
    samples.frombytes(frame[:BYTES_PER_FRAME])
    if not len(samples):
        return 0.0
    total = 0
    for s in samples:
        total += s * s
    return (total / len(samples)) ** 0.5


def apply_gain(frame: bytes, factor: float) -> bytes:
    """
    Scale a frame by a linear factor (1.0 = unity), clamping to int16.
    factor==1.0 returns the input unchanged (hot path for full-volume
    routes). Silence in → silence out.
    """
    if factor == 1.0 or is_silence(frame):
        return frame if len(frame) == BYTES_PER_FRAME else _pad(frame)
    samples = array("h")
    samples.frombytes(_pad(frame))
    out = array("h", (_clamp(int(s * factor)) for s in samples))
    return out.tobytes()


def mix(frames) -> bytes:
    """
    Sum any number of frames sample-by-sample with int16 saturation.
    Empty input or all-silence → SILENCE. A single frame is returned as-is
    (common: exactly one active source into a destination).
    """
    active = [f for f in frames if f and not is_silence(f)]
    if not active:
        return SILENCE
    if len(active) == 1:
        f = active[0]
        return f if len(f) == BYTES_PER_FRAME else _pad(f)
    acc = [0] * SAMPLES_PER_FRAME
    for f in active:
        samples = array("h")
        samples.frombytes(_pad(f))
        for i in range(SAMPLES_PER_FRAME):
            acc[i] += samples[i]
    out = array("h", (_clamp(v) for v in acc))
    return out.tobytes()


def db_to_factor(db: float) -> float:
    """Convert a gain in decibels to a linear multiplier (0 dB → 1.0)."""
    if db == 0.0:
        return 1.0
    return 10.0 ** (db / 20.0)


def _pad(frame: bytes) -> bytes:
    """Normalize a frame to exactly BYTES_PER_FRAME (pad/truncate)."""
    if len(frame) == BYTES_PER_FRAME:
        return frame
    if len(frame) > BYTES_PER_FRAME:
        return frame[:BYTES_PER_FRAME]
    return frame + b"\x00" * (BYTES_PER_FRAME - len(frame))
