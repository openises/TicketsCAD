"""
HttpStreamLeg — a public internet audio stream (Broadcastify, LiveATC,
Icecast, or any plain HTTP MP3/AAC/OGG feed) as a read-only matrix channel
(2026-09-08, Eric: "I'd like a source of audio we can listen to while
testing services").

This is a TEST/DEV convenience, not an operator-facing capability: a way to
patch a continuous, real, always-available audio source into the console's
patch rail (Sel/Mon/Mute/Volume, mixing, routes) without needing live Zello
or DMR traffic on hand. It ships attached to nothing by default, exactly
like every other adapter in this service — see attach_http_stream_leg()'s
own "mode: off" default in service.py.

Decoding: the matrix's internal bus is 8 kHz mono 16-bit PCM (frame.py).
A public stream can be MP3, AAC, OGG/Vorbis, or anything else Icecast-family
services serve, at any sample rate/channel count. Rather than bundling a
codec library (this project's stated stdlib-first preference for bridge/
service processes — see requirements.txt's own reasoning, and services/
sip-bridge/requirements.txt's identical stance), this leg shells out to
`ffmpeg` as a subprocess and lets it do the decode + resample + downmix in
one step: `ffmpeg -re -i <url> -f s16le -ar 8000 -ac 1 -`. `ffmpeg` is a
SYSTEM dependency (apt-installed, not a pip package) -- the only one this
service has ever needed; see docs/AUDIO-MATRIX-SETUP.md and install.sh for
where it's installed.

One-way: outbound() (matrix -> leg) is a no-op. There is nothing to send a
public listen-only stream back to.

The subprocess spawn is injectable (open_stream) so the fixture test drives
this leg with a canned byte generator and never touches ffmpeg or a real
network -- same pattern as DmrLeg's open_stream/post_audio. See
tests/test_http_stream_leg.py.
"""

from __future__ import annotations

import logging
import subprocess
import threading
import time
from typing import Callable, Iterable, Optional

try:
    from frame import BYTES_PER_FRAME, SAMPLE_RATE
except ImportError:  # pragma: no cover - direct-module fallback
    from ..frame import BYTES_PER_FRAME, SAMPLE_RATE  # type: ignore

LOG = logging.getLogger("audio-matrix.http_stream")

# Read chunk size from ffmpeg's stdout. Not frame-aligned by construction
# (ffmpeg writes whatever it has ready) -- _push_pcm() below carries any
# leftover bytes across reads so frames stay aligned to real 20ms boundaries
# rather than clicking at arbitrary chunk edges.
READ_CHUNK = 4096

# Backoff before reconnecting after the stream drops (ffmpeg exits, a 404,
# a network blip). A public feed can be flaky; this must never hot-loop.
RECONNECT_BACKOFF_S = 5.0


class HttpStreamLeg:
    def __init__(
        self,
        core,
        channel_id: str,
        url: str,
        ffmpeg_path: str = "ffmpeg",
        open_stream: Optional[Callable[[], Iterable[bytes]]] = None,
    ):
        self.core = core
        self.channel_id = channel_id
        self.url = url
        self.ffmpeg_path = ffmpeg_path
        self._open_stream = open_stream or self._default_open_stream

        self._rx_thread: Optional[threading.Thread] = None
        self._running = False
        self._proc: Optional[subprocess.Popen] = None
        self._leftover = b""

        # counters for /health + tests
        self.rx_frames = 0
        self.reconnects = 0

    # ── lifecycle ────────────────────────────────────────────────────
    def start_rx(self) -> None:
        if self._running:
            return
        self._running = True
        self._rx_thread = threading.Thread(
            target=self._rx_loop, name=f"http-stream-leg-rx-{self.channel_id}", daemon=True
        )
        self._rx_thread.start()

    def stop(self) -> None:
        self._running = False
        proc = self._proc
        if proc is not None:
            try:
                proc.terminate()
            except Exception:  # noqa: BLE001 - already gone
                pass

    # ── matrix -> leg: nothing to send back to a listen-only stream ───
    def outbound(self, frame: bytes) -> None:
        pass

    # ── RX loop ─────────────────────────────────────────────────────
    def _rx_loop(self) -> None:
        first_attempt = True
        while self._running:
            if not first_attempt:
                self.reconnects += 1
                time.sleep(RECONNECT_BACKOFF_S)
            first_attempt = False
            try:
                for chunk in self._open_stream():
                    if not self._running:
                        break
                    if chunk:
                        self._push_pcm(chunk)
            except Exception as e:  # noqa: BLE001 - stream dropped; retry
                LOG.warning("http_stream %s: read error on %s: %s (reconnecting)",
                            self.channel_id, self.url, e)
            finally:
                self._leftover = b""  # a fresh connection starts frame-aligned

    def _push_pcm(self, chunk: bytes) -> None:
        """Slice a raw PCM byte chunk into 320-byte frames, carrying any
        leftover partial-frame bytes into the NEXT chunk so frames stay
        aligned to real 20ms boundaries across arbitrary ffmpeg write sizes."""
        buf = self._leftover + chunk
        n_full = len(buf) // BYTES_PER_FRAME
        for i in range(n_full):
            frame = buf[i * BYTES_PER_FRAME:(i + 1) * BYTES_PER_FRAME]
            self.core.inbound(self.channel_id, frame, keyed=True)
            self.rx_frames += 1
        self._leftover = buf[n_full * BYTES_PER_FRAME:]

    def health(self) -> dict:
        return {
            "channel_id": self.channel_id,
            "url": self.url,
            "rx_frames": self.rx_frames,
            "reconnects": self.reconnects,
            "running": self._running,
        }

    # ── default network seam (overridable for tests) ──────────────────
    def _default_open_stream(self) -> Iterable[bytes]:
        # -re paces ffmpeg's read of the INPUT at the input's own real-time
        # rate rather than devouring it as fast as disk/network allows --
        # correct for a live radio-style feed (we want a steady 20ms/frame
        # trickle, not the whole stream buffered instantly). -loglevel error
        # keeps ffmpeg's own chatter out of this service's logs.
        cmd = [
            self.ffmpeg_path,
            "-loglevel", "error",
            "-re", "-i", self.url,
            "-f", "s16le", "-ar", str(SAMPLE_RATE), "-ac", "1",
            "-",
        ]
        self._proc = subprocess.Popen(
            cmd, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
        )
        return _iter_stdout_chunks(self._proc)


def _iter_stdout_chunks(proc: subprocess.Popen) -> Iterable[bytes]:
    try:
        while True:
            chunk = proc.stdout.read(READ_CHUNK)
            if not chunk:
                return  # ffmpeg exited / stream ended
            yield chunk
    finally:
        try:
            proc.terminate()
            proc.wait(timeout=3.0)
        except Exception:  # noqa: BLE001 - best-effort cleanup
            try:
                proc.kill()
            except Exception:  # noqa: BLE001
                pass
