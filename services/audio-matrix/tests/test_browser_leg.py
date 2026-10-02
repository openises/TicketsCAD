"""
Live integration test for BrowserLegServer (Phase 152 prerequisite #3).

Unlike the other legs' fixture tests (LoopbackLeg stands in for the wire
entirely), a WebSocket server leg's whole job IS the wire — the handshake,
the framing, the per-connection dynamic channel lifecycle. So this test
runs the REAL asyncio server on a real (ephemeral, loopback-only) TCP port
and drives it with two real WebSocket clients, proving the stated success
criterion directly: two independent browser sessions get independent,
isolated audio through the matrix, exactly as two DMR/Zello channels would.

Requires the `websockets` package (see services/audio-matrix/requirements.txt).
Run:

    python3 services/audio-matrix/tests/test_browser_leg.py
"""

import asyncio
import json
import os
import sys
import time
from array import array

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import frame as F                                     # noqa: E402
from matrix_core import Channel, MatrixCore, Route     # noqa: E402
from legs.browser import (                             # noqa: E402
    CLOSE_AUTH_FAILED,
    CLOSE_BAD_HANDSHAKE,
    BrowserLegServer,
)

try:
    import websockets
except ImportError:
    print("SKIP: websockets not installed (pip install -r "
          "services/audio-matrix/requirements.txt)")
    print("\n=== 0 passed, 0 failed ===")
    sys.exit(0)

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


# Canned session directory the fixture validator resolves tokens against —
# stands in for a real `console_sessions` DB row (see api/console-session.php).
SESSIONS = {
    "tok-alice": {"id": 501, "user_id": 1, "username": "alice"},
    "tok-bob": {"id": 502, "user_id": 2, "username": "bob"},
}


def validate_session(token):
    return SESSIONS.get(token)


async def run() -> None:
    core = MatrixCore()
    # A fixed high port rather than port=0: BrowserLegServer doesn't stash
    # the websockets.serve() object where a caller on a different thread
    # could read back an OS-assigned port, and this test owns loopback
    # exclusively — a collision on this port in CI is exceedingly unlikely.
    port = 18999
    # Phase 152 prerequisite #7 — capture every (channel_id, label, event,
    # state) notify_fn call instead of hitting a real PHP endpoint. This is
    # the exact seam services/audio-matrix/service.py wires
    # make_state_notify_fn() into for the real deployment; here it proves
    # BrowserLegServer/BrowserLeg call it with the right shape and at the
    # right moments, independent of whether the HTTP delivery itself works
    # (that half is covered PHP-side, tests/test_phase152_channel_state_wiring.php).
    notify_log = []

    def fake_notify(channel_id, label, event, state):
        notify_log.append((channel_id, label, event, state))

    server = BrowserLegServer(core, host="127.0.0.1", port=port,
                              validate_session=validate_session,
                              notify_fn=fake_notify)
    server.start()
    url = f"ws://127.0.0.1:{port}"

    try:
        # ── 1. two independent sessions connect ──────────────────────
        print("1. Handshake + dynamic channel lifecycle")
        async with websockets.connect(url) as wa:
            await wa.send(json.dumps({"session_token": "tok-alice"}))
            ack_a = json.loads(await asyncio.wait_for(wa.recv(), timeout=5))
            check("alice handshake acked", ack_a.get("type") == "handshake_ok")
            check("alice channel id uses session id", ack_a.get("channel_id") == "browser:501")

            async with websockets.connect(url) as wb:
                await wb.send(json.dumps({"session_token": "tok-bob"}))
                ack_b = json.loads(await asyncio.wait_for(wb.recv(), timeout=5))
                check("bob handshake acked", ack_b.get("type") == "handshake_ok")

                # give the accept-loop coroutines a moment to register
                # the dynamic channels before we touch the core directly
                for _ in range(50):
                    if core.channel("browser:501") and core.channel("browser:502"):
                        break
                    await asyncio.sleep(0.02)
                check("alice's channel exists in the live matrix", core.channel("browser:501") is not None)
                check("bob's channel exists in the live matrix", core.channel("browser:502") is not None)

                # ── 2. independent, isolated audio between two sessions ──
                print("\n2. Independent per-session audio")
                core.add_route(Route("browser:501", "browser:502"))
                await wa.send(tone(4000))

                # wait for the leg to actually ingest it, then mix by hand
                # (deterministic — no reliance on the background tick clock)
                leg_a = core.channel("browser:501").leg
                for _ in range(50):
                    if leg_a is not None and leg_a.rx_frames >= 1:
                        break
                    await asyncio.sleep(0.02)
                check("alice's frame reached her leg", leg_a is not None and leg_a.rx_frames == 1)
                core.tick()

                got_b = await asyncio.wait_for(wb.recv(), timeout=5)
                check("bob receives alice's routed audio", first_sample(got_b) == 4000)

                # Bob has NO route back to Alice — she must NOT hear any
                # AUDIO back. She DOES legitimately receive her own
                # tx_started TEXT control frame now (Phase 152 prereq #7 —
                # she has a live outgoing route, so her leg confirms TX),
                # which is not an echo of routed audio and must not be
                # mistaken for one; drain and classify everything she gets
                # within a short window rather than assuming silence.
                heard_binary_echo = False
                saw_tx_started = False
                deadline = asyncio.get_event_loop().time() + 0.5
                while True:
                    remaining = deadline - asyncio.get_event_loop().time()
                    if remaining <= 0:
                        break
                    try:
                        msg = await asyncio.wait_for(wa.recv(), timeout=remaining)
                    except asyncio.TimeoutError:
                        break
                    if isinstance(msg, (bytes, bytearray)):
                        heard_binary_echo = True
                    else:
                        parsed = json.loads(msg)
                        if parsed.get("type") == "tx_started":
                            saw_tx_started = True
                check("alice hears NO AUDIO back (no reverse route = isolated)", not heard_binary_echo)
                check("alice DOES get her own tx_started confirmation (prereq #7 — she has a live outgoing route)",
                      saw_tx_started)

                # Silence ends the transmission -- must produce tx_ended,
                # not just "no more frames arrived" (TX state only ever
                # changes on an actual inbound() call).
                await wa.send(F.SILENCE)
                for _ in range(50):
                    if leg_a is not None and leg_a.rx_frames >= 2:
                        break
                    await asyncio.sleep(0.02)
                saw_tx_ended = False
                deadline2 = asyncio.get_event_loop().time() + 0.5
                while True:
                    remaining2 = deadline2 - asyncio.get_event_loop().time()
                    if remaining2 <= 0:
                        break
                    try:
                        msg2 = await asyncio.wait_for(wa.recv(), timeout=remaining2)
                    except asyncio.TimeoutError:
                        break
                    if not isinstance(msg2, (bytes, bytearray)) and json.loads(msg2).get("type") == "tx_ended":
                        saw_tx_ended = True
                check("a SILENCE frame after audio produces tx_ended", saw_tx_ended)

            # bob's `async with` block just exited -> his socket closed
            for _ in range(50):
                if core.channel("browser:502") is None:
                    break
                await asyncio.sleep(0.02)
            check("bob's channel is removed from the matrix on disconnect", core.channel("browser:502") is None)
            check("the route referencing bob's channel is gone too",
                  all("browser:502" not in (r.src, r.dst) for r in core.routes()))
            check("alice's channel is untouched by bob's disconnect", core.channel("browser:501") is not None)

        # ── 3. malformed / rejected handshakes ───────────────────────
        print("\n3. Handshake rejection")
        async with websockets.connect(url) as bad1:
            await bad1.send(b"\x00" * F.BYTES_PER_FRAME)  # binary first: invalid
            try:
                await asyncio.wait_for(bad1.wait_closed(), timeout=5)
                check("binary-first connection is closed",
                      bad1.close_code == CLOSE_BAD_HANDSHAKE)
            except asyncio.TimeoutError:
                check("binary-first connection is closed", False)

        async with websockets.connect(url) as bad2:
            await bad2.send(json.dumps({"session_token": "not-a-real-token"}))
            try:
                await asyncio.wait_for(bad2.wait_closed(), timeout=5)
                check("invalid-token connection is closed",
                      bad2.close_code == CLOSE_AUTH_FAILED)
            except asyncio.TimeoutError:
                check("invalid-token connection is closed", False)

        # alice's own `async with` block ended before section 3 began, so her
        # channel is gone too by now (proven separately in section 1/2's own
        # disconnect checks for bob) — the real thing this proves is that a
        # REJECTED handshake adds nothing on top of that already-clean state.
        check("a rejected handshake never creates a matrix channel",
              len(core.channels()) == 0)

        # ── 4. notify_fn wiring (Phase 152 prereq #7) ────────────────
        print("\n4. notify_fn(channel_id, label, event, state) call sequence")
        alice_events = [e for e in notify_log if e[0] == "browser:501"]
        bob_events = [e for e in notify_log if e[0] == "browser:502"]
        check("alice: channel_state connected was reported",
              ("browser:501", "alice", "channel_state", "connected") in alice_events)
        check("alice: tx_state started was reported",
              ("browser:501", "alice", "tx_state", "started") in alice_events)
        check("alice: tx_state ended was reported",
              ("browser:501", "alice", "tx_state", "ended") in alice_events)
        check("alice: channel_state disconnected was reported (her socket closed at the end of section 1)",
              ("browser:501", "alice", "channel_state", "disconnected") in alice_events)
        check("bob: channel_state connected AND disconnected were both reported",
              ("browser:502", "bob", "channel_state", "connected") in bob_events
              and ("browser:502", "bob", "channel_state", "disconnected") in bob_events)
        check("bob never got a tx_state event (he never sent audio)",
              not any(e[2] == "tx_state" for e in bob_events))
        # Order matters: connected must precede tx_started must precede
        # tx_ended must precede disconnected, for alice specifically.
        alice_order = [e[2] + ":" + e[3] for e in alice_events]
        expected_order = ["channel_state:connected", "tx_state:started",
                          "tx_state:ended", "channel_state:disconnected"]
        check("alice's events arrived in the correct chronological order",
              alice_order == expected_order)

    finally:
        server.stop()


asyncio.run(run())

print(f"\n=== {_pass} passed, {_fail} failed ===")
sys.exit(0 if _fail == 0 else 1)
