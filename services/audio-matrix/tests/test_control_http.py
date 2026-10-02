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
import time
import urllib.request
import urllib.error

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from matrix_core import MatrixCore                                      # noqa: E402
from control_http import ControlPlaneConfigError, make_control_server  # noqa: E402

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


# ── Phase 152 prerequisite #4: empty control_token is a HARD boot
# refusal, not an "open mutations" warning, unless dev_insecure=True is
# passed explicitly (service.py: --dev-insecure) ──────────────────────
refused = False
try:
    make_control_server(MatrixCore(), 0, "", get_ticks=lambda: 0)
except ControlPlaneConfigError:
    refused = True
check("empty control_token refuses to boot (no dev_insecure)", refused)

opened_ok = True
try:
    _dev_core = MatrixCore()
    _dev_srv = make_control_server(_dev_core, 0, "", get_ticks=lambda: 0,
                                   dev_insecure=True)
    _dev_srv.server_close()
except ControlPlaneConfigError:
    opened_ok = False
check("empty control_token WITH dev_insecure=True is still allowed "
      "(the explicit local-dev escape hatch)", opened_ok)

non_empty_ok = True
try:
    _tok_core = MatrixCore()
    _tok_srv = make_control_server(_tok_core, 0, "some-real-token",
                                   get_ticks=lambda: 0)
    _tok_srv.server_close()
except ControlPlaneConfigError:
    non_empty_ok = False
check("a real non-empty token never triggers the refusal, "
      "with or without dev_insecure", non_empty_ok)

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

# ── /routes/update (Phase 152 prerequisite #4 -- remove-then-add) ────
code, r = req("POST", "/routes/update",
              {"old_src": "dmr", "old_dst": "disp", "src": "dmr", "dst": "disp",
               "gain_db": -6.0, "priority": 3}, token=TOKEN)
check("update dmr->disp (same pair, new gain/priority) -> 200", code == 200)
check("updated route reflects the new gain_db", r.get("gain_db") == -6.0)
check("updated route reflects the new priority", r.get("priority") == 3)
code, routes = req("GET", "/routes")
check("still exactly 1 route after update (no duplicate left behind)", len(routes) == 1)

code, err = req("POST", "/routes/update",
                {"old_src": "dmr", "old_dst": "disp", "src": "dmr", "dst": "phone"},
                token=TOKEN)
check("update to a regulatory-blocked pair (no override) -> 400", code == 400)
code, routes = req("GET", "/routes")
route_pairs = {(rr["src"], rr["dst"]) for rr in routes}
check("failed update's best-effort rollback restored the ORIGINAL dmr->disp route",
      ("dmr", "disp") in route_pairs and ("dmr", "phone") not in route_pairs)
# restore the gain/priority this section changed, so later assertions
# (which don't care about them) aren't confused by leftover state
req("POST", "/routes/update",
    {"old_src": "dmr", "old_dst": "disp", "src": "dmr", "dst": "disp"}, token=TOKEN)

# regulatory guard surfaces as 400
code, err = req("POST", "/routes", {"src": "dmr", "dst": "phone"}, token=TOKEN)
check("amateur->pstn route -> 400", code == 400 and "regulatory" in (err.get("error", "")))
code, ok = req("POST", "/routes",
               {"src": "dmr", "dst": "phone", "allow_cross_class": True}, token=TOKEN)
check("amateur->pstn WITH override -> 200", code == 200)

# ── Route.expires_at wire-through (Phase 152 prereq #6) ────────────────
req("POST", "/channels", {"id": "exsrc", "name": "Expiry Src", "reg_class": "internal"}, token=TOKEN)
req("POST", "/channels", {"id": "exdst", "name": "Expiry Dst", "reg_class": "internal"}, token=TOKEN)

future_epoch = time.time() + 300
code, r = req("POST", "/routes", {"src": "exsrc", "dst": "exdst", "expires_at": future_epoch}, token=TOKEN)
check("create with a future expires_at -> 200", code == 200)
check("response echoes expires_at back", r.get("expires_at") == future_epoch)
check("response reports is_expired=False for a future expiry", r.get("is_expired") is False)

code, routes = req("GET", "/routes")
exroute = next((x for x in routes if x.get("src") == "exsrc" and x.get("dst") == "exdst"), None)
check("GET /routes surfaces the same expires_at", exroute is not None and exroute.get("expires_at") == future_epoch)

# /routes/update can EXTEND (renew) an expiry via the same remove-then-add path.
later_epoch = time.time() + 600
code, r = req("POST", "/routes/update",
              {"old_src": "exsrc", "old_dst": "exdst", "src": "exsrc", "dst": "exdst",
               "expires_at": later_epoch}, token=TOKEN)
check("renew via /routes/update -> 200", code == 200)
check("renewed route reflects the NEW (later) expires_at", r.get("expires_at") == later_epoch)

# /routes/update can also CLEAR an expiry (expires_at omitted/null -> never expires).
code, r = req("POST", "/routes/update",
              {"old_src": "exsrc", "old_dst": "exdst", "src": "exsrc", "dst": "exdst"}, token=TOKEN)
check("update with expires_at omitted clears it (never expires)", code == 200 and r.get("expires_at") is None)

# enable/disable
code, r = req("POST", "/routes/enable", {"src": "dmr", "dst": "disp", "enabled": False}, token=TOKEN)
check("disable route 200", code == 200 and r.get("enabled") is False)

# delete
code, res = req("DELETE", "/routes?src=dmr&dst=disp", token=TOKEN)
check("delete route 200", code == 200 and res.get("removed") is True)
code, res = req("DELETE", "/routes?src=nope&dst=nope", token=TOKEN)
check("delete missing route 404", code == 404)

# ── /channels/http_stream: 501 when not wired (default -- e.g. the DMR/
# routes-only server booted above never passed the two callbacks) ────────
code, r = req("POST", "/channels/http_stream",
              {"channel_id": "x", "name": "X", "url": "http://example.invalid/x.mp3"},
              token=TOKEN)
check("POST /channels/http_stream -> 501 when create_http_stream_leg not wired", code == 501)
code, r = req("DELETE", "/channels/http_stream?channel_id=x", token=TOKEN)
check("DELETE /channels/http_stream -> 501 when remove_http_stream_leg not wired", code == 501)

srv.shutdown()

# ── a SECOND server, WITH the http_stream callbacks wired (2026-09-08,
# Eric: "I want to create multiple streams as needed" -- live hot-attach
# without a service restart) ──────────────────────────────────────────
hs_core = MatrixCore()
hs_created = []      # (channel_id, name, reg_class, url) tuples the fake recorded
hs_removed = []      # channel_ids the fake recorded
hs_active = set()    # channel_ids currently "attached", per the fake's own bookkeeping


def fake_create(channel_id, name, reg_class, url):
    if url == "http://bad.invalid/refuse-me.mp3":
        raise ValueError("simulated: could not start ffmpeg")
    hs_created.append((channel_id, name, reg_class, url))
    hs_active.add(channel_id)
    return object()


def fake_remove(channel_id):
    if channel_id not in hs_active:
        return False
    hs_active.discard(channel_id)
    hs_removed.append(channel_id)
    return True


hs_srv = make_control_server(hs_core, 0, TOKEN, get_ticks=lambda: 0,
                             create_http_stream_leg=fake_create,
                             remove_http_stream_leg=fake_remove)
HS_PORT = hs_srv.server_address[1]
hs_thread = threading.Thread(target=hs_srv.serve_forever, daemon=True)
hs_thread.start()


def hs_req(method, path, body=None, token=None):
    url = f"http://127.0.0.1:{HS_PORT}{path}"
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


code, r = hs_req("POST", "/channels/http_stream", {
    "channel_id": "noaa_wx", "name": "NOAA Weather Radio", "reg_class": "internal",
    "url": "http://www.urberg.net:8000/tim273/edina",
}, token=TOKEN)
check("POST /channels/http_stream (wired) -> 200", code == 200)
check("...response echoes the channel_id", r.get("channel_id") == "noaa_wx")
check("...the injected create callback actually ran with the right args",
      hs_created == [("noaa_wx", "NOAA Weather Radio", "internal",
                      "http://www.urberg.net:8000/tim273/edina")])

code, r = hs_req("POST", "/channels/http_stream", {"channel_id": "no_name_needed",
                 "url": "http://example.invalid/y.mp3"}, token=TOKEN)
check("name is optional -- defaults sensibly rather than 400ing", code == 200)

code, r = hs_req("POST", "/channels/http_stream", {"channel_id": "", "url": "http://x/y.mp3"},
                 token=TOKEN)
check("empty channel_id -> 400, not a crash", code == 400)

code, r = hs_req("POST", "/channels/http_stream", {"channel_id": "no_url_here"}, token=TOKEN)
check("missing url -> 400", code == 400)

code, r = hs_req("POST", "/channels/http_stream", {
    "channel_id": "will_fail", "url": "http://bad.invalid/refuse-me.mp3",
}, token=TOKEN)
check("the injected callback raising -> 400 with the error surfaced, not a 500", code == 400)
check("...the error message is present and human-readable",
      isinstance(r.get("error"), str) and "ffmpeg" in r.get("error", ""))

code, r = hs_req("POST", "/channels/http_stream",
                 {"channel_id": "noaa_wx", "url": "http://x/y.mp3"})  # no token
check("POST /channels/http_stream without a token -> 401 (not exempt from auth)", code == 401)

code, r = hs_req("DELETE", "/channels/http_stream?channel_id=noaa_wx", token=TOKEN)
check("DELETE /channels/http_stream (wired) -> 200", code == 200 and r.get("removed") is True)
check("...the injected remove callback actually ran", hs_removed == ["noaa_wx"])

code, r = hs_req("DELETE", "/channels/http_stream?channel_id=noaa_wx", token=TOKEN)
check("DELETE on an already-removed channel -> 404, not a crash", code == 404)

code, r = hs_req("DELETE", "/channels/http_stream", token=TOKEN)  # no channel_id query param
check("DELETE with no channel_id -> 400", code == 400)

hs_srv.shutdown()

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
