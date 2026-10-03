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

Three connection modes, matching the shapes real PBX/trunk deployments
take (spec.md's own "which PBX platform(s) your first real deployment
targets" framing -- this bridge supports all of them rather than picking one
and leaving the others as future tasks):

  1. ami     -- Asterisk Manager Interface (FreePBX, plain Asterisk). Reads
                Newchannel/Hangup events over a raw TCP socket (the AMI
                protocol itself is simple line-based text -- no external
                AMI library is required, matching this project's existing
                preference for stdlib-first bridges wherever practical).
  3. threecx -- (Phase 155) connects to a 3CX server's Call Control API
                WebSocket, so a 3CX system needs no webhook support and no
                Asterisk. See threecx.py for the protocol and the call-
                tracking state machine, and docs/INBOUND-SIP-CALLS.md for
                the click-by-click 3CX setup.
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
  python bridge.py --config bridge.ini --check     # verify every connection, then exit

Requirements:
  pip install requests   (stdlib covers everything else -- socket for AMI,
                           http.server for the webhook receiver)
  pip install websockets (ONLY for mode = threecx)

Heartbeat (Phase 155): while running, the bridge POSTs {"event": "heartbeat"}
to TicketsCAD every heartbeat_seconds, so the Inbound Calls admin page can show
"Bridge connected" -- a quiet phone line and a dead bridge are otherwise
indistinguishable. Run with --check after editing bridge.ini and it will say,
in plain English, which link in the chain is broken.

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
import re
import socket
import sys
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BRIDGE_VERSION = "1.1.1"

try:
    import requests
except ImportError:
    requests = None

# ─────────────────────────────────────────────────────────────
#  Configuration
# ─────────────────────────────────────────────────────────────

DEFAULT_CONFIG = {
    "mode": "ami",  # ami, webhook or threecx

    # TicketsCAD server
    "ticketscad_url": "http://localhost/newui",
    "bearer_token": "",  # the pbx_trunks.bearer_token minted by sip-trunks-admin.php

    # ── AMI mode ──
    "ami_host": "127.0.0.1",
    "ami_port": 5038,
    "ami_user": "",
    "ami_secret": "",
    "ami_reconnect_seconds": 5,
    "ami_context": "",  # only report calls whose FIRST channel is in this dialplan context (blank = every call)

    # ── Webhook mode ──
    "listen_host": "0.0.0.0",
    "listen_port": 8085,
    "provider": "generic",  # key into PROVIDER_ADAPTERS
    "webhook_shared_secret": "",  # optional: verify an inbound header from the provider

    # ── 3CX mode (Call Control API) ──
    "threecx_url": "",            # the address you use for the 3CX web client, e.g. https://pbx.example.org:5001
    "threecx_client_id": "",      # the DN entered under Integrations > API
    "threecx_client_secret": "",  # the API key (shown once when the client was created)
    "threecx_monitor_dns": "",    # optional: comma-separated extensions to consider; blank = all the API client lists
    "threecx_verify_tls": True,   # set false only for a self-signed 3CX certificate
    "threecx_include_internal": False,  # also report extension-to-extension calls
    "threecx_trunk_did_map": "",  # 3CX gives no dialed number on V20: "10001=+16125550100,10002=+16125550111"
    "threecx_default_called_number": "",  # called_number to send when nothing maps
    "threecx_terminal_grace_seconds": 2,  # wait this long after the last phone stops ringing before "missed/ended"
    "threecx_ringing_max_seconds": 180,   # safety net: close a call that rings this long with no hangup report
    "threecx_reconcile_seconds": 45,      # how often to re-read the live call list as a watchdog
    "threecx_ws_url": "",         # override only if the WebSocket lives elsewhere than <url>/callcontrol/ws
    "threecx_token_url": "",      # override only if the token endpoint is not <url>/connect/token
    "threecx_reconnect_seconds": 5,
    "capture_file": "",           # record raw 3CX traffic (JSON lines) for troubleshooting
    "capture_redact": True,       # mask numbers/names/addresses in that file (recommended)

    # ── Behavior ──
    "heartbeat_seconds": 30,
    "log_level": "INFO",
    "log_file": "",
    "health_port": 8086,
    "http_timeout_seconds": 5,
}

INGEST_PATH = "/api/sip-ingest.php"

# What the Inbound Calls Setup window writes on the bearer_token line when it does not hold the token
# (TicketsCAD shows a trunk's token only once, when it is created or rotated).
PLACEHOLDER_TOKEN = "PASTE-THE-TRUNK-TOKEN-HERE"


def clean_token(value):
    """A pasted token with spaces or one pair of quote marks around it is still the same token; the quote
    marks are not part of it. Left in, they make TicketsCAD answer 403 'bad bearer' for a correct token."""
    token = (value or "").strip()
    if len(token) >= 2 and token[0] == token[-1] and token[0] in ("'", '"'):
        token = token[1:-1].strip()
    return token


def load_config(args):
    cfg = dict(DEFAULT_CONFIG)
    if args.config:
        # interpolation=None: a secret containing '%' must not be treated as a
        # format string. Inline comments need leading whitespace, so a token
        # containing '#' or ';' is still read intact.
        parser = configparser.ConfigParser(interpolation=None, inline_comment_prefixes=('#', ';'))
        parser.read(args.config, encoding='utf-8')
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
    cfg["bearer_token"] = clean_token(cfg.get("bearer_token"))
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


def _post_event(cfg, log, payload):
    """POST one canonical payload. Returns 'ok', 'retry' (network failure, 5xx,
    429 -- worth trying again) or 'drop' (a 4xx: the same request will fail
    the same way, so retrying only repeats the failure)."""
    if requests is None:
        log.error("the 'requests' package is not installed -- run: pip install requests")
        return "drop"
    url = cfg["ticketscad_url"].rstrip("/") + INGEST_PATH
    headers = {
        "Authorization": "Bearer " + cfg["bearer_token"],
        "Content-Type": "application/json",
    }
    try:
        resp = requests.post(url, json=payload, headers=headers,
                              timeout=float(cfg.get("http_timeout_seconds", 5)))
    except requests.RequestException as exc:
        log.error("failed to reach TicketsCAD at %s: %s", url, exc)
        return "retry"
    if resp.status_code >= 400:
        log.warning("sip-ingest rejected %s for call %s: HTTP %d %s",
                    payload.get("event"), payload.get("call_id"), resp.status_code, resp.text[:200])
        return "retry" if (resp.status_code >= 500 or resp.status_code == 429) else "drop"
    log.info("forwarded %s for call %s -> %s", payload.get("event"), payload.get("call_id"), url)
    return "ok"


def forward_event(cfg, log, event, call_id, caller_number=None, caller_name=None,
                   called_number=None, event_ts=None, status=None, extra=None):
    """POST one canonical event to api/sip-ingest.php. Never raises -- a
    forwarding failure is logged and the bridge keeps running; the PBX
    side of a dropped webhook is the adapter's problem to retry, not a
    reason to crash the whole bridge process.

    `status`, when given, is the shared dict the /health endpoint reads
    from -- last_forward_at is stamped on a SUCCESSFUL forward only, so a
    stuck bridge (PBX side still ringing but TicketsCAD unreachable) is
    visible as a stale timestamp rather than a falsely-fresh one.

    `extra` (Phase 155) adds fields beyond the canonical six -- for example
    answered_by_dn on a claimed_externally event, which TicketsCAD stores in
    that event's audit detail."""
    payload = {
        "event": event,
        "call_id": call_id,
        "caller_number": caller_number,
        "caller_name": caller_name,
        "called_number": called_number,
        "event_ts": event_ts or now_iso(),
    }
    if extra:
        payload.update(extra)
    outcome = _post_event(cfg, log, payload)
    if outcome == "ok" and status is not None:
        status["last_forward_at"] = now_iso()
    return outcome == "ok"


class DeliveryQueue:
    """Ordered, retrying delivery for bridges that emit a CALL's events from
    one thread while TicketsCAD may be briefly unreachable (Phase 155).

    One FIFO worker keeps a call's events in order -- `claimed_externally` and
    `ended` must never reach TicketsCAD before the `ringing` they refer to
    (a non-ring first event would create a phantom 'missed' row) -- and each
    event is retried a few times with back-off. A 4xx is dropped, not looped.
    The WebSocket reader never blocks on HTTP."""

    BACKOFF = (1, 2, 4)

    def __init__(self, cfg, log, status, stop_event):
        import queue
        self.cfg, self.log, self.status, self.stop_event = cfg, log, status, stop_event
        self.q = queue.Queue()
        self.thread = threading.Thread(target=self._run, daemon=True)
        self.thread.start()

    def put(self, payload):
        self.q.put(payload)

    def _run(self):
        import queue
        while True:
            try:
                payload = self.q.get(timeout=0.5)
            except queue.Empty:
                if self.stop_event.is_set():
                    return
                continue
            outcome = "retry"
            for attempt in range(len(self.BACKOFF) + 1):
                outcome = _post_event(self.cfg, self.log, payload)
                if outcome != "retry":
                    break
                if attempt < len(self.BACKOFF):
                    if self.stop_event.wait(self.BACKOFF[attempt]):
                        break
            if outcome == "ok":
                self.status["last_forward_at"] = now_iso()
            elif outcome == "retry":
                self.log.error("giving up on %s for call %s after retries", payload.get("event"), payload.get("call_id"))

    def pending(self):
        return self.q.qsize()


# ─────────────────────────────────────────────────────────────
#  Mode 1: Asterisk Manager Interface (AMI)
# ─────────────────────────────────────────────────────────────
#
# AMI is a simple line-based text protocol over TCP -- no external
# library needed. This client reads Newchannel (a call arriving), Newstate /
# DialEnd (it was answered) and Hangup (it ended) and AmiCallTracker below
# turns them into ONE ringing and ONE ended-or-abandoned event per call.
# A production deployment should set ami_context (bridge.ini, or
# --ami-context) to the dialplan context the trunk rings into, so calls the
# workstations place themselves, or another trunk's traffic, are not reported
# as ringing calls.

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


# Asterisk reports one call as MANY channels: the caller's channel plus one
# outbound channel per phone a ring group or Dial() rings, all sharing the same
# Linkedid (the caller's channel is the one whose Uniqueid IS the Linkedid).
# The original bridge forwarded a "ringing" for every Newchannel keyed by
# Uniqueid, so a three-phone ring group produced four rows for one call, the
# extra ones with a blank or "s" called number, and every leg's Hangup produced
# an "ended" for a row the dispatcher never saw. AmiCallTracker reports ONE
# call per Linkedid, from its first channel, and says whether it was answered.
#
# Written from the AMI event documentation, tested against SYNTHETIC event
# sequences (tests/test_ami_tracker.py). It has not been run against a live
# Asterisk ring group: the event names and field names (Newchannel, Newstate,
# DialEnd/DialStatus, Hangup, Uniqueid, Linkedid, ChannelState) are the
# Asterisk 12+ AMI ones, and capturing a real ring-group call to replace the
# synthetic sequences is the open verification step.

AMI_CHANNEL_STATE_UP = "6"

# AMI shows an unknown caller as "<unknown>" (and sometimes ""): report it as
# absent rather than as a number or a name.
_AMI_UNKNOWN = ("", "<unknown>", "unknown")


def _ami_clean(value):
    if value is None:
        return None
    value = str(value).strip()
    return None if value.lower() in _AMI_UNKNOWN else value


class AmiCallTracker:
    """Reduces a stream of Asterisk AMI events to canonical call events.

    handle(fields) takes one parsed AMI event (a dict of header -> value) and
    returns a list of canonical payload dicts (usually empty, at most one):
        {"event": "ringing" | "ended" | "abandoned", "call_id": <Linkedid>, ...}

    Rules:
      * a call is keyed by Linkedid (Uniqueid when the PBX sends no Linkedid);
      * only the call's FIRST channel (Uniqueid == Linkedid) starts or ends it --
        the outbound legs of a ring group, queue or Local channel are ignored;
      * "answered" means the caller's channel reached state Up or a Dial leg
        reported ANSWER; the caller hanging up before that is "abandoned" (a
        missed call), after that "ended". The previous cause-code guess called
        a caller who hung up while it rang "ended", so it never reached the
        Missed Calls list;
      * a call the bridge did not see start (it began mid-call) is never
        reported: no inventing a ring that was not seen.
    """

    MAX_TRACKED = 500            # safety cap; a Hangup lost to a reconnect must not leak forever
    MAX_AGE_SECONDS = 6 * 3600

    def __init__(self, context_filter=None, clock=time.time):
        self.context_filter = (context_filter or "").strip() or None
        self.clock = clock
        self.calls = {}  # linkedid -> {"answered": bool, "at": float}

    def _prune(self):
        cutoff = self.clock() - self.MAX_AGE_SECONDS
        stale = [k for k, v in self.calls.items() if v["at"] < cutoff]
        overflow = len(self.calls) - len(stale) - self.MAX_TRACKED
        if overflow > 0:
            remaining = sorted((k for k in self.calls if k not in stale), key=lambda k: self.calls[k]["at"])
            stale.extend(remaining[: overflow + 50])
        for key in stale:
            self.calls.pop(key, None)

    def handle(self, fields):
        name = fields.get("Event")
        uid = fields.get("Uniqueid")
        linked = fields.get("Linkedid") or uid

        if name == "Newchannel":
            if not uid or uid != linked:
                return []  # an outbound leg of a call already (or about to be) tracked
            if self.context_filter and fields.get("Context") != self.context_filter:
                return []
            if linked in self.calls:
                return []
            self._prune()
            self.calls[linked] = {"answered": False, "at": self.clock()}
            return [{
                "event": "ringing",
                "call_id": linked,
                "caller_number": _ami_clean(fields.get("CallerIDNum")),
                "caller_name": _ami_clean(fields.get("CallerIDName")),
                "called_number": _ami_clean(fields.get("Exten")),
            }]

        if name == "Newstate":
            if uid and uid == linked and linked in self.calls and str(fields.get("ChannelState")) == AMI_CHANNEL_STATE_UP:
                self.calls[linked]["answered"] = True
            return []

        if name == "DialEnd":
            key = fields.get("Linkedid") or fields.get("Uniqueid")
            if key in self.calls and str(fields.get("DialStatus", "")).upper() == "ANSWER":
                self.calls[key]["answered"] = True
            return []

        if name == "Hangup":
            if not uid or uid != linked or linked not in self.calls:
                return []  # a leg hanging up, or a call this bridge never saw ring
            state = self.calls.pop(linked)
            return [{"event": "ended" if state["answered"] else "abandoned", "call_id": linked}]

        return []


def run_ami_bridge(cfg, log, stop_event, status=None):
    """Connects to AMI, logs in, and forwards one ringing / ended / abandoned
    event per CALL (see AmiCallTracker) forever, with reconnect-on-drop, until
    stop_event is set."""
    reconnect_delay = max(1, int(cfg.get("ami_reconnect_seconds", 5)))
    tracker = AmiCallTracker(context_filter=cfg.get("ami_context"))

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
                if event.get("Event") == "FullyBooted":
                    log.info("AMI login accepted, streaming events")
                    continue
                for out in tracker.handle(event.fields):
                    forward_event(
                        cfg, log, out["event"], out["call_id"],
                        caller_number=out.get("caller_number"),
                        caller_name=out.get("caller_name"),
                        called_number=out.get("called_number"),
                        status=status,
                    )

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
#  Heartbeat (Phase 155)
# ─────────────────────────────────────────────────────────────

def _answer_snippet(resp, limit=140):
    """The start of whatever answered, for an administrator: an HTML page's <title> if it has one, otherwise
    its text with the tags removed. Never includes anything this bridge sent (the token is only ever in a
    request header)."""
    text = resp.text or ""
    title = re.search(r"<title[^>]*>(.*?)</title>", text, re.IGNORECASE | re.DOTALL)
    if title:
        text = title.group(1)
    text = re.sub(r"<[^>]+>", " ", text)
    # It is printed to an administrator's terminal and came from a server we do not control: drop control
    # characters (an ESC would start a terminal escape sequence) before anything else.
    text = re.sub(r"[\x00-\x1f\x7f]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text[:limit] if text else "(empty)"


def explain_http_rejection(resp, url):
    """Words for an administrator when TicketsCAD's ingest address answers 401 or 403.

    Only TicketsCAD itself says {"error": "bad bearer"} (403) or {"error": "Bearer token required"} (401). Any
    other 401/403 -- a firewall, Cloudflare, a reverse proxy, a web-server rule -- was never TicketsCAD's
    decision, and blaming the token for it sends the administrator after the wrong thing."""
    status = resp.status_code
    error = None
    try:
        body = resp.json()
        if isinstance(body, dict):
            error = body.get("error")
    except ValueError:
        pass

    if status == 403 and error == "bad bearer":
        return ("TicketsCAD rejected the bearer token (HTTP 403, answer: bad bearer). It received the token and "
                "does not recognise it. Check that bearer_token in bridge.ini is the real token (not "
                + PLACEHOLDER_TOKEN + ") with no extra characters, and that ticketscad_url is the same "
                "TicketsCAD installation where the trunk was created. Or click Rotate Token on the Inbound Calls "
                "page and use its Setup window, which fills in the new token.")
    if status == 401 and error == "Bearer token required":
        return ("TicketsCAD answered HTTP 401 (answer: Bearer token required): the Authorization header did not "
                "arrive. Something between this machine and TicketsCAD is removing it -- some web-server and "
                "proxy setups do. The token itself has not been checked yet.")

    server = (resp.headers.get("Server") or "").strip()
    cloudflare = "cloudflare" in server.lower() or "CF-RAY" in resp.headers
    source = "Cloudflare" if cloudflare else ("a server identifying as '%s'" % server if server else "a web server or proxy")
    return ("HTTP %d from %s, but not TicketsCAD's own answer -- %s is blocking the request before it reaches "
            "TicketsCAD, so the token has not been checked. Ask whoever runs that firewall or proxy to allow "
            "this machine to reach %s. It said: %s" % (status, url, source, INGEST_PATH, _answer_snippet(resp)))


def send_heartbeat(cfg):
    """POST one heartbeat. Returns (ok, detail, reply_dict_or_None). Never
    raises. `detail` is phrased for an administrator."""
    if requests is None:
        return False, "the 'requests' package is not installed -- run: pip install requests", None
    url = cfg["ticketscad_url"].rstrip("/") + INGEST_PATH
    try:
        resp = requests.post(
            url,
            json={"event": "heartbeat", "bridge": "sip-bridge %s (%s)" % (BRIDGE_VERSION, cfg["mode"])},
            headers={"Authorization": "Bearer " + cfg["bearer_token"], "Content-Type": "application/json"},
            timeout=float(cfg.get("http_timeout_seconds", 5)),
        )
    except requests.RequestException as exc:
        return False, "cannot reach TicketsCAD at %s (%s)" % (url, exc), None
    if resp.status_code in (401, 403):
        return False, explain_http_rejection(resp, url), None
    if resp.status_code == 404:
        return False, ("HTTP 404 from %s -- ticketscad_url should be the folder that contains login.php "
                       "(for example https://cad.example.org/newui), with no /api/... on the end." % url), None
    if resp.status_code == 429:
        return False, "TicketsCAD is rate-limiting this address (HTTP 429)", None
    if resp.status_code >= 400:
        return False, "TicketsCAD answered HTTP %d: %s" % (resp.status_code, resp.text[:160]), None
    try:
        reply = resp.json()
    except ValueError:
        return False, ("the address answered but not with TicketsCAD's JSON (%s) -- ticketscad_url is probably "
                       "pointing at the wrong web page" % url), None
    return True, "ok", reply


def run_heartbeat(cfg, log, stop_event, status):
    """Beat every heartbeat_seconds; log only when the state CHANGES so a
    long outage is one line, not thousands."""
    interval = max(5, int(cfg.get("heartbeat_seconds", 30)))
    last_state = None
    while not stop_event.is_set():
        ok, detail, reply = send_heartbeat(cfg)
        state = "ok" if ok else detail
        if ok:
            status["last_heartbeat_ok_at"] = now_iso()
            status["ticketscad"] = "ok"
            if last_state != "ok":
                label = (reply or {}).get("trunk_label", "?")
                log.info("connected to TicketsCAD as trunk '%s'", label)
                if reply and reply.get("trunk_enabled") is False:
                    log.warning("trunk '%s' is DISABLED in TicketsCAD -- calls will be dropped until "
                                "it is enabled on the Inbound Calls page", label)
        else:
            status["ticketscad"] = "error: " + detail
            if state != last_state:
                log.error("TicketsCAD link problem: %s", detail)
        last_state = state
        stop_event.wait(interval)


# ─────────────────────────────────────────────────────────────
#  --check : the "what is actually wrong" doctor
# ─────────────────────────────────────────────────────────────

def run_check(cfg):
    """Print a step-by-step report and return a process exit code. Written
    for a volunteer administrator: every failure names the setting to fix."""
    failures = [0]

    def say(ok, message):
        print(("  [ OK ]  " if ok else "  [FAIL]  ") + message)
        if not ok:
            failures[0] += 1

    print("TicketsCAD inbound-call bridge %s -- connection check (mode = %s)\n" % (BRIDGE_VERSION, cfg["mode"]))
    if not cfg.get("bearer_token"):
        say(False, "bearer_token is empty -- mint one in Settings > Communications & Integrations > "
                   "Inbound Calls (SIP/PBX), then paste it into bridge.ini")
        return 1
    if cfg["bearer_token"].upper().startswith("PASTE"):
        say(False, "bearer_token still says %s -- replace it with the real token. TicketsCAD shows a trunk's "
                   "token only once; if you no longer have it, click Rotate Token on the Inbound Calls page and "
                   "use its Setup window, which writes the new token into bridge.ini for you" % PLACEHOLDER_TOKEN)
        return 1
    say(True, "bearer_token is set")

    ok, detail, reply = send_heartbeat(cfg)
    if ok:
        label = (reply or {}).get("trunk_label", "?")
        say(True, "TicketsCAD reachable and the token is accepted (trunk '%s')" % label)
        if reply and reply.get("trunk_enabled") is False:
            say(False, "that trunk is DISABLED -- switch it on in the Inbound Calls page, or every call will be dropped")
        if reply and reply.get("recorded") is False:
            say(False, "TicketsCAD could not record the heartbeat -- run `php sql/run_migrations.php` on the "
                       "TicketsCAD server (the heartbeat columns are missing); calls still work")
    else:
        say(False, detail)
        print("\nFix the line above first; the PBX checks below are skipped until TicketsCAD is reachable.")
        return 1

    mode = cfg["mode"]
    if mode == "threecx":
        from threecx import check_threecx
        check_threecx(cfg, say)
    elif mode == "ami":
        if not cfg.get("ami_user") or not cfg.get("ami_secret"):
            say(False, "ami_user / ami_secret are not set (Asterisk manager.conf credentials)")
        else:
            try:
                sock = socket.create_connection((cfg["ami_host"], int(cfg["ami_port"])), timeout=5)
                banner = sock.makefile("r", encoding="utf-8", errors="replace").readline().strip()
                sock.close()
                say(True, "AMI port %s:%s answered (%s)" % (cfg["ami_host"], cfg["ami_port"], banner or "no banner"))
            except OSError as exc:
                say(False, "cannot connect to AMI at %s:%s (%s)" % (cfg["ami_host"], cfg["ami_port"], exc))
    elif mode == "webhook":
        try:
            probe = socket.socket()
            probe.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
            probe.bind((cfg["listen_host"], int(cfg["listen_port"])))
            probe.close()
            say(True, "port %s is free for the webhook receiver (point your provider at http://<this-machine>:%s/)"
                % (cfg["listen_port"], cfg["listen_port"]))
        except OSError as exc:
            say(False, "cannot listen on %s:%s (%s) -- is another copy of the bridge already running?"
                % (cfg["listen_host"], cfg["listen_port"], exc))

    print("")
    if failures[0]:
        print("%d problem(s) found. Fix them and run --check again." % failures[0])
        return 1
    print("Everything checks out. Start the bridge normally (no --check) and watch the "
          "Inbound Calls page for 'Bridge connected'.")
    return 0


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
                "version": BRIDGE_VERSION,
                "mode": cfg["mode"],
                "started_at": status["started_at"],
                "last_forward_at": status.get("last_forward_at"),
                "last_heartbeat_ok_at": status.get("last_heartbeat_ok_at"),
                "ticketscad": status.get("ticketscad"),
                "threecx": status.get("threecx"),
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
    p.add_argument("--mode", choices=["ami", "webhook", "threecx"], default=None)
    p.add_argument("--check", action="store_true",
                   help="Verify TicketsCAD, the token and the PBX connection, print a plain-English "
                        "report, then exit (0 = everything works)")
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
    args = parse_args()
    cfg = load_config(args)
    log = setup_logging(cfg)

    if args.ami_context:
        cfg["ami_context"] = args.ami_context
    if args.check:
        sys.exit(run_check(cfg))
    if not cfg.get("bearer_token"):
        log.error("no bearer_token configured -- mint one in Settings > Communications & "
                   "Integrations > Inbound Calls (SIP/PBX) on the TicketsCAD side first")
        sys.exit(1)

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
    heartbeat_thread = threading.Thread(target=run_heartbeat, args=(cfg, log, stop_event, status), daemon=True)
    heartbeat_thread.start()

    if cfg["mode"] == "ami":
        if not cfg.get("ami_user") or not cfg.get("ami_secret"):
            log.error("AMI mode requires ami_user/ami_secret (Asterisk manager.conf credentials)")
            sys.exit(1)
        run_ami_bridge(cfg, log, stop_event, status=status)
    elif cfg["mode"] == "webhook":
        run_webhook_bridge(cfg, log, stop_event, status=status)
    elif cfg["mode"] == "threecx":
        missing = [k for k in ("threecx_url", "threecx_client_id", "threecx_client_secret") if not cfg.get(k)]
        if missing:
            log.error("3CX mode requires %s in bridge.ini", ", ".join(missing))
            sys.exit(1)
        from threecx import run_threecx_bridge
        queue_ = DeliveryQueue(cfg, log, status, stop_event)
        run_threecx_bridge(cfg, log, stop_event, queue_.put, status=status)
    else:
        log.error("unknown mode: %s (expected ami, webhook or threecx)", cfg["mode"])
        sys.exit(1)


if __name__ == "__main__":
    main()
