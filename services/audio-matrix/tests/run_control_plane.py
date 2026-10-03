"""
run_control_plane.py — boot a REAL audio-matrix control plane (real
MatrixCore, real control_http server, the real USRP-leg hot-attach factory)
on an ephemeral port, for tests that drive it over HTTP from PHP
(tests/test_voice_bridge_e2e.php). No database, no radio, no bridge program.

    python run_control_plane.py --token TOKEN [--php-base-url URL]

Prints one line "READY <port>" on stdout once it is serving, then runs until
its stdin is closed (or it is killed). When --php-base-url is given, a leg's
rx_state notifications are POSTed to <url>/api/matrix-channel-state.php with
the same bearer token, exactly as the deployed service does.

THIS IS A TEST HARNESS, not part of the deployed service: it exists so a PHP
test can exercise the real Python code path (PHP -> control plane -> leg ->
UDP) without installing the systemd unit.
"""

import argparse
import os
import sys
import threading

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "legs"))

import service  # noqa: E402
from control_http import make_control_server  # noqa: E402
from matrix_core import MatrixCore  # noqa: E402


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--token", required=True)
    ap.add_argument("--php-base-url", default="")
    args = ap.parse_args(argv)

    core = MatrixCore()
    cfg = {"php_base_url": args.php_base_url, "control_token": args.token}
    registry: dict = {}
    create, remove, health = service.make_leg_factory(core, cfg, registry)
    core.start()
    srv = make_control_server(core, 0, args.token, get_ticks=lambda: 0,
                              create_leg=create, remove_leg=remove, get_leg_health=health)
    port = srv.server_address[1]
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    print("READY %d" % port, flush=True)
    try:
        sys.stdin.read()          # block until the parent closes our stdin
    finally:
        for leg in list(registry.values()):
            leg.stop()
        srv.shutdown()
        core.stop()
    return 0


if __name__ == "__main__":
    sys.exit(main())
