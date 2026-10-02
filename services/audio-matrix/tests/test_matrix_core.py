"""
Fixture harness for the audio matrix core (Phase 114c).

Runs with NO radios and NO third-party services — pure loopback legs. Proves
the frame math, single-hop routing, gain, priority ducking, the regulatory
guard, and route-table validation. Run:

    python3 services/audio-matrix/tests/test_matrix_core.py
"""

import os
import sys
import time
from array import array

# Import the sibling modules (services/audio-matrix on the path).
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import frame as F                                    # noqa: E402
from matrix_core import (                            # noqa: E402
    Channel, MatrixCore, RegClass, Route, RouteError,
)
from legs.loopback import LoopbackLeg                # noqa: E402

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
    """A frame of constant int16 amplitude (deterministic mix/gain math)."""
    return array("h", [value] * n).tobytes()


def first_sample(frame):
    a = array("h")
    a.frombytes(frame[:F.BYTES_PER_FRAME])
    return a[0] if len(a) else 0


# ── 1. frame math ────────────────────────────────────────────────────
print("1. Frame primitives")
check("SILENCE is 320 bytes", len(F.SILENCE) == 320)
check("is_silence(SILENCE)", F.is_silence(F.SILENCE))
check("is_silence(tone) is False", not F.is_silence(tone(1000)))
check("apply_gain unity is identity", F.apply_gain(tone(1000), 1.0) == tone(1000))
check("apply_gain 0.5 halves", first_sample(F.apply_gain(tone(1000), 0.5)) == 500)
check("apply_gain saturates (no wrap)",
      first_sample(F.apply_gain(tone(30000), 2.0)) == 32767)
check("mix of two tones sums", first_sample(F.mix([tone(1000), tone(2000)])) == 3000)
check("mix saturates", first_sample(F.mix([tone(20000), tone(20000)])) == 32767)
check("mix of silence is SILENCE", F.mix([F.SILENCE, F.SILENCE]) == F.SILENCE)
check("db_to_factor(0) == 1.0", F.db_to_factor(0.0) == 1.0)
check("db_to_factor(-6) ~ 0.5", abs(F.db_to_factor(-6.0) - 0.501) < 0.01)

# ── 2. single-hop routing + gain ─────────────────────────────────────
print("\n2. Routing + gain")
core = MatrixCore()
a = core.add_channel(Channel("a", "Chan A"))
b = core.add_channel(Channel("b", "Chan B"))
la = LoopbackLeg(core, "a"); a.leg = la
lb = LoopbackLeg(core, "b"); b.leg = lb
core.add_route(Route("a", "b"))          # A -> B at unity

la.feed(tone(1000)); core.tick()
check("A audio reaches B at unity", first_sample(lb.last()) == 1000)
check("B audio does NOT reach A (no reverse route)", F.is_silence(la.last()))

lb.reset()
# gain route on a fresh dest
c = core.add_channel(Channel("c", "Chan C")); lc = LoopbackLeg(core, "c"); c.leg = lc
core.add_route(Route("a", "c", gain_db=-6.0))
la.feed(tone(1000)); core.tick()
check("A->C at -6 dB ~ half amplitude", 480 <= first_sample(lc.last()) <= 520)

# source stops feeding -> dest silent next tick
core.tick()
check("dest goes silent when source stops feeding", F.is_silence(lb.last()))

# ── 3. full-duplex patch ─────────────────────────────────────────────
print("\n3. Full-duplex patch (A<->B)")
core2 = MatrixCore()
x = core2.add_channel(Channel("x", "X")); lx = LoopbackLeg(core2, "x"); x.leg = lx
y = core2.add_channel(Channel("y", "Y")); ly = LoopbackLeg(core2, "y"); y.leg = ly
core2.add_route(Route("x", "y"))
core2.add_route(Route("y", "x"))
lx.feed(tone(1500)); ly.feed(tone(2500)); core2.tick()
check("X->Y carries X audio", first_sample(ly.last()) == 1500)
check("Y->X carries Y audio", first_sample(lx.last()) == 2500)

# ── 4. priority ducking ──────────────────────────────────────────────
print("\n4. Priority ducking")
core3 = MatrixCore()
hot = core3.add_channel(Channel("hot", "Select"))
mon = core3.add_channel(Channel("mon", "Monitor"))
out = core3.add_channel(Channel("out", "Speaker"))
lo = LoopbackLeg(core3, "out"); out.leg = lo
lh = LoopbackLeg(core3, "hot"); hot.leg = lh
lm = LoopbackLeg(core3, "mon"); mon.leg = lm
core3.add_route(Route("hot", "out", priority=10, ducking=False))
core3.add_route(Route("mon", "out", priority=1,  ducking=True))
# only monitor active -> full volume
lm.feed(tone(1000)); core3.tick()
check("monitor alone plays full volume", first_sample(lo.last()) == 1000)
# both active -> monitor ducked ~ -12 dB under the hot select
lh.feed(tone(1000)); lm.feed(tone(1000)); core3.tick()
mixed = first_sample(lo.last())
# hot(1000) + monitor(1000 * ~0.25) ~= 1250
check("monitor ducked under hot select", 1200 <= mixed <= 1300)

# ── 5. regulatory guard ──────────────────────────────────────────────
print("\n5. Regulatory guard")
core4 = MatrixCore()
core4.add_channel(Channel("ham", "TG 3127", RegClass.AMATEUR))
core4.add_channel(Channel("phone", "SIP", RegClass.PSTN))
core4.add_channel(Channel("disp", "Dispatch", RegClass.INTERNAL))
blocked = False
try:
    core4.add_route(Route("ham", "phone"))
except RouteError:
    blocked = True
check("amateur->pstn blocked without override", blocked)
ok = True
try:
    core4.add_route(Route("ham", "phone", allow_cross_class=True))
except RouteError:
    ok = False
check("amateur->pstn allowed WITH audited override", ok)
internal_ok = True
try:
    core4.add_route(Route("ham", "disp"))    # amateur->internal always fine
except RouteError:
    internal_ok = False
check("amateur->internal (monitoring) always allowed", internal_ok)

# ── 6. route-table validation ────────────────────────────────────────
print("\n6. Route validation")
core5 = MatrixCore()
core5.add_channel(Channel("p", "P"))
core5.add_channel(Channel("q", "Q"))
self_rejected = False
try:
    core5.add_route(Route("p", "p"))
except RouteError:
    self_rejected = True
check("self-route rejected", self_rejected)
core5.add_route(Route("p", "q"))
dup_rejected = False
try:
    core5.add_route(Route("p", "q"))
except RouteError:
    dup_rejected = True
check("duplicate route rejected", dup_rejected)
unknown_rejected = False
try:
    core5.add_route(Route("p", "nope"))
except RouteError:
    unknown_rejected = True
check("route to unknown channel rejected", unknown_rejected)
# disabled route carries nothing
core5.add_route(Route("q", "p", enabled=False))
lp = LoopbackLeg(core5, "p"); core5.channel("p").leg = lp
lq = LoopbackLeg(core5, "q"); core5.channel("q").leg = lq
lq.feed(tone(1000)); core5.tick()
check("disabled route carries no audio", F.is_silence(lp.last()))

# ── 7. audit hook fires ──────────────────────────────────────────────
print("\n7. Audit hook")
events = []
core6 = MatrixCore(on_audit=lambda ev, d: events.append((ev, d)))
core6.add_channel(Channel("m", "M", RegClass.AMATEUR))
core6.add_channel(Channel("n", "N", RegClass.COMMERCIAL))
core6.add_route(Route("m", "n", allow_cross_class=True))
check("route_add audited", any(e[0] == "matrix.route_add" for e in events))
check("cross_class override audited",
      any(e[0] == "matrix.cross_class_route" for e in events))

# ── 8. remove_channel() (Phase 152 — dynamic browser-leg sessions) ────
print("\n8. remove_channel()")
core7 = MatrixCore()
core7.add_channel(Channel("bws1", "Browser Session 1"))
core7.add_channel(Channel("bws2", "Browser Session 2"))
core7.add_route(Route("bws1", "bws2"))
core7.add_route(Route("bws2", "bws1"))
check("channel exists before removal", core7.channel("bws1") is not None)
removed = core7.remove_channel("bws1")
check("remove_channel() returns True for a real channel", removed is True)
check("channel is gone after removal", core7.channel("bws1") is None)
check("routes referencing the removed channel (both directions) are gone",
      all("bws1" not in (r.src, r.dst) for r in core7.routes()))
check("the OTHER channel is untouched", core7.channel("bws2") is not None)
check("removing an unknown channel returns False, does not raise",
      core7.remove_channel("does-not-exist") is False)

# A route between two SURVIVING channels must not be collaterally removed
# by an unrelated channel's departure.
core8 = MatrixCore()
core8.add_channel(Channel("x", "X"))
core8.add_channel(Channel("y", "Y"))
core8.add_channel(Channel("z", "Z"))
core8.add_route(Route("x", "y"))
core8.remove_channel("z")
check("removing an unrelated channel leaves an existing route intact",
      any(r.src == "x" and r.dst == "y" for r in core8.routes()))

# ── 9. Route.expires_at -- read-time enforcement (Phase 152 prereq #6) ─
print("\n9. Route.expires_at read-time expiry")
core9 = MatrixCore()
core9.add_channel(Channel("ex_src", "Expiry Source"))
core9.add_channel(Channel("ex_dst", "Expiry Dest"))
lsrc9 = LoopbackLeg(core9, "ex_src"); core9.channel("ex_src").leg = lsrc9
ldst9 = LoopbackLeg(core9, "ex_dst"); core9.channel("ex_dst").leg = ldst9

check("a route with expires_at=None never expires",
      not Route("ex_src", "ex_dst").is_expired())
check("is_expired() accepts an explicit `now` for deterministic testing",
      Route("ex_src", "ex_dst", expires_at=1000.0).is_expired(now=1001.0) is True
      and Route("ex_src", "ex_dst", expires_at=1000.0).is_expired(now=999.0) is False)

# A route expiring 60s in the FUTURE still carries audio right now.
core9.add_route(Route("ex_src", "ex_dst", expires_at=time.time() + 60.0))
lsrc9.feed(tone(1234))
core9.tick()
check("a route that has not yet expired still relays audio",
      first_sample(ldst9.last()) == 1234)

# The SAME route, but already in the past -- tick() must exclude it,
# without anyone ever calling remove_route() or disabling it.
core9.remove_route("ex_src", "ex_dst")
core9.add_route(Route("ex_src", "ex_dst", expires_at=time.time() - 1.0))
ldst9.reset()
lsrc9.feed(tone(1234))
core9.tick()
check("an EXPIRED route delivers SILENCE -- read-time refusal, no sweep involved",
      F.is_silence(ldst9.last()))
check("the expired Route object is still present (not auto-removed) -- "
      "GET /routes must be able to show 'expired', not make it vanish",
      any(r.src == "ex_src" and r.dst == "ex_dst" for r in core9.routes()))
expired_route = next(r for r in core9.routes() if r.src == "ex_src" and r.dst == "ex_dst")
check("the Route's own is_expired() (default now=real time) agrees", expired_route.is_expired() is True)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
