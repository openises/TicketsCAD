"""
Fixture test for the generic USRP leg (Phase 155, GH#151 + GH#129, Release A:
listen-only). NO bridge program, NO radio, NO network beyond loopback.

The "bridge" here is tests/usrp_sim.py, a USRP packet generator/receiver.
That makes every claim below a claim about THIS LEG'S handling of the USRP
wire format and of the header quirks the spec records from reading
dvmbridge's source — not a claim that a real dvmbridge, dvmfne, Analog_Bridge
or chan_usrp behaves that way. See usrp_sim.py's docblock.

Asserts:
  * the source-address filter: only the configured bridge host is heard
  * header bytes other than magic/sequence are ignored (garbage tolerated)
  * a bare 32-byte header ends a receive; a ping does not; silence ends it
    too, because a bridge may never send a clean end
  * DTMF/text/TLV/runt/wrong-magic datagrams never reach the matrix as audio
  * rx_state started/ended notifications, delivered off the receive thread
  * the leg NEVER transmits: audio routed INTO it produces zero datagrams
  * exclusive bind (a second leg on the same port fails), and stop() frees it
  * the leg really carries PCM through MatrixCore.tick() to another channel
  * a digital-voice leg stays under the existing cross-class guard

    python services/audio-matrix/tests/test_usrp_leg.py
"""

import os
import random
import socket
import struct
import sys
import threading
import time

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, os.path.join(os.path.dirname(HERE), "legs"))
sys.path.insert(0, HERE)

from frame import BYTES_PER_FRAME  # noqa: E402
from matrix_core import Channel, MatrixCore, RegClass, Route, RouteError  # noqa: E402
from loopback import LoopbackLeg  # noqa: E402
import usrp as usrp_mod  # noqa: E402
from usrp import UsrpLeg, UsrpConfigError, parse_ipv4, parse_port, pack_header  # noqa: E402
from usrp_sim import UsrpSimulator, make_header, tone_frames  # noqa: E402

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


def free_port():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def wait_for(pred, timeout=3.0):
    end = time.monotonic() + timeout
    while time.monotonic() < end:
        if pred():
            return True
        time.sleep(0.01)
    return pred()


def pcm(val=1000):
    return struct.pack("<160h", *([val] * 160))


def queued(core, cid):
    return len(core.channel(cid)._inq)


def new_leg(core, cid="dvm:p25", **kw):
    core.add_channel(Channel(cid, "P25 TG 1", RegClass.AMATEUR))
    kw.setdefault("listen_port", free_port())
    leg = UsrpLeg(core, channel_id=cid, **kw)
    core.channel(cid).leg = leg
    return leg


# ══ 1. configuration validation ═════════════════════════════════════════
print("1. Configuration validation")
check("parse_ipv4 accepts a loopback address", parse_ipv4("127.0.0.1", "x") == "127.0.0.1")
check("parse_ipv4 accepts an RFC1918 address", parse_ipv4("10.0.0.10", "x") == "10.0.0.10")
for bad in ("localhost", "bridge.example.org", "::1", "0.0.0.0", "224.0.0.1", "", "999.1.1.1"):
    try:
        parse_ipv4(bad, "bridge_host")
        ok = False
    except UsrpConfigError:
        ok = True
    check("parse_ipv4 refuses %r (a hostname would make the source filter depend on DNS)" % bad, ok)
for bad in (0, 80, 1023, 65536, "abc", None):
    try:
        parse_port(bad, "p")
        ok = False
    except UsrpConfigError:
        ok = True
    check("parse_port refuses %r" % (bad,), ok)
check("parse_port accepts 34001", parse_port("34001", "p") == 34001)
try:
    UsrpLeg(MatrixCore(), "x", framing="raw_meta")
    ok = False
except UsrpConfigError:
    ok = True
check("an unsupported framing is refused at construction (raw_meta is a later release)", ok)

# ══ 2. datagram handling (deterministic: no socket, no sleeping) ═════════
print("\n2. Datagram handling")
core = MatrixCore()
events = []
leg = new_leg(core, bridge_host="127.0.0.1",
              notify_fn=lambda cid, label, ev, st: events.append((cid, ev, st)))
clock = {"t": 100.0}
leg._clock = lambda: clock["t"]
cid = "dvm:p25"
hdr = lambda seq, **kw: make_header(seq, "clean", **kw)  # noqa: E731

leg.handle_datagram(hdr(0) + pcm(500), "127.0.0.1")
check("a clean voice datagram delivers one frame to the matrix", queued(core, cid) == 1)
check("the first voice frame starts a receive (rx_calls == 1)", leg.rx_calls == 1 and leg._receiving)

# header bytes other than magic + sequence must not matter
rng = random.Random(7)
for i in range(1, 6):
    raw = bytearray(hdr(i))
    for off in list(range(8, 12)) + list(range(16, 32)):
        raw[off] = rng.getrandbits(8)
    raw[20:24] = struct.pack(">I", rng.choice([0, 1, 2, 4, 5, 6, 0xDEADBEEF]))
    leg.handle_datagram(bytes(raw) + pcm(600 + i), "127.0.0.1")
check("voice frames with GARBAGE in header bytes 8-11 and 16-31 (incl. the type word) are all accepted",
      queued(core, cid) == 6 and leg.rx_dropped_nonvoice == 0)
check("...and that run was one receive, not six", leg.rx_calls == 1)

# dvmbridge-style header from the simulator
leg.handle_datagram(make_header(6, "dvmbridge", rng=random.Random(3)) + pcm(700), "127.0.0.1")
check("a dvmbridge-style header (magic + seq + byte 15, rest random) is accepted", queued(core, cid) == 7)
check("sequence 0..6 in order produced no gap count", leg.rx_seq_gaps == 0)

# several 20 ms frames in one datagram
leg.handle_datagram(hdr(7) + pcm(1) + pcm(2) + pcm(3), "127.0.0.1")
check("three frames packed into one datagram each reach the matrix", queued(core, cid) == 10)
check("rx_frames counts frames, not datagrams", leg.rx_frames == 10)

# sequence gap is diagnostic only
leg.handle_datagram(hdr(50) + pcm(9), "127.0.0.1")
check("a sequence jump increments rx_seq_gaps...", leg.rx_seq_gaps == 1)
check("...but the audio is still delivered", queued(core, cid) == 11)

# EOT by length
leg.handle_datagram(hdr(51, keyup=0), "127.0.0.1")
check("a bare 32-byte header ends the receive", not leg._receiving and leg.rx_eot_by_length == 1)
queued_events = []
while not leg._notify_q.empty():
    queued_events.append(leg._notify_q.get_nowait())
check("...and exactly one 'started' then one 'ended' rx_state notification was queued for that call",
      queued_events == [("rx_state", "started"), ("rx_state", "ended")])

# ping must not end a receive
leg.handle_datagram(hdr(60) + pcm(11), "127.0.0.1")
check("a new voice datagram starts a second receive", leg._receiving and leg.rx_calls == 2)
leg.handle_datagram(hdr(61, frame_type=usrp_mod.USRP_TYPE_PING, keyup=0), "127.0.0.1")
check("a 32-byte PING header is ignored, it does not end the receive", leg._receiving)

# end by silence (injected clock; no sleeping)
clock["t"] += 0.1
leg._check_inactivity()
check("100 ms of silence does NOT end the receive yet (threshold is 400 ms)", leg._receiving)
clock["t"] += 0.5
leg._check_inactivity()
check("600 ms of silence ends the receive even though no EOT ever arrived",
      not leg._receiving and leg.rx_eot_by_timeout == 1)

# EOT when not receiving is harmless
leg.handle_datagram(hdr(70, keyup=0), "127.0.0.1")
check("a bare header while idle changes nothing", not leg._receiving and leg.rx_eot_by_length == 1)

# source filter
q0 = queued(core, cid)
leg.handle_datagram(hdr(80) + pcm(5), "10.9.8.7")
check("a datagram from any address other than bridge_host is dropped and counted",
      queued(core, cid) == q0 and leg.rx_dropped_source == 1)
check("...and does not start a receive", not leg._receiving)

# malformed / non-voice
leg.handle_datagram(b"NOPE" + b"\x00" * 348, "127.0.0.1")
leg.handle_datagram(b"USRP\x00\x00", "127.0.0.1")
check("wrong magic and runt datagrams are dropped as malformed",
      leg.rx_dropped_malformed == 2 and queued(core, cid) == q0)
leg.handle_datagram(hdr(90, frame_type=4) + b"\x01\x02\x03" * 10, "127.0.0.1")   # TLV
leg.handle_datagram(hdr(91, frame_type=1) + b"5", "127.0.0.1")                    # DTMF
leg.handle_datagram(hdr(92, frame_type=2) + b"hello", "127.0.0.1")                # text
leg.handle_datagram(hdr(93) + b"\x00" * 160, "127.0.0.1")                         # half a frame
check("TLV, DTMF, text and a half-frame payload are dropped as non-voice, never played as audio",
      leg.rx_dropped_nonvoice == 4 and queued(core, cid) == q0 and not leg._receiving)
leg.handle_datagram(hdr(94) + pcm(1) * (usrp_mod.MAX_FRAMES_PER_DATAGRAM + 1), "127.0.0.1")
check("an over-long datagram is dropped, not partly played", leg.rx_dropped_nonvoice == 5)

h = leg.health()
check("health() reports listen_only true and the bind address",
      h["listen_only"] is True and h["listen_port"] == leg.listen_port and h["bridge_host"] == "127.0.0.1")
check("health() carries the counters /legs exposes", h["rx_dropped_source"] == 1 and h["rx_calls"] == 2)

# ══ 3. the leg never transmits ═══════════════════════════════════════════
print("\n3. Listen-only: nothing is ever sent")
core3 = MatrixCore()
bridge_rx = UsrpSimulator("127.0.0.1", 0, bind_host="127.0.0.1")   # stands in for the bridge's receive port
leg3 = new_leg(core3, cid="dvm:p25", bridge_host="127.0.0.1", tx_port=bridge_rx.local_port)
src = Channel("loop:src", "Loop source", RegClass.INTERNAL)
core3.add_channel(src)
src_leg = LoopbackLeg(core3, "loop:src")
src.leg = src_leg
core3.add_route(Route(src="loop:src", dst="dvm:p25"))
leg3.start_rx()
try:
    for i in range(30):
        src_leg.feed(pcm(2000))
        core3.tick()
    got = bridge_rx.receive(timeout=0.4)
    check("30 ticks of real audio routed INTO the digital-voice channel produce ZERO UDP datagrams to the bridge",
          got is None)
    check("...the audio was counted as blocked (visible in health), not silently lost",
          leg3.tx_frames_blocked == 30 and leg3.health()["tx_frames_blocked"] == 30)
    src_leg.feed(b"\x00" * BYTES_PER_FRAME)
    core3.tick()
    check("silence delivered into the leg is not counted as blocked transmit", leg3.tx_frames_blocked == 30)
finally:
    leg3.stop()
    bridge_rx.close()

# ══ 4. real loopback sockets ═════════════════════════════════════════════
print("\n4. Real loopback UDP")
core4 = MatrixCore()
ev4 = []
leg4 = new_leg(core4, cid="dvm:p25", bridge_host="127.0.0.1", rx_inactivity_ms=150,
               notify_fn=lambda c, l, e, s: ev4.append((c, e, s)))
leg4.start_rx()
sim = UsrpSimulator("127.0.0.1", leg4.listen_port, bind_host="127.0.0.1")
try:
    n = sim.send_voice(list(tone_frames(1000, 0.2)), style="dvmbridge", frames_per_datagram=1)
    check("10 voice datagrams sent through a real socket", n == 10)
    check("all 10 frames arrive in the matrix's jitter buffer",
          wait_for(lambda: queued(core4, "dvm:p25") == 10))
    check("rx_state 'started' was delivered to the notifier (off the receive thread)",
          wait_for(lambda: ("dvm:p25", "rx_state", "started") in ev4))
    sim.send_eot("dvmbridge")
    check("a real EOT datagram ends the receive and delivers rx_state 'ended'",
          wait_for(lambda: ("dvm:p25", "rx_state", "ended") in ev4) and leg4.rx_eot_by_length == 1)

    # no EOT at all: silence ends it
    ev4.clear()
    sim.send_voice(list(tone_frames(800, 0.04)), style="clean")
    check("a second call starts", wait_for(lambda: ("dvm:p25", "rx_state", "started") in ev4))
    check("with no EOT, 150 ms of silence ends the call by timeout",
          wait_for(lambda: ("dvm:p25", "rx_state", "ended") in ev4, timeout=2.0) and leg4.rx_eot_by_timeout >= 1)

    # wrong source over a real socket: 127.0.0.2 is loopback on Linux and Windows
    try:
        bad = UsrpSimulator("127.0.0.1", leg4.listen_port, bind_host="127.0.0.2")
    except OSError:
        bad = None
    if bad is not None:
        before = queued(core4, "dvm:p25")
        bad.send_voice([pcm(3000)], style="clean")
        check("a datagram from 127.0.0.2 (not the configured bridge host) is dropped over a real socket",
              wait_for(lambda: leg4.rx_dropped_source >= 1) and queued(core4, "dvm:p25") == before)
        bad.close()
    else:
        check("(127.0.0.2 not bindable on this host; real-socket wrong-source check covered by section 2)", True)

    # exclusive bind
    dup = UsrpLeg(core4, channel_id="dvm:dup", listen_port=leg4.listen_port)
    core4.add_channel(Channel("dvm:dup", "dup", RegClass.AMATEUR))
    try:
        dup.start_rx()
        loud = False
        dup.stop()
    except OSError:
        loud = True
    check("a second leg on the same listen address and port FAILS to start (no silent split)", loud)
finally:
    sim.close()
    port4 = leg4.listen_port
    leg4.stop()

probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
try:
    probe.bind(("127.0.0.1", port4))
    freed = True
except OSError:
    freed = False
finally:
    probe.close()
check("stop() releases the UDP port (detach frees it for the next leg)", freed)
check("stop() is idempotent", (leg4.stop() or True))

# ══ 5. carries PCM through the real matrix tick ═══════════════════════════
print("\n5. Through MatrixCore.tick()")
core5 = MatrixCore()
leg5 = new_leg(core5, cid="dvm:p25", bridge_host="127.0.0.1")
sink = Channel("loop:sink", "Sink", RegClass.INTERNAL)
core5.add_channel(sink)
sink_leg = LoopbackLeg(core5, "loop:sink")
sink.leg = sink_leg
core5.add_route(Route(src="dvm:p25", dst="loop:sink"))
frames = [pcm(1000 + i) for i in range(5)]
for i, f in enumerate(frames):
    leg5.handle_datagram(make_header(i, "clean") + f, "127.0.0.1")
for _ in range(5):
    core5.tick()
check("five received frames come out of a patched channel byte-for-byte, in order",
      sink_leg.captured[:5] == frames)

# ══ 6. regulatory class handling ══════════════════════════════════════════
print("\n6. Regulatory class")
core6 = MatrixCore()
core6.add_channel(Channel("dvm:amateur", "P25 amateur", RegClass.AMATEUR))
core6.add_channel(Channel("dvm:part90", "P25 Part 90 network", RegClass.COMMERCIAL))
core6.add_channel(Channel("dmr:tg", "DMR amateur", RegClass.AMATEUR))
core6.add_channel(Channel("pbx:desk", "Phone", RegClass.PSTN))
core6.add_channel(Channel("zello:x", "Zello", RegClass.INTERNAL))
for src6, dst6, label in (("dvm:part90", "dmr:tg", "a commercial (Part 90) digital voice channel -> amateur DMR"),
                           ("dmr:tg", "dvm:part90", "amateur DMR -> a commercial digital voice channel"),
                           ("dvm:amateur", "pbx:desk", "an amateur digital voice channel -> a phone line")):
    try:
        core6.add_route(Route(src=src6, dst=dst6))
        blocked = False
    except RouteError:
        blocked = True
    check("route blocked without the audited override: " + label, blocked)
check("the same pair IS allowed with the audited override flag",
      core6.add_route(Route(src="dvm:part90", dst="dmr:tg", allow_cross_class=True)) is not None)
check("amateur digital voice <-> amateur DMR needs no override (same class)",
      core6.add_route(Route(src="dvm:amateur", dst="dmr:tg")) is not None)
check("amateur digital voice -> internal Zello needs no override (dispatch monitoring)",
      core6.add_route(Route(src="dvm:amateur", dst="zello:x")) is not None)

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
