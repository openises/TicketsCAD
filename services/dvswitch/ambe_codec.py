"""
AMBE+2 codec wrapper around md380-emu running on UDP port 2470.

md380-emu protocol (discovered empirically + cross-referenced against
the qemu-arm-static wrapper that DVSwitch ships):

  request:  320 bytes — 8 kHz mono 16-bit little-endian PCM
            (160 samples × 2 bytes = 20 ms of audio)
  response: 7 bytes   — AMBE+2 frame; 49 information bits packed
            MSB-first into the first 6 bytes, plus 1 LSB-stored
            bit in byte 7. The high 7 bits of byte 7 are reserved.

There is no framing, no mode byte, no session — every UDP datagram
is one frame in / one frame out. Encoding is synchronous; the
emulator replies in <2 ms on dvswitch-01.

Decode flips the same: 7-byte AMBE in, 320-byte PCM out.

Used by hbp_client to encode Piper TTS PCM for TX, and to decode
inbound DMR voice bursts for the Vosk transcription pipeline.
"""

from __future__ import annotations

import socket
import logging
from typing import Optional

LOG = logging.getLogger("hbp.ambe")

# Constants that match every DMR Tier II implementation
AMBE_FRAME_BYTES = 7        # 49 bits packed + 7 reserved bits
PCM_FRAME_BYTES  = 320      # 160 samples × 2 bytes
PCM_FRAME_MS     = 20
SAMPLE_RATE_HZ   = 8000

# md380-emu's UDP socket — defaults match DVSwitch's qemu-arm-static
# unit on dvswitch-01.
DEFAULT_HOST = "127.0.0.1"
DEFAULT_PORT = 2470


class AmbeCodec:
    """Tiny synchronous client for md380-emu.

    Thread-safety: the public methods hold no global lock. Either
    serialize calls externally (typical for a single TX path) or
    instantiate one AmbeCodec per consumer thread.
    """

    def __init__(
        self,
        host: str = DEFAULT_HOST,
        port: int = DEFAULT_PORT,
        timeout_s: float = 1.0,
    ) -> None:
        self.host = host
        self.port = port
        self.timeout_s = timeout_s
        self.sock: Optional[socket.socket] = None
        self._reopen()

    def _reopen(self) -> None:
        if self.sock:
            try:
                self.sock.close()
            except OSError:
                pass
        s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        s.settimeout(self.timeout_s)
        # Letting the OS pick a source port; md380-emu replies to
        # whatever port the request came from.
        s.bind(("", 0))
        self.sock = s

    def close(self) -> None:
        if self.sock:
            try:
                self.sock.close()
            except OSError:
                pass
            self.sock = None

    # ── encode ──────────────────────────────────────────────────
    def encode(self, pcm: bytes) -> bytes:
        """Encode 20 ms of PCM into one 49-bit AMBE+2 frame.

        Raises ValueError if pcm is not exactly 320 bytes.
        Raises socket.timeout if md380-emu doesn't reply within
        self.timeout_s.
        """
        if len(pcm) != PCM_FRAME_BYTES:
            raise ValueError(
                f"pcm must be exactly {PCM_FRAME_BYTES} bytes "
                f"(20 ms at 8 kHz 16-bit mono), got {len(pcm)}"
            )
        assert self.sock is not None
        self.sock.sendto(pcm, (self.host, self.port))
        data, _ = self.sock.recvfrom(64)
        if len(data) != AMBE_FRAME_BYTES:
            raise RuntimeError(
                f"md380-emu returned {len(data)} bytes, expected "
                f"{AMBE_FRAME_BYTES}: {data.hex()}"
            )
        return data

    def encode_stream(self, pcm: bytes) -> list[bytes]:
        """Encode an arbitrary-length PCM stream as a list of
        AMBE+2 frames. PCM is zero-padded up to a 20 ms boundary at
        the end so the last frame is well-formed."""
        out = []
        n = len(pcm)
        for off in range(0, n, PCM_FRAME_BYTES):
            chunk = pcm[off : off + PCM_FRAME_BYTES]
            if len(chunk) < PCM_FRAME_BYTES:
                chunk = chunk + b"\x00" * (PCM_FRAME_BYTES - len(chunk))
            out.append(self.encode(chunk))
        return out

    # ── decode ──────────────────────────────────────────────────
    def decode(self, ambe: bytes) -> bytes:
        """Decode one 7-byte AMBE+2 frame back to 20 ms of PCM."""
        if len(ambe) != AMBE_FRAME_BYTES:
            raise ValueError(
                f"ambe must be exactly {AMBE_FRAME_BYTES} bytes, "
                f"got {len(ambe)}"
            )
        assert self.sock is not None
        self.sock.sendto(ambe, (self.host, self.port))
        data, _ = self.sock.recvfrom(2048)
        if len(data) != PCM_FRAME_BYTES:
            raise RuntimeError(
                f"md380-emu returned {len(data)} bytes, expected "
                f"{PCM_FRAME_BYTES}"
            )
        return data


# ── self-test: round-trip a sine wave ─────────────────────────────
def _selftest() -> int:
    """Encode → decode a 1 kHz sine wave and check the decoded audio
    has reasonable magnitude. Returns 0 on success, non-zero on fail.
    """
    import math
    import struct

    logging.basicConfig(level=logging.INFO,
                        format="%(asctime)s %(levelname)s [%(name)s] %(message)s")

    codec = AmbeCodec()
    try:
        # 1 kHz sine, 0.5 amplitude, 20 ms = 160 samples
        samples = [int(0.5 * 32767 * math.sin(2 * math.pi * 1000 * i / 8000))
                   for i in range(160)]
        pcm = struct.pack("<160h", *samples)
        ambe = codec.encode(pcm)
        if len(ambe) != AMBE_FRAME_BYTES:
            print(f"FAIL: AMBE wrong size: {len(ambe)}")
            return 1
        LOG.info("encoded 1kHz sine → %s", ambe.hex())

        # Round-trip back
        pcm2 = codec.decode(ambe)
        if len(pcm2) != PCM_FRAME_BYTES:
            print(f"FAIL: round-trip PCM wrong size: {len(pcm2)}")
            return 1

        # Sanity-check the decoded audio has magnitude
        recovered = struct.unpack("<160h", pcm2)
        peak = max(abs(s) for s in recovered)
        LOG.info("round-trip PCM peak amplitude = %d (should be >> 0)", peak)
        if peak < 100:
            print(f"FAIL: round-trip audio is silent (peak={peak})")
            return 1

        # Silence test — 20 ms of zeros
        silence = b"\x00" * PCM_FRAME_BYTES
        ambe_sil = codec.encode(silence)
        LOG.info("silence frame AMBE = %s", ambe_sil.hex())
        if ambe_sil == ambe:
            print("FAIL: silence and sine produced identical AMBE")
            return 1

        print("OK — md380-emu codec round-trip succeeds")
        return 0
    finally:
        codec.close()


if __name__ == "__main__":
    raise SystemExit(_selftest())
