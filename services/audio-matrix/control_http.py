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

Read endpoints are open (dispatch dashboards poll health); mutations
require the token so only the app / an authorized operator can re-patch.
"""

from __future__ import annotations

import json
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlparse, parse_qs

from matrix_core import Channel, MatrixCore, RegClass, Route, RouteError


def make_control_server(core: MatrixCore, port: int, token: str,
                        host: str = "127.0.0.1", get_ticks=None):
    """
    Build (but do not start) a ThreadingHTTPServer exposing `core`.
    Caller runs server.serve_forever() on its own thread. `get_ticks`
    is an optional callable returning the tick counter for /health.
    """

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
                    "enabled": r.enabled}

        @staticmethod
        def _chan_dict(c):
            return {"id": c.id, "name": c.name,
                    "reg_class": c.reg_class.value, "keyed": c._keyed}

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
                    r = core.add_route(Route(
                        src=str(data["src"]), dst=str(data["dst"]),
                        gain_db=float(data.get("gain_db", 0.0)),
                        priority=int(data.get("priority", 0)),
                        ducking=bool(data.get("ducking", True)),
                        enabled=bool(data.get("enabled", True)),
                        allow_cross_class=bool(data.get("allow_cross_class", False)),
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
            self._send(404, {"error": "not found"})

    return ThreadingHTTPServer((host, port), Handler)
