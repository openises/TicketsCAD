"""
End-to-end test of the REAL bridge.py in mode = threecx against two local mocks:

  * a mock 3CX server  -- POST /connect/token, GET /callcontrol (snapshot),
                          GET /callcontrol/<dn>/participants/<id>, and the
                          /callcontrol/ws WebSocket. It behaves the way REAL
                          3CX servers were captured behaving: event frames carry
                          attached_data = null, so the bridge must GET each
                          participant; the token reply says expires_in: 60.
  * a mock TicketsCAD  -- POST /api/sip-ingest.php with bearer-token auth,
                          recording every body, able to fail on demand.

The bridge runs as a real subprocess, so main(), the heartbeat thread, the
ordered/retrying delivery queue, config parsing, capture and the --check doctor
are all exercised, not just their functions.

HONEST LIMITS: the mock speaks the protocol as DOCUMENTED AND CAPTURED. It proves
this bridge talks that protocol correctly and recovers from drops and outages; it
cannot prove a real 3CX behaves identically in every detail (see the research
note's unknowns U2-U7). Capture a real session with capture_file to close those.

Run:  python services/sip-bridge/tests/test_bridge_threecx_e2e.py
Skips cleanly (exit 0, prints SKIP) if `websockets` or `requests` is missing.
"""
import base64
import json
import os
import re
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import warnings
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote_plus

warnings.simplefilter("ignore", ResourceWarning)

try:
    import requests  # noqa: F401
    from websockets.sync.server import serve as ws_serve
except ImportError as exc:  # pragma: no cover
    print("SKIP: %s" % exc)
    sys.exit(0)

HERE = os.path.dirname(os.path.abspath(__file__))
BRIDGE = os.path.join(os.path.dirname(HERE), "bridge.py")

BEARER = "good-trunk-token"
CLIENT_ID = "800"
CLIENT_SECRET = "s3cret%with#chars"   # % and # on purpose: ini interpolation / comment traps


def jwt(roles):
    def b64(d):
        return base64.urlsafe_b64encode(json.dumps(d).encode()).decode().rstrip("=")
    return b64({"alg": "none"}) + "." + b64({"role": roles}) + ".sig"


def participant(pid, dn, status, callid=42, legid=2):
    return {"id": pid, "status": status, "dn": dn, "party_caller_name": "JONES, PAT",
            "party_dn": "10001", "party_caller_id": "+16125551234", "party_did": "",
            "device_id": "sip:%s@10.0.0.21:5060" % dn, "party_dn_type": "Wexternalline",
            "direct_control": False, "originated_by_dn": "", "originated_by_type": "None",
            "referred_by_dn": "", "referred_by_type": "None", "on_behalf_of_dn": "",
            "on_behalf_of_type": "None", "callid": callid, "legid": legid}


def envelope(etype, dn, pid):
    return json.dumps({"sequence": 1, "event": {
        "event_type": etype, "entity": "/callcontrol/%s/participants/%s" % (dn, pid),
        "attached_data": None}})


class MockTicketsCad:
    def __init__(self, token_ok=True, fail_first_calls=0):
        self.bodies = []
        self.auth_headers = []
        self.token_ok = token_ok
        self.fail_first_calls = fail_first_calls
        self.rejected = 0
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_POST(self):
                length = int(self.headers.get("Content-Length", 0))
                body = json.loads(self.rfile.read(length) or b"{}")
                outer.auth_headers.append(self.headers.get("Authorization"))
                if not outer.token_ok or self.headers.get("Authorization") != "Bearer " + BEARER:
                    self.send_response(403)
                    self.end_headers()
                    self.wfile.write(b'{"error":"bad bearer"}')
                    return
                if body.get("event") != "heartbeat" and outer.rejected < outer.fail_first_calls:
                    outer.rejected += 1
                    self.send_response(503)
                    self.end_headers()
                    return
                outer.bodies.append(body)
                reply = {"ok": True}
                if body.get("event") == "heartbeat":
                    reply.update({"heartbeat": True, "recorded": True, "trunk_id": 1,
                                  "trunk_label": "Main Dispatch Line", "trunk_enabled": True})
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps(reply).encode())

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    @property
    def url(self):
        return "http://127.0.0.1:%d" % self.port

    def call_events(self):
        return [b for b in self.bodies if b.get("event") != "heartbeat"]

    def heartbeats(self):
        return [b for b in self.bodies if b.get("event") == "heartbeat"]

    def close(self):
        self.httpd.shutdown()


class Mock3cx:
    """A tiny 3CX. `scripts` is a list; each WebSocket connection consumes the
    next entry, a list of steps:
        ("upsert", dn, participant)   register it, then send a null-data Upsert frame
        ("remove", dn, pid)           forget it, then send a Remove frame
        ("vanish", dn, pid)           forget it SILENTLY (a missed hangup)
        ("sleep", seconds)
        "CLOSE"                       drop the socket"""

    def __init__(self, scripts, preload=(), extensions=("101", "102"), secret_ok=True,
                 token_role=None, expires_in=60):
        self.scripts = list(scripts)
        self.table = {}                       # (dn, pid) -> participant
        for dn, p in preload:
            self.table[(dn, p["id"])] = p
        self.extensions = list(extensions)
        self.secret_ok = secret_ok
        self.token_role = token_role
        self.expires_in = expires_in
        self.ws_auth_headers = []
        self.connections = 0
        self.token_requests = 0
        self.participant_gets = 0
        self.access_token = jwt(token_role) if token_role is not None else "3cx-access-token"
        outer = self

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def _json(self, code, obj):
                self.send_response(code)
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(json.dumps(obj).encode())

            def do_POST(self):
                length = int(self.headers.get("Content-Length", 0))
                form = {k: unquote_plus(v) for k, v in
                        (p.split("=", 1) for p in self.rfile.read(length).decode().split("&"))}
                outer.token_requests += 1
                good = (outer.secret_ok and self.path == "/connect/token"
                        and form.get("client_id") == CLIENT_ID
                        and form.get("client_secret") == CLIENT_SECRET
                        and form.get("grant_type") == "client_credentials")
                if not good:
                    self.send_response(401)
                    self.end_headers()
                    return
                self._json(200, {"token_type": "Bearer", "expires_in": outer.expires_in,
                                 "access_token": outer.access_token})

            def do_GET(self):
                if self.headers.get("Authorization") != "Bearer " + outer.access_token:
                    self.send_response(401)
                    self.end_headers()
                    return
                if self.path == "/callcontrol":
                    snap = [{"dn": "800", "type": "Wroutepoint", "devices": [], "participants": []}]
                    for dn in outer.extensions:
                        snap.append({"dn": dn, "type": "Wextension", "devices": [],
                                     "participants": [p for (d, _), p in outer.table.items() if d == dn]})
                    return self._json(200, snap)
                m = re.match(r"^/callcontrol/([^/]+)/participants/(\d+)$", self.path)
                if m:
                    outer.participant_gets += 1
                    p = outer.table.get((m.group(1), int(m.group(2))))
                    return self._json(200, p) if p else self._json(404, {})
                self.send_response(404)
                self.end_headers()

        self.httpd = ThreadingHTTPServer(("127.0.0.1", 0), H)
        self.http_port = self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

        def ws_handler(conn):
            outer.connections += 1
            auth = conn.request.headers.get("Authorization")
            outer.ws_auth_headers.append(auth)
            if auth != "Bearer " + outer.access_token:
                conn.close(1008, "unauthorized")
                return
            steps = outer.scripts.pop(0) if outer.scripts else []
            for step in steps:
                if step == "CLOSE":
                    conn.close()
                    return
                kind = step[0]
                if kind == "upsert":
                    outer.table[(step[1], step[2]["id"])] = step[2]
                    conn.send(envelope(0, step[1], step[2]["id"]))
                elif kind == "remove":
                    outer.table.pop((step[1], step[2]), None)
                    conn.send(envelope(1, step[1], step[2]))
                elif kind == "vanish":
                    outer.table.pop((step[1], step[2]), None)
                elif kind == "sleep":
                    time.sleep(step[1])
                time.sleep(0.05)
            while True:           # hold the socket open until the client goes away
                try:
                    conn.recv(timeout=0.5)
                except TimeoutError:
                    continue      # silence is normal; only a real close ends the handler
                except Exception:
                    return

        self.ws = ws_serve(ws_handler, "127.0.0.1", 0)
        self.ws_port = self.ws.socket.getsockname()[1]
        threading.Thread(target=self.ws.serve_forever, daemon=True).start()

    def close(self):
        self.httpd.shutdown()
        self.ws.shutdown()


def free_port():
    import socket
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    port = s.getsockname()[1]
    s.close()
    return port


class BridgeProcess:
    def __init__(self, cad, pbx, extra_ini=""):
        self.dir = tempfile.mkdtemp(prefix="sipbridge-e2e-")
        self.ini = os.path.join(self.dir, "bridge.ini")
        self.capture = os.path.join(self.dir, "capture.jsonl")
        with open(self.ini, "w", encoding="utf-8") as fh:
            fh.write("[sip-bridge]\n"
                     "mode = threecx\n"
                     "ticketscad_url = %s\n"
                     "bearer_token = %s\n"
                     "threecx_url = http://127.0.0.1:%d\n"
                     "threecx_ws_url = ws://127.0.0.1:%d/callcontrol/ws\n"
                     "threecx_client_id = %s\n"
                     "threecx_client_secret = %s\n"
                     "threecx_reconnect_seconds = 1\n"
                     "threecx_terminal_grace_seconds = 1\n"
                     "threecx_trunk_did_map = 10001=+16125550100\n"
                     "heartbeat_seconds = 5\n"
                     "health_port = %d\n"
                     "log_level = DEBUG\n%s" % (cad.url, BEARER, pbx.http_port, pbx.ws_port,
                                                CLIENT_ID, CLIENT_SECRET, free_port(), extra_ini))
        self.out_path = os.path.join(self.dir, "out.txt")
        self.out = open(self.out_path, "w")
        self.proc = None

    def start(self, *args):
        self.proc = subprocess.Popen([sys.executable, BRIDGE, "--config", self.ini] + list(args),
                                     stdout=self.out, stderr=subprocess.STDOUT, cwd=os.path.dirname(BRIDGE))
        return self

    def run_to_completion(self, *args, timeout=30):
        p = subprocess.run([sys.executable, BRIDGE, "--config", self.ini] + list(args),
                           capture_output=True, text=True, timeout=timeout, cwd=os.path.dirname(BRIDGE))
        return p.returncode, p.stdout + p.stderr

    def stop(self):
        if self.proc and self.proc.poll() is None:
            self.proc.terminate()
            try:
                self.proc.wait(timeout=10)
            except subprocess.TimeoutExpired:
                self.proc.kill()
        self.out.close()

    def log(self):
        self.out.flush()
        with open(self.out_path, encoding="utf-8", errors="replace") as fh:
            return fh.read()


def wait_for(predicate, timeout=25.0):
    end = time.time() + timeout
    while time.time() < end:
        if predicate():
            return True
        time.sleep(0.1)
    return False


def kinds(cad):
    return [e["event"] for e in cad.call_events()]


class ThreeCxBridgeE2E(unittest.TestCase):
    def setUp(self):
        self.cleanups = []

    def tearDown(self):
        for fn in reversed(self.cleanups):
            try:
                fn()
            except Exception:
                pass

    def launch(self, scripts, extra_ini="", cad_kw=None, **pbx_kw):
        cad = MockTicketsCad(**(cad_kw or {}))
        pbx = Mock3cx(scripts, **pbx_kw)
        bridge = BridgeProcess(cad, pbx, extra_ini=extra_ini)
        self.cleanups += [cad.close, pbx.close, bridge.stop]
        return cad, pbx, bridge

    # ── live events ──────────────────────────────────────────

    def test_events_with_null_data_are_fetched_and_forwarded_in_order(self):
        script = [("upsert", "101", participant(7, "101", "Ringing")),
                  ("upsert", "101", participant(7, "101", "Connected")),
                  ("remove", "101", 7)]
        cad, pbx, bridge = self.launch([script])
        bridge.start()
        ok = wait_for(lambda: kinds(cad) == ["ringing", "claimed_externally", "ended"])
        self.assertTrue(ok, "events: %s\n--- bridge log ---\n%s" % (cad.call_events(), bridge.log()))
        ring, claim, end = cad.call_events()
        self.assertEqual(ring["caller_number"], "+16125551234")
        self.assertEqual(ring["called_number"], "+16125550100", "dialed number comes from threecx_trunk_did_map")
        self.assertEqual(claim["answered_by_dn"], "101")
        self.assertEqual(len({e["call_id"] for e in (ring, claim, end)}), 1)
        self.assertTrue(all(h == "Bearer " + BEARER for h in cad.auth_headers))
        self.assertEqual(pbx.ws_auth_headers[0], "Bearer " + pbx.access_token)
        self.assertGreaterEqual(pbx.participant_gets, 2, "the bridge must GET each participant: events carry none")
        self.assertTrue(wait_for(lambda: cad.heartbeats()), "no heartbeat reached TicketsCAD")
        self.assertIn("threecx", cad.heartbeats()[0]["bridge"])

    def test_a_call_already_ringing_when_the_bridge_connects_is_not_lost(self):
        cad, pbx, bridge = self.launch([[]], preload=[("101", participant(7, "101", "Ringing"))])
        bridge.start()
        self.assertTrue(wait_for(lambda: kinds(cad) == ["ringing"]), bridge.log())

    def test_ring_group_of_three_is_one_ring_one_claim_one_end(self):
        script = [("upsert", "101", participant(7, "101", "Ringing", legid=2)),
                  ("upsert", "102", participant(8, "102", "Ringing", legid=3)),
                  ("upsert", "102", participant(8, "102", "Connected", legid=3)),
                  ("remove", "101", 7),
                  ("sleep", 1.5),
                  ("remove", "102", 8)]
        cad, pbx, bridge = self.launch([script])
        bridge.start()
        self.assertTrue(wait_for(lambda: kinds(cad) == ["ringing", "claimed_externally", "ended"]),
                        "events: %s\n%s" % (kinds(cad), bridge.log()))

    # ── resilience ───────────────────────────────────────────

    def test_state_survives_a_dropped_websocket(self):
        first = [("upsert", "101", participant(7, "101", "Ringing")), "CLOSE"]
        second = [("upsert", "101", participant(7, "101", "Connected")), ("remove", "101", 7)]
        cad, pbx, bridge = self.launch([first, second])
        bridge.start()
        ok = wait_for(lambda: kinds(cad) == ["ringing", "claimed_externally", "ended"], timeout=30)
        self.assertTrue(ok, "events: %s\n%s" % (kinds(cad), bridge.log()))
        self.assertGreaterEqual(pbx.connections, 2, "bridge should have reconnected")
        self.assertEqual(len({e["call_id"] for e in cad.call_events()}), 1)

    def test_a_hangup_nobody_reported_is_recovered_by_the_reconcile_watchdog(self):
        script = [("upsert", "101", participant(7, "101", "Ringing")),
                  ("sleep", 0.3),
                  ("vanish", "101", 7)]            # caller hangs up; 3CX sends no Remove
        cad, pbx, bridge = self.launch([script], extra_ini="threecx_reconcile_seconds = 2\n")
        bridge.start()
        ok = wait_for(lambda: kinds(cad) == ["ringing", "abandoned"], timeout=30)
        self.assertTrue(ok, "events: %s\n%s" % (kinds(cad), bridge.log()))

    def test_delivery_is_retried_and_stays_in_order_when_ticketscad_blips(self):
        script = [("upsert", "101", participant(7, "101", "Ringing")),
                  ("upsert", "101", participant(7, "101", "Connected")),
                  ("remove", "101", 7)]
        cad, pbx, bridge = self.launch([script], cad_kw={"fail_first_calls": 2})
        bridge.start()
        ok = wait_for(lambda: kinds(cad) == ["ringing", "claimed_externally", "ended"], timeout=40)
        self.assertTrue(ok, "events: %s (rejected %d)\n%s" % (kinds(cad), cad.rejected, bridge.log()))
        self.assertEqual(cad.rejected, 2, "the first two POSTs were refused, then retried")

    def test_the_token_is_not_re_requested_every_few_seconds(self):
        # The captured reply says expires_in: 60 -- minutes on those builds, but 3CX's
        # own SDK reads it as seconds. Reading it as seconds would mean a token request
        # on every reconcile; the bridge must treat small values as minutes.
        cad, pbx, bridge = self.launch([[]], extra_ini="threecx_reconcile_seconds = 2\n")
        bridge.start()
        self.assertTrue(wait_for(lambda: pbx.connections >= 1))
        time.sleep(7)
        self.assertEqual(pbx.token_requests, 1, bridge.log())

    # ── capture ──────────────────────────────────────────────

    def test_capture_file_records_real_frames_with_callers_masked(self):
        script = [("upsert", "101", participant(7, "101", "Ringing")), ("remove", "101", 7)]
        cad, pbx, bridge = self.launch([script], extra_ini="capture_file = CAPTUREPATH\n")
        with open(bridge.ini, encoding="utf-8") as fh:
            text = fh.read().replace("CAPTUREPATH", bridge.capture.replace("\\", "/"))
        with open(bridge.ini, "w", encoding="utf-8") as fh:
            fh.write(text)
        bridge.start()
        self.assertTrue(wait_for(lambda: kinds(cad) == ["ringing", "abandoned"]), bridge.log())
        raw = open(bridge.capture, encoding="utf-8").read()
        recs = [json.loads(line) for line in raw.splitlines() if line.strip()]
        self.assertTrue(any(r["kind"] == "ws" for r in recs), "no WebSocket frame was captured")
        self.assertTrue(any(r["kind"] == "get" for r in recs), "no REST reply was captured")
        self.assertIn('"status": "Ringing"', raw, "structure and statuses must survive redaction")
        self.assertNotIn("6125551234", raw, "caller number leaked into the capture file")
        self.assertNotIn("JONES", raw, "caller name leaked into the capture file")
        self.assertNotIn("10.0.0.21", raw, "device address leaked into the capture file")

    # ── the --check doctor ───────────────────────────────────

    def test_check_passes_when_everything_is_right(self):
        cad, pbx, bridge = self.launch([[]], token_role=["App", "Enterprise", "CallFlowApp"])
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 0, out)
        self.assertIn("token is accepted (trunk 'Main Dispatch Line')", out)
        self.assertIn("3CX accepted the API credentials", out)
        self.assertIn("extensions this API client can see: 101, 102", out)
        self.assertIn("accepted the connection", out)
        self.assertNotIn("[FAIL]", out)

    def test_check_names_the_bad_3cx_credentials(self):
        cad, pbx, bridge = self.launch([[]], secret_ok=False)
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 1, out)
        self.assertIn("3CX rejected the API credentials", out)
        self.assertIn("threecx_client_id", out)

    def test_check_names_a_bad_ticketscad_token_and_stops_there(self):
        cad, pbx, bridge = self.launch([[]], cad_kw={"token_ok": False})
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 1, out)
        self.assertIn("rejected the bearer token", out)
        self.assertNotIn("3CX accepted", out, "PBX checks must not run while TicketsCAD is unreachable")

    def test_check_warns_when_the_licence_looks_wrong(self):
        cad, pbx, bridge = self.launch([[]], token_role=["App", "Local", "Paid"])
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 1, out)
        self.assertIn("no 'Enterprise' role", out)
        self.assertIn("AI Edition", out)

    def test_check_explains_the_empty_extension_list_trap(self):
        cad, pbx, bridge = self.launch([[]], extensions=())
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 1, out)
        self.assertIn("lists NO extensions", out)
        self.assertIn("extensions to monitor", out)

    def test_check_flags_a_monitored_dn_the_api_client_cannot_see(self):
        cad, pbx, bridge = self.launch([[]], extra_ini="threecx_monitor_dns = 101,999\n")
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 1, out)
        self.assertIn("DN 999", out)

    def test_secret_with_percent_and_hash_survives_the_ini_file(self):
        # CLIENT_SECRET contains '%' and '#'. The mock only issues a token for the
        # exact value, so a green --check proves it arrived intact.
        cad, pbx, bridge = self.launch([[]])
        code, out = bridge.run_to_completion("--check")
        self.assertEqual(code, 0, out)


if __name__ == "__main__":
    unittest.main(verbosity=2)
