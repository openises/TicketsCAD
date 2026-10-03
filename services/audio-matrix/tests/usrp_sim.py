"""
usrp_sim.py — a USRP UDP packet generator / receiver for testing the audio
matrix's USRP leg WITHOUT a real bridge program (Phase 155, GH#151/GH#129).

THIS IS A SIMULATOR. It is not DVMProject, not DVSwitch and not AllStar, and
nothing it proves says anything about those programs working. It reproduces
the USRP wire format (a 32-byte header plus 320 bytes of 8 kHz s16le PCM) and
the header quirks recorded in the spec after reading dvmbridge's source
(garbage in the unused header bytes, an end-of-transmission that is just a
bare header) so the leg's handling of them is exercised on every CI run.
Whether a real dvmbridge behaves this way has NOT been verified against a
running instance.

Use as a library (tests/test_usrp_leg.py) or as a lab tool:

    # play 3 s of 1 kHz tone at a leg listening on 127.0.0.1:34001
    python services/audio-matrix/tests/usrp_sim.py send --port 34001 --seconds 3

    # act as the bridge's receive side and report what arrives
    python services/audio-matrix/tests/usrp_sim.py listen --port 32001 --seconds 10

Header styles for send():
  clean        every unused header field zero (what chan_usrp-style senders do)
  dvmbridge    magic + sequence + byte 15 set, every other byte random
               (how dvmbridge's uninitialised header buffer may look)
"""

from __future__ import annotations

import argparse
import math
import random
import socket
import struct
import sys
import time
from array import array

USRP_MAGIC = b"USRP"
HEADER_LEN = 32
FRAME_BYTES = 320
FRAME_SAMPLES = 160
SAMPLE_RATE = 8000


def tone_frames(freq_hz: float = 1000.0, seconds: float = 1.0, amplitude: int = 8000):
    """Yield 320-byte s16le frames of a sine tone (phase continuous)."""
    total = int(seconds * 50)  # 50 frames per second
    n = 0
    for _ in range(total):
        buf = array("h")
        for _i in range(FRAME_SAMPLES):
            buf.append(int(amplitude * math.sin(2 * math.pi * freq_hz * n / SAMPLE_RATE)))
            n += 1
        yield buf.tobytes()


def make_header(seq: int, style: str = "clean", keyup: int = 1,
                frame_type: int = 0, rng: random.Random | None = None) -> bytes:
    if style == "clean":
        return struct.pack(">4sIIIIIII", USRP_MAGIC, seq & 0xFFFFFFFF, 0,
                           keyup, 0, frame_type, 0, 0)
    if style == "dvmbridge":
        rng = rng or random.Random(1)
        raw = bytearray(rng.getrandbits(8) for _ in range(HEADER_LEN))
        raw[0:4] = USRP_MAGIC
        raw[4:8] = struct.pack(">I", seq & 0xFFFFFFFF)
        raw[15] = keyup & 0xFF
        return bytes(raw)
    raise ValueError("unknown header style %r" % style)


class UsrpSimulator:
    """A UDP socket that sends USRP datagrams to `(target_host, target_port)`
    and can also receive them (to capture what a transmitting leg sends)."""

    def __init__(self, target_host: str = "127.0.0.1", target_port: int = 34001,
                 bind_host: str = "127.0.0.1", bind_port: int = 0):
        self.target = (target_host, target_port)
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.bind((bind_host, bind_port))
        self.sock.settimeout(0.2)
        self.seq = 0
        self.rng = random.Random(1)
        self.sent = 0

    @property
    def local_port(self) -> int:
        return self.sock.getsockname()[1]

    def close(self) -> None:
        try:
            self.sock.close()
        except OSError:
            pass

    # ── sending ──────────────────────────────────────────────────────
    def send_raw(self, data: bytes) -> None:
        self.sock.sendto(data, self.target)
        self.sent += 1

    def send_voice(self, frames, style: str = "clean", frames_per_datagram: int = 1,
                   pace: bool = False) -> int:
        """Send PCM frames as voice datagrams. `frames_per_datagram` > 1
        packs several 20 ms frames into one datagram (a P25 burst). Returns
        the number of datagrams sent."""
        frames = list(frames)
        sent = 0
        for i in range(0, len(frames), frames_per_datagram):
            chunk = frames[i:i + frames_per_datagram]
            hdr = make_header(self.seq, style, rng=self.rng)
            self.seq += 1
            self.send_raw(hdr + b"".join(chunk))
            sent += 1
            if pace:
                time.sleep(0.02 * len(chunk))
        return sent

    def send_eot(self, style: str = "clean") -> None:
        """End of transmission: a bare 32-byte header (keyup 0 for 'clean';
        random for 'dvmbridge', where byte 15 is not reliable)."""
        hdr = make_header(self.seq, style, keyup=0, rng=self.rng)
        if style == "dvmbridge":
            # A type word of 3 would read as a ping; the simulator's job is
            # to produce a plain EOT, so steer away from that one value.
            if hdr[20:24] == b"\x00\x00\x00\x03":
                hdr = hdr[:23] + b"\x00" + hdr[24:]
        self.seq += 1
        self.send_raw(hdr)

    def send_ping(self) -> None:
        self.send_raw(make_header(self.seq, "clean", keyup=0, frame_type=3))
        self.seq += 1

    # ── receiving ────────────────────────────────────────────────────
    def receive(self, timeout: float = 1.0):
        """Return (datagram, (host, port)) or None on timeout."""
        deadline = time.monotonic() + timeout
        while time.monotonic() < deadline:
            try:
                return self.sock.recvfrom(4096)
            except socket.timeout:
                continue
            except OSError:
                return None
        return None


def _main(argv=None) -> int:
    p = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = p.add_subparsers(dest="cmd", required=True)
    s = sub.add_parser("send", help="send a test tone to a USRP leg")
    s.add_argument("--host", default="127.0.0.1")
    s.add_argument("--port", type=int, default=34001)
    s.add_argument("--bind", default="127.0.0.1",
                   help="local source address (must equal the channel's bridge_host)")
    s.add_argument("--seconds", type=float, default=2.0)
    s.add_argument("--freq", type=float, default=1000.0)
    s.add_argument("--style", choices=["clean", "dvmbridge"], default="clean")
    s.add_argument("--burst", type=int, default=1, help="20 ms frames per datagram")
    l = sub.add_parser("listen", help="receive USRP datagrams and report them")
    l.add_argument("--bind", default="127.0.0.1")
    l.add_argument("--port", type=int, default=32001)
    l.add_argument("--seconds", type=float, default=10.0)
    args = p.parse_args(argv)

    if args.cmd == "send":
        sim = UsrpSimulator(args.host, args.port, bind_host=args.bind)
        n = sim.send_voice(tone_frames(args.freq, args.seconds), style=args.style,
                           frames_per_datagram=args.burst, pace=True)
        sim.send_eot(args.style)
        print("sent %d voice datagrams + EOT from %s:%d to %s:%d"
              % (n, args.bind, sim.local_port, args.host, args.port))
        sim.close()
        return 0

    sim = UsrpSimulator("127.0.0.1", 0, bind_host=args.bind, bind_port=args.port)
    deadline = time.monotonic() + args.seconds
    voice = eot = other = 0
    while time.monotonic() < deadline:
        got = sim.receive(0.2)
        if not got:
            continue
        data, addr = got
        if len(data) == HEADER_LEN:
            eot += 1
        elif len(data) >= HEADER_LEN + FRAME_BYTES:
            voice += 1
        else:
            other += 1
    print("listened %.1fs on %s:%d: %d voice, %d header-only, %d other"
          % (args.seconds, args.bind, args.port, voice, eot, other))
    sim.close()
    return 0


if __name__ == "__main__":
    sys.exit(_main())
