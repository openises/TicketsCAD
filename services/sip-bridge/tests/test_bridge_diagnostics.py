"""
Diagnostics tests for services/sip-bridge/bridge.py (bridge 1.1.1).

Found by a real support report: an administrator ran `bridge.py --check` and was told "TicketsCAD rejected
the bearer token (HTTP 403)". The bridge printed that sentence for ANY 401 or 403, so an answer from a
firewall, Cloudflare or a reverse proxy -- which never reached TicketsCAD at all, and never looked at the
token -- was reported as a token problem. These tests stand up a tiny local server that answers the way each
of those really answers, and require the bridge to say which one it was.

Also covered: the Setup window's literal placeholder token, and a token pasted into bridge.ini with quote
marks around it (the quote marks become part of the token and TicketsCAD answers 'bad bearer').

Run:  python services/sip-bridge/tests/test_bridge_diagnostics.py
"""
import argparse
import contextlib
import io
import json
import os
import sys
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, HTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
BRIDGE_DIR = os.path.dirname(HERE)
sys.path.insert(0, BRIDGE_DIR)

try:
    import bridge  # noqa: E402
    import requests  # noqa: E402,F401
except ImportError as exc:  # pragma: no cover
    print("SKIP: %s" % exc)
    sys.exit(0)

TOKEN = "s3cr3t-token-VALUE-0123456789"


class FakeIngest:
    """Answers every POST with one canned reply, and remembers what it was sent."""

    def __init__(self, status, headers, body):
        outer = self
        self.seen_auth = None

        class H(BaseHTTPRequestHandler):
            def log_message(self, *a):
                pass

            def do_POST(self):
                length = int(self.headers.get("Content-Length", 0) or 0)
                if length:
                    self.rfile.read(length)
                outer.seen_auth = self.headers.get("Authorization")
                self.send_response(status)
                for k, v in headers.items():
                    self.send_header(k, v)
                data = body.encode("utf-8")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                self.wfile.write(data)

        self.httpd = HTTPServer(("127.0.0.1", 0), H)
        self.url = "http://127.0.0.1:%d" % self.httpd.server_address[1]
        threading.Thread(target=self.httpd.serve_forever, daemon=True).start()

    def close(self):
        self.httpd.shutdown()
        self.httpd.server_close()


def cfg_for(url, token=TOKEN):
    cfg = dict(bridge.DEFAULT_CONFIG)
    cfg.update({"ticketscad_url": url, "bearer_token": token, "mode": "webhook", "http_timeout_seconds": 5})
    return cfg


def heartbeat_against(status, headers, body):
    fake = FakeIngest(status, headers, body)
    try:
        ok, detail, reply = bridge.send_heartbeat(cfg_for(fake.url))
        return ok, detail, fake
    finally:
        fake.close()


JSON = {"Content-Type": "application/json"}
HTML = {"Content-Type": "text/html; charset=UTF-8"}


class HeartbeatExplanationTest(unittest.TestCase):
    def test_ticketscads_own_bad_bearer_is_reported_as_a_token_problem(self):
        ok, detail, fake = heartbeat_against(403, JSON, '{"error":"bad bearer"}')
        self.assertFalse(ok)
        self.assertIn("rejected the bearer token", detail)
        self.assertIn("bad bearer", detail)
        self.assertIn("Rotate Token", detail)
        self.assertIn(bridge.PLACEHOLDER_TOKEN, detail, "the placeholder is the first thing to check")
        self.assertEqual(fake.seen_auth, "Bearer " + TOKEN, "the token must reach the server intact")

    def test_a_missing_authorization_header_is_not_blamed_on_the_token(self):
        ok, detail, _ = heartbeat_against(401, JSON, '{"error":"Bearer token required"}')
        self.assertFalse(ok)
        self.assertIn("Authorization header did not arrive", detail)
        self.assertIn("has not been checked", detail)
        self.assertNotIn("rejected the bearer token", detail)

    def test_a_cloudflare_block_is_named_as_cloudflare_and_not_as_a_token_problem(self):
        page = "<html><head><title>Attention Required! | Cloudflare</title></head><body><h1>Sorry, you have been blocked</h1></body></html>"
        ok, detail, _ = heartbeat_against(403, dict(HTML, Server="cloudflare", **{"CF-RAY": "8abc-ORD"}), page)
        self.assertFalse(ok)
        self.assertIn("Cloudflare", detail)
        self.assertIn("not TicketsCAD's own answer", detail)
        self.assertIn("Attention Required! | Cloudflare", detail, "the page title tells the administrator what blocked them")
        self.assertIn("/api/sip-ingest.php", detail, "names the path to allow through")
        self.assertNotIn("rejected the bearer token", detail)

    def test_a_proxy_or_web_server_block_names_the_server(self):
        page = "<html><head><title>403 Forbidden</title></head><body><center><h1>403 Forbidden</h1></center><hr><center>nginx</center></body></html>"
        ok, detail, _ = heartbeat_against(403, dict(HTML, Server="nginx/1.24.0"), page)
        self.assertFalse(ok)
        self.assertIn("nginx/1.24.0", detail)
        self.assertIn("not TicketsCAD's own answer", detail)
        self.assertIn("403 Forbidden", detail)
        self.assertNotIn("rejected the bearer token", detail)

    def test_http_basic_auth_in_front_of_ticketscad_is_a_block_not_a_token_problem(self):
        ok, detail, _ = heartbeat_against(401, dict(HTML, Server="Apache/2.4.58", **{"WWW-Authenticate": 'Basic realm="x"'}),
                                          "<html><head><title>401 Unauthorized</title></head></html>")
        self.assertFalse(ok)
        self.assertIn("not TicketsCAD's own answer", detail)
        self.assertIn("401 Unauthorized", detail)

    def test_an_empty_403_body_is_still_explained(self):
        ok, detail, _ = heartbeat_against(403, {}, "")
        self.assertFalse(ok)
        self.assertIn("not TicketsCAD's own answer", detail)
        self.assertIn("(empty)", detail)

    def test_control_characters_from_the_server_never_reach_the_terminal(self):
        body = "<title>blocked\x1b[31m RED \x1b]0;owned\x07</title>"
        ok, detail, _ = heartbeat_against(403, HTML, body)
        self.assertFalse(ok)
        self.assertNotIn("\x1b", detail)
        self.assertNotIn("\x07", detail)
        self.assertIn("blocked", detail)

    def test_json_that_is_not_ticketscads_error_is_not_mistaken_for_it(self):
        ok, detail, _ = heartbeat_against(403, JSON, '{"error":"Forbidden","message":"WAF rule 942100"}')
        self.assertFalse(ok)
        self.assertIn("not TicketsCAD's own answer", detail)

    def test_no_message_ever_contains_the_token(self):
        cases = [
            (403, JSON, '{"error":"bad bearer"}'),
            (401, JSON, '{"error":"Bearer token required"}'),
            (403, HTML, "<title>blocked</title>"),
            (403, dict(HTML, Server="cloudflare"), "blocked"),
        ]
        for status, headers, body in cases:
            _, detail, _ = heartbeat_against(status, headers, body)
            self.assertNotIn(TOKEN, detail, "HTTP %d message leaked the token" % status)

    def test_a_good_token_still_succeeds(self):
        ok, detail, fake = heartbeat_against(
            200, JSON, '{"ok":true,"heartbeat":true,"recorded":true,"trunk_id":3,"trunk_label":"Main","trunk_enabled":true}')
        self.assertTrue(ok, detail)
        self.assertEqual(detail, "ok")

    def test_other_statuses_keep_their_existing_messages(self):
        _, d404, _ = heartbeat_against(404, HTML, "<title>Not Found</title>")
        self.assertIn("ticketscad_url should be the folder that contains login.php", d404)
        _, d429, _ = heartbeat_against(429, JSON, "{}")
        self.assertIn("rate-limiting", d429)


class CheckCommandTest(unittest.TestCase):
    def run_check(self, cfg):
        buf = io.StringIO()
        with contextlib.redirect_stdout(buf):
            code = bridge.run_check(cfg)
        return code, buf.getvalue()

    def test_the_setup_placeholder_stops_the_check_before_any_network_call(self):
        # Port 9 on loopback: nothing listens. If the check tried the network the message would be about
        # reaching TicketsCAD, not about the placeholder.
        code, out = self.run_check(cfg_for("http://127.0.0.1:9", token=bridge.PLACEHOLDER_TOKEN))
        self.assertEqual(code, 1)
        self.assertIn("bearer_token still says " + bridge.PLACEHOLDER_TOKEN, out)
        self.assertIn("Rotate Token", out)
        self.assertNotIn("cannot reach", out)
        self.assertNotIn("[ OK ]  bearer_token is set", out)

    def test_a_lowercase_or_edited_placeholder_is_caught_too(self):
        code, out = self.run_check(cfg_for("http://127.0.0.1:9", token="paste-the-token-here"))
        self.assertEqual(code, 1)
        self.assertIn("still says", out)

    def test_check_reports_a_block_in_front_of_ticketscad_and_stops(self):
        fake = FakeIngest(403, dict(HTML, Server="cloudflare"), "<title>Attention Required! | Cloudflare</title>")
        try:
            code, out = self.run_check(cfg_for(fake.url))
        finally:
            fake.close()
        self.assertEqual(code, 1)
        self.assertIn("[ OK ]  bearer_token is set", out)
        self.assertIn("Cloudflare", out)
        self.assertIn("not TicketsCAD's own answer", out)
        self.assertNotIn(TOKEN, out)
        self.assertIn("PBX checks below are skipped", out)


class TokenCleaningTest(unittest.TestCase):
    def test_clean_token(self):
        self.assertEqual(bridge.clean_token('abc123'), 'abc123')
        self.assertEqual(bridge.clean_token('  abc123  '), 'abc123')
        self.assertEqual(bridge.clean_token('"abc123"'), 'abc123')
        self.assertEqual(bridge.clean_token("'abc123'"), 'abc123')
        self.assertEqual(bridge.clean_token(' " abc123 " '), 'abc123')
        self.assertEqual(bridge.clean_token(''), '')
        self.assertEqual(bridge.clean_token(None), '')

    def test_clean_token_does_not_eat_characters_that_belong_to_the_token(self):
        self.assertEqual(bridge.clean_token('ab"c'), 'ab"c')
        self.assertEqual(bridge.clean_token('"abc'), '"abc')
        self.assertEqual(bridge.clean_token('abc"'), 'abc"')
        self.assertEqual(bridge.clean_token('"abc\''), '"abc\'', "mismatched quotes are not a pair")
        self.assertEqual(bridge.clean_token('"'), '"')

    def test_a_quoted_token_in_bridge_ini_reaches_the_server_without_the_quotes(self):
        fd, path = tempfile.mkstemp(suffix=".ini")
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            fh.write('[sip-bridge]\nmode = webhook\nbearer_token = "%s"\n' % TOKEN)
        try:
            ns = argparse.Namespace(config=path)
            for name in ("mode", "ticketscad_url", "bearer_token", "ami_host", "ami_port", "ami_user",
                         "ami_secret", "listen_port", "provider", "log_level"):
                setattr(ns, name, None)
            cfg = bridge.load_config(ns)
        finally:
            os.unlink(path)
        self.assertEqual(cfg["bearer_token"], TOKEN)
        fake = FakeIngest(200, JSON, '{"ok":true,"heartbeat":true}')
        try:
            cfg["ticketscad_url"] = fake.url
            ok, detail, _ = bridge.send_heartbeat(cfg)
        finally:
            fake.close()
        self.assertTrue(ok, detail)
        self.assertEqual(fake.seen_auth, "Bearer " + TOKEN)

    def test_a_token_given_on_the_command_line_is_cleaned_too(self):
        ns = argparse.Namespace(config=None)
        for name in ("mode", "ticketscad_url", "ami_host", "ami_port", "ami_user", "ami_secret",
                     "listen_port", "provider", "log_level"):
            setattr(ns, name, None)
        ns.bearer_token = ' "%s" ' % TOKEN
        self.assertEqual(bridge.load_config(ns)["bearer_token"], TOKEN)

    def test_the_version_moved_so_a_customers_check_output_shows_they_have_this_fix(self):
        self.assertEqual(bridge.BRIDGE_VERSION, "1.1.1")


if __name__ == "__main__":
    unittest.main(verbosity=1)
