#!/usr/bin/env python3
"""
Read-only MeshCore companion-mode `self_info` dumper.

Used by the Mesh Console health card and by the operator when debugging
"is my node responding?" The bridge service holds the serial port while
running, so this script must be run with the service stopped (or run from
within the bridge itself).

Usage:
    sudo systemctl stop meshbridge.service
    sudo python3 verify_node.py --port /dev/ttyUSB0 --json
    sudo systemctl start meshbridge.service

`--json` mode is what the bridge's health endpoint POSTs back to CAD; the
non-json mode is the readable view for humans.
"""

import argparse
import asyncio
import json
import sys


async def show(port: str, json_out: bool) -> int:
    from meshcore import MeshCore
    mc = await MeshCore.create_serial(port, 115200)
    if mc is None:
        print(json.dumps({"ok": False, "error": "no response"}) if json_out
              else "FAILED: no response from device", file=sys.stderr)
        return 1
    si = mc.self_info or {}
    if json_out:
        # Render coordinates / floats so the bridge can POST a clean JSON body.
        print(json.dumps({"ok": True, "self_info": si}, default=str))
    else:
        print(f"name:    {si.get('name')}")
        print(f"pubkey:  {si.get('public_key')}")
        print(f"freq:    {si.get('radio_freq')} MHz")
        print(f"bw:      {si.get('radio_bw')} kHz")
        print(f"sf:      {si.get('radio_sf')}")
        print(f"cr:      {si.get('radio_cr')}")
        print(f"tx:      {si.get('tx_power')} / max {si.get('max_tx_power')} dBm")
        if si.get("adv_lat") or si.get("adv_lon"):
            print(f"adv loc: {si.get('adv_lat')}, {si.get('adv_lon')}")
    try:
        await mc.disconnect()
    except Exception:
        pass
    return 0


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__.splitlines()[1])
    p.add_argument("--port", default="/dev/ttyUSB0")
    p.add_argument("--json", action="store_true", help="Machine-readable output")
    args = p.parse_args()
    return asyncio.run(show(args.port, args.json))


if __name__ == "__main__":
    sys.exit(main())
