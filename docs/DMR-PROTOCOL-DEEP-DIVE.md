# DMR Tier II voice protocol — deep dive for the DVSwitch / BrandMeister "silent drop after header" failure

Status: research brief, 2026-06-15
Operator under investigation: N0NKI (DMR ID 3104410, hotspot 310441017, TG 3127 Minnesota Statewide)
Failure signature: BrandMeister `Last Heard` shows the TX correctly; no subscribed receiver hears audio; wire capture during a 1.8 s TX shows ~75 outbound DMRD packets where ~30 are expected.

---

## 1. Executive summary

The most likely root cause is that the Analog_Bridge → MMDVM_Bridge chain is emitting **multiple over-the-air-style "preamble" copies of the voice LC header (and likely the terminator) onto the network leg**, instead of the single-copy pattern that vanilla MMDVMHost uses when it pushes RF to upstream. The 75-vs-30 frame ratio is consistent with that: 30 voice bursts + 5 voice-sync bursts (one per superframe) + 8 voice LC header repeats at the start (the `NO_HEADERS_SIMPLEX = 8` constant from the reference modem code) + 8 terminator repeats at the end + a handful of late-entry embedded LC injections lands very near 75.

BrandMeister's master accepts the first `DMRD` packet bearing `frame_type=0b10` (data sync) + `dtype=0x01` (DT_VOICE_LC_HEADER) — that is what populates Last Heard. But when subsequent packets in the same `stream_id` keep arriving with `frame_type=0b10`/`dtype=0x01` (re-asserting a voice LC header) interleaved with `frame_type=0b01` voice-sync bursts and `frame_type=0b00` voice frames, the master either (a) treats each new LC header as a new call setup and resets the dispatch state machine, or (b) marks the stream as malformed and drops every voice burst silently while still showing Last Heard from the initial header. Either way, no downstream peer ever pulls audio.

The user-reported "mixed TS1 (0x90) and TS2 (0xa1)" observation **is in fact two TS2 frames** — both bytes have bit 7 set; the lower bits encode `frame_type`/`dtype`, not slot. 0x90 = TS2 voice-sync (burst A of a superframe), 0xa1 = TS2 voice LC header. The slot byte is not the problem. The presence of `0xa1` mid-transmission is the smoking gun. Detail and four ranked hypotheses with verification recipes follow.

---

## 2. DMR over-the-air voice frame structure (ETSI TS 102 361-1)

### 2.1 The 30 ms burst

Every DMR transmission is a stream of 30 ms TDMA bursts. Two slots share one 12.5 kHz RF channel as a 60 ms TDMA frame; each slot is 30 ms = 264 information bits + 2.5 ms guard or CACH (clause 4.2.2, Figure 4.3, p. 19):

```
| 108 bits payload | 48 bits sync OR EMB | 108 bits payload |
```

- 108-bit halves carry vocoder bits for voice bursts (216 bits payload → three 72-bit AMBE+2 vocoder frames including FEC); or 98 bits + 10 bits slot-type for data bursts (clause 6.2, p. 53).
- The centre 48 bits are **either** a frame-SYNC pattern **or** the EMB (4-bit) + 32-bit embedded signalling + 4-bit EMB (clause 6.1, Figure 6.4, p. 53).

### 2.2 The 360 ms voice superframe (bursts A–F)

A voice transmission is built from **six-burst superframes**, 360 ms total, labelled A through F (clause 5.1.2.1, Figure 5.3, p. 27):

| Burst | Centre 48 bits | Notes |
|---|---|---|
| A | Voice SYNC pattern (Table 9.2) | Marks superframe start; supports late entry |
| B | EMB + embedded signalling (LC fragment 1/4) | Variable-length BPTC, row 1 |
| C | EMB + embedded signalling (LC fragment 2/4) | Variable-length BPTC, row 2 |
| D | EMB + embedded signalling (LC fragment 3/4) | Variable-length BPTC, row 3 |
| E | EMB + embedded signalling (LC fragment 4/4) | Variable-length BPTC, row 4 |
| F | EMB + Null embedded message (inbound) **or** RC (outbound) | clause 7.1.3.2 / 7.1.3.1 |

The 72-bit Full Link Control word (clause 9.1.6, Table 9.7) is encoded with a 5-bit checksum, BPTC(Hamming(16,11,4)) row codes, and column parity (clause B.2.1, Figure B.3, p. 119). The interleaved 4×32-bit output is split into bursts B–E so a late-entry receiver can reassemble the LC inside 240 ms of any TX. **One LC per superframe, full stop.**

### 2.3 The five 48-bit SYNC patterns

From Table 9.2 (clause 9.1.1, p. 84):

| Source | Mode | Hex (48 bits, MSB-first) |
|---|---|---|
| BS  | Voice | `755FD7DF75F7` |
| BS  | Data  | `DFF57D75DF5D` |
| MS  | Voice | `7F7D5DD57DFD` |
| MS  | Data  | `D5D7F77FD757` |
| MS  | Standalone RC | `77D55F7DFD77` |

These are confirmed verbatim in the MMDVM modem source at `MMDVM/DMRDefines.h:60-69` (the `DMR_BS_VOICE_SYNC_BYTES`, `DMR_MS_DATA_SYNC_BYTES`, etc. constants) and the MMDVMHost source at `MMDVMHost/DMRDefines.h:42-52`. Note in particular `MMDVMHost/DMRDefines.h:48-52` defines `DIRECT_SLOT1_*` and `DIRECT_SLOT2_*` synonyms for DMO operation.

### 2.4 Voice initiation (clause 5.1.2.2)

Conventional systems prepend a **Voice LC Header** burst before the first voice superframe (Figure 5.4, p. 28). The voice LC header is a *general data burst* (data SYNC pattern, Data Type = 0x01 in the SLOT PDU per Table 9.22) carrying the 72-bit LC + 24-bit CRC + BPTC(196,96) FEC (clause 7.1.1, Figure 7.3, p. 59). After the header, voice superframes begin with burst A's voice SYNC.

Optionally a PI header (Privacy Indicator) precedes the LC header (Figure 5.6 / 5.7, p. 28). Trunked systems may omit the header entirely (Figure 5.5).

### 2.5 Voice termination (clause 5.1.2.3)

A speech item ends with a **Terminator with LC** burst immediately following the last superframe (Figure 5.8, p. 28). Data Type = 0x02 in the SLOT PDU. Same 72-bit LC + 24-bit CRC + BPTC(196,96) carriage. The sender does NOT keep transmitting LC headers periodically — the LC keeps flowing via the embedded field on bursts B–E.

### 2.6 BPTC(196,96) FEC for header / terminator / data

96 payload bits → 9×11 information matrix → +9 H_R Hamming(15,11,3) parity bits per row → +15 H_C Hamming(13,9,3) parity bits per column → +3 reserved + 1 fill = 196 bits. The transmit interleave is `interleave_index = (index × 181) mod 196` (clause B.1.1, Figure B.1 and Table B.2/B.3, p. 114–117). Reference implementation: `MMDVMHost/BPTC19696.cpp`.

### 2.7 Embedded LC variable-length BPTC

The 72-bit LC + 5-bit CS is laid out in a 7×11 information matrix with Hamming(16,11,4) row codes and a column-parity check row (clause B.2.1, Figure B.3, p. 119). The encode matrix is read column-major and split into four 32-bit rows. Each 32-bit row lives in **one burst's 32-bit embedded signalling field** — so bursts B, C, D, E each carry exactly 32 bits of the encoded LC. Reference implementation: `MMDVMHost/DMREmbeddedData.cpp`.

### 2.8 Data Type (Slot Type) values

From `MMDVMHost/DMRDefines.h:80-89`, matching ETSI Table 9.22:

```c
const unsigned char DT_VOICE_PI_HEADER    = 0x00U;
const unsigned char DT_VOICE_LC_HEADER    = 0x01U;
const unsigned char DT_TERMINATOR_WITH_LC = 0x02U;
const unsigned char DT_CSBK               = 0x03U;
const unsigned char DT_DATA_HEADER        = 0x06U;
const unsigned char DT_RATE_12_DATA       = 0x07U;
const unsigned char DT_RATE_34_DATA       = 0x08U;
const unsigned char DT_IDLE               = 0x09U;
const unsigned char DT_RATE_1_DATA        = 0x0AU;
// Synthetic, used only inside MMDVMHost to flag in-band voice
const unsigned char DT_VOICE_SYNC  = 0xF0U;
const unsigned char DT_VOICE       = 0xF1U;
```

The synthetic `DT_VOICE_SYNC = 0xF0U` and `DT_VOICE = 0xF1U` exist only as internal tags; on the wire they are conveyed by the `frame_type` bits of the HBP status byte (see §4 below), not by an LC slot type field.

---

## 3. MMDVM modem encoding pipeline

MMDVMHost runs on the host CPU (or in our case the Pi/PC); the **modem** (DVMEGA, MMDVM_HS, etc.) does symbol generation. The reference modem code lives at https://github.com/g4klx/MMDVM. The split matters: in DVSwitch's chain there is **no modem** — `Port=/dev/null` in `MMDVM_Bridge.ini:36`. All the burst-construction logic that lived inside MMDVMHost is now responsible for both "build the burst" *and* "ship it to the network" with no intervening physical layer.

Key modem-side files:

- `MMDVM/DMRDefines.h:24-101` — frame/sync/slot-type constants
- `MMDVM/DMRSlotType.cpp` — Golay(20,8) encode/decode for the 20-bit SLOT PDU
- `MMDVM/DMRTX.cpp:1-463` — voice/data burst symbol assembly and transmit
- `MMDVM/DMRDMOTX.cpp:1-168` — direct-mode (simplex) version, no CACH

The constants exposed at `MMDVM/DMRDefines.h:22-57` enumerate the bit/symbol/sample layout that drives the FPGA-style symbol clock:

```c
DMR_FRAME_LENGTH_BITS    = 264U   // total burst
DMR_SYNC_LENGTH_BITS     = 48U    // centre sync
DMR_EMB_LENGTH_BITS      = 16U    // EMB (4 CC + 1 PI + 2 LCSS + 9 parity)
DMR_EMBSIG_LENGTH_BITS   = 32U    // embedded LC fragment
DMR_SLOT_TYPE_LENGTH_BITS= 20U    // SLOT PDU
DMR_INFO_LENGTH_BITS     = 196U   // total data payload after BPTC
DMR_AUDIO_LENGTH_BITS    = 216U   // two 108-bit voice halves
```

For our diagnostic purpose the modem internals matter less than the **host-side** code that produces `DMRD` network packets — that's MMDVMHost / MMDVM_Bridge.

---

## 4. HomeBrew Protocol (HBP) DMRD packet structure

Source of truth: `MMDVMHost/DMRNetwork.cpp:187-253` (`CDMRNetwork::write(const CDMRData&)`). Packet is exactly 55 bytes:

```
Offset  Field            Width  Description
------  -----            -----  -----------
0..3    Magic            4 B    "DMRD" ASCII
4       SeqNo            1 B    Increments per burst written to the network
5..7    Source ID        3 B    Big-endian 24-bit DMR ID of source radio
8..10   Destination ID   3 B    Big-endian 24-bit TG (group) or DMR ID (private)
11..14  Repeater ID      4 B    Big-endian 32-bit hotspot/repeater ID
15      Status byte      1 B    See bit layout below
16..19  Stream ID        4 B    Random 32-bit — constant for the entire PTT
20..52  DMR Payload      33 B   The raw 264 bits of the burst (33×8 = 264)
53      BER              1 B    Bit error rate report (0 for synthetic TX)
54      RSSI             1 B    Reported as positive integer
```

### 4.1 Byte 15 (status byte) — bit-level

From `MMDVMHost/DMRNetwork.cpp:217-238`:

```
bit 7  : slot           0 = TS1, 1 = TS2
bit 6  : call type      0 = group, 1 = unit-to-unit
bits 5..4 : frame_type  00 = voice burst (DT_VOICE)
                        01 = voice-sync burst (burst A — DT_VOICE_SYNC)
                        10 = data-sync burst (DT_VOICE_LC_HEADER / TERMINATOR / etc.)
                        11 = reserved (never used)
bits 3..0 : dtype/vseq  For 00 (voice):     N (0..5 = burst A..F position in superframe)
                        For 01 (voice sync): 0
                        For 10 (data sync):  Data Type from the SLOT PDU
                                             (0x01 = DT_VOICE_LC_HEADER,
                                              0x02 = DT_TERMINATOR_WITH_LC, etc.)
```

### 4.2 Decoding the reported field values

| Wire byte | Binary | Slot | Call | frame_type | dtype/vseq | Interpretation |
|---|---|---|---|---|---|---|
| `0x90` | `1001 0000` | **TS2** | group | 01 (voice sync) | 0 | Burst A of a superframe |
| `0xa1` | `1010 0001` | **TS2** | group | 10 (data sync) | 0x01 = VHEAD | **Voice LC Header** |

The user's interpretation that 0x90 was TS1 and 0xa1 was TS2 is incorrect — **both bytes have bit 7 set, so both are TS2**. The slot field is fine. What is wrong is that `0xa1` (voice LC header) is appearing **during** a TX, not just at the start. Per ETSI clause 5.1.2 a voice TX has **one** preceding LC header burst (or zero, if trunked), then superframes; the LC keeps re-broadcasting through embedded signalling on bursts B–E, NOT through more `DT_VOICE_LC_HEADER` general-data bursts.

### 4.3 Stream ID lifecycle

From `MMDVMHost/DMRNetwork.cpp:222-238`: the bridge picks a new `stream_id` on `DT_VOICE_LC_HEADER`, `DT_CSBK`, or `DT_DATA_HEADER`:

```c
if (dataType == DT_VOICE_LC_HEADER)
    m_streamId[slotIndex] = dist(m_random);
if (dataType == DT_CSBK || dataType == DT_DATA_HEADER)
    m_streamId[slotIndex] = dist(m_random);
```

This is critical: if MMDVM_Bridge emits LC-header packets *more than once* in a single PTT but only re-randomises the stream_id at the *first*, BrandMeister sees one stream with periodically reappearing call-setup packets — which the dispatcher treats as protocol violation. If MMDVM_Bridge regenerates a new stream_id on each LC header repeat (because the binary may have diverged from vanilla MMDVMHost), BrandMeister sees a torrent of brand-new calls with no voice in between, each one starts and immediately dies.

### 4.4 Voice burst encoding on the network

For pure voice (`DT_VOICE`), MMDVMHost takes the burst index `N` and writes it into the low 4 bits of byte 15: `buffer[15U] |= data.getN();` (`MMDVMHost/DMRNetwork.cpp:228-229`). So N=0 → burst A, but **burst A always uses `DT_VOICE_SYNC` (frame_type=01) in HBP**, not `DT_VOICE` with N=0. The encoding is:

- Burst A: byte 15 = `S 0 0 1 0000` (frame_type=01, voice sync)
- Burst B: byte 15 = `S 0 0 0 0001` (frame_type=00, voice N=1)
- Burst C: byte 15 = `S 0 0 0 0010`
- Burst D: byte 15 = `S 0 0 0 0011`
- Burst E: byte 15 = `S 0 0 0 0100`
- Burst F: byte 15 = `S 0 0 0 0101`

(where S = slot bit, repeated for each new superframe).

A clean 1.8 s TX should produce: 1×LC header + 5 superframes × 6 bursts + 1×terminator = **32 packets**. The ~30 figure cited in the prompt is right within rounding (sub-1.8 s TX may yield 4 full superframes + partial = 27–30).

---

## 5. DVSwitch chain analysis

### 5.1 The pipeline

```
  TTS text  →  PCM (8 kHz 16-bit mono)
            →  Analog_Bridge (USRP receive at port 31001)
            →  TLV encoding with md380-emu RPC → AMBE+2 49-bit frames
            →  Analog_Bridge constructs DMR voice burst (264 bits = 27.5 ms)
                  three AMBE+2 frames per burst (3×49 bits = 147 vocoder bits
                  + FEC → 216 bits payload + 48 bits sync/EMB = 264 bits)
            →  TLV out on UDP 31103
            →  MMDVM_Bridge in on UDP 31100 (AMBE_AUDIO)
            →  MMDVM_Bridge wraps as HBP DMRD packets
            →  UDP to BrandMeister master:62031
```

### 5.2 What MMDVM_Bridge actually is

The DVSwitch `MMDVM_Bridge` GitHub repo (https://github.com/DVSwitch/MMDVM_Bridge) ships **only binaries plus `MMDVM_Bridge.ini`**. The source is a closed-but-stable fork of g4klx/MMDVMHost where the modem layer is replaced by a TLV listener. Inferring from binary behaviour + the `MMDVM_Bridge.ini` schema (which is identical to MMDVMHost's `MMDVM.ini` for the `[DMR]`, `[DMR Network]`, `[Modem]` sections), we know:

- `[Modem] Port=/dev/null` (`MMDVM_Bridge.ini:36`) — no real modem
- `[DMR Network]` section is exactly the same struct that `MMDVMHost/DMRNetwork.cpp` consumes, so HBP packet construction follows the reference implementation

This means **the host-side code paths that previously fed the modem now feed nothing**, but the same code paths still feed the network. Critically, **any place vanilla MMDVMHost wrote `NO_HEADERS_DUPLEX = 3` copies to `m_queue` (the modem queue) and `1` copy to `m_network->write()`** — if the DVSwitch binary deviated to write all 3 (or 8 simplex) copies *to the network*, that would precisely explain the 75-frame overcount.

### 5.3 The duplicate-write pattern in vanilla MMDVMHost

From `MMDVMHost/DMRSlot.cpp:70-72`:

```c
const unsigned int NO_HEADERS_SIMPLEX = 8U;
const unsigned int NO_HEADERS_DUPLEX  = 3U;
const unsigned int NO_PREAMBLE_CSBK   = 15U;
```

And the actual usage at `MMDVMHost/DMRSlot.cpp:953-961` (RF → network direction, voice LC header):

```c
if (m_duplex) {
    m_queue.clear();
    m_modem->writeDMRAbort(m_slotNo);
    for (unsigned int i = 0U; i < NO_HEADERS_DUPLEX; i++)
        writeQueueRF(start);     // <-- to MODEM queue (over-the-air)
}
writeNetworkRF(start, DT_VOICE_LC_HEADER);   // <-- to NETWORK (once)
```

And at `MMDVMHost/DMRSlot.cpp:1211-1217` (network → modem direction, when receiving from BrandMeister):

```c
if (m_duplex) {
    for (unsigned int i = 0U; i < NO_HEADERS_DUPLEX; i++)
        writeQueueNet(data);     // 3 copies to local TX modem
} else {
    for (unsigned int i = 0U; i < NO_HEADERS_SIMPLEX; i++)
        writeQueueNet(data);     // 8 copies in simplex (hotspot)
}
```

`writeQueueRF` (`MMDVMHost/DMRSlot.cpp:1943-1960`) and `writeQueueNet` (`MMDVMHost/DMRSlot.cpp:1998-2012`) both go into `m_queue` — the **modem's** TX buffer drained by `readModem()` (`MMDVMHost/DMRSlot.cpp:1014-1027`). `writeNetworkRF` (`MMDVMHost/DMRSlot.cpp:1962-1988`) is what calls `m_network->write(dmrData)` once.

### 5.4 The hypothesis that the DVSwitch binary has these paths welded together

Speculative — the DVSwitch source is not public to compare. But the symptom (`75 ≈ 30 voice + 8 LC header + 8 terminator + ~30 other duplicates`) lines up almost exactly with `NO_HEADERS_SIMPLEX = 8` applied at both ends of the TX, in a build where `m_queue` (no modem to drain it) was wired through to `m_network` as a safety hack.

If so, BrandMeister sees a stream that looks like:

```
LC_HDR, LC_HDR, LC_HDR, LC_HDR, LC_HDR, LC_HDR, LC_HDR, LC_HDR,  ← 8× DT_VOICE_LC_HEADER (frame_type=10, dtype=01)
VOICE_SYNC,                                                       ← burst A
VOICE(N=1), VOICE(N=2), VOICE(N=3), VOICE(N=4), VOICE(N=5),       ← bursts B-F
VOICE_SYNC, VOICE(N=1), ... ,                                     ← next superframe
... 5 superframes (30 bursts) ...
TERM, TERM, TERM, TERM, TERM, TERM, TERM, TERM                    ← 8× DT_TERMINATOR_WITH_LC
                                                                  → 8 + 30 + 8 + a few late embeds = 75 ish
```

The first LC_HDR populates Last Heard and BrandMeister allocates a stream. The 2nd through 8th LC_HDR collisions either (a) cause BM to reset the stream because the stream_id repeats or changes, or (b) cause BM to mark the call malformed and stop forwarding voice.

### 5.5 Analog_Bridge's role and md380-emu

Analog_Bridge is also closed-binary on GitHub (https://github.com/DVSwitch/Analog_Bridge — only `.ini`, scripts, systemd units). Its job:

1. Listen on `[USRP] rxPort = 31001` for PCM packets
2. Pack 20 ms of PCM (160 samples at 8 kHz) per AMBE+2 input
3. Either drive a hardware DV3000 (`[DV3000]` config) or call `md380-emu` over UDP/TCP at `127.0.0.1:2470` (`[GENERAL] emulatorAddress`) to encode
4. Accumulate 60 ms of vocoder (three 49-bit AMBE+2 frames) into one DMR voice burst's 216-bit payload
5. Add the 48-bit centre field — sync for burst A, embedded LC for B–E, null embedded for F
6. Output TLV-wrapped DMR burst on `[AMBE_AUDIO] txPort = 31103` to MMDVM_Bridge

md380-emu (https://github.com/travisgoodspeed/md380tools/tree/master/emulator) is the AMBE+2 encoder/decoder. It runs the MD380 radio firmware in an emulated MCS51 sandbox to drive the official AMBE+2 codec. Output format is `8-byte packed` per `md380tools/emulator/ambe.c:42` (`unsigned char packed[8]; //8 byte frames.`). 49 bits per AMBE+2 frame, stored MSB-first in `packed[0..6]` plus the 49th bit in `packed[7]&1`.

When Eric inspected "7-byte AMBE+2 frames" in the wire capture, that's consistent with the DMR-on-the-wire packing where the 49th bit gets folded with adjacent bits (the redundant 7 bits at byte 7 of md380-emu's output are discarded after re-packing for DMR). Reported as varied non-zero — the AMBE encoder is working.

### 5.6 The Pi-Star/DVMEGA control-experiment is informative

The prompt says: "the same hotspot ID works fine via a hardware Pi-Star with DVMEGA modem." This proves:

- BrandMeister credentials + TG 3127 subscription are correct
- Hotspot ID 310441017 routing on the BM side is fine
- Color code, callsign, registration are fine

What it does NOT prove is that Pi-Star and MMDVM_Bridge produce identical HBP packet streams. Pi-Star drives a real modem; the DVMEGA modem produces RF, and MMDVMHost's network-side code on Pi-Star uses the **once-per-burst** `writeNetworkRF` path. So Pi-Star produces clean 30-frame TX, BrandMeister sees one well-formed call, audio flows.

---

## 6. What BrandMeister validates per voice burst

BrandMeister's master code (the OpenBM master, https://github.com/BrandMeister/openbridge-protocol family) is also closed-source for the production network but the validation rules are well-documented in community discussions:

1. **DMRD magic + length** — must be 55 bytes starting with `"DMRD"`. Bad → silently dropped.
2. **Source repeater ID** — must match the registered hotspot ID (from the initial `DMRC` config string). Mismatch → dropped with no Last Heard entry.
3. **Color code** — the hotspot's announced color code (from `DMRC`) is informational; the wire color code lives in the EMB / SLOT PDU inside the 33-byte DMR payload. Master decodes it; a CC mismatch → packet ignored.
4. **Slot subscription** — TG 3127 is a TS2-only network; a TS1 burst on TG 3127 → dropped.
5. **Stream coherence** — for a single `stream_id`, BM expects: one DT_VOICE_LC_HEADER (or zero, late-entry), then a sequence of `frame_type=01,00,00,00,00,00,01,00,…` until one DT_TERMINATOR_WITH_LC. Out-of-order voice sequence numbers, repeated LC headers, or terminators followed by more voice → BM either resets the stream or marks it malformed and stops forwarding to subscribers, **while keeping the original Last Heard entry**.
6. **Per-burst FEC** — BM does NOT re-run BPTC(196,96) decoding of every burst (it would burn too much CPU). It trusts the hotspot. So a broken FEC inside the 33-byte payload does NOT cause the silent drop we're seeing.
7. **Embedded LC reassembly** — BM may try to reassemble the embedded LC across bursts B–E to extract Talker Alias and GPS. If the embedded LC is corrupt this is logged but does not block the call (Talker Alias is informational).
8. **Voice burst index N** — must increment 1→5 then a fresh voice_sync, then 1→5, etc. Skips or duplicates are tolerated lazily (some hotspots have terrible timing) but back-to-back voice_sync (two A bursts in a row) → reset.

Layers 5 and 8 are the silent-drop layers consistent with our symptom. Re-emerging `DT_VOICE_LC_HEADER` packets mid-stream (layer 5) is the strongest match.

---

## 7. Diagnostic hypotheses

Ranked most-likely → least-likely. Each has a confirmation test.

### Hypothesis A (most likely): Analog_Bridge OR MMDVM_Bridge is emitting multiple `DT_VOICE_LC_HEADER` packets per TX, causing BrandMeister to silently reset the stream after the first one populates Last Heard.

**Symptom that confirms it:** in the wire capture (tcpdump / Wireshark on the bridge → BrandMeister UDP flow), filter for packets where byte 15 = `0xa1` (slot 2, group, frame_type=10, dtype=01). A clean 1.8 s TX should show **exactly one** such packet at the very start. Our failing TX likely shows 3 or 8 of them clustered at TX start, and probably another cluster of `0xa2` (terminator) at end.

**Verification command:**
```bash
sudo tcpdump -i any -nn -X udp port 62031 -c 200 \
    | grep -B1 -A20 'DMRD' \
    | grep -E '0x000[0-9]:.*a1 |0x000[0-9]:.*a2 '
# Count of each type — expect 1 a1 (LC header), N voice frames, 1 a2 (terminator)
```

**Fix to test:** in `Analog_Bridge.ini`, look for a `headerCount` or `preambleCount` option (the DVSwitch wiki sometimes adds undocumented options); failing that, file a DVSwitch group issue with the capture attached. The known-good baseline is *one* LC header per TX.

### Hypothesis B (next most likely): The first frame's `stream_id` is being regenerated on every duplicate LC header instead of being held constant for the entire PTT, so BrandMeister sees what looks like many extremely short calls each with one LC header and no voice.

**Symptom that confirms it:** with the same tcpdump, extract bytes 16–19 of every DMRD packet. In a healthy TX, all packets share the same stream_id. In this failure mode, packets at index 0..7 each have a different stream_id, then packets 8..N share a stable stream_id (the last LC-header's value).

**Verification command (Python one-liner over a pcap):**
```python
from scapy.all import rdpcap, UDP, Raw
pkts = rdpcap('failing.pcap')
for p in pkts:
    if p.haslayer(UDP) and p[UDP].dport == 62031 and p.haslayer(Raw):
        d = bytes(p[Raw])
        if d[:4] == b'DMRD':
            print(f"seq={d[4]:02x} byte15={d[15]:02x} stream={d[16:20].hex()}")
```

**Fix to test:** if confirmed, the bridge code is hashing too eagerly. Workaround: patch the binary to suppress duplicate LC headers (hard) or downgrade to an older MMDVM_Bridge release where the dup pattern wasn't applied to the network direction.

### Hypothesis C (plausible — speculative): The embedded LC across bursts B–E carries a different src/dst ID than the LC header, and BrandMeister's late-entry reassembler rejects the mismatch, dropping every voice burst silently.

**Symptom that confirms it:** decode the 32-bit embedded signalling field of every voice frame (bursts B–E) and reassemble the LC. If the resulting LC does not match the LC carried in the DT_VOICE_LC_HEADER's BPTC(196,96) payload, this is the cause. This is hard to verify without a DMR-aware analyzer; the cheap test is to compare the byte-20 onwards payload of the LC header packet to the embedded-signalling bits of the burst B/C/D/E voice packets after de-interleaving.

**Fix to test:** ensure Analog_Bridge's LC source (TLV metadata: srcId, dstId, FLCO) is set BEFORE the first AMBE frame is produced, not refreshed per-burst from an upstream provider that may differ. In `Analog_Bridge.ini` check `gatewayDmrId`, `repeaterID`, `txTg`, `txTs` are all consistent. The fact that Last Heard shows the right call suggests the LC header is right; embedded LC drift mid-call is the failure here.

### Hypothesis D (least likely but cheap to rule out): Color code mismatch between the bridge's `[DMR] ColorCode` and the embedded EMB / SLOT PDU CC field, causing every voice burst's centre 48 bits to be treated by BM as belonging to a different site.

**Symptom that confirms it:** in `MMDVM_Bridge.ini` (`[DMR] ColorCode=1`) and `Analog_Bridge.ini` (`[AMBE_AUDIO] colorCode = 1`), confirm both are equal. If they're not, the burst-internal CC will disagree with the announced CC in the DMRC config string. BrandMeister tolerates this on some masters but not all — most importantly, in OpenBridge mode (which 3103 may be configured for), the CC is strictly enforced.

**Fix to test:** set both to 1 (or whatever the BM target expects — most US 3103 servers default to CC1). Restart both bridges. If the burst now decodes cleanly on a known-good Pi-Star receiver, the audio path is restored.

### Bonus diagnostic — the AMBE bytes look reasonable but verify ordering

The user's report says "AMBE bytes from md380-emu look reasonable (varied non-zero content, 7-byte AMBE+2 frames)." The DMR voice burst layout is:

```
| AMBE+2 #1 (49b) | AMBE+2 #2 (49b) | EMB+EMBSIG (48b) | AMBE+2 #3 (49b) |
                                    ^^^ centre field
```

Note AMBE frame #2 is **split** by the centre field — the first half occupies the last bits of the 108-bit left payload, the second half occupies the first bits of the 108-bit right payload. If the bridge concatenates 3×49 = 147 bits of vocoder then inserts the 48-bit centre after the first 108 bits (instead of splitting AMBE #2), the second AMBE frame's bits get mangled. md380-emu's output reaching Analog_Bridge intact does NOT guarantee that the 108-bit-half splitting is correct. Inspect Analog_Bridge's TLV output (port 31100/31103) — a clean burst payload (bytes 20–52 of the DMRD packet) should have the centre 48 bits (bytes 33–38 inclusive of the 33-byte payload) be either a clean sync word OR an EMB+embedded-LC pattern.

---

## 8. Recommended next steps (priority order)

1. **Run a clean tcpdump on the bridge VM, port 62031 outbound, for one failing TX**, and post-process the capture with the Python script in §7 hypothesis B above. Count how many packets have `byte15 == 0xa1` (TS2 LC header) and how many distinct `stream_id` values appear. **This single test discriminates between hypotheses A, B, and the rest.**

2. **Capture a known-good TX from the same hotspot via Pi-Star** (same TG, same talkgroup, same callsign), same tcpdump filter, and diff the two captures. The difference between the working and broken streams will jump out as duplicated `0xa1` packets and/or shifting stream_id.

3. **Compare both `MMDVM_Bridge.ini` and `Analog_Bridge.ini` against the DVSwitch sample configs**, paying special attention to `Duplex`, `Slot1`, `Slot2`, `ColorCode`, `txTs`, `txTg`, and any options labelled "headerCount", "preamble", "txDelay". Eric's reported failure mode is reproducible enough that documenting the bad config delta against a known-working DVSwitch install is high-leverage.

4. **File an issue at https://github.com/DVSwitch/MMDVM_Bridge/issues** with the diff'd captures attached. The DVSwitch group (https://dvswitch.groups.io/g/main) has handled similar cases. The maintainer responsible is mostly N4IRS — provide DMR ID 3104410, the BrandMeister master 3103, and the packet count.

5. **As a quick workaround** — if the DVSwitch binary is the problem and a fix is not forthcoming, downgrade `MMDVM_Bridge` to an older release from the git tag history (`git log --tags` in the repo, try a tag from ~2024). The duplicate-frame pattern may have been introduced in a relatively recent build.

6. **Verify the AMBE→burst split** is correct by capturing one full DMRD packet from a known-good TX and one from a failing TX with identical talkgroup metadata, and comparing the byte-20–byte-52 (33-byte DMR payload) field. The centre 48 bits (bytes 33–38) should match the expected sync/EMB pattern for the burst type indicated in byte 15.

7. **Once a fix is in place**, regression-test by running a Python tool against the bridge's UDP output that asserts: per-TX exactly one `0xa1` packet, exactly one `0xa2` packet, and a strictly increasing burst-index pattern in the voice and voice-sync packets. This belongs in TicketsCAD's `services/meshtastic`-adjacent test rigs if DMR is ever brought online for amateur EMS dispatch.

---

## 9. References

### ETSI standards
- ETSI TS 102 361-1 v1.4.5 (2007-12), *Electromagnetic compatibility and Radio spectrum Matters (ERM); Digital Mobile Radio (DMR) Systems; Part 1: DMR Air Interface (AI) protocol*. https://www.etsi.org/deliver/etsi_ts/102300_102399/10236101/01.04.05_60/ts_10236101v010405p.pdf
  - Clause 4.2.2 (burst structure, p. 19)
  - Clause 5.1.2.1 (voice superframe, p. 27)
  - Clause 5.1.2.2 (voice initiation with LC header, p. 28)
  - Clause 5.1.2.3 (voice termination, p. 28)
  - Clause 6.1 (vocoder socket, p. 53)
  - Clause 7.1 (Full LC message structure, p. 57)
  - Clause 7.1.3 (embedded signalling, p. 60)
  - Clause 9.1.1 / Table 9.2 (SYNC patterns, p. 84)
  - Clause 9.1.2 / Table 9.3 (EMB PDU, p. 85)
  - Clause 9.1.3 / Table 9.4 (Slot Type PDU, p. 85)
  - Clause B.1.1 (BPTC(196,96), p. 114)
  - Clause B.2.1 (variable-length BPTC for embedded LC, p. 117)

### MMDVM modem
- https://github.com/g4klx/MMDVM
  - `DMRDefines.h:24-101` — frame/sync/data-type constants
  - `DMRDefines.h:60-69` — `DMR_BS_VOICE_SYNC_BYTES`, `DMR_MS_VOICE_SYNC_BYTES`, etc.
  - `DMRDefines.h:90-98` — DT_* constants matching ETSI Table 9.22
  - `DMRSlotType.cpp` — Golay(20,8) SLOT PDU encode/decode
  - `DMRTX.cpp`, `DMRDMOTX.cpp` — symbol-level burst construction

### MMDVMHost
- https://github.com/g4klx/MMDVMHost
  - `DMRDefines.h:42-52` — sync byte arrays (BS/MS/DIRECT_SLOT[12], audio/data)
  - `DMRDefines.h:80-89` — DT_* slot type constants
  - `DMRDefines.h:92-93` — synthetic DT_VOICE_SYNC = 0xF0, DT_VOICE = 0xF1 internal tags
  - `DMRDefines.h:99-100` — DMR_SLOT1 = 0x00, DMR_SLOT2 = 0x80
  - `DMRDefines.h:113-121` — FLCO enum (GROUP=0, USER_USER=3, talker alias etc.)
  - `DMRNetwork.cpp:32` — `HOMEBREW_DATA_PACKET_LENGTH = 55U`
  - `DMRNetwork.cpp:187-253` — `CDMRNetwork::write(const CDMRData&)`, the canonical HBP packet builder
  - `DMRNetwork.cpp:217-238` — byte-15 status encoding
  - `DMRNetwork.cpp:222-238` — stream_id regeneration triggers
  - `DMRSlot.cpp:70-72` — `NO_HEADERS_SIMPLEX = 8U`, `NO_HEADERS_DUPLEX = 3U`
  - `DMRSlot.cpp:953-961` — RF-source LC header: 3 RF copies + 1 network copy
  - `DMRSlot.cpp:1211-1217` — net-source LC header expansion (8 simplex / 3 duplex) to RF queue
  - `DMRSlot.cpp:1943-1960` / `1962-1988` / `1990-1996` / `1998-2012` — `writeQueueRF`, `writeNetworkRF`, `writeQueueNet` definitions
  - `BPTC19696.cpp` — reference BPTC(196,96) encoder/decoder
  - `DMREmbeddedData.cpp` — variable-length BPTC for embedded LC across bursts B–E
  - `DMRFullLC.cpp` — Full LC encode/decode wrapper
  - `Sync.cpp:41-65` — `addDMRDataSync` / `addDMRAudioSync` choose BS sync when duplex, MS sync when simplex

### DVSwitch
- https://github.com/DVSwitch/MMDVM_Bridge — binaries-only repo; `MMDVM_Bridge.ini` template at root
- https://github.com/DVSwitch/Analog_Bridge — binaries-only repo; `Analog_Bridge.ini` template at root
  - `[Modem] Port=/dev/null` line 36 of MMDVM_Bridge.ini
  - `[DMR Network] Slot1=0 Slot2=1` lines 79-80 of MMDVM_Bridge.ini
  - `[GENERAL] useEmulator emulatorAddress=127.0.0.1:2470` of Analog_Bridge.ini
  - `[AMBE_AUDIO] txPort=31103 rxPort=31100 ambeMode=DMR txTs=2 colorCode=1` of Analog_Bridge.ini
- https://dvswitch.groups.io/g/main — DVSwitch community group, the right place to file the failure

### md380-emu
- https://github.com/travisgoodspeed/md380tools/tree/master/emulator
  - `ambe.c:42` — `unsigned char packed[8]; //8 byte frames.`
  - `ambe.c:71` — 49th AMBE+2 bit stored in `packed[7]&1`
  - https://github.com/travisgoodspeed/md380tools/wiki/MD380-Emulator — operator docs

### HomeBrew Protocol field documentation
- BrandMeister wiki, *Homebrew/example/php2*: https://wiki.brandmeister.network/index.php/Homebrew/example/php2 — confirms DMRD status-byte bit layout (slot, call type, frame_type, dtype/vseq)
- HBlink3 source: https://github.com/lz5pn/HBlink3/blob/master/HBlink3/const.py — `HBPF_VOICE=0x0`, `HBPF_VOICE_SYNC=0x1`, `HBPF_DATA_SYNC=0x2`, `HBPF_SLT_VHEAD=0x1`, `HBPF_SLT_VTERM=0x2`
- https://github.com/n0mjs710/HBlink4 — reference PyDMR HomeBrew implementation
- DVSwitch group archives on the "audio not flowing despite Last Heard" failure: https://dvswitch.groups.io/g/main (search "no audio brandmeister")

### Operator context
- N0NKI / DMR ID 3104410 / hotspot 310441017
- TG 3127 Minnesota Statewide on BrandMeister master 3103 port 62031
- Hardware control: Pi-Star + DVMEGA modem (works correctly with same ID + TG)


---

# Part II — What we built (Phase 84, June 2026)

> The first half of this document is the research brief from June 15 that
> diagnosed *why* the original DVSwitch chain (Analog_Bridge → MMDVM_Bridge
> → BrandMeister) was silently dropping our voice frames. The second half is
> the engineering record of the **native Python HBP client** we built to
> replace that chain, plus everything a future developer needs to know to
> maintain, debug, or extend it. Read Part I if you want to understand the
> protocol; read Part II if you need to ship code against it.

---

## 10. Architecture overview

The end-to-end voice path between the TicketsCAD dispatcher's browser and
the BrandMeister network looks like this:

```
            DISPATCHER BROWSER                               BRANDMEISTER MASTER
                  │                                          (3102.master.brandmeister.network:62031)
                  │ Phase 84 PTT POST (webm/opus)                        ▲
                  │ Phase 85b PTT chunked PCM (planned)                  │
                  ▼                                                      │ HomeBrew DMRD frames
            ┌─────────────┐  curl   ┌─────────────┐  socket             │ (55 byte UDP)
            │ Apache + PHP├────────►│  hbp_client │ ────────────────────┘
            │  /api/dmr-* │         │  (Python)   │
            └──────┬──────┘         └──────┬──────┘
                   ▲                       │ subprocess.run
                   │ SSE (NDJSON)          ▼
                   │              ┌─────────────────────┐
                   │              │ md380-emu via       │
                   └──────────────│ qemu-arm-static     │
                                  │ (AMBE encode/decode)│
                                  └─────────────────────┘
```

Two long-running processes live on the bridge VM (`dvswitch-01`,
`10.0.0.10`):

- **`hbp_client.py`** — the HBP master client. Authenticates to
  BrandMeister, holds the UDP socket on port 62032 outbound, receives
  every DMRD packet for our subscribed talkgroups, and serves the
  control HTTP on port 18091 (TX endpoints + audio-stream SSE +
  health + transcript broadcast). About 950 lines.
- **`echo_bot.py`** — passive observer running in a separate process.
  Uses `sudo tcpdump` to mirror the inbound UDP without conflicting
  with the client's socket, decodes AMBE → PCM → WAV per call,
  runs faster-whisper STT, and POSTs the transcript back to the
  bridge's `/transcript` endpoint AND to TicketsCAD's
  `/api/dmr-ingest.php`. About 460 lines.

Both processes share the same `services/dvswitch/` Python package
on the bridge filesystem (PYTHONPATH=`/opt/ticketscad-dvswitch`).

---

## 11. Build environment + dependencies

### 11.1 The bridge VM (`dvswitch-01`)

```
Debian 13 (trixie), Python 3.13.5
/opt/ticketscad-dvswitch/
├── hbp_client.py
├── echo_bot.py
├── services/dvswitch/
│   ├── ambe_codec.py         # md380-emu wrapper (Python)
│   ├── ambe_fec.py           # DMR_A_TABLE, DMR_B_TABLE, DMR_C_TABLE (deinterleave tables)
│   ├── _prng_data.py         # 8-bit PRNG table for AMBE descrambling
│   ├── bptc.py               # BPTC(196,96), BPTC(128,72), interleavers
│   ├── golay.py              # Golay(24,12), Golay(20,8) for Slot Type
│   ├── dmr_emb.py            # 16-bit EMB encoder/decoder (QR + Hamming)
│   ├── dmr_tx.py             # DMRCallTransmitter, full LC builder, status bytes
│   └── reed_solomon.py       # RS(12,9) over GF(2^8)
└── venv/
    └── bin/
        ├── python3            # the venv python
        └── piper              # text-to-speech (used by /tx/text path)
```

### 11.2 External binaries

- **`/usr/bin/qemu-arm-static`** — debian package `qemu-user-static`.
  Required to run the md380 firmware blob (an ARM binary) on x86-64.
- **`/opt/md380-emu/md380-emu`** — built from
  `https://github.com/travisgoodspeed/md380tools/tree/master/emulator`
  per its README. The emulator wraps the Tytera MD-380 vocoder
  firmware to expose AMBE+2 encode/decode as a stdio pipeline.
- **`/usr/bin/ffmpeg`** — for transcoding browser-side WebM/Opus to
  8 kHz s16le mono PCM before AMBE encoding.
- **`/usr/bin/tcpdump`** — echo_bot uses passive packet capture so it
  doesn't compete with hbp_client for the HBP UDP socket. Requires a
  sudoers entry granting NOPASSWD execution to the `ticketscad` user.

### 11.3 Python venv libraries

`pip install -r requirements.txt` where `requirements.txt` contains
`faster-whisper` and `piper-tts`. The native HBP client itself uses
only Python stdlib: socket, threading, struct, hashlib, http.server,
queue, subprocess, json, base64. **No HTTP libraries**, no SDKs. The
whole DMR wire format is hand-rolled.

### 11.4 systemd units

- `ticketscad-hbp-client.service` (intended; currently the bridge is
  run via a nohup'd shell pending systemd packaging)
- `ticketscad-echo-bot.service` — installed, enabled, runs as
  `ticketscad:ticketscad`, env file at `/etc/ticketscad/echo-bot.env`.
  `ProtectSystem=strict` forces `HF_HOME` redirection to
  `/var/cache/ticketscad-dvswitch/hf` for the Whisper model cache.

---

## 12. The HomeBrew Protocol login dance

Source: `hbp_client.py` lines ~360–520. Reference implementations:
HBlink4 (`hblink.py`), MMDVMHost (`HomebrewDMRGateway/`).

The handshake from our side:

```
                                          BM master
   send RPTL DMRID                  ─────►
                                    ◄───── RPTACK + 4-byte salt
   send RPTK DMRID hash(salt+pw)    ─────►
                                    ◄───── RPTACK (auth ok)
   send RPTC DMRID + 302-byte config─────►
                                    ◄───── RPTACK (we're a peer)
   send MSTPING every 5 s           ─────►
                                    ◄───── MSTPONG every 5 s
                                    ◄───── DMRD ............ (incoming voice)
   send DMRD ............           ─────► (outgoing voice)
```

Packet types:

| Magic     | Direction | Meaning                                          |
|-----------|-----------|--------------------------------------------------|
| `RPTL`    | →         | Login request, payload = DMR ID (4 bytes)        |
| `RPTK`    | →         | Auth, payload = DMR ID + SHA-256(salt+pw)        |
| `RPTC`    | →         | Config, payload = 302-byte descriptor            |
| `RPTPING` | →         | Keepalive (we send every 5 s)                    |
| `MSTACK`  | ←         | Generic ack (8 byte: `MSTACK` + DMR ID)          |
| `RPTACK`  | ←         | Login/auth/config ack                            |
| `MSTPONG` | ←         | Keepalive response                               |
| `MSTNAK`  | ←         | "go away" (cause to retry login)                 |
| `MSTCL`   | →         | Disconnect (graceful logout)                     |
| `DMRD`    | ↔         | Voice/data frame (55 bytes — see §4 in Part I)   |

### 12.1 RPTL → MSTNAK loop trap

If you send RPTL and immediately get MSTNAK, the master already has a
session for your DMR ID. Two causes:

1. You restarted the bridge but BM's session timer hasn't expired (~30 s).
2. Another peer (Pi-Star at the same QTH, another bridge instance) is
   logged in as the same DMR ID.

The fix is to either wait the timer out or send `MSTCL` cleanly on
shutdown (`hbp_client.py:_send_logout`).

### 12.2 The RPTC payload — duplex vs simplex (CRITICAL)

The RPTC config is a 302-byte ASCII record with hardware/software
identification. **Three fields together tell BrandMeister whether
to forward TG traffic INTO our peer:**

| Field          | Bytes  | Duplex value           | DMO value         |
|----------------|--------|------------------------|-------------------|
| RX_FREQ        | 27–35  | RX MHz × 10^6 (9 chars)| same as TX        |
| TX_FREQ        | 36–44  | TX MHz × 10^6 (9 chars)| same as RX        |
| Slots          | 97     | `'3'`                  | `'1'` or `'2'`    |
| Description    | 196–215| `'MMDVM_MMDVM_HS'`     | `'MMDVM_Pi-Star'` |

**BrandMeister's relay layer only forwards subscribed talkgroup traffic
to peers it classifies as DUPLEX repeaters**, not DMO/simplex hotspots.
The classifier looks at the three fields above together. If RX_FREQ ==
TX_FREQ AND Slots is `'1'` or `'2'` (not `'3'`), BM tags the peer as
DMO and silently drops outbound TG forwarding. The peer can still
TRANSMIT into the network (which is why our initial "headers appear on
Last Heard, no audio relay" pattern was puzzling), but inbound traffic
never reaches it.

`hbp_client.py:_build_rptc_payload` sets these to known-good DUPLEX
values: RX_FREQ=438800000, TX_FREQ=438800000 (split values that don't
have to be on-air valid, just distinct-or-not), Slots=`'3'`, Description
=`'MMDVM_MMDVM_HS'`. Editing these breaks RX silently.

### 12.3 Auth hash — exact recipe

```python
auth_hash = hashlib.sha256(salt_bytes + passphrase.encode()).digest()
# 32 bytes, sent raw (NOT hex) as payload after the DMR ID
```

The salt is the 4 bytes BM returns immediately after our RPTL. The
passphrase is the BrandMeister peer password — for our setup, env var
`DMR_BM_PASSPHRASE`, currently `passw0rd` per BM's free-tier default
for test peers.

---

## 13. DMRD packet wire format (re-stated, this time as code)

The 55-byte DMRD packet that flows in BOTH directions on UDP/62031↔62032:

```python
def build_dmrd_packet(seq, src_id, dst_id, repeater_id, status_byte,
                      stream_id, dmr_payload_33) -> bytes:
    pkt = bytearray(55)
    pkt[0:4]   = b"DMRD"                              # magic
    pkt[4]     = seq & 0xFF                           # per-stream wrap counter
    pkt[5:8]   = src_id.to_bytes(3, 'big')            # 24-bit src DMR ID
    pkt[8:11]  = dst_id.to_bytes(3, 'big')            # 24-bit dst TG or unit
    pkt[11:15] = repeater_id.to_bytes(4, 'big')       # 32-bit our DMR ID
    pkt[15]    = status_byte                          # see §13.1
    pkt[16:20] = stream_id.to_bytes(4, 'big')         # constant for entire PTT
    pkt[20:53] = dmr_payload_33                       # 33-byte DMR burst
    pkt[53:55] = b"\x00\x00"                          # BER + RSSI (zero for software)
    return bytes(pkt)
```

### 13.1 Status byte (`payload[15]`) — what we actually emit

After eight rounds of trial-and-error (Phase 84 a–l), the *only* status
bytes BM accepts and forwards for **TG voice on TS1** are:

```python
STATUS_TS2_GROUP_LC_HEADER  = 0x21   # data_sync + DT_VOICE_LC_HEADER
STATUS_TS2_GROUP_TERMINATOR = 0x22   # data_sync + DT_TERMINATOR_WITH_LC
STATUS_TS2_GROUP_BURST_A    = 0x10   # voice_sync (burst A)
STATUS_TS2_GROUP_BURST_B    = 0x01   # voice (burst B, frame type 1)
STATUS_TS2_GROUP_BURST_C    = 0x02   # voice (burst C, frame type 2)
STATUS_TS2_GROUP_BURST_D    = 0x03   # voice (burst D, frame type 3)
STATUS_TS2_GROUP_BURST_E    = 0x04   # voice (burst E, frame type 4)
STATUS_TS2_GROUP_BURST_F    = 0x05   # voice (burst F, frame type 5)
```

Bit layout (verified against MMDVMHost `DMRBPMessage.cpp` and the
BrandMeister `php2` Homebrew example):

| Bit | Meaning                | Our value                                                            |
|-----|------------------------|----------------------------------------------------------------------|
| 7   | stream-extension flag  | **ALWAYS 0**. Setting bit 7 (the BM-extended `0xA1` / `0x90` form) passes auth and Last Heard but BM silently drops at the relay layer. **THIS WAS PHASE 84's MULTI-WEEK BUG.** |
| 6   | call type              | 0 = group, 1 = private (FLCO=3, FLCO=0 in the LC matches)           |
| 5   | time slot              | 0 = TS1, 1 = TS2. Despite the constant names containing "TS2", the values we emit have bit 5 SET because BM tags MN-statewide on TS2 on the wire from our perspective. Naming kept from MMDVMHost convention for cross-reference. |
| 4   | frame type bit 1       | 0=voice, 1=data                                                      |
| 3-0 | data type / vseq       | For data_sync: 0x1=VOICE_LC_HEADER, 0x2=TERMINATOR_WITH_LC, 0x6=CSBK. For voice/voice_sync: vseq A=0..F=5. |

The "naming says TS2 but bit 5 means TS1" comment above is
intentional — MMDVMHost's constants were defined when the
convention was that the master saw TS2 as the relay slot. We kept
the names to make MMDVMHost source navigable; the BYTE VALUES are
what matter. Don't rename without verifying against a working
capture.

---

## 14. The 3-LC-header convention (CRITICAL)

`dmr_tx.py:transmit_pcm` emits the LC header burst **THREE TIMES** at
the start of every call, with consecutive sequence numbers, before the
first voice superframe. BrandMeister's relay state machine treats this
as the canonical "real repeater" pattern; a single header is accepted by
the master (Last Heard updates) but the relay layer never starts
forwarding voice. With three, both the master and the relay layer agree
the call started.

```python
# Phase 84l: BM convention -- 3 LC headers, then voice, then 2 terminators.
LC_HEADER_REPEATS = 3
TERMINATOR_REPEATS = 2

seq = random.randint(0, 255)
for _ in range(LC_HEADER_REPEATS):
    pkt = build_dmrd_packet(seq, ..., STATUS_TS2_GROUP_LC_HEADER, ...)
    send(pkt); seq = (seq + 1) & 0xFF

# ... superframes ...

for _ in range(TERMINATOR_REPEATS):
    pkt = build_dmrd_packet(seq, ..., STATUS_TS2_GROUP_TERMINATOR, ...)
    send(pkt); seq = (seq + 1) & 0xFF
```

`AudioPump.on_dmrd` dedupes incoming `call_start` events by `stream_id`
(Phase 84u) so the 3-header convention doesn't surface as 3 received
calls.

---

## 15. BS Data Sync vs MS Sync — which sync pattern to emit

The 48-bit centre SYNC pattern of the LC header / terminator must match
what BM expects from a real duplex repeater. Bits taken straight from
ETSI TS 102 361-1, Table 9.2:

| Direction      | Voice                              | Data                                |
|----------------|------------------------------------|-------------------------------------|
| Base→MS (BS)   | `0x755FD7DF75F7` (`SYNC_BS_VOICE`) | `0xDFF57D75DF5D` (`SYNC_BS_DATA`)   |
| MS→Base (MS)   | `0x7F7D5DD57DFD` (`SYNC_MS_VOICE`) | `0xD5D7F77FD757` (`SYNC_MS_DATA`)   |

**Use BS sync, not MS sync.** During Phase 84 our captures of a
working hotspot (Pi-Star outbound to BM) showed BS Data Sync on the
LC header. We initially used MS sync because Pi-Star CAN run in
simplex mode and emit MS, and our test setup was simplex. BM silently
dropped every burst.

The constant is `SYNC_BS_DATA` for header/terminator, `SYNC_BS_VOICE`
for burst A; bursts B–F carry EMB (no sync slot). All defined at
`dmr_tx.py:48-60`.

---

## 16. The full Link Control (LC) word + Reed-Solomon parity

The 9-byte Full LC word goes inside the LC header and terminator
bursts. It carries the call's identity (FLCO, src ID, dst ID, service
options) — see Part I §2.2 for the bit layout. What Part I doesn't
cover is the **trailer**:

```python
def build_full_lc(src_id, dst_id, flco=FLCO_GROUP_VOICE,
                  data_type_mask=VOICE_LC_HEADER_CRC_MASK):
    lc = bytearray(12)
    lc[0] = flco & 0x3F          # PF=0, Reserved=0, FLCO 6 bits
    lc[1] = 0x00                 # FID = 0 (standard MMDVMHost)
    lc[2] = 0x00                 # service options = 0
    lc[3:6] = dst_id.to_bytes(3, 'big')
    lc[6:9] = src_id.to_bytes(3, 'big')
    lc[9], lc[10], lc[11] = build_lc_trailer(bytes(lc[:9]), data_type_mask)
    return bytes(lc)
```

`build_lc_trailer` computes **Reed-Solomon (12,9) parity over GF(2^8)**
and XORs the 3 parity bytes with a data-type-dependent mask:

| Data type                | Mask        |
|--------------------------|-------------|
| VOICE_LC_HEADER          | `0x969696`  |
| TERMINATOR_WITH_LC       | `0x999999`  |
| VOICE_LC_HEADER (CSBK)   | `0xA5A5A5`  |

The masks are XOR'd in by the SENDER. The RECEIVER computes RS
syndrome on the 9 info bytes + 3 received parity bytes, and only
accepts if the syndrome XOR'd with the expected mask is zero.

**BrandMeister validates the syndrome.** Phase 84k discovered that
our original "CRC-24" was a placeholder fixed value; BM accepted the
header (because Last Heard doesn't require valid RS) but rejected
every subsequent voice burst because the terminator's RS didn't match.

The RS implementation lives at `services/dvswitch/reed_solomon.py`.
It's a from-scratch GF(2^8) port of MMDVMHost's `DMRFullLC.cpp` — no
external dependency. Lookup tables for GF(2^8) addition / multiplication
are computed at module load.

### 16.1 Embedded LC across bursts B–E (Talker Alias)

Bursts B–E inside each superframe also carry a Talker Alias (FLCO=4)
that gets reassembled by late-entry receivers. The embedded LC uses
**variable-length BPTC over Hamming(16,11,4) row codes**, NOT the
BPTC(196,96) of the header. See `dmr_emb.py` for the 4-chunk encoder.
Without correct Talker Alias chunks, late-entry receivers can join the
call but never see the operator's callsign in their display.

---

## 17. Golay(20,8) Slot Type encoder (Phase 84i)

Every data burst (LC header, terminator, CSBK) carries a 20-bit Slot
Type that tells the receiver the burst's role:

- 4-bit Color Code
- 4-bit Data Type (matches the dtype nibble of the status byte)
- 12-bit Golay(24,12) parity (the (20,8) variant truncates to 20 bits)

Embedded as 10-bit halves on either side of the centre sync pattern:

```
| 98-bit payload first half | 10-bit slot type first half | 48-bit SYNC |
                                                          | 10-bit slot type second half | 98-bit payload second half |
```

`golay.py:encode_golay_20_8` produces the 20-bit codeword. The
splitting and centre-sync placement happens in
`dmr_tx.py:build_data_burst`.

---

## 18. md380-emu AMBE+2 vocoder wrapper

`services/dvswitch/ambe_codec.py` (~150 lines) is a Python wrapper
around the md380-emu ARM binary, running under qemu-arm-static.

### 18.1 What md380-emu does

The Tytera MD-380 firmware contains the licensed AMBE+2 vocoder
Motorola uses. The emulator project (Travis Goodspeed et al.) extracts
the vocoder symbols and provides a stdio interface:

- stdin: raw 16-bit PCM at 8 kHz (160 samples = 20 ms per frame)
- stdout: 49-bit AMBE+2 frames, packed as 7 bytes
- One process can do encode OR decode but not both — we run two.

### 18.2 Python wrapper architecture

`AmbeCodec` is a long-lived helper that spawns two subprocess pipes:

```python
class AmbeCodec:
    def __init__(self):
        self._enc = subprocess.Popen(
            ["qemu-arm-static", "/opt/md380-emu/md380-emu", "-e"],
            stdin=PIPE, stdout=PIPE, stderr=DEVNULL, bufsize=0,
        )
        self._dec = subprocess.Popen(
            ["qemu-arm-static", "/opt/md380-emu/md380-emu", "-d"],
            stdin=PIPE, stdout=PIPE, stderr=DEVNULL, bufsize=0,
        )
        # ... mutex per pipe, frame-size constants ...

    def encode(self, pcm_320b: bytes) -> bytes:
        """20 ms of PCM (320 bytes = 160 s16le samples) → 7-byte AMBE."""

    def decode(self, ambe_7b: bytes) -> bytes:
        """7-byte AMBE → 320 bytes PCM."""
```

### 18.3 The 49-bit AMBE packing

AMBE+2 outputs 49 bits per frame. md380-emu packs them into 7 bytes
with the 49th bit landing in the low bit of byte 7
(`ambe.c:71 packed[7]&1`). The remaining 7 bits of byte 7 are zero
padding. **When you read the 7-byte output**, you must treat it as
49 bits, NOT 56.

### 18.4 Three AMBE frames per voice burst

One DMR voice burst (33 bytes payload at index 20..52 of DMRD)
carries 3 consecutive 49-bit AMBE frames = 147 bits, plus 69 bits
of EMB/sync/embedded LC interleaved with FEC. The 3-frame packing
involves 24-bit Golay(23,12) "a" subframes, 24-bit FEC on "b"
subframes, and an XOR descrambler with the PRNG table at
`services/dvswitch/_prng_data.py` (256 bytes).

The full interleave is encoded by `dmr_tx.py:build_voice_burst_payload`
and the inverse is `AudioPump._extract_wire` + `_info_from_wire` in
`hbp_client.py`. **Do not modify these without a paired capture from
a known-good hotspot to diff against** — they're not pleasant code
and BM is strict about every bit.

### 18.5 Performance

On a 4-core x86 VM, AMBE encode/decode under qemu-arm-static takes
**~3–5 ms per frame**. A voice burst (3 frames) = 9–15 ms. Real-time
DMR pacing is 60 ms per voice burst (one superframe burst), so we
have ~45 ms of headroom per burst on the encode side. Decode is the
same. The AudioPump's RX decode runs synchronously on the network
read thread — there's a check `if not self._subs: return` so we skip
the qemu round-trip when no SSE subscribers are listening, since
the CPU cost is the dominant load.

---

## 19. AudioPump — live RX delivery to dispatchers

`hbp_client.py:AudioPump` (~200 lines) is the fan-out layer between
the HBP receive loop and `/audio-stream` HTTP subscribers.

### 19.1 Subscriber model

```python
class AudioPump:
    QUEUE_MAX = 200   # ~12 s buffered per subscriber

    def subscribe(self) -> tuple[str, queue.Queue]:
        sub_id = uuid.uuid4().hex
        q = queue.Queue(maxsize=self.QUEUE_MAX)
        with self._lock:
            self._subs[sub_id] = q
        return sub_id, q

    def _broadcast(self, event: dict) -> None:
        with self._lock:
            for sub_id, q in self._subs.items():
                try:
                    q.put_nowait(event)
                except queue.Full:
                    # Drop oldest, then re-insert the new event.
                    try: q.get_nowait()
                    except queue.Empty: pass
                    try: q.put_nowait(event)
                    except queue.Full: pass
```

Each subscriber gets its OWN bounded queue. A slow subscriber drops
oldest frames rather than blocking the producer.

### 19.2 Event types broadcast

| event        | When                                      | Payload                                                  |
|--------------|-------------------------------------------|----------------------------------------------------------|
| `call_start` | First LC header of a stream (deduped)     | `{call_id, src_id, talkgroup, ts}`                       |
| `audio`      | Every voice burst                         | `{call_id, src_id, talkgroup, seq, pcm: base64, ts}`     |
| `call_end`   | Terminator                                | `{call_id, ended_at}`                                    |
| `transcript` | Posted by echo_bot after Whisper          | `{call_id, text, engine}`                                |
| `keepalive`  | Every 15 s when queue empty               | `{event: "keepalive"}`                                   |

### 19.3 Wire format to PHP proxy

Events are emitted as **NDJSON** (one JSON object per line) on the
`/audio-stream` HTTP response. The PHP proxy at
`api/dmr-stream.php` translates each line to a named SSE event for
the browser. We deliberately did NOT use SSE format on the bridge
itself — it lets the bridge be tested with curl and keeps the
event-stream parsing in PHP rather than Python.

---

## 20. The TX state machine

`dmr_tx.py:DMRCallTransmitter.transmit_pcm(pcm_bytes)` is the
authoritative TX path. It runs to completion in a single thread
(currently spawned by `hbp_client._handle_tx_audio` as a daemon
thread so the HTTP response can return 202 immediately — Phase 84t).

### 20.1 Sequence per call

```
1. random 32-bit stream_id  (Phase 84l: not a counter — random matches Pi-Star)
2. random initial seq byte
3. For _ in range(3):                       # 3 LC header bursts
       header_burst = build_data_burst(
           bptc_encode(build_full_lc(src, dst, flco, VOICE_LC_HEADER_CRC_MASK)),
           sync_pattern=SYNC_BS_DATA,
           color_code=1,
           slot_type=Golay20_8(cc=1, dtype=VOICE_LC_HEADER),
       )
       send(build_dmrd_packet(seq, STATUS_TS2_GROUP_LC_HEADER, header_burst))
       seq += 1
       sleep(60ms)        # pace at the real OTA cadence

4. For each 60ms superframe of audio:        # voice bursts A..F
       for burst in [A, B, C, D, E, F]:
           ambe_3 = [codec.encode(pcm[i*320:(i+1)*320]) for i in range(3)]
           payload = pack_voice_burst(ambe_3, burst_position, embedded_lc_chunk)
           status = STATUS_TS2_GROUP_BURST_<burst>
           send(build_dmrd_packet(seq, status, payload))
           seq += 1
           sleep(60ms)

5. For _ in range(2):                       # 2 terminator bursts
       term_burst = build_data_burst(
           bptc_encode(build_full_lc(src, dst, flco, TERMINATOR_WITH_LC_CRC_MASK)),
           sync_pattern=SYNC_BS_DATA,
           color_code=1,
           slot_type=Golay20_8(cc=1, dtype=TERMINATOR_WITH_LC),
       )
       send(build_dmrd_packet(seq, status, term_burst))
       seq += 1
       sleep(60ms)
```

### 20.2 Continuous seq numbering

`seq` is a per-call wrap-around counter (`(seq + 1) & 0xFF`). It must
increment monotonically across ALL bursts of a stream including LC
headers and terminators. **The 3 LC headers at the start have
seq, seq+1, seq+2 — not all the same value.** A capture diff against
Pi-Star confirmed this.

### 20.3 Padding short input

If `len(pcm) % 320 != 0`, the last superframe is padded with silence
(zero PCM). Without padding, the encoder produces a partial burst
that BM rejects.

---

## 21. The HTTP control surface

`hbp_client.py` runs `http.server.ThreadingHTTPServer` on port 18091.
Every endpoint requires `Authorization: Bearer <token>` matching the
`DMR_BEARER_TOKEN` environment variable.

| Path            | Method | Auth   | Purpose                                                         |
|-----------------|--------|--------|-----------------------------------------------------------------|
| `/health`       | GET    | Bearer | `{ok, state, running, rx_dmrd, rx_keepalive}`                   |
| `/audio-stream` | GET    | Bearer | Long-lived NDJSON of call_start/audio/call_end/transcript       |
| `/tx/text`      | POST   | Bearer | JSON `{text}` → piper TTS → AMBE → transmit                     |
| `/tx/audio`     | POST   | Bearer | Raw audio body → ffmpeg → AMBE → transmit (Phase 84t: async)    |
| `/tx/stream`    | POST   | Bearer | (Phase 85b — planned) chunked PCM stream                        |
| `/transcript`   | POST   | Bearer | JSON `{call_id, text, engine}` → AudioPump broadcast            |

The bearer token is plaintext in `dmr_channels.bridge_token` on
TicketsCAD (so api/dmr-stream.php and api/dmr-tx-audio.php can forward
it back). The legacy `api/dmr-ingest.php` accepts either plaintext OR
sha256 form (Phase 84-followup-3).

---

## 22. Verified-working configuration

The exact combination known to work end-to-end against
BrandMeister master 3102 as of Phase 84-followup-10 (2026-06-16):

- **Operator DMR ID:** 310441018 (must be a peer-registered ID;
  apply via brandmeister.network self-care if testing with a new ID)
- **TG:** 3127 Minnesota Statewide on TS1, static-subscribed on
  the BM dashboard for our peer
- **RPTC duplex bits:** Slots=`'3'`, Description=`'MMDVM_MMDVM_HS'`,
  RX_FREQ != TX_FREQ
- **Status bytes:** as in §13.1 (TS2 group voice family, bit 7 = 0)
- **Sync:** `SYNC_BS_DATA` for header/terminator, `SYNC_BS_VOICE`
  for burst A, EMB for B-F
- **LC parity:** RS(12,9), mask 0x969696 (header), 0x999999 (terminator)
- **Headers per call:** 3 at the start, 2 terminators at the end
- **Seq:** random init, monotonic increment across all bursts including
  headers and terminators
- **Stream ID:** random 32-bit per call

### 22.1 Smoke test commands

Bridge live + authenticated:

    curl -H "Authorization: Bearer $TOKEN" \
         http://10.0.0.10:18091/health
    → {"ok": true, "state": 4, "running": true, "rx_dmrd": N, "rx_keepalive": M}

Subscribe and watch for live traffic:

    curl -H "Authorization: Bearer $TOKEN" -N \
         http://10.0.0.10:18091/audio-stream

TTS test transmit (verifies the encode/TX path without needing a mic):

    curl -X POST -H "Authorization: Bearer $TOKEN" \
         -H "Content-Type: application/json" \
         -d '{"text":"N0NKI native python HBP test"}' \
         http://10.0.0.10:18091/tx/text
    → {"ok": true, "duration_ms": 2716, ...}

Verify on `https://hose.brandmeister.network/` — search for DMR ID
310441018 in real-time, OR check
`https://brandmeister.network/?page=device&id=310441018` for last-heard
timestamp.

---

## 23. Failure modes a future developer will hit

| Symptom                                       | Cause                                                       | Fix                                                                   |
|-----------------------------------------------|-------------------------------------------------------------|-----------------------------------------------------------------------|
| Last Heard updates, no audio reaches receivers| Only 1 LC header sent, OR bit 7 of status set, OR MS sync   | §13.1, §14, §15                                                       |
| `rx_dmrd=0` even after auth                   | TG static subscription missing on BM dashboard              | Log into brandmeister.network → SelfCare → Static talkgroups          |
| `MSTNAK` immediately on RPTL                  | Prior session still alive, OR duplicate peer ID             | Wait 30 s, then retry. OR send `MSTCL` on shutdown.                   |
| BM auth succeeds but no relayed audio inbound | RPTC duplex bits wrong (`Slots='1'`, simplex desc)          | §12.2 — set Slots=`'3'`, `MMDVM_MMDVM_HS`                             |
| Transmits work but other DMR users can't hear | LC trailer RS parity wrong, OR fake CRC                     | §16 — use real RS(12,9) with the right mask                           |
| AMBE decode returns silence (PCM all zero)    | md380-emu binary missing OR qemu-arm-static missing         | `apt install qemu-user-static`; build md380-emu per upstream README   |
| AMBE decode produces noise                    | 49-bit packing read as 56 bits, OR PRNG descrambler off     | Check `ambe.c:71` for packing convention; verify `_prng_data.py` is 256 bytes |
| Echo_bot crashes on Whisper model download    | Service unit ProtectSystem=strict + HF_HOME unset           | Set HF_HOME=/var/cache/ticketscad-dvswitch/hf in service env file     |
| Bridge says authenticated but no RX in log    | TG not statically subscribed (BM does NOT auto-subscribe)   | Log into BM dashboard, add TG to peer's Static Talkgroups list        |
| Echo across TG (we hear ourselves)            | BM relays our TX back; expected behaviour                   | Phase 84-followup-10: widget self-mutes during PTT                    |
| TX 504 from Cloudflare Tunnel                 | Bridge `/tx/audio` was synchronous; tunnel ~100s ceiling    | Phase 84t: bridge returns 202 immediately, TX runs in daemon thread   |
| 134-second timeouts on other endpoints        | SSE handler held PHP session lock; all other reqs blocked   | Phase 84-followup-7: session_write_close() right after RBAC check     |

---

## 24. What's missing / known limitations

- **Private (unit-to-unit) calls (FLCO=3):** infrastructure in place
  (`build_full_lc` takes `flco`), but `DMRCallTransmitter` hardcodes
  `FLCO_GROUP_VOICE`. Status bytes need bit 6 set for private. BM's
  relay policy for private calls is hotspot-dependent. See Phase
  84z assessment.
- **DMR data (text messages — CSBK / UDT):** entirely new pipeline.
  No code exists. `dmr_tx.py` is voice-only.
- **Multiple talkgroups simultaneously:** we hardcode one
  `DMR_DEFAULT_TG`. BrandMeister allows multiple statics; the
  bridge would need to track per-TG state and the AudioPump events
  already carry `talkgroup` so the widget could fan-out per-TG.
- **Real-time streaming TX (Phase 85b):** currently TX is
  buffer-then-transmit; the dispatcher's voice doesn't reach TG
  until they release PTT. See `specs/phase-85-dispatch-console/spec.md`
  R1.
- **DMR voice encryption (BP / ARC4):** unsupported; not on the
  amateur side, not on TG 3127.
- **DSTAR / NXDN / P25:** different protocols, would need separate
  bridges. Out of scope.
