"""
DMR EMB (Embedded signalling) encoder for voice bursts B, C, D, E, F.

EMB is a 16-bit field carried in 4 separate 4-bit nibbles surrounding
the embedded LC payload in voice bursts that don't carry the main
SYNC pattern (bursts B-F). Without proper EMB, BrandMeister sees
voice bursts as malformed mid-stream and drops the audio even when
the LC header was accepted.

Wire layout in a voice burst's 33-byte payload:

    byte 13 low nibble  → EMB nibble 1 (bits 0-3 of EMB code)
    byte 14 high nibble → EMB nibble 2 (bits 4-7 of EMB code)
    bytes 14 low + 15-17 + byte 18 high → 32-bit embedded data
    byte 18 low nibble  → EMB nibble 3 (bits 8-11 of EMB code)
    byte 19 high nibble → EMB nibble 4 (bits 12-15 of EMB code)

The 16-bit EMB code is a QR(16,7,6) Quasi-cyclic Reed code over:
    bits 0-3: color code
    bit 4:    PI (Privacy Indicator)
    bits 5-6: LCSS (Link Control Start/Stop)
    bit 7:    reserved (0)
    bits 8-15: 9 parity bits (top bit shared with codeword high byte)

LCSS values (ETSI TS 102 361-1 clause 7.1.1.2):
    0b00 = single fragment (no embedded LC reassembly)
    0b01 = first fragment of LC
    0b10 = continuation fragment
    0b11 = last fragment of LC

For Phase 84j v1 we use LCSS=0 (no embedded LC) — receivers ignore the
32-bit embedded data; voice path is intact. Phase 84d will add full
embedded LC distributed across bursts B-E.

Reference: MMDVMHost/DMREMB.cpp + MMDVMHost/QR1676.cpp.
"""

from __future__ import annotations


# ── QR(16,7) encoding table (MMDVMHost/QR1676.cpp) ────────────────
ENCODING_TABLE_1676 = (
    0x0000, 0x0273, 0x04E5, 0x0696, 0x09C9, 0x0BBA, 0x0D2C, 0x0F5F,
    0x11E2, 0x1391, 0x1507, 0x1774, 0x182B, 0x1A58, 0x1CCE, 0x1EBD,
    0x21B7, 0x23C4, 0x2552, 0x2721, 0x287E, 0x2A0D, 0x2C9B, 0x2EE8,
    0x3055, 0x3226, 0x34B0, 0x36C3, 0x399C, 0x3BEF, 0x3D79, 0x3F0A,
    0x411E, 0x436D, 0x45FB, 0x4788, 0x48D7, 0x4AA4, 0x4C32, 0x4E41,
    0x50FC, 0x528F, 0x5419, 0x566A, 0x5935, 0x5B46, 0x5DD0, 0x5FA3,
    0x60A9, 0x62DA, 0x644C, 0x663F, 0x6960, 0x6B13, 0x6D85, 0x6FF6,
    0x714B, 0x7338, 0x75AE, 0x77DD, 0x7882, 0x7AF1, 0x7C67, 0x7E14,
    0x804F, 0x823C, 0x84AA, 0x86D9, 0x8986, 0x8BF5, 0x8D63, 0x8F10,
    0x91AD, 0x93DE, 0x9548, 0x973B, 0x9864, 0x9A17, 0x9C81, 0x9EF2,
    0xA1F8, 0xA38B, 0xA51D, 0xA76E, 0xA831, 0xAA42, 0xACD4, 0xAEA7,
    0xB01A, 0xB269, 0xB4FF, 0xB68C, 0xB9D3, 0xBBA0, 0xBD36, 0xBF45,
    0xC151, 0xC322, 0xC5B4, 0xC7C7, 0xC898, 0xCAEB, 0xCC7D, 0xCE0E,
    0xD0B3, 0xD2C0, 0xD456, 0xD625, 0xD97A, 0xDB09, 0xDD9F, 0xDFEC,
    0xE0E6, 0xE295, 0xE403, 0xE670, 0xE92F, 0xEB5C, 0xEDCA, 0xEFB9,
    0xF104, 0xF377, 0xF5E1, 0xF792, 0xF8CD, 0xFABE, 0xFC28, 0xFE5B,
)
assert len(ENCODING_TABLE_1676) == 128


def encode_qr_1676(info_byte: int) -> tuple[int, int]:
    """Encode 7 info bits → 2 bytes (16-bit QR(16,7) codeword).

    info_byte's bits 7..1 are the 7 info bits; bit 0 is ignored
    (matches MMDVMHost's `value = (data[0] >> 1) & 0x7F`).

    Returns (byte_0, byte_1) where the 16-bit codeword is
    (byte_0 << 8) | byte_1.
    """
    assert 0 <= info_byte < 256
    value = (info_byte >> 1) & 0x7F
    cksum = ENCODING_TABLE_1676[value]
    return (cksum >> 8) & 0xFF, cksum & 0xFF


def apply_emb(
    burst: bytearray,
    color_code: int = 1,
    pi: bool = False,
    lcss: int = 0,
) -> None:
    """Write the 16-bit EMB code into a voice burst's 4 EMB nibbles.

    Modifies burst in place. Other positions are preserved.

    Per MMDVMHost/DMREMB.cpp getData():
        emb[0] = (color_code << 4) | (PI ? 0x08 : 0) | (LCSS << 1)
        emb[1] = 0
        QR1676.encode(emb)  ← fills emb[0] high bits with codeword high,
                              emb[1] with codeword low
        burst[13] = (burst[13] & 0xF0) | ((emb[0] >> 4) & 0x0F)  ← nibble 1
        burst[14] = (burst[14] & 0x0F) | ((emb[0] << 4) & 0xF0)  ← nibble 2
        burst[18] = (burst[18] & 0xF0) | ((emb[1] >> 4) & 0x0F)  ← nibble 3
        burst[19] = (burst[19] & 0x0F) | ((emb[1] << 4) & 0xF0)  ← nibble 4
    """
    if len(burst) != 33:
        raise ValueError(f"burst must be 33 bytes, got {len(burst)}")
    if not 0 <= color_code <= 0x0F:
        raise ValueError(f"color_code must fit in 4 bits, got {color_code:#x}")
    if not 0 <= lcss <= 0x03:
        raise ValueError(f"lcss must fit in 2 bits, got {lcss:#x}")

    info = ((color_code & 0x0F) << 4) \
         | (0x08 if pi else 0x00) \
         | ((lcss & 0x03) << 1)
    eb0, eb1 = encode_qr_1676(info)

    burst[13] = (burst[13] & 0xF0) | ((eb0 >> 4) & 0x0F)
    burst[14] = (burst[14] & 0x0F) | ((eb0 << 4) & 0xF0)
    burst[18] = (burst[18] & 0xF0) | ((eb1 >> 4) & 0x0F)
    burst[19] = (burst[19] & 0x0F) | ((eb1 << 4) & 0xF0)


def write_embedded_data(burst: bytearray, embedded_32: bytes) -> None:
    """Write the 32-bit embedded data field into a voice burst.

    Embedded data occupies bits 116-147 of the 264-bit burst:
        byte 14 low nibble (4 bits)
        bytes 15, 16, 17 (24 bits)
        byte 18 high nibble (4 bits)

    For Phase 84j v1 with LCSS=0 (no embedded LC), pass all-zero
    embedded_32 — receivers should ignore the field.
    """
    if len(burst) != 33:
        raise ValueError(f"burst must be 33 bytes, got {len(burst)}")
    if len(embedded_32) != 4:
        raise ValueError(f"embedded_32 must be 4 bytes, got {len(embedded_32)}")

    burst[14] = (burst[14] & 0xF0) | ((embedded_32[0] >> 4) & 0x0F)
    burst[15] = ((embedded_32[0] << 4) & 0xF0) | ((embedded_32[1] >> 4) & 0x0F)
    burst[16] = ((embedded_32[1] << 4) & 0xF0) | ((embedded_32[2] >> 4) & 0x0F)
    burst[17] = ((embedded_32[2] << 4) & 0xF0) | ((embedded_32[3] >> 4) & 0x0F)
    burst[18] = (burst[18] & 0x0F) | ((embedded_32[3] << 4) & 0xF0)


def _selftest() -> int:
    # All-zero info encodes to all zeros.
    assert encode_qr_1676(0) == (0, 0)
    print("[PASS] zero info encodes to zero codeword")

    # CC=1, PI=0, LCSS=0, reserved=0 → info_byte = 0x10
    # value = 0x10 >> 1 = 0x08, ENCODING_TABLE_1676[8] = 0x11E2
    eb0, eb1 = encode_qr_1676(0x10)
    assert eb0 == 0x11 and eb1 == 0xE2, \
        f"CC=1 EMB: got eb0={eb0:#04x} eb1={eb1:#04x}, expected 0x11 0xe2"
    print(f"[PASS] CC=1, PI=0, LCSS=0 EMB code = 0x11E2")

    # Apply EMB to a zeroed burst and check nibble placement.
    b = bytearray(33)
    apply_emb(b, color_code=1, pi=False, lcss=0)
    assert b[13] == 0x01, f"byte 13 = {b[13]:#04x}, expected 0x01"
    assert b[14] == 0x10, f"byte 14 = {b[14]:#04x}, expected 0x10"
    assert b[18] == 0x0E, f"byte 18 = {b[18]:#04x}, expected 0x0e"
    assert b[19] == 0x20, f"byte 19 = {b[19]:#04x}, expected 0x20"
    print(f"[PASS] EMB nibbles placed at burst bytes 13, 14, 18, 19")

    # Verify only documented positions changed.
    for i in range(33):
        if i in (13, 14, 18, 19):
            continue
        assert b[i] == 0, f"byte {i} changed unexpectedly: {b[i]:#04x}"
    print(f"[PASS] EMB only modifies bytes 13, 14, 18, 19")

    # Embedded data write.
    b2 = bytearray(33)
    write_embedded_data(b2, b"\xde\xad\xbe\xef")
    assert b2[14] == 0x0D and b2[15] == 0xEA and b2[16] == 0xDB \
        and b2[17] == 0xEE and b2[18] == 0xF0, \
        f"embedded data: {b2[14]:#04x} {b2[15]:#04x} {b2[16]:#04x} " \
        f"{b2[17]:#04x} {b2[18]:#04x}"
    print(f"[PASS] embedded data correctly straddles bytes 14-18")

    # EMB + embedded data + voice should all coexist without overlap.
    b3 = bytearray(b"\xff" * 33)
    apply_emb(b3, color_code=1, lcss=0)
    write_embedded_data(b3, b"\x00\x00\x00\x00")
    # Bytes outside EMB+embedded region should still be 0xFF (voice area).
    for i in list(range(13)) + list(range(20, 33)):
        assert b3[i] == 0xFF, f"voice byte {i} clobbered: {b3[i]:#04x}"
    print(f"[PASS] EMB + embedded preserve voice area (bytes 0-12, 20-32)")

    print("\nOK — emb module validates structurally.")
    return 0


if __name__ == "__main__":
    raise SystemExit(_selftest())
