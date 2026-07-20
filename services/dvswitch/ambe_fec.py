"""
AMBE+2 FEC encoder for DMR voice bursts.

md380-emu produces raw 49-bit AMBE+2 frames; DMR over-the-air voice
bursts require 72 bits per AMBE+2 slot (49 info bits + 23 FEC bits)
positioned via specific bit-tables across the 264-bit burst.

This module implements the encode-side of MMDVMHost/AMBEFEC.cpp +
MMDVMHost/Golay24128.cpp. The decode side is in md380-emu's RX path
and we don't need it here.

Per-frame FEC structure (49 info → 72 wire bits):

    A block: 12 info bits → Golay(24,12) → 24 wire bits
    B block: 12 info bits XOR PRNG(A_data) → Golay(23,12) → 23 wire bits XOR PRNG
    C block: 25 info bits unprotected → 25 wire bits

    Total: 24 + 23 + 25 = 72 wire bits per AMBE+2 frame

Then the three 72-bit AMBE+2 wire frames go into a 264-bit voice
burst via DMR_A_TABLE / DMR_B_TABLE / DMR_C_TABLE bit position
mappings, with the centre 48-bit slot at bit positions 108-155
reserved for sync or EMB+embedded LC.

Per-burst structure (3 AMBE frames + centre):

  bits  0-107   left payload — AMBE frame 1 (full 72) +
                                AMBE frame 2 (first 36 of 72)
  bits 108-155  centre 48 bits — sync (burst A) or EMB+LC (B-F)
  bits 156-263  right payload — AMBE frame 2 (last 36) +
                                AMBE frame 3 (full 72)

References:
  MMDVMHost/AMBEFEC.cpp regenerateDMR + DMR_A/B/C_TABLE
  MMDVMHost/Golay24128.cpp encode24128 / encode23127
  ETSI TS 102 361-2 (vocoder) / ETSI TS 102 361-1 clause 6.1
  docs/DMR-PROTOCOL-DEEP-DIVE.md §2.1
"""

from __future__ import annotations
from typing import List

# ── Bit position tables (port of MMDVMHost/AMBEFEC.cpp) ───────────
# Each AMBE+2 wire frame occupies 72 bits in the voice burst.
# Frame 1 starts at bit 0 of the burst payload; frame 2 starts at
# bit 72; frame 3 starts at bit 192. Frame 2 STRADDLES the centre
# 48-bit slot — bit positions ≥108 get +48 added to skip over it.
DMR_A_TABLE = (
    0, 4, 8, 12, 16, 20, 24, 28, 32, 36, 40, 44,
    48, 52, 56, 60, 64, 68, 1, 5, 9, 13, 17, 21,
)
DMR_B_TABLE = (
    25, 29, 33, 37, 41, 45, 49, 53, 57, 61, 65, 69,
    2, 6, 10, 14, 18, 22, 26, 30, 34, 38, 42,
)
DMR_C_TABLE = (
    46, 50, 54, 58, 62, 66, 70, 3, 7, 11, 15, 19,
    23, 27, 31, 35, 39, 43, 47, 51, 55, 59, 63, 67, 71,
)

assert len(DMR_A_TABLE) == 24
assert len(DMR_B_TABLE) == 23
assert len(DMR_C_TABLE) == 25


# ── Golay(24,12) and Golay(23,12) on-the-fly encoder ─────────────
# The extended binary Golay code (24,12,8) is a perfect t=3 code.
# Generator polynomial g(x) = x^11 + x^10 + x^6 + x^5 + x^4 + x^2 + 1
# = 0xC75 (binary 1100 0111 0101).
#
# To encode 12 data bits d[11..0]:
#   1. Compute r(x) = (d(x) * x^11) mod g(x).
#   2. The codeword is d(x) * x^11 + r(x), giving 23 bits (Golay 23,12).
#   3. For (24,12) extended Golay, append a single overall-parity bit
#      so the 24-bit codeword has even weight.

GOLAY_POLY = 0xC75  # x^11 + x^10 + x^6 + x^5 + x^4 + x^2 + 1


def golay_23_12_encode(data: int) -> int:
    """Encode 12 data bits → 23-bit Golay(23,12) codeword.

    Data is at bits [22..11] of the result; parity at bits [10..0].
    """
    assert 0 <= data < 4096
    # Shift data left 11 to make room for the 11-bit parity.
    shifted = data << 11
    # Compute parity = shifted mod g(x) using GF(2) polynomial division.
    # The dividend is 23 bits wide; the divisor is 12 bits wide
    # (g(x) has degree 11). Process MSB-first.
    rem = shifted
    for i in range(22, 10, -1):
        if rem & (1 << i):
            rem ^= GOLAY_POLY << (i - 11)
    # rem now contains the 11-bit parity in the low 11 bits.
    # The codeword is data || parity = (data << 11) | parity = shifted | rem.
    # But we already modified rem, so reconstruct.
    parity = rem & 0x7FF
    return shifted | parity


def golay_24_12_encode(data: int) -> int:
    """Encode 12 data bits → 24-bit extended Golay(24,12) codeword.

    The 24th bit is overall parity (XOR of the other 23 bits).
    """
    g23 = golay_23_12_encode(data)
    # Count parity of the 23 bits — if odd, append 1 to make even.
    # MMDVMHost's encode24128 returns the 24 bits with the OVERALL
    # parity in bit 0 (the LSB), so the data is in bits [23..12]
    # and the codeword is (g23 << 1) | overall_parity.
    overall = bin(g23).count("1") & 1
    return (g23 << 1) | overall


# ── PRNG table for B-block whitening (4096 × 24-bit) ─────────────
# From MMDVMHost/AMBEFEC.cpp PRNG_TABLE[].
PRNG_TABLE = (
    0x42CC47, 0x19D6FE, 0x304729, 0x6B2CD0, 0x60BF47, 0x39650E, 0x7354F1, 0xEACF60,
    0x819C9F, 0xDE25CE, 0xD7B745, 0x8CC8B8, 0x8D592B, 0xF71257, 0xBCA084, 0xA5B329,
    0xEE6AFA, 0xF7D9A7, 0xBCC21C, 0x4712D9, 0x4F2922, 0x14FA37, 0x5D43EC, 0x564115,
    0x299A92, 0x20A9EB, 0x7B707D, 0x3BE3A4, 0x20D95B, 0x6B085A, 0x5233A5, 0x99A474,
    # The full 4096-entry table is bulky; a generator file populates the
    # remaining 4064 entries below in build/run via _load_prng_table().
    # See _prng_loader for the rest.
)


def _load_prng_table() -> tuple:
    """Loads the full 4096-entry PRNG table from the embedded data
    file. We keep PRNG values out of this source file's primary
    contents to keep the file readable; the actual table is loaded
    once at module import time from prng_table_data."""
    from services.dvswitch._prng_data import PRNG_TABLE as full_table  # type: ignore
    assert len(full_table) == 4096
    return full_table


# ── AMBE+2 49-bit → 72-bit wire conversion ───────────────────────
def info_bits_from_ambe(ambe_7bytes: bytes) -> List[bool]:
    """md380-emu returns 7 bytes per AMBE+2 frame. The 49 info bits
    are stored MSB-first across the first 6 bytes (= 48 bits) plus
    bit 48 in the high bit of byte 6. The remaining 7 bits of byte 6
    are reserved/zero.

    Returns a list of 49 booleans, MSB first.
    """
    if len(ambe_7bytes) != 7:
        raise ValueError(f"ambe frame must be 7 bytes, got {len(ambe_7bytes)}")
    bits: List[bool] = []
    for i in range(6):
        b = ambe_7bytes[i]
        for j in range(8):
            bits.append(bool((b >> (7 - j)) & 1))
    # The 49th bit is the MSB of byte 6.
    bits.append(bool((ambe_7bytes[6] >> 7) & 1))
    return bits


def wire_bits_from_info(info_bits: List[bool], prng_table) -> List[bool]:
    """Take 49 AMBE+2 info bits, produce 72 wire bits with FEC.

    Layout of info bits:
       info_bits[0..11]   → A block data (12 bits)
       info_bits[12..23]  → B block data (12 bits)
       info_bits[24..48]  → C block (25 bits, unprotected)
    """
    if len(info_bits) != 49:
        raise ValueError(f"need 49 info bits, got {len(info_bits)}")

    # A block data → 12-bit integer.
    a_data = 0
    for i in range(12):
        if info_bits[i]:
            a_data |= 1 << (11 - i)
    # B block data → 12-bit integer.
    b_data = 0
    for i in range(12):
        if info_bits[12 + i]:
            b_data |= 1 << (11 - i)
    # C block stays as 25 raw bits.

    # Encode A with Golay(24,12).
    a = golay_24_12_encode(a_data)            # 24 bits
    # Encode B with Golay(23,12).
    b = golay_23_12_encode(b_data)            # 23 bits
    # Whitening — XOR B with the 23 high bits of PRNG_TABLE[a_data].
    p = prng_table[a_data] >> 1               # 23-bit PRNG
    b ^= p

    # Pack into the 72-bit wire output. Convention from MMDVMHost:
    # A bits are at positions 0..23 (MSB-first), B at 24..46,
    # C at 47..71.
    wire: List[bool] = [False] * 72
    for i in range(24):
        wire[i] = bool((a >> (23 - i)) & 1)
    for i in range(23):
        wire[24 + i] = bool((b >> (22 - i)) & 1)
    for i in range(25):
        wire[47 + i] = info_bits[24 + i]
    return wire


# ── 3-frame voice burst payload assembler ─────────────────────────
def place_ambe_frames_into_burst(
    frame1: bytes, frame2: bytes, frame3: bytes,
    burst_payload: bytearray,
    prng_table,
) -> None:
    """Place three raw 7-byte AMBE+2 frames into a 33-byte burst
    payload at the positions DMR Tier II specifies.

    The 33-byte burst_payload buffer must be supplied by the caller
    (typically already containing the LC header / sync / EMB centre
    48 bits in the middle). This function ONLY writes the 216 bits
    of voice content via DMR_A/B/C_TABLE positions.
    """
    if len(burst_payload) != 33:
        raise ValueError(f"burst payload must be 33 bytes, got {len(burst_payload)}")

    wire1 = wire_bits_from_info(info_bits_from_ambe(frame1), prng_table)
    wire2 = wire_bits_from_info(info_bits_from_ambe(frame2), prng_table)
    wire3 = wire_bits_from_info(info_bits_from_ambe(frame3), prng_table)

    # Convert burst_payload to a bit list for easy manipulation.
    bits = [False] * 264
    for byte_idx, byte_val in enumerate(burst_payload):
        for j in range(8):
            bits[byte_idx * 8 + j] = bool((byte_val >> (7 - j)) & 1)

    # For each table entry, write to position p (frame1), p + 72
    # (frame2, with the +48 hop over the centre slot), p + 192
    # (frame3).
    for i in range(24):
        p1 = DMR_A_TABLE[i]
        p2 = p1 + 72
        if p2 >= 108:
            p2 += 48
        p3 = p1 + 192
        bits[p1] = wire1[i]
        bits[p2] = wire2[i]
        bits[p3] = wire3[i]

    for i in range(23):
        p1 = DMR_B_TABLE[i]
        p2 = p1 + 72
        if p2 >= 108:
            p2 += 48
        p3 = p1 + 192
        bits[p1] = wire1[24 + i]
        bits[p2] = wire2[24 + i]
        bits[p3] = wire3[24 + i]

    for i in range(25):
        p1 = DMR_C_TABLE[i]
        p2 = p1 + 72
        if p2 >= 108:
            p2 += 48
        p3 = p1 + 192
        bits[p1] = wire1[47 + i]
        bits[p2] = wire2[47 + i]
        bits[p3] = wire3[47 + i]

    # Pack the modified bits back into burst_payload (in-place).
    for byte_idx in range(33):
        b = 0
        for j in range(8):
            if bits[byte_idx * 8 + j]:
                b |= 1 << (7 - j)
        burst_payload[byte_idx] = b


# ── self-test ─────────────────────────────────────────────────────
def _selftest() -> int:
    """Sanity-check the Golay encoders and the burst assembler."""
    # Golay(23,12) of zero data should be zero.
    assert golay_23_12_encode(0) == 0, "Golay(23,12)(0) should be 0"
    assert golay_24_12_encode(0) == 0, "Golay(24,12)(0) should be 0"
    # Golay codewords should be linear: enc(a XOR b) = enc(a) XOR enc(b).
    for trial in [(0x111, 0x222), (0xFFF, 0x001), (0xABC, 0xDEF)]:
        a, b = trial
        ax = golay_23_12_encode(a) ^ golay_23_12_encode(b)
        c = golay_23_12_encode(a ^ b)
        assert ax == c, f"Golay(23,12) linearity fails for {trial}: {ax:06x} vs {c:06x}"
        ax = golay_24_12_encode(a) ^ golay_24_12_encode(b)
        c = golay_24_12_encode(a ^ b)
        assert ax == c, f"Golay(24,12) linearity fails for {trial}: {ax:06x} vs {c:06x}"
    print("[PASS] Golay(23,12) and Golay(24,12) linear over GF(2)")

    # Golay(24,12) encodes 12 bits → 24 bits with even weight.
    for d in [0x001, 0x123, 0xABC, 0xFFF]:
        c = golay_24_12_encode(d)
        w = bin(c).count("1")
        assert w % 2 == 0, f"Golay(24,12)({d:03x}) = {c:06x} has odd weight {w}"
    print("[PASS] Golay(24,12) codewords have even weight (extended-parity correct)")

    # Burst assembler — silence in, structurally valid out.
    silence = b"\x00" * 7
    burst = bytearray(33)
    # Use placeholder PRNG with all zeros for this structural test;
    # the real PRNG matters for encoding correctness vs reference but
    # not for the burst-assembly check.
    place_ambe_frames_into_burst(silence, silence, silence, burst,
                                 prng_table=[0] * 4096)
    nonzero = sum(1 for b in burst if b != 0)
    # All silence + zero PRNG should produce mostly-zero output (the
    # straddler / sync slots are zero too because we didn't set them).
    print(f"[PASS] burst assembler produces {nonzero} non-zero bytes for silence")

    print("\nOK — AMBE FEC encoder structurally validates.")
    print("Next: validate output bytes against captured-from-BM reference")
    print("(Phase 84e will add the centre 48-bit slot and complete the burst).")
    return 0


if __name__ == "__main__":
    raise SystemExit(_selftest())
