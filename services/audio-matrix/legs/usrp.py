"""
UsrpLeg — a generic UDP "USRP" audio leg for the audio matrix (Phase 155,
GH#151 + GH#129, Release A: LISTEN-ONLY).

USRP is the small UDP framing that AllStar's chan_usrp, DVSwitch's
Analog_Bridge and DVMProject's `dvmbridge` (with `udpUsrp: true`) all use to
exchange plain 8 kHz signed-16-bit mono PCM with another program. It is
already the matrix's native frame shape (frame.py: 160 samples / 320 bytes /
20 ms), so this leg needs NO codec and NO knowledge of any radio network: a
separate, operator-installed bridge program does the vocoding and talks to
the radio network; this leg only moves PCM over loopback/LAN UDP.

    <radio network> <-> bridge program (vocoder lives here, NOT in TicketsCAD)
                              |  UDP, USRP framing, 8 kHz s16le
                          UsrpLeg  <->  MatrixCore

Wire format (32-byte header, big-endian, then PCM):

    offset  0  "USRP" magic
    offset  4  sequence number (uint32)
    offset  8  memory  (ignored)
    offset 12  keyup   (4 bytes; the low byte is byte 15)
    offset 16  talkgroup (ignored)
    offset 20  type    (0 voice, 1 DTMF, 2 text, 3 ping, 4 TLV)
    offset 24  mpx     (ignored)
    offset 28  reserved(ignored)
    offset 32  payload: 320 bytes of PCM for a voice frame

WHAT THIS RELEASE DOES NOT DO — read before extending
-----------------------------------------------------
This leg RECEIVES ONLY. `outbound()` never sends a datagram: a route that
delivers audio INTO this channel is counted (`tx_frames_blocked`) and
discarded. There is deliberately no transmit code in this file. Transmit
needs, first, the station-ID / unattended-keying compliance machinery to be
attached to this channel (fcc_gate.FccRelayGate is defined and hooked in
MatrixCore.tick() but is not yet attached to any channel by service.py) —
see specs/phase-155-community-backlog/151-dvm-host-and-129-p25-bridge.md
(Release B). Do not "just add a sendto" here without that.

Robustness rules (each has a test in tests/test_usrp_leg.py)
-----------------------------------------------------------
* ONLY datagrams whose source address equals the configured bridge host are
  accepted. The bridge's UDP port has no authentication, so on a flat LAN
  anything could otherwise inject audio into a radio channel's strip.
* A voice datagram is recognised by LENGTH (a positive multiple of 320 bytes
  after the 32-byte header), never by the header's type/keyup/talkgroup
  bytes. DVMProject's `dvmbridge` builds its outgoing USRP header from an
  uninitialised buffer and only sets the magic, the sequence number and one
  keyup byte (read from its source, 2026-10-02, NOT verified against a
  running instance), so those bytes can be arbitrary. Only the magic and the
  sequence number are interpreted here, and the sequence number only feeds
  diagnostic counters.
* A bare 32-byte header ends a receive (that is what the protocol's
  end-of-transmission looks like), EXCEPT a header whose type word is 3
  (ping), which is ignored. Because a given bridge may never send a clean
  end-of-transmission, a receive also ends after `rx_inactivity_ms` of
  silence on the socket. The leg never relies on byte 15 to detect the end.
* Anything else (DTMF, text, TLV metadata, compressed voice, wrong magic,
  runt datagrams) is dropped and counted, never fed to the matrix as audio.
* Binding is exclusive: a second leg on the same listen address and port
  fails to start, loudly, instead of silently splitting the stream between
  two channels.
* State-change notifications to PHP (`rx_state` started/ended) ride a
  bounded queue drained by a worker thread, so a slow or unreachable
  TicketsCAD web server can never stall the UDP receive loop.

The socket is injectable (`sock_factory`) and the clock is injectable
(`clock`) so tests can drive the leg without sleeping; the real-socket
loopback tests in tests/test_usrp_leg.py use usrp_sim.py, a USRP packet
generator/receiver that stands in for a bridge program.
"""

from __future__ import annotations

import ipaddress
import logging
import queue
import socket
import struct
import threading
import time
from typing import Callable, Optional

try:
    from frame import BYTES_PER_FRAME
except ImportError:  # pragma: no cover - direct-module fallback
    from ..frame import BYTES_PER_FRAME  # type: ignore

LOG = logging.getLogger("audio-matrix.usrp")

USRP_MAGIC = b"USRP"
USRP_HEADER_LEN = 32
USRP_TYPE_VOICE = 0
USRP_TYPE_DTMF = 1
USRP_TYPE_TEXT = 2
USRP_TYPE_PING = 3
USRP_TYPE_TLV = 4

# A UDP datagram larger than this is not a USRP voice frame (a real one is
# 352 bytes); reading a little more than a few frames keeps a burst-packed
# sender working without letting one datagram carry unbounded audio.
RECV_BUFSIZE = 4096
MAX_FRAMES_PER_DATAGRAM = 12

DEFAULT_LISTEN_HOST = "127.0.0.1"
DEFAULT_LISTEN_PORT = 34001
DEFAULT_BRIDGE_HOST = "127.0.0.1"
DEFAULT_BRIDGE_TX_PORT = 32001
DEFAULT_RX_INACTIVITY_MS = 400

SUPPORTED_FRAMING = ("usrp",)

# How long the receive loop waits on the socket before it checks the stop
# flag and the inactivity timer. Short, so stop() and the end-of-RX timer
# are prompt.
_POLL_S = 0.05


class UsrpConfigError(ValueError):
    """The leg was given configuration it must not run with."""


def pack_header(seq: int, keyup: int = 0, talkgroup: int = 0,
                frame_type: int = USRP_TYPE_VOICE) -> bytes:
    """Build a 32-byte USRP header (used by the simulator and tests)."""
    return struct.pack(">4sIIIIIII", USRP_MAGIC, seq & 0xFFFFFFFF, 0,
                       keyup & 0xFFFFFFFF, talkgroup & 0xFFFFFFFF,
                       frame_type & 0xFFFFFFFF, 0, 0)


def parse_ipv4(value: str, what: str) -> str:
    """Normalise an IPv4 literal; refuse anything else. A hostname is not
    accepted on purpose: resolving one at boot would make the source filter
    depend on DNS, and a filter that follows DNS is not a filter."""
    try:
        addr = ipaddress.ip_address(str(value).strip())
    except ValueError:
        raise UsrpConfigError("%s must be an IPv4 address, got %r" % (what, value))
    if addr.version != 4:
        raise UsrpConfigError("%s must be an IPv4 address, got %r" % (what, value))
    if addr.is_unspecified or addr.is_multicast:
        raise UsrpConfigError("%s may not be %s" % (what, addr))
    return str(addr)


def parse_port(value, what: str) -> int:
    try:
        port = int(value)
    except (TypeError, ValueError):
        raise UsrpConfigError("%s must be a port number, got %r" % (what, value))
    if port < 1024 or port > 65535:
        raise UsrpConfigError("%s must be between 1024 and 65535, got %d" % (what, port))
    return port


class UsrpLeg:
    def __init__(
        self,
        core,
        channel_id: str,
        bridge_host: str = DEFAULT_BRIDGE_HOST,
        tx_port: int = DEFAULT_BRIDGE_TX_PORT,
        listen_host: str = DEFAULT_LISTEN_HOST,
        listen_port: int = DEFAULT_LISTEN_PORT,
        framing: str = "usrp",
        rx_inactivity_ms: int = DEFAULT_RX_INACTIVITY_MS,
        notify_fn: Optional[Callable[[str, str, str, str], None]] = None,
        label: Optional[str] = None,
        sock_factory: Optional[Callable[[], socket.socket]] = None,
        clock: Optional[Callable[[], float]] = None,
    ):
        if framing not in SUPPORTED_FRAMING:
            raise UsrpConfigError("unsupported framing %r (supported: %s)"
                                  % (framing, ", ".join(SUPPORTED_FRAMING)))
        self.core = core
        self.channel_id = channel_id
        self.label = label or channel_id
        self.bridge_host = parse_ipv4(bridge_host, "bridge_host")
        # tx_port is the bridge's own UDP receive port. This release never
        # sends, so it is carried only so health() and the bridge-config
        # snippet agree with what a later transmit release will use.
        self.tx_port = parse_port(tx_port, "bridge_tx_port")
        self.listen_host = parse_ipv4(listen_host, "listen_host")
        self.listen_port = parse_port(listen_port, "listen_port")
        self.framing = framing
        self.rx_inactivity_s = max(0.05, float(rx_inactivity_ms) / 1000.0)
        self._notify_fn = notify_fn
        self._sock_factory = sock_factory or self._default_sock
        self._clock = clock or time.monotonic

        self._sock: Optional[socket.socket] = None
        self._rx_thread: Optional[threading.Thread] = None
        self._running = False

        # Receive state (touched only from the RX thread).
        self._receiving = False
        self._last_rx_clock = 0.0
        self._last_seq: Optional[int] = None

        # Notification worker.
        self._notify_q: "queue.Queue" = queue.Queue(maxsize=64)
        self._notify_thread: Optional[threading.Thread] = None

        # Counters for /legs + tests. Plain ints: written by one thread,
        # read for display by another; a torn read of a counter is harmless.
        self.rx_frames = 0
        self.rx_calls = 0
        self.rx_dropped_source = 0
        self.rx_dropped_malformed = 0
        self.rx_dropped_nonvoice = 0
        self.rx_seq_gaps = 0
        self.rx_eot_by_length = 0
        self.rx_eot_by_timeout = 0
        self.tx_frames_blocked = 0
        self.notify_dropped = 0
        self.last_rx_epoch: Optional[float] = None

    # ── lifecycle ────────────────────────────────────────────────────
    @staticmethod
    def _default_sock() -> socket.socket:
        # NO SO_REUSEADDR: on Windows that option lets two sockets bind the
        # same UDP port, which would silently split one bridge's stream
        # across two channels. Exclusive bind fails loudly instead.
        return socket.socket(socket.AF_INET, socket.SOCK_DGRAM)

    def start_rx(self) -> None:
        """Bind the listen socket and start receiving. Raises OSError if the
        address is in use (a second leg on the same port) — callers treat
        that as 'leg not attached' and say so."""
        if self._running:
            return
        sock = self._sock_factory()
        try:
            sock.bind((self.listen_host, self.listen_port))
            sock.settimeout(_POLL_S)
        except OSError:
            try:
                sock.close()
            except OSError:
                pass
            raise
        self._sock = sock
        self._running = True
        self._notify_thread = threading.Thread(
            target=self._notify_loop, name="usrp-leg-notify-%s" % self.channel_id, daemon=True)
        self._notify_thread.start()
        self._rx_thread = threading.Thread(
            target=self._rx_loop, name="usrp-leg-rx-%s" % self.channel_id, daemon=True)
        self._rx_thread.start()
        LOG.info("usrp leg %s listening on %s:%d (bridge %s, LISTEN-ONLY)",
                 self.channel_id, self.listen_host, self.listen_port, self.bridge_host)

    def stop(self) -> None:
        """Stop receiving and release the UDP port."""
        if not self._running and self._sock is None:
            return
        self._running = False
        t = self._rx_thread
        if t is not None and t is not threading.current_thread():
            t.join(timeout=1.0)
        self._rx_thread = None
        sock = self._sock
        self._sock = None
        if sock is not None:
            try:
                sock.close()
            except OSError:
                pass
        if self._receiving:
            self._end_rx("stopped")
        # Retire the notifier WITHOUT waiting for it: it is a daemon thread
        # that finishes what is already queued and exits on the sentinel. A
        # detach is a request/response on the control plane (the PHP caller
        # gives up after 5 s), and the web server the notifier POSTs to may
        # be slow or down — waiting here would make "remove this channel"
        # take as long as that server's timeout.
        try:
            self._notify_q.put_nowait(None)
        except queue.Full:
            # A full queue means the notifier is behind; drop one stale
            # notification so the sentinel fits and the thread can retire.
            try:
                self._notify_q.get_nowait()
                self._notify_q.put_nowait(None)
            except (queue.Empty, queue.Full):
                pass
        self._notify_thread = None

    # ── matrix -> leg: LISTEN-ONLY, nothing is ever sent ─────────────
    def outbound(self, frame: bytes) -> None:
        """The matrix delivers the mix routed INTO this channel every tick
        (silence when nothing is patched in). This release never transmits:
        non-silent audio is counted and discarded. See the module docblock
        before changing this."""
        if frame and any(frame):
            self.tx_frames_blocked += 1

    # ── RX ───────────────────────────────────────────────────────────
    def _rx_loop(self) -> None:
        while self._running:
            sock = self._sock
            if sock is None:
                break
            try:
                data, addr = sock.recvfrom(RECV_BUFSIZE)
            except socket.timeout:
                self._check_inactivity()
                continue
            except OSError as e:
                if not self._running:
                    break
                # e.g. Windows WSAECONNRESET after an ICMP unreachable; the
                # socket is still usable. Do not spin.
                LOG.debug("usrp %s: recv error %s", self.channel_id, e)
                time.sleep(_POLL_S)
                continue
            try:
                self.handle_datagram(data, addr[0])
            except Exception as e:  # noqa: BLE001 - a bad packet must not kill RX
                LOG.warning("usrp %s: error handling datagram: %s", self.channel_id, e)
            self._check_inactivity()

    def handle_datagram(self, data: bytes, src_host: str) -> None:
        """Process ONE received datagram. Public so tests can drive the leg
        deterministically (no socket, no sleeping)."""
        if src_host != self.bridge_host:
            self.rx_dropped_source += 1
            return
        if len(data) < USRP_HEADER_LEN or data[:4] != USRP_MAGIC:
            self.rx_dropped_malformed += 1
            return

        (seq,) = struct.unpack(">I", data[4:8])
        (ftype,) = struct.unpack(">I", data[20:24])
        payload = data[USRP_HEADER_LEN:]

        if len(payload) == 0:
            # A bare header. A ping is a keepalive, not an end of call.
            if ftype == USRP_TYPE_PING:
                return
            if self._receiving:
                self.rx_eot_by_length += 1
                self._end_rx("eot")
            return

        n_full, rem = divmod(len(payload), BYTES_PER_FRAME)
        if rem != 0 or n_full > MAX_FRAMES_PER_DATAGRAM:
            # DTMF / text / TLV / compressed-voice / anything that is not a
            # whole number of 20 ms PCM frames. Never audio.
            self.rx_dropped_nonvoice += 1
            return

        # Sequence bookkeeping — diagnostics only.
        if self._last_seq is not None and self._receiving:
            if seq != ((self._last_seq + 1) & 0xFFFFFFFF):
                self.rx_seq_gaps += 1
        self._last_seq = seq

        if not self._receiving:
            self._receiving = True
            self.rx_calls += 1
            self._notify("rx_state", "started")
        for i in range(n_full):
            frame = payload[i * BYTES_PER_FRAME:(i + 1) * BYTES_PER_FRAME]
            self.core.inbound(self.channel_id, frame, keyed=True)
            self.rx_frames += 1
        self._last_rx_clock = self._clock()
        self.last_rx_epoch = time.time()

    def _check_inactivity(self) -> None:
        if self._receiving and (self._clock() - self._last_rx_clock) >= self.rx_inactivity_s:
            self.rx_eot_by_timeout += 1
            self._end_rx("timeout")

    def _end_rx(self, reason: str) -> None:
        self._receiving = False
        self._last_seq = None
        self._notify("rx_state", "ended")

    # ── notifications (bounded queue + worker) ───────────────────────
    def _notify(self, event: str, state: str) -> None:
        if self._notify_fn is None:
            return
        try:
            self._notify_q.put_nowait((event, state))
        except queue.Full:
            self.notify_dropped += 1

    def _notify_loop(self) -> None:
        while True:
            item = self._notify_q.get()
            if item is None:
                return
            event, state = item
            fn = self._notify_fn
            if fn is None:
                continue
            try:
                fn(self.channel_id, self.label, event, state)
            except Exception as e:  # noqa: BLE001 - informational only
                LOG.debug("usrp %s: notify %s=%s failed: %s", self.channel_id, event, state, e)

    # ── health ───────────────────────────────────────────────────────
    def health(self) -> dict:
        return {
            "channel_id": self.channel_id,
            "adapter_family": "usrp",
            "running": bool(self._running),
            "listen_only": True,
            "bridge_host": self.bridge_host,
            "bridge_tx_port": self.tx_port,
            "listen_host": self.listen_host,
            "listen_port": self.listen_port,
            "framing": self.framing,
            "rx_hang_ms": int(round(self.rx_inactivity_s * 1000)),
            "receiving": bool(self._receiving),
            "rx_frames": self.rx_frames,
            "rx_calls": self.rx_calls,
            "rx_dropped_source": self.rx_dropped_source,
            "rx_dropped_malformed": self.rx_dropped_malformed,
            "rx_dropped_nonvoice": self.rx_dropped_nonvoice,
            "rx_seq_gaps": self.rx_seq_gaps,
            "rx_eot_by_length": self.rx_eot_by_length,
            "rx_eot_by_timeout": self.rx_eot_by_timeout,
            "tx_frames_blocked": self.tx_frames_blocked,
            "notify_dropped": self.notify_dropped,
            "last_rx_epoch": self.last_rx_epoch,
        }
