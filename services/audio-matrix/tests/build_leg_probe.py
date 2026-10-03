"""
build_leg_probe.py — run service.build_usrp_leg() on one channel config.

Used by tests/test_voice_bridge_channels.php to prove the PHP validator
(inc/voice-bridge-channels.php) and the Python builder agree on what a valid
channel config is: PHP writes the config, THIS runs the real builder on it.
Not part of the deployed service.

    python build_leg_probe.py '<json config>'

Prints "OK <json of the leg's constructor arguments>" or "ERR <message>".
Does not bind any socket (the leg is constructed, never started).
"""

import json
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "legs"))

import logging  # noqa: E402

import service  # noqa: E402
from matrix_core import MatrixCore  # noqa: E402
from usrp import UsrpConfigError  # noqa: E402

logging.disable(logging.CRITICAL)


def main() -> int:
    try:
        config = json.loads(sys.argv[1])
    except (IndexError, ValueError):
        print("ERR unparseable json")
        return 0
    try:
        leg = service.build_usrp_leg(MatrixCore(), "probe:x", "Probe", config, {})
    except (UsrpConfigError, ValueError) as e:
        print("ERR " + str(e))
        return 0
    spec = dict(leg.spec)
    print("OK " + json.dumps(spec, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
