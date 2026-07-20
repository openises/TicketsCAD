"""
DMR TX call state machine — drives a full voice call from start to end.

Builds:
  1. Voice LC header burst (BS Data SYNC + BPTC-encoded Full LC + CRC)
  2. N voice superframes (burst A through F), each 6 bursts × 60 ms
  3. Voice terminator burst (same shape as LC header)

Each 33-byte burst is wrapped in a 55-byte HBP DMRD packet and sent
to BrandMeister via a caller-supplied send function. Pacing is
realtime — one packet every 60 ms (DMR Tier II voice burst rate).

Sequence number rules (per BrandMeister Homebrew/example/php2 +
captured reference 2026-06-15):
  * seq starts at 0 for the first LC header
  * the 2nd LC header (we send 2 per call setup) reuses seq=0
  * voice bursts increment seq by 1 per packet starting from 2
  * terminator gets the next seq after the last voice burst

Status byte (byte 15) values for TS2 group voice:
  0xA1 = TS2 + group + data_sync + DT_VOICE_LC_HEADER (0x01)
  0xA2 = TS2 + group + data_sync + DT_TERMINATOR_WITH_LC (0x02)
  0x90 = TS2 + group + voice_sync + burst A (vseq=0)
  0x81..0x85 = TS2 + group + voice + bursts B..F (vseq=1..5)

ETSI / source references:
  TS 102 361-1 v1.4.5 — clauses 5.1.2.x, 6.1, 7.1, 9.1.1, B.1.1
  MMDVMHost/DMRSlot.cpp — voice call sequencing
  MMDVMHost/DMRFullLC.cpp + CDMRLC — Full LC + CRC layout
"""

from __future__ import annotations
import os
import struct
import time
import logging
from typing import Callable, Optional

from services.dvswitch.ambe_codec import AmbeCodec, PCM_FRAME_BYTES
from services.dvswitch.bptc_19696 import BPTC19696
from services.dvswitch.embedded_lc import (
    build_ta_header_lc, encode_embedded_lc, LCSS_BY_VSEQ,
)
from services.dvswitch.rs_129 import (
    build_lc_trailer, VOICE_LC_HEADER_CRC_MASK, TERMINATOR_WITH_LC_CRC_MASK,
)
from services.dvswitch.slot_type import (
    DATA_TYPE_VOICE_LC_HEADER, DATA_TYPE_TERMINATOR_WITH_LC,
)
from services.dvswitch.voice_burst import (
    SYNC_BS_VOICE, SYNC_BS_DATA, SYNC_MS_VOICE, SYNC_MS_DATA,
    build_voice_burst_a, build_voice_burst_emb, build_data_burst,
)

DEFAULT_COLOR_CODE = 1   # BrandMeister default; matches MN Statewide TG3127

LOG = logging.getLogger("hbp.tx")

# ── DMR Full LC layout (12 bytes / 96 bits) ──────────────────────
# Composed of 9 bytes Full LC + 3 bytes 24-bit CRC. The 96 bits go
# into BPTC(196,96) for the voice LC header and terminator bursts.
#
# Full LC layout per ETSI clause 9.1.6, Table 9.7:
#   byte 0: PF(1) + Reserved(1) + FLCO(6)
#   byte 1: Feature Set ID (FID)
#   byte 2: Service Options
#   bytes 3..5: Destination ID (24-bit BE)
#   bytes 6..8: Source ID (24-bit BE)
FLCO_GROUP_VOICE = 0x00


def build_full_lc(
    src_id: int, dst_id: int,
    flco: int = FLCO_GROUP_VOICE,
    fid: int = 0x00,
    svc_options: int = 0x00,
    data_type_mask: tuple = VOICE_LC_HEADER_CRC_MASK,
) -> bytes:
    """Build the 9-byte Full LC + 3-byte RS(12,9) parity trailer =
    12 bytes total for feeding into BPTC(196,96).

    The trailer is Reed-Solomon (12,9) parity over GF(2^8), XOR'd
    with the per-data-type mask (0x969696 for voice LC header,
    0x999999 for terminator). MMDVMHost/DMRFullLC.cpp is the
    canonical reference; BrandMeister validates the syndrome on
    receive and silently drops the entire stream when it doesn't
    match.
    """
    lc = bytearray(12)
    lc[0] = (flco & 0x3F) & 0x3F      # PF and Reserved are 0
    lc[1] = fid & 0xFF
    lc[2] = svc_options & 0xFF
    # dst_id and src_id as 24-bit BE.
    lc[3] = (dst_id >> 16) & 0xFF
    lc[4] = (dst_id >> 8) & 0xFF
    lc[5] = dst_id & 0xFF
    lc[6] = (src_id >> 16) & 0xFF
    lc[7] = (src_id >> 8) & 0xFF
    lc[8] = src_id & 0xFF
    # RS(12,9) parity XOR with data-type mask.
    lc[9], lc[10], lc[11] = build_lc_trailer(bytes(lc[:9]), data_type_mask)
    return bytes(lc)


# ── HBP DMRD packet wrapper ──────────────────────────────────────
def build_dmrd_packet(
    seq: int,
    src_id: int,
    dst_id: int,
    repeater_id: int,
    status_byte: int,
    stream_id: int,
    dmr_payload_33: bytes,
) -> bytes:
    """Wrap a 33-byte DMR burst in a 55-byte HBP DMRD packet."""
    if len(dmr_payload_33) != 33:
        raise ValueError(f"DMR payload must be 33 bytes, got "
                         f"{len(dmr_payload_33)}")
    pkt = bytearray(55)
    pkt[0:4] = b"DMRD"
    pkt[4] = seq & 0xFF
    pkt[5] = (src_id >> 16) & 0xFF
    pkt[6] = (src_id >> 8) & 0xFF
    pkt[7] = src_id & 0xFF
    pkt[8] = (dst_id >> 16) & 0xFF
    pkt[9] = (dst_id >> 8) & 0xFF
    pkt[10] = dst_id & 0xFF
    pkt[11:15] = struct.pack(">I", repeater_id)
    pkt[15] = status_byte & 0xFF
    pkt[16:20] = struct.pack(">I", stream_id)
    pkt[20:53] = dmr_payload_33
    # bytes 53-54: BER + RSSI (typically zero for software hotspots)
    pkt[53] = 0
    pkt[54] = 0
    return bytes(pkt)


# ── Status byte presets — STANDARD MMDVMHost HBP format ──────────
# Earlier versions used BM-extended values with bit 7 set (0xA1, 0x90,
# 0x81-0x85, 0xA2). Those were accepted by BM auth and showed on the
# public hose, but BM silently DROPPED them at the relay layer — they
# never reached TG 3127 subscribers. Switching to the bit-7-clear
# standard MMDVMHost values (proven correct by replaying a captured
# duplex-hotspot call: it reached Pi-Star with the "Net" tag) is what
# makes BM actually forward.
STATUS_TS2_GROUP_LC_HEADER  = 0x21   # data_sync + DT_VOICE_LC_HEADER
STATUS_TS2_GROUP_TERMINATOR = 0x22   # data_sync + DT_TERMINATOR_WITH_LC
STATUS_TS2_GROUP_BURST_A    = 0x10   # voice_sync (burst A)
STATUS_TS2_GROUP_BURST_B    = 0x01
STATUS_TS2_GROUP_BURST_C    = 0x02
STATUS_TS2_GROUP_BURST_D    = 0x03
STATUS_TS2_GROUP_BURST_E    = 0x04
STATUS_TS2_GROUP_BURST_F    = 0x05

BURST_STATUS_BY_VSEQ = (
    STATUS_TS2_GROUP_BURST_A,
    STATUS_TS2_GROUP_BURST_B,
    STATUS_TS2_GROUP_BURST_C,
    STATUS_TS2_GROUP_BURST_D,
    STATUS_TS2_GROUP_BURST_E,
    STATUS_TS2_GROUP_BURST_F,
)


# ── TX state machine ─────────────────────────────────────────────
DMR_BURST_INTERVAL_S = 0.060        # 60 ms per voice burst (Tier II)
NUM_LC_HEADERS = 3                  # BM reference sends 3 LC headers
NUM_TERMINATORS = 1


# Process-wide stream-id counter. BM's reference inbound uses a small
# integer in byte 0 + zeros after (e.g. 0x03000000 = call #3), not a
# random 32-bit. We replicate that format so the master's validation
# doesn't reject our packets.
_STREAM_ID_COUNTER = 0


class DMRCallTransmitter:
    """Sends one DMR voice call from PCM input to wire HBP packets.

    Caller supplies a send_fn(bytes) callback that delivers the raw
    55-byte DMRD packets to the master. This class doesn't open
    sockets — it composes packets and paces them.
    """

    def __init__(
        self,
        src_id: int,
        dst_id: int,
        repeater_id: int,
        send_fn: Callable[[bytes], None],
        ambe_codec: Optional[AmbeCodec] = None,
        log: logging.Logger = LOG,
        color_code: int = DEFAULT_COLOR_CODE,
        talker_alias: str = "N0NKI",
    ) -> None:
        self.src_id = src_id
        self.dst_id = dst_id
        self.repeater_id = repeater_id
        self.send_fn = send_fn
        self.codec = ambe_codec or AmbeCodec()
        self.log = log
        self.color_code = color_code
        self.talker_alias = talker_alias
        self._bptc = BPTC19696()
        # Pre-compute the 4 Embedded LC chunks for our TA Header.
        # These are constant for the duration of the process — re-built
        # only if talker_alias is changed externally.
        self._ta_chunks = encode_embedded_lc(
            build_ta_header_lc(self.talker_alias)
        )

    def transmit_pcm(self, pcm: bytes) -> int:
        """Send the entire PCM stream as one DMR call. Returns the
        total number of HBP DMRD packets sent.

        PCM is 8 kHz mono 16-bit LE. Zero-padded up to a 60 ms (3
        AMBE frame) boundary so the last burst is well-formed.
        """
        if not pcm:
            return 0
        # Random 32-bit stream ID — matches the format Pi-Star sends
        # outbound (verified by USG packet capture 2026-06-15). The
        # `0x03000000`-style counter we observed in BM-forwarded
        # *inbound* packets is BM's relay rewrite, not the
        # originating stream_id.
        stream_id = int.from_bytes(os.urandom(4), "big")

        # Header and terminator carry the SAME 9-byte LC content but
        # with DIFFERENT RS(12,9) trailer masks (0x969696 for header,
        # 0x999999 for terminator), so the 12-byte payloads — and
        # therefore the BPTC outputs — differ.
        lc_hdr_12 = build_full_lc(
            self.src_id, self.dst_id,
            data_type_mask=VOICE_LC_HEADER_CRC_MASK,
        )
        lc_term_12 = build_full_lc(
            self.src_id, self.dst_id,
            data_type_mask=TERMINATOR_WITH_LC_CRC_MASK,
        )
        bptc_hdr  = bytearray(33); self._bptc.encode(lc_hdr_12,  bptc_hdr)
        bptc_term = bytearray(33); self._bptc.encode(lc_term_12, bptc_term)

        # BS Data SYNC — what a duplex repeater/hotspot sends to BM
        # (verified by capture of working duplex hotspot 312720222
        # outbound on 2026-06-16: c46d ff57d75df5de... = BS Data SYNC).
        # An earlier capture from a simplex DMO Pi-Star sent MS sync,
        # which led us briefly down the wrong path; the duplex/repeater
        # variant — which is what BM actually forwards — uses BS.
        lc_header_burst = build_data_burst(
            bytes(bptc_hdr), sync_pattern=SYNC_BS_DATA,
            color_code=self.color_code,
            data_type=DATA_TYPE_VOICE_LC_HEADER,
        )
        terminator_burst = build_data_burst(
            bytes(bptc_term), sync_pattern=SYNC_BS_DATA,
            color_code=self.color_code,
            data_type=DATA_TYPE_TERMINATOR_WITH_LC,
        )

        # Encode PCM → list of AMBE+2 frames (one per 20 ms).
        ambe_frames = self.codec.encode_stream(pcm)
        # Pad to a 3-frame boundary.
        while len(ambe_frames) % 3 != 0:
            ambe_frames.append(self.codec.encode(b"\x00" * PCM_FRAME_BYTES))
        # Group into 3-frame voice bursts.
        burst_groups = [
            (ambe_frames[i], ambe_frames[i + 1], ambe_frames[i + 2])
            for i in range(0, len(ambe_frames), 3)
        ]

        # Pace using monotonic absolute-time schedule (prevents drift).
        next_send = time.monotonic()
        seq = 0
        sent = 0

        # ── LC headers (NUM_LC_HEADERS copies) ───────────────────
        # BM reference: 3 LC headers with seq=0,1,2 (continuous, not
        # duplicate). Voice continues with seq=3, terminator gets the
        # next sequential value.
        for _ in range(NUM_LC_HEADERS):
            pkt = build_dmrd_packet(
                seq=seq,
                src_id=self.src_id, dst_id=self.dst_id,
                repeater_id=self.repeater_id,
                status_byte=STATUS_TS2_GROUP_LC_HEADER,
                stream_id=stream_id,
                dmr_payload_33=lc_header_burst,
            )
            self._send_at(next_send, pkt)
            sent += 1
            next_send += DMR_BURST_INTERVAL_S
            seq += 1

        # ── voice superframes ────────────────────────────────────
        vseq = 0
        for f1, f2, f3 in burst_groups:
            if vseq == 0:
                # BS Voice SYNC — duplex repeater/hotspot direction.
                # Matches captured outbound from working duplex hotspot.
                payload = build_voice_burst_a(
                    f1, f2, f3, sync_pattern=SYNC_BS_VOICE)
            else:
                # Voice bursts B-F carry the 4-fragment Embedded LC
                # for the Talker Alias. LCSS sequence (MMDVMHost
                # convention, not the literal ETSI labels):
                #   B (vseq=1, chunk 0): LCSS=1 first
                #   C (vseq=2, chunk 1): LCSS=3 middle
                #   D (vseq=3, chunk 2): LCSS=3 middle
                #   E (vseq=4, chunk 3): LCSS=2 last
                #   F (vseq=5):           LCSS=0 null
                if vseq <= 4:
                    embedded = self._ta_chunks[vseq - 1]
                else:
                    embedded = b"\x00\x00\x00\x00"
                payload = build_voice_burst_emb(
                    f1, f2, f3,
                    embedded_32=embedded,
                    color_code=self.color_code,
                    lcss=LCSS_BY_VSEQ[vseq])
            status = BURST_STATUS_BY_VSEQ[vseq]
            pkt = build_dmrd_packet(
                seq=seq,
                src_id=self.src_id, dst_id=self.dst_id,
                repeater_id=self.repeater_id,
                status_byte=status,
                stream_id=stream_id,
                dmr_payload_33=payload,
            )
            self._send_at(next_send, pkt)
            sent += 1
            next_send += DMR_BURST_INTERVAL_S
            seq += 1
            vseq = (vseq + 1) % 6

        # ── terminator ────────────────────────────────────────────
        for _ in range(NUM_TERMINATORS):
            pkt = build_dmrd_packet(
                seq=seq,
                src_id=self.src_id, dst_id=self.dst_id,
                repeater_id=self.repeater_id,
                status_byte=STATUS_TS2_GROUP_TERMINATOR,
                stream_id=stream_id,
                dmr_payload_33=terminator_burst,
            )
            self._send_at(next_send, pkt)
            sent += 1
            next_send += DMR_BURST_INTERVAL_S
            seq += 1

        self.log.info(
            "TX call complete: stream=0x%08x, %d packets sent over %.2fs",
            stream_id, sent, sent * DMR_BURST_INTERVAL_S,
        )
        return sent

    def _send_at(self, deadline: float, packet: bytes) -> None:
        """Sleep until `deadline` (monotonic time), then send."""
        now = time.monotonic()
        if deadline > now:
            time.sleep(deadline - now)
        self.send_fn(packet)


# ──────────────────────────────────────────────────────────────────
# StreamingDMRTransmitter (Phase 85b)
# ──────────────────────────────────────────────────────────────────
#
# DMRCallTransmitter above takes a complete PCM buffer and runs to
# completion before returning. That guarantees TX latency = at least
# the speaker's full PTT duration, which makes the channel useless
# for back-and-forth radio traffic.
#
# StreamingDMRTransmitter has the same on-the-wire format but is
# fed PCM in chunks as the dispatcher speaks. The start() method
# sends the 3 LC headers immediately; feed_pcm() encodes/sends voice
# bursts as soon as it has 60 ms (3 AMBE frames = 480 bytes PCM) of
# input; finish() drains any partial trailing buffer and emits the
# 2 terminator bursts.
#
# Pacing: voice bursts must go out every 60 ms (one slot of one
# TDMA frame on the wire). If feed_pcm() supplies data slower than
# that, we stall waiting for input — the wire-side dispatcher's
# receiver hears a brief silence then resumes when our next burst
# arrives. If it supplies faster (e.g. browser back-pressure paused
# then released a buffer), we send bursts back-to-back until caught
# up, but throttled to no faster than the 60 ms slot cadence.
#
# IMPORTANT: this is NOT thread-safe. One streaming TX at a time per
# instance. The caller (typically hbp_client._handle_tx_stream) owns
# the lifecycle and runs everything on one thread.
class StreamingDMRTransmitter:
    def __init__(
        self,
        src_id: int,
        dst_id: int,
        repeater_id: int,
        send_fn: Callable[[bytes], None],
        ambe_codec: Optional[AmbeCodec] = None,
        log: logging.Logger = LOG,
        color_code: int = DEFAULT_COLOR_CODE,
        talker_alias: str = "N0NKI",
        loopback_fn: Optional[Callable[..., None]] = None,
        wav_writer: Optional[Callable[[bytes], None]] = None,
    ) -> None:
        self.src_id = src_id
        self.dst_id = dst_id
        self.repeater_id = repeater_id
        self.send_fn = send_fn
        self.codec = ambe_codec or AmbeCodec()
        self.log = log
        self.color_code = color_code
        self.talker_alias = talker_alias
        self._bptc = BPTC19696()
        self._ta_chunks = encode_embedded_lc(
            build_ta_header_lc(self.talker_alias)
        )
        self.stream_id = int.from_bytes(os.urandom(4), "big")
        self.seq = 0
        self.vseq = 0
        self.sent_packets = 0
        self.next_send = None
        self.started = False
        self.finished = False
        self._pcm_buffer = bytearray()
        self._lc_hdr_burst = None
        self._terminator_burst = None
        # Phase 85c-fix-6: loopback hooks so other dispatcher sessions
        # hear/record locally-originated TX. loopback_fn signature:
        #   fn(stream_id_bytes, src_id, dst_id, pcm_60ms, voice_seq,
        #      is_first, is_last)
        # wav_writer gets the raw 60ms PCM chunks for on-disk recording.
        self._loopback_fn = loopback_fn
        self._wav_writer = wav_writer
        self._voice_seq = 0
        self._loopback_started = False

    def start(self) -> None:
        """Send the 3 LC headers. Call before feed_pcm()."""
        if self.started:
            return
        self.started = True
        self.next_send = time.monotonic()

        lc_hdr_12 = build_full_lc(
            self.src_id, self.dst_id,
            data_type_mask=VOICE_LC_HEADER_CRC_MASK,
        )
        lc_term_12 = build_full_lc(
            self.src_id, self.dst_id,
            data_type_mask=TERMINATOR_WITH_LC_CRC_MASK,
        )
        bptc_hdr  = bytearray(33); self._bptc.encode(lc_hdr_12,  bptc_hdr)
        bptc_term = bytearray(33); self._bptc.encode(lc_term_12, bptc_term)
        self._lc_hdr_burst = build_data_burst(
            bytes(bptc_hdr), sync_pattern=SYNC_BS_DATA,
            color_code=self.color_code,
            data_type=DATA_TYPE_VOICE_LC_HEADER,
        )
        self._terminator_burst = build_data_burst(
            bytes(bptc_term), sync_pattern=SYNC_BS_DATA,
            color_code=self.color_code,
            data_type=DATA_TYPE_TERMINATOR_WITH_LC,
        )

        for _ in range(NUM_LC_HEADERS):
            pkt = build_dmrd_packet(
                seq=self.seq,
                src_id=self.src_id, dst_id=self.dst_id,
                repeater_id=self.repeater_id,
                status_byte=STATUS_TS2_GROUP_LC_HEADER,
                stream_id=self.stream_id,
                dmr_payload_33=self._lc_hdr_burst,
            )
            self._send_at(self.next_send, pkt)
            self.sent_packets += 1
            self.next_send += DMR_BURST_INTERVAL_S
            self.seq = (self.seq + 1) & 0xFF
        self.log.info(
            "streaming TX started stream=0x%08x src=%d dst=%d",
            self.stream_id, self.src_id, self.dst_id,
        )

    def feed_pcm(self, chunk: bytes) -> int:
        """Append raw PCM (8 kHz s16le mono) to the input buffer and
        emit as many voice bursts as the buffer can fill. Returns the
        number of bursts sent during this call."""
        if not self.started:
            self.start()
        if not chunk or self.finished:
            return 0
        self._pcm_buffer.extend(chunk)
        # One voice burst = 3 AMBE frames = 3 * 20 ms = 60 ms of audio.
        # PCM_FRAME_BYTES = 320 (160 samples * 2 bytes). 3 frames = 960 bytes.
        frame3_bytes = PCM_FRAME_BYTES * 3
        bursts_sent = 0
        while len(self._pcm_buffer) >= frame3_bytes:
            pcm_60ms = bytes(self._pcm_buffer[:frame3_bytes])
            del self._pcm_buffer[:frame3_bytes]
            self._emit_loopback(pcm_60ms, is_last=False)
            frames = [
                self.codec.encode(pcm_60ms[i * PCM_FRAME_BYTES:(i + 1) * PCM_FRAME_BYTES])
                for i in range(3)
            ]
            self._send_voice_burst(*frames)
            bursts_sent += 1
        return bursts_sent

    def finish(self) -> int:
        """Drain any trailing PCM (padded with silence to a 60 ms
        boundary), emit the terminators, return the total packet
        count for this call."""
        if not self.started:
            return 0
        if self.finished:
            return self.sent_packets
        self.finished = True

        frame3_bytes = PCM_FRAME_BYTES * 3
        if 0 < len(self._pcm_buffer) < frame3_bytes:
            pad = frame3_bytes - len(self._pcm_buffer)
            self._pcm_buffer.extend(b"\x00" * pad)
            pcm_60ms = bytes(self._pcm_buffer[:frame3_bytes])
            del self._pcm_buffer[:frame3_bytes]
            self._emit_loopback(pcm_60ms, is_last=False)
            frames = [
                self.codec.encode(pcm_60ms[i * PCM_FRAME_BYTES:(i + 1) * PCM_FRAME_BYTES])
                for i in range(3)
            ]
            self._send_voice_burst(*frames)

        # End-of-call loopback marker (empty PCM, is_last=True). Fires
        # call_end on subscriber sessions and closes the WAV file.
        self._emit_loopback(b"", is_last=True)

        for _ in range(NUM_TERMINATORS):
            pkt = build_dmrd_packet(
                seq=self.seq,
                src_id=self.src_id, dst_id=self.dst_id,
                repeater_id=self.repeater_id,
                status_byte=STATUS_TS2_GROUP_TERMINATOR,
                stream_id=self.stream_id,
                dmr_payload_33=self._terminator_burst,
            )
            self._send_at(self.next_send, pkt)
            self.sent_packets += 1
            self.next_send += DMR_BURST_INTERVAL_S
            self.seq = (self.seq + 1) & 0xFF

        self.log.info(
            "streaming TX done stream=0x%08x packets=%d",
            self.stream_id, self.sent_packets,
        )
        return self.sent_packets

    def _send_voice_burst(self, f1: bytes, f2: bytes, f3: bytes) -> None:
        if self.vseq == 0:
            payload = build_voice_burst_a(
                f1, f2, f3, sync_pattern=SYNC_BS_VOICE)
        else:
            if self.vseq <= 4:
                embedded = self._ta_chunks[self.vseq - 1]
            else:
                embedded = b"\x00\x00\x00\x00"
            payload = build_voice_burst_emb(
                f1, f2, f3,
                embedded_32=embedded,
                color_code=self.color_code,
                lcss=LCSS_BY_VSEQ[self.vseq])
        status = BURST_STATUS_BY_VSEQ[self.vseq]
        pkt = build_dmrd_packet(
            seq=self.seq,
            src_id=self.src_id, dst_id=self.dst_id,
            repeater_id=self.repeater_id,
            status_byte=status,
            stream_id=self.stream_id,
            dmr_payload_33=payload,
        )
        self._send_at(self.next_send, pkt)
        self.sent_packets += 1
        self.next_send += DMR_BURST_INTERVAL_S
        self.seq = (self.seq + 1) & 0xFF
        self.vseq = (self.vseq + 1) % 6

    def _send_at(self, deadline: float, packet: bytes) -> None:
        now = time.monotonic()
        if deadline > now:
            time.sleep(deadline - now)
        self.send_fn(packet)

    def _emit_loopback(self, pcm_60ms: bytes, is_last: bool) -> None:
        """Fan locally-originated audio out to (a) the SSE pump so other
        dispatcher sessions hear the TX in real time, and (b) the WAV
        writer for the history-card replay button. Both hooks are
        optional — if neither is set, this is a no-op.

        is_first is computed internally: emitted exactly once per
        transmitter instance, on the first PCM chunk.
        is_last is set by the caller from finish().
        """
        if not self._loopback_fn and not self._wav_writer:
            return
        is_first = not self._loopback_started
        if is_first and pcm_60ms:
            self._loopback_started = True
        sid_bytes = self.stream_id.to_bytes(4, "big")
        if self._loopback_fn:
            try:
                self._loopback_fn(
                    sid_bytes, self.src_id, self.dst_id,
                    pcm_60ms, self._voice_seq,
                    is_first, is_last,
                )
            except Exception as e:
                self.log.warning("loopback_fn raised: %s", e)
        if self._wav_writer and pcm_60ms:
            try:
                self._wav_writer(pcm_60ms)
            except Exception as e:
                self.log.warning("wav_writer raised: %s", e)
        if pcm_60ms:
            self._voice_seq = (self._voice_seq + 1) & 0xFFFFFFFF


# ── self-test ─────────────────────────────────────────────────────
def _selftest() -> int:
    """Build a 1-second call, capture the packet stream into a list,
    and verify structural shape (counts of header / voice / terminator)
    matches DMR Tier II."""
    captured = []

    def fake_send(pkt: bytes) -> None:
        captured.append(pkt)

    # 1 second of silence — should be 50 AMBE frames = 16.67 bursts;
    # padded up to 17 bursts (51 AMBE frames).
    pcm = b"\x00" * PCM_FRAME_BYTES * 50

    tx = DMRCallTransmitter(
        src_id=3104410,
        dst_id=3127,
        repeater_id=310441017,
        send_fn=fake_send,
    )
    n = tx.transmit_pcm(pcm)
    print(f"sent {n} packets")

    # Categorize by byte 15 (status).
    from collections import Counter
    statuses = Counter(p[15] for p in captured)
    print(f"status distribution: {dict(statuses)}")

    # Expected for 50 AMBE → 17 voice bursts + 3 LC + 1 term = 21 packets
    expected_voice_bursts = 17
    expected_total = NUM_LC_HEADERS + expected_voice_bursts + NUM_TERMINATORS
    if n != expected_total:
        print(f"FAIL: expected {expected_total} packets, got {n}")
        return 1
    if statuses[STATUS_TS2_GROUP_LC_HEADER] != NUM_LC_HEADERS:
        print(f"FAIL: expected {NUM_LC_HEADERS} LC headers")
        return 1
    if statuses[STATUS_TS2_GROUP_TERMINATOR] != NUM_TERMINATORS:
        print(f"FAIL: expected {NUM_TERMINATORS} terminator")
        return 1
    print(f"[PASS] {n} packets, {NUM_LC_HEADERS} LC headers + "
          f"{expected_voice_bursts} voice + {NUM_TERMINATORS} terminator")

    # Verify stream ID is consistent across all packets.
    stream_ids = set(p[16:20] for p in captured)
    if len(stream_ids) != 1:
        print(f"FAIL: stream_id varies across packets: {stream_ids}")
        return 1
    print(f"[PASS] stream ID consistent: {next(iter(stream_ids)).hex()}")

    # Verify burst A sync pattern is in every burst-A packet.
    burst_a_pkts = [p for p in captured if p[15] == STATUS_TS2_GROUP_BURST_A]
    for pkt in burst_a_pkts[:1]:
        payload = pkt[20:53]
        if SYNC_BS_VOICE.hex() not in payload.hex():
            print(f"FAIL: burst A {payload.hex()} missing BS Voice SYNC")
            return 1
    print(f"[PASS] burst A packets carry BS Voice SYNC ({len(burst_a_pkts)} found)")

    return 0


if __name__ == "__main__":
    import sys
    here = os.path.dirname(os.path.abspath(__file__))
    sys.path.insert(0, os.path.dirname(os.path.dirname(here)))
    logging.basicConfig(level=logging.INFO,
                        format="%(asctime)s %(levelname)s [%(name)s] %(message)s")
    raise SystemExit(_selftest())
