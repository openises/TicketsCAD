"""
DMR voice burst constructor — assembles 33-byte burst payloads ready
for the 20..52 DMR-data slot of an HBP DMRD packet.

Each voice burst has the 264-bit structure:

    bits   0..107  left payload — AMBE frame 1 (full 72 wire bits) +
                                   AMBE frame 2 (first 36 of its 72)
    bits 108..155  centre 48-bit slot
    bits 156..263  right payload — AMBE frame 2 (last 36 of 72) +
                                   AMBE frame 3 (full 72 wire bits)

The centre 48-bit slot carries:

    Burst A:           Voice SYNC pattern (MS or BS — we use MS
                       for simplex hotspots per ETSI 9.1.1)
    Bursts B, C, D, E: 4-bit EMB nibble + 32-bit embedded-LC fragment +
                       4-bit EMB nibble
    Burst F:           4-bit EMB nibble + 32-bit null embedded data +
                       4-bit EMB nibble (for v1 — RC support deferred)

The voice LC header and terminator are 33-byte data bursts (not
voice) — their structure is BPTC(196,96)-encoded LC + 48-bit Data
SYNC + BPTC(196,96)-encoded LC. They're built by a separate function.

ETSI references:
  TS 102 361-1 v1.4.5
    clause 4.2.2   — burst structure
    clause 5.1.2.1 — voice superframe (bursts A-F)
    clause 5.1.2.2 — voice initiation (LC header)
    clause 5.1.2.3 — voice termination
    clause 7.1.3   — embedded signalling
    Table 9.2      — SYNC patterns
"""

from __future__ import annotations
from typing import List

# We import _prng_data lazily inside the build functions so this
# module stays import-safe even before the PRNG table file lands.

# ── SYNC patterns (ETSI Table 9.2, 48 bits MSB-first) ────────────
# Hex strings; the actual 6-byte values are derived at module load.
SYNC_BS_VOICE = bytes.fromhex("755FD7DF75F7")   # Base Station Voice
SYNC_BS_DATA  = bytes.fromhex("DFF57D75DF5D")   # Base Station Data
SYNC_MS_VOICE = bytes.fromhex("7F7D5DD57DFD")   # Mobile Station Voice
SYNC_MS_DATA  = bytes.fromhex("D5D7F77FD757")   # Mobile Station Data
SYNC_MS_STD_RC = bytes.fromhex("77D55F7DFD77")  # MS Standalone RC

assert all(len(s) == 6 for s in (SYNC_BS_VOICE, SYNC_BS_DATA,
                                  SYNC_MS_VOICE, SYNC_MS_DATA))


# ── bit helpers ─────────────────────────────────────────────────
def _bits_from_bytes(data: bytes) -> List[bool]:
    """Unpack bytes into MSB-first booleans."""
    out: List[bool] = []
    for b in data:
        for i in range(8):
            out.append(bool((b >> (7 - i)) & 1))
    return out


def _bits_to_bytes(bits: List[bool]) -> bytes:
    """Pack MSB-first booleans into bytes. len(bits) must be %8 == 0."""
    if len(bits) % 8 != 0:
        raise ValueError(f"bit count must be multiple of 8, got {len(bits)}")
    out = bytearray(len(bits) // 8)
    for i, b in enumerate(bits):
        if b:
            out[i >> 3] |= 1 << (7 - (i & 7))
    return bytes(out)


def _write_centre_48(burst: bytearray, centre6: bytes) -> None:
    """Write a 48-bit sync or EMB+embedded value into bits 108..155
    of the 33-byte burst payload.

    Because 108 isn't byte-aligned (it's bit 4 of byte 13), we have
    to do bit-level packing: the 48 bits straddle bytes 13..19 with
    a 4-bit offset.
    """
    if len(centre6) != 6:
        raise ValueError(f"centre48 must be 6 bytes, got {len(centre6)}")
    # Pull existing bits, splice in our 48, repack.
    bits = []
    for i, b in enumerate(burst):
        for j in range(8):
            bits.append(bool((b >> (7 - j)) & 1))
    centre_bits = _bits_from_bytes(centre6)
    for i in range(48):
        bits[108 + i] = centre_bits[i]
    new_bytes = _bits_to_bytes(bits)
    burst[:] = new_bytes


# ── voice burst constructor ─────────────────────────────────────
def build_voice_burst(
    ambe_frame_1: bytes,
    ambe_frame_2: bytes,
    ambe_frame_3: bytes,
    centre48: bytes,
) -> bytes:
    """Build a 33-byte voice burst from three 7-byte AMBE+2 frames
    and a 6-byte centre slot value (sync pattern OR EMB+embedded).

    Returns 33 bytes ready to drop into a DMRD packet's positions
    20..52.
    """
    from services.dvswitch.ambe_fec import place_ambe_frames_into_burst  # noqa
    from services.dvswitch._prng_data import PRNG_TABLE  # noqa

    if any(len(f) != 7 for f in (ambe_frame_1, ambe_frame_2, ambe_frame_3)):
        raise ValueError("each AMBE frame must be exactly 7 bytes")
    if len(centre48) != 6:
        raise ValueError("centre48 must be exactly 6 bytes")

    burst = bytearray(33)
    # 1) Place the 3 AMBE+2 frames with FEC into the voice-content
    #    bit positions (DMR_A/B/C tables).
    place_ambe_frames_into_burst(
        ambe_frame_1, ambe_frame_2, ambe_frame_3,
        burst, PRNG_TABLE,
    )
    # 2) Overwrite the centre 48 bits with the supplied sync/EMB.
    _write_centre_48(burst, centre48)
    return bytes(burst)


def build_voice_burst_a(
    ambe_frame_1: bytes,
    ambe_frame_2: bytes,
    ambe_frame_3: bytes,
    sync_pattern: bytes = SYNC_BS_VOICE,
) -> bytes:
    """Burst A — voice sync pattern in the centre.

    BS Voice SYNC is what BrandMeister forwards to subscribers. We
    use BS sync because our captures from BM (the gold standard for
    'what BM expects') show BS sync. The Sync.cpp logic in
    MMDVMHost picks MS for simplex / BS for duplex, but for direct
    HBP TX to a master we follow what the master itself emits.
    """
    return build_voice_burst(ambe_frame_1, ambe_frame_2, ambe_frame_3,
                             sync_pattern)


def build_voice_burst_emb(
    ambe_frame_1: bytes,
    ambe_frame_2: bytes,
    ambe_frame_3: bytes,
    embedded_32: bytes = b"\x00\x00\x00\x00",
    color_code: int = 1,
    lcss: int = 0,
    pi: bool = False,
) -> bytes:
    """Bursts B, C, D, E, F — proper 16-bit QR(16,7)-coded EMB +
    32-bit embedded data centre.

    The centre 48 bits are laid out as:
        bits 108-111 = EMB nibble 1
        bits 112-115 = EMB nibble 2
        bits 116-147 = 32-bit embedded data (LC fragment or null)
        bits 148-151 = EMB nibble 3
        bits 152-155 = EMB nibble 4

    For Phase 84j v1 we send LCSS=0 (no embedded LC reassembly) +
    zero embedded data, with proper EMB FEC over CC=1. This is what
    BrandMeister expects mid-call; without proper EMB on voice
    bursts B-F, BM accepts the LC header (via slot type) but drops
    voice mid-stream. Phase 84d will add full embedded LC across B-E.
    """
    from services.dvswitch.ambe_fec import place_ambe_frames_into_burst  # noqa
    from services.dvswitch._prng_data import PRNG_TABLE  # noqa
    from services.dvswitch.emb import apply_emb, write_embedded_data  # noqa

    if any(len(f) != 7 for f in (ambe_frame_1, ambe_frame_2, ambe_frame_3)):
        raise ValueError("each AMBE frame must be exactly 7 bytes")
    if len(embedded_32) != 4:
        raise ValueError("embedded_32 must be exactly 4 bytes")

    burst = bytearray(33)
    # 1) AMBE voice content (216 bits at DMR_A/B/C positions).
    place_ambe_frames_into_burst(
        ambe_frame_1, ambe_frame_2, ambe_frame_3,
        burst, PRNG_TABLE,
    )
    # 2) Overlay the 4-byte embedded data slot (centre bits 116-147).
    write_embedded_data(burst, embedded_32)
    # 3) Apply 16-bit EMB code into the 4 nibbles around it.
    apply_emb(burst, color_code=color_code, pi=pi, lcss=lcss)
    return bytes(burst)


# ── data burst constructor (voice LC header / terminator) ─────────
def build_data_burst(
    bptc_payload_33: bytes,
    sync_pattern: bytes = SYNC_BS_DATA,
    color_code: int = 1,
    data_type: int = 1,
) -> bytes:
    """Build a 33-byte DATA burst (voice LC header or terminator).

    The caller supplies a 33-byte buffer already populated with BPTC
    output (LC + 24-bit CRC encoded via BPTC(196,96)). We then
    overlay the 48-bit centre with the DATA SYNC pattern and apply
    the 20-bit Slot Type FEC (Golay(20,8)) to the four gap regions
    BPTC leaves unwritten. Without slot type, BrandMeister reads
    color_code=0 / data_type=0 and rejects the audio stream.

    For our outbound LC header we use BS Data SYNC to match what
    BrandMeister forwards (per captured reference 2026-06-15).
    """
    from services.dvswitch.slot_type import apply_slot_type  # noqa

    if len(bptc_payload_33) != 33:
        raise ValueError(f"BPTC payload must be 33 bytes, got "
                         f"{len(bptc_payload_33)}")
    out = bytearray(bptc_payload_33)
    _write_centre_48(out, sync_pattern)
    apply_slot_type(out, color_code=color_code, data_type=data_type)
    return bytes(out)


# ── self-test ─────────────────────────────────────────────────────
def _selftest() -> int:
    """Build a silence-payload voice burst with each centre type and
    verify the sync pattern appears at the right bit position."""
    silence = b"\x00" * 7

    # Burst A — should contain BS Voice SYNC at bits 108..155.
    burst_a = build_voice_burst_a(silence, silence, silence,
                                  sync_pattern=SYNC_BS_VOICE)
    if len(burst_a) != 33:
        print(f"FAIL: burst A wrong size: {len(burst_a)}")
        return 1
    # Decode centre and verify it matches.
    bits = _bits_from_bytes(burst_a)
    centre_bits = bits[108:156]
    centre_bytes = _bits_to_bytes(centre_bits)
    if centre_bytes != SYNC_BS_VOICE:
        print(f"FAIL: burst A centre is {centre_bytes.hex()}, "
              f"expected {SYNC_BS_VOICE.hex()}")
        return 1
    print(f"[PASS] burst A centre = BS Voice SYNC {centre_bytes.hex()}")

    # Burst B with null embedded — centre should be all zeros.
    burst_b = build_voice_burst_emb(silence, silence, silence)
    bits = _bits_from_bytes(burst_b)
    centre_bits = bits[108:156]
    centre_bytes = _bits_to_bytes(centre_bits)
    if centre_bytes != b"\x00" * 6:
        print(f"FAIL: null-EMB burst centre = {centre_bytes.hex()}, "
              f"expected all zeros")
        return 1
    print(f"[PASS] burst B+null embedded centre = all zeros")

    # Data burst (LC header style) — centre should be BS Data SYNC.
    data_payload = bytearray(33)
    data_burst = build_data_burst(bytes(data_payload),
                                  sync_pattern=SYNC_BS_DATA)
    bits = _bits_from_bytes(data_burst)
    centre_bits = bits[108:156]
    centre_bytes = _bits_to_bytes(centre_bits)
    if centre_bytes != SYNC_BS_DATA:
        print(f"FAIL: data burst centre = {centre_bytes.hex()}, "
              f"expected {SYNC_BS_DATA.hex()}")
        return 1
    print(f"[PASS] data burst centre = BS Data SYNC {centre_bytes.hex()}")

    print("\nOK — voice burst constructor structurally validates.")
    print("Each burst is 33 bytes; sync patterns land at bit 108..155.")
    return 0


if __name__ == "__main__":
    # Allow running directly without the package import path.
    import sys
    import os
    here = os.path.dirname(os.path.abspath(__file__))
    sys.path.insert(0, os.path.dirname(os.path.dirname(here)))
    raise SystemExit(_selftest())
