"""
Service + control-plane test for the USRP-family legs (Phase 155, GH#151 +
GH#129, Release A: listen-only). NO bridge program, NO radio; the "bridge"
is tests/usrp_sim.py. Real UDP sockets on loopback, a real control-plane
HTTP server, and a fake DB cursor with canned comm_channels rows.

Covers service.py's boot-time attach (load_channels), the hot-attach factory
(make_leg_factory), and control_http.py's POST/DELETE /channels/leg and
GET /legs:

  * a `dvmproject` row only gets a leg once the usage-policy acknowledgment
    has been recorded; a `usrp_bridge` row does not need one
  * a digital-voice bridge at class `internal`/`pstn` never gets a leg
  * a bad config (malformed JSON, a privileged port, a bad address, a
    port already in use) skips the leg and keeps the service booting
  * an `enabled=0` row binds nothing (the SELECT filters on enabled = 1)
  * editing a channel on the same port works (old leg stopped first) and a
    rejected edit puts the previous leg back
  * removing a channel frees its UDP port
  * the control plane answers 200/400/401/404/501 as documented, and
    GET /legs is bearer-protected

    python services/audio-matrix/tests/test_usrp_service.py
"""

import json
import os
import socket
import sys
import threading
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "legs"))
sys.path.insert(0, HERE)

import service  # noqa: E402
from control_http import make_control_server  # noqa: E402
from matrix_core import MatrixCore  # noqa: E402
from usrp_sim import UsrpSimulator, tone_frames  # noqa: E402

_pass = 0
_fail = 0
TOKEN = "t0k3n-for-tests"


def check(name, cond):
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"[PASS] {name}")
    else:
        _fail += 1
        print(f"[FAIL] {name}")


def free_port():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def distinct_ports(n):
    got = set()
    while len(got) < n:
        got.add(free_port())
    return sorted(got)


def port_is_free(port):
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        s.bind(("127.0.0.1", port))
        return True
    except OSError:
        return False
    finally:
        s.close()


def wait_for(pred, timeout=3.0):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if pred():
            return True
        time.sleep(0.01)
    return pred()


class FakeCursor:
    def __init__(self, script, log):
        self._script, self._log, self._rows = script, log, []

    def execute(self, sql, params=None):
        self._log.append(sql)
        low = sql.lower()
        for match, rows in self._script:
            if match in low:
                self._rows = rows
                return
        self._rows = []

    def fetchall(self):
        return list(self._rows)

    def fetchone(self):
        return self._rows[0] if self._rows else None

    def close(self):
        pass


class FakeDB:
    def __init__(self, script):
        self._script = script
        self.queries = []

    def cursor(self, dictionary=False):
        return FakeCursor(self._script, self.queries)


def cfgjson(listen_port, **extra):
    d = {"backend": "dvmproject", "mode": "p25", "bridge_host": "127.0.0.1",
         "bridge_tx_port": 32001, "listen_host": "127.0.0.1",
         "listen_port": listen_port, "framing": "usrp"}
    d.update(extra)
    return json.dumps(d)


# ══ 1. boot-time attach ═════════════════════════════════════════════════
print("1. load_channels: boot-time attach")
p_ok, p_noack, p_bridge, p_comm, p_conf1 = distinct_ports(5)

ROWS = [
    {"id": 1, "channel_key": "dvm:ok", "label": "P25 TG 1", "regulatory_class": "amateur",
     "adapter": "dvmproject", "config_json": cfgjson(p_ok)},
    {"id": 2, "channel_key": "dvm:internal", "label": "Wrongly internal",
     "regulatory_class": "internal", "adapter": "dvmproject", "config_json": cfgjson(p_noack)},
    {"id": 3, "channel_key": "usrp:bridge", "label": "Analog bridge", "regulatory_class": "amateur",
     "adapter": "usrp_bridge", "config_json": cfgjson(p_bridge, backend="usrp_bridge")},
    {"id": 4, "channel_key": "dvm:part90", "label": "Part 90 network", "regulatory_class": "commercial",
     "adapter": "dvmproject", "config_json": cfgjson(p_comm)},
    {"id": 5, "channel_key": "dvm:badjson", "label": "bad json", "regulatory_class": "amateur",
     "adapter": "dvmproject", "config_json": "not json {"},
    {"id": 6, "channel_key": "dvm:lowport", "label": "privileged port", "regulatory_class": "amateur",
     "adapter": "dvmproject", "config_json": cfgjson(80)},
    {"id": 7, "channel_key": "dvm:badhost", "label": "hostname", "regulatory_class": "amateur",
     "adapter": "dvmproject", "config_json": cfgjson(free_port(), bridge_host="bridge.example.org")},
    {"id": 8, "channel_key": "dvm:dupport", "label": "same port as dvm:ok", "regulatory_class": "amateur",
     "adapter": "dvmproject", "config_json": cfgjson(p_ok)},
    {"id": 9, "channel_key": "dvm:txset", "label": "tx_enabled written by a later release",
     "regulatory_class": "amateur", "adapter": "dvmproject",
     "config_json": cfgjson(p_conf1, tx_enabled=True)},
]
db = FakeDB([("from comm_channels", ROWS), ("from settings", [{"value": "{\"user\":\"x\",\"at\":\"2026-10-02\"}"}])])
core = MatrixCore()
registry = {}
try:
    service.load_channels(db, core, cfg={}, leg_registry=registry)
    check("the channels query filters on enabled = 1 (a disabled row binds nothing)",
          any("enabled = 1" in q for q in db.queries))
    check("a dvmproject row with a valid config AND a recorded acknowledgment gets a running leg",
          "dvm:ok" in registry and registry["dvm:ok"].health()["running"] is True)
    check("...its matrix channel carries the row's regulatory class (amateur)",
          core.channel("dvm:ok").reg_class.value == "amateur" and core.channel("dvm:ok").leg is registry["dvm:ok"])
    check("a usrp_bridge row also gets a leg", "usrp:bridge" in registry)
    check("a commercial-class (Part 90) digital voice channel gets a leg at class commercial",
          "dvm:part90" in registry and core.channel("dvm:part90").reg_class.value == "commercial")
    check("a digital-voice channel at class internal gets NO leg (it would escape the cross-class guard)",
          "dvm:internal" not in registry and core.channel("dvm:internal").leg is None)
    check("malformed config_json -> no leg, the service keeps booting",
          "dvm:badjson" not in registry and core.channel("dvm:badjson") is not None)
    check("a privileged listen port (80) -> no leg", "dvm:lowport" not in registry)
    check("a hostname as bridge_host -> no leg", "dvm:badhost" not in registry)
    check("a second row on a port already in use -> no leg, the first keeps its port",
          "dvm:dupport" not in registry and registry["dvm:ok"].health()["running"])
    leg9 = registry.get("dvm:txset")
    check("tx_enabled=true in config does not enable transmit: the leg is still listen-only",
          leg9 is not None and leg9.health()["listen_only"] is True)
    # and it really carries audio
    sim = UsrpSimulator("127.0.0.1", p_ok, bind_host="127.0.0.1")
    sim.send_voice(list(tone_frames(1000, 0.1)), style="dvmbridge")
    sim.close()
    check("a boot-attached leg receives audio into its matrix channel",
          wait_for(lambda: len(core.channel("dvm:ok")._inq) == 5))
finally:
    for leg in list(registry.values()):
        leg.stop()
check("stopping the boot-attached legs frees their ports", port_is_free(p_ok) and port_is_free(p_bridge))

# ── policy acknowledgment gate ──────────────────────────────────────────
print("\n2. Usage-policy acknowledgment gate")
for label, settings_rows in (("no settings row at all", []), ("an empty value", [{"value": ""}]),
                              ("a whitespace-only value", [{"value": "   "}])):
    pa, pb = distinct_ports(2)
    rows = [
        {"id": 1, "channel_key": "dvm:a", "label": "A", "regulatory_class": "amateur",
         "adapter": "dvmproject", "config_json": cfgjson(pa)},
        {"id": 2, "channel_key": "usrp:b", "label": "B", "regulatory_class": "amateur",
         "adapter": "usrp_bridge", "config_json": cfgjson(pb, backend="usrp_bridge")},
    ]
    c2 = MatrixCore()
    reg2 = {}
    try:
        service.load_channels(FakeDB([("from comm_channels", rows), ("from settings", settings_rows)]),
                              c2, cfg={}, leg_registry=reg2)
        check("unacknowledged (%s): the dvmproject leg is NOT attached, no socket bound" % label,
              "dvm:a" not in reg2 and port_is_free(pa))
        check("unacknowledged (%s): the channel itself still loads (routes validate, console shows it)" % label,
              c2.channel("dvm:a") is not None and c2.channel("dvm:a").leg is None)
        check("unacknowledged (%s): a usrp_bridge row is NOT subject to the DVMProject acknowledgment" % label,
              "usrp:b" in reg2)
    finally:
        for leg in list(reg2.values()):
            leg.stop()

# ══ 3. hot-attach factory ════════════════════════════════════════════════
print("\n3. make_leg_factory: hot attach / edit / remove")
core3 = MatrixCore()
reg3 = {}
create, remove, health = service.make_leg_factory(core3, {}, reg3)
pa, pb = distinct_ports(2)
leg = create("dvm:hot", "Hot P25", "amateur", "dvmproject", json.loads(cfgjson(pa)))
check("create() adds the channel and a running leg", core3.channel("dvm:hot").leg is leg and leg.health()["running"])
check("create() reports the leg in health()", [h["channel_id"] for h in health()] == ["dvm:hot"])
sim = UsrpSimulator("127.0.0.1", pa, bind_host="127.0.0.1")
sim.send_voice(list(tone_frames(900, 0.06)))
check("a hot-attached leg receives audio", wait_for(lambda: len(core3.channel("dvm:hot")._inq) == 3))
sim.close()

# edit to a new port: old stopped first, new bound, old port freed
leg2 = create("dvm:hot", "Hot P25 (edited)", "amateur", "dvmproject", json.loads(cfgjson(pb)))
check("re-creating (an edit) replaces the leg and frees the old port",
      leg2 is not leg and port_is_free(pa) and not port_is_free(pb))
check("...and updates the live channel's name", core3.channel("dvm:hot").name == "Hot P25 (edited)")

# edit to the SAME port works because the old leg is stopped first
leg3 = create("dvm:hot", "Hot P25 (same port)", "amateur", "dvmproject", json.loads(cfgjson(pb, rx_hang_ms=900)))
check("an edit that keeps the same port succeeds (old leg is stopped before the new one binds)",
      leg3.health()["running"] and leg3.rx_inactivity_s == 0.9)

# a rejected edit restores the previous leg
blocker = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
blocker.bind(("127.0.0.1", 0))
busy_port = blocker.getsockname()[1]
refused = False
try:
    create("dvm:hot", "Hot P25 (bad edit)", "amateur", "dvmproject", json.loads(cfgjson(busy_port)))
except ValueError as e:
    refused = "could not start leg" in str(e)
check("an edit onto a port someone else holds is refused with a clear error", refused)
restored = reg3.get("dvm:hot")
check("...and the previous leg was put back, still bound to its port",
      restored is not None and restored.health()["running"] and not port_is_free(pb))
sim = UsrpSimulator("127.0.0.1", pb, bind_host="127.0.0.1")
before = len(core3.channel("dvm:hot")._inq)
sim.send_voice(list(tone_frames(900, 0.04)))
check("...and it still receives audio", wait_for(lambda: len(core3.channel("dvm:hot")._inq) > before))
sim.close()
blocker.close()

for bad_args, label in ((("x", "X", "internal", "dvmproject", json.loads(cfgjson(free_port()))), "class internal"),
                         (("x", "X", "pstn", "usrp_bridge", json.loads(cfgjson(free_port()))), "class pstn"),
                         (("x", "X", "amateur", "nope", {}), "an unknown adapter"),
                         (("x", "X", "amateur", "dvmproject", {"bridge_host": "127.0.0.1", "listen_port": 80}), "a privileged port"),
                         (("x", "X", "amateur", "dvmproject", {}), "an empty config")):
    try:
        create(*bad_args)
        ok = False
    except ValueError:
        ok = True
    check("create() refuses " + label, ok and core3.channel("x") is None)

check("remove() stops the leg, frees the port and removes the channel",
      remove("dvm:hot") is True and port_is_free(pb) and core3.channel("dvm:hot") is None and not reg3)
check("remove() on an unknown channel returns False", remove("dvm:hot") is False)

# ══ 4. control plane ═════════════════════════════════════════════════════
print("\n4. Control plane: /channels/leg and /legs")
core4 = MatrixCore()
reg4 = {}
c4, r4, h4 = service.make_leg_factory(core4, {}, reg4)
srv = make_control_server(core4, 0, TOKEN, get_ticks=lambda: 0,
                          create_leg=c4, remove_leg=r4, get_leg_health=h4)
PORT = srv.server_address[1]
threading.Thread(target=srv.serve_forever, daemon=True).start()


def req(method, path, body=None, token=TOKEN, port=None):
    url = "http://127.0.0.1:%d%s" % (port or PORT, path)
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


pc = free_port()
body = {"channel_id": "dvm:ctl", "name": "Control P25", "reg_class": "amateur",
        "adapter": "dvmproject", "config": json.loads(cfgjson(pc))}
code, r = req("POST", "/channels/leg", body, token=None)
check("POST /channels/leg without a token -> 401 and nothing bound", code == 401 and port_is_free(pc))
code, r = req("POST", "/channels/leg", body)
check("POST /channels/leg -> 200 and the UDP port is now bound", code == 200 and not port_is_free(pc))
code, r = req("GET", "/legs", token=None)
check("GET /legs without a token -> 401 (it names the bridge's address and ports)", code == 401)
code, r = req("GET", "/legs")
legs = (r or {}).get("legs", [])
check("GET /legs lists the leg with its health, and says listen_only",
      code == 200 and len(legs) == 1 and legs[0]["channel_id"] == "dvm:ctl"
      and legs[0]["listen_only"] is True and legs[0]["listen_port"] == pc)
code, r = req("POST", "/channels/leg", dict(body, config={"bridge_host": "127.0.0.1", "listen_port": 80}))
check("a bad config -> 400 with the reason", code == 400 and "listen_port" in json.dumps(r))
code, r = req("POST", "/channels/leg", dict(body, config={}))
check("an EMPTY config -> 400, never a leg quietly bound to a default port",
      code == 400 and "required" in json.dumps(r) and port_is_free(34001))
code, r = req("POST", "/channels/leg", dict(body, config="nope"))
check("a non-object config -> 400", code == 400)
code, r = req("POST", "/channels/leg", {"channel_id": "z"})
check("a missing adapter -> 400", code == 400)
code, r = req("POST", "/channels/leg", dict(body, reg_class="internal"))
check("class internal -> 400 (a digital-voice bridge is amateur or commercial)", code == 400)
code, r = req("DELETE", "/channels/leg?channel_id=dvm:ctl", token=None)
check("DELETE without a token -> 401 and the leg is untouched", code == 401 and not port_is_free(pc))
code, r = req("DELETE", "/channels/leg")
check("DELETE with no channel_id -> 400", code == 400)
code, r = req("DELETE", "/channels/leg?channel_id=dvm:ctl")
check("DELETE -> 200 and the port is freed", code == 200 and r.get("removed") is True and port_is_free(pc))
code, r = req("DELETE", "/channels/leg?channel_id=dvm:ctl")
check("DELETE again -> 404", code == 404)

# The server consumes an unauthenticated request's body before answering 401;
# otherwise the client's send races the server's close and, on Windows, the
# caller sees a connection error instead of the 401.
big = {"channel_id": "x", "adapter": "dvmproject", "config": {"pad": "y" * 500000}}
clean = True
for _i in range(10):
    try:
        c, _r = req("POST", "/channels/leg", big, token=None)
        clean = clean and c == 401
    except OSError:
        clean = False
check("10 unauthenticated POSTs with a 500 KB body each get a clean 401, never a connection error", clean)
srv.shutdown()

# not wired -> 501
srv2 = make_control_server(MatrixCore(), 0, TOKEN, get_ticks=lambda: 0)
PORT2 = srv2.server_address[1]
threading.Thread(target=srv2.serve_forever, daemon=True).start()
code, r = req("POST", "/channels/leg", body, port=PORT2)
check("POST /channels/leg -> 501 when legs are not wired", code == 501)
code, r = req("DELETE", "/channels/leg?channel_id=x", port=PORT2)
check("DELETE /channels/leg -> 501 when legs are not wired", code == 501)
code, r = req("GET", "/legs", port=PORT2)
check("GET /legs -> 501 when legs are not wired", code == 501)
srv2.shutdown()

# ══ 5. rx_state notification wire shape (the Python half of the PHP contract) ═
print("\n5. rx_state notification body")
from http.server import BaseHTTPRequestHandler, HTTPServer  # noqa: E402
from browser import make_state_notify_fn  # noqa: E402

captured = {}


class Cap(BaseHTTPRequestHandler):
    def do_POST(self):
        n = int(self.headers.get("Content-Length", "0"))
        captured["path"] = self.path
        captured["auth"] = self.headers.get("Authorization")
        captured["body"] = json.loads(self.rfile.read(n).decode())
        self.send_response(200)
        self.send_header("Content-Length", "2")
        self.end_headers()
        self.wfile.write(b"{}")

    def log_message(self, *a):
        pass


cap_srv = HTTPServer(("127.0.0.1", 0), Cap)
threading.Thread(target=cap_srv.serve_forever, daemon=True).start()
notify = make_state_notify_fn("http://127.0.0.1:%d" % cap_srv.server_address[1], "bearer-xyz")
notify("dvm:p25", "P25 TG 1", "rx_state", "started")
cap_srv.shutdown()
check("rx_state posts to api/matrix-channel-state.php", captured.get("path") == "/api/matrix-channel-state.php")
check("...with the shared bearer token", captured.get("auth") == "Bearer bearer-xyz")
check("...as {event: rx_state, channel_id, rx: started} (the key PHP reads is 'rx', not 'tx')",
      captured.get("body") == {"event": "rx_state", "channel_id": "dvm:p25", "label": "P25 TG 1", "rx": "started"})

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
