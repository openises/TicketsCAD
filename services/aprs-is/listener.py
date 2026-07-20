#!/usr/bin/env python3
"""
TicketsCAD APRS-IS Persistent TCP Listener
==========================================

Replaces the 5-minute aprs.fi REST poller (tools/aprs-poller.php) with a
persistent TCP connection to APRS-IS. Real-time delivery: a packet hits
the dashboard within ~1 second of being received instead of up to 5
minutes later.

How it works
------------
1. Open TCP socket to rotate.aprs2.net:14580 (or another core server).
2. Send APRS-IS login line:
     user CALL pass PASSCODE vers TicketsCAD 4.0 filter <filter>\\r\\n
   The filter is a server-side filter command (e.g. "b/W7ABC*/KE0XYZ" to
   subscribe to two specific callsigns). See http://www.aprs-is.net/javAPRSFilter.aspx
3. Read lines forever. Server sends comments (starting with `#`) and APRS
   packets. Parse each non-comment line with `aprslib`.
4. For each parsed position packet, POST to /api/location.php with
   action=report, provider_code=aprs.
5. Report listener health every 60s to /api/service-uptime.php.
6. Auto-reconnect with exponential backoff on disconnect.

Configuration
-------------
INI file at services/aprs-is/listener.ini, or environment vars, or CLI args.
Required keys: callsign, passcode, filter.

  [aprs_is]
  server = rotate.aprs2.net
  port = 14580
  callsign = N0CALL
  passcode = 12345               # APRS-IS passcode for callsign
  filter = b/N0CALL/W7ABC*       # server-side filter
  provider_code = aprs

  [ticketscad]
  url = http://localhost/newui
  csrf_token =                   # leave blank for OwnTracks-style key auth
  health_interval = 60

Usage
-----
  python listener.py --config listener.ini
  python listener.py --callsign N0CALL --passcode 12345 --filter "b/N0CALL"

Requirements
------------
  pip install aprslib requests

Service management
------------------
- systemd unit: services/aprs-is/aprs-is.service.example
- Logs to stdout (and optional --log-file) — works under journald
- PID file written to --pid-file (default /tmp/aprs-is-listener.pid)
- SIGTERM/SIGINT triggers clean shutdown
"""

import argparse
import configparser
import json
import logging
import os
import signal
import socket
import sys
import threading
import time
from datetime import datetime, timezone

try:
    import requests
except ImportError:
    requests = None

try:
    import aprslib
    HAS_APRSLIB = True
except ImportError:
    HAS_APRSLIB = False

# ─────────────────────────────────────────────────────────────
#  Defaults
# ─────────────────────────────────────────────────────────────

DEFAULT_CONFIG = {
    "server":        "rotate.aprs2.net",
    "port":          14580,
    "callsign":      "N0CALL",
    "passcode":      "-1",            # -1 = receive-only
    "filter":        "",              # empty = no filter (firehose — discouraged)
    "provider_code": "aprs",
    "url":           "http://localhost/newui",
    "csrf_token":    "",
    "health_interval": 60,
    "reconnect_min": 5,
    "reconnect_max": 300,
    "log_file":      "",
    # Phase 43d (Sonar python:S5443): default to /run, a tmpfs created by
    # systemd for this service. Falls back to /tmp only if /run isn't
    # writable so older sysvinit hosts still work. /run is owned 0755 by
    # the service user under systemd-tmpfiles, which avoids the world-
    # writable-shared-directory race condition Sonar flags about /tmp.
    "pid_file":      "/run/aprs-is-listener/listener.pid",
}

logger = logging.getLogger("aprs-is")

# ─────────────────────────────────────────────────────────────
#  TicketsCAD API client
# ─────────────────────────────────────────────────────────────

class TicketsCADClient:
    """Posts position reports + listener health to TicketsCAD."""

    def __init__(self, cfg):
        self.base_url = cfg["url"].rstrip("/")
        self.provider_code = cfg["provider_code"]
        self._csrf_token = cfg.get("csrf_token", "")
        self.session = requests.Session() if requests else None

    def report_position(self, callsign, lat, lng, altitude=None,
                        speed=None, heading=None, raw_data=None):
        if not self.session:
            logger.error("requests library not installed")
            return False
        payload = {
            "action":          "report",
            "provider_code":   self.provider_code,
            "unit_identifier": callsign,
            "lat":             lat,
            "lng":             lng,
            "altitude":        altitude,
            "speed":           speed,
            "heading":         heading,
            "raw_data":        json.dumps(raw_data) if raw_data else None,
            "csrf_token":      self._csrf_token,
        }
        try:
            r = self.session.post(f"{self.base_url}/api/location.php",
                                  json=payload, timeout=10)
            if r.status_code == 200:
                return True
            logger.warning("API %d: %s", r.status_code, r.text[:200])
        except Exception as e:
            logger.error("POST position failed: %s", e)
        return False

    def report_health(self, status, details=None):
        if not self.session:
            return
        try:
            self.session.post(
                f"{self.base_url}/api/service-uptime.php",
                json={
                    "service":    "aprs-is",
                    "status":     status,
                    "details":    details or {},
                    "csrf_token": self._csrf_token,
                },
                timeout=5,
            )
        except Exception:
            pass

# ─────────────────────────────────────────────────────────────
#  Listener core
# ─────────────────────────────────────────────────────────────

class AprsIsListener:
    """Maintains a persistent TCP connection to APRS-IS, decodes packets,
    forwards positions to the TicketsCAD API. Auto-reconnects on disconnect."""

    def __init__(self, cfg, client):
        self.cfg = cfg
        self.client = client
        self.sock = None
        self.running = False
        self.stats = {
            "connected_at":   None,
            "packets":        0,
            "positions":      0,
            "skipped":        0,
            "errors":         0,
            "reconnects":     0,
            "last_packet_at": None,
        }
        self._stop_evt = threading.Event()

    def stop(self):
        self.running = False
        self._stop_evt.set()
        try:
            if self.sock:
                self.sock.shutdown(socket.SHUT_RDWR)
        except Exception:
            pass
        try:
            if self.sock:
                self.sock.close()
        except Exception:
            pass

    def run(self):
        self.running = True
        backoff = int(self.cfg["reconnect_min"])
        backoff_max = int(self.cfg["reconnect_max"])

        while self.running:
            try:
                self._connect_and_read()
                # Clean disconnect — reset backoff
                backoff = int(self.cfg["reconnect_min"])
            except KeyboardInterrupt:
                logger.info("Interrupted; shutting down.")
                self.running = False
                break
            except Exception as e:
                logger.error("Listener error: %s — reconnect in %ds", e, backoff)
                self.stats["errors"] += 1
                self.client.report_health("disconnected", {
                    "error": str(e), "next_attempt_s": backoff,
                })
            if not self.running:
                break
            self._stop_evt.wait(backoff)
            backoff = min(backoff * 2, backoff_max)
            self.stats["reconnects"] += 1

    def _connect_and_read(self):
        host = self.cfg["server"]
        port = int(self.cfg["port"])
        logger.info("Connecting to APRS-IS %s:%d", host, port)
        self.sock = socket.create_connection((host, port), timeout=30)
        # APRS-IS keepalives via "#" comments every ~20s; raise SO_KEEPALIVE
        self.sock.setsockopt(socket.SOL_SOCKET, socket.SO_KEEPALIVE, 1)
        self.stats["connected_at"] = datetime.now(timezone.utc).isoformat()
        self.client.report_health("running", {"connected_at": self.stats["connected_at"]})

        # Send login
        login = "user {call} pass {pw} vers TicketsCAD 4.0".format(
            call=self.cfg["callsign"], pw=self.cfg["passcode"])
        flt = self.cfg["filter"].strip()
        if flt:
            login += " filter " + flt
        login += "\r\n"
        self.sock.sendall(login.encode("ascii", errors="ignore"))
        logger.info("Sent login: %s", login.strip().replace(self.cfg["passcode"], "***"))

        # Read loop
        buf = b""
        while self.running:
            try:
                chunk = self.sock.recv(4096)
            except socket.timeout:
                # Send a keepalive comment to detect dead connection
                try:
                    self.sock.sendall(b"# keepalive\r\n")
                except Exception:
                    raise ConnectionError("keepalive write failed")
                continue
            if not chunk:
                raise ConnectionError("server closed connection")
            buf += chunk
            while b"\n" in buf:
                line, buf = buf.split(b"\n", 1)
                self._handle_line(line.strip().decode("utf-8", errors="replace"))

    def _handle_line(self, line):
        if not line:
            return
        if line.startswith("#"):
            # Server comment / keepalive — keep stats but don't parse
            return
        self.stats["packets"] += 1
        self.stats["last_packet_at"] = datetime.now(timezone.utc).isoformat()

        if not HAS_APRSLIB:
            self.stats["skipped"] += 1
            return
        try:
            pkt = aprslib.parse(line)
        except (aprslib.ParseError, aprslib.UnknownFormat):
            self.stats["skipped"] += 1
            return
        except Exception as e:
            self.stats["errors"] += 1
            logger.debug("parse error: %s", e)
            return

        if "latitude" not in pkt or "longitude" not in pkt:
            self.stats["skipped"] += 1
            return

        callsign = pkt.get("from")
        if not callsign:
            self.stats["skipped"] += 1
            return

        lat = pkt["latitude"]
        lng = pkt["longitude"]
        alt = pkt.get("altitude")
        spd = pkt.get("speed")
        crs = pkt.get("course")

        ok = self.client.report_position(
            callsign=callsign, lat=lat, lng=lng,
            altitude=alt, speed=spd, heading=crs,
            raw_data={"aprs": line[:512]},
        )
        if ok:
            self.stats["positions"] += 1

# ─────────────────────────────────────────────────────────────
#  Config + bootstrap
# ─────────────────────────────────────────────────────────────

def load_config(path, args):
    cfg = dict(DEFAULT_CONFIG)
    if path and os.path.exists(path):
        cp = configparser.ConfigParser()
        cp.read(path)
        if cp.has_section("aprs_is"):
            for k in cp.options("aprs_is"):
                cfg[k] = cp.get("aprs_is", k)
        if cp.has_section("ticketscad"):
            for k in cp.options("ticketscad"):
                cfg[k] = cp.get("ticketscad", k)
    # Env overrides
    for k in cfg:
        ek = "APRSIS_" + k.upper()
        if ek in os.environ and os.environ[ek] != "":
            cfg[k] = os.environ[ek]
    # CLI overrides
    if args.callsign:      cfg["callsign"] = args.callsign
    if args.passcode:      cfg["passcode"] = args.passcode
    if args.filter:        cfg["filter"]   = args.filter
    if args.server:        cfg["server"]   = args.server
    if args.port:          cfg["port"]     = args.port
    if args.url:           cfg["url"]      = args.url
    if args.csrf_token:    cfg["csrf_token"] = args.csrf_token
    if args.provider_code: cfg["provider_code"] = args.provider_code
    if args.log_file:      cfg["log_file"] = args.log_file
    if args.pid_file:      cfg["pid_file"] = args.pid_file
    return cfg

def setup_logging(cfg, verbose):
    fmt = "%(asctime)s %(levelname)s %(message)s"
    level = logging.DEBUG if verbose else logging.INFO
    handlers = [logging.StreamHandler(sys.stdout)]
    if cfg.get("log_file"):
        handlers.append(logging.FileHandler(cfg["log_file"]))
    logging.basicConfig(level=level, format=fmt, handlers=handlers)

def write_pidfile(path):
    # Phase 43d (Sonar python:S5443): make sure the directory exists before
    # writing. /run/aprs-is-listener won't exist on first boot unless
    # systemd-tmpfiles or the unit's RuntimeDirectory= creates it.
    # If the configured path is unwritable (e.g. /run not mounted on a
    # sysvinit host), fall back to /tmp/<basename> so the service still
    # starts — and log the fallback so the operator sees it.
    try:
        d = os.path.dirname(path) or "."
        if d and not os.path.isdir(d):
            try:
                os.makedirs(d, mode=0o755, exist_ok=True)
            except Exception:
                fallback = os.path.join("/tmp", os.path.basename(path))
                logger.warning("pidfile dir %s not writable; falling back to %s", d, fallback)
                path = fallback
        with open(path, "w") as f:
            f.write(str(os.getpid()))
    except Exception as e:
        logger.warning("Could not write pidfile %s: %s", path, e)

def remove_pidfile(path):
    try:
        if os.path.exists(path):
            os.remove(path)
    except Exception:
        pass

def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--config")
    ap.add_argument("--callsign")
    ap.add_argument("--passcode")
    ap.add_argument("--filter")
    ap.add_argument("--server")
    ap.add_argument("--port", type=int)
    ap.add_argument("--url", help="TicketsCAD base URL")
    ap.add_argument("--csrf-token")
    ap.add_argument("--provider-code")
    ap.add_argument("--log-file")
    ap.add_argument("--pid-file")
    ap.add_argument("-v", "--verbose", action="store_true")
    args = ap.parse_args()

    cfg = load_config(args.config, args)
    setup_logging(cfg, args.verbose)

    if not requests:
        logger.error("requests not installed; pip install requests")
        return 2
    if not HAS_APRSLIB:
        logger.error("aprslib not installed; pip install aprslib")
        return 2

    write_pidfile(cfg["pid_file"])
    client = TicketsCADClient(cfg)
    listener = AprsIsListener(cfg, client)

    # Periodic health reporter
    def health_loop():
        interval = int(cfg["health_interval"])
        while listener.running:
            time.sleep(interval)
            listener.client.report_health("running", dict(listener.stats))
    threading.Thread(target=health_loop, daemon=True).start()

    def handle_sig(signum, frame):
        logger.info("Caught signal %s; shutting down", signum)
        listener.client.report_health("stopped", dict(listener.stats))
        listener.stop()
        remove_pidfile(cfg["pid_file"])
    signal.signal(signal.SIGINT, handle_sig)
    signal.signal(signal.SIGTERM, handle_sig)

    try:
        listener.run()
    finally:
        remove_pidfile(cfg["pid_file"])
    return 0

if __name__ == "__main__":
    sys.exit(main())
