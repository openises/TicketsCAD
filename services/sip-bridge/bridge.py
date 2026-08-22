#!/usr/bin/env python3
"""
TicketsCAD Inbound SIP/PBX Call Bridge (Phase 149)

Normalizes one PBX/SIP trunk's native inbound-call events into the ONE
canonical JSON shape api/sip-ingest.php accepts, and POSTs it there with
the trunk's bearer token. TicketsCAD's PHP tree never speaks SIP, AMI, or
ARI directly -- this process is the only thing that does, matching the
"small bridge process talks to the vendor, PHP never does" pattern this
project already uses for the DMR radio bridge and the Meshtastic bridge
(services/meshtastic/bridge.py, whose config-file/service-file/health-port
shape this script deliberately mirrors).

Canonical event contract (specs/phase-149-inbound-sip-calls/plan.md §2):
    {
        "event": "ringing" | "claimed_externally" | "ended" | "abandoned",
        "call_id": "<PBX Uniqueid/Linkedid or SIP Call-ID>",
        "caller_number": "+16125551234",
        "caller_name": "CNAM string or null",
        "called_number": "<DID dialed>",
        "event_ts": "2026-08-22T14:03:11Z"
    }

Two connection modes, matching the two shapes real PBX/trunk deployments
take (spec.md's own "which PBX platform(s) your first real deployment
targets" framing -- this bridge supports both from day one rather than
picking one and leaving the other as a future task):

  1. ami     -- Asterisk Manager Interface (FreePBX, plain Asterisk). Reads
                Newchannel/Hangup events over a raw TCP socket (the AMI
                protocol itself is simple line-based text -- no external
                AMI library is required, matching this project's existing
                preference for stdlib-first bridges wherever practical).
  2. webhook -- runs a small built-in HTTP server that accepts a hosted
                SIP-trunk provider's own webhook shape and normalizes it.
                Ships with one worked adapter (a generic "already close to
                canonical" shape) -- a real deployment against a NAMED
                hosted provider adds one function to PROVIDER_ADAPTERS,
                matching the multi-provider design intentionally: this
                bridge is provider-agnostic at the TicketsCAD-facing side
                regardless of which mode feeds it.

Usage:
  python bridge.py --config bridge.ini
  python bridge.py --mode ami --ami-host 127.0.0.1 --ami-port 5038 \
                    --ami-user cad-bridge --ami-secret ... \
                    --ticketscad-url http://localhost/newui --bearer-token ...
  python bridge.py --mode webhook --listen-port 8085 --provider generic \
                    --ticketscad-url http://localhost/newui --bearer-token ...

Requirements:
  pip install requests   (stdlib covers everything else -- socket for AMI,
                           http.server for the webhook receiver)

Service management: run as a systemd unit (see sip-bridge.service.example)
or Windows Task Scheduler at boot, same as the other bridges in this repo.
No real SIP trunk/PBX exists to point this at during Phase 149's own
build -- it is designed and ready, deployed only when a real target
exists, exactly like the DMR bridge and Meshtastic bridge were.
"""

import argparse
import configparser
import json
import logging
import socket
import sys
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

try:
    import requests
except ImportError:
    requests = None

# ─────────────────────────────────────────────────────────────
#  Configuration
# ─────────────────────────────────────────────────────────────

DEFAULT_CONFIG = {
    "mode": "ami",  # ami or webhook

    # TicketsCAD server
    "ticketscad_url": "http://localhost/newui",
    "bearer_token": "",  # the pbx_trunks.bearer_token minted by sip-trunks-admin.php

    # ── AMI mode ──
    "ami_host": "127.0.0.1",
    "ami_port": 5038,
    "ami_user": "",
    "ami_secret": "",
    "ami_reconnect_seconds": 5,

    # ── Webhook mode ──
    "listen_host": "0.0.0.0",
    "listen_port": 8085,
    "provider": "generic",  # key into PROVIDER_ADAPTERS
    "webhook_shared_secret": "",  # optional: verify an inbound header from the provider

    # ── Behavior ──
    "log_level": "INFO",
    "log_file": "",
    "health_port": 8086,
    "http_timeout_seconds": 5,
}

INGEST_PATH = "/api/sip-ingest.php"


def load_config(args):
    cfg = dict(DEFAULT_CONFIG)
    if args.config:
        parser = configparser.ConfigParser()
        parser.read(args.config)
        if parser.has_section("sip-bridge"):
            for key, value in parser.items("sip-bridge"):
                if key in cfg:
                    if isinstance(cfg[key], bool):
                        cfg[key] = value.strip().lower() in ("1", "true", "yes", "on")
                    elif isinstance(cfg[key], int):
                        cfg[key] = int(value)
                    else:
                        cfg[key] = value
    # CLI flags override the config file, matching the meshtastic bridge's
    # own precedence (file first, then explicit overrides).
    overrides = {
        "mode": args.mode, "ticketscad_url": args.ticketscad_url,
        "bearer_token": args.bearer_token, "ami_host": args.ami_host,
        "ami_port": args.ami_port, "ami_user": args.ami_user,
        "ami_secret": args.ami_secret, "listen_port": args.listen_port,
        "provider": args.provider, "log_level": args.log_level,
    }
    for key, value in overrides.items():
        if value is not None:
            cfg[key] = value
    return cfg


def setup_logging(cfg):
    handlers = [logging.StreamHandler(sys.stdout)]
    if cfg.get("log_file"):
        handlers.append(logging.FileHandler(cfg["log_file"]))
    logging.basicConfig(
        level=getattr(logging, str(cfg.get("log_level", "INFO")).upper(), logging.INFO),
        format="%(asctime)s [%(levelname)s] %(message)s",
        handlers=handlers,
    )
    return logging.getLogger("sip-bridge")


# ─────────────────────────────────────────────────────────────
#  Forwarding to TicketsCAD (shared by both modes)
# ─────────────────────────────────────────────────────────────

def now_iso():
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def forward_event(cfg, log, event, call_id, caller_number=None, caller_name=None,
                   called_number=None, event_ts=None, status=None):
    """POST one canonical event to api/sip-ingest.php. Never raises -- a
    forwarding failure is logged and the bridge keeps running; the PBX
    side of a dropped webhook is the adapter's problem to retry, not a
    reason to crash the whole bridge process.

    `status`, when given, is the shared dict the /health endpoint reads
    from -- last_forward_at is stamped on a SUCCESSFUL forward only, so a
    stuck bridge (PBX side still ringing but TicketsCAD unreachable) is
    visible as a stale timestamp rather than a falsely-fresh one."""
    if requests is None:
        log.error("the 'requests' package is not installed -- run: pip install requests")
        return False
    payload = {
        "event": event,
        "call_id": call_id,
        "caller_number": caller_number,
        "caller_name": caller_name,
        "called_number": called_number,
        "event_ts": event_ts or now_iso(),
    }
    url = cfg["ticketscad_url"].rstrip("/") + INGEST_PATH
    headers = {
        "Authorization": "Bearer " + cfg["bearer_token"],
        "Content-Type": "application/json",
    }
    try:
        resp = requests.post(url, json=payload, headers=headers,
                              timeout=float(cfg.get("http_timeout_seconds", 5)))
        if resp.status_code >= 400:
            log.warning("sip-ingest rejected %s for call %s: HTTP %d %s",
                        event, call_id, resp.status_code, resp.text[:200])
            return False
        log.info("forwarded %s for call %s -> %s", event, call_id, url)
        if status is not None:
            status["last_forward_at"] = now_iso()
        return True
    except requests.RequestException as exc:
        log.error("failed to reach TicketsCAD at %s: %s", url, exc)
        return False


# ─────────────────────────────────────────────────────────────
#  Mode 1: Asterisk Manager Interface (AMI)
# ─────────────────────────────────────────────────────────────
#
# AMI is a simple line-based text protocol over TCP -- no external
# library needed. This client reads Newchannel (a call arriving) and
# Hangup (a call ending, with a Cause code distinguishing an answered
# call from one that rang out unanswered -- "abandoned" per plan.md §3's
# own state machine) for the channels matching this trunk's incoming
# context. A production deployment should scope AMI_FILTER_CONTEXT to
# the dialplan context this specific trunk rings into, so one bridge
# process per trunk does not see every OTHER trunk's traffic too.

AMI_FILTER_CONTEXT = None  # set via --ami-context / [sip-bridge] ami_context =

# Asterisk hangup cause 17 = "User busy", 19 = "No answer" -- both read as
# an unanswered/abandoned call for this bridge's purposes. See Asterisk's
# own channel.h AST_CAUSE_* table; kept as a small local set rather than a
# dependency so this file has zero non-stdlib imports for AMI mode.
ABANDONED_HANGUP_CAUSES = {"17", "19", "21", "102"}


class AmiEvent:
    """One parsed AMI event block: a dict of Key: Value lines."""
    def __init__(self, fields):
        self.fields = fields

    def get(self, key, default=None):
        return self.fields.get(key, default)


def _ami_read_events(sock_file):
    """Yields AmiEvent objects, one per blank-line-terminated block."""
    fields = {}
    for line in sock_file:
        line = line.rstrip("\r\n")
        if line == "":
            if fields:
                yield AmiEvent(fields)
                fields = {}
            continue
        if ":" in line:
            key, _, value = line.partition(":")
            fields[key.strip()] = value.strip()


def run_ami_bridge(cfg, log, stop_event, status=None):
    """Connects to AMI, logs in, and forwards Newchannel/Hangup events
    forever (with reconnect-on-drop), until stop_event is set."""
    reconnect_delay = max(1, int(cfg.get("ami_reconnect_seconds", 5)))
    seen_ringing = {}  # AMI Uniqueid -> True, so we don't double-forward "ringing"

    while not stop_event.is_set():
        try:
            log.info("connecting to AMI at %s:%s", cfg["ami_host"], cfg["ami_port"])
            sock = socket.create_connection((cfg["ami_host"], int(cfg["ami_port"])), timeout=10)
            sock_file = sock.makefile("r", encoding="utf-8", errors="replace")

            banner = sock_file.readline()  # "Asterisk Call Manager/x.y.z"
            log.debug("AMI banner: %s", banner.strip())

            login = (
                "Action: Login\r\n"
                f"Username: {cfg['ami_user']}\r\n"
                f"Secret: {cfg['ami_secret']}\r\n"
                "Events: call\r\n\r\n"
            )
            sock.sendall(login.encode("utf-8"))

            for event in _ami_read_events(sock_file):
                if stop_event.is_set():
                    break
                event_name = event.get("Event")
                if event_name == "Newchannel":
                    context = event.get("Context")
                    if AMI_FILTER_CONTEXT and context != AMI_FILTER_CONTEXT:
                        continue
                    uid = event.get("Uniqueid")
                    if not uid or uid in seen_ringing:
                        continue
                    seen_ringing[uid] = True
                    forward_event(
                        cfg, log, "ringing", uid,
                        caller_number=event.get("CallerIDNum"),
                        caller_name=event.get("CallerIDName") or None,
                        called_number=event.get("Exten"),
                        status=status,
                    )
                elif event_name == "Hangup":
                    uid = event.get("Uniqueid")
                    if not uid:
                        continue
                    cause = event.get("Cause")
                    was_answered = uid not in seen_ringing or cause not in ABANDONED_HANGUP_CAUSES
                    seen_ringing.pop(uid, None)
                    forward_event(cfg, log, "ended" if was_answered else "abandoned", uid, status=status)
                elif event_name == "FullyBooted":
                    log.info("AMI login accepted, streaming events")

            sock.close()
        except (OSError, socket.error) as exc:
            log.warning("AMI connection lost (%s) -- retrying in %ds", exc, reconnect_delay)
        if not stop_event.is_set():
            time.sleep(reconnect_delay)


# ─────────────────────────────────────────────────────────────
#  Mode 2: Webhook receiver (hosted SIP-trunk providers)
# ─────────────────────────────────────────────────────────────
#
# A hosted provider's own webhook shape rarely matches plan.md §2's
# canonical contract byte-for-byte -- each one gets a small adapter
# function here. Only "generic" (a passthrough for a provider whose
# shape is already close to canonical, or a test harness) ships built
# in; a real deployment against a NAMED provider adds one function.

def _adapt_generic(body):
    """A provider whose webhook body is already close to canonical, or a
    test/simulator harness driving this bridge directly. Missing optional
    fields are fine -- forward_event() tolerates None throughout."""
    event = body.get("event") or body.get("status")
    if event in ("ring", "ringing", "incoming"):
        event = "ringing"
    elif event in ("hangup", "completed", "ended"):
        event = "ended"
    elif event in ("no-answer", "busy", "abandoned", "missed"):
        event = "abandoned"
    return {
        "event": event,
        "call_id": body.get("call_id") or body.get("id") or body.get("CallSid"),
        "caller_number": body.get("caller_number") or body.get("from") or body.get("From"),
        "caller_name": body.get("caller_name") or body.get("from_name"),
        "called_number": body.get("called_number") or body.get("to") or body.get("To"),
        "event_ts": body.get("event_ts") or body.get("timestamp"),
    }


PROVIDER_ADAPTERS = {
    "generic": _adapt_generic,
    # Add a named hosted provider here when a real deployment targets one,
    # e.g. "twilio_voice": _adapt_twilio_voice -- one function, same
    # canonical dict shape returned. Never touches api/sip-ingest.php.
}


def make_webhook_handler(cfg, log, status=None):
    adapter = PROVIDER_ADAPTERS.get(cfg["provider"], _adapt_generic)
    shared_secret = cfg.get("webhook_shared_secret") or ""

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):
            log.debug("%s - %s", self.address_string(), fmt % args)

        def do_POST(self):
            if shared_secret:
                supplied = self.headers.get("X-Webhook-Secret", "")
                if not supplied or supplied != shared_secret:
                    self.send_response(401)
                    self.end_headers()
                    return
            length = int(self.headers.get("Content-Length", 0) or 0)
            raw = self.rfile.read(length) if length else b""
            try:
                body = json.loads(raw.decode("utf-8")) if raw else {}
            except (json.JSONDecodeError, UnicodeDecodeError):
                self.send_response(400)
                self.end_headers()
                return
            canonical = adapter(body)
            if not canonical.get("event") or not canonical.get("call_id"):
                log.warning("dropping unrecognized webhook body (no event/call_id after adapting): %s",
                            json.dumps(body)[:300])
                self.send_response(200)  # ack anyway -- don't make the provider retry forever
                self.end_headers()
                return
            ok = forward_event(cfg, log, status=status, **canonical)
            self.send_response(200 if ok else 502)
            self.end_headers()

        def do_GET(self):
            self.send_response(404)
            self.end_headers()

    return Handler


def run_webhook_bridge(cfg, log, stop_event, status=None):
    handler_cls = make_webhook_handler(cfg, log, status=status)
    server = ThreadingHTTPServer((cfg["listen_host"], int(cfg["listen_port"])), handler_cls)
    log.info("webhook receiver listening on %s:%s (provider=%s)",
              cfg["listen_host"], cfg["listen_port"], cfg["provider"])
    server_thread = threading.Thread(target=server.serve_forever, daemon=True)
    server_thread.start()
    while not stop_event.is_set():
        time.sleep(1)
    server.shutdown()


# ─────────────────────────────────────────────────────────────
#  Health endpoint (matches the DMR bridge's authenticated-liveness
#  convention this project's own CLAUDE.md documents -- "quiet ≠ dead",
#  a real /health the CAD side can poll rather than inferring liveness
#  from event recency alone)
# ─────────────────────────────────────────────────────────────

def run_health_server(cfg, log, stop_event, status):
    class HealthHandler(BaseHTTPRequestHandler):
        def log_message(self, fmt, *args):
            pass

        def do_GET(self):
            if self.path != "/health":
                self.send_response(404)
                self.end_headers()
                return
            body = json.dumps({
                "running": True,
                "mode": cfg["mode"],
                "started_at": status["started_at"],
                "last_forward_at": status.get("last_forward_at"),
            }).encode("utf-8")
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = ThreadingHTTPServer(("0.0.0.0", int(cfg.get("health_port", 8086))), HealthHandler)
    log.info("health endpoint on :%s/health", cfg.get("health_port", 8086))
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    while not stop_event.is_set():
        time.sleep(1)
    server.shutdown()


# ─────────────────────────────────────────────────────────────
#  Entry point
# ─────────────────────────────────────────────────────────────

def parse_args():
    p = argparse.ArgumentParser(description="TicketsCAD inbound SIP/PBX call bridge")
    p.add_argument("--config", help="Path to a bridge.ini config file")
    p.add_argument("--mode", choices=["ami", "webhook"], default=None)
    p.add_argument("--ticketscad-url", default=None)
    p.add_argument("--bearer-token", default=None)
    p.add_argument("--ami-host", default=None)
    p.add_argument("--ami-port", type=int, default=None)
    p.add_argument("--ami-user", default=None)
    p.add_argument("--ami-secret", default=None)
    p.add_argument("--ami-context", default=None, help="Restrict to one dialplan context")
    p.add_argument("--listen-port", type=int, default=None)
    p.add_argument("--provider", default=None)
    p.add_argument("--log-level", default=None)
    return p.parse_args()


def main():
    global AMI_FILTER_CONTEXT
    args = parse_args()
    cfg = load_config(args)
    log = setup_logging(cfg)

    if not cfg.get("bearer_token"):
        log.error("no bearer_token configured -- mint one in Settings > Communications & "
                   "Integrations > Inbound Calls (SIP/PBX) on the TicketsCAD side first")
        sys.exit(1)
    if args.ami_context:
        AMI_FILTER_CONTEXT = args.ami_context

    stop_event = threading.Event()
    status = {"started_at": now_iso(), "last_forward_at": None}

    def handle_signal(signum, frame):
        log.info("received signal %s, shutting down", signum)
        stop_event.set()

    try:
        import signal
        signal.signal(signal.SIGINT, handle_signal)
        signal.signal(signal.SIGTERM, handle_signal)
    except (ImportError, ValueError, AttributeError):
        pass  # signal module quirks on some platforms -- Ctrl+C still works

    health_thread = threading.Thread(target=run_health_server, args=(cfg, log, stop_event, status), daemon=True)
    health_thread.start()

    if cfg["mode"] == "ami":
        if not cfg.get("ami_user") or not cfg.get("ami_secret"):
            log.error("AMI mode requires ami_user/ami_secret (Asterisk manager.conf credentials)")
            sys.exit(1)
        run_ami_bridge(cfg, log, stop_event, status=status)
    elif cfg["mode"] == "webhook":
        run_webhook_bridge(cfg, log, stop_event, status=status)
    else:
        log.error("unknown mode: %s (expected ami or webhook)", cfg["mode"])
        sys.exit(1)


if __name__ == "__main__":
    main()
