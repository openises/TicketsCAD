"""
HTTP control plane for the audio matrix (Phase 114c).

The audio flows through legs (binary UDP/streams); THIS is the control
surface the PHP app + console talk to over HTTP — list/patch channels and
routes, read health. Same Bearer-token auth pattern as the DMR bridge's
/tx/text endpoint (services/dvswitch/hbp_client.py). Stdlib only
(http.server), so it runs anywhere the matrix does with no dependency.

Endpoints (all JSON; every mutating call requires Authorization: Bearer):
  GET  /health                      -> {running, ticks, channels, routes}
  GET  /channels                    -> [{id,name,reg_class,keyed}]
  GET  /routes                      -> [{src,dst,gain_db,priority,ducking,enabled}]
  POST /channels {id,name,reg_class}-> add a channel (setup)
  POST /routes   {src,dst,...}      -> add a patch (regulatory-guarded)
  DELETE /routes?src=&dst=          -> remove a patch
  POST /routes/enable {src,dst,enabled} -> toggle a patch live
  POST /routes/update {old_src,old_dst,src,dst,...} -> remove-then-add
                                        (Phase 152 prerequisite #4's live
                                        apply of a comm_routes UPDATE)
  GET  /workstation-mutes            -> {workstation_token: [muted_token,...]}
  POST /workstation-mutes {workstation_token,muted_workstation_token,muted}
                                      -> set/clear ONE direction of an
                                        adjacent-transmit-mute pairing
                                        (Phase 152 plan.md section 3.5 --
                                        the PHP caller sends this twice for
                                        a mutual pairing, same as a full-
                                        duplex Route needing two rows)
  POST /channels/http_stream {channel_id,name,reg_class,url}
                                      -> create (or re-attach to an
                                        existing bare) channel AND attach a
                                        live HttpStreamLeg, so a stream
                                        added via the admin UI starts
                                        flowing immediately -- no service
                                        restart (2026-09-08, Eric: "I want
                                        to create multiple streams as
                                        needed"). Only present when
                                        service.py wires create_http_
                                        stream_leg/remove_http_stream_leg
                                        into make_control_server(); 501
                                        otherwise.
  DELETE /channels/http_stream?channel_id=  -> stop the leg and remove the
                                        channel from the live matrix
  POST /channels/leg {channel_id,name,reg_class,adapter,config}
                                      -> create (or re-attach) a channel AND
                                        attach a live USRP-family leg
                                        (adapter "dvmproject" or
                                        "usrp_bridge"; Phase 155, GH#151/
                                        GH#129 -- LISTEN-ONLY) with no
                                        service restart. 501 unless
                                        service.py wires create_leg/
                                        remove_leg; 400 on a bad config or a
                                        UDP port already in use.
  DELETE /channels/leg?channel_id=    -> stop that leg, free its port, and
                                        remove the channel
  GET  /legs                          -> per-leg health for USRP-family legs
                                        ([{channel_id, running, receiving,
                                        rx_frames, ...}]). BEARER-REQUIRED
                                        (unlike the other reads): it carries
                                        the bridge's address and ports.

Read endpoints are open (dispatch dashboards poll health); mutations
require the token so only the app / an authorized operator can re-patch.

Phase 152 prerequisite #4 — an EMPTY token used to mean "mutations are
open to anyone who can reach this port" (a warning, not a refusal). That
default is now closed: make_control_server() REFUSES to build a server
with an empty token unless the caller passes dev_insecure=True (service.py
threads this from an explicit `--dev-insecure` CLI flag, never a config
default). This is deliberately a hard error at construction time, not a
runtime warning a busy operator can miss in a log file.
"""

from __future__ import annotations

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

from matrix_core import Channel, MatrixCore, RegClass, Route, RouteError


class ControlPlaneConfigError(RuntimeError):
    """Raised when make_control_server() is asked to boot an unsafe
    configuration (empty control_token with no explicit dev override)."""


def make_control_server(core: MatrixCore, port: int, token: str,
                        host: str = "127.0.0.1", get_ticks=None,
                        dev_insecure: bool = False,
                        create_http_stream_leg=None,
                        remove_http_stream_leg=None,
                        create_leg=None,
                        remove_leg=None,
                        get_leg_health=None):
    """
    Build (but do not start) a ThreadingHTTPServer exposing `core`.
    Caller runs server.serve_forever() on its own thread. `get_ticks`
    is an optional callable returning the tick counter for /health.

    `create_http_stream_leg(channel_id, name, reg_class, url) -> leg` and
    `remove_http_stream_leg(channel_id) -> bool` are injected by service.py
    (2026-09-08) rather than this module importing HttpStreamLeg directly --
    keeps the control plane decoupled from leg implementations, matching
    how it already has no idea DmrLeg/BrowserLeg exist. None (the default)
    means the /channels/http_stream endpoints answer 501; service.py always
    wires both in practice since HttpStreamLeg has no optional external
    dependency the way the browser leg's mysql-connector check does.

    `create_leg(channel_id, name, reg_class, adapter, config) -> leg`,
    `remove_leg(channel_id) -> bool` and `get_leg_health() -> list[dict]`
    (Phase 155) are the same injection for the generic USRP-family legs;
    None answers 501 on the three routes that need them.

    Raises ControlPlaneConfigError if `token` is empty and `dev_insecure`
    is not explicitly True — an open control plane means anyone who can
    reach `port` can create/delete routes (including cross-class ones) and
    add channels with no audit trail of who did it. There is no safe
    production default for this; the caller must either configure a real
    token or explicitly opt into the insecure dev mode.
    """
    if not token and not dev_insecure:
        raise ControlPlaneConfigError(
            "control_token is empty. Refusing to start the control plane "
            "with open (unauthenticated) route/channel mutations. Set "
            "control_token in the service config, or pass "
            "dev_insecure=True (service.py: --dev-insecure on the command "
            "line) for local development ONLY -- never on a deployed host."
        )

    class Handler(BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):    # silence default stderr spam
            pass

        # ── helpers ──────────────────────────────────────────────
        def _send(self, code, obj):
            body = json.dumps(obj).encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def _authed(self):
            hdr = self.headers.get("Authorization", "")
            if hdr.startswith("Bearer ") and hdr[7:].strip() == token:
                return True
            # Consume (up to 1 MiB of) the request body BEFORE answering.
            # Replying 401 and closing with the body unread makes the client's
            # send race the server's close, which surfaces on Windows as a
            # connection-aborted error instead of the 401 (seen intermittently
            # in services/audio-matrix/tests/test_usrp_service.py).
            try:
                n = min(int(self.headers.get("Content-Length", "0") or "0"), 1048576)
                if n > 0:
                    self.rfile.read(n)
            except Exception:
                pass
            self._send(401, {"error": "unauthorized"})
            return False

        def _body(self):
            n = int(self.headers.get("Content-Length", "0") or "0")
            if n <= 0:
                return {}
            try:
                return json.loads(self.rfile.read(n).decode("utf-8"))
            except Exception:
                return None

        @staticmethod
        def _route_dict(r):
            return {"src": r.src, "dst": r.dst, "gain_db": r.gain_db,
                    "priority": r.priority, "ducking": r.ducking,
                    "enabled": r.enabled, "allow_cross_class": r.allow_cross_class,
                    "expires_at": r.expires_at, "is_expired": r.is_expired()}

        @staticmethod
        def _chan_dict(c):
            return {"id": c.id, "name": c.name,
                    "reg_class": c.reg_class.value, "keyed": c._keyed,
                    "workstation_token": c.workstation_token}

        # ── GET ──────────────────────────────────────────────────
        def do_GET(self):
            path = urlparse(self.path).path
            if path == "/health":
                self._send(200, {
                    "running": True,
                    "ticks": (get_ticks() if get_ticks else None),
                    "channels": len(core.channels()),
                    "routes": len(core.routes()),
                })
                return
            if path == "/channels":
                self._send(200, [self._chan_dict(c) for c in core.channels()])
                return
            if path == "/routes":
                self._send(200, [self._route_dict(r) for r in core.routes()])
                return
            if path == "/workstation-mutes":
                self._send(200, core.workstation_mutes())
                return
            if path == "/legs":
                # Auth required: the body names the bridge's host and ports.
                if not self._authed():
                    return
                if get_leg_health is None:
                    self._send(501, {"error": "legs not supported by this build"})
                    return
                self._send(200, {"legs": get_leg_health()})
                return
            self._send(404, {"error": "not found"})

        # ── POST / DELETE ────────────────────────────────────────
        def do_POST(self):
            if not self._authed():
                return
            path = urlparse(self.path).path
            data = self._body()
            if data is None:
                self._send(400, {"error": "invalid JSON"})
                return

            if path == "/channels":
                try:
                    rc = RegClass(data.get("reg_class", "internal"))
                    ch = core.add_channel(Channel(str(data["id"]), str(data.get("name", data["id"])), rc))
                    self._send(200, self._chan_dict(ch))
                except (RouteError, ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                return

            if path == "/routes":
                try:
                    expires_at = data.get("expires_at")
                    r = core.add_route(Route(
                        src=str(data["src"]), dst=str(data["dst"]),
                        gain_db=float(data.get("gain_db", 0.0)),
                        priority=int(data.get("priority", 0)),
                        ducking=bool(data.get("ducking", True)),
                        enabled=bool(data.get("enabled", True)),
                        allow_cross_class=bool(data.get("allow_cross_class", False)),
                        expires_at=(float(expires_at) if expires_at is not None else None),
                    ))
                    self._send(200, self._route_dict(r))
                except (RouteError, ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                return

            if path == "/routes/enable":
                src, dst = str(data.get("src", "")), str(data.get("dst", ""))
                want = bool(data.get("enabled", True))
                for r in core.routes():
                    if r.src == src and r.dst == dst:
                        r.enabled = want
                        self._send(200, self._route_dict(r))
                        return
                self._send(404, {"error": "route not found"})
                return

            if path == "/routes/update":
                # Phase 152 prerequisite #4 (live route apply from
                # api/matrix.php). There is no field-level "patch a route"
                # primitive on MatrixCore -- add_route() always ADDS and
                # rejects a duplicate src/dst pair outright, which is
                # exactly the common case here (same src/dst, only gain_db/
                # priority/etc changed). So this is remove-then-add: locate
                # the OLD route by (old_src, old_dst) and remove it (a
                # no-op, not an error, if it's already gone -- e.g. a
                # retry), then add_route() the NEW full field set (which
                # re-validates everything, exactly mirroring
                # inc/matrix-routes.php's matrix_route_update() re-
                # validating the whole triple on every edit).
                #
                # Narrow atomicity gap, documented rather than hidden: if
                # the new add_route() call fails validation (e.g. the edit
                # introduced a blocked cross-class pair with no override),
                # the OLD route is already gone and is NOT automatically
                # restored -- this endpoint attempts a best-effort re-add
                # of the old route so the matrix isn't left with a hole,
                # and reports the ORIGINAL failure either way. The risk is
                # low in practice: api/matrix.php's PHP-side validation
                # (inc/matrix-routes.php) mirrors add_route()'s rules
                # exactly, so a request that reaches this endpoint has
                # already passed the same checks once.
                try:
                    old_src = str(data["old_src"])
                    old_dst = str(data["old_dst"])
                    expires_at = data.get("expires_at")
                    new_route = Route(
                        src=str(data["src"]), dst=str(data["dst"]),
                        gain_db=float(data.get("gain_db", 0.0)),
                        priority=int(data.get("priority", 0)),
                        ducking=bool(data.get("ducking", True)),
                        enabled=bool(data.get("enabled", True)),
                        allow_cross_class=bool(data.get("allow_cross_class", False)),
                        expires_at=(float(expires_at) if expires_at is not None else None),
                    )
                except (ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                    return

                old = None
                for r in core.routes():
                    if r.src == old_src and r.dst == old_dst:
                        old = r
                        break
                core.remove_route(old_src, old_dst)
                try:
                    r = core.add_route(new_route)
                    self._send(200, self._route_dict(r))
                except (RouteError, ValueError) as e:
                    if old is not None:
                        try:
                            core.add_route(old)
                        except (RouteError, ValueError):
                            pass  # best effort only -- the failure below is still reported
                    self._send(400, {"error": str(e)})
                return

            if path == "/workstation-mutes":
                try:
                    ws_token = str(data["workstation_token"])
                    muted_token = str(data["muted_workstation_token"])
                    muted = bool(data.get("muted", True))
                    if not ws_token or not muted_token:
                        raise ValueError("workstation_token and muted_workstation_token are required")
                except (ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                    return
                core.set_workstation_mute(ws_token, muted_token, muted)
                self._send(200, {"ok": True})
                return

            if path == "/channels/http_stream":
                if create_http_stream_leg is None:
                    self._send(501, {"error": "http_stream legs not supported by this build"})
                    return
                try:
                    channel_id = str(data["channel_id"])
                    name = str(data.get("name") or channel_id)
                    reg_class = str(data.get("reg_class") or "internal")
                    url = str(data["url"])
                    if not channel_id or not url:
                        raise ValueError("channel_id and url are required")
                    create_http_stream_leg(channel_id, name, reg_class, url)
                    self._send(200, {"ok": True, "channel_id": channel_id})
                except (RouteError, ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                except Exception as e:  # noqa: BLE001 - e.g. leg construction failed
                    self._send(400, {"error": "could not start stream: " + str(e)})
                return

            if path == "/channels/leg":
                if create_leg is None:
                    self._send(501, {"error": "legs not supported by this build"})
                    return
                try:
                    channel_id = str(data["channel_id"])
                    name = str(data.get("name") or channel_id)
                    reg_class = str(data.get("reg_class") or "")
                    adapter = str(data["adapter"])
                    config = data.get("config") or {}
                    if not channel_id or not adapter:
                        raise ValueError("channel_id and adapter are required")
                    if not isinstance(config, dict):
                        raise ValueError("config must be an object")
                    create_leg(channel_id, name, reg_class, adapter, config)
                    self._send(200, {"ok": True, "channel_id": channel_id})
                except (RouteError, ValueError, KeyError) as e:
                    self._send(400, {"error": str(e)})
                except Exception as e:  # noqa: BLE001 - e.g. leg construction failed
                    self._send(400, {"error": "could not start leg: " + str(e)})
                return

            self._send(404, {"error": "not found"})

        def do_DELETE(self):
            if not self._authed():
                return
            parsed = urlparse(self.path)
            if parsed.path == "/routes":
                q = parse_qs(parsed.query)
                src = (q.get("src") or [""])[0]
                dst = (q.get("dst") or [""])[0]
                ok = core.remove_route(src, dst)
                self._send(200 if ok else 404, {"removed": ok})
                return
            if parsed.path == "/channels/http_stream":
                if remove_http_stream_leg is None:
                    self._send(501, {"error": "http_stream legs not supported by this build"})
                    return
                q = parse_qs(parsed.query)
                channel_id = (q.get("channel_id") or [""])[0]
                if not channel_id:
                    self._send(400, {"error": "channel_id required"})
                    return
                ok = remove_http_stream_leg(channel_id)
                self._send(200 if ok else 404, {"removed": ok})
                return
            if parsed.path == "/channels/leg":
                if remove_leg is None:
                    self._send(501, {"error": "legs not supported by this build"})
                    return
                q = parse_qs(parsed.query)
                channel_id = (q.get("channel_id") or [""])[0]
                if not channel_id:
                    self._send(400, {"error": "channel_id required"})
                    return
                ok = remove_leg(channel_id)
                self._send(200 if ok else 404, {"removed": ok})
                return
            self._send(404, {"error": "not found"})

    return ThreadingHTTPServer((host, port), Handler)
