"""
Control-plane HTTP test for the audio matrix (Phase 114c).

Spins the real control server on an ephemeral port and drives it over
HTTP with urllib — no external deps, no radios. Proves auth, channel +
route CRUD, the regulatory guard surfaced as a 400, and enable/disable.

    python3 services/audio-matrix/tests/test_control_http.py
"""

import json
import os
import sys
import threading
import urllib.request
import urllib.error

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from matrix_core import MatrixCore                 # noqa: E402
from control_http import make_control_server       # noqa: E402

_pass = 0
_fail = 0
TOKEN = "test-secret-token"


def check(name, cond):
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"[PASS] {name}")
    else:
        _fail += 1
        print(f"[FAIL] {name}")


def req(method, path, body=None, token=None):
    url = f"http://127.0.0.1:{PORT}{path}"
    data = json.dumps(body).encode() if body is not None else None
    r = urllib.request.Request(url, data=data, method=method)
    if token:
        r.add_header("Authorization", "Bearer " + token)
    if data is not None:
        r.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(r, timeout=5) as resp:
            return resp.status, json.loads(resp.read().decode() or "null")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode() or "null")


# ── boot the server on an ephemeral port ─────────────────────────────
core = MatrixCore()
srv = make_control_server(core, 0, TOKEN, get_ticks=lambda: 42)
PORT = srv.server_address[1]
t = threading.Thread(target=srv.serve_forever, daemon=True)
t.start()

print("Control plane on port", PORT)

# ── health (open) ────────────────────────────────────────────────────
code, h = req("GET", "/health")
check("GET /health 200", code == 200)
check("health reports running", h.get("running") is True)
check("health ticks passthrough", h.get("ticks") == 42)

# ── auth gate ────────────────────────────────────────────────────────
code, _ = req("POST", "/channels", {"id": "x", "name": "X"})
check("POST without token -> 401", code == 401)
code, _ = req("POST", "/channels", {"id": "x", "name": "X"}, token="wrong")
check("POST with wrong token -> 401", code == 401)

# ── channel + route CRUD ─────────────────────────────────────────────
code, _ = req("POST", "/channels", {"id": "dmr", "name": "TG 3127", "reg_class": "amateur"}, token=TOKEN)
check("add amateur channel 200", code == 200)
req("POST", "/channels", {"id": "disp", "name": "Dispatch", "reg_class": "internal"}, token=TOKEN)
req("POST", "/channels", {"id": "phone", "name": "SIP", "reg_class": "pstn"}, token=TOKEN)

code, chans = req("GET", "/channels")
check("GET /channels lists 3", code == 200 and len(chans) == 3)

code, r = req("POST", "/routes", {"src": "dmr", "dst": "disp"}, token=TOKEN)
check("add dmr->disp route 200", code == 200 and r.get("src") == "dmr")

code, routes = req("GET", "/routes")
check("GET /routes lists 1", len(routes) == 1)

# regulatory guard surfaces as 400
code, err = req("POST", "/routes", {"src": "dmr", "dst": "phone"}, token=TOKEN)
check("amateur->pstn route -> 400", code == 400 and "regulatory" in (err.get("error", "")))
code, ok = req("POST", "/routes",
               {"src": "dmr", "dst": "phone", "allow_cross_class": True}, token=TOKEN)
check("amateur->pstn WITH override -> 200", code == 200)

# enable/disable
code, r = req("POST", "/routes/enable", {"src": "dmr", "dst": "disp", "enabled": False}, token=TOKEN)
check("disable route 200", code == 200 and r.get("enabled") is False)

# delete
code, res = req("DELETE", "/routes?src=dmr&dst=disp", token=TOKEN)
check("delete route 200", code == 200 and res.get("removed") is True)
code, res = req("DELETE", "/routes?src=nope&dst=nope", token=TOKEN)
check("delete missing route 404", code == 404)

srv.shutdown()
print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
