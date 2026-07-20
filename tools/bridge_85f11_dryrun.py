#!/usr/bin/env python3
"""Phase 85f-11: add dry_run support to /tx/text so smoke tests can
verify the TTS+AMBE pipeline without putting audio on-air.

Two edits to /opt/ticketscad-dvswitch/hbp_client.py:

1. HBPClient.tx_text() — accept dry_run param. If true, run Piper +
   ffmpeg + pre-roll silence injection, then RETURN before creating
   the DMRCallTransmitter / calling transmit_pcm. Returns the same
   shape as a real call (packets_sent set to 0, dry_run=True).

2. ControlHandler._do_post_json() /tx/text branch — read dry_run from
   body, pass to tx_text.

Run as: sudo python3 /tmp/bridge_85f11_dryrun.py
"""
import pathlib

TARGET = pathlib.Path("/opt/ticketscad-dvswitch/hbp_client.py")

# Edit 1: tx_text signature + early return in dry-run mode
OLD1 = """        codec = AmbeCodec()
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
            codec.close()"""

NEW1 = """        # Phase 85f-11: dry-run short-circuit. We've already done the
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
            codec.close()"""

# Edit 2: tx_text signature
OLD2 = """    def tx_text(self, text: str, src_id: int, dst_id: int,
                piper_bin: str, piper_voice: str,
                ffmpeg_bin: str = "ffmpeg") -> dict:"""

NEW2 = """    def tx_text(self, text: str, src_id: int, dst_id: int,
                piper_bin: str, piper_voice: str,
                ffmpeg_bin: str = "ffmpeg",
                dry_run: bool = False) -> dict:"""

# Edit 3: ControlHandler dispatcher passes dry_run
OLD3 = """        if self.path == "/tx/text":
            text = (body.get("text") or "").strip()
            if not text:
                return self._json(400, {"error": "text required"})
            tg = int(body.get("talkgroup") or self.default_tg)
            src = int(body.get("src_id") or self.operator_id)
            result = self.client.tx_text(
                text=text, src_id=src, dst_id=tg,
                piper_bin=self.piper_bin,
                piper_voice=self.piper_voice,
                ffmpeg_bin=self.ffmpeg_bin,
            )"""

NEW3 = """        if self.path == "/tx/text":
            text = (body.get("text") or "").strip()
            if not text:
                return self._json(400, {"error": "text required"})
            tg = int(body.get("talkgroup") or self.default_tg)
            src = int(body.get("src_id") or self.operator_id)
            # Phase 85f-11: optional dry_run skips on-air TX so we can
            # verify the TTS+AMBE pipeline without bothering operators.
            dry_run = bool(body.get("dry_run", False))
            result = self.client.tx_text(
                text=text, src_id=src, dst_id=tg,
                piper_bin=self.piper_bin,
                piper_voice=self.piper_voice,
                ffmpeg_bin=self.ffmpeg_bin,
                dry_run=dry_run,
            )"""

src = TARGET.read_text()
if "Phase 85f-11" in src:
    raise SystemExit("already patched")
for label, old, new in [("edit1", OLD1, NEW1), ("edit2", OLD2, NEW2), ("edit3", OLD3, NEW3)]:
    if old not in src:
        raise SystemExit(f"anchor for {label} not found")
    src = src.replace(old, new)
TARGET.write_text(src)
print("patched")
