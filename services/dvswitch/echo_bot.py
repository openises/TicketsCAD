#!/usr/bin/env python3
"""
TicketsCAD DMR echo bot — listen for inbound TG voice via the running
hbp_client's authenticated session, decode AMBE → PCM → WAV, transcribe
with faster-whisper, and reply via hbp_client's /tx/text HTTP endpoint.

The bot is intentionally a SEPARATE process from hbp_client so a Whisper
restart or VAD hang doesn't take down the TX path. It uses tcpdump to
passively observe inbound DMRD packets on the local UDP socket (port
62032 by default) — no socket conflict with hbp_client.

Run manually:
    /opt/ticketscad-dvswitch/venv/bin/python3 \
        /opt/ticketscad-dvswitch/echo_bot.py

Or via systemd (preferred — see services/dvswitch/ticketscad-echo-bot.service):
    sudo systemctl enable --now ticketscad-echo-bot

Environment overrides:
    WHISPER_MODEL    (default base.en; try small.en for better quality)
    WHISPER_COMPUTE  (default int8; int8_float16 if CPU has AVX2)
    DMR_BEARER_TOKEN (required — must match hbp_client's token)
    DMR_HBP_LOCAL_PORT (default 62032 — must match hbp_client bind port)
    DMR_BM_MASTER_IP (default 74.91.114.19 — must match hbp_client master)
    BOT_REPLY_PREFIX (default "I heard you say.")
    BOT_REPLY_SUFFIX (default "End of reply.")
    BOT_REPLY_ENABLED (default "true" — set to "false" to ingest-only)

TicketsCAD ingest (Phase 84r — RX/transcript pump):
    DMR_INGEST_URL   (optional — e.g. https://your-server.example.com/api/dmr-ingest.php)
    DMR_INGEST_TOKEN (optional — bearer token; same value as DMR_BEARER_TOKEN if shared)
    DMR_INGEST_LABEL (default "echo-bot" — DMR channel label on the ingest side)
    DMR_RECORDINGS_DIR (default /tmp — write WAVs here instead of /tmp when
                       set, so api/dmr-audio.php can serve them via HTTP Range)

When DMR_INGEST_URL is set, every finalised RX call is POSTed to
TicketsCAD with the schema dmr-ingest.php expects (label / direction /
started_at / ended_at / duration_ms / talkgroup / transcript /
transcript_engine / audio_path / audio_format / radio_id /
radio_callsign). Best-effort — a TicketsCAD outage never crashes the
bot.

The reply is composed as:
    "{BOT_REPLY_PREFIX} {transcript}. {BOT_REPLY_SUFFIX}"

Empty transcripts (silence, garbled audio Whisper couldn't parse) are
skipped — no reply is sent and no ingest POST is made.
"""
from __future__ import annotations

import json
import logging
import os
import re
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
import wave
from collections import defaultdict

# Project modules — installed via PYTHONPATH=/opt/ticketscad-dvswitch
from services.dvswitch.ambe_codec import AmbeCodec
from services.dvswitch.ambe_fec import (
    DMR_A_TABLE, DMR_B_TABLE, DMR_C_TABLE,
)
from services.dvswitch._prng_data import PRNG_TABLE


# ── Configuration ────────────────────────────────────────────────
HBP_TX_URL = f"http://127.0.0.1:{os.environ.get('DMR_HTTP_PORT', '18091')}/tx/text"
# Phase 84aa — broadcast transcripts back to the bridge so radio
# widget SSE subscribers see them as soon as Whisper finishes.
HBP_TRANSCRIPT_URL = f"http://127.0.0.1:{os.environ.get('DMR_HTTP_PORT', '18091')}/transcript"
HBP_TOKEN = os.environ["DMR_BEARER_TOKEN"]
LOCAL_PORT = int(os.environ.get("DMR_HBP_LOCAL_PORT", "62032"))
BM_MASTER_IP = os.environ.get("DMR_BM_MASTER_IP", "74.91.114.19")

WHISPER_MODEL = os.environ.get("WHISPER_MODEL", "base.en")
WHISPER_COMPUTE = os.environ.get("WHISPER_COMPUTE", "int8")

REPLY_PREFIX = os.environ.get("BOT_REPLY_PREFIX", "I heard you say.")
REPLY_SUFFIX = os.environ.get("BOT_REPLY_SUFFIX", "End of reply.")
REPLY_ENABLED = os.environ.get("BOT_REPLY_ENABLED", "true").lower() in ("1", "true", "yes", "on")

# TicketsCAD ingest (optional)
INGEST_URL = os.environ.get("DMR_INGEST_URL", "").strip() or None
INGEST_TOKEN = os.environ.get("DMR_INGEST_TOKEN", "").strip() or None
INGEST_LABEL = os.environ.get("DMR_INGEST_LABEL", "echo-bot")
RECORDINGS_DIR = os.environ.get("DMR_RECORDINGS_DIR", "/tmp").rstrip("/")

# Status bytes that mark voice content (covers both BM-extended and
# standard MMDVMHost values, both timeslots).
VOICE_STATUSES = {
    0x10, 0x90,                    # burst A (TS1 / TS2)
    0x01, 0x02, 0x03, 0x04, 0x05,  # bursts B-F TS1
    0x81, 0x82, 0x83, 0x84, 0x85,  # bursts B-F TS2
}
TERMINATOR_STATUSES = {0x22, 0xa2}
LC_HEADER_STATUSES = {0x21, 0xa1}

# How long to wait for more packets in a stream before assuming the
# call ended (in case the terminator was lost).
CALL_STALE_TIMEOUT_S = 2.0

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
LOG = logging.getLogger("echo_bot")


# ── DMR wire → AMBE → PCM ────────────────────────────────────────
def _bits_from_burst(burst: bytes) -> list[int]:
    return [(b >> (7 - j)) & 1 for b in burst for j in range(8)]


def _extract_wire(burst_bits: list[int], frame_idx: int) -> list[int]:
    """Pull a 72-bit AMBE wire frame (24 A + 23 B + 25 C) from the
    burst at the position assigned to frame_idx (0, 1, or 2)."""
    out = []
    for tab in (DMR_A_TABLE, DMR_B_TABLE, DMR_C_TABLE):
        for p in tab:
            if frame_idx == 0:
                pos = p
            elif frame_idx == 2:
                pos = p + 192
            else:
                pos = p + 72 + (48 if p + 72 >= 108 else 0)
            out.append(burst_bits[pos])
    return out


def _info_from_wire(wire_72: list[int]) -> tuple[int, int, list[int]]:
    """Decode 72 wire bits into (a_data, b_data, c_bits) using the
    Golay codes + PRNG whitening that MMDVMHost uses."""
    a_data = sum(wire_72[i] << (11 - i) for i in range(12))
    p = PRNG_TABLE[a_data] >> 1
    b_codeword = sum(wire_72[24 + i] << (22 - i) for i in range(23)) ^ p
    b_data = (b_codeword >> 11) & 0xFFF
    c_bits = wire_72[47:72]
    return a_data, b_data, c_bits


def _pack_ambe(a: int, b: int, c_bits: list[int]) -> bytes:
    """49 info bits (12 + 12 + 25) → 7-byte AMBE frame, MSB first."""
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


# ── STT ──────────────────────────────────────────────────────────
LOG.info("loading faster-whisper %s (compute=%s)…",
         WHISPER_MODEL, WHISPER_COMPUTE)
from faster_whisper import WhisperModel  # noqa: E402
_whisper = WhisperModel(WHISPER_MODEL, device="cpu", compute_type=WHISPER_COMPUTE)
LOG.info("whisper ready")

_codec = AmbeCodec()


def transcribe(path_wav_8k: str) -> str:
    """Whisper's internal frontend resamples 8 kHz → 16 kHz; no
    ffmpeg pre-step needed. Greedy decode (beam_size=1) is faster
    and AMBE quality doesn't benefit from beam search."""
    segments, _info = _whisper.transcribe(
        path_wav_8k,
        beam_size=1,
        language="en",
        condition_on_previous_text=False,
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 300},
    )
    parts = [seg.text.strip() for seg in segments]
    return " ".join(p for p in parts if p).strip()


# ── HBP TX (via hbp_client's HTTP control endpoint) ──────────────
def post_tx(text: str) -> None:
    req = urllib.request.Request(
        HBP_TX_URL,
        data=json.dumps({"text": text}).encode(),
        headers={
            "Authorization": f"Bearer {HBP_TOKEN}",
            "Content-Type": "application/json",
        },
        method="POST",
    )
    resp = urllib.request.urlopen(req, timeout=30)
    LOG.info("TX response: %s", resp.read().decode())


# ── TicketsCAD ingest (best-effort) ──────────────────────────────
def _post_ingest_sync(payload: dict) -> None:
    """Single best-effort POST to dmr-ingest.php. Logs failures, never raises."""
    if not (INGEST_URL and INGEST_TOKEN):
        return
    data = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        INGEST_URL, data=data, method="POST",
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {INGEST_TOKEN}",
            "User-Agent": f"ticketscad-echo-bot/1.0 ({INGEST_LABEL})",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=8) as resp:
            if resp.status >= 300:
                LOG.warning("ingest POST %s returned %d", INGEST_URL, resp.status)
            else:
                LOG.info("ingest OK (call %s, %d byte payload)",
                         payload.get("stream_id", "?"), len(data))
    except urllib.error.HTTPError as e:
        LOG.warning("ingest HTTPError %s: %s", e.code, e.read()[:200])
    except (urllib.error.URLError, TimeoutError, OSError) as e:
        LOG.warning("ingest network error: %s", e)
    except Exception as e:  # never crash the bot on ingest failure
        LOG.exception("ingest unexpected failure: %s", e)


def post_ingest(payload: dict) -> None:
    """Fire-and-forget — background thread posts to TicketsCAD."""
    threading.Thread(
        target=_post_ingest_sync, args=(payload,),
        name=f"echo-bot-ingest-{payload.get('stream_id', '?')}",
        daemon=True,
    ).start()


# ── Bridge transcript broadcast (Phase 84aa) ─────────────────────
def _post_bridge_transcript_sync(call_id: str, text: str, engine: str) -> None:
    """POST a finished transcript back to hbp_client's /transcript so
    every audio-stream subscriber (radio widgets) sees it. Strictly
    best-effort — never raises."""
    if not call_id or not text:
        return
    body = json.dumps({"call_id": call_id, "text": text, "engine": engine}).encode("utf-8")
    req = urllib.request.Request(
        HBP_TRANSCRIPT_URL, data=body, method="POST",
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {HBP_TOKEN}",
            "User-Agent": "ticketscad-echo-bot/1.0",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=5) as resp:
            if resp.status >= 300:
                LOG.warning("transcript broadcast %s returned %d", HBP_TRANSCRIPT_URL, resp.status)
    except Exception as e:
        LOG.warning("transcript broadcast failed: %s", e)


def publish_transcript_to_bridge(call_id: str, text: str, engine: str = "whisper") -> None:
    """Fire-and-forget — background thread broadcasts to the bridge."""
    threading.Thread(
        target=_post_bridge_transcript_sync, args=(call_id, text, engine),
        name=f"echo-bot-bridge-transcript-{call_id[:8]}",
        daemon=True,
    ).start()


# ── Call accumulator ─────────────────────────────────────────────
# Each entry: stream_id (4 bytes) → list of (seq, status, payload, full_packet, arrival_time).
_calls: dict[bytes, list[tuple[int, int, bytes, bytes, float]]] = defaultdict(list)
_last_pkt: dict[bytes, float] = {}
_first_pkt: dict[bytes, float] = {}


def finish_call(sid: bytes) -> None:
    call = _calls.pop(sid, [])
    last_t = _last_pkt.pop(sid, None)
    first_t = _first_pkt.pop(sid, None)
    if len(call) < 5:
        LOG.info("call %s too short (%d) — skip", sid.hex(), len(call))
        return
    LOG.info("call %s ended — %d packets, decoding", sid.hex(), len(call))
    call.sort(key=lambda x: x[0])

    # Pull metadata from the first DMRD packet (LC header carries the
    # canonical src/dst IDs). full_pkt[5:8] = src, [8:11] = dst.
    full_pkt0 = call[0][3]
    src_id = int.from_bytes(full_pkt0[5:8], "big")
    dst_tg = int.from_bytes(full_pkt0[8:11], "big")

    voice_payloads = [p for _seq, st, p, _full, _t in call if st in VOICE_STATUSES]
    if not voice_payloads:
        LOG.warning("call %s had no voice payloads", sid.hex())
        return
    LOG.info("voice payloads: %d  src=%d  tg=%d", len(voice_payloads), src_id, dst_tg)

    pcm_buf = bytearray()
    for payload in voice_payloads:
        bb = _bits_from_burst(payload)
        for fi in range(3):
            wire = _extract_wire(bb, fi)
            a, b, c = _info_from_wire(wire)
            ambe = _pack_ambe(a, b, c)
            try:
                pcm_buf.extend(_codec.decode(ambe))
            except Exception as e:
                LOG.warning("AMBE decode failed: %s", e)
                continue
    if not pcm_buf:
        LOG.warning("no PCM decoded for call %s", sid.hex())
        return

    # Write WAV to recordings dir (for TicketsCAD's api/dmr-audio.php
    # to serve back) or /tmp if no recordings dir is configured.
    started_at = first_t if first_t else time.time()
    when = time.localtime(started_at)
    wav_filename = (
        f"{INGEST_LABEL}-{time.strftime('%Y%m%d-%H%M%S', when)}-"
        f"{src_id}-tg{dst_tg}-{sid.hex()}.wav"
    )
    wav_path = os.path.join(RECORDINGS_DIR, wav_filename)
    try:
        os.makedirs(RECORDINGS_DIR, exist_ok=True)
        with wave.open(wav_path, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(8000)
            w.writeframes(bytes(pcm_buf))
    except OSError as e:
        LOG.warning("WAV write to %s failed: %s — falling back to /tmp", wav_path, e)
        wav_path = f"/tmp/rx-{sid.hex()}.wav"
        with wave.open(wav_path, "wb") as w:
            w.setnchannels(1)
            w.setsampwidth(2)
            w.setframerate(8000)
            w.writeframes(bytes(pcm_buf))
    duration_s = len(pcm_buf) / 16000.0
    LOG.info("wrote %s (%.2f sec)", wav_path, duration_s)

    # STT
    t0 = time.monotonic()
    try:
        text = transcribe(wav_path)
    except Exception as e:
        LOG.error("STT failed: %s", e)
        text = ""
    LOG.info("STT (%.2fs): %r", time.monotonic() - t0, text)

    # Ingest to TicketsCAD (best-effort, even if STT is empty).
    if INGEST_URL and INGEST_TOKEN:
        ended_at = last_t if last_t else (started_at + duration_s)
        payload = {
            "label": INGEST_LABEL,
            "direction": "rx",
            "started_at": started_at,
            "ended_at": ended_at,
            "duration_ms": int(duration_s * 1000),
            "talkgroup": dst_tg,
            "audio_path": wav_path,
            "audio_format": "wav",
            "radio_id": src_id,
            "stream_id": sid.hex(),
        }
        if text:
            payload["transcript"] = text
            payload["transcript_engine"] = f"whisper:{WHISPER_MODEL}"
        post_ingest(payload)

    # Phase 84aa — also broadcast the transcript to every radio
    # widget via the bridge's SSE pipeline. This is independent of
    # the ingest path: ingest persists the transcript in the
    # database, this delivers it to live dispatcher dashboards.
    # The bridge tolerates empty `text` (treats it as the call's
    # transcript being "(no speech detected)") but we suppress the
    # post for empty text to keep the audio-stream events clean.
    if text:
        publish_transcript_to_bridge(sid.hex(), text, f"whisper:{WHISPER_MODEL}")

    # Optional echo reply.
    if not text:
        LOG.info("empty STT — no reply"); return
    if not REPLY_ENABLED:
        return
    reply = f"{REPLY_PREFIX} {text}. {REPLY_SUFFIX}"
    LOG.info("replying: %r", reply)
    try:
        post_tx(reply)
    except Exception as e:
        LOG.error("TX post failed: %s", e)


# ── tcpdump parser ───────────────────────────────────────────────
_HEX_LINE = re.compile(r"^\s+0x[0-9a-f]+:\s+(.+)$")
_TS_LINE = re.compile(r"^\d{2}:\d{2}:\d{2}")


def parse_hex_block(hex_str: str) -> tuple[int, int, bytes, bytes, bytes] | None:
    """Returns (seq, status, stream_id, burst_payload, full_55_byte_pkt) or None."""
    h = re.sub(r"\s", "", hex_str)
    idx = h.find("444d5244")    # "DMRD"
    if idx < 0:
        return None
    try:
        pkt = bytes.fromhex(h[idx : idx + 110])
    except ValueError:
        return None
    if len(pkt) < 53:
        return None
    return pkt[4], pkt[15], pkt[16:20], pkt[20:53], pkt[:55]


def main() -> int:
    LOG.info("starting tcpdump on UDP %d (inbound DMRD from %s)",
             LOCAL_PORT, BM_MASTER_IP)
    proc = subprocess.Popen(
        [
            "sudo", "tcpdump", "-i", "eth0", "-nn", "-x", "-l",
            f"src host {BM_MASTER_IP} and dst port {LOCAL_PORT} and greater 50",
        ],
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        bufsize=1,
        text=True,
    )

    LOG.info("listening for inbound voice…")
    current_hex: list[str] = []
    last_stale_check = time.monotonic()
    assert proc.stdout is not None
    for line in proc.stdout:
        line = line.rstrip()
        if _TS_LINE.match(line):
            if current_hex:
                result = parse_hex_block("".join(current_hex))
                if result is not None:
                    seq, status, stream, payload, full_pkt = result
                    now_mono = time.monotonic()
                    now_wall = time.time()
                    _calls[stream].append((seq, status, payload, full_pkt, now_wall))
                    if stream not in _first_pkt:
                        _first_pkt[stream] = now_wall
                    _last_pkt[stream] = now_wall
                    if status in TERMINATOR_STATUSES:
                        finish_call(stream)
                current_hex = []
        else:
            m = _HEX_LINE.match(line)
            if m:
                current_hex.append(m.group(1))

        now_mono = time.monotonic()
        if now_mono - last_stale_check > CALL_STALE_TIMEOUT_S:
            now_wall = time.time()
            for sid in list(_last_pkt):
                if now_wall - _last_pkt[sid] > CALL_STALE_TIMEOUT_S:
                    LOG.info("call %s stale (no terminator) — finalizing",
                             sid.hex())
                    finish_call(sid)
            last_stale_check = now_mono

    return proc.wait()


if __name__ == "__main__":
    sys.exit(main())
