"""
FccRelayGate fixture harness (Phase 152 prerequisite #2's Python half).

The interval-math and callsign/enforce/regulatory-class DECISION LOGIC
(amateur=600s ceiling, commercial=900s ceiling, pstn/internal short-
circuit to not-applicable, hard-lapsed refusal, soft-lapsed allow) lives
in PHP (inc/id-policy.php, inc/comm_fcc_gate.php) and is already proven
directly by tests/test_phase152_id_policy.php's 19 assertions against the
real functions and a real database. This file does NOT re-derive that
math in Python or fake a database -- it drives the real FccRelayGate
class with a CANNED check_fn returning the exact shapes
api/matrix-id-check.php's `check` action would return for each of those
PHP-side scenarios, and proves the PYTHON side's OWN responsibilities:
caching per utterance (not per 20ms tick), resetting on silence so a new
utterance is never judged by a stale decision, failing closed on a check
exception, and — wired through the real MatrixCore — that a channel with
no gate configured (every direct-human-PTT leg, forever) is completely
unaffected.

Run:
    python3 services/audio-matrix/tests/test_fcc_gate.py
"""

import os
import sys
from array import array

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import frame as F                                          # noqa: E402
from matrix_core import Channel, MatrixCore, Route          # noqa: E402
from legs.loopback import LoopbackLeg                       # noqa: E402
from fcc_gate import FccRelayGate                            # noqa: E402

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


def tone(value, n=F.SAMPLES_PER_FRAME):
    return array("h", [value] * n).tobytes()


def first_sample(frame):
    a = array("h")
    a.frombytes(frame[:F.BYTES_PER_FRAME])
    return a[0] if len(a) else 0


class CannedCheck:
    """A check_fn stand-in returning a fixed {allowed,reason,zone} dict
    every call, and counting how many times it was actually invoked --
    the whole point of this test is proving the gate does NOT call this
    on every tick."""
    def __init__(self, result, raise_instead=False):
        self.result = result
        self.raise_instead = raise_instead
        self.calls = 0

    def __call__(self):
        self.calls += 1
        if self.raise_instead:
            raise RuntimeError("simulated network/PHP failure")
        return dict(self.result)


# ── 1. mirrors comm_fcc_may_transmit()'s reason shapes, Python side ───
print("1. Gate decisions per canned PHP reason")

pstn_check = CannedCheck({"allowed": True, "reason": "not_applicable", "zone": "none"})
g = FccRelayGate("chan-pstn", pstn_check)
check("pstn/internal not_applicable -> allowed", g.tick(True) is True)
check("not_applicable checked exactly once for one continuous utterance",
      pstn_check.calls == 1)

no_cs_check = CannedCheck({"allowed": False, "reason": "no_callsign", "zone": "none"})
g2 = FccRelayGate("chan-nocs", no_cs_check)
check("no_callsign -> refused", g2.tick(True) is False)
check("refusals counter incremented", g2.refusals == 1)

hard_lapsed_check = CannedCheck({"allowed": False, "reason": "lapsed_hard", "zone": "red"})
g3 = FccRelayGate("chan-hard", hard_lapsed_check)
check("hard-enforced lapsed interval -> refused (no human to confirm, unlike the client-side gate)",
      g3.tick(True) is False)

soft_lapsed_check = CannedCheck({"allowed": True, "reason": "lapsed_soft", "zone": "yellow"})
g4 = FccRelayGate("chan-soft", soft_lapsed_check)
check("soft-enforced lapsed interval -> ALLOWED (flagged via zone, not refused)",
      g4.tick(True) is True)
health4 = g4.health()
check("health() surfaces the flagged zone for a soft-lapsed allow",
      health4["zone"] == "yellow" and health4["reason"] == "lapsed_soft")

ok_check = CannedCheck({"allowed": True, "reason": "ok", "zone": "green"})
g5 = FccRelayGate("chan-ok", ok_check)
check("fresh ID, well inside interval -> allowed", g5.tick(True) is True)

# ── 2. caching: checked once per utterance, not once per tick ─────────
print("\n2. Per-utterance caching (not per-tick)")
cached_check = CannedCheck({"allowed": True, "reason": "ok", "zone": "green"})
g6 = FccRelayGate("chan-cache", cached_check, recheck_secs=60.0)
for _ in range(50):  # 50 ticks = 1 second of continuous live audio
    g6.tick(True)
check("50 ticks of one continuous utterance -> exactly ONE HTTP check",
      cached_check.calls == 1)

# ── 3. silence resets the cache -> next utterance is checked fresh ────
print("\n3. Silence resets the cache for the NEXT utterance")
reset_check = CannedCheck({"allowed": True, "reason": "ok", "zone": "green"})
g7 = FccRelayGate("chan-reset", reset_check, recheck_secs=60.0)
g7.tick(True)                      # utterance 1, frame 1 -> checked
g7.tick(True)                      # utterance 1, frame 2 -> cached
check("still one check mid-utterance", reset_check.calls == 1)
allowed_during_silence = g7.tick(False)   # source goes silent -> utterance ends
check("silence itself is always refused (nothing to relay)", allowed_during_silence is False)
g7.tick(True)                      # utterance 2 begins
check("a NEW utterance re-checks even though recheck_secs hasn't elapsed",
      reset_check.calls == 2)

# ── 4. fail-closed on a check_fn exception ─────────────────────────────
print("\n4. Fail-closed on a check failure")
broken_check = CannedCheck({}, raise_instead=True)
g8 = FccRelayGate("chan-broken", broken_check)
check("a check_fn exception REFUSES the relay (fail-closed, never fail-open)",
      g8.tick(True) is False)
check("the tick loop itself does not raise", True)  # got this far without an exception
check("health() reports the failure reason", g8.health()["reason"] == "check_failed")

# ── 5. wired through the REAL MatrixCore: gated vs. ungated channels ──
print("\n5. Real MatrixCore integration - gate applies ONLY to the configured destination")
core = MatrixCore()
src = core.add_channel(Channel("src", "Source"))
gated_dst = core.add_channel(Channel("gated", "Gated Amateur Relay"))
ungated_dst = core.add_channel(Channel("ungated", "Internal, No Gate"))

l_src = LoopbackLeg(core, "src"); src.leg = l_src
l_gated = LoopbackLeg(core, "gated"); gated_dst.leg = l_gated
l_ungated = LoopbackLeg(core, "ungated"); ungated_dst.leg = l_ungated

core.add_route(Route("src", "gated"))
core.add_route(Route("src", "ungated"))

refuse_check = CannedCheck({"allowed": False, "reason": "lapsed_hard", "zone": "red"})
gated_dst.fcc_gate = FccRelayGate("gated", refuse_check)
check("ungated_dst.fcc_gate defaults to None (no gate assigned)", ungated_dst.fcc_gate is None)

l_src.feed(tone(9000))
core.tick()

check("the GATED destination receives SILENCE (relay refused)",
      F.is_silence(l_gated.last()))
check("the UNGATED destination on the SAME source still gets the real audio "
      "(a gate on one channel never leaks onto another)",
      first_sample(l_ungated.last()) == 9000)

# A direct-human-PTT leg is simply never given an fcc_gate at all (there is
# no route delivering audio to it via MatrixCore -- radio-widget.js's PTT
# talks to the bridge directly) -- proven structurally: the gate type only
# ever appears attached to a Channel that is the DESTINATION of a Route,
# and this test's own "ungated_dst" case shows a channel with fcc_gate=None
# passes routed audio through completely untouched, exactly as every
# channel behaved before this gate existed.
check("a channel with no fcc_gate configured behaves identically to pre-gate MatrixCore",
      first_sample(l_ungated.last()) == 9000 and ungated_dst.fcc_gate is None)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
