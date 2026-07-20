"""
DmrLeg — connects the audio matrix to the live DMR bridge (Phase 114c).

The DMR bridge (services/dvswitch/hbp_client.py) already terminates the
BrandMeister/hotspot RF path and exposes two localhost HTTP seams on
DMR_HTTP_PORT (default 18091):

  RX  GET  /audio-stream   NDJSON, one JSON object per line:
          {"event":"audio","pcm":"<base64 s16le 8k>","tx_origin":bool,...}
          (also call_start / call_end / transcript / keepalive lines)
  TX  POST /tx/audio        body = any ffmpeg-decodable audio; keys ONE
          DMR TG call for the whole body, returns 202 {tx_id,...}

This leg bridges those to the matrix's 8 kHz / 20 ms / 320-byte frame bus:

  * RX: stream the NDJSON, base64-decode each `audio` event's PCM, slice it
    into 320-byte frames, and core.inbound(channel_id, frame) each one. The
    matrix's per-channel jitter buffer absorbs the ~60 ms (3-frame) bursts.

  * TX: the matrix delivers one 20 ms frame per tick via outbound(). A DMR
    call is NOT one frame — it's an utterance. So we VOX-buffer: accumulate
    frames while audio is flowing, and when the routed audio goes quiet for
    `hang_ms`, flush the buffered PCM as a single WAV to /tx/audio (one keyup
    per utterance, exactly like the widget PTT path).

CRITICAL loop guard: the bridge loops locally-originated TX back into
/audio-stream tagged `tx_origin:true` (so other dispatch widgets hear it).
If we re-ingested those we'd route our own TX straight back out to /tx/audio
— an infinite feedback keyup. skip_tx_origin (default True) drops them. Real
RF from the air has no tx_origin flag and flows normally.

The two network seams (`_open_stream`, `_post_audio`) are injectable so the
fixture test drives the leg with canned NDJSON and captures TX WAVs without
any radio, bridge, or socket. See tests/test_dmr_leg.py.
"""

from __future__ import annotations

import base64
import io
import json
import logging
import threading
import time
import wave
from typing import Callable, Iterable, Optional

# Import from the sibling matrix package. This file lives in
# services/audio-matrix/legs/; the core is one dir up on sys.path when the
# service boots (service.py inserts services/audio-matrix). Keep the import
# name-only so the fixture test's sys.path shim works the same way.
try:
    from frame import BYTES_PER_FRAME, SAMPLE_RATE, is_silence
except ImportError:  # pragma: no cover - direct-module fallback
    from ..frame import BYTES_PER_FRAME, SAMPLE_RATE, is_silence  # type: ignore

LOG = logging.getLogger("audio-matrix.dmr")

DEFAULT_BRIDGE = "http://127.0.0.1:18091"


def pcm_to_wav(pcm: bytes, rate: int = SAMPLE_RATE) -> bytes:
    """Wrap raw s16le mono PCM in a WAV container. The bridge's /tx/audio
    runs `ffmpeg -i pipe:0` which auto-detects the input; raw headerless
    PCM is NOT auto-detectable, but a WAV header is, so we always frame
    our TX as WAV."""
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(pcm)
    return buf.getvalue()


class DmrLeg:
    def __init__(
        self,
        core,
        channel_id: str = "dmr_bm",
        bridge_url: str = DEFAULT_BRIDGE,
        hang_ms: int = 600,
        max_utterance_ms: int = 30000,
        skip_tx_origin: bool = True,
        open_stream: Optional[Callable[[], Iterable[bytes]]] = None,
        post_audio: Optional[Callable[[bytes], tuple]] = None,
    ):
        self.core = core
        self.channel_id = channel_id
        self.bridge_url = bridge_url.rstrip("/")
        self.hang_frames = max(1, int(hang_ms / 20))
        self.max_frames = max(1, int(max_utterance_ms / 20))
        self.skip_tx_origin = skip_tx_origin
        self._open_stream = open_stream or self._default_open_stream
        self._post_audio = post_audio or self._default_post_audio

        # RX thread state
        self._rx_thread: Optional[threading.Thread] = None
        self._running = False

        # TX VOX buffer state (touched only from the matrix tick thread via
        # outbound(), plus the flush it triggers — single-threaded, no lock).
        self._tx_buf: list[bytes] = []
        self._silence_run = 0
        self._tx_active = False

        # counters for /health + tests
        self.rx_frames = 0
        self.rx_skipped_tx_origin = 0
        self.tx_utterances = 0
        self.tx_frames = 0

    # ── RX: DMR air -> matrix ────────────────────────────────────────
    def start_rx(self) -> None:
        if self._running:
            return
        self._running = True
        self._rx_thread = threading.Thread(
            target=self._rx_loop, name=f"dmr-leg-rx-{self.channel_id}", daemon=True
        )
        self._rx_thread.start()

    def stop(self) -> None:
        self._running = False
        # flush any half-buffered utterance so it isn't lost on shutdown
        if self._tx_active:
            self._flush_tx()

    def _rx_loop(self) -> None:
        while self._running:
            try:
                for line in self._open_stream():
                    if not self._running:
                        break
                    self._ingest_line(line)
            except Exception as e:  # noqa: BLE001 - connection dropped; retry
                LOG.warning("DMR /audio-stream read error: %s (reconnecting)", e)
            if self._running:
                time.sleep(1.0)  # brief backoff before reconnect

    def _ingest_line(self, line: bytes) -> None:
        line = line.strip()
        if not line:
            return
        try:
            evt = json.loads(line)
        except (ValueError, TypeError):
            return
        if evt.get("event") != "audio":
            return  # call_start / call_end / transcript / keepalive: ignore
        if self.skip_tx_origin and evt.get("tx_origin"):
            self.rx_skipped_tx_origin += 1
            return
        b64 = evt.get("pcm")
        if not b64:
            return
        try:
            pcm = base64.b64decode(b64)
        except (ValueError, TypeError):
            return
        self._push_pcm(pcm)

    def _push_pcm(self, pcm: bytes) -> None:
        """Slice arbitrary-length PCM into 320-byte frames into the matrix.
        A trailing partial frame is zero-padded to a full 20 ms frame."""
        for off in range(0, len(pcm), BYTES_PER_FRAME):
            frame = pcm[off:off + BYTES_PER_FRAME]
            if len(frame) < BYTES_PER_FRAME:
                frame = frame + b"\x00" * (BYTES_PER_FRAME - len(frame))
            self.core.inbound(self.channel_id, frame)
            self.rx_frames += 1

    # ── TX: matrix -> DMR air (VOX-buffered per utterance) ───────────
    def outbound(self, frame: bytes) -> None:
        """Called by the matrix tick every 20 ms with the frame routed to
        this channel (SILENCE when nothing is patched in). We accumulate a
        keyed utterance and flush it as one DMR call on the hang timer."""
        silent = is_silence(frame)
        if not silent:
            # Speech (or mid-speech): (re)start/extend the utterance.
            self._tx_buf.append(frame)
            self._silence_run = 0
            self._tx_active = True
        elif self._tx_active:
            # Silence while an utterance is open — keep short internal gaps,
            # but count toward the hang timer.
            self._tx_buf.append(frame)
            self._silence_run += 1
            if self._silence_run >= self.hang_frames:
                self._flush_tx()
                return
        # else: silence with no open utterance — nothing to do.

        if self._tx_active and len(self._tx_buf) >= self.max_frames:
            # Safety cap: never let a stuck-open route buffer unbounded audio.
            LOG.warning("DMR TX utterance hit max length; forcing flush")
            self._flush_tx()

    def _flush_tx(self) -> None:
        # Trim the trailing silence (the hang tail) so the keyup is tight.
        buf = self._tx_buf
        self._tx_buf = []
        active = self._tx_active
        self._tx_active = False
        self._silence_run = 0
        if not active:
            return
        while buf and is_silence(buf[-1]):
            buf.pop()
        if not buf:
            return
        pcm = b"".join(buf)
        self.tx_utterances += 1
        self.tx_frames += len(buf)
        try:
            status, resp = self._post_audio(pcm_to_wav(pcm))
            if status not in (200, 202):
                LOG.warning("DMR /tx/audio returned %s: %s", status, resp)
        except Exception as e:  # noqa: BLE001 - never let TX kill the tick
            LOG.warning("DMR /tx/audio POST failed: %s", e)

    # ── default network seams (overridable for tests) ────────────────
    def _default_open_stream(self) -> Iterable[bytes]:
        import urllib.request

        req = urllib.request.Request(self.bridge_url + "/audio-stream")
        resp = urllib.request.urlopen(req, timeout=30)  # noqa: S310 - localhost
        return _iter_lines(resp)

    def _default_post_audio(self, wav: bytes) -> tuple:
        import urllib.error
        import urllib.request

        req = urllib.request.Request(
            self.bridge_url + "/tx/audio", data=wav, method="POST"
        )
        req.add_header("Content-Type", "audio/wav")
        try:
            with urllib.request.urlopen(req, timeout=30) as r:  # noqa: S310
                return r.status, json.loads(r.read().decode() or "null")
        except urllib.error.HTTPError as e:
            return e.code, {"error": e.read().decode("utf-8", "replace")[:200]}

    def health(self) -> dict:
        return {
            "channel_id": self.channel_id,
            "bridge_url": self.bridge_url,
            "rx_frames": self.rx_frames,
            "rx_skipped_tx_origin": self.rx_skipped_tx_origin,
            "tx_utterances": self.tx_utterances,
            "tx_frames": self.tx_frames,
            "tx_active": self._tx_active,
        }


def _iter_lines(resp) -> Iterable[bytes]:
    """Yield NDJSON lines from an HTTP response object (urllib response is
    already line-iterable, but wrap it so a chunked reader works too)."""
    for line in resp:
        yield line
