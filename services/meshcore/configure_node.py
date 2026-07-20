#!/usr/bin/env python3
"""
MeshCore companion-mode node configurator.

Idempotently sets a node's name + radio params on a freshly-flashed Heltec V3
(or any MeshCore companion radio reachable over USB serial). Designed to run
on the bridge VM where /dev/ttyUSB0 lives.

Why this exists: a fresh MeshCore flash boots into EU868 defaults
(869.618 MHz / 62.5 kHz / SF8 / CR5), which is silent on a US mesh.
First boot has to push the right region + a useful node name before the
radio is interoperable.

Usage:
    sudo systemctl stop meshbridge.service        # release the serial port
    sudo python3 configure_node.py \
        --port /dev/ttyUSB0 --name CAD-Bridge-02 --region us
    sudo systemctl start meshbridge.service

Regions (frequencies match the MeshCore-published "Primary Channel" defaults):
    us       910.525 MHz / 250 kHz / SF11 / CR5     # US 915 ISM
    eu868    869.525 MHz / 250 kHz / SF11 / CR5     # EU 868
    eu433    433.000 MHz / 250 kHz / SF11 / CR5     # EU 433
    cn       470.000 MHz / 250 kHz / SF11 / CR5
    in       865.000 MHz / 250 kHz / SF11 / CR5
    au       915.000 MHz / 250 kHz / SF11 / CR5     # AU/NZ 915

After the configuration is applied the radio reboots. The reboot drops the
chip back into bootloader-strap mode, so we use esptool to issue a clean
hard reset that lands in the application. The script then re-opens the
serial port and dumps self_info to confirm the new settings stuck.
"""

import argparse
import asyncio
import subprocess
import sys

REGIONS = {
    "us":    (910.525, 250.0, 11, 5),
    "eu868": (869.525, 250.0, 11, 5),
    "eu433": (433.000, 250.0, 11, 5),
    "cn":    (470.000, 250.0, 11, 5),
    "in":    (865.000, 250.0, 11, 5),
    "au":    (915.000, 250.0, 11, 5),
}


def hard_reset(port: str, chip: str = "esp32s3") -> None:
    # esptool's --after hard-reset issues the proper RTS/DTR sequence to
    # release the bootloader strap and run the app. MeshCore's own reboot()
    # leaves the chip in DOWNLOAD mode otherwise.
    #
    # Use sys.executable (the venv interpreter that imported the meshcore
    # lib that pulled in esptool), not the system python3, which usually
    # doesn't have esptool installed on the bridge VMs.
    subprocess.run(
        [sys.executable, "-m", "esptool",
         "--port", port, "--chip", chip,
         "--after", "hard-reset", "run"],
        check=True, capture_output=True,
    )


async def run(port: str, name: str, region: str, tx_power: int) -> int:
    from meshcore import MeshCore

    if region not in REGIONS:
        print(f"unknown region '{region}'; known: {','.join(REGIONS)}", file=sys.stderr)
        return 2
    freq, bw, sf, cr = REGIONS[region]

    mc = await MeshCore.create_serial(port, 115200)
    if mc is None:
        print(f"FAILED to connect at {port}. Is meshbridge.service running on it?",
              file=sys.stderr)
        return 1

    si = mc.self_info or {}
    print("=== current ===")
    print(f"  name={si.get('name')!r} freq={si.get('radio_freq')} "
          f"bw={si.get('radio_bw')} sf={si.get('radio_sf')} cr={si.get('radio_cr')} "
          f"tx={si.get('tx_power')}")

    print(f"\napplying region={region}: {freq}/{bw}/{sf}/{cr}, tx={tx_power}, name={name!r}")
    await mc.commands.set_radio(freq, bw, sf, cr)
    await mc.commands.set_tx_power(tx_power)
    await mc.commands.set_name(name)

    print("rebooting node to commit...")
    try:
        await mc.commands.reboot()
    except Exception:
        pass
    try:
        await mc.disconnect()
    except Exception:
        pass

    # The reboot path leaves the strap pin held; use esptool to drop into app.
    print("hard-reset via esptool to clear bootloader strap...")
    hard_reset(port)
    await asyncio.sleep(3)

    # Verify the new state.
    mc2 = await MeshCore.create_serial(port, 115200)
    if mc2 is None:
        print("FAILED to re-verify after reboot; check the device", file=sys.stderr)
        return 1
    si2 = mc2.self_info or {}
    print("\n=== after reboot ===")
    print(f"  name={si2.get('name')!r} freq={si2.get('radio_freq')} "
          f"bw={si2.get('radio_bw')} sf={si2.get('radio_sf')} cr={si2.get('radio_cr')} "
          f"tx={si2.get('tx_power')} pubkey={si2.get('public_key')}")
    try:
        await mc2.disconnect()
    except Exception:
        pass

    if (round(si2.get("radio_freq", 0), 3) != round(freq, 3)
            or si2.get("name") != name):
        print("WARN: post-reboot state does not match desired config; check above",
              file=sys.stderr)
        return 1
    return 0


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    p.add_argument("--port", default="/dev/ttyUSB0")
    p.add_argument("--name", required=True, help="Node name (visible to mesh)")
    p.add_argument("--region", default="us", choices=list(REGIONS),
                   help="Frequency band preset")
    p.add_argument("--tx-power", type=int, default=20,
                   help="TX power dBm (default 20)")
    args = p.parse_args()
    return asyncio.run(run(args.port, args.name, args.region, args.tx_power))


if __name__ == "__main__":
    sys.exit(main())
