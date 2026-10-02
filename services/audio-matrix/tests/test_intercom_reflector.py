"""
ReflectorLeg — proves the dispatcher-intercom hub actually carries audio
between two (and three) independent browser legs (Phase 152, found while
wiring intercom_dd onto the matrix).

Without this leg, a hub channel with leg=None (every comm_channels row
loads that way by default — see service.py's load_channels()) silently
discards any mix computed for it (Channel.deliver() no-ops with leg=None)
and, as a SOURCE, is permanently silent (nothing ever populates its own
inbound jitter buffer). Section 1 below reproduces that bug directly
against a bare Channel with leg=None, proving the fix is fixing something
real, not merely asserting the new code's own logic looks right in
isolation. Section 2 proves the fix: a route INTO the hub, reflected, then
a route OUT of the hub, carries real audio one tick later. Section 3
proves the party-line framing this feature was actually built for --
THREE independent legs, one talker, two listeners, both receive it, and a
mute pairing on the (separately-tested) workstation layer has NOTHING to
do with this mechanism (this file never touches workstation mutes at all).

Run:
    python3 services/audio-matrix/tests/test_intercom_reflector.py
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


# ── 1. The bug this fix closes: a hub channel with leg=None is a dead end ──
print("1. Reproducing the bug -- a hub with NO reflector leg cannot carry audio")
core0 = MatrixCore()
core0.add_channel(Channel(id="browser:a0", name="A"))
core0.add_channel(Channel(id="intercom_dd:main", name="Intercom"))  # leg=None, as service.py loads it by default
core0.add_channel(Channel(id="browser:b0", name="B"))
legA0 = LoopbackLeg(core0, "browser:a0")
legB0 = LoopbackLeg(core0, "browser:b0")
core0.channel("browser:a0").leg = legA0
core0.channel("browser:b0").leg = legB0
core0.add_route(Route(src="browser:a0", dst="intercom_dd:main"))
core0.add_route(Route(src="intercom_dd:main", dst="browser:b0"))
legA0.feed(tone(5000))
for _ in range(3):
    core0.tick()
check("WITHOUT a reflector leg, B never receives A's audio (the real, shipped-but-broken state)",
      legB0.last() is None or F.is_silence(legB0.last()))

# ── 2. The fix: a ReflectorLeg makes the hub carry audio, one tick later ──
print("\n2. ReflectorLeg: audio routed IN becomes audio routed OUT, one tick later")
core1 = MatrixCore()
core1.add_channel(Channel(id="browser:a1", name="A"))
core1.add_channel(Channel(id="intercom_dd:main", name="Intercom"))
core1.add_channel(Channel(id="browser:b1", name="B"))
legA1 = LoopbackLeg(core1, "browser:a1")
legB1 = LoopbackLeg(core1, "browser:b1")
core1.channel("browser:a1").leg = legA1
core1.channel("browser:b1").leg = legB1
core1.channel("intercom_dd:main").leg = ReflectorLeg(core1, "intercom_dd:main")
core1.add_route(Route(src="browser:a1", dst="intercom_dd:main"))
core1.add_route(Route(src="intercom_dd:main", dst="browser:b1"))

legA1.feed(tone(8000))
core1.tick()  # tick 1: A's frame mixes into the hub; ReflectorLeg re-injects it
check("after tick 1, B has NOT received it yet (the reflection is exactly one tick latent, not instant)",
      legB1.last() is None or F.is_silence(legB1.last()))
legA1.feed(tone(8000))  # keep feeding -- LoopbackLeg's own _inq only holds what's fed since the last pop
core1.tick()  # tick 2: the hub's reflected frame from tick 1 is now its current input -> out to B
check("after tick 2, B DOES receive A's audio, carried through the intercom hub",
      legB1.last() is not None and first_sample(legB1.last()) == 8000)

# ── 3. Three-way party line: one talker, two listeners, both receive it ──
print("\n3. Three-way party line -- the actual feature this fixes (5-persona review's chosen model)")
core2 = MatrixCore()
core2.add_channel(Channel(id="browser:a2", name="A"))
core2.add_channel(Channel(id="intercom_dd:main", name="Intercom"))
core2.add_channel(Channel(id="browser:b2", name="B"))
core2.add_channel(Channel(id="browser:c2", name="C"))
legA2 = LoopbackLeg(core2, "browser:a2")
legB2 = LoopbackLeg(core2, "browser:b2")
legC2 = LoopbackLeg(core2, "browser:c2")
core2.channel("browser:a2").leg = legA2
core2.channel("browser:b2").leg = legB2
core2.channel("browser:c2").leg = legC2
core2.channel("intercom_dd:main").leg = ReflectorLeg(core2, "intercom_dd:main")
core2.add_route(Route(src="browser:a2", dst="intercom_dd:main"))
core2.add_route(Route(src="intercom_dd:main", dst="browser:b2"))
core2.add_route(Route(src="intercom_dd:main", dst="browser:c2"))

for _ in range(2):
    legA2.feed(tone(12000))
    core2.tick()
check("B receives A's voice through the shared hub", first_sample(legB2.last()) == 12000)
check("C receives the SAME audio simultaneously -- a real party line, not a 1:1 call",
      first_sample(legC2.last()) == 12000)

# ── 4. No amplification: the reflector cannot route into itself ──
print("\n4. Self-route protection still holds for a reflected channel (unchanged, re-confirmed here)")
threw = False
try:
    core2.add_route(Route(src="intercom_dd:main", dst="intercom_dd:main"))
except Exception:
    threw = True
check("a self-route on the reflected hub channel is still rejected (no runaway feedback path exists)", threw)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(1 if _fail else 0)
