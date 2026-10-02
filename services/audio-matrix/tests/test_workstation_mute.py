"""
Adjacent-transmit mute — the per-destination audio filter (Phase 152,
plan.md section 3.5). Proves MatrixCore.tick()'s workstation-mute
exclusion for the case it CAN actually reach: a direct route between two
browser-leg channels.

Read this file's section 4 before assuming this feature covers every
channel type -- it does NOT, and that limitation is proven here
deliberately, not glossed over. See the bottom of this file and
specs/phase-152-comms-console-v2/tasks.md for the full reasoning: a real
DMR/Zello talkgroup round-trip (dispatcher A's talk route into the
channel, versus that SAME channel's own native RX feeding dispatcher B's
listen route) are two completely independent signals in this matrix's
single-hop model -- A's audio never appears as a traceable Route source
by the time it could theoretically echo back as "real incoming RF", so
there is no origin identity left for this filter to act on. Likewise the
intercom_dd hub's ReflectorLeg re-injects an already-BLENDED mix with no
per-source identity preserved, so today's mute filter is a safe no-op
there too -- proven, not merely asserted, in section 4.

Run:
    python3 services/audio-matrix/tests/test_workstation_mute.py
"""

import os
import sys
from array import array

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import frame as F                                    # noqa: E402
from matrix_core import Channel, MatrixCore, Route    # noqa: E402
from legs.loopback import LoopbackLeg                 # noqa: E402
from legs.reflector import ReflectorLeg               # noqa: E402

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


# ── 1. Baseline: with NO mute configured, a direct route carries audio normally ──
print("1. Baseline -- no mute pairing configured, audio flows normally")
core1 = MatrixCore()
core1.add_channel(Channel(id="browser:a1", name="A", workstation_token="WS-A"))
core1.add_channel(Channel(id="browser:b1", name="B", workstation_token="WS-B"))
legA1 = LoopbackLeg(core1, "browser:a1")
legB1 = LoopbackLeg(core1, "browser:b1")
core1.channel("browser:a1").leg = legA1
core1.channel("browser:b1").leg = legB1
core1.add_route(Route(src="browser:b1", dst="browser:a1"))  # B's talk reaches A's listen
legB1.feed(tone(9000))
core1.tick()
check("with no mute configured, A hears B normally", first_sample(legA1.last()) == 9000)

# ── 2. The fix: A mutes B -- A no longer hears B on this same route ──
print("\n2. A mutes B's own transmissions -- A stops hearing B, on the SAME route")
core1.set_workstation_mute("WS-A", "WS-B", True)
legA1.reset()
legB1.feed(tone(9000))
core1.tick()
check("after A mutes B, A receives SILENCE from that route (not B's audio)",
      legA1.last() is None or F.is_silence(legA1.last()))

print("\n   ...and un-muting restores it")
core1.set_workstation_mute("WS-A", "WS-B", False)
legA1.reset()
legB1.feed(tone(9000))
core1.tick()
check("after A un-mutes B, audio flows again", first_sample(legA1.last()) == 9000)

# ── 3. THE regression risk plan.md names explicitly: a third workstation
#       C must NOT be affected by A's mute of B ──
print("\n3. A third workstation (C) hearing B normally is UNAFFECTED by A's mute of B")
core2 = MatrixCore()
core2.add_channel(Channel(id="browser:a2", name="A", workstation_token="WS-A"))
core2.add_channel(Channel(id="browser:b2", name="B", workstation_token="WS-B"))
core2.add_channel(Channel(id="browser:c2", name="C", workstation_token="WS-C"))
legA2 = LoopbackLeg(core2, "browser:a2")
legB2 = LoopbackLeg(core2, "browser:b2")
legC2 = LoopbackLeg(core2, "browser:c2")
core2.channel("browser:a2").leg = legA2
core2.channel("browser:b2").leg = legB2
core2.channel("browser:c2").leg = legC2
core2.add_route(Route(src="browser:b2", dst="browser:a2"))
core2.add_route(Route(src="browser:b2", dst="browser:c2"))
core2.set_workstation_mute("WS-A", "WS-B", True)   # A mutes B ONLY

legB2.feed(tone(15000))
core2.tick()
check("A (who muted B) hears silence", legA2.last() is None or F.is_silence(legA2.last()))
check("C (who did NOT mute B) hears B PERFECTLY NORMALLY -- this is the one regression "
      "risk this feature is most likely to accidentally break (a channel-wide mute instead "
      "of a per-destination one)", first_sample(legC2.last()) == 15000)

# ── 3b. Ducking isolation: a muted source must not be able to duck an
#        unrelated, unmuted source on the SAME destination ──
print("\n3b. A muted contribution cannot duck a DIFFERENT, unmuted source on the same destination")
core3 = MatrixCore()
core3.add_channel(Channel(id="browser:a3", name="A", workstation_token="WS-A"))
core3.add_channel(Channel(id="browser:b3", name="B", workstation_token="WS-B"))
core3.add_channel(Channel(id="browser:d3", name="D", workstation_token="WS-D"))
legA3 = LoopbackLeg(core3, "browser:a3")
legB3 = LoopbackLeg(core3, "browser:b3")
legD3 = LoopbackLeg(core3, "browser:d3")
core3.channel("browser:a3").leg = legA3
core3.channel("browser:b3").leg = legB3
core3.channel("browser:d3").leg = legD3
# B is HIGHER priority than D -- if B's mute-for-A exclusion didn't also
# exclude it from the ducking computation, D would be wrongly ducked at A
# even though A never actually hears B at all.
core3.add_route(Route(src="browser:b3", dst="browser:a3", priority=10, gain_db=0.0))
core3.add_route(Route(src="browser:d3", dst="browser:a3", priority=0, gain_db=0.0))
core3.set_workstation_mute("WS-A", "WS-B", True)

legB3.feed(tone(20000))
legD3.feed(tone(5000))
core3.tick()
check("D's contribution at A is NOT ducked by B, even though B outranks D in priority -- "
      "because B is excluded from A's mix ENTIRELY, not just silenced in place",
      first_sample(legA3.last()) == 5000)

# ── 4. HONEST, PROVEN limitation: the intercom_dd hub path is a no-op today ──
print("\n4. Disclosed limitation: hub-relayed audio (intercom_dd) is NOT filtered by this "
      "mechanism yet -- proven here, not silently assumed")
core4 = MatrixCore()
core4.add_channel(Channel(id="browser:a4", name="A", workstation_token="WS-A"))
core4.add_channel(Channel(id="intercom_dd:main", name="Intercom"))  # no workstation_token -- it's a hub, not a browser leg
core4.add_channel(Channel(id="browser:b4", name="B", workstation_token="WS-B"))
legA4 = LoopbackLeg(core4, "browser:a4")
legB4 = LoopbackLeg(core4, "browser:b4")
core4.channel("browser:a4").leg = legA4
core4.channel("browser:b4").leg = legB4
core4.channel("intercom_dd:main").leg = ReflectorLeg(core4, "intercom_dd:main")
core4.add_route(Route(src="browser:b4", dst="intercom_dd:main"))
core4.add_route(Route(src="intercom_dd:main", dst="browser:a4"))
core4.set_workstation_mute("WS-A", "WS-B", True)  # A tries to mute B

for _ in range(2):
    legB4.feed(tone(11000))
    core4.tick()
check("A STILL hears B through the intercom hub despite the mute pairing -- the filter has "
      "no traceable origin once audio passes through the reflector's single shared mix "
      "(see this file's own module docblock + tasks.md for the full explanation and why "
      "this is disclosed rather than silently broken)",
      first_sample(legA4.last()) == 11000)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(1 if _fail else 0)
