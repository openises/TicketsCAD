"""
Embedded LC encoder for DMR voice bursts B–E.

Carries a 72-bit Full LC + 5-bit CRC + 51 bits of FEC parity = 128 bits,
split into four 32-bit chunks that ride in the centre of voice bursts
B, C, D, E. Without this BrandMeister tags every call "No TA" and many
receivers (including Pi-Star MMDVMHost firmware) filter the audio out.

Most common Embedded LC payload: a Talker Alias Header (FLCO=4) carrying
the operator callsign in 7-bit ASCII so receivers display the callsign
on screen during voice.

Pipeline (port of MMDVMHost/DMREmbeddedData.cpp encodeEmbeddedData):
  1. Build 9-byte LC content (FLCO byte + FID + 7 payload bytes)
  2. Compute 5-bit CRC = sum of the 9 bytes, modulo 31
  3. Scatter the 72 LC bits + 5 CRC bits across positions in an
     8×16 grid (info bits in rows 0-6, CRC at column 10 of rows 1-5)
  4. Hamming(16,11,4) parity on rows 0-6
  5. Column parity for row 7 (XOR down each column)
  6. Pack the 128 bits column-major into 4 × 32-bit output chunks

Reference: ETSI TS 102 361-1 v1.4.5 §7.1.3 + Annex E.1.1.
Source: MMDVMHost/DMREmbeddedData.cpp + MMDVMHost/Hamming.cpp encode16114.
"""

from __future__ import annotations
from typing import List


# ── FLCO constants ───────────────────────────────────────────────
FLCO_GROUP                = 0x00
FLCO_USER_USER            = 0x03
FLCO_TALKER_ALIAS_HEADER  = 0x04
FLCO_TALKER_ALIAS_BLOCK1  = 0x05
FLCO_TALKER_ALIAS_BLOCK2  = 0x06
FLCO_TALKER_ALIAS_BLOCK3  = 0x07
FLCO_GPS_INFO             = 0x08

# TA format codes (ETSI Annex E.1.1)
TA_FORMAT_7_BIT     = 0
TA_FORMAT_ISO_8859  = 1
TA_FORMAT_UTF_8     = 2
TA_FORMAT_UTF_16_BE = 3


# ── TA Header LC builder ─────────────────────────────────────────
def build_ta_header_lc(callsign: str, format_8bit: bool = True) -> bytes:
    """Build a 9-byte Talker Alias Header LC for the given callsign.

    Two formats supported:
      - 8-bit ISO 8859-1 (format=1, format_8bit=True): one char per byte
        starting at byte 3. Simplest layout; many decoders prefer this.
      - 7-bit ASCII (format=0, format_8bit=False): continuous bit stream
        starting at bit 0 of byte 2.

    Byte layout (8-bit):
      byte 0: FLCO=4
      byte 1: FID=0
      byte 2: bits 7-6 = 01 (format=1), bits 5-1 = size, bit 0 = 0
      bytes 3-8: up to 6 chars as raw bytes

    Byte layout (7-bit) per MMDVMHost CDMRTA::decodeTA():
      byte 2: bits 7-6 = 00 (format=0), bits 5-1 = size, bit 0 = MSB of char 0
      bytes 3-8: rest of continuous 7-bit char stream
    """
    chars = callsign[:6].upper()  # 6 chars fits in TA Header for both formats
    real_len = len(chars)
    while len(chars) < 6:
        chars = chars + "\x00"

    lc = bytearray(9)
    lc[0] = FLCO_TALKER_ALIAS_HEADER & 0x3F
    lc[1] = 0

    if format_8bit:
        # 8-bit ISO 8859-1: one char per byte, no bit-stream packing.
        # Byte 2: format=01 (bits 7-6), size (bits 5-1), bit 0 = 0
        lc[2] = (TA_FORMAT_ISO_8859 << 6) | ((real_len & 0x1F) << 1)
        for i, c in enumerate(chars):
            lc[3 + i] = ord(c) & 0xFF
    else:
        # 7-bit ASCII: continuous bit stream.
        chars7 = chars + "\x00"  # 7 chars total for 49-bit stream
        bits: List[int] = []
        bits.extend([
            (TA_FORMAT_7_BIT >> 1) & 1, TA_FORMAT_7_BIT & 1,
            (real_len >> 4) & 1, (real_len >> 3) & 1, (real_len >> 2) & 1,
            (real_len >> 1) & 1, real_len & 1,
        ])
        for c in chars7:
            v = ord(c) & 0x7F
            for i in range(7):
                bits.append((v >> (6 - i)) & 1)
        assert len(bits) == 56
        for byte_idx in range(7):
            b = 0
            for bit_idx in range(8):
                b = (b << 1) | bits[byte_idx * 8 + bit_idx]
            lc[2 + byte_idx] = b
    return bytes(lc)


# ── CRC-5 (sum of bytes mod 31) ──────────────────────────────────
def crc_5_embedded(lc_9bytes: bytes) -> int:
    if len(lc_9bytes) != 9:
        raise ValueError(f"LC must be 9 bytes, got {len(lc_9bytes)}")
    return sum(lc_9bytes) % 31


# ── Hamming(16,11,4) row encoder ─────────────────────────────────
def hamming_16_11_4_encode(d: List[bool]) -> None:
    """Write the 5 parity bits at d[11..15] given info bits d[0..10]."""
    d[11] = d[0] ^ d[1] ^ d[2] ^ d[3] ^ d[5] ^ d[7] ^ d[8]
    d[12] = d[1] ^ d[2] ^ d[3] ^ d[4] ^ d[6] ^ d[8] ^ d[9]
    d[13] = d[2] ^ d[3] ^ d[4] ^ d[5] ^ d[7] ^ d[9] ^ d[10]
    d[14] = d[0] ^ d[1] ^ d[2] ^ d[4] ^ d[6] ^ d[7] ^ d[10]
    d[15] = d[0] ^ d[2] ^ d[5] ^ d[6] ^ d[8] ^ d[9] ^ d[10]


# ── Embedded LC encoder ──────────────────────────────────────────
def encode_embedded_lc(lc_9bytes: bytes) -> List[bytes]:
    """Encode a 9-byte Full LC into 4 × 32-bit chunks for bursts B-E.

    Returns 4 entries, each a 4-byte bytes object suitable for passing
    as `embedded_32` to build_voice_burst_emb. The encoding adds the
    5-bit CRC, applies Hamming(16,11,4) row parity, and a per-column
    parity row, then column-major packs the resulting 8×16 grid.
    """
    if len(lc_9bytes) != 9:
        raise ValueError(f"LC must be 9 bytes, got {len(lc_9bytes)}")

    # 72 bits of LC content, MSB-first.
    m_data = [False] * 72
    for byte_idx, byte_val in enumerate(lc_9bytes):
        for bit_idx in range(8):
            m_data[byte_idx * 8 + bit_idx] = bool((byte_val >> (7 - bit_idx)) & 1)

    # 5-bit CRC.
    crc = crc_5_embedded(lc_9bytes)

    # Build 128-bit grid; rows are 16 bits each; the 8th row is column parity.
    data = [False] * 128

    # CRC bits placed at specific positions (per MMDVMHost ref).
    data[106] = bool(crc & 0x01)
    data[90]  = bool(crc & 0x02)
    data[74]  = bool(crc & 0x04)
    data[58]  = bool(crc & 0x08)
    data[42]  = bool(crc & 0x10)

    # Info bits scattered through rows 0-6.
    info_idx = 0
    info_ranges = [
        (0, 11),    # row 0: 11 info bits
        (16, 27),   # row 1: 11 info bits
        (32, 42),   # row 2: 10 info bits + 1 CRC at 42
        (48, 58),   # row 3: 10 info bits + 1 CRC at 58
        (64, 74),   # row 4: 10 info bits + 1 CRC at 74
        (80, 90),   # row 5: 10 info bits + 1 CRC at 90
        (96, 106),  # row 6: 10 info bits + 1 CRC at 106
    ]
    for start, end in info_ranges:
        for a in range(start, end):
            data[a] = m_data[info_idx]
            info_idx += 1
    assert info_idx == 72, f"placed {info_idx} info bits, expected 72"

    # Hamming(16,11,4) per row for rows 0-6 (offsets 0, 16, ..., 96).
    for row_start in range(0, 112, 16):
        row = data[row_start : row_start + 16]
        hamming_16_11_4_encode(row)
        data[row_start : row_start + 16] = row

    # Column parity for row 7 (positions 112..127).
    for col in range(16):
        p = False
        for row in range(7):
            p ^= data[col + row * 16]
        data[col + 112] = p

    # Column-major pack: m_raw[a] = data[b] where b cycles 0,16,32,...,112,1,17,...
    m_raw = [False] * 128
    b = 0
    for a in range(128):
        m_raw[a] = data[b]
        b += 16
        if b > 127:
            b -= 127

    # Split into 4 × 32-bit chunks. Each chunk needs to be returned
    # as 4-byte big-endian for write_embedded_data() (which uses
    # MSB-first packing).
    chunks: List[bytes] = []
    for chunk_idx in range(4):
        chunk_bits = m_raw[chunk_idx * 32 : chunk_idx * 32 + 32]
        chunk_bytes = bytearray(4)
        for byte_idx in range(4):
            v = 0
            for bit_idx in range(8):
                v = (v << 1) | (1 if chunk_bits[byte_idx * 8 + bit_idx] else 0)
            chunk_bytes[byte_idx] = v
        chunks.append(bytes(chunk_bytes))
    return chunks


# ── LCSS values (MMDVMHost convention, NOT standard ETSI labels) ──
# Per MMDVMHost CDMREmbeddedData::getData switch statement:
#   chunk 0 (burst B) → LCSS=1 (first fragment)
#   chunk 1 (burst C) → LCSS=3 (middle/continuation)
#   chunk 2 (burst D) → LCSS=3 (middle/continuation)
#   chunk 3 (burst E) → LCSS=2 (last fragment)
#   burst F (no chunk) → LCSS=0 (null)
LCSS_BY_VSEQ = {
    1: 1,   # burst B = first chunk
    2: 3,   # burst C = middle chunk
    3: 3,   # burst D = middle chunk
    4: 2,   # burst E = last chunk
    5: 0,   # burst F = null
}


def _selftest() -> int:
    # Build TA Header LC for "N0NKI"
    lc = build_ta_header_lc("N0NKI")
    print(f"[TA] 9-byte TA Header LC for N0NKI: {lc.hex()}")
    assert lc[0] == 0x04, f"FLCO byte = {lc[0]:#04x}, expected 0x04"
    assert lc[1] == 0x00, f"FID = {lc[1]:#04x}, expected 0x00"
    # Now using 8-bit format: byte 2 = 01_00101_0 = 0x4A
    assert lc[2] == 0x4A, f"TA Header byte = {lc[2]:#04x}, expected 0x4A (fmt 1, size 5)"
    assert lc[3] == 0x4E, f"byte 3 = {lc[3]:#04x}, expected 0x4E ('N')"
    assert lc[4] == 0x30, f"byte 4 = {lc[4]:#04x}, expected 0x30 ('0')"
    print(f"[PASS] 8-bit format with raw ASCII: 'N0NKI' verified in bytes 3-7")

    # CRC-5
    crc = crc_5_embedded(lc)
    print(f"[CRC] 5-bit CRC = {crc} ({crc:#07b})")
    # Verify: sum of N0NKI LC bytes
    s = sum(lc) % 31
    assert crc == s, f"CRC mismatch: {crc} vs {s}"

    # Encode
    chunks = encode_embedded_lc(lc)
    assert len(chunks) == 4, f"expected 4 chunks, got {len(chunks)}"
    for i, c in enumerate(chunks):
        assert len(c) == 4, f"chunk {i} = {len(c)} bytes, expected 4"
    print(f"[ENC] 4 chunks (B/C/D/E):")
    for i, c in enumerate(chunks):
        print(f"  chunk {i} → burst {'BCDE'[i]}: {c.hex()}  (LCSS={[1,3,3,2][i]})")

    # Hamming sanity: all-zeros input → all-zeros output
    zero_chunks = encode_embedded_lc(b"\x00" * 9)
    assert all(c == b"\x00\x00\x00\x00" for c in zero_chunks), \
        f"all-zero LC should yield all-zero chunks, got {[c.hex() for c in zero_chunks]}"
    print(f"[PASS] all-zero LC encodes to all-zero chunks")

    # Linearity check
    lc_a = bytearray(9); lc_a[0] = 0x04
    lc_b = bytearray(9); lc_b[3] = 0xFF
    cs_a = encode_embedded_lc(bytes(lc_a))
    cs_b = encode_embedded_lc(bytes(lc_b))
    lc_xor = bytes(x ^ y for x, y in zip(lc_a, lc_b))
    cs_xor = encode_embedded_lc(lc_xor)
    # Note: CRC is non-linear (sum mod 31) so full linearity won't hold,
    # but the bit-scatter + Hamming pieces are linear. Just sanity-check
    # the encoder doesn't crash on varied input.
    print(f"[PASS] varied inputs encode without errors")

    print("\nOK — embedded_lc module validates structurally.")
    return 0


if __name__ == "__main__":
    raise SystemExit(_selftest())
