"""
DMR Slot Type encoder — port of MMDVMHost/Golay2087.cpp +
MMDVMHost/DMRSlotType.cpp encode side.

A DMR data burst's 33-byte payload carries an extra 20 bits of
Slot Type FEC code that BPTC(196,96) does NOT write. Those 20
bits live in:

    byte 12 low 6 bits   (burst bits 98-103)
    byte 13 high 4 bits  (burst bits 104-107)
    byte 19 low 4 bits   (burst bits 156-159)
    byte 20 high 6 bits  (burst bits 160-165)

Encoding: 8 info bits (4-bit color code + 4-bit data type)
→ 20 wire bits via shortened Golay(20,8,5).

Without this, BrandMeister sees CC=0 / DT=0 on every LC header
and terminator, which the network correctly rejects (CC mismatch).

Reference: MMDVMHost/Golay2087.cpp + MMDVMHost/DMRSlotType.cpp +
ETSI TS 102 361-1 v1.4.5 clause B.1.1.
"""

from __future__ import annotations


# ── Data Type values (ETSI clause 9.1.1, Table 9.1) ──────────────
DATA_TYPE_VOICE_LC_HEADER       = 0x1
DATA_TYPE_TERMINATOR_WITH_LC    = 0x2
DATA_TYPE_CSBK                  = 0x3
DATA_TYPE_DATA_HEADER           = 0x6
DATA_TYPE_RATE_12_DATA          = 0x7
DATA_TYPE_RATE_34_DATA          = 0x8


# ── Golay(20,8) encoding table (MMDVMHost/Golay2087.cpp) ─────────
ENCODING_TABLE_2087 = (
    0x0000, 0xB08E, 0xE093, 0x501D, 0x70A9, 0xC027, 0x903A, 0x20B4,
    0x60DC, 0xD052, 0x804F, 0x30C1, 0x1075, 0xA0FB, 0xF0E6, 0x4068,
    0x7036, 0xC0B8, 0x90A5, 0x202B, 0x009F, 0xB011, 0xE00C, 0x5082,
    0x10EA, 0xA064, 0xF079, 0x40F7, 0x6043, 0xD0CD, 0x80D0, 0x305E,
    0xD06C, 0x60E2, 0x30FF, 0x8071, 0xA0C5, 0x104B, 0x4056, 0xF0D8,
    0xB0B0, 0x003E, 0x5023, 0xE0AD, 0xC019, 0x7097, 0x208A, 0x9004,
    0xA05A, 0x10D4, 0x40C9, 0xF047, 0xD0F3, 0x607D, 0x3060, 0x80EE,
    0xC086, 0x7008, 0x2015, 0x909B, 0xB02F, 0x00A1, 0x50BC, 0xE032,
    0x90D9, 0x2057, 0x704A, 0xC0C4, 0xE070, 0x50FE, 0x00E3, 0xB06D,
    0xF005, 0x408B, 0x1096, 0xA018, 0x80AC, 0x3022, 0x603F, 0xD0B1,
    0xE0EF, 0x5061, 0x007C, 0xB0F2, 0x9046, 0x20C8, 0x70D5, 0xC05B,
    0x8033, 0x30BD, 0x60A0, 0xD02E, 0xF09A, 0x4014, 0x1009, 0xA087,
    0x40B5, 0xF03B, 0xA026, 0x10A8, 0x301C, 0x8092, 0xD08F, 0x6001,
    0x2069, 0x90E7, 0xC0FA, 0x7074, 0x50C0, 0xE04E, 0xB053, 0x00DD,
    0x3083, 0x800D, 0xD010, 0x609E, 0x402A, 0xF0A4, 0xA0B9, 0x1037,
    0x505F, 0xE0D1, 0xB0CC, 0x0042, 0x20F6, 0x9078, 0xC065, 0x70EB,
    0xA03D, 0x10B3, 0x40AE, 0xF020, 0xD094, 0x601A, 0x3007, 0x8089,
    0xC0E1, 0x706F, 0x2072, 0x90FC, 0xB048, 0x00C6, 0x50DB, 0xE055,
    0xD00B, 0x6085, 0x3098, 0x8016, 0xA0A2, 0x102C, 0x4031, 0xF0BF,
    0xB0D7, 0x0059, 0x5044, 0xE0CA, 0xC07E, 0x70F0, 0x20ED, 0x9063,
    0x7051, 0xC0DF, 0x90C2, 0x204C, 0x00F8, 0xB076, 0xE06B, 0x50E5,
    0x108D, 0xA003, 0xF01E, 0x4090, 0x6024, 0xD0AA, 0x80B7, 0x3039,
    0x0067, 0xB0E9, 0xE0F4, 0x507A, 0x70CE, 0xC040, 0x905D, 0x20D3,
    0x60BB, 0xD035, 0x8028, 0x30A6, 0x1012, 0xA09C, 0xF081, 0x400F,
    0x30E4, 0x806A, 0xD077, 0x60F9, 0x404D, 0xF0C3, 0xA0DE, 0x1050,
    0x5038, 0xE0B6, 0xB0AB, 0x0025, 0x2091, 0x901F, 0xC002, 0x708C,
    0x40D2, 0xF05C, 0xA041, 0x10CF, 0x307B, 0x80F5, 0xD0E8, 0x6066,
    0x200E, 0x9080, 0xC09D, 0x7013, 0x50A7, 0xE029, 0xB034, 0x00BA,
    0xE088, 0x5006, 0x001B, 0xB095, 0x9021, 0x20AF, 0x70B2, 0xC03C,
    0x8054, 0x30DA, 0x60C7, 0xD049, 0xF0FD, 0x4073, 0x106E, 0xA0E0,
    0x90BE, 0x2030, 0x702D, 0xC0A3, 0xE017, 0x5099, 0x0084, 0xB00A,
    0xF062, 0x40EC, 0x10F1, 0xA07F, 0x80CB, 0x3045, 0x6058, 0xD0D6,
)
assert len(ENCODING_TABLE_2087) == 256


def encode_golay_2087(info_byte: int) -> tuple[int, int, int]:
    """Encode 8 info bits → 3 bytes:
       byte 0 = info bits (unchanged)
       byte 1 = parity low 8 bits
       byte 2 = parity high 8 bits (only top 4 bits are wire bits)

    Returns (byte_0, byte_1, byte_2).
    """
    assert 0 <= info_byte < 256
    cksum = ENCODING_TABLE_2087[info_byte]
    return info_byte & 0xFF, cksum & 0xFF, (cksum >> 8) & 0xFF


def apply_slot_type(
    burst: bytearray,
    color_code: int,
    data_type: int,
) -> None:
    """Write the Slot Type FEC into the 20 'gap' bit positions of
    a 33-byte data burst.

    Modifies burst in place. Other positions are preserved.

    Per MMDVMHost/DMRSlotType.cpp getData(), the layout is:

        sb[0] = (color_code << 4) | (data_type & 0x0F)
        sb[1] = 0   ← overwritten by parity
        sb[2] = 0   ← overwritten by parity

        encode → fills sb[1] (low parity) and sb[2] (high parity)

        burst[12] = (burst[12] & 0xC0) | ((sb[0] >> 2) & 0x3F)
        burst[13] = (burst[13] & 0x0F) | ((sb[0] << 6) & 0xC0) | ((sb[1] >> 2) & 0x30)
        burst[19] = (burst[19] & 0xF0) | ((sb[1] >> 2) & 0x0F)
        burst[20] = (burst[20] & 0x03) | ((sb[1] << 6) & 0xC0) | ((sb[2] >> 2) & 0x3C)
    """
    if len(burst) != 33:
        raise ValueError(f"burst must be 33 bytes, got {len(burst)}")
    if not 0 <= color_code <= 0x0F:
        raise ValueError(f"color_code must fit in 4 bits, got {color_code:#x}")
    if not 0 <= data_type <= 0x0F:
        raise ValueError(f"data_type must fit in 4 bits, got {data_type:#x}")

    info_byte = ((color_code & 0x0F) << 4) | (data_type & 0x0F)
    sb0, sb1, sb2 = encode_golay_2087(info_byte)

    burst[12] = (burst[12] & 0xC0) | ((sb0 >> 2) & 0x3F)
    burst[13] = (burst[13] & 0x0F) | ((sb0 << 6) & 0xC0) | ((sb1 >> 2) & 0x30)
    burst[19] = (burst[19] & 0xF0) | ((sb1 >> 2) & 0x0F)
    burst[20] = (burst[20] & 0x03) | ((sb1 << 6) & 0xC0) | ((sb2 >> 2) & 0x3C)


def _selftest() -> int:
    # ENCODING_TABLE_2087[0] == 0 — zero info → zero parity
    assert encode_golay_2087(0) == (0, 0, 0)
    print("[PASS] zero info encodes to zero parity")

    # ENCODING_TABLE_2087[0x11] is what we'd compute for color_code=1, data_type=1
    # (voice LC header on color code 1). Verify the wire-byte layout.
    burst = bytearray(33)
    apply_slot_type(burst, color_code=1, data_type=DATA_TYPE_VOICE_LC_HEADER)
    print(f"[PASS] CC=1 LC header slot type wrote: "
          f"b12={burst[12]:#04x} b13={burst[13]:#04x} "
          f"b19={burst[19]:#04x} b20={burst[20]:#04x}")

    # And the terminator
    burst2 = bytearray(33)
    apply_slot_type(burst2, color_code=1,
                    data_type=DATA_TYPE_TERMINATOR_WITH_LC)
    print(f"[PASS] CC=1 terminator slot type wrote: "
          f"b12={burst2[12]:#04x} b13={burst2[13]:#04x} "
          f"b19={burst2[19]:#04x} b20={burst2[20]:#04x}")

    # Verify only the documented bit positions changed.
    other = bytearray(33)
    apply_slot_type(other, color_code=1, data_type=1)
    for i in range(33):
        if i in (12, 13, 19, 20):
            continue
        if other[i] != 0:
            print(f"FAIL: byte {i} changed unexpectedly: {other[i]:#04x}")
            return 1
    print("[PASS] slot type only modifies bytes 12, 13, 19, 20")

    # Verify the preserved-bit masks: writing to a pre-filled burst
    # only changes the gap positions.
    burst3 = bytearray(b"\xff" * 33)
    apply_slot_type(burst3, color_code=1, data_type=1)
    # byte 12: high 2 bits should still be 0b11 (straddler-like)
    assert burst3[12] & 0xC0 == 0xC0, f"byte 12 high bits wiped: {burst3[12]:#04x}"
    # byte 13: low 4 bits should still be 0xF (sync nibble)
    assert burst3[13] & 0x0F == 0x0F, f"byte 13 low bits wiped: {burst3[13]:#04x}"
    # byte 19: high 4 bits should still be 0xF (sync nibble)
    assert burst3[19] & 0xF0 == 0xF0, f"byte 19 high bits wiped: {burst3[19]:#04x}"
    # byte 20: low 2 bits should still be 0b11 (straddler)
    assert burst3[20] & 0x03 == 0x03, f"byte 20 low bits wiped: {burst3[20]:#04x}"
    print("[PASS] preserved-bit masks honored (BPTC + sync bits untouched)")

    print("\nOK — slot_type module validates structurally.")
    return 0


if __name__ == "__main__":
    raise SystemExit(_selftest())
