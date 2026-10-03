"""
3CX Call Control API support for the TicketsCAD inbound-call bridge.

Two halves, deliberately separated so the hard part is testable without a
3CX server:

  ThreeCxTracker   PURE. Takes the JSON messages 3CX pushes over its Call
                   Control WebSocket (plus a `fetch` callable that returns a
                   participant, because 3CX's events carry NONE) and returns
                   the canonical ringing / claimed_externally / ended /
                   abandoned events TicketsCAD understands. No sockets; the
                   clock is injectable. Everything interesting -- a ring
                   group ringing five phones, one answering and four being
                   cancelled, a call nobody answers, a reconnect that missed
                   a hangup -- is decided here and is unit-tested.

  ThreeCxClient    The I/O half: OAuth2 client-credentials token, REST GETs,
                   and the /callcontrol/ws WebSocket. Uses the third-party
                   `websockets` package (already vetted for the audio-matrix
                   service) only when this mode is selected, so the AMI and
                   webhook modes keep needing nothing but `requests`.

Protocol facts, and how far each is trusted (full evidence trail:
specs/phase-155-community-backlog/003-3cx-integration-research.md):

  * Events: {"sequence": N, "event": {"event_type": 0|1|2|4,
             "entity": "/callcontrol/<dn>/participants/<id>",
             "attached_data": null}}.  0 = Upsert, 1 = Remove.
    3CX's documentation says attached_data holds the participant; REAL
    captures and 3CX's own sample code show it is NULL for Upsert and Remove.
    So every Upsert is followed by a GET of the entity, done inline and in
    order -- which also removes the out-of-order-GET race 3CX's own SDK has
    to guard against.
  * There are no timestamps in events; the bridge stamps its own.
  * `party_did` is EMPTY for inbound external calls on V20, so the dialed
    number is unavailable; a per-trunk map or constant stands in.
  * An API client only receives events for the extensions listed on it.
  * The Call Control API needs the 3CX AI Edition licence (formerly
    Enterprise).  Two things that are NOT yet verified against a live server
    are flagged `UNVERIFIED` below; run with capture_file set to gather real
    traffic.
"""

import json
import re
import ssl
import time
from datetime import datetime, timezone

RINGING = "ringing"
CONNECTED = "connected"
KNOWN_STATUSES = frozenset({"ringing", "connected", "dialing"})
_ENTITY_PARTICIPANT = re.compile(r"^/callcontrol/([^/]+)/participants/(\d+)/?$", re.IGNORECASE)
_NUMBERISH = re.compile(r"^\+?[0-9][0-9 ()\-.]{2,}$")


def _iso(epoch):
    return datetime.fromtimestamp(int(epoch), timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _get(d, *names, default=None):
    """Case-insensitive dict lookup across several candidate key names, so a
    snake_case field and a PascalCase field from a different 3CX build are
    both understood."""
    if not isinstance(d, dict):
        return default
    for n in names:
        if n in d:
            return d[n]
    lowered = {str(k).lower(): v for k, v in d.items()}
    for n in names:
        if n.lower() in lowered:
            return lowered[n.lower()]
    return default


def _text(value):
    return "" if value is None else str(value).strip()


def parse_did_map(text):
    """'10001=+16125550100, 10002=+16125550111' -> {'10001': '+16125550100', ...}"""
    out = {}
    for part in re.split(r"[,\n;]+", text or ""):
        if "=" in part:
            k, _, v = part.partition("=")
            if k.strip() and v.strip():
                out[k.strip()] = v.strip()
    return out


class _Call:
    __slots__ = ("key", "canonical_id", "caller_number", "caller_name", "called_number",
                 "members", "rang", "connected", "silent", "first_seen", "last_seen",
                 "empty_since", "last_ts", "answered_by")

    def __init__(self, key, canonical_id, now):
        self.key = key
        self.canonical_id = canonical_id
        self.caller_number = None
        self.caller_name = None
        self.called_number = None
        self.members = set()        # (dn, participant_id) still on the call
        self.rang = False           # we have emitted `ringing`
        self.connected = False      # some monitored participant reached Connected
        self.silent = False         # first seen already in progress: emit nothing, ever
        self.first_seen = now
        self.last_seen = now
        self.empty_since = None     # when the last member disappeared (grace timer)
        self.last_ts = 0            # event_ts never goes backwards within a call
        self.answered_by = None


class ThreeCxTracker:
    """Turns 3CX Call Control events into TicketsCAD canonical events.

    Parameters
    ----------
    monitor_dns            Optional iterable of DN strings to consider; empty
                           means every DN 3CX tells us about.
    include_internal       Also report extension-to-extension calls (default
                           False: a dispatch banner for a colleague ringing
                           another desk would be noise).
    trunk_did_map          {trunk DN -> dialed number}; 3CX does not supply a
                           DID on V20, so this is how `called_number` is filled.
    default_called_number  Fallback `called_number` when nothing maps.
    terminal_grace_seconds After the last participant disappears, wait this long
                           before declaring the call over -- a transfer or a
                           queue's next wave of agents refills the call within
                           a second or two and must not read as "missed".
    ringing_max_seconds    Safety net: a call still ringing after this long with
                           a participant that never goes away is closed as
                           abandoned so TicketsCAD cannot ring forever.
    stale_connected_seconds  Same, for an answered call nobody reports ending.
    """

    def __init__(self, monitor_dns=None, include_internal=False, trunk_did_map=None,
                 default_called_number=None, terminal_grace_seconds=2,
                 ringing_max_seconds=180, stale_connected_seconds=6 * 3600,
                 log=None, clock=None):
        self.monitor_dns = {str(d).strip() for d in (monitor_dns or []) if str(d).strip()}
        self.include_internal = bool(include_internal)
        self.trunk_did_map = dict(trunk_did_map or {})
        self.default_called_number = default_called_number or None
        self.terminal_grace_seconds = terminal_grace_seconds
        self.ringing_max_seconds = ringing_max_seconds
        self.stale_connected_seconds = stale_connected_seconds
        self.log = log
        self.clock = clock or time.time
        self.calls = {}      # call key -> _Call
        self.members = {}    # (dn, participant_id) -> (call key, status)
        self._warned_statuses = set()

    # ── public ────────────────────────────────────────────────

    def handle_message(self, msg, fetch=None):
        """Process one decoded WebSocket message. `fetch(entity)` must return
        the participant dict for an entity path, or None when 3CX says it no
        longer exists (HTTP 404). Never raises on a message it does not
        understand."""
        if not isinstance(msg, dict):
            return []
        event = _get(msg, "event", "Event")
        if not isinstance(event, dict):
            return []
        etype = _get(event, "event_type", "EventType")
        if isinstance(etype, str):
            etype = {"upsert": 0, "remove": 1, "dtmfstring": 2, "response": 4}.get(etype.strip().lower())
        entity = _text(_get(event, "entity", "Entity"))
        m = _ENTITY_PARTICIPANT.match(entity)
        if etype == 1:
            return self._remove(m.group(1), int(m.group(2))) if m else []
        if etype != 0:
            return []                       # DTMF / responses / prompts: no call state
        data = _get(event, "attached_data", "AttachedData")
        if not m:
            # A DN-level upsert that happens to carry a participant list.
            if isinstance(data, dict) and isinstance(_get(data, "participants", "Participants"), list):
                dn = _text(_get(data, "dn", "Dn", "DN"))
                out = []
                for p in _get(data, "participants", "Participants"):
                    if isinstance(p, dict):
                        out.extend(self._upsert(dn or _text(_get(p, "dn")), p))
                return out
            return []
        dn, pid = m.group(1), int(m.group(2))
        participant = data if (isinstance(data, dict) and _get(data, "status", "Status")) else None
        if participant is None:
            if fetch is None:
                return []
            participant = fetch(entity)
            if participant is None:         # 404: it was already gone
                return self._remove(dn, pid)
        participant = dict(participant)
        participant.setdefault("id", pid)
        return self._upsert(dn, participant)

    def apply_snapshot(self, snapshot):
        """Reconcile with the REST GET /callcontrol reply (a list of DN objects,
        each with a `participants` list). Used on connect (priming) and every
        few seconds as a watchdog:
          * a participant present but untracked is treated as an Upsert --
            Ringing is announced, an already-Connected call is adopted silently
            (its ring time is unknown, so no ring is invented);
          * a tracked participant that has vanished is treated as a Remove,
            which is how a hangup missed during a disconnect is recovered."""
        out = []
        present = set()
        entries = snapshot if isinstance(snapshot, list) else [snapshot]
        for entry in entries:
            if not isinstance(entry, dict):
                continue
            if "routepoint" in _text(_get(entry, "type", "Type")).lower():
                continue                    # the API client's own route point
            dn = _text(_get(entry, "dn", "Dn", "DN"))
            for p in (_get(entry, "participants", "Participants") or []):
                if not isinstance(p, dict):
                    continue
                pdn = dn or _text(_get(p, "dn"))
                try:
                    pid = int(_get(p, "id", "Id"))
                except (TypeError, ValueError):
                    continue
                if not self._watching(pdn):
                    continue
                present.add((pdn, pid))
                out.extend(self._upsert(pdn, p))
        for key in [k for k in self.members if k not in present and self._watching(k[0])]:
            out.extend(self._remove(*key))
        return out

    def tick(self):
        """Time-driven transitions: end of the grace period after the last
        participant leaves, and the two safety nets. Call about once a second."""
        now = self.clock()
        out = []
        for call in list(self.calls.values()):
            if call.empty_since is not None and now - call.empty_since >= self.terminal_grace_seconds:
                out.extend(self._finish(call, "removed"))
            elif call.empty_since is None and not call.connected and call.rang \
                    and now - call.first_seen > self.ringing_max_seconds:
                out.extend(self._finish(call, "ringing too long"))
            elif call.empty_since is None and call.connected \
                    and now - call.last_seen > self.stale_connected_seconds:
                out.extend(self._finish(call, "stale"))
        return out

    def open_call_count(self):
        return len(self.calls)

    # ── internals ─────────────────────────────────────────────

    def _watching(self, dn):
        return not self.monitor_dns or dn in self.monitor_dns

    def _eligible(self, dn, p):
        """Could this participant belong to a call worth showing?"""
        ptype = _text(_get(p, "party_dn_type", "PartyDnType")).lower()
        if not self.include_internal and "extension" in ptype:
            return False                    # the other end is another extension
        if dn and _text(_get(p, "originated_by_dn", "OriginatedByDn")) == dn:
            return False                    # this DN placed the call
        return True

    def _upsert(self, dn, p):
        if not self._watching(dn):
            return []
        try:
            pid = int(_get(p, "id", "Id"))
        except (TypeError, ValueError):
            return []
        status = _text(_get(p, "status", "Status")).lower()
        callid = _get(p, "callid", "CallId", "call_id")
        key = _text(callid) if callid not in (None, "") else "p:%s:%s" % (dn, pid)
        now = self.clock()
        pkey = (dn, pid)

        known = self.members.get(pkey)
        if known and known == (key, status):
            call = self.calls.get(key)
            if call:
                call.last_seen = now        # still present: keeps the stale net quiet
            return []                       # 3CX routinely sends the same Upsert twice

        if status not in KNOWN_STATUSES and status not in self._warned_statuses:
            self._warned_statuses.add(status)
            if self.log:
                self.log.warning("3CX participant status %r is not one this bridge knows; treating it as a "
                                 "non-terminal update (set capture_file to record real traffic)", status)

        call = self.calls.get(key)
        out = []
        if call is None:
            if not self._eligible(dn, p):
                return []
            if status == RINGING:
                call = _Call(key, "3cx:%s:%s" % (key, _iso(now).replace("-", "").replace(":", "")), now)
            elif status == CONNECTED:
                call = _Call(key, "3cx:%s:%s" % (key, _iso(now).replace("-", "").replace(":", "")), now)
                call.silent = True          # already in progress when first seen: never announce
                call.connected = True
            else:
                return []                   # Dialing etc. with no call yet: an outbound leg
            self.calls[key] = call

        if known and known[0] != key and known[0] in self.calls:
            self.calls[known[0]].members.discard(pkey)   # participant moved to another call
        call.members.add(pkey)
        call.empty_since = None
        call.last_seen = now
        self.members[pkey] = (key, status)

        number = _text(_get(p, "party_caller_id", "PartyCallerId"))
        name = _text(_get(p, "party_caller_name", "PartyCallerName"))
        if not call.caller_number:
            if _NUMBERISH.match(number):
                call.caller_number = number
            elif _NUMBERISH.match(name):
                call.caller_number = name
        if not call.caller_name:
            if name and not _NUMBERISH.match(name):
                call.caller_name = name
            elif number and not _NUMBERISH.match(number):
                call.caller_name = number   # e.g. "Anonymous": better shown than dropped
        if not call.called_number:
            call.called_number = (_text(_get(p, "party_did", "PartyDid"))
                                  or self.trunk_did_map.get(_text(_get(p, "party_dn", "PartyDn")))
                                  or self.default_called_number)

        if status == RINGING and not call.rang and not call.silent:
            call.rang = True
            out.append(self._event("ringing", call))
        elif status == CONNECTED and not call.connected:
            call.connected = True
            call.answered_by = dn
            if call.rang:
                out.append(self._event("claimed_externally", call, {"answered_by_dn": dn}))
        elif status == CONNECTED and call.answered_by is None:
            call.answered_by = dn
        return out

    def _remove(self, dn, pid):
        pkey = (dn, pid)
        known = self.members.pop(pkey, None)
        if known is None:
            return []
        call = self.calls.get(known[0])
        if call is None:
            return []
        call.members.discard(pkey)
        call.last_seen = self.clock()
        if not call.members:
            call.empty_since = self.clock()  # grace timer; tick() decides
        return []

    def _finish(self, call, reason):
        out = []
        if call.rang and not call.silent:
            out.append(self._event("ended" if call.connected else "abandoned", call))
        for pkey in [k for k, v in self.members.items() if v[0] == call.key]:
            self.members.pop(pkey, None)
        self.calls.pop(call.key, None)
        if self.log and reason not in ("removed",):
            self.log.warning("3CX call %s closed (%s): no Remove event arrived", call.canonical_id, reason)
        return out

    def _event(self, name, call, extra=None):
        ts = max(int(self.clock()), call.last_ts)
        call.last_ts = ts
        ev = {
            "event": name,
            "call_id": call.canonical_id,
            "caller_number": call.caller_number,
            "caller_name": call.caller_name,
            "called_number": call.called_number,
            "event_ts": _iso(ts),
        }
        if extra:
            ev.update(extra)
        return ev


# ─────────────────────────────────────────────────────────────
#  Capture: record real 3CX traffic so unknowns can be closed
# ─────────────────────────────────────────────────────────────

_REDACT_KEYS = ("party_caller_id", "party_caller_name", "device_id", "referred_by_dn", "on_behalf_of_dn")


def _redact_value(value):
    s = str(value)
    s = re.sub(r"[0-9]", "9", s)
    return re.sub(r"[A-Za-z]", "x", s)


def _redact(obj):
    if isinstance(obj, dict):
        return {k: (_redact_value(v) if k in _REDACT_KEYS and v not in (None, "") else _redact(v))
                for k, v in obj.items()}
    if isinstance(obj, list):
        return [_redact(v) for v in obj]
    return obj


class CaptureLog:
    """Append-only JSON-lines record of every WebSocket frame and every REST
    reply. Phone numbers, names and device addresses are masked by default
    (digits become 9, letters become x) so a file can be mailed to the project
    without leaking callers; the structure and every status/type/id is kept,
    which is all anyone needs to fix the bridge."""

    def __init__(self, path, redact=True):
        self.path = path
        self.redact = redact

    def write(self, kind, obj):
        if not self.path:
            return
        rec = {"t": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ"), "kind": kind,
               "data": _redact(obj) if self.redact else obj}
        try:
            with open(self.path, "a", encoding="utf-8") as fh:
                fh.write(json.dumps(rec, ensure_ascii=False) + "\n")
        except OSError:
            pass


# ─────────────────────────────────────────────────────────────
#  I/O: token, REST, WebSocket
# ─────────────────────────────────────────────────────────────

class ThreeCxError(Exception):
    """A problem talking to 3CX, phrased for an administrator to act on."""


def _ssl_context(verify_tls):
    ctx = ssl.create_default_context()
    if not verify_tls:
        ctx.check_hostname = False
        ctx.verify_mode = ssl.CERT_NONE
    return ctx


def _jwt_roles(token):
    """Roles from the (unverified) JWT payload, or None if it cannot be read.
    Used only to give a licence hint -- never for any security decision."""
    try:
        import base64
        payload = token.split(".")[1]
        payload += "=" * (-len(payload) % 4)
        data = json.loads(base64.urlsafe_b64decode(payload.encode()).decode("utf-8"))
        role = data.get("role")
        return [role] if isinstance(role, str) else list(role or [])
    except Exception:
        return None


class ThreeCxClient:
    def __init__(self, base_url, client_id, client_secret, verify_tls=True,
                 ws_url=None, token_url=None, timeout=10, log=None, capture=None):
        self.base_url = base_url.rstrip("/")
        self.client_id = client_id
        self.client_secret = client_secret
        self.verify_tls = verify_tls
        self.timeout = timeout
        self.log = log
        self.capture = capture
        self.token_url = token_url or self.base_url + "/connect/token"
        if ws_url:
            self.ws_url = ws_url
        else:
            scheme = "wss" if self.base_url.lower().startswith("https") else "ws"
            self.ws_url = scheme + "://" + self.base_url.split("://", 1)[-1] + "/callcontrol/ws"
        self._token = None
        self._token_refresh_at = 0.0
        self.token_roles = None

    # ── token ─────────────────────────────────────────────────

    def fetch_token(self):
        """OAuth2 client-credentials. Raises ThreeCxError with a plain-English
        hint for every failure an administrator can actually fix."""
        import requests
        try:
            resp = requests.post(
                self.token_url,
                data={"client_id": self.client_id, "client_secret": self.client_secret,
                      "grant_type": "client_credentials"},
                timeout=self.timeout, verify=self.verify_tls)
        except requests.exceptions.SSLError as exc:
            raise ThreeCxError("TLS error reaching %s (%s). If your 3CX uses a self-signed certificate, "
                               "set threecx_verify_tls = false." % (self.token_url, exc))
        except requests.RequestException as exc:
            raise ThreeCxError("cannot reach 3CX at %s (%s). Check threecx_url (add :5001 if your 3CX "
                               "uses that port) and that this machine can reach it." % (self.token_url, exc))
        if resp.status_code in (400, 401):
            raise ThreeCxError(
                "3CX rejected the API credentials (HTTP %d). threecx_client_id must be the Client ID entered "
                "under Admin > Integrations > API, and threecx_client_secret the API key shown once when it "
                "was created. If 3CX's Console Restrictions allow-list IP addresses, this computer must be on it."
                % resp.status_code)
        if resp.status_code == 404:
            raise ThreeCxError("HTTP 404 from %s -- threecx_url should be the address you use for the 3CX "
                               "web client, without a path." % self.token_url)
        if resp.status_code >= 400:
            raise ThreeCxError("3CX token request failed: HTTP %d %s" % (resp.status_code, resp.text[:200]))
        try:
            body = resp.json()
            token = body["access_token"]
        except (ValueError, KeyError):
            raise ThreeCxError("3CX returned a token reply without an access_token: %s" % resp.text[:200])
        self._token = token
        # 3CX documents a ~60 minute lifetime but real captures show expires_in: 60
        # (minutes) while its own SDK reads the field as seconds. Values under 300
        # are therefore read as minutes. UNVERIFIED which build does what; refreshing
        # early is harmless, and any HTTP 401 forces a refresh regardless.
        try:
            expires = int(body.get("expires_in", 3600))
        except (TypeError, ValueError):
            expires = 3600
        lifetime = expires if expires >= 300 else expires * 60
        self._token_refresh_at = time.time() + min(max(60, lifetime - 120), 50 * 60)
        self.token_roles = _jwt_roles(token)
        return token

    def token(self):
        if not self._token or time.time() >= self._token_refresh_at:
            return self.fetch_token()
        return self._token

    def licence_hint(self):
        """None when fine/unknown, else a sentence about the licence."""
        if self.token_roles is not None and "Enterprise" not in self.token_roles:
            return ("the 3CX token has no 'Enterprise' role, which is how 3CX marks AI Edition (formerly "
                    "Enterprise) -- the Call Control API probably is not included in this licence")
        return None

    # ── REST ──────────────────────────────────────────────────

    def get_json(self, path, _retry=True):
        """GET a Call Control REST path. Returns parsed JSON, or None for 404.
        Refreshes the token once on 401."""
        import requests
        try:
            resp = requests.get(self.base_url + path,
                                headers={"Authorization": "Bearer " + self.token()},
                                timeout=self.timeout, verify=self.verify_tls)
        except requests.RequestException as exc:
            raise ThreeCxError("GET %s failed: %s" % (path, exc))
        if resp.status_code == 401 and _retry:
            self.fetch_token()
            return self.get_json(path, _retry=False)
        if resp.status_code == 404:
            return None
        if resp.status_code in (401, 403):
            raise ThreeCxError(
                "3CX returned %d for %s. In Integrations > API tick '3CX Call Control API Access' on this "
                "client, list the extensions to monitor, and check the licence (the Call Control API needs "
                "3CX AI Edition, formerly Enterprise)." % (resp.status_code, path))
        if resp.status_code >= 400:
            raise ThreeCxError("GET %s returned HTTP %d" % (path, resp.status_code))
        try:
            body = resp.json()
        except ValueError:
            raise ThreeCxError("GET %s did not return JSON" % path)
        if self.capture:
            self.capture.write("get", {"path": path, "body": body})
        return body

    def snapshot(self):
        body = self.get_json("/callcontrol")
        if body is None:
            raise ThreeCxError("GET /callcontrol returned 404 -- is this the 3CX web address?")
        return body

    def get_participant(self, entity):
        return self.get_json(entity)

    # ── WebSocket ─────────────────────────────────────────────

    def connect_ws(self):
        try:
            from websockets.sync.client import connect
        except ImportError:
            raise ThreeCxError("the 'websockets' package is required for mode = threecx -- "
                               "run: pip install websockets")
        kwargs = {
            "additional_headers": {"Authorization": "Bearer " + self.token()},
            "open_timeout": self.timeout,
            "max_size": 4 * 1024 * 1024,
            "ping_interval": 5,
            "ping_timeout": 10,
        }
        if self.ws_url.lower().startswith("wss"):
            kwargs["ssl"] = _ssl_context(self.verify_tls)
        try:
            return connect(self.ws_url, **kwargs)
        except Exception as exc:    # InvalidStatus (401), timeouts, DNS, TLS ...
            raise ThreeCxError("WebSocket connect to %s failed: %s" % (self.ws_url, exc))


def build_tracker(cfg, log=None, clock=None):
    monitor = [d for d in re.split(r"[,\s]+", cfg.get("threecx_monitor_dns", "") or "") if d]
    return ThreeCxTracker(
        monitor_dns=monitor,
        include_internal=bool(cfg.get("threecx_include_internal", False)),
        trunk_did_map=parse_did_map(cfg.get("threecx_trunk_did_map", "")),
        default_called_number=cfg.get("threecx_default_called_number") or None,
        terminal_grace_seconds=float(cfg.get("threecx_terminal_grace_seconds", 2)),
        ringing_max_seconds=float(cfg.get("threecx_ringing_max_seconds", 180)),
        log=log, clock=clock)


def build_client(cfg, log=None):
    capture = CaptureLog(cfg.get("capture_file", ""), redact=bool(cfg.get("capture_redact", True))) \
        if cfg.get("capture_file") else None
    return ThreeCxClient(
        cfg["threecx_url"], cfg["threecx_client_id"], cfg["threecx_client_secret"],
        verify_tls=bool(cfg.get("threecx_verify_tls", True)),
        ws_url=cfg.get("threecx_ws_url") or None,
        token_url=cfg.get("threecx_token_url") or None,
        log=log, capture=capture), capture


def run_threecx_bridge(cfg, log, stop_event, forward, status=None):
    """Main loop for mode = threecx. `forward(event_dict)` hands one canonical
    event to the bridge's ordered delivery queue.

    Order matters on every (re)connect: open the WebSocket FIRST, then take the
    snapshot, so an event arriving between the two is not lost (3CX's own SDK
    does it the other way round and has that small window). Reconnects back off
    5s -> 120s and always re-reconcile against a fresh snapshot, which is how a
    hangup that happened during an outage is still reported."""
    client, capture = build_client(cfg, log)
    tracker = build_tracker(cfg, log)
    base_delay = max(1, int(cfg.get("threecx_reconnect_seconds", 5)))
    delay = base_delay
    reconcile_every = max(2, int(cfg.get("threecx_reconcile_seconds", 45)))
    warned_licence = False

    def deliver(events):
        for ev in events:
            forward(ev)

    while not stop_event.is_set():
        ws = None
        try:
            client.fetch_token()
            hint = client.licence_hint()
            if hint and not warned_licence:
                log.warning("3CX licence: %s", hint)
                warned_licence = True
            ws = client.connect_ws()
            deliver(tracker.apply_snapshot(client.snapshot()))
            log.info("3CX Call Control connected (%s)%s", client.ws_url,
                     (", monitoring: " + ",".join(sorted(tracker.monitor_dns))) if tracker.monitor_dns
                     else ", monitoring every extension the API client lists")
            if status is not None:
                status["threecx"] = "connected"
            connected_at = time.time()
            next_reconcile = time.time() + reconcile_every
            while not stop_event.is_set():
                try:
                    raw = ws.recv(timeout=1)
                except TimeoutError:
                    raw = None
                except Exception as exc:        # ConnectionClosed and friends
                    raise ThreeCxError("WebSocket closed: %s" % exc)
                if raw is not None:
                    try:
                        msg = json.loads(raw if isinstance(raw, str) else raw.decode("utf-8", "replace"))
                    except ValueError:
                        msg = None
                    if msg is not None:
                        if capture:
                            capture.write("ws", msg)
                        log.debug("3CX event: %s", json.dumps(msg)[:400])
                        deliver(tracker.handle_message(msg, fetch=client.get_participant))
                deliver(tracker.tick())
                if time.time() >= next_reconcile:
                    deliver(tracker.apply_snapshot(client.snapshot()))
                    next_reconcile = time.time() + reconcile_every
                if delay != base_delay and time.time() - connected_at > 30:
                    delay = base_delay          # a connection that held: forgive earlier failures
        except ThreeCxError as exc:
            log.warning("3CX: %s -- retrying in %ds", exc, delay)
            if status is not None:
                status["threecx"] = "error: %s" % exc
        except Exception as exc:    # never let one bad frame or socket kill the bridge
            log.exception("3CX bridge loop error: %s", exc)
            if status is not None:
                status["threecx"] = "error: %s" % exc
        finally:
            if ws is not None:
                try:
                    ws.close()
                except Exception:
                    pass
        stop_event.wait(delay)
        delay = min(delay * 2, 120)


def check_threecx(cfg, say):
    """Doctor steps for mode = threecx. `say(ok, message)` prints one line.
    Returns True when everything an administrator controls is working."""
    ok_all = True
    required = ("threecx_url", "threecx_client_id", "threecx_client_secret")
    missing = [k for k in required if not cfg.get(k)]
    if missing:
        say(False, "config is missing: " + ", ".join(missing))
        return False
    client, _ = build_client(cfg)
    try:
        client.fetch_token()
        say(True, "3CX accepted the API credentials (token from %s)" % client.token_url)
    except ThreeCxError as exc:
        say(False, str(exc))
        return False
    hint = client.licence_hint()
    if hint:
        say(False, hint[0].upper() + hint[1:])
        ok_all = False
    try:
        snap = client.snapshot()
        entries = [e for e in (snap if isinstance(snap, list) else [snap]) if isinstance(e, dict)]
        dns = [str(_get(e, "dn", "Dn", "DN")) for e in entries
               if "routepoint" not in _text(_get(e, "type", "Type")).lower()]
        if dns:
            say(True, "GET /callcontrol works -- extensions this API client can see: %s" % ", ".join(dns))
        else:
            say(False, "GET /callcontrol works but lists NO extensions. 3CX sends nothing for an empty list: "
                       "add every dispatch extension under 'extensions to monitor' on the API client.")
            ok_all = False
        wanted = [d for d in re.split(r"[,\s]+", cfg.get("threecx_monitor_dns", "") or "") if d]
        for d in wanted:
            if d not in dns:
                say(False, "DN %s is in threecx_monitor_dns but this API client cannot see it. Add it to the "
                           "client's extensions to monitor in Integrations > API." % d)
                ok_all = False
    except ThreeCxError as exc:
        say(False, str(exc))
        return False
    try:
        ws = client.connect_ws()
        ws.close()
        say(True, "WebSocket %s accepted the connection" % client.ws_url)
    except ThreeCxError as exc:
        say(False, str(exc))
        ok_all = False
    return ok_all
