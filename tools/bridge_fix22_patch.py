#!/usr/bin/env python3
"""Phase 85f-7: inject 0.75 s pre-roll silence into the bridge's
tx_text path (TTS-originated TX only). Run as:
    sudo python3 /tmp/bridge_fix22_patch.py
"""
import pathlib

TARGET = pathlib.Path("/opt/ticketscad-dvswitch/hbp_client.py")

OLD = """            pcm = ff.stdout
        except subprocess.CalledProcessError as e:"""

NEW = """            pcm = ff.stdout
            # Phase 85f-7: 0.75 s pre-roll silence so far-end radios,
            # hotspots, and the BrandMeister relay path have time to
            # come up before the first syllable of TTS audio.
            # 0.75 s @ 8 kHz mono s16le = 12000 samples = 24000 bytes.
            # NOT applied to /tx/audio (widget) -- humans provide their
            # own pre-roll by holding PTT before speaking.
            pcm = (b"\\x00" * 24000) + pcm
        except subprocess.CalledProcessError as e:"""

src = TARGET.read_text()
if "Phase 85f-7" in src:
    raise SystemExit("already patched")
if OLD not in src:
    raise SystemExit("anchor not found")
TARGET.write_text(src.replace(OLD, NEW))
print("patched")
