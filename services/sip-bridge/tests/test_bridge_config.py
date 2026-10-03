"""
Config-file tests for services/sip-bridge/bridge.py: the shipped
bridge.ini.example must parse and load every key it documents, and secrets
containing characters configparser treats specially must survive intact.

Run:  python services/sip-bridge/tests/test_bridge_config.py
"""
import argparse
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
BRIDGE_DIR = os.path.dirname(HERE)
sys.path.insert(0, BRIDGE_DIR)

try:
    import bridge  # noqa: E402
except ImportError as exc:  # pragma: no cover
    print("SKIP: %s" % exc)
    sys.exit(0)


def args_for(path):
    ns = argparse.Namespace()
    for name in ("mode", "ticketscad_url", "bearer_token", "ami_host", "ami_port", "ami_user",
                 "ami_secret", "listen_port", "provider", "log_level"):
        setattr(ns, name, None)
    ns.config = path
    return ns


def write_ini(text):
    fd, path = tempfile.mkstemp(suffix=".ini")
    with os.fdopen(fd, "w", encoding="utf-8") as fh:
        fh.write(text)
    return path


class BridgeConfigTest(unittest.TestCase):
    def test_the_shipped_example_parses_and_every_key_is_a_known_option(self):
        path = os.path.join(BRIDGE_DIR, "bridge.ini.example")
        import configparser
        parser = configparser.ConfigParser(interpolation=None, inline_comment_prefixes=("#", ";"))
        parser.read(path, encoding="utf-8")
        self.assertTrue(parser.has_section("sip-bridge"))
        unknown = [k for k, _ in parser.items("sip-bridge") if k not in bridge.DEFAULT_CONFIG]
        self.assertEqual(unknown, [], "bridge.ini.example documents keys bridge.py would silently ignore")
        cfg = bridge.load_config(args_for(path))
        self.assertEqual(cfg["mode"], "ami")
        self.assertEqual(cfg["heartbeat_seconds"], 30)

    def test_every_threecx_key_in_the_example_is_a_real_setting(self):
        text = open(os.path.join(BRIDGE_DIR, "bridge.ini.example"), encoding="utf-8").read()
        for key in ("threecx_url", "threecx_client_id", "threecx_client_secret",
                    "threecx_monitor_dns", "threecx_verify_tls"):
            self.assertIn(key, text)
            self.assertIn(key, bridge.DEFAULT_CONFIG)

    def test_percent_and_hash_in_a_secret_survive(self):
        path = write_ini("[sip-bridge]\nmode = threecx\nthreecx_client_secret = ab%cd#ef;gh\nbearer_token = t%k\n")
        cfg = bridge.load_config(args_for(path))
        self.assertEqual(cfg["threecx_client_secret"], "ab%cd#ef;gh")
        self.assertEqual(cfg["bearer_token"], "t%k")

    def test_inline_comment_after_whitespace_is_stripped(self):
        path = write_ini("[sip-bridge]\nthreecx_client_id = 800   # the API client DN\n")
        self.assertEqual(bridge.load_config(args_for(path))["threecx_client_id"], "800")

    def test_booleans_and_ints_are_typed(self):
        path = write_ini("[sip-bridge]\nthreecx_verify_tls = false\nheartbeat_seconds = 12\n")
        cfg = bridge.load_config(args_for(path))
        self.assertIs(cfg["threecx_verify_tls"], False)
        self.assertEqual(cfg["heartbeat_seconds"], 12)

    def test_cli_overrides_the_file(self):
        path = write_ini("[sip-bridge]\nmode = ami\n")
        ns = args_for(path)
        ns.mode = "threecx"
        self.assertEqual(bridge.load_config(ns)["mode"], "threecx")


if __name__ == "__main__":
    unittest.main(verbosity=2)
