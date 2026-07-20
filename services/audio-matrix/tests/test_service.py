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

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
