"""
Service-glue test (Phase 114c) — load_channels / load_routes / DMR gating.

Exercises service.py's DB-to-matrix wiring with a fake DB cursor (canned
rows) — no MySQL, no bridge, no network. Focus is the load-time logic that
CI must protect: regulatory-blocked routes are skipped not fatal, routes to
missing channels are dropped, and the DMR leg stays INERT unless mode=live.

    python3 services/audio-matrix/tests/test_service.py
"""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "legs"))

import service  # noqa: E402
from matrix_core import MatrixCore  # noqa: E402

_pass = 0
_fail = 0


def check(name, cond):
    global _pass, _fail
    if cond:
        _pass += 1
        print(f"[PASS] {name}")
    else:
        _fail += 1
        print(f"[FAIL] {name}")


class FakeCursor:
    """Minimal DB cursor: returns queued result sets in order of execute()."""
    def __init__(self, script):
        self._script = script       # list of (match_substr, rows)
        self._rows = []

    def execute(self, sql, params=None):
        low = sql.lower()
        for match, rows in self._script:
            if match in low:
                self._rows = rows
                return
        self._rows = []

    def fetchall(self):
        return list(self._rows)

    def fetchone(self):
        return self._rows[0] if self._rows else None

    def close(self):
        pass


class FakeDB:
    def __init__(self, script):
        self._script = script

    def cursor(self, dictionary=False):
        return FakeCursor(self._script)


# ── channels + routes load ────────────────────────────────────────────
CHANNELS = [
    {"id": 1, "channel_key": "dmr_bm:3127", "label": "TG 3127",
     "regulatory_class": "amateur", "adapter": "dmr_bm"},
    {"id": 2, "channel_key": "disp:main", "label": "Dispatch",
     "regulatory_class": "internal", "adapter": "broker"},
    {"id": 3, "channel_key": "sip:desk", "label": "Desk phone",
     "regulatory_class": "pstn", "adapter": "sip"},
]
ROUTES = [
    # valid: dmr -> dispatch (amateur->internal always allowed)
    {"src_channel_id": 1, "dst_channel_id": 2, "gain_db": 0, "priority": 0,
     "ducking": 1, "enabled": 1, "allow_cross_class": 0},
    # blocked: dmr -> sip (amateur->pstn, no override) must be SKIPPED
    {"src_channel_id": 1, "dst_channel_id": 3, "gain_db": 0, "priority": 0,
     "ducking": 1, "enabled": 1, "allow_cross_class": 0},
    # dangling: dst 99 doesn't exist -> dropped
    {"src_channel_id": 2, "dst_channel_id": 99, "gain_db": 0, "priority": 0,
     "ducking": 1, "enabled": 1, "allow_cross_class": 0},
    # allowed via override: dmr -> sip WITH allow_cross_class
    {"src_channel_id": 1, "dst_channel_id": 3, "gain_db": 0, "priority": 0,
     "ducking": 1, "enabled": 1, "allow_cross_class": 1},
]

db = FakeDB([
    ("from comm_channels", CHANNELS),
    ("show tables like 'comm_routes'", [{"x": "comm_routes"}]),
    ("from comm_routes", ROUTES),
])

core = MatrixCore()
key_by_id = service.load_channels(db, core)
check("loaded 3 channels", len(core.channels()) == 3)
check("key_by_id maps db ids to keys", key_by_id.get(1) == "dmr_bm:3127")
check("a non-intercom_dd channel loads with no leg (unchanged default)",
      core.channel("dmr_bm:3127").leg is None)

# Phase 152 -- intercom_dd is the one adapter that must get a ReflectorLeg
# attached automatically at load time (see legs/reflector.py's own
# docblock: without it, the dispatcher-intercom party line cannot carry
# audio between two dispatchers at all, since it has no other transport).
INTERCOM_CHANNELS = CHANNELS + [
    {"id": 4, "channel_key": "intercom_dd:main", "label": "Dispatcher Intercom",
     "regulatory_class": "internal", "adapter": "intercom_dd"},
]
core_ic = MatrixCore()
service.load_channels(FakeDB([("from comm_channels", INTERCOM_CHANNELS)]), core_ic)
check("intercom_dd:main gets a ReflectorLeg automatically, unconditionally (no config toggle)",
      core_ic.channel("intercom_dd:main").leg is not None
      and type(core_ic.channel("intercom_dd:main").leg).__name__ == "ReflectorLeg")
check("...while every OTHER adapter in the same load still gets leg=None",
      core_ic.channel("dmr_bm:3127").leg is None
      and core_ic.channel("disp:main").leg is None
      and core_ic.channel("sip:desk").leg is None)

n = service.load_routes(db, core, key_by_id)
# valid dmr->disp (1) + override dmr->sip (1) = 2; blocked + dangling skipped.
check("loaded 2 routes (blocked + dangling skipped)", n == 2)
routes = {(r.src, r.dst): r for r in core.routes()}
check("amateur->internal route present", ("dmr_bm:3127", "disp:main") in routes)
check("amateur->pstn present ONLY via override",
      ("dmr_bm:3127", "sip:desk") in routes
      and routes[("dmr_bm:3127", "sip:desk")].allow_cross_class is True)

# comm_routes table absent -> graceful "no routes"
db2 = FakeDB([("from comm_channels", CHANNELS),
              ("show tables like 'comm_routes'", [])])
core2 = MatrixCore()
kbi2 = service.load_channels(db2, core2)
check("no comm_routes table -> 0 routes, no crash",
      service.load_routes(db2, core2, kbi2) == 0)

# ── workstation mutes load (Phase 152 -- survives a service restart) ──
WORKSTATION_MUTE_ROWS = [
    {"src_token": "11111111-1111-4111-8111-111111111111",
     "dst_token": "22222222-2222-4222-8222-222222222222"},
]
db_wm = FakeDB([
    ("show tables like 'console_workstation_mutes'", [{"x": "console_workstation_mutes"}]),
    ("from console_workstation_mutes", WORKSTATION_MUTE_ROWS),
])
core_wm = MatrixCore()
n_wm = service.load_workstation_mutes(db_wm, core_wm)
check("load_workstation_mutes() loads 1 pair from the DB", n_wm == 1)
check("the pair is applied to the live core exactly as PHP's schema names it "
      "(src_token=workstation_id -- the SUPPRESSOR -- dst_token=muted_workstation_id "
      "-- the SUPPRESSED -- i.e. 11...'s speakers now suppress 22...'s own transmissions)",
      "22222222-2222-4222-8222-222222222222" in core_wm.workstation_mutes().get(
          "11111111-1111-4111-8111-111111111111", []))

# console_workstation_mutes table absent -> graceful "no mutes", no crash
db_wm2 = FakeDB([("show tables like 'console_workstation_mutes'", [])])
core_wm2 = MatrixCore()
check("no console_workstation_mutes table -> 0 pairs, no crash",
      service.load_workstation_mutes(db_wm2, core_wm2) == 0)


# ── DMR leg gating (safety) ───────────────────────────────────────────
core3 = MatrixCore()
service.load_channels(FakeDB([("from comm_channels", CHANNELS)]), core3)

leg_off = service.attach_dmr_leg(core3, {"dmr": {"mode": "off",
                                                 "channel_key": "dmr_bm:3127"}})
check("mode=off -> no DMR leg attached (bridge untouched)", leg_off is None)
check("mode=off -> channel has no leg", core3.channel("dmr_bm:3127").leg is None)

leg_missing = service.attach_dmr_leg(core3, {"dmr": {"mode": "live",
                                                     "channel_key": "nope:x"}})
check("mode=live but channel missing -> no leg", leg_missing is None)

leg_default = service.attach_dmr_leg(core3, {})   # no dmr block at all
check("no dmr config -> no leg", leg_default is None)


# ── browser leg gating (Phase 152 prerequisite #3 safety) ─────────────
# mode="off"/no-config must return None WITHOUT ever touching db_connect()
# (no DB, no mysql-connector, no network) -- the same shape as the DMR
# leg's own off/default cases above. mode="live" is exercised for real in
# test_browser_leg.py's live asyncio integration test, which injects its
# own validate_session rather than going through service.py's DB wiring.
browser_off = service.attach_browser_leg(core3, {"browser": {"mode": "off", "port": 18093}})
check("browser mode=off -> no server attached", browser_off is None)

browser_default = service.attach_browser_leg(core3, {})  # no browser block at all
check("no browser config -> no server", browser_default is None)


# ── http_stream: DB-driven boot attach + live hot-attach (2026-09-08) ──
# Redesigned from a static config-file list (Eric: "full channel type... I
# want to create multiple streams as needed") to fully DB-driven: a
# comm_channels row's own `enabled` flag IS the off/on switch (no separate
# config-file toggle), and the URL lives in config_json. Real ffmpeg/thread
# spawning is stubbed out with a fake leg class so this stays a fast, no-
# network unit test — see FakeHttpStreamLeg below.
class FakeHttpStreamLeg:
    """Stand-in for HttpStreamLeg: records what it was constructed with and
    whether start_rx()/stop() were called, without touching a real
    subprocess, thread, or network."""
    instances = []

    def __init__(self, core, channel_id, url, ffmpeg_path="ffmpeg"):
        self.core = core
        self.channel_id = channel_id
        self.url = url
        self.ffmpeg_path = ffmpeg_path
        self.started = False
        self.stopped = False
        FakeHttpStreamLeg.instances.append(self)

    def start_rx(self):
        self.started = True

    def stop(self):
        self.stopped = True

    def outbound(self, frame):
        pass


_orig_http_stream_leg_cls = service.HttpStreamLeg
service.HttpStreamLeg = FakeHttpStreamLeg
try:
    HTTP_STREAM_CHANNELS = CHANNELS + [
        {"id": 5, "channel_key": "test_stream", "label": "NOAA Weather Radio",
         "regulatory_class": "internal", "adapter": "http_stream",
         "config_json": '{"url": "http://www.urberg.net:8000/tim273/edina"}'},
        {"id": 6, "channel_key": "test_stream_broken", "label": "Malformed config",
         "regulatory_class": "internal", "adapter": "http_stream",
         "config_json": 'not valid json'},
        {"id": 7, "channel_key": "test_stream_no_url", "label": "No url key",
         "regulatory_class": "internal", "adapter": "http_stream",
         "config_json": '{"note": "someone forgot the url"}'},
    ]
    core_hs = MatrixCore()
    hs_registry = {}
    service.load_channels(FakeDB([("from comm_channels", HTTP_STREAM_CHANNELS)]),
                           core_hs, cfg={}, http_stream_registry=hs_registry)

    check("a channel row with a valid config_json url gets a leg attached",
          core_hs.channel("test_stream").leg is not None)
    check("...and start_rx() was actually called (not just constructed)",
          core_hs.channel("test_stream").leg.started is True)
    check("...the leg received the exact url from config_json",
          core_hs.channel("test_stream").leg.url == "http://www.urberg.net:8000/tim273/edina")
    check("...and it's registered in the shared http_stream_registry (for shutdown)",
          hs_registry.get("test_stream") is core_hs.channel("test_stream").leg)

    check("malformed config_json -> no leg, no crash",
          core_hs.channel("test_stream_broken").leg is None)
    check("config_json with no url key -> no leg, no crash",
          core_hs.channel("test_stream_no_url").leg is None)
    check("channels with no usable url are NOT in the registry",
          "test_stream_broken" not in hs_registry and "test_stream_no_url" not in hs_registry)

    # ── live hot-attach via the control-plane factory ──────────────────
    create_fn, remove_fn = service.make_http_stream_leg_factory(core_hs, {}, hs_registry)

    new_leg = create_fn("hot_stream", "Hot-Added Stream", "internal",
                         "http://example.invalid/live-added.mp3")
    check("create() adds a brand-new channel that didn't exist before",
          core_hs.channel("hot_stream") is not None)
    check("create() attaches a leg and starts it", new_leg.started is True)
    check("create() registers the new leg in the shared registry",
          hs_registry.get("hot_stream") is new_leg)

    # Re-creating on the SAME channel_key (an admin editing the URL) must
    # stop the OLD leg's ffmpeg/thread, never leak it.
    replacement_leg = create_fn("hot_stream", "Hot-Added Stream", "internal",
                                 "http://example.invalid/different-url.mp3")
    check("re-creating on the same channel stops the OLD leg first (no leaked subprocess)",
          new_leg.stopped is True)
    check("...and the registry now points at the NEW leg",
          hs_registry.get("hot_stream") is replacement_leg)

    removed_ok = remove_fn("hot_stream")
    check("remove() stops the leg", replacement_leg.stopped is True)
    check("remove() removes the channel from the live matrix",
          core_hs.channel("hot_stream") is None)
    check("remove() returns True for a channel that existed", removed_ok is True)
    check("remove() cleared the channel out of the registry",
          "hot_stream" not in hs_registry)

    removed_again = remove_fn("hot_stream")
    check("remove() on an already-removed channel returns False, doesn't raise",
          removed_again is False)
finally:
    service.HttpStreamLeg = _orig_http_stream_leg_cls

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
