#!/usr/bin/env python3
"""
Audio-matrix service (Phase 114c) — the runnable daemon.

Boots the MatrixCore, loads channels + routes from the TicketsCAD DB
(comm_channels / comm_routes), registers transport legs, and serves the
HTTP control plane. Runs as a systemd unit alongside the DMR bridge.

    python3 services/audio-matrix/service.py            # foreground
    (systemd: ticketscad-audio-matrix.service)

Phase 152 prerequisite #4 — an empty control_token is a HARD boot refusal
(exit 2) unless you pass --dev-insecure on the command line. This is a
local-development-only escape hatch; a deployed host must always have a
real control_token configured. See control_http.py's ControlPlaneConfigError
docblock for why.

    python3 services/audio-matrix/service.py --dev-insecure  # local dev only

Config — /etc/ticketscad-audio-matrix.conf, JSON, mode 0600 owned by
www-data (same shape + location convention as the APRS listener's):

    {
      "db_host": "localhost", "db_user": "newui",
      "db_pass": "...", "db_name": "newui",
      "control_port": 18092,
      "control_token": "<shared secret the PHP console uses>",
      "dmr": {
        "mode": "off",                       # "off" | "live"
        "bridge_url": "http://127.0.0.1:18091",
        "channel_key": "dmr_bm:3127"
      },
      "browser": {
        "mode": "off",                       # "off" | "live"
        "host": "0.0.0.0",
        "port": 18093
      },
      "php_base_url": "https://your-install.example.com"  # optional (Phase
                                             # 152 prereq #7) -- lets the
                                             # browser leg report channel
                                             # connect/disconnect and TX
                                             # start/stop to
                                             # api/matrix-channel-state.php
                                             # so OTHER console viewers see
                                             # it over SSE. Omit/blank =
                                             # each session's own tab still
                                             # gets its own tx_started/
                                             # tx_ended, just no cross-
                                             # workstation fanout.
    }

SAFETY — the DMR leg wires into the LIVE amateur-radio bridge. It stays
INERT unless dmr.mode == "live". With mode "off" (the default) the DMR
channel still exists in the matrix (so routes validate and the console can
show it) but no /audio-stream is read and no /tx/audio is posted — the live
DMR integration is untouched. Flip to "live" only deliberately; it is fully
reversible (set back to "off", restart).

The browser leg (legs/browser.py, Phase 152 prerequisite #3) is different
in kind from every other leg: it has no static comm_channels row to sit
inert on, because a browser tab's channel is created and destroyed per
WebSocket connection, not loaded once at boot. So mode "off" (the default)
means the WS server is not started at all — nothing listens on `port` and
no dispatcher's browser can attach live audio to this install. Flip to
"live" only once console_sessions exists (sql/run_phase152_console_
sessions.php) and the `websockets` package is installed
(services/audio-matrix/requirements.txt).
"""

from __future__ import annotations

import json
import logging
import os
import signal
import sys
import threading
from typing import Optional

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "legs"))

from matrix_core import Channel, MatrixCore, RegClass, Route, RouteError  # noqa: E402
from control_http import ControlPlaneConfigError, make_control_server     # noqa: E402
from dmr import DmrLeg                                                     # noqa: E402
from browser import BrowserLegServer, default_validate_session, make_state_notify_fn  # noqa: E402
from reflector import ReflectorLeg                                         # noqa: E402
from http_stream import HttpStreamLeg                                      # noqa: E402
# ^ safe to import even when `websockets` isn't installed — that import is
# deferred inside BrowserLegServer._run_loop(), only reached if mode="live".

try:
    import mysql.connector  # type: ignore
except ImportError:  # pragma: no cover
    mysql = None  # type: ignore

CONFIG_FILE = os.environ.get("AUDIO_MATRIX_CONF", "/etc/ticketscad-audio-matrix.conf")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(name)s %(levelname)s %(message)s",
)
LOG = logging.getLogger("audio-matrix")


# ── config + DB ──────────────────────────────────────────────────────
def load_config() -> dict:
    if not os.path.exists(CONFIG_FILE):
        LOG.error("config file not found: %s", CONFIG_FILE)
        LOG.error('create it with {"db_host":"localhost","db_user":"newui",'
                  '"db_pass":"...","db_name":"newui","control_port":18092,'
                  '"control_token":"..."}')
        sys.exit(2)
    with open(CONFIG_FILE) as fh:
        cfg = json.load(fh)
    for key in ("db_host", "db_user", "db_pass", "db_name"):
        if key not in cfg:
            LOG.error("missing %s in %s", key, CONFIG_FILE)
            sys.exit(2)
    return cfg


def db_connect(cfg: dict):
    if mysql is None:
        LOG.error("mysql-connector-python not installed")
        sys.exit(2)
    return mysql.connector.connect(
        host=cfg["db_host"], user=cfg["db_user"],
        password=cfg["db_pass"], database=cfg["db_name"],
        autocommit=True, connection_timeout=10,
    )


_REG = {
    "amateur": RegClass.AMATEUR, "commercial": RegClass.COMMERCIAL,
    "pstn": RegClass.PSTN, "internal": RegClass.INTERNAL,
}


def _http_stream_url_from_config(config_json) -> Optional[str]:
    """Extract {"url": "..."} from a comm_channels.config_json TEXT value.
    Returns None on missing/malformed config rather than raising — a
    channel row with no usable url just doesn't get a leg, same posture
    as every other "gracefully skip, don't crash the boot" gate in this
    file (load_routes()'s missing-table check, load_workstation_mutes()'s
    same convention)."""
    if not config_json:
        return None
    try:
        cfg = json.loads(config_json)
    except (TypeError, ValueError):
        return None
    url = cfg.get("url") if isinstance(cfg, dict) else None
    return url if isinstance(url, str) and url.strip() else None


def load_channels(db, core: MatrixCore, cfg: Optional[dict] = None,
                   http_stream_registry: Optional[dict] = None) -> dict:
    """Load enabled comm_channels into the matrix. Returns {channel_key: id}
    so routes can be resolved by the DB's integer ids.

    Every channel loads with leg=None by default — most get a real leg
    attached later (attach_dmr_leg(), attach_browser_leg()) if configured
    live. Two adapters are the exception and get their leg attached
    RIGHT HERE, inline, because both are entirely self-contained from the
    DB row alone (no separate live/off toggle in the service config file):

      * `intercom_dd` NEVER gets an external transport at all (spec.md:
        "no new transport") but still needs SOME leg, or a talk route into
        it is silently discarded and a listen route out of it is
        permanently silent (see legs/reflector.py's own docblock for the
        full "why" — this was a real, shipped-but-non-functional gap until
        that fix). Attached unconditionally — there is no legitimate "off"
        state for a pure hub channel.

      * `http_stream` (2026-09-08, Eric: "I want to create multiple
        streams as needed") reads its URL from `config_json` (written by
        tools/http-stream-channels.php's admin UI, or the control plane's
        live POST /channels/http_stream) and starts an HttpStreamLeg
        immediately if a url is present. Unlike DMR (one bridge, one
        static off/live toggle) there can be any number of these rows, so
        there's no single config-file switch for them — `enabled=0` on
        the row itself IS the off state (the WHERE clause below already
        excludes disabled rows). A malformed/missing url just skips the
        leg with a warning rather than crashing the whole service boot.
    """
    cur = db.cursor(dictionary=True)
    key_by_id = {}
    ffmpeg_path = (cfg or {}).get("ffmpeg_path", "ffmpeg")
    try:
        cur.execute(
            "SELECT id, channel_key, label, regulatory_class, adapter, config_json "
            "FROM comm_channels WHERE enabled = 1"
        )
        for row in cur.fetchall():
            key = row["channel_key"]
            rc = _REG.get((row["regulatory_class"] or "internal"), RegClass.INTERNAL)
            try:
                core.add_channel(Channel(id=key, name=row["label"], reg_class=rc))
                key_by_id[row["id"]] = key
                if row["adapter"] == "intercom_dd":
                    core.channel(key).leg = ReflectorLeg(core, key)
                elif row["adapter"] == "http_stream":
                    url = _http_stream_url_from_config(row.get("config_json"))
                    if url:
                        leg = HttpStreamLeg(core, channel_id=key, url=url, ffmpeg_path=ffmpeg_path)
                        core.channel(key).leg = leg
                        leg.start_rx()
                        if http_stream_registry is not None:
                            http_stream_registry[key] = leg
                        LOG.warning("http_stream leg LIVE on channel %s -> %s", key, url)
                    else:
                        LOG.warning("http_stream channel %r has no usable url in "
                                    "config_json — leg NOT attached", key)
            except RouteError:
                pass  # duplicate key — already added
    finally:
        cur.close()
    LOG.info("loaded %d channels", len(key_by_id))
    return key_by_id


def load_routes(db, core: MatrixCore, key_by_id: dict) -> int:
    """Load comm_routes into the matrix, resolving channel ids to keys.
    Routes whose endpoints aren't loaded (disabled/pruned channels) are
    skipped. Regulatory-blocked routes without the override are skipped
    with a warning rather than aborting the whole service."""
    cur = db.cursor(dictionary=True)
    n = 0
    try:
        # comm_routes may not exist yet on an install that hasn't run the
        # 114c migration — degrade to "no routes" rather than crash.
        cur.execute("SHOW TABLES LIKE 'comm_routes'")
        if not cur.fetchone():
            LOG.info("comm_routes table absent — starting with no routes")
            return 0
        cur.execute(
            "SELECT src_channel_id, dst_channel_id, gain_db, priority, "
            "ducking, enabled, allow_cross_class FROM comm_routes"
        )
        for row in cur.fetchall():
            src = key_by_id.get(row["src_channel_id"])
            dst = key_by_id.get(row["dst_channel_id"])
            if not src or not dst:
                continue
            try:
                core.add_route(Route(
                    src=src, dst=dst,
                    gain_db=float(row["gain_db"] or 0.0),
                    priority=int(row["priority"] or 0),
                    ducking=bool(row["ducking"]),
                    enabled=bool(row["enabled"]),
                    allow_cross_class=bool(row["allow_cross_class"]),
                ))
                n += 1
            except RouteError as e:
                LOG.warning("skipping route %s->%s: %s", src, dst, e)
    finally:
        cur.close()
    LOG.info("loaded %d routes", n)
    return n


def load_workstation_mutes(db, core: MatrixCore) -> int:
    """
    Load console_workstation_mutes into the matrix at boot, resolving
    PHP's internal integer workstation ids to the real workstation_token
    strings the matrix works with (Channel.workstation_token). Without
    this, every pairing an operator configured before a service restart
    would silently vanish from the LIVE matrix (the DB rows survive fine —
    api/console-workstation-mutes.php's own control-plane push only ever
    updates the RUNNING process) until each was re-toggled by hand.
    Degrades to 0 gracefully on an install that hasn't run sql/run_
    phase152_workstations.php yet, matching load_routes()'s own convention
    for a not-yet-migrated table.
    """
    cur = db.cursor(dictionary=True)
    n = 0
    try:
        cur.execute("SHOW TABLES LIKE 'console_workstation_mutes'")
        if not cur.fetchone():
            LOG.info("console_workstation_mutes table absent — starting with no workstation mutes")
            return 0
        cur.execute(
            "SELECT wa.workstation_token AS src_token, wb.workstation_token AS dst_token "
            "FROM console_workstation_mutes m "
            "JOIN console_workstations wa ON wa.id = m.workstation_id "
            "JOIN console_workstations wb ON wb.id = m.muted_workstation_id"
        )
        for row in cur.fetchall():
            core.set_workstation_mute(row["src_token"], row["dst_token"], True)
            n += 1
    finally:
        cur.close()
    LOG.info("loaded %d workstation mute pairs", n)
    return n


# ── legs ─────────────────────────────────────────────────────────────
def attach_dmr_leg(core: MatrixCore, cfg: dict):
    """Attach the DMR leg IF configured live. Returns the leg or None.

    mode "off" (default): the DMR channel stays in the matrix but gets no
    leg — the live bridge is never contacted. mode "live": read
    /audio-stream and post /tx/audio against the real bridge."""
    dmr = cfg.get("dmr") or {}
    mode = (dmr.get("mode") or "off").lower()
    key = dmr.get("channel_key")
    if mode != "live":
        LOG.info("DMR leg mode=%s — NOT wiring the live bridge", mode)
        return None
    if not key or core.channel(key) is None:
        LOG.warning("DMR leg 'live' but channel_key %r not loaded — skipping", key)
        return None
    leg = DmrLeg(core, channel_id=key,
                 bridge_url=dmr.get("bridge_url", "http://127.0.0.1:18091"))
    core.channel(key).leg = leg
    leg.start_rx()
    LOG.warning("DMR leg LIVE on channel %s -> %s", key, leg.bridge_url)
    return leg


def attach_browser_leg(core: MatrixCore, cfg: dict):
    """Attach the dynamic browser-tab leg IF configured live. Returns the
    running BrowserLegServer or None.

    mode "off" (default): no WS server is started at all — unlike DMR,
    there's no static comm_channels row for this to sit inert on; a
    browser session's channel is created and destroyed per WebSocket
    connection (see legs/browser.py), so "off" means literally nothing is
    listening on `port`.

    mode "live": start the WS accept loop and validate each connection's
    session_token against console_sessions on a DEDICATED DB connection —
    deliberately NOT the shared boot-time `db` handle passed into
    load_channels()/load_routes(), because mysql-connector-python
    connections are not safe to use concurrently from more than one
    thread, and this leg's accept loop runs on its own thread for the
    service's entire lifetime."""
    browser = cfg.get("browser") or {}
    mode = (browser.get("mode") or "off").lower()
    if mode != "live":
        LOG.info("Browser leg mode=%s — WS server NOT started", mode)
        return None
    if mysql is None:
        LOG.warning("Browser leg 'live' but mysql-connector-python not installed — skipping")
        return None
    session_db = db_connect(cfg)

    def validate(token: str):
        return default_validate_session(
            lambda: session_db.cursor(dictionary=True), token
        )

    # Phase 152 prerequisite #7 — reports channel connect/disconnect and
    # TX start/stop to PHP (api/matrix-channel-state.php) so a
    # screen.console viewer on ANOTHER workstation sees it over SSE, not
    # just the one tab directly involved. Optional: `php_base_url` has no
    # other consumer in this config today, so an install that hasn't set
    # it yet just gets local-only WS echo (the strip still gets its own
    # tx_started/tx_ended) with no cross-workstation fanout, never a
    # startup failure.
    php_base_url = str(cfg.get("php_base_url") or "").strip()
    notify_fn = (
        make_state_notify_fn(php_base_url, cfg.get("control_token", ""))
        if php_base_url
        else None
    )
    if not php_base_url:
        LOG.info("Browser leg: php_base_url not configured — channel/TX "
                 "state stays local to each session's own tab (no SSE fanout)")

    server = BrowserLegServer(
        core,
        host=browser.get("host", "0.0.0.0"),
        port=int(browser.get("port", 18093)),
        notify_fn=notify_fn,
        validate_session=validate,
    )
    server.start()
    LOG.warning("Browser leg LIVE on %s:%d", server.host, server.port)
    return server


def make_http_stream_leg_factory(core: MatrixCore, cfg: dict, http_stream_registry: dict):
    """Build the (create, remove) closures the control plane calls to
    hot-attach/detach an HttpStreamLeg WITHOUT a service restart
    (2026-09-08, Eric: "I want to create multiple streams as needed" —
    a full admin-manageable channel type, not a restart-required CLI/
    config-file dance). Boot-time streams (rows that already existed
    before this process started) are attached inline by load_channels()
    itself; this factory is for streams an admin adds/removes AFTER boot,
    via api/http-stream-channels.php -> matrix_control_apply_http_stream_
    create()/_delete() -> control_http.py's POST/DELETE
    /channels/http_stream.

    `http_stream_registry` is the SAME dict load_channels() populates for
    boot-time streams — sharing it means main()'s shutdown path can stop
    every http_stream leg (boot-attached AND hot-attached) from one place,
    and a hot-created stream that happens to reuse a boot-time channel_key
    is handled the same way an admin editing a URL is (stop the old leg,
    attach the new one) rather than leaking the old ffmpeg subprocess.
    """
    ffmpeg_path = cfg.get("ffmpeg_path", "ffmpeg")

    def create(channel_id: str, name: str, reg_class: str, url: str):
        rc = _REG.get(reg_class or "internal", RegClass.INTERNAL)
        ch = core.channel(channel_id)
        if ch is None:
            ch = core.add_channel(Channel(id=channel_id, name=name, reg_class=rc))
        old = http_stream_registry.pop(channel_id, None)
        if old is not None:
            old.stop()  # replacing an existing live stream on this channel (e.g. URL edit)
        leg = HttpStreamLeg(core, channel_id=channel_id, url=url, ffmpeg_path=ffmpeg_path)
        ch.leg = leg
        leg.start_rx()
        http_stream_registry[channel_id] = leg
        LOG.warning("http_stream leg LIVE (hot-attached) on channel %s -> %s", channel_id, url)
        return leg

    def remove(channel_id: str) -> bool:
        leg = http_stream_registry.pop(channel_id, None)
        if leg is not None:
            leg.stop()
        return core.remove_channel(channel_id)

    return create, remove


# ── audit sink ───────────────────────────────────────────────────────
def make_audit_sink():
    def sink(event_type: str, detail: dict):
        LOG.info("AUDIT %s %s", event_type, json.dumps(detail, default=str))
    return sink


# ── main ─────────────────────────────────────────────────────────────
def main() -> int:
    cfg = load_config()
    db = db_connect(cfg)

    core = MatrixCore(on_audit=make_audit_sink())
    http_stream_legs: dict = {}   # channel_key -> HttpStreamLeg, boot- AND hot-attached
    key_by_id = load_channels(db, core, cfg, http_stream_legs)
    load_routes(db, core, key_by_id)
    load_workstation_mutes(db, core)

    leg = attach_dmr_leg(core, cfg)
    browser_srv = attach_browser_leg(core, cfg)
    create_http_stream_leg, remove_http_stream_leg = make_http_stream_leg_factory(
        core, cfg, http_stream_legs)

    ticks = {"n": 0}
    _orig_tick = core.tick

    def counting_tick():
        _orig_tick()
        ticks["n"] += 1

    core.tick = counting_tick  # type: ignore
    core.start()

    port = int(cfg.get("control_port", 18092))
    token = cfg.get("control_token", "")
    dev_insecure = "--dev-insecure" in sys.argv
    if dev_insecure and not token:
        LOG.warning("--dev-insecure with no control_token — control-plane "
                    "mutations are OPEN. Local development ONLY.")
    try:
        srv = make_control_server(core, port, token,
                                  get_ticks=lambda: ticks["n"],
                                  dev_insecure=dev_insecure,
                                  create_http_stream_leg=create_http_stream_leg,
                                  remove_http_stream_leg=remove_http_stream_leg)
    except ControlPlaneConfigError as e:
        LOG.error(str(e))
        if leg is not None:
            leg.stop()
        if browser_srv is not None:
            browser_srv.stop()
        for hleg in http_stream_legs.values():
            hleg.stop()
        core.stop()
        try:
            db.close()
        except Exception:
            pass
        return 2
    srv_thread = threading.Thread(target=srv.serve_forever, name="ctl-http",
                                  daemon=True)
    srv_thread.start()
    LOG.info("control plane on 127.0.0.1:%d", port)
    LOG.info("audio matrix running (%d channels, tick=20ms)",
             len(core.channels()))

    stop = threading.Event()

    def _sig(_signum, _frame):
        LOG.info("signal received — shutting down")
        stop.set()

    signal.signal(signal.SIGTERM, _sig)
    signal.signal(signal.SIGINT, _sig)
    try:
        stop.wait()
    finally:
        if leg is not None:
            leg.stop()
        if browser_srv is not None:
            browser_srv.stop()
        for hleg in http_stream_legs.values():
            hleg.stop()
        core.stop()
        srv.shutdown()
        try:
            db.close()
        except Exception:
            pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
