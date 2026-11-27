"""
Unit tests for services/sip-bridge/threecx.py's TLS handling.

The 3CX client is secure by default (ssl.create_default_context() verifies the
chain and the hostname, and requests verify=True). These tests pin that default,
pin the deliberate self-signed opt-out (threecx_verify_tls = false), and pin the
hardening added 2026-11-08:
  * threecx_ca_bundle -- validate against a PINNED certificate instead of
    disabling verification; it takes precedence over verify_tls.
  * an explicit TLS 1.2 floor on the SSL context.
  * a warning whenever verification is disabled with no ca_bundle.

Run:  python services/sip-bridge/tests/test_threecx_tls.py
"""
import os
import ssl
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from threecx import _ssl_context, build_client, ThreeCxClient  # noqa: E402


class _RecordingLog:
    def __init__(self):
        self.warnings = []

    def warning(self, msg, *a, **k):
        self.warnings.append(msg % a if a else msg)

    # the client only ever calls .warning in these paths; stub the rest harmlessly
    def info(self, *a, **k):
        pass

    def error(self, *a, **k):
        pass


class ThreeCxTlsTest(unittest.TestCase):

    def test_secure_by_default(self):
        ctx = _ssl_context(True)
        self.assertTrue(ctx.check_hostname, "hostname verification must be ON by default")
        self.assertEqual(ctx.verify_mode, ssl.CERT_REQUIRED,
                         "certificate validation must be REQUIRED by default")

    def test_tls_12_floor(self):
        ctx = _ssl_context(True)
        # create_default_context already floors at 1.2 on modern Python; we set
        # it explicitly so it is pinned regardless of interpreter age.
        self.assertGreaterEqual(ctx.minimum_version, ssl.TLSVersion.TLSv1_2)

    def test_opt_out_disables_and_warns(self):
        log = _RecordingLog()
        ctx = _ssl_context(False, log=log)
        self.assertFalse(ctx.check_hostname)
        self.assertEqual(ctx.verify_mode, ssl.CERT_NONE)
        self.assertTrue(any("DISABLED" in w for w in log.warnings),
                        "disabling verification must emit a warning")

    def test_ca_bundle_takes_precedence_over_opt_out(self):
        # With a ca_bundle set, verification is NOT disabled even if verify_tls
        # is false: the context tries to LOAD the bundle. A bad path proves the
        # bundle is actually used rather than the CERT_NONE branch taken.
        with self.assertRaises((FileNotFoundError, ssl.SSLError, OSError)):
            _ssl_context(False, ca_bundle="/no/such/ca-bundle.pem")

    def test_ca_bundle_used_even_when_verify_true(self):
        with self.assertRaises((FileNotFoundError, ssl.SSLError, OSError)):
            _ssl_context(True, ca_bundle="/no/such/ca-bundle.pem")

    def test_requests_verify_arg_resolution(self):
        # The requests calls pass verify=(self.ca_bundle or self.verify_tls).
        c_pinned = ThreeCxClient("https://pbx", "id", "sec", verify_tls=False,
                                 ca_bundle="/etc/3cx/server.pem")
        self.assertEqual(c_pinned.ca_bundle, "/etc/3cx/server.pem")
        self.assertEqual(c_pinned.ca_bundle or c_pinned.verify_tls, "/etc/3cx/server.pem")

        c_default = ThreeCxClient("https://pbx", "id", "sec")
        self.assertIsNone(c_default.ca_bundle)
        self.assertIs(c_default.ca_bundle or c_default.verify_tls, True)

        c_disabled = ThreeCxClient("https://pbx", "id", "sec", verify_tls=False)
        self.assertIs(c_disabled.ca_bundle or c_disabled.verify_tls, False)

    def test_build_client_reads_ca_bundle(self):
        cfg = {
            "threecx_url": "https://pbx.example.test",
            "threecx_client_id": "cid",
            "threecx_client_secret": "secret",
            "threecx_ca_bundle": "/etc/3cx/server.pem",
            "threecx_verify_tls": True,
        }
        client, _cap = build_client(cfg, log=_RecordingLog())
        self.assertEqual(client.ca_bundle, "/etc/3cx/server.pem")
        self.assertTrue(client.verify_tls)

    def test_build_client_warns_when_disabled_without_bundle(self):
        log = _RecordingLog()
        cfg = {
            "threecx_url": "https://pbx.example.test",
            "threecx_client_id": "cid",
            "threecx_client_secret": "secret",
            "threecx_verify_tls": False,
        }
        client, _cap = build_client(cfg, log=log)
        self.assertIsNone(client.ca_bundle)
        self.assertFalse(client.verify_tls)
        self.assertTrue(any("NOT verify" in w for w in log.warnings),
                        "build_client must warn when verification is off with no ca_bundle")


if __name__ == "__main__":
    unittest.main(verbosity=2)
