#!/usr/bin/env python3
"""
Audio-matrix service (Phase 114c) — the runnable daemon.

Boots the MatrixCore, loads channels + routes from the TicketsCAD DB
(comm_channels / comm_routes), registers transport legs, and serves the
HTTP control plane. Runs as a systemd unit alongside the DMR bridge.

    python3 services/audio-matrix/service.py            # foreground
    (systemd: ticketscad-audio-matrix.service)

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
      }
    }

SAFETY — the DMR leg wires into the LIVE amateur-radio bridge. It stays
INERT unless dmr.mode == "live". With mode "off" (the default) the DMR
channel still exists in the matrix (so routes validate and the console can
show it) but no /audio-stream is read and no /tx/audio is posted — the live
DMR integration is untouched. Flip to "live" only deliberately; it is fully
reversible (set back to "off", restart).
"""

from __future__ import annotations

import json
import logging
import os
import signal
import sys
import threading

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
sys.path.insert(0, os.path.join(HERE, "legs"))

from matrix_core import Channel, MatrixCore, RegClass, Route, RouteError  # noqa: E402
from control_http import make_control_server                              # noqa: E402
from dmr import DmrLeg                                                     # noqa: E402

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


def load_channels(db, core: MatrixCore) -> dict:
    """Load enabled comm_channels into the matrix. Returns {channel_key: id}
    so routes can be resolved by the DB's integer ids."""
    cur = db.cursor(dictionary=True)
    key_by_id = {}
    try:
        cur.execute(
            "SELECT id, channel_key, label, regulatory_class, adapter "
            "FROM comm_channels WHERE enabled = 1"
        )
        for row in cur.fetchall():
            key = row["channel_key"]
            rc = _REG.get((row["regulatory_class"] or "internal"), RegClass.INTERNAL)
            try:
                core.add_channel(Channel(id=key, name=row["label"], reg_class=rc))
                key_by_id[row["id"]] = key
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
    key_by_id = load_channels(db, core)
    load_routes(db, core, key_by_id)

    leg = attach_dmr_leg(core, cfg)

    ticks = {"n": 0}
    _orig_tick = core.tick

    def counting_tick():
        _orig_tick()
        ticks["n"] += 1

    core.tick = counting_tick  # type: ignore
    core.start()

    port = int(cfg.get("control_port", 18092))
    token = cfg.get("control_token", "")
    if not token:
        LOG.warning("no control_token set — control-plane mutations are OPEN")
    srv = make_control_server(core, port, token,
                              get_ticks=lambda: ticks["n"])
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
        core.stop()
        srv.shutdown()
        try:
            db.close()
        except Exception:
            pass
    return 0


if __name__ == "__main__":
    sys.exit(main())
