#!/usr/bin/env python3
"""
HomeBrew Protocol (HBP) client — speaks directly to BrandMeister.

Phase 84 — Tickets CAD NewUI. Replaces the DVSwitch chain
(MMDVM_Bridge + Analog_Bridge) with a Python HBP client that
authenticates to BrandMeister, exchanges keepalives, and handles
DMRD voice frames in both directions.

Protocol references:
  * MMDVMHost source — DMRNetwork.cpp (canonical HBP login + RPTC
    config + DMRD framing). See specs/dvswitch-proxy-2026-06/
    setup-log.md for file:line cites.
  * BrandMeister wiki — Homebrew/example/php2 (byte-15 status
    decoding).
  * ETSI TS 102 361-1 v1.4.5 — DMR over-the-air burst structure,
    cited throughout docs/DMR-PROTOCOL-DEEP-DIVE.md.

Phase 84a (this slice): auth + keepalive + RX packet logging only.
Decoder + TX encoder land in 84b / 84c.

Usage:
  python3 hbp_client.py --config /etc/ticketscad/dvswitch-<instance>.env

The env file reuses the same DMR_* variables as bridge.py and reads
the BrandMeister password from MMDVM_Bridge.ini so credentials
stay on disk under chmod 0600 and never enter code review.
"""

from __future__ import annotations

import argparse
import configparser
import hashlib
import json
import logging
import os
import secrets
import select
import signal
import socket
import struct
import subprocess
import sys
import threading
import time
from dataclasses import dataclass, field
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Optional

LOG = logging.getLogger("hbp")

# ── HBP packet signatures ─────────────────────────────────────────
SIG_RPTL    = b"RPTL"     # login request (client → master)
SIG_RPTACK  = b"RPTACK"   # generic ack (master → client)
SIG_MSTNAK  = b"MSTNAK"   # generic nak
SIG_RPTK    = b"RPTK"     # hash response
SIG_RPTC    = b"RPTC"     # config payload (BrandMeister-extended 302-byte format)
SIG_RPTPING = b"RPTPING"  # keepalive (client → master)
SIG_MSTPONG = b"MSTPONG"  # keepalive ack (master → client)
SIG_RPTCL   = b"RPTCL"    # close request
SIG_DMRD    = b"DMRD"     # voice/data frame

# ── State machine ─────────────────────────────────────────────────
STATE_DOWN          = 0
STATE_LOGIN_SENT    = 1
STATE_AUTH_SENT     = 2
STATE_CONFIG_SENT   = 3
STATE_RUNNING       = 4


@dataclass
class HBPConfig:
    """Operator + radio + BrandMeister credentials for HBP."""
    dmr_id: int                  # 4-byte unsigned repeater ID (e.g. 310441017)
    callsign: str                # exactly 8 chars space-padded
    rx_freq_hz: int              # 9 chars decimal
    tx_freq_hz: int              # 9 chars decimal
    tx_power_w: int              # 2 chars (00-99)
    color_code: int              # 2 chars (00-15)
    latitude: float              # 8 chars (signed decimal)
    longitude: float             # 9 chars (signed decimal)
    height_m: int                # 3 chars (000-999)
    location: str                # 20 chars
    description: str             # 19 chars
    url: str                     # 124 chars
    software_id: str             # 40 chars
    package_id: str              # 40 chars
    password: str                # BrandMeister hotspot password
    master_host: str
    master_port: int
    local_port: int = 62032

    @classmethod
    def from_mmdvm_ini(cls, ini_path: str, password: Optional[str] = None) -> "HBPConfig":
        """Read an existing MMDVM_Bridge.ini and turn it into HBPConfig.

        The DVSwitch install we're replacing already has every field
        we need on disk under chmod 0640. Re-parsing it means we don't
        duplicate credentials and we exactly match what BrandMeister
        accepted before.
        """
        cp = configparser.RawConfigParser(strict=False, allow_no_value=True)
        # MMDVM_Bridge.ini uses INI sections; comments after values
        # are NOT standard ConfigParser. Strip them.
        with open(ini_path, encoding="utf-8") as fh:
            lines = []
            for raw in fh:
                stripped = raw.split(";", 1)[0].rstrip()
                if stripped:
                    lines.append(stripped + "\n")
        from io import StringIO
        cp.read_file(StringIO("".join(lines)))

        gen   = cp["General"]
        info  = cp["Info"]
        dmrnw = cp["DMR Network"]

        return cls(
            dmr_id=int(gen["Id"]),
            callsign=gen["Callsign"].strip(),
            rx_freq_hz=int(info["RXFrequency"]),
            tx_freq_hz=int(info["TXFrequency"]),
            tx_power_w=int(info["Power"]),
            color_code=int(cp["DMR"]["ColorCode"]),
            latitude=float(info["Latitude"]),
            longitude=float(info["Longitude"]),
            height_m=int(info["Height"]),
            location=info["Location"].strip(),
            description=info["Description"].strip(),
            url=info["URL"].strip(),
            software_id="TicketsCAD-HBP-2026-06",
            package_id="20260615",
            password=password if password is not None else dmrnw["Password"].strip(),
            master_host=dmrnw["Address"].strip(),
            master_port=int(dmrnw["Port"]),
            local_port=int(dmrnw.get("Local", "62032")),
        )


# ── Phase 84s — Live audio pump ──────────────────────────────────
# When a DMRD voice burst arrives, decode AMBE → PCM in-process and
# fan it out to any subscribed listeners (the radio-widget SSE in
# particular). Each subscriber gets its own bounded queue so a slow
# listener cannot back-pressure the receive loop.
import base64
import queue
import uuid
import wave
from datetime import datetime, timezone


class AudioPump:
    """Decodes inbound DMR voice bursts and distributes live PCM to
    subscriber queues. Threadsafe.

    Each subscriber registered via subscribe() gets a bounded queue.
    When a voice burst arrives, the AMBE frames are decoded
    synchronously (md380-emu is ~2 ms per frame on dvswitch-01) and
    the resulting PCM + metadata are pushed as a JSON-able dict on
    every subscriber's queue. Slow listeners drop oldest frames.
    """

    QUEUE_MAX = 200   # ~12 s of frames per subscriber (60 ms each)

    def __init__(self) -> None:
        self._subs: dict[str, queue.Queue] = {}
        self._lock = threading.Lock()
        self._codec = None     # lazily-initialised md380-emu wrapper
        self._call_meta: dict[bytes, dict] = {}  # stream_id -> {started_at, src_id, dst_tg}
        # Lazy-import decode helpers to avoid import cycles at module load.
        self._helpers = None   # populated on first decode

    def subscribe(self) -> tuple[str, queue.Queue]:
        sub_id = uuid.uuid4().hex
        q: queue.Queue = queue.Queue(maxsize=self.QUEUE_MAX)
        with self._lock:
            self._subs[sub_id] = q
        return sub_id, q

    def unsubscribe(self, sub_id: str) -> None:
        with self._lock:
            self._subs.pop(sub_id, None)

    def _broadcast(self, event: dict) -> None:
        with self._lock:
            for sub_id, q in list(self._subs.items()):
                try:
                    q.put_nowait(event)
                except queue.Full:
                    # Drop oldest to make room — a stalled subscriber
                    # never holds up the receive loop.
                    try:
                        q.get_nowait()
                        q.put_nowait(event)
                    except Exception:
                        pass

    def _ensure_helpers(self) -> bool:
        if self._helpers is not None:
            return True
        try:
            from services.dvswitch.ambe_codec import AmbeCodec
            from services.dvswitch.ambe_fec import (
                DMR_A_TABLE, DMR_B_TABLE, DMR_C_TABLE,
            )
            from services.dvswitch._prng_data import PRNG_TABLE
            self._helpers = {
                "DMR_A_TABLE": DMR_A_TABLE,
                "DMR_B_TABLE": DMR_B_TABLE,
                "DMR_C_TABLE": DMR_C_TABLE,
                "PRNG_TABLE":  PRNG_TABLE,
            }
            self._codec = AmbeCodec()
            return True
        except Exception as e:
            LOG.exception("AudioPump failed to load decode helpers: %s", e)
            return False

    @staticmethod
    def _bits(burst: bytes) -> list[int]:
        return [(b >> (7 - j)) & 1 for b in burst for j in range(8)]

    @staticmethod
    def _extract_wire(burst_bits, frame_idx, A, B, C):
        out = []
        for tab in (A, B, C):
            for p in tab:
                if frame_idx == 0:
                    pos = p
                elif frame_idx == 2:
                    pos = p + 192
                else:
                    pos = p + 72 + (48 if p + 72 >= 108 else 0)
                out.append(burst_bits[pos])
        return out

    @staticmethod
    def _info_from_wire(wire_72, prng):
        a_data = sum(wire_72[i] << (11 - i) for i in range(12))
        p = prng[a_data] >> 1
        b_codeword = sum(wire_72[24 + i] << (22 - i) for i in range(23)) ^ p
        b_data = (b_codeword >> 11) & 0xFFF
        c_bits = wire_72[47:72]
        return a_data, b_data, c_bits

    @staticmethod
    def _pack_ambe(a, b, c_bits):
        info = (
            [(a >> (11 - i)) & 1 for i in range(12)] +
            [(b >> (11 - i)) & 1 for i in range(12)] +
            list(c_bits)
        )
        out = bytearray(7)
        for i in range(48):
            if info[i]:
                out[i >> 3] |= 1 << (7 - (i & 7))
        if info[48]:
            out[6] |= 0x80
        return bytes(out)

    def on_dmrd(self, payload: bytes) -> None:
        """Hook invoked by HBPClient for every inbound DMRD packet."""
        if len(payload) < 53 or not self._ensure_helpers():
            return
        seq = payload[4]
        status = payload[15]
        stream = payload[16:20]
        src_id = int.from_bytes(payload[5:8], "big")
        dst_tg = int.from_bytes(payload[8:11], "big")

        # LC header (0x21 / 0xa1) → emit call_start ONCE per stream.
        # Phase 84u: BM convention repeats the LC header 3x at the start
        # of each call; dedupe so the subscriber sees one call_start.
        if status in (0x21, 0xa1):
            if stream in self._call_meta:
                return   # already saw this stream's first LC header
            now = time.time()
            self._call_meta[stream] = {
                "started_at": now,
                "src_id": src_id,
                "dst_tg": dst_tg,
            }
            self._broadcast({
                "event": "call_start",
                "call_id": stream.hex(),
                "src_id": src_id,
                "talkgroup": dst_tg,
                "ts": now,
            })
            return

        # Terminator (0x22 / 0xa2) → emit call_end.
        if status in (0x22, 0xa2):
            meta = self._call_meta.pop(stream, None)
            self._broadcast({
                "event": "call_end",
                "call_id": stream.hex(),
                "ended_at": time.time(),
            })
            return

        # Voice bursts (0x10/0x90 = A; 0x01..0x05/0x81..0x85 = B-F).
        voice_statuses = {0x10, 0x90, 0x01, 0x02, 0x03, 0x04, 0x05,
                          0x81, 0x82, 0x83, 0x84, 0x85}
        if status not in voice_statuses:
            return
        if not self._subs:
            return  # no listeners — skip the (cheap-ish) AMBE decode

        burst = payload[20:53]
        bb = self._bits(burst)
        A = self._helpers["DMR_A_TABLE"]
        B = self._helpers["DMR_B_TABLE"]
        C = self._helpers["DMR_C_TABLE"]
        PRNG = self._helpers["PRNG_TABLE"]
        pcm_chunks = []
        for fi in range(3):
            wire = self._extract_wire(bb, fi, A, B, C)
            a, b, c = self._info_from_wire(wire, PRNG)
            ambe = self._pack_ambe(a, b, c)
            try:
                pcm = self._codec.decode(ambe)
            except Exception as e:
                LOG.warning("live AMBE decode failed: %s", e)
                pcm = b"\x00" * 320  # 20 ms silence on failure
            pcm_chunks.append(pcm)
        full_pcm = b"".join(pcm_chunks)
        self._broadcast({
            "event": "audio",
            "call_id": stream.hex(),
            "src_id": src_id,
            "talkgroup": dst_tg,
            "seq": seq,
            "pcm": base64.b64encode(full_pcm).decode("ascii"),
            "ts": time.time(),
        })

    def broadcast_tx_audio(self, stream_id: bytes, src_id: int, dst_tg: int,
                           pcm_60ms: bytes, seq: int, is_first: bool = False,
                           is_last: bool = False) -> None:
        """Phase 85c-fix-6 — loopback locally-originated TX audio into
        the SSE fanout so every other connected widget hears the
        dispatcher's transmissions.

        BrandMeister does NOT echo a peer's own TX back to that peer;
        without this loopback, other dispatchers using the same widget
        would never hear their colleague's outgoing audio. Critical
        because Eric runs a shared-radio pool — multiple responders
        could TX from any of 12 radios at any time, and the dispatcher
        console MUST capture every transmission for the operational
        record.

        Emits the same call_start / audio / call_end event shape as
        inbound on_dmrd, so widgets need no special handling. The
        stream_id space is shared with inbound calls — for our TX
        we generate a 4-byte stream_id in StreamingDMRTransmitter
        from os.urandom, which never collides with inbound.
        """
        if not self._subs:
            return  # nobody listening — skip the broadcast work
        now = time.time()
        if is_first:
            self._call_meta[stream_id] = {
                "started_at": now, "src_id": src_id, "dst_tg": dst_tg,
            }
            self._broadcast({
                "event": "call_start",
                "call_id": stream_id.hex(),
                "src_id": src_id,
                "talkgroup": dst_tg,
                "ts": now,
                "tx_origin": True,
            })
        if pcm_60ms:
            self._broadcast({
                "event": "audio",
                "call_id": stream_id.hex(),
                "src_id": src_id,
                "talkgroup": dst_tg,
                "seq": seq & 0xFF,
                "pcm": base64.b64encode(pcm_60ms).decode("ascii"),
                "ts": now,
                "tx_origin": True,
            })
        if is_last:
            self._call_meta.pop(stream_id, None)
            self._broadcast({
                "event": "call_end",
                "call_id": stream_id.hex(),
                "ended_at": now,
                "tx_origin": True,
            })

    def publish_transcript(self, call_id: str, text: str, engine: str = "whisper") -> None:
        """Called from echo_bot (or any STT) when a finished call has a
        transcript. Pushed to subscribers so the radio widget can fill
        in the pending transcript line for the matching call card."""
        self._broadcast({
            "event": "transcript",
            "call_id": call_id,
            "text": text,
            "engine": engine,
        })


AUDIO_PUMP = AudioPump()


class HBPClient:
    """Single-instance HBP client connecting to one BrandMeister master.

    Threading model: one socket; the main thread owns the receive loop
    and the keepalive scheduler. Both are non-blocking via select.
    """

    KEEPALIVE_INTERVAL_S = 5.0
    RX_TIMEOUT_S = 0.5
    LOGIN_TIMEOUT_S = 10.0
    LOGIN_RETRY_S = 2.0
    # If RUNNING but the master hasn't sent us ANYTHING (MSTPONG/DMRD) for
    # this long, the session is dead — re-login. 30s = 6 missed keepalives.
    MASTER_SILENCE_TIMEOUT_S = 30.0

    def __init__(self, config: HBPConfig) -> None:
        self.config = config
        self.state = STATE_DOWN
        self.sock: Optional[socket.socket] = None
        self.running = False
        self._last_keepalive_sent = 0.0
        self._last_master_seen = 0.0
        self._login_started_at = 0.0
        # Stats for end-of-session reporting + future debug panel
        self.rx_packet_count = 0
        self.rx_dmrd_count = 0
        self.rx_keepalive_count = 0
        self.rx_unknown_count = 0
        self.relogin_count = 0
        # Caller-supplied hook fired for every DMRD packet (Phase 84b)
        self.on_dmrd = None  # type: ignore[assignment]
        # Phase 85f-9: per-TG last-RX monotonic timestamp. _handle_dmrd
        # updates on every inbound packet; tx_text uses it for the
        # clear-channel wait. dict[talkgroup_int] -> monotonic seconds.
        self._last_rx_at = {}

    # ── socket lifecycle ────────────────────────────────────────
    def _bind(self) -> None:
        self.sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self.sock.bind(("0.0.0.0", self.config.local_port))
        self.sock.settimeout(self.RX_TIMEOUT_S)
        LOG.info("bound local UDP %d", self.config.local_port)

    def _send(self, payload: bytes) -> None:
        if not self.sock:
            raise RuntimeError("socket not bound")
        self.sock.sendto(payload, (self.config.master_host,
                                   self.config.master_port))

    def send_to_master(self, packet: bytes) -> None:
        """Public hook for Phase 84 TX path. Sends a raw HBP packet
        (typically a DMRD voice frame) to the master. Caller is
        responsible for packet structure + pacing.
        """
        self._send(packet)

    # Phase 84g — TTS TX entry point: synthesise text via Piper,
    # encode + frame as DMR, send to BM. Called from the HTTP handler.
    def tx_text(self, text: str, src_id: int, dst_id: int,
                piper_bin: str, piper_voice: str,
                ffmpeg_bin: str = "ffmpeg",
                dry_run: bool = False,
                clear_channel_seconds: float = 0.0) -> dict:
        from services.dvswitch.ambe_codec import AmbeCodec
        from services.dvswitch.dmr_tx import DMRCallTransmitter

        if self.state != STATE_RUNNING:
            return {"error": "not authenticated to master"}

        # Phase 85f-9: optional clear-channel wait — block until
        # the target TG has been idle for N seconds.
        waited_for_clear = 0.0
        if clear_channel_seconds > 0:
            waited_for_clear = self.wait_clear_channel(
                dst_id, clear_channel_seconds)
            LOG.info("waited %.2fs for clear channel on TG %d "
                     "(requested %.1fs idle)",
                     waited_for_clear, dst_id, clear_channel_seconds)

        # Piper TTS — synthesise to 22050 Hz mono WAV, then ffmpeg-resample
        # to 8 kHz s16le PCM.
        try:
            piper = subprocess.run(
                [piper_bin, "--model", piper_voice, "--output_raw"],
                input=text.encode("utf-8"),
                capture_output=True, check=True, timeout=20,
            )
            raw22050 = piper.stdout
            ff = subprocess.run(
                [ffmpeg_bin, "-loglevel", "error",
                 "-f", "s16le", "-ar", "22050", "-ac", "1",
                 "-i", "pipe:0",
                 "-f", "s16le", "-ar", "8000", "-ac", "1", "pipe:1"],
                input=raw22050, capture_output=True, check=True, timeout=20,
            )
            pcm = ff.stdout
            # Phase 85f-7: 0.75 s pre-roll silence so far-end radios,
            # hotspots, and the BrandMeister relay path have time to
            # come up before the first syllable of TTS audio.
            # 0.75 s @ 8 kHz mono s16le = 12000 samples = 24000 bytes.
            # NOT applied to /tx/audio (widget) -- humans provide their
            # own pre-roll by holding PTT before speaking.
            pcm = (b"\x00" * 24000) + pcm
        except subprocess.CalledProcessError as e:
            return {"error": "TTS pipeline failed",
                    "stderr": (e.stderr or b"").decode("utf-8", "replace")[:500]}
        except FileNotFoundError as e:
            return {"error": f"binary not found: {e.filename}"}

        # Phase 85f-11: dry-run short-circuit. We've already done the
        # expensive work (Piper synthesis + ffmpeg resample + pre-roll
        # injection) so the smoke test can verify pcm_bytes math and
        # confirm the TTS pipeline produced something playable, but we
        # skip AMBE encode + send_to_master so nothing goes on-air.
        if dry_run:
            return {"ok": True, "dry_run": True, "packets_sent": 0,
                    "pcm_bytes": len(pcm),
                    "duration_ms": int(len(pcm) / 2 / 8000 * 1000)}

        codec = AmbeCodec()
        tx = DMRCallTransmitter(
            src_id=src_id,
            dst_id=dst_id,
            repeater_id=self.config.dmr_id,
            send_fn=self.send_to_master,
            ambe_codec=codec,
        )
        try:
            sent = tx.transmit_pcm(pcm)
            return {"ok": True, "packets_sent": sent,
                    "pcm_bytes": len(pcm),
                    "duration_ms": int(len(pcm) / 2 / 8000 * 1000)}
        finally:
            codec.close()

    # ── HBP login flow ──────────────────────────────────────────
    def _dmr_id_bytes(self) -> bytes:
        return struct.pack(">I", self.config.dmr_id)

    def _send_login(self) -> None:
        # RPTL + 4-byte BE repeater ID
        self._send(SIG_RPTL + self._dmr_id_bytes())
        self.state = STATE_LOGIN_SENT
        self._login_started_at = time.monotonic()
        LOG.info("sent RPTL for DMR ID %d", self.config.dmr_id)

    def _handle_login_response(self, payload: bytes) -> None:
        """Master replies to RPTL with RPTACK + 4-byte challenge salt
        (when login is permitted) or MSTNAK (denied)."""
        if payload.startswith(SIG_MSTNAK):
            LOG.error("master refused login (MSTNAK). Wrong ID or banned.")
            self.state = STATE_DOWN
            return
        if not payload.startswith(SIG_RPTACK):
            LOG.warning("unexpected response to RPTL: %r", payload[:16])
            return
        # RPTACK + 4-byte salt
        salt = payload[len(SIG_RPTACK):len(SIG_RPTACK) + 4]
        if len(salt) != 4:
            LOG.error("RPTACK to RPTL is missing 4-byte salt (got %d bytes)",
                      len(salt))
            return
        # Hash = SHA256(salt || password). The password is utf-8
        # encoded; salt is the 4 raw bytes.
        digest = hashlib.sha256(salt + self.config.password.encode("utf-8")).digest()
        self._send(SIG_RPTK + self._dmr_id_bytes() + digest)
        self.state = STATE_AUTH_SENT
        LOG.info("sent RPTK (auth hash)")

    def _handle_auth_response(self, payload: bytes) -> None:
        if payload.startswith(SIG_MSTNAK):
            LOG.error("master rejected RPTK (wrong password?)")
            self.state = STATE_DOWN
            return
        if not payload.startswith(SIG_RPTACK):
            LOG.warning("unexpected response to RPTK: %r", payload[:16])
            return
        # Master is happy; send the RPTC config payload.
        config_packet = self._build_config_packet()
        self._send(config_packet)
        self.state = STATE_CONFIG_SENT
        LOG.info("sent RPTC config (%d bytes)", len(config_packet))

    def _handle_config_response(self, payload: bytes) -> None:
        if payload.startswith(SIG_MSTNAK):
            LOG.error("master rejected RPTC config")
            self.state = STATE_DOWN
            return
        if not payload.startswith(SIG_RPTACK):
            LOG.warning("unexpected response to RPTC: %r", payload[:16])
            return
        self.state = STATE_RUNNING
        self._last_master_seen = time.monotonic()
        LOG.info("RUNNING — authenticated to %s:%d as DMR ID %d",
                 self.config.master_host, self.config.master_port,
                 self.config.dmr_id)

    def _build_config_packet(self) -> bytes:
        """Build the RPTC config payload — exact BrandMeister-extended
        302-byte format. Captured byte-for-byte from a working
        MMDVM_Bridge → BrandMeister 3103 login on 2026-06-15. The
        slot character sits AT OFFSET 97, BETWEEN description and URL
        — that single byte's placement is what trips up most third-party
        attempts at this protocol.

        Layout (302 bytes total):

            offset   len  field          format / source
            ------   ---  -------------  -------------------------------
                 0    4   sig            "RPTC"
                 4    4   dmr_id         big-endian uint32
                 8    8   callsign       %-8.8s (space-padded)
                16    9   rx_freq_hz     %09u
                25    9   tx_freq_hz     %09u
                34    2   power          %02u (0..99)
                36    2   color_code     %02u (0..15)
                38    8   latitude       %.5f truncated to 8 chars
                46    9   longitude      %.5f truncated to 9 chars
                55    3   height_m       %03u
                58   20   location       %-20.20s
                78   19   description    %-19.19s
                97    1   slots          '1','2','3','4' (DMR slot mask)
                98  124   url            %-124.124s
               222   40   version        %-40.40s
               262   40   software       %-40.40s
        """
        c = self.config

        def pad(s: str, width: int) -> bytes:
            return s[:width].ljust(width).encode("ascii", errors="replace")

        # In MMDVMHost the simplex (DMO) branch hard-codes '4' for slots
        # regardless of which Slot1/Slot2 the .ini declared. Our setup
        # is simplex, so '4' it is.
        slots_char = b"3"

        body = (
            SIG_RPTC +
            self._dmr_id_bytes() +
            pad(c.callsign, 8) +
            f"{c.rx_freq_hz:09d}".encode("ascii") +
            f"{c.tx_freq_hz:09d}".encode("ascii") +
            f"{min(c.tx_power_w, 99):02d}".encode("ascii") +
            f"{c.color_code:02d}".encode("ascii") +
            f"{c.latitude:.5f}"[:8].ljust(8).encode("ascii") +
            f"{c.longitude:.5f}"[:9].ljust(9).encode("ascii") +
            f"{min(c.height_m, 999):03d}".encode("ascii") +
            pad(c.location, 20) +
            pad(c.description, 19) +
            slots_char +
            pad(c.url, 124) +
            pad("20251120_WPSD", 40) +
            pad("MMDVM_MMDVM_HS", 40)
        )
        assert len(body) == 302, f"RPTC must be 302 bytes, got {len(body)}"
        return body

    # ── keepalive ───────────────────────────────────────────────
    def _maybe_send_keepalive(self) -> None:
        if self.state != STATE_RUNNING:
            return
        now = time.monotonic()
        if now - self._last_keepalive_sent >= self.KEEPALIVE_INTERVAL_S:
            self._send(SIG_RPTPING + self._dmr_id_bytes())
            self._last_keepalive_sent = now

    # ── packet dispatch ─────────────────────────────────────────
    def _on_packet(self, payload: bytes) -> None:
        self.rx_packet_count += 1
        if not payload:
            return
        if payload.startswith(SIG_MSTPONG):
            self.rx_keepalive_count += 1
            self._last_master_seen = time.monotonic()
            return
        if payload.startswith(SIG_DMRD):
            self.rx_dmrd_count += 1
            self._last_master_seen = time.monotonic()
            self._handle_dmrd(payload)
            return

        # MSTNAK while RUNNING = the master dropped our session (restart,
        # failover, timeout on its side). It will NAK every keepalive forever;
        # the only way back is a fresh login. Before 2026-07-06 this fell into
        # the unexpected-packet logger and the bridge sat dead for DAYS while
        # believing it was connected — TXes went into the void, reported ok.
        if self.state == STATE_RUNNING and payload.startswith(SIG_MSTNAK):
            LOG.error("master NAKed our session while running — re-logging in")
            self._force_relogin()
            return

        # Login flow responses key on state
        if self.state == STATE_LOGIN_SENT:
            self._handle_login_response(payload)
        elif self.state == STATE_AUTH_SENT:
            self._handle_auth_response(payload)
        elif self.state == STATE_CONFIG_SENT:
            self._handle_config_response(payload)
        else:
            self.rx_unknown_count += 1
            LOG.warning("unexpected packet in state %d: %r",
                        self.state, payload[:16])

    def _force_relogin(self) -> None:
        """Drop to STATE_DOWN and immediately start a fresh login handshake.
        Used when the running session is dead (MSTNAK, master silence)."""
        self.relogin_count += 1
        self.state = STATE_DOWN
        self._send_login()

    def wait_clear_channel(self, tg: int, idle_seconds: float,
                           max_wait_seconds: float = 30.0) -> float:
        """Block until tg has been idle for idle_seconds, or until
        max_wait_seconds total has passed. Returns however long we
        actually waited (in seconds, monotonic). Polls every 100ms.

        Phase 85f-9 — gives /tx/text a real 'wait for idle moment'
        feature so the operator can ask 'wait for 2 seconds of silence'
        and have it actually happen instead of firing immediately.
        """
        start = time.monotonic()
        deadline = start + max_wait_seconds
        # Sonar python:S1244 (2026-07-03): the previous version used 0.0
        # as an in-band sentinel for "never stamped" and compared with
        # `last_rx == 0.0`. It happens to be safe here (0.0 is the exact
        # default, not the result of any arithmetic), but the pattern
        # keeps tripping the float-equality rule and obscures intent.
        # Use None as the sentinel — explicit, no float compare.
        while time.monotonic() < deadline:
            last_rx = self._last_rx_at.get(tg)
            if last_rx is None:
                return time.monotonic() - start
            idle_for = time.monotonic() - last_rx
            if idle_for >= idle_seconds:
                return time.monotonic() - start
            time.sleep(0.1)
        return time.monotonic() - start

    def _handle_dmrd(self, payload: bytes) -> None:
        if len(payload) < 53:
            LOG.warning("DMRD too short: %d bytes", len(payload))
            return
        # Phase 84a: just log; Phase 84b plugs in the voice decoder.
        seq = payload[4]
        src = int.from_bytes(payload[5:8], "big")
        dst = int.from_bytes(payload[8:11], "big")
        # Phase 85f-9: stamp clear-channel tracker before further parse.
        self._last_rx_at[dst] = time.monotonic()
        rpt = int.from_bytes(payload[11:15], "big")
        status = payload[15]
        stream = payload[16:20].hex()
        slot = "TS2" if status & 0x80 else "TS1"
        frame_type = (status >> 4) & 0x03
        dtype = status & 0x0F
        ft_name = {0: "voice", 1: "voice_sync", 2: "data_sync"}.get(frame_type, "?")
        LOG.info(
            "DMRD seq=%02x %s %s dtype=%x src=%d→tg=%d rpt=%d stream=%s",
            seq, slot, ft_name, dtype, src, dst, rpt, stream
        )
        if self.on_dmrd:
            try:
                self.on_dmrd(payload)
            except Exception as e:
                LOG.exception("on_dmrd hook crashed: %s", e)

    # ── main loop ───────────────────────────────────────────────
    def run(self) -> None:
        self._bind()
        self.running = True
        self._send_login()
        while self.running:
            # If login stalled mid-flow, retry
            if (self.state in (STATE_LOGIN_SENT, STATE_AUTH_SENT, STATE_CONFIG_SENT)
                and time.monotonic() - self._login_started_at > self.LOGIN_TIMEOUT_S):
                LOG.warning("login stalled in state %d, retrying", self.state)
                self.state = STATE_DOWN
                time.sleep(self.LOGIN_RETRY_S)
                self._send_login()
            if self.state == STATE_DOWN:
                time.sleep(self.LOGIN_RETRY_S)
                self._send_login()

            # Silence watchdog: RUNNING but nothing from the master (no
            # MSTPONG, no DMRD) for MASTER_SILENCE_TIMEOUT_S → the session is
            # dead even though nobody NAKed us. Re-login.
            if (self.state == STATE_RUNNING
                    and self._last_master_seen > 0.0
                    and time.monotonic() - self._last_master_seen
                        > self.MASTER_SILENCE_TIMEOUT_S):
                LOG.error("no traffic from master for %.0fs — re-logging in",
                          time.monotonic() - self._last_master_seen)
                self._force_relogin()

            self._maybe_send_keepalive()

            try:
                data, _addr = self.sock.recvfrom(8192)
                self._on_packet(data)
            except socket.timeout:
                continue
            except OSError as e:
                if not self.running:
                    break
                LOG.warning("socket error: %s", e)
                time.sleep(0.5)

        self.shutdown()

    def shutdown(self) -> None:
        self.running = False
        if self.sock and self.state == STATE_RUNNING:
            try:
                self._send(SIG_RPTCL + self._dmr_id_bytes())
                LOG.info("sent RPTCL")
            except OSError:
                pass
        if self.sock:
            try:
                self.sock.close()
            except OSError:
                pass
            self.sock = None
        LOG.info(
            "shutdown — packets=%d dmrd=%d keepalive=%d unknown=%d",
            self.rx_packet_count, self.rx_dmrd_count,
            self.rx_keepalive_count, self.rx_unknown_count
        )


# ── HTTP control server (Phase 84g) ───────────────────────────────
class ControlHandler(BaseHTTPRequestHandler):
    """Minimal HTTP control endpoint: GET /health, POST /tx/text."""

    # Phase 85c-fix-6: force HTTP/1.1 responses. Python's
    # BaseHTTPRequestHandler defaults to HTTP/1.0 which triggers
    # "Connection: close" semantics. With our React-based proxy on
    # the other end, the close-after-body race occasionally swallowed
    # the response body before on('data') fired. HTTP/1.1 + an
    # explicit Connection: close header (when warranted) is more
    # predictable; the bridge still closes the socket, but data is
    # delivered first.
    protocol_version = "HTTP/1.1"

    client: Optional[HBPClient] = None
    bearer: str = ""
    piper_bin: str = ""
    piper_voice: str = ""
    ffmpeg_bin: str = "ffmpeg"
    operator_id: int = 0
    default_tg: int = 0

    def _auth_ok(self) -> bool:
        auth = self.headers.get("Authorization", "")
        return auth.startswith("Bearer ") and auth[7:].strip() == self.bearer

    def _json(self, code: int, body: dict) -> None:
        payload = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        # Phase 85c-fix-6: explicitly mark connection close so the
        # response-then-FIN sequence is unambiguous to clients. The
        # React-based proxy was sometimes seeing 'close' before any
        # data, which we believe was a TCP buffering race with the
        # default HTTP/1.0 implicit-close behavior.
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(payload)
        try:
            self.wfile.flush()
        except Exception:
            pass

    def do_GET(self) -> None:
        if not self._auth_ok():
            return self._json(401, {"error": "unauthorized"})
        if self.path == "/health":
            c = self.client
            return self._json(200, {
                "ok": True,
                "state": c.state if c else -1,
                "running": bool(c and c.running),
                "rx_dmrd": c.rx_dmrd_count if c else 0,
                "rx_keepalive": c.rx_keepalive_count if c else 0,
            })
        if self.path == "/audio-stream":
            return self._serve_audio_stream()
        if self.path.startswith("/recording"):
            return self._serve_recording()
        return self._json(404, {"error": "not found"})

    def _serve_recording(self) -> None:
        """Phase 85c-fix-9 — Stream a saved WAV file back to the
        dispatcher widget via api/dmr-audio.php.

        Accepts:  GET /recording?path=<relative-or-absolute>
        Validates that the resolved path lives under the recordings
        directory tree (basic containment check), then streams the
        body with HTTP Range support so HTML5 audio scrubbing works.

        Both relative paths (echo_bot convention, e.g.
        `minnesota-statewide/2026/06/15/...wav`) and absolute paths
        (proxy convention since Phase 85c-fix-6, e.g.
        `/var/cache/ticketscad-dvswitch/recordings/tx-...wav`) are
        accepted as long as they resolve inside RECORDINGS_ROOT.
        """
        from urllib.parse import urlparse, parse_qs
        RECORDINGS_ROOT = "/var/cache/ticketscad-dvswitch"

        qs = parse_qs(urlparse(self.path).query)
        path_arg = (qs.get("path") or [""])[0]
        if not path_arg:
            return self._json(400, {"error": "path required"})

        if path_arg.startswith("/"):
            candidate = path_arg
        else:
            candidate = os.path.join(RECORDINGS_ROOT, path_arg)
        try:
            real = os.path.realpath(candidate)
            root = os.path.realpath(RECORDINGS_ROOT)
        except Exception:
            return self._json(400, {"error": "invalid path"})
        if not real.startswith(root + os.sep):
            LOG.warning("recording request outside root: %s", path_arg)
            return self._json(403, {"error": "path outside recordings root"})
        if not os.path.isfile(real):
            return self._json(404, {"error": "file not found"})

        try:
            size = os.path.getsize(real)
        except Exception:
            return self._json(404, {"error": "file unreadable"})

        # Range parsing: support a single byte range "bytes=N-M" only.
        range_hdr = self.headers.get("Range", "")
        start, end = 0, size - 1
        partial = False
        if range_hdr.startswith("bytes="):
            try:
                rng = range_hdr[6:].split(",")[0].strip()
                lo, _, hi = rng.partition("-")
                if lo: start = int(lo)
                if hi: end   = int(hi)
                if start < 0 or end >= size or start > end:
                    self.send_response(416)
                    self.send_header("Content-Range", f"bytes */{size}")
                    self.end_headers()
                    return
                partial = True
            except Exception:
                pass  # malformed Range — serve whole file

        length = end - start + 1
        ext = real.lower().rsplit(".", 1)[-1]
        ctype = "audio/wav" if ext == "wav" else "application/octet-stream"
        self.send_response(206 if partial else 200)
        self.send_header("Content-Type", ctype)
        self.send_header("Accept-Ranges", "bytes")
        self.send_header("Content-Length", str(length))
        if partial:
            self.send_header("Content-Range", f"bytes {start}-{end}/{size}")
        self.send_header("Cache-Control", "private, max-age=3600")
        self.end_headers()

        try:
            with open(real, "rb") as f:
                f.seek(start)
                remaining = length
                while remaining > 0:
                    chunk = f.read(min(remaining, 65536))
                    if not chunk: break
                    self.wfile.write(chunk)
                    remaining -= len(chunk)
        except (BrokenPipeError, ConnectionResetError):
            pass
        except Exception as e:
            LOG.warning("recording stream failed: %s", e)

    def _serve_audio_stream(self) -> None:
        """Long-lived NDJSON stream of live audio + call metadata events
        from the AudioPump. One event per line, application/x-ndjson.

        api/dmr-stream.php is the upstream consumer — it translates
        each NDJSON line into a named SSE event for the browser.
        """
        self.send_response(200)
        self.send_header("Content-Type", "application/x-ndjson")
        self.send_header("Cache-Control", "no-cache, no-store")
        self.send_header("X-Accel-Buffering", "no")
        self.end_headers()
        try:
            self.wfile.flush()
        except Exception:
            return
        sub_id, q = AUDIO_PUMP.subscribe()
        LOG.info("audio-stream subscriber %s connected (subs=%d)",
                 sub_id[:8], len(AUDIO_PUMP._subs))
        last_kp = time.monotonic()
        try:
            while True:
                try:
                    event = q.get(timeout=15.0)
                except queue.Empty:
                    # Keep-alive comment so clients don't time out.
                    try:
                        self.wfile.write(b'{"event":"keepalive"}\n')
                        self.wfile.flush()
                    except Exception:
                        break
                    continue
                try:
                    self.wfile.write((json.dumps(event) + "\n").encode())
                    self.wfile.flush()
                except Exception:
                    break
        finally:
            AUDIO_PUMP.unsubscribe(sub_id)
            LOG.info("audio-stream subscriber %s disconnected", sub_id[:8])

    def do_POST(self) -> None:
        if not self._auth_ok():
            return self._json(401, {"error": "unauthorized"})
        # /tx/audio handles a raw audio body, so it must dispatch
        # BEFORE the JSON parse path (which would consume the body).
        if self.path == "/tx/audio":
            return self._handle_tx_audio()
        # Phase 85b — streaming PCM upload, runs the TX state machine
        # IN-LINE with the request so audio reaches BM as it arrives.
        if self.path == "/tx/stream":
            return self._handle_tx_stream()
        # Phase 84aa — echo_bot posts finished transcripts here so
        # the bridge can broadcast them to every audio-stream
        # subscriber. JSON body: {call_id, text, engine}.
        if self.path == "/transcript":
            return self._handle_transcript()
        # Fall through to legacy JSON dispatcher (/tx/text + others).
        return ControlHandler._do_post_json(self)

    def _read_chunked_body(self):
        """Generator yielding chunk bytes from a Transfer-Encoding:
        chunked request body. http.server doesn't dechunk for us.
        Yields raw bytes; stops at the 0-length terminator."""
        while True:
            size_line = self.rfile.readline().strip()
            if not size_line:
                return
            try:
                size = int(size_line.split(b';', 1)[0], 16)
            except ValueError:
                return
            if size == 0:
                # Trailers (we don't care) + final CRLF
                while True:
                    line = self.rfile.readline()
                    if not line or line in (b"\r\n", b"\n"):
                        break
                return
            data = b''
            while len(data) < size:
                more = self.rfile.read(size - len(data))
                if not more:
                    return
                data += more
            # Trailing CRLF after each chunk's data
            self.rfile.readline()
            yield data

    def _handle_tx_stream(self) -> None:
        """Phase 85b — streaming TX endpoint.

        Body: raw 8 kHz s16le mono PCM, either as Content-Length
        bytes OR Transfer-Encoding: chunked. The TX state machine
        runs IN-LINE on this request thread: LC headers fire as
        soon as the request arrives, voice bursts emit at the wire's
        60 ms cadence as PCM chunks arrive, terminators fire on
        body EOF. End-to-end latency from "first bytes received"
        to "first byte on BM master" is ~360 ms (3 LC headers x
        60 ms).
        """
        if self.client.state != STATE_RUNNING:
            return self._json(503, {"error": "not authenticated"})

        from services.dvswitch.ambe_codec import AmbeCodec
        from services.dvswitch.dmr_tx import StreamingDMRTransmitter
        codec = AmbeCodec()

        # Phase 85c-fix-6: prepare WAV writer for own-TX recording so
        # the dispatcher (and reviewers) can replay the transmission
        # from the history card. The bridge is the single source of
        # truth for own-TX recording — proxy no longer writes to DB.
        wav_state = {"writer": None, "path": None, "frames": 0}

        # Phase 85c-fix-6: proxy can supply a stream_id via header so
        # both sides agree on the WAV filename without depending on
        # the HTTP response round-trip (which our React proxy
        # sometimes loses). Filename format is stable:
        #   /var/cache/ticketscad-dvswitch/recordings/tx-<stream_id_hex>.wav
        # Proxy writes its dmr_messages row using the same path
        # at PTT start; bridge writes WAV contents under that path.
        client_stream_hex = (self.headers.get("X-Stream-Id", "") or "").strip().lower()
        client_stream_id = None
        if client_stream_hex and len(client_stream_hex) == 8:
            try:
                client_stream_id = int(client_stream_hex, 16)
            except ValueError:
                client_stream_id = None

        def open_wav_for_stream(stream_id_hex: str) -> None:
            if wav_state["writer"] is not None:
                return
            try:
                rec_dir = "/var/cache/ticketscad-dvswitch/recordings"
                os.makedirs(rec_dir, exist_ok=True)
                fname = f"tx-{stream_id_hex}.wav"
                path = os.path.join(rec_dir, fname)
                w = wave.open(path, "wb")
                w.setnchannels(1); w.setsampwidth(2); w.setframerate(8000)
                wav_state["writer"] = w
                wav_state["path"] = path
                LOG.info("tx_stream WAV opened: %s", path)
            except Exception as e:
                LOG.warning("tx_stream WAV open failed: %s", e)

        def write_wav(pcm: bytes) -> None:
            w = wav_state["writer"]
            if w is None:
                return
            try:
                w.writeframes(pcm)
                wav_state["frames"] += len(pcm) // 2
            except Exception as e:
                LOG.warning("tx_stream WAV write failed: %s", e)

        def close_wav() -> Optional[str]:
            w = wav_state["writer"]
            if w is None:
                return None
            try:
                w.close()
            except Exception:
                pass
            wav_state["writer"] = None
            return wav_state["path"]

        def loopback(stream_id_bytes, src_id, dst_id, pcm_60ms,
                     vseq, is_first, is_last):
            sid_hex = stream_id_bytes.hex()
            if is_first:
                open_wav_for_stream(sid_hex)
            AUDIO_PUMP.broadcast_tx_audio(
                stream_id_bytes, src_id, dst_id, pcm_60ms,
                vseq, is_first=is_first, is_last=is_last,
            )
            # WAV gets only the actual audio chunks, not the empty
            # end-marker. _emit_loopback in dmr_tx already short-
            # circuits wav_writer on empty PCM, but defensive here.

        tx = StreamingDMRTransmitter(
            src_id=self.operator_id,
            dst_id=self.default_tg,
            repeater_id=self.client.config.dmr_id,
            send_fn=self.client.send_to_master,
            ambe_codec=codec,
            loopback_fn=loopback,
            wav_writer=write_wav,
        )
        if client_stream_id is not None:
            tx.stream_id = client_stream_id
            LOG.info("tx_stream using client-supplied stream_id 0x%08x", client_stream_id)
        try:
            # LC headers go out now — the dispatcher's voice has
            # ~360 ms to arrive before the wire starts hearing silence.
            tx.start()

            transfer_encoding = self.headers.get(
                "Transfer-Encoding", "").lower()
            content_length = self.headers.get("Content-Length", "")
            bytes_received = 0
            chunk_count = 0
            t0 = time.monotonic()
            LOG.info("tx_stream begin: TE=%r CL=%r",
                     transfer_encoding, content_length)
            if transfer_encoding == "chunked":
                for chunk in self._read_chunked_body():
                    bytes_received += len(chunk)
                    chunk_count += 1
                    tx.feed_pcm(chunk)
            else:
                length = int(content_length or "0")
                if length > 0:
                    remaining = length
                    while remaining > 0:
                        chunk = self.rfile.read(min(4096, remaining))
                        if not chunk:
                            break
                        bytes_received += len(chunk)
                        chunk_count += 1
                        tx.feed_pcm(chunk)
                        remaining -= len(chunk)
            elapsed = time.monotonic() - t0
            LOG.info("tx_stream body done: %d bytes in %d chunks over %.2fs",
                     bytes_received, chunk_count, elapsed)

            total = tx.finish()
            wav_path = close_wav()
            return self._json(200, {
                "ok": True,
                "stream_id": format(tx.stream_id, "08x"),
                "packets_sent": total,
                "bytes_received": bytes_received,
                "chunks": chunk_count,
                "audio_path": wav_path,
                "audio_frames": wav_state["frames"],
            })
        except Exception as e:
            LOG.exception("tx_stream failed: %s", e)
            try: tx.finish()
            except Exception: pass
            close_wav()
            return self._json(500, {"error": str(e)})
        finally:
            codec.close()

    def _handle_transcript(self) -> None:
        """Phase 84aa — accept a transcript JSON post and broadcast it
        to every audio-stream subscriber.

            POST /transcript
            { "call_id": "<stream hex>", "text": "...", "engine": "whisper" }
        """
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0 or length > 128 * 1024:
            return self._json(400, {"error": "invalid Content-Length"})
        try:
            body = self.rfile.read(length)
            msg = json.loads(body.decode("utf-8", "replace"))
        except Exception as e:
            return self._json(400, {"error": f"bad JSON: {e}"})
        if not isinstance(msg, dict):
            return self._json(400, {"error": "expected JSON object"})
        call_id = str(msg.get("call_id") or "").strip()
        text    = str(msg.get("text")    or "").strip()
        engine  = str(msg.get("engine")  or "whisper").strip()
        if not call_id:
            return self._json(400, {"error": "call_id required"})
        AUDIO_PUMP.publish_transcript(call_id, text, engine)
        return self._json(202, {"published": True, "call_id": call_id,
                                "engine": engine, "text_len": len(text)})

    def _handle_tx_audio(self) -> None:
        """Accept arbitrary audio bytes (the body); ffmpeg-transcode to
        8 kHz s16le mono PCM; transmit via DMRCallTransmitter as a
        single TG call. Used by the radio widget's PTT path.

        Phase 84t: ffmpeg runs synchronously (fast — a few hundred ms),
        but the DMR transmit is paced at ~60 ms per superframe (so 5 sec
        of audio takes 5 sec wall time). Returning AFTER the full
        transmission means the HTTP response keeps the Cloudflare Tunnel
        connection open for the full TX duration — long calls hit the
        ~100 s tunnel ceiling and the dispatcher sees an HTML 504 page.
        Instead, transcode synchronously (so the caller learns about
        format errors), then spawn a background thread for the actual
        DMR transmission, and return 202 Accepted immediately with a
        tx_id the caller can correlate against logs.
        """
        if self.client.state != STATE_RUNNING:
            return self._json(503, {"error": "not authenticated"})
        length = int(self.headers.get("Content-Length", "0") or "0")
        if length <= 0 or length > 8 * 1024 * 1024:
            return self._json(400, {"error": "invalid Content-Length"})
        body = self.rfile.read(length)
        if not body:
            return self._json(400, {"error": "empty body"})
        # Run ffmpeg with stdin pipe — auto-detect input format.
        try:
            ff = subprocess.run(
                [self.ffmpeg_bin, "-loglevel", "error",
                 "-i", "pipe:0",
                 "-f", "s16le", "-ar", "8000", "-ac", "1", "pipe:1"],
                input=body, capture_output=True, check=True, timeout=20,
            )
            pcm = ff.stdout
            # Phase 85f-7: 0.75 s pre-roll silence so far-end radios,
            # hotspots, and the BrandMeister relay path have time to
            # come up before the first syllable of TTS audio.
            # 0.75 s @ 8 kHz mono s16le = 12000 samples = 24000 bytes.
            # NOT applied to /tx/audio (widget) -- humans provide their
            # own pre-roll by holding PTT before speaking.
            pcm = (b"\x00" * 24000) + pcm
        except subprocess.CalledProcessError as e:
            return self._json(500, {
                "error": "ffmpeg transcode failed",
                "stderr": (e.stderr or b"").decode("utf-8", "replace")[:500],
            })
        except FileNotFoundError:
            return self._json(500, {"error": "ffmpeg not found"})
        if not pcm:
            return self._json(500, {"error": "empty PCM after transcode"})

        # Background DMR transmit so the HTTP response can return
        # immediately. Concurrency is naturally serialised at the
        # client.send_to_master socket level (one TX path at a time
        # would just queue up here — for an MVP this is acceptable).
        tx_id = secrets.token_hex(8)
        duration_ms = int(len(pcm) / 2 / 8000 * 1000)

        def _do_tx(pcm_bytes: bytes, tx_id: str) -> None:
            from services.dvswitch.ambe_codec import AmbeCodec
            from services.dvswitch.dmr_tx import DMRCallTransmitter
            codec = AmbeCodec()
            tx = DMRCallTransmitter(
                src_id=self.operator_id,
                dst_id=self.default_tg,
                repeater_id=self.client.config.dmr_id,
                send_fn=self.client.send_to_master,
                ambe_codec=codec,
            )
            try:
                sent = tx.transmit_pcm(pcm_bytes)
                LOG.info("tx_audio %s: transmitted %d packets (%d ms audio)",
                         tx_id, sent, int(len(pcm_bytes) / 2 / 8000 * 1000))
            except Exception as e:
                LOG.exception("tx_audio %s transmit failed: %s", tx_id, e)
            finally:
                codec.close()

        threading.Thread(target=_do_tx, args=(pcm, tx_id),
                         name=f"dmr-tx-{tx_id}", daemon=True).start()
        return self._json(202, {
            "accepted": True, "tx_id": tx_id,
            "pcm_bytes": len(pcm), "duration_ms": duration_ms,
        })

    def _do_post_json(self) -> None:
        """Legacy JSON POST path — kept under a private name so the
        public do_POST can dispatch /tx/audio before parsing JSON."""
        length = int(self.headers.get("Content-Length", "0") or "0")
        try:
            body = json.loads(self.rfile.read(length).decode()) if length else {}
        except json.JSONDecodeError:
            return self._json(400, {"error": "bad json"})
        if self.path == "/tx/text":
            text = (body.get("text") or "").strip()
            if not text:
                return self._json(400, {"error": "text required"})
            tg = int(body.get("talkgroup") or self.default_tg)
            src = int(body.get("src_id") or self.operator_id)
            # Phase 85f-11: optional dry_run skips on-air TX so we can
            # verify the TTS+AMBE pipeline without bothering operators.
            dry_run = bool(body.get("dry_run", False))
            # Phase 85f-9: pass through clear-channel wait param.
            ccs = float(body.get("clear_channel_seconds", 0) or 0)
            # Phase 113e: optional per-request Piper voice/bin override so the
            # caller (weather_radio.php etc.) can pick the voice the Voice &
            # Speech page routed for this speech application. DMR audio is
            # 8 kHz AMBE, so we stay on Piper here by design (hosted engines
            # buy nothing through the vocoder); this just selects the model.
            # Only an existing, readable .onnx is honored — otherwise fall
            # back to the bridge's configured voice (never fail a bulletin
            # over a bad override).
            voice = str(body.get("voice") or "").strip()
            use_voice = self.piper_voice
            if voice and os.path.isfile(voice):
                use_voice = voice
            result = self.client.tx_text(
                text=text, src_id=src, dst_id=tg,
                piper_bin=self.piper_bin,
                piper_voice=use_voice,
                ffmpeg_bin=self.ffmpeg_bin,
                dry_run=dry_run,
                clear_channel_seconds=ccs,
            )
            code = 200 if result.get("ok") else 500
            return self._json(code, result)
        return self._json(404, {"error": "not found"})

    def log_message(self, fmt: str, *args) -> None:
        LOG.info("HTTP " + (fmt % args))


def serve_http(client: HBPClient, port: int, bearer: str,
               operator_id: int, default_tg: int,
               piper_bin: str, piper_voice: str,
               ffmpeg_bin: str = "ffmpeg") -> ThreadingHTTPServer:
    ControlHandler.client = client
    ControlHandler.bearer = bearer
    ControlHandler.piper_bin = piper_bin
    ControlHandler.piper_voice = piper_voice
    ControlHandler.ffmpeg_bin = ffmpeg_bin
    ControlHandler.operator_id = operator_id
    ControlHandler.default_tg = default_tg
    server = ThreadingHTTPServer(("0.0.0.0", port), ControlHandler)
    t = threading.Thread(target=server.serve_forever,
                         name="hbp-http", daemon=True)
    t.start()
    LOG.info("HTTP control listening on :%d", port)
    return server


def _load_env_file(path: str) -> dict:
    out = {}
    with open(path, encoding="utf-8") as fh:
        for line in fh:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            k, v = line.split("=", 1)
            out[k.strip()] = v.strip()
    return out


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mmdvm-ini", default="/opt/MMDVM_Bridge/MMDVM_Bridge.ini",
                        help="MMDVM_Bridge.ini path (reuses identity + password)")
    parser.add_argument("--env", default=None,
                        help="optional env file for runtime overrides")
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args()

    logging.basicConfig(
        level=getattr(logging, args.log_level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    )

    if args.env and os.path.exists(args.env):
        env = _load_env_file(args.env)
        for k, v in env.items():
            os.environ.setdefault(k, v)

    if not os.path.exists(args.mmdvm_ini):
        LOG.error("MMDVM_Bridge.ini not found at %s", args.mmdvm_ini)
        raise SystemExit(2)

    config = HBPConfig.from_mmdvm_ini(args.mmdvm_ini)
    LOG.info("config loaded — DMR ID=%d callsign=%s master=%s:%d",
             config.dmr_id, config.callsign,
             config.master_host, config.master_port)

    client = HBPClient(config)

    # Phase 84s — wire the live audio pump so inbound DMRD voice bursts
    # get decoded to PCM in-process and pushed to /audio-stream subscribers.
    client.on_dmrd = AUDIO_PUMP.on_dmrd

    # Phase 84g — HTTP control server for /health and /tx/text.
    http_port = int(os.environ.get("DMR_HTTP_PORT", "18091"))
    bearer = os.environ.get("DMR_BEARER_TOKEN", "")
    operator_id = int(os.environ.get("DMR_OPERATOR_ID", "0"))
    default_tg = int(os.environ.get("DMR_DEFAULT_TG", "0"))
    piper_bin = os.environ.get("DMR_PIPER_BIN", "")
    piper_voice = os.environ.get("DMR_PIPER_VOICE", "")
    ffmpeg_bin = os.environ.get("DMR_FFMPEG_BIN", "ffmpeg")
    http_server = None
    if bearer and piper_bin and piper_voice:
        # Start the HBP client in a worker thread so we can also
        # serve HTTP from the main thread.
        worker = threading.Thread(target=client.run, name="hbp-loop",
                                  daemon=True)
        worker.start()
        http_server = serve_http(
            client, http_port, bearer,
            operator_id=operator_id or config.dmr_id,
            default_tg=default_tg,
            piper_bin=piper_bin, piper_voice=piper_voice,
            ffmpeg_bin=ffmpeg_bin,
        )

    def _sigterm(_sig, _frame):
        LOG.info("signal received, shutting down")
        client.running = False
        if http_server:
            http_server.shutdown()

    signal.signal(signal.SIGTERM, _sigterm)
    signal.signal(signal.SIGINT, _sigterm)

    if http_server:
        # Main thread holds open while worker runs.
        try:
            while client.running:
                time.sleep(0.5)
        except KeyboardInterrupt:
            pass
    else:
        # No HTTP — run inline.
        client.run()


if __name__ == "__main__":
    main()
