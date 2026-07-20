#!/usr/bin/env python3
"""
DVSwitch bridge — TicketsCAD NewUI

Lives on dvswitch-01 (or any host with Analog_Bridge + MMDVM_Bridge +
md380-emu installed). One process per linked talkgroup. Talks USRP-PCM
to Analog_Bridge on the audio side and JSON-over-HTTP to TicketsCAD
NewUI on the control side.

This is the foundation slice (Phase 73i). It covers:

  * USRP listen + send loops (16-bit 8 kHz PCM, 32-byte big-endian
    header, 'USRP' magic, 320-byte voice payloads).
  * HTTP control endpoint with bearer-token auth — matches the Phase
    35A meshbridge_v2 pattern. Endpoints: /health, /tx/test,
    /calls/recent, /config.
  * Per-call audit log written to a JSONL file (POSTed to TicketsCAD's
    api/dmr-ingest.php in a future slice).
  * Configurable per-instance via env file (path passed as --env), so
    a systemd template unit `ticketscad-dvswitch@.service` can spin up
    one instance per channel.

Deferred to later phases:

  * Piper TTS engine for tx (Phase 73m — shipped).
  * Vosk STT for rx (Phase 73n — shipped).
  * faster-whisper for higher accuracy (queued for later slice).
  * POST to TicketsCAD's api/dmr-ingest.php with bearer auth.

Run standalone for testing:

    DMR_INSTANCE=tg91 \\
    DMR_USRP_LISTEN_PORT=33001 \\
    DMR_USRP_SEND_PORT=33000 \\
    DMR_HTTP_PORT=18091 \\
    DMR_BEARER_TOKEN=secret \\
    python3 bridge.py
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import shutil
import signal
import socket
import struct
import subprocess
import threading
import time
import urllib.error
import urllib.request
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Optional

# ─── Constants ────────────────────────────────────────────────────
USRP_MAGIC = b'USRP'
USRP_HEADER_LEN = 32          # 'USRP' magic + 7×4 byte BE ints
USRP_VOICE_LEN = 320          # 160 samples × 16-bit PCM
USRP_TYPE_VOICE = 0
USRP_TYPE_DTMF = 1
USRP_TYPE_TEXT = 2
USRP_TYPE_PING = 3
USRP_TYPE_TLV = 4
SAMPLE_RATE = 8000
LOG = logging.getLogger("dvswitch.bridge")


def env_int(key: str, default: int) -> int:
    v = os.environ.get(key)
    return int(v) if v is not None else default


def env_str(key: str, default: str = "") -> str:
    return os.environ.get(key, default)


# ─── USRP framing ─────────────────────────────────────────────────
def usrp_pack_header(
    seq: int, memory: int = 0, keyup: int = 0,
    talkgroup: int = 0, frame_type: int = USRP_TYPE_VOICE,
    mpx: int = 0, reserved: int = 0,
) -> bytes:
    """USRP packet header — 32 bytes big-endian."""
    return struct.pack(
        ">4sIIIIIII",
        USRP_MAGIC, seq, memory, keyup,
        talkgroup, frame_type, mpx, reserved,
    )


def usrp_unpack_header(buf: bytes) -> Optional[dict]:
    """Return {seq, keyup, tg, type, ...} or None if not a USRP frame."""
    if len(buf) < USRP_HEADER_LEN or buf[:4] != USRP_MAGIC:
        return None
    magic, seq, memory, keyup, tg, ftype, mpx, reserved = struct.unpack(
        ">4sIIIIIII", buf[:USRP_HEADER_LEN]
    )
    return {
        "seq": seq, "memory": memory, "keyup": keyup,
        "talkgroup": tg, "type": ftype, "mpx": mpx,
        "reserved": reserved,
    }


# ─── TTS — Piper ──────────────────────────────────────────────────
class PiperTTS:
    """Phase 73m — synthesize text to 8 kHz 16-bit PCM via Piper +
    ffmpeg resample. Piper outputs 22050 Hz by default; we feed the
    raw output through ffmpeg to land on the 8 kHz sample rate USRP
    expects. ffmpeg is a runtime dep but it's tiny and already on
    most Debian installs.
    """

    def __init__(self, piper_bin: str, voice_path: str,
                 ffmpeg_bin: str = "ffmpeg",
                 source_rate: int = 22050) -> None:
        self.piper_bin = piper_bin
        self.voice_path = voice_path
        self.ffmpeg_bin = ffmpeg_bin
        self.source_rate = source_rate

    def synthesize(self, text: str) -> bytes:
        """Return 8 kHz 16-bit mono PCM bytes for `text`."""
        if not text:
            return b""
        # Step 1: piper -> raw PCM at source_rate, mono, s16le
        piper_proc = subprocess.run(
            [self.piper_bin, "-m", self.voice_path, "--output-raw"],
            input=text.encode("utf-8"),
            capture_output=True, check=True, timeout=30,
        )
        raw_in = piper_proc.stdout
        if not raw_in:
            return b""
        # Step 2: ffmpeg -> 8 kHz
        ff = subprocess.run(
            [
                self.ffmpeg_bin, "-loglevel", "error",
                "-f", "s16le", "-ar", str(self.source_rate), "-ac", "1",
                "-i", "pipe:0",
                "-f", "s16le", "-ar", str(SAMPLE_RATE), "-ac", "1",
                "pipe:1",
            ],
            input=raw_in,
            capture_output=True, check=True, timeout=15,
        )
        return ff.stdout


# ─── STT — Vosk ───────────────────────────────────────────────────
class VoskSTT:
    """Phase 73n — transcribe 8 kHz 16-bit mono PCM via Vosk. Loads the
    model once at construction; per-call cost is one KaldiRecognizer
    instance plus the AcceptWaveform/FinalResult pair. Returns a
    {text, partials, engine} dict so the bridge can attach it to the
    call record before posting to api/dmr-ingest.php.

    Vosk is the lighter of the two STT options — fast enough for
    streaming partials, accurate enough for short tactical radio
    traffic. faster-whisper can be slotted in later for high-stakes
    archival transcription.

    All published Vosk English models run at 16 kHz internally, so we
    upsample the bridge's 8 kHz USRP PCM with ffmpeg before feeding it
    to KaldiRecognizer. ffmpeg is already a dep (used by PiperTTS for
    the inverse downsample), so this adds zero extra packages.
    """

    VOSK_RATE = 16000

    def __init__(self, model_path: str, ffmpeg_bin: str = "ffmpeg") -> None:
        # Import is lazy so a bridge without DMR_VOSK_MODEL set doesn't
        # need vosk on the venv.
        from vosk import Model, KaldiRecognizer, SetLogLevel
        SetLogLevel(-1)  # silence vosk's chatty default logging
        self._Model = Model
        self._KaldiRecognizer = KaldiRecognizer
        self.model_path = model_path
        self.ffmpeg_bin = ffmpeg_bin
        self.model = Model(model_path)

    def _upsample(self, pcm_bytes: bytes) -> bytes:
        ff = subprocess.run(
            [
                self.ffmpeg_bin, "-loglevel", "error",
                "-f", "s16le", "-ar", str(SAMPLE_RATE), "-ac", "1",
                "-i", "pipe:0",
                "-f", "s16le", "-ar", str(self.VOSK_RATE), "-ac", "1",
                "pipe:1",
            ],
            input=pcm_bytes,
            capture_output=True, check=True, timeout=10,
        )
        return ff.stdout

    def transcribe(self, pcm_bytes: bytes) -> dict:
        """Return {text, partials, engine}. Empty text on silence."""
        if not pcm_bytes:
            return {"text": "", "partials": [], "engine": "vosk"}
        pcm_16k = self._upsample(pcm_bytes)
        rec = self._KaldiRecognizer(self.model, self.VOSK_RATE)
        rec.SetWords(True)
        partials: list[str] = []
        # Feed in 4000-sample (8000-byte) chunks — vosk's documented
        # sweet spot for streaming.
        CHUNK = 8000
        offset = 0
        while offset < len(pcm_16k):
            chunk = pcm_16k[offset:offset + CHUNK]
            offset += CHUNK
            if rec.AcceptWaveform(chunk):
                try:
                    res = json.loads(rec.Result())
                    if res.get("text"):
                        partials.append(res["text"])
                except (json.JSONDecodeError, ValueError):
                    pass
        try:
            final = json.loads(rec.FinalResult())
        except (json.JSONDecodeError, ValueError):
            final = {}
        text = (final.get("text") or "").strip()
        if not text and partials:
            text = " ".join(partials).strip()
        return {
            "text": text,
            "partials": partials,
            "engine": "vosk",
        }


# ─── STT — faster-whisper ────────────────────────────────────────
class WhisperSTT:
    """Phase 80a — transcribe finalised calls via faster-whisper.

    Tradeoff vs. Vosk: whisper is higher accuracy, especially on noisy
    radio audio and on names/numbers/callsigns. The cost is ~3-10× the
    CPU per call and roughly twice the memory footprint depending on
    model size. Recommended pattern: keep Vosk as the primary STT so
    the dispatcher sees a transcript immediately; if WhisperSTT is also
    configured, the bridge runs it as a secondary pass and stores the
    higher-quality transcript on the dmr_messages row. The Vosk result
    stays available in transcript_partials for comparison.

    faster-whisper accepts 16 kHz mono float32 or int16. We feed it the
    already-upsampled 16 kHz PCM that VoskSTT produced rather than
    paying for the upsample twice.

    Models are downloaded on first use to ~/.cache/huggingface/. For
    air-gapped installs, pre-stage with `huggingface-cli download
    Systran/faster-whisper-base` and set HF_HOME pointing at the cache.
    """

    def __init__(self, model_name: str = "base", ffmpeg_bin: str = "ffmpeg",
                 compute_type: str = "int8") -> None:
        # Lazy import so a bridge without DMR_WHISPER_MODEL set doesn't
        # need faster-whisper on the venv.
        from faster_whisper import WhisperModel
        self._WhisperModel = WhisperModel
        self.model_name = model_name
        self.ffmpeg_bin = ffmpeg_bin
        self.compute_type = compute_type
        # int8 keeps the memory footprint reasonable on the 4 GB
        # dvswitch-01 VM; float16 / int8_float16 are options on bigger
        # boxes. CPU device is correct for the dvswitch-01 spec; no GPU.
        self.model = WhisperModel(model_name, device="cpu",
                                  compute_type=compute_type)

    def _upsample(self, pcm_bytes: bytes) -> bytes:
        """8 kHz mono int16 PCM → 16 kHz mono int16 PCM via ffmpeg."""
        ff = subprocess.run(
            [
                self.ffmpeg_bin, "-loglevel", "error",
                "-f", "s16le", "-ar", str(SAMPLE_RATE), "-ac", "1",
                "-i", "pipe:0",
                "-f", "s16le", "-ar", "16000", "-ac", "1",
                "pipe:1",
            ],
            input=pcm_bytes,
            capture_output=True, check=True, timeout=10,
        )
        return ff.stdout

    def transcribe(self, pcm_bytes: bytes, pcm_16k: Optional[bytes] = None) -> dict:
        """Return {text, partials, engine}. Empty text on silence.

        Accepts either the original 8 kHz PCM (and upsamples) OR a
        pre-upsampled 16 kHz blob if VoskSTT already did the work.
        """
        if not pcm_bytes and not pcm_16k:
            return {"text": "", "partials": [], "engine": "faster-whisper"}
        if pcm_16k is None:
            pcm_16k = self._upsample(pcm_bytes)
        # faster-whisper wants a numpy array. Convert from int16.
        import numpy as np
        samples = np.frombuffer(pcm_16k, dtype=np.int16).astype(np.float32) / 32768.0
        segments, _info = self.model.transcribe(
            samples,
            language="en",
            beam_size=1,           # greedy decode keeps latency bounded
            vad_filter=True,       # skip pure silence
            without_timestamps=True,
        )
        parts: list[str] = []
        for seg in segments:
            text = (seg.text or "").strip()
            if text:
                parts.append(text)
        full = " ".join(parts).strip()
        return {
            "text": full,
            "partials": parts,
            "engine": "faster-whisper:" + self.model_name,
        }


# ─── Bridge core ──────────────────────────────────────────────────
class DVSwitchBridge:
    """One bridge instance per linked talkgroup."""

    def __init__(
        self,
        instance: str,
        listen_port: int,
        send_port: int,
        send_host: str = "127.0.0.1",
        audit_path: Optional[str] = None,
        ingest_url: Optional[str] = None,
        ingest_token: Optional[str] = None,
        tts: Optional["PiperTTS"] = None,
        stt: Optional["VoskSTT"] = None,
        whisper_stt: Optional["WhisperSTT"] = None,
        recordings_dir: Optional[str] = None,
        recording_retention_hours: int = 168,
    ) -> None:
        self.instance = instance
        self.listen_port = listen_port
        self.send_port = send_port
        self.send_host = send_host
        self.audit_path = audit_path
        # Phase 73l — TicketsCAD ingest endpoint (api/dmr-ingest.php).
        # When set, every finalised call is POSTed there with the
        # channel's bearer token so dmr_messages stays in sync.
        self.ingest_url = ingest_url
        self.ingest_token = ingest_token
        self.tts = tts
        self.stt = stt
        # Phase 80a — optional secondary STT for higher-accuracy
        # transcripts. When configured, runs after Vosk and overrides
        # transcript on the call record; Vosk output stays in
        # transcript_partials for comparison.
        self.whisper_stt = whisper_stt
        # Phase 77b — raw audio retention for DVR-style dispatcher
        # playback. Each finalised RX call is written as a single-channel
        # 8 kHz 16-bit WAV under <recordings_dir>/<YYYY>/<MM>/<DD>/. A
        # background cleanup thread prunes files older than
        # recording_retention_hours. Disable by passing None.
        self.recordings_dir = recordings_dir
        self.recording_retention_hours = recording_retention_hours
        self._retention_thread: Optional[threading.Thread] = None
        # Phase 77c — preempt active RX when TX is requested. The
        # BrandMeister network rejects this in practice; the flag is
        # plumbed here so a future hotspot / local repeater mode can
        # honour it by sending a key-down + grace window before keying
        # TX. send_voice_burst respects the flag at the seam.
        self.preempt_active_rx = False
        self.running = False
        self.recent_calls: list[dict] = []
        self.recent_calls_max = 50
        self.tx_seq = 0
        self._current_rx: Optional[dict] = None
        self._sock: Optional[socket.socket] = None
        self._tx_sock: Optional[socket.socket] = None
        self._rx_thread: Optional[threading.Thread] = None

    # ── lifecycle ────────────────────────────────────────────────
    def start(self) -> None:
        if self.running:
            return
        self._sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self._sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._sock.bind(("0.0.0.0", self.listen_port))
        self._sock.settimeout(0.5)

        self._tx_sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self._tx_sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)

        self.running = True
        self._rx_thread = threading.Thread(
            target=self._rx_loop, name=f"dvswitch-rx-{self.instance}",
            daemon=True,
        )
        self._rx_thread.start()
        # Phase 77b — retention sweep runs hourly; only useful if
        # recordings_dir is configured.
        if self.recordings_dir:
            os.makedirs(self.recordings_dir, exist_ok=True)
            self._retention_thread = threading.Thread(
                target=self._retention_loop,
                name=f"dvswitch-retain-{self.instance}",
                daemon=True,
            )
            self._retention_thread.start()
        LOG.info(
            "Bridge %s started: listen=%d send=%s:%d%s",
            self.instance, self.listen_port, self.send_host, self.send_port,
            (" recordings=" + self.recordings_dir) if self.recordings_dir else "",
        )

    def stop(self) -> None:
        self.running = False
        if self._sock:
            try:
                self._sock.close()
            except OSError:
                pass
            self._sock = None
        if self._tx_sock:
            try:
                self._tx_sock.close()
            except OSError:
                pass
            self._tx_sock = None

    # ── rx ───────────────────────────────────────────────────────
    def _rx_loop(self) -> None:
        """Receive USRP frames, group consecutive voice frames into a
        call, log on key-up/key-down. STT engine plugs in here later."""
        sample_count = 0
        first_voice_at: Optional[float] = None
        while self.running:
            try:
                data, addr = self._sock.recvfrom(4096)
            except socket.timeout:
                if self._current_rx and time.time() - self._current_rx["last_voice_at"] > 1.5:
                    self._finalize_call()
                continue
            except OSError:
                break

            hdr = usrp_unpack_header(data)
            if hdr is None:
                continue

            now = time.time()
            ftype = hdr["type"]
            keyup = hdr["keyup"]

            if ftype == USRP_TYPE_VOICE:
                if self._current_rx is None or self._current_rx.get("ended"):
                    self._current_rx = {
                        "started_at": now, "last_voice_at": now,
                        "talkgroup": hdr["talkgroup"], "from": addr[0],
                        "samples": 0, "ended": False,
                        # Phase 73n — accumulate raw PCM so the STT
                        # engine has the full call to transcribe at
                        # finalize. Capped at ~30 s (240 KB) so a
                        # stuck PTT can't OOM the bridge.
                        "pcm": bytearray(),
                    }
                    first_voice_at = now
                    sample_count = 0
                voice = data[USRP_HEADER_LEN:USRP_HEADER_LEN + USRP_VOICE_LEN]
                sample_count += len(voice) // 2  # 16-bit samples
                self._current_rx["last_voice_at"] = now
                self._current_rx["samples"] = sample_count
                if len(self._current_rx["pcm"]) < SAMPLE_RATE * 2 * 30:
                    self._current_rx["pcm"].extend(voice)
                if keyup == 0:
                    # Key-down on this frame marks call end.
                    self._finalize_call()
            elif ftype == USRP_TYPE_PING:
                LOG.debug("ping from %s tg=%d", addr[0], hdr["talkgroup"])

    def _finalize_call(self) -> None:
        if not self._current_rx:
            return
        rx = self._current_rx
        if rx.get("ended"):
            return
        rx["ended"] = True
        rx["ended_at"] = time.time()
        duration_ms = int(
            (rx["ended_at"] - rx["started_at"]) * 1000
        ) if rx["ended_at"] > rx["started_at"] else int(rx["samples"] / SAMPLE_RATE * 1000)
        call_record = {
            "instance": self.instance,
            "direction": "rx",
            "talkgroup": rx["talkgroup"],
            "from": rx["from"],
            "started_at": time.strftime("%Y-%m-%d %H:%M:%S",
                                       time.localtime(rx["started_at"])),
            "ended_at": time.strftime("%Y-%m-%d %H:%M:%S",
                                     time.localtime(rx["ended_at"])),
            "duration_ms": duration_ms,
            "samples": rx["samples"],
        }
        # Phase 73n — transcribe before publishing the call record so
        # the JSONL audit + ingest POST carry the transcript in one
        # shot. STT runs synchronously inline; with the vosk-small
        # model on dvswitch-01 a 10 s call transcribes in ~200 ms,
        # well under the keyup gap between back-to-back transmissions.
        pcm = bytes(rx.get("pcm") or b"")
        # Phase 77b — save raw audio as WAV for DVR-style playback. The
        # dispatcher panel reads dmr_messages.audio_path + audio_format
        # to render an HTML5 audio player; this is where the file is
        # actually written. Single-channel 8 kHz 16-bit matches the USRP
        # voice stream we already accumulated.
        if self.recordings_dir and pcm:
            try:
                audio_path = self._write_call_wav(rx, pcm)
                if audio_path:
                    # Phase 82 — store the path RELATIVE to recordings_dir
                    # (not the absolute filesystem path) so the ingest
                    # endpoint can validate + sanitise it and the playback
                    # proxy can resolve it without leaking absolute paths
                    # through the database.
                    rel = os.path.relpath(audio_path, self.recordings_dir)
                    # Always use forward slashes regardless of host OS so
                    # the API contract is platform-independent.
                    rel = rel.replace(os.sep, "/")
                    call_record["audio_path"] = rel
                    call_record["audio_format"] = "wav-mono-8khz-16bit"
            except OSError as e:
                LOG.warning("audio save failed for call tg=%d: %s",
                            rx["talkgroup"], e)
        vosk_text = ""
        vosk_partials: list[str] = []
        if self.stt and pcm:
            try:
                stt_out = self.stt.transcribe(pcm)
                if stt_out.get("text"):
                    vosk_text = stt_out["text"]
                    vosk_partials = stt_out.get("partials") or []
                    call_record["transcript"] = vosk_text
                    call_record["transcript_engine"] = stt_out.get("engine", "vosk")
                    if vosk_partials:
                        call_record["transcript_partials"] = json.dumps(vosk_partials)
            except Exception as e:  # never crash the bridge on STT errors
                LOG.warning("STT (vosk) failed on call tg=%d: %s", rx["talkgroup"], e)
                call_record["error"] = "stt_failed:" + str(e)[:120]
        # Phase 80a — secondary high-accuracy transcript via faster-whisper.
        # Runs synchronously after Vosk; on dvswitch-01 with the 'base'
        # model + int8 a 10-second call adds ~1-2 seconds of latency.
        # That's still well inside the keyup gap on most channels and
        # the dispatcher's view only needs the BEST transcript to be on
        # the dmr_messages row by the time they look.
        if self.whisper_stt and pcm:
            try:
                wh_out = self.whisper_stt.transcribe(pcm)
                if wh_out.get("text"):
                    # Override the displayed transcript with the higher-
                    # accuracy whisper result; keep Vosk visible in the
                    # partials field for side-by-side comparison.
                    call_record["transcript"] = wh_out["text"]
                    call_record["transcript_engine"] = wh_out.get("engine", "faster-whisper")
                    secondary = list(vosk_partials)
                    if vosk_text:
                        secondary.append("[vosk] " + vosk_text)
                    if secondary:
                        call_record["transcript_partials"] = json.dumps(secondary)
            except Exception as e:
                LOG.warning("STT (whisper) failed on call tg=%d: %s",
                            rx["talkgroup"], e)
                # Don't overwrite a successful Vosk transcript — only
                # set the whisper error in the JSONL audit.
                call_record["whisper_error"] = "whisper_failed:" + str(e)[:120]
        self._track_call(call_record)
        LOG.info(
            "RX call tg=%d from=%s %dms (%d samples)%s",
            rx["talkgroup"], rx["from"], duration_ms, rx["samples"],
            (" transcript='" + call_record["transcript"][:60] + "'") if call_record.get("transcript") else "",
        )
        self._current_rx = None

    # ── tx ───────────────────────────────────────────────────────
    def send_voice_burst(self, talkgroup: int, pcm_bytes: bytes) -> int:
        """Send a contiguous PCM stream as USRP voice frames. Returns
        the number of frames emitted. TTS pipeline calls this with
        synthesised audio (Phase 73l).

        Phase 77c: if an RX call is currently active and
        preempt_active_rx is False (BrandMeister default), the burst is
        rejected with a RuntimeError that the HTTP handler turns into
        a 409. On hotspot/local modes where preempt_active_rx is True,
        the burst proceeds even mid-RX — the radio operator at the
        other end will hear our TX cut in.
        """
        if not self._tx_sock:
            raise RuntimeError("bridge not started")
        active_rx = (self._current_rx is not None
                     and not self._current_rx.get("ended"))
        if active_rx and not self.preempt_active_rx:
            raise RuntimeError(
                "rx_busy: a receive call is in progress and preemption "
                "is disabled on this bridge"
            )
        frames = 0
        offset = 0
        # Phase 83 — pace USRP frame emission at realtime audio rate.
        # Each USRP voice frame carries 20 ms of 8 kHz 16-bit PCM (160
        # samples × 2 bytes = 320 bytes). The downstream Analog_Bridge
        # → MMDVM_Bridge → BrandMeister chain assumes each incoming
        # USRP frame represents one 20 ms slice of audio in real time.
        # Sending frames in a tight loop (no sleep) makes the entire
        # transmission arrive in microseconds; Analog_Bridge then emits
        # DMR voice bursts at the rate it received PCM, which is 30×
        # faster than the 60 ms-per-burst DMR Tier II expects. The
        # result: BrandMeister accepts the call header (LH populates),
        # then drops every voice burst silently because the stream is
        # malformed at the wire level. Pacing fixes this.
        FRAME_INTERVAL_S = USRP_VOICE_LEN / 2 / SAMPLE_RATE
        next_send = time.monotonic()
        # Pre-roll keyup=1 frame with silence to grab the PTT.
        self._send_frame(talkgroup, b"\x00" * USRP_VOICE_LEN, keyup=1)
        frames += 1
        next_send += FRAME_INTERVAL_S
        while offset < len(pcm_bytes):
            chunk = pcm_bytes[offset:offset + USRP_VOICE_LEN]
            if len(chunk) < USRP_VOICE_LEN:
                chunk = chunk + b"\x00" * (USRP_VOICE_LEN - len(chunk))
            # Sleep until the next scheduled send. Using monotonic +
            # absolute schedule rather than incremental sleep avoids
            # drift across long transmissions.
            now = time.monotonic()
            if next_send > now:
                time.sleep(next_send - now)
            self._send_frame(talkgroup, chunk, keyup=1)
            offset += USRP_VOICE_LEN
            frames += 1
            next_send += FRAME_INTERVAL_S
        # Tail: keyup=0 to release PTT.
        now = time.monotonic()
        if next_send > now:
            time.sleep(next_send - now)
        self._send_frame(talkgroup, b"\x00" * USRP_VOICE_LEN, keyup=0)
        frames += 1
        record = {
            "instance": self.instance,
            "direction": "tx", "talkgroup": talkgroup,
            "started_at": time.strftime("%Y-%m-%d %H:%M:%S"),
            "duration_ms": int(len(pcm_bytes) / 2 / SAMPLE_RATE * 1000),
            "samples": len(pcm_bytes) // 2,
        }
        self._track_call(record)
        LOG.info("TX burst tg=%d frames=%d samples=%d",
                 talkgroup, frames, record["samples"])
        return frames

    def _send_frame(self, tg: int, payload: bytes, keyup: int = 1) -> None:
        self.tx_seq = (self.tx_seq + 1) & 0xFFFFFFFF
        hdr = usrp_pack_header(self.tx_seq, keyup=keyup,
                               talkgroup=tg, frame_type=USRP_TYPE_VOICE)
        self._tx_sock.sendto(hdr + payload, (self.send_host, self.send_port))

    # ── audit log ────────────────────────────────────────────────
    def _track_call(self, record: dict) -> None:
        self.recent_calls.insert(0, record)
        if len(self.recent_calls) > self.recent_calls_max:
            self.recent_calls = self.recent_calls[:self.recent_calls_max]
        if self.audit_path:
            try:
                with open(self.audit_path, "a", encoding="utf-8") as fh:
                    fh.write(json.dumps(record) + "\n")
            except OSError as e:
                LOG.warning("audit write failed: %s", e)
        # Phase 73l — POST to TicketsCAD ingest. Best-effort; failures
        # only get logged so a TicketsCAD outage doesn't crash the
        # bridge. The record has already landed in the local JSONL
        # for replay if we ever need it.
        if self.ingest_url and self.ingest_token:
            threading.Thread(
                target=self._post_ingest,
                args=(record,),
                name=f"dvswitch-ingest-{self.instance}",
                daemon=True,
            ).start()

    def _post_ingest(self, record: dict) -> None:
        # Translate bridge.py's internal call record into the schema
        # dmr-ingest.php expects (see specs/dvswitch-proxy-2026-06/
        # spec.md for the field map).
        body = {
            "label":         self.instance,
            "direction":     record.get("direction", "rx"),
            "started_at":    record.get("started_at"),
            "ended_at":      record.get("ended_at"),
            "duration_ms":   record.get("duration_ms"),
        }
        # Phase 82 — Analog_Bridge's USRP path does not always propagate
        # the source DMR talkgroup ID; we frequently see tg=0 in the
        # USRP header even when the upstream call was on a valid TG.
        # Only forward the talkgroup field when it's non-zero so the
        # ingest endpoint falls back to dmr_channels.talkgroup. The
        # channel's configured TG is the right default for any
        # single-talkgroup bridge instance.
        tg_from_usrp = record.get("talkgroup")
        if tg_from_usrp is not None and int(tg_from_usrp) > 0:
            body["talkgroup"] = tg_from_usrp
        if "transcript" in record:           body["transcript"] = record["transcript"]
        if "transcript_engine" in record:    body["transcript_engine"] = record["transcript_engine"]
        if "transcript_partials" in record:  body["transcript_partials"] = record["transcript_partials"]
        if "error" in record:                body["error"] = record["error"]
        if "audio_path" in record:           body["audio_path"] = record["audio_path"]
        if "audio_format" in record:      body["audio_format"] = record["audio_format"]
        if "radio_id" in record:          body["radio_id"] = record["radio_id"]
        if "radio_callsign" in record:    body["radio_callsign"] = record["radio_callsign"]

        data = json.dumps(body).encode("utf-8")
        req = urllib.request.Request(
            self.ingest_url,
            data=data,
            method="POST",
            headers={
                "Content-Type":  "application/json",
                "Authorization": "Bearer " + self.ingest_token,
                "User-Agent":    "ticketscad-dvswitch/1.0 (" + self.instance + ")",
            },
        )
        try:
            with urllib.request.urlopen(req, timeout=6) as resp:
                if resp.status >= 300:
                    LOG.warning(
                        "ingest POST %s returned status %d",
                        self.ingest_url, resp.status,
                    )
        except urllib.error.HTTPError as e:
            LOG.warning("ingest HTTPError %s: %s", e.code, e.read()[:200])
        except (urllib.error.URLError, TimeoutError, OSError) as e:
            LOG.warning("ingest network error: %s", e)
        except Exception as e:  # belt-and-braces; never crash bridge
            LOG.exception("ingest unexpected failure: %s", e)

    # ── audio recording (Phase 77b) ──────────────────────────────
    def _write_call_wav(self, rx: dict, pcm: bytes) -> Optional[str]:
        """Write a finalised RX call's PCM to a WAV file.

        Returns the absolute filesystem path written, or None if the
        recording directory isn't configured. The caller stores this
        path in dmr_messages.audio_path; api/dmr-audio.php streams it
        back with HTTP Range support for the dispatcher's audio player.
        """
        if not self.recordings_dir:
            return None
        started = rx.get("started_at") or time.time()
        when = time.localtime(started)
        day_dir = os.path.join(
            self.recordings_dir,
            self.instance,
            time.strftime("%Y", when),
            time.strftime("%m", when),
            time.strftime("%d", when),
        )
        os.makedirs(day_dir, exist_ok=True)
        # File name carries enough metadata to be useful even outside the
        # database — sortable, no collisions in a 1 ms window.
        name = "{stamp}-tg{tg}-{ms}ms.wav".format(
            stamp=time.strftime("%H%M%S", when),
            tg=int(rx.get("talkgroup", 0)),
            ms=int(rx.get("samples", 0) / SAMPLE_RATE * 1000),
        )
        # Include the start millisecond to avoid same-second collisions
        # on busy talkgroups.
        ms = int((started - int(started)) * 1000)
        name = name.replace(".wav", "-{}.wav".format(ms))
        path = os.path.join(day_dir, name)
        # WAV header for mono 8 kHz 16-bit. wave.open handles framing.
        with wave.open(path, "wb") as wf:
            wf.setnchannels(1)
            wf.setsampwidth(2)
            wf.setframerate(SAMPLE_RATE)
            wf.writeframes(pcm)
        return path

    def _retention_loop(self) -> None:
        """Background sweep — delete WAV files older than the configured
        retention window. Runs hourly; no-ops if recordings_dir unset.
        """
        while self.running:
            try:
                self._sweep_old_recordings()
            except Exception as e:  # never crash the bridge
                LOG.warning("recording sweep failed: %s", e)
            # Sleep in short ticks so shutdown is responsive.
            for _ in range(3600):
                if not self.running:
                    return
                time.sleep(1)

    def _sweep_old_recordings(self) -> None:
        if not self.recordings_dir:
            return
        cutoff = time.time() - (self.recording_retention_hours * 3600)
        removed = 0
        root = os.path.join(self.recordings_dir, self.instance)
        if not os.path.isdir(root):
            return
        for dirpath, _dirs, files in os.walk(root):
            for f in files:
                if not f.endswith(".wav"):
                    continue
                full = os.path.join(dirpath, f)
                try:
                    if os.path.getmtime(full) < cutoff:
                        os.remove(full)
                        removed += 1
                except OSError:
                    pass
        if removed:
            LOG.info("recording sweep removed %d file(s) older than %dh",
                     removed, self.recording_retention_hours)


# ─── HTTP control surface ─────────────────────────────────────────
class ControlHandler(BaseHTTPRequestHandler):
    """Bearer-auth'd HTTP control endpoint. Matches the Phase 35A
    meshbridge pattern — minimal admin surface, JSON in/out."""

    bridge: DVSwitchBridge = None  # set by serve_http()
    bearer: str = ""

    def _auth_ok(self) -> bool:
        auth = self.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            return False
        return auth[7:].strip() == self.bearer

    def _json(self, code: int, body: dict) -> None:
        payload = json.dumps(body).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def do_GET(self) -> None:
        if not self._auth_ok():
            return self._json(401, {"error": "unauthorized"})
        if self.path == "/health":
            return self._json(200, {
                "ok": True,
                "instance": self.bridge.instance,
                "running": self.bridge.running,
                "listen_port": self.bridge.listen_port,
                "send_port": self.bridge.send_port,
                "recent_calls": len(self.bridge.recent_calls),
                "uptime_started": getattr(self.bridge, "_start_ts", None),
            })
        if self.path == "/calls/recent":
            return self._json(200, {"calls": self.bridge.recent_calls})
        if self.path == "/config":
            return self._json(200, {
                "instance": self.bridge.instance,
                "listen_port": self.bridge.listen_port,
                "send_port": self.bridge.send_port,
                "send_host": self.bridge.send_host,
                "recordings_dir": self.bridge.recordings_dir,
                "recording_retention_hours": self.bridge.recording_retention_hours,
            })
        if self.path.startswith("/recording"):
            # Phase 77b — stream a WAV recording for dispatcher playback.
            # Supports HTTP Range so HTML5 audio can scrub long files
            # without re-fetching from byte 0 on every seek.
            return self._serve_recording()
        return self._json(404, {"error": "not found"})

    def _serve_recording(self) -> None:
        """GET /recording?path=<abs_path_under_recordings_dir>

        Streams the WAV with Range support. Containment check ensures
        we only ever serve files inside the configured recordings_dir
        — the path query param is not allowed to escape via ../ etc.
        """
        from urllib.parse import urlparse, parse_qs
        q = parse_qs(urlparse(self.path).query)
        rel = (q.get("path") or [""])[0]
        if not rel or not self.bridge.recordings_dir:
            return self._json(404, {"error": "no recording"})
        # The stored audio_path is absolute (matches what bridge.py
        # wrote). We accept either the absolute path OR a path relative
        # to recordings_dir. realpath + containment check rejects
        # traversal attempts.
        if os.path.isabs(rel):
            target = os.path.realpath(rel)
        else:
            target = os.path.realpath(os.path.join(self.bridge.recordings_dir, rel))
        root = os.path.realpath(self.bridge.recordings_dir)
        if not target.startswith(root + os.sep) and target != root:
            return self._json(403, {"error": "path outside recordings_dir"})
        if not os.path.isfile(target):
            return self._json(404, {"error": "recording not found"})

        size = os.path.getsize(target)
        rng = self.headers.get("Range", "")
        start = 0
        end = size - 1
        status = 200
        if rng.startswith("bytes="):
            try:
                spec = rng[6:].split(",")[0]
                s, e = spec.split("-", 1)
                if s.strip():
                    start = int(s)
                if e.strip():
                    end = int(e)
                if start < 0 or end >= size or start > end:
                    raise ValueError("range out of bounds")
                status = 206
            except (ValueError, TypeError):
                self.send_response(416)
                self.send_header("Content-Range", "bytes */%d" % size)
                self.end_headers()
                return

        length = end - start + 1
        self.send_response(status)
        self.send_header("Content-Type", "audio/wav")
        self.send_header("Content-Length", str(length))
        self.send_header("Accept-Ranges", "bytes")
        if status == 206:
            self.send_header("Content-Range",
                             "bytes %d-%d/%d" % (start, end, size))
        self.end_headers()
        with open(target, "rb") as fh:
            fh.seek(start)
            remaining = length
            while remaining > 0:
                chunk = fh.read(min(65536, remaining))
                if not chunk:
                    break
                self.wfile.write(chunk)
                remaining -= len(chunk)

    def do_POST(self) -> None:
        if not self._auth_ok():
            return self._json(401, {"error": "unauthorized"})
        length = int(self.headers.get("Content-Length", "0") or "0")
        try:
            body = json.loads(self.rfile.read(length).decode()) if length else {}
        except json.JSONDecodeError:
            return self._json(400, {"error": "bad json"})
        if self.path == "/tx/test":
            # 1-second 1kHz beep, useful for confirming the bridge can
            # push PCM end-to-end without involving TTS yet.
            tg = int(body.get("talkgroup", 0))
            duration_s = float(body.get("duration_s", 1.0))
            pcm = self._make_tone_pcm(1000.0, duration_s)
            try:
                frames = self.bridge.send_voice_burst(tg, pcm)
                return self._json(200, {"ok": True, "frames": frames})
            except RuntimeError as e:
                # Phase 77c — rx_busy gets a 409 so the UI can show a
                # meaningful "channel in use" message instead of a 500.
                msg = str(e)
                if msg.startswith("rx_busy"):
                    return self._json(409, {"error": msg})
                LOG.exception("tx/test failed")
                return self._json(500, {"error": msg})
            except Exception as e:
                LOG.exception("tx/test failed")
                return self._json(500, {"error": str(e)})
        if self.path == "/tx/text":
            # Phase 73m — synthesise via Piper, downsample to 8 kHz, send.
            if not self.bridge.tts:
                return self._json(503, {"error": "TTS not configured"})
            text = (body.get("text") or "").strip()
            if not text:
                return self._json(400, {"error": "text required"})
            tg = int(body.get("talkgroup", 0))
            try:
                pcm = self.bridge.tts.synthesize(text)
                if not pcm:
                    return self._json(500, {"error": "tts produced no audio"})
                frames = self.bridge.send_voice_burst(tg, pcm)
                # Best-effort ingest record for the tx
                self.bridge._track_call({
                    "instance": self.bridge.instance,
                    "direction": "tx",
                    "talkgroup": tg,
                    "started_at": time.strftime("%Y-%m-%d %H:%M:%S"),
                    "duration_ms": int(len(pcm) / 2 / SAMPLE_RATE * 1000),
                    "transcript": text,
                    "transcript_engine": "piper",
                })
                return self._json(200, {
                    "ok": True, "frames": frames,
                    "samples": len(pcm) // 2,
                    "duration_ms": int(len(pcm) / 2 / SAMPLE_RATE * 1000),
                })
            except RuntimeError as e:
                msg = str(e)
                if msg.startswith("rx_busy"):
                    return self._json(409, {"error": msg})
                LOG.exception("tx/text failed")
                return self._json(500, {"error": msg})
            except subprocess.CalledProcessError as e:
                LOG.exception("tx/text synthesis failed")
                return self._json(500, {
                    "error": "tts pipeline failed",
                    "stderr": (e.stderr or b"").decode("utf-8", "replace")[:500],
                })
            except Exception as e:
                LOG.exception("tx/text failed")
                return self._json(500, {"error": str(e)})
        return self._json(404, {"error": "not found"})

    def log_message(self, fmt: str, *args) -> None:  # noqa: D401
        LOG.debug("http %s", fmt % args)

    @staticmethod
    def _make_tone_pcm(freq_hz: float, duration_s: float) -> bytes:
        """Generate 16-bit PCM tone for the /tx/test endpoint."""
        import math
        n = int(duration_s * SAMPLE_RATE)
        amplitude = 8000
        out = bytearray()
        for i in range(n):
            sample = int(amplitude * math.sin(2 * math.pi * freq_hz * i / SAMPLE_RATE))
            out.extend(struct.pack("<h", sample))
        return bytes(out)


def serve_http(bridge: DVSwitchBridge, port: int, bearer: str) -> ThreadingHTTPServer:
    ControlHandler.bridge = bridge
    ControlHandler.bearer = bearer
    bridge._start_ts = int(time.time())
    server = ThreadingHTTPServer(("0.0.0.0", port), ControlHandler)
    thread = threading.Thread(
        target=server.serve_forever,
        name=f"dvswitch-http-{bridge.instance}",
        daemon=True,
    )
    thread.start()
    LOG.info("HTTP control listening on :%d (instance=%s)", port, bridge.instance)
    return server


# ─── Entry point ──────────────────────────────────────────────────
def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--env", help="env file path", default=None)
    parser.add_argument("--instance", help="instance label", default=None)
    args = parser.parse_args()

    if args.env and os.path.exists(args.env):
        # Trivial env-file loader (KEY=VALUE per line, # comments).
        with open(args.env, encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                if "=" in line:
                    k, v = line.split("=", 1)
                    os.environ.setdefault(k.strip(), v.strip())

    instance = args.instance or env_str("DMR_INSTANCE", "default")
    listen_port = env_int("DMR_USRP_LISTEN_PORT", 33001)
    send_port = env_int("DMR_USRP_SEND_PORT", 33000)
    send_host = env_str("DMR_USRP_SEND_HOST", "127.0.0.1")
    http_port = env_int("DMR_HTTP_PORT", 18091)
    bearer = env_str("DMR_BEARER_TOKEN", "")
    audit_dir = env_str("DMR_AUDIT_DIR", "/var/log/ticketscad-dvswitch")
    audit_path = os.path.join(audit_dir, f"{instance}.jsonl")
    ingest_url = env_str("DMR_INGEST_URL", "")  # e.g. https://your-server.example.com/api/dmr-ingest.php
    ingest_token = env_str("DMR_INGEST_TOKEN", "")

    # Phase 73m — Piper TTS (optional)
    piper_bin = env_str("DMR_PIPER_BIN", "")
    piper_voice = env_str("DMR_PIPER_VOICE", "")
    ffmpeg_bin = env_str("DMR_FFMPEG_BIN", "ffmpeg")
    tts: Optional[PiperTTS] = None
    if piper_bin and piper_voice:
        if shutil.which(piper_bin) is None and not os.path.exists(piper_bin):
            LOG.warning("DMR_PIPER_BIN '%s' not found — TTS disabled", piper_bin)
        elif not os.path.exists(piper_voice):
            LOG.warning("DMR_PIPER_VOICE '%s' not found — TTS disabled", piper_voice)
        else:
            tts = PiperTTS(piper_bin, piper_voice, ffmpeg_bin)
            LOG.info("Piper TTS ready: voice=%s", os.path.basename(piper_voice))

    # Phase 73n — Vosk STT (optional). Set DMR_VOSK_MODEL to a path
    # like /opt/ticketscad-dvswitch/models/vosk-model-small-en-us-0.15
    # to enable transcripts on RX. Phase 80a adds faster-whisper as a
    # secondary high-accuracy pass; both can be enabled together.
    vosk_model = env_str("DMR_VOSK_MODEL", "")
    stt: Optional[VoskSTT] = None
    if vosk_model:
        if not os.path.isdir(vosk_model):
            LOG.warning("DMR_VOSK_MODEL '%s' not a directory — STT disabled", vosk_model)
        else:
            try:
                stt = VoskSTT(vosk_model, ffmpeg_bin=ffmpeg_bin)
                LOG.info("Vosk STT ready: model=%s", os.path.basename(vosk_model))
            except ImportError:
                LOG.warning("vosk package not installed in venv — STT disabled")
            except Exception as e:
                LOG.warning("Vosk init failed: %s — STT disabled", e)

    # Phase 80a — optional secondary STT via faster-whisper. Set
    # DMR_WHISPER_MODEL to a model name (e.g. "base", "small", "medium").
    # The model is downloaded on first use; DMR_WHISPER_COMPUTE controls
    # quantisation (int8 default, int8_float16 / float16 for bigger boxes).
    whisper_model = env_str("DMR_WHISPER_MODEL", "")
    whisper_compute = env_str("DMR_WHISPER_COMPUTE", "int8")
    whisper_stt: Optional[WhisperSTT] = None
    if whisper_model:
        try:
            whisper_stt = WhisperSTT(whisper_model, ffmpeg_bin=ffmpeg_bin,
                                     compute_type=whisper_compute)
            LOG.info("faster-whisper STT ready: model=%s compute=%s",
                     whisper_model, whisper_compute)
        except ImportError:
            LOG.warning("faster-whisper not installed in venv — secondary STT disabled")
        except Exception as e:
            LOG.warning("faster-whisper init failed: %s — secondary STT disabled", e)

    log_level = env_str("DMR_LOG_LEVEL", "INFO").upper()
    logging.basicConfig(
        level=getattr(logging, log_level, logging.INFO),
        format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
    )

    if not bearer:
        LOG.error("DMR_BEARER_TOKEN must be set")
        raise SystemExit(2)

    os.makedirs(audit_dir, exist_ok=True)

    # Phase 77b — DVR-style raw audio retention. The dispatcher panel
    # can rewind, scrub, and replay finalised calls. Default off; set
    # DMR_RECORDINGS_DIR to enable.
    recordings_dir = env_str("DMR_RECORDINGS_DIR", "")
    retention_hours = env_int("DMR_RECORDING_RETENTION_HOURS", 168)
    # Phase 77c — TX preempts active RX when set true. BrandMeister
    # rejects this; the flag is here for future hotspot/local modes.
    preempt_str = env_str("DMR_PREEMPT_ACTIVE_RX", "false").strip().lower()
    preempt = preempt_str in ("1", "true", "yes", "on")

    bridge = DVSwitchBridge(
        instance=instance,
        listen_port=listen_port, send_port=send_port,
        send_host=send_host, audit_path=audit_path,
        ingest_url=ingest_url or None,
        ingest_token=ingest_token or None,
        tts=tts,
        stt=stt,
        whisper_stt=whisper_stt,
        recordings_dir=recordings_dir or None,
        recording_retention_hours=retention_hours,
    )
    bridge.preempt_active_rx = preempt
    bridge.start()
    server = serve_http(bridge, http_port, bearer)

    def shutdown(_sig, _frame):
        LOG.info("shutting down")
        bridge.stop()
        server.shutdown()

    signal.signal(signal.SIGTERM, shutdown)
    signal.signal(signal.SIGINT, shutdown)

    # Block forever; signal handlers will end it.
    signal.pause()


if __name__ == "__main__":
    main()
