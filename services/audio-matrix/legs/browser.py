"""
BrowserLeg / BrowserLegServer — a dispatcher's own web browser as a matrix
channel (Phase 152 prerequisite #3, the Communications Console v2 rebuild).

Every other leg (DMR, loopback) is STATIC: one channel_id, loaded once at
service boot from comm_channels and kept for the service's whole lifetime.
A console operator's browser tab is the opposite — it opens when they log
in, closes when they close the tab or their session lapses, and there can
be any number of them (one per dispatcher, one per workstation). So this
leg does not get wired up once in service.py's load_channels() like the
DMR leg; it runs its OWN accept loop and calls core.add_channel() /
core.remove_channel() dynamically, once per WebSocket connection.

Wire protocol, matching assets/js/radio-widget.js's existing
startWsMicPump()/mic-downsampler convention exactly (see that file's
`ws.send(int16Frame.buffer)` call and the AudioWorklet that produces it):

  1. The FIRST message on a new connection is TEXT, JSON:
         {"session_token": "<from api/console-session.php>"}
     No audio may be sent before this handshake succeeds. A connection
     that sends anything else first, or whose token doesn't validate, is
     closed immediately (close code 4001) — never left open sending
     silence, which would look like a live but muted channel to the rest
     of the matrix.
  2. Every message after that is BINARY: exactly one 320-byte s16le mono
     8 kHz PCM frame (frame.py's BYTES_PER_FRAME), same as every other
     leg's frame contract. No envelope, no header — one WS message is one
     20 ms frame, in both directions.
  3. The server may send a JSON TEXT message at any time
     ({"type":"error","message":...}) for handshake/format problems;
     the browser side treats any non-binary message as diagnostic, not
     audio.
  4. Phase 152 prerequisite #7 — the server ALSO sends
     {"type":"tx_started"} / {"type":"tx_ended"} the INSTANT the matrix
     core actually begins/stops mixing this session's own mic frames into
     a live route (see BrowserLeg._update_tx_state()) — never merely when
     the browser clicked PTT. A strip's TX lamp must key off this echo,
     not the click, so a press that never reached the server (dropped WS,
     an FCC/regulatory gate refusal upstream) visibly never lights it.

Session validation is injectable (validate_session) so the fixture test
drives real handshake acceptance/rejection without a database — the live
service wires it to a `console_sessions` lookup (see
api/console-session.php / sql/run_phase152_console_sessions.php).

Threading: the matrix tick calls Channel.deliver() -> leg.outbound(frame)
synchronously from MatrixCore's own tick thread (see matrix_core.py).
This leg's actual WebSocket send must happen on the asyncio event loop
that owns the socket, which runs on a DIFFERENT thread — so outbound()
hands the frame to that loop via asyncio.run_coroutine_threadsafe() rather
than awaiting anything itself. A slow/backed-up client cannot block the
matrix tick: the coroutine is fire-and-forgotten, and a full outbound
queue drops the OLDEST frame rather than growing without bound (same
bounded-latency policy as MatrixCore's own per-channel jitter buffer).
"""

from __future__ import annotations

import asyncio
import json
import logging
import threading
import time
import urllib.error
import urllib.request
from collections import deque
from typing import Callable, Optional

try:
    from frame import BYTES_PER_FRAME, is_silence
except ImportError:  # pragma: no cover - direct-module fallback
    from ..frame import BYTES_PER_FRAME, is_silence  # type: ignore

LOG = logging.getLogger("audio-matrix.browser")

# Bounded per-connection outbound queue: 1 second of 20ms frames. A
# dispatcher's own browser tab that stalls (backgrounded, laptop asleep)
# must not accumulate memory or add latency once it resumes — mirrors
# MatrixCore's INQ_MAX policy (drop oldest, never grow unbounded).
OUTQ_MAX = 50

# WebSocket close codes (private-use range, RFC 6455 3000-3999).
CLOSE_BAD_HANDSHAKE = 4001   # first message wasn't valid {"session_token"}
CLOSE_AUTH_FAILED = 4003     # session_token didn't validate


class BrowserLeg:
    """
    One console session's leg. Exists for the lifetime of a single
    WebSocket connection. `send_frame` is injected by the server (it
    closes over the asyncio loop + socket); tests can inject a plain
    list-appending stub to exercise outbound() with no real socket.
    """

    def __init__(
        self,
        core,
        channel_id: str,
        send_frame: Callable[[bytes], None],
        on_tx_state: Optional[Callable[[str], None]] = None,
    ):
        self.core = core
        self.channel_id = channel_id
        self._send_frame = send_frame
        # Phase 152 prerequisite #7 — called with "started"/"ended" the
        # instant this session's audio transitions into/out of actually
        # being relayed by a live route. None means no confirmation wired
        # (e.g. a bare unit test of outbound()/inbound() alone).
        self._on_tx_state = on_tx_state
        self._tx_active = False
        self.rx_frames = 0
        self.tx_frames = 0
        self.tx_dropped = 0
        self._outq: deque = deque(maxlen=OUTQ_MAX)

    # matrix -> leg (called on the MatrixCore tick thread)
    def outbound(self, frame: bytes) -> None:
        before = len(self._outq)
        self._outq.append(frame)  # deque(maxlen) silently drops the oldest
        if len(self._outq) == before:
            self.tx_dropped += 1
        try:
            self._send_frame(frame)
            self.tx_frames += 1
        except Exception as e:  # noqa: BLE001 - a dead socket must not kill the tick
            LOG.debug("browser leg %s: send failed: %s", self.channel_id, e)

    # leg -> matrix (called from the WS receive loop)
    def inbound(self, frame: bytes) -> None:
        if len(frame) != BYTES_PER_FRAME:
            return  # malformed frame from a misbehaving client — drop, don't crash
        self.core.inbound(self.channel_id, frame)
        self.rx_frames += 1
        self._update_tx_state(frame)

    def _has_live_outgoing_route(self) -> bool:
        """Is this channel CURRENTLY the source of at least one enabled,
        non-expired route? "TX" only means something once this session's
        audio actually has somewhere live to go — a browser tab sending
        mic audio with no patch out is not transmitting anything."""
        for r in self.core.routes():
            if r.src == self.channel_id and r.enabled and not r.is_expired():
                return True
        return False

    def _update_tx_state(self, frame: bytes) -> None:
        should_be_active = (not is_silence(frame)) and self._has_live_outgoing_route()
        if should_be_active == self._tx_active:
            return
        self._tx_active = should_be_active
        if self._on_tx_state is not None:
            try:
                self._on_tx_state("started" if should_be_active else "ended")
            except Exception as e:  # noqa: BLE001 - a callback failure must never break RX
                LOG.debug("browser leg %s: on_tx_state callback failed: %s", self.channel_id, e)

    def health(self) -> dict:
        return {
            "channel_id": self.channel_id,
            "rx_frames": self.rx_frames,
            "tx_frames": self.tx_frames,
            "tx_dropped": self.tx_dropped,
            "tx_active": self._tx_active,
        }


def default_validate_session(db_cursor_factory, session_token: str) -> Optional[dict]:
    """
    Default session-token validator: looks up console_sessions on the real
    DB connection the service already holds. Returns
    {"id":..., "user_id":..., "username":..., "workstation_token":...} or
    None. `db_cursor_factory` is a zero-arg callable returning a fresh
    dict-cursor (mysql-connector cursors are not thread-safe to share
    across the tick thread and this leg's own accept-loop thread, so a
    fresh one is pulled per validation rather than reusing one connection).
    """
    cur = db_cursor_factory()
    try:
        cur.execute(
            "SELECT id, user_id, username, workstation_token "
            "FROM console_sessions "
            "WHERE session_token = %s AND revoked_at IS NULL "
            "AND expires_at > NOW()",
            (session_token,),
        )
        row = cur.fetchone()
        return dict(row) if row else None
    finally:
        cur.close()


def make_state_notify_fn(
    base_url: str, token: str, timeout: float = 3.0
) -> Callable[[str, str, str, str], None]:
    """
    Phase 152 prerequisite #7 — build the default (channel_id, label,
    event, state) notifier hitting api/matrix-channel-state.php, so a
    screen.console viewer on ANOTHER workstation learns about a session's
    connect/disconnect or TX state over SSE, not just the one browser tab
    directly involved.

    `event` is "channel_state" (state: "connected"/"disconnected"),
    "tx_state" (state: "started"/"ended") or, added in Phase 155 for the
    generic USRP leg (legs/usrp.py), "rx_state" (state: "started"/"ended" —
    audio began/stopped arriving FROM a bridge on that channel).
    Best-effort: any failure
    (network, non-2xx) is logged at debug and swallowed — a state-report
    call must never break the leg's own connect/disconnect/TX handling,
    mirroring fcc_gate.py's own fail-safe-for-the-caller posture (that one
    fails CLOSED because it gates whether audio may flow; this one has no
    gating role at all, it is purely informational, so failing open is
    correct here).
    """
    def _notify(channel_id: str, label: str, event: str, state: str) -> None:
        body = {"event": event, "channel_id": channel_id, "label": label}
        if event == "channel_state":
            body["state"] = state
        elif event == "rx_state":
            body["rx"] = state
        else:
            body["tx"] = state
        data = json.dumps(body).encode("utf-8")
        req = urllib.request.Request(
            base_url.rstrip("/") + "/api/matrix-channel-state.php",
            data=data, method="POST",
        )
        req.add_header("Content-Type", "application/json")
        req.add_header("Authorization", "Bearer " + token)
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:  # noqa: S310 - configured base_url
                r.read()
        except Exception as e:  # noqa: BLE001 - best-effort notification only
            LOG.debug("state notify failed for %s %s=%s: %s", channel_id, event, state, e)

    return _notify


class BrowserLegServer:
    """
    Owns the asyncio WebSocket accept loop on its own thread. Each accepted
    connection performs the token handshake, then lives as one dynamic
    matrix channel (`browser:<session_id>`) for as long as the socket stays
    open. Constructed once at service boot; start()/stop() bracket its
    life the same way MatrixCore.start()/stop() do.
    """

    def __init__(
        self,
        core,
        host: str = "0.0.0.0",
        port: int = 18093,
        validate_session: Optional[Callable[[str], Optional[dict]]] = None,
        on_session_open: Optional[Callable[[dict], None]] = None,
        on_session_close: Optional[Callable[[dict], None]] = None,
        notify_fn: Optional[Callable[[str, str, str, str], None]] = None,
    ):
        self.core = core
        self.host = host
        self.port = port
        self._validate_session = validate_session or (lambda token: None)
        self._on_session_open = on_session_open
        self._on_session_close = on_session_close
        # Phase 152 prerequisite #7 — (channel_id, label, event, state).
        # None means channel/TX state changes stay purely local to this
        # session's own WS (still echoed there) but never reach PHP/SSE,
        # so OTHER console viewers won't see it — fine for tests, real
        # deployments should wire make_state_notify_fn().
        self._notify_fn = notify_fn

        self._loop: Optional[asyncio.AbstractEventLoop] = None
        self._thread: Optional[threading.Thread] = None
        self._stop_evt: Optional[asyncio.Event] = None  # created on the loop thread
        self._running = False

        # active sessions, keyed by channel_id, for /health reporting
        self._legs: dict = {}
        self._legs_lock = threading.Lock()

    # ── lifecycle ────────────────────────────────────────────────────
    def start(self) -> None:
        if self._running:
            return
        self._running = True
        self._thread = threading.Thread(
            target=self._run_loop, name="browser-leg-ws", daemon=True
        )
        self._thread.start()
        # Block briefly until the loop is actually up so callers (service.py,
        # tests) can rely on the port being bound the instant start() returns.
        deadline = time.monotonic() + 5.0
        while self._loop is None and time.monotonic() < deadline:
            time.sleep(0.02)

    def stop(self) -> None:
        self._running = False
        loop = self._loop
        stop_evt = self._stop_evt
        if loop is not None and loop.is_running() and stop_evt is not None:
            # Signal the async side so it exits its `async with
            # websockets.serve(...)` block NORMALLY (letting the library's
            # own graceful-close coroutine run) rather than yanking the loop
            # out from under it with loop.stop() — the latter leaves the
            # server's close() coroutine scheduled on an already-dead loop
            # ("RuntimeError: Event loop is closed", a real bug caught by
            # this class's own test, not a cosmetic warning).
            loop.call_soon_threadsafe(stop_evt.set)
        if self._thread is not None:
            self._thread.join(timeout=5.0)

    def health(self) -> dict:
        with self._legs_lock:
            return {
                "active_sessions": len(self._legs),
                "port": self.port,
            }

    # ── asyncio side ─────────────────────────────────────────────────
    def _run_loop(self) -> None:
        import websockets  # imported lazily so the rest of the service can
                            # run (loopback/DMR only) on a host that hasn't
                            # installed the one extra dependency yet.

        async def _main():
            self._loop = asyncio.get_running_loop()
            self._stop_evt = asyncio.Event()
            async with websockets.serve(self._handle, self.host, self.port,
                                        max_size=BYTES_PER_FRAME + 4096):
                LOG.info("browser leg WS server on %s:%d", self.host, self.port)
                await self._stop_evt.wait()

        loop = asyncio.new_event_loop()
        asyncio.set_event_loop(loop)
        try:
            loop.run_until_complete(_main())
        finally:
            loop.close()
            self._loop = None

    async def _handle(self, ws) -> None:
        session = await self._handshake(ws)
        if session is None:
            return  # _handshake already closed the socket with a reason

        channel_id = f"browser:{session['id']}"
        loop = asyncio.get_running_loop()

        label = session.get("username") or channel_id

        def send_frame(frame: bytes) -> None:
            # Called from the MatrixCore tick thread (a different thread
            # than this coroutine). Never await here — schedule and return
            # immediately so a slow/backed-up client can't stall the tick.
            asyncio.run_coroutine_threadsafe(_safe_send(ws, frame), loop)

        def on_tx_state(tx: str) -> None:
            # Called from the WS receive coroutine (this same loop/thread),
            # so this CAN run synchronously -- but send still goes through
            # the same fire-and-forget scheduling as send_frame for
            # consistency and because the coroutine below awaits ws.send()
            # itself; run_coroutine_threadsafe on our own loop is a no-op
            # in cost here and keeps one code path for both directions.
            asyncio.run_coroutine_threadsafe(
                _safe_send(ws, json.dumps({"type": "tx_" + tx})), loop
            )
            if self._notify_fn is not None:
                try:
                    self._notify_fn(channel_id, label, "tx_state", tx)
                except Exception:  # noqa: BLE001 - notification must not break TX handling
                    pass

        from matrix_core import Channel  # local import: avoid a hard
                                          # dependency for callers that only
                                          # need BrowserLeg for unit tests

        leg = BrowserLeg(self.core, channel_id, send_frame, on_tx_state=on_tx_state)
        try:
            # Phase 152 workstation identity (plan.md section 3.5) --
            # resolved ONCE here, at connect, from the SAME console_
            # sessions row default_validate_session() already looked up
            # (its own SELECT includes workstation_token). A session
            # minted with no workstation_token (an install that hasn't
            # deployed console-workstation.js yet, or a stray non-UUID
            # value api/console-session.php declined to store) leaves
            # this None, which the adjacent-transmit-mute filter in
            # MatrixCore.tick() treats as "never muted, never mutes" --
            # exactly today's behavior, unaffected.
            self.core.add_channel(Channel(
                id=channel_id, name=label,
                workstation_token=session.get("workstation_token"),
            ))
        except Exception as e:  # RouteError: channel_id collision, extremely
                                 # unlikely (session ids are unique) but must
                                 # never crash the accept loop
            LOG.warning("browser leg: could not add channel %s: %s", channel_id, e)
            await ws.close(code=CLOSE_BAD_HANDSHAKE, reason="channel setup failed")
            return
        self.core.channel(channel_id).leg = leg

        with self._legs_lock:
            self._legs[channel_id] = leg
        if self._on_session_open is not None:
            try:
                self._on_session_open(session)
            except Exception:  # noqa: BLE001 - bookkeeping must not break audio
                pass
        if self._notify_fn is not None:
            try:
                self._notify_fn(channel_id, label, "channel_state", "connected")
            except Exception:  # noqa: BLE001
                pass

        try:
            async for message in ws:
                if isinstance(message, (bytes, bytearray)):
                    leg.inbound(bytes(message))
                # a stray text message post-handshake is ignored, not fatal
        finally:
            self.core.remove_channel(channel_id)
            with self._legs_lock:
                self._legs.pop(channel_id, None)
            if self._on_session_close is not None:
                try:
                    self._on_session_close(session)
                except Exception:  # noqa: BLE001
                    pass
            if self._notify_fn is not None:
                try:
                    self._notify_fn(channel_id, label, "channel_state", "disconnected")
                except Exception:  # noqa: BLE001
                    pass

    async def _handshake(self, ws):
        try:
            first = await asyncio.wait_for(ws.recv(), timeout=10.0)
        except Exception:
            await _safe_close(ws, CLOSE_BAD_HANDSHAKE, "handshake timeout")
            return None
        if isinstance(first, (bytes, bytearray)):
            await _safe_close(ws, CLOSE_BAD_HANDSHAKE, "first message must be the JSON handshake")
            return None
        try:
            payload = json.loads(first)
            token = payload["session_token"]
            if not isinstance(token, str) or not token:
                raise ValueError("empty session_token")
        except Exception:
            await _safe_close(ws, CLOSE_BAD_HANDSHAKE, "malformed handshake")
            return None

        session = self._validate_session(token)
        if session is None:
            await _safe_close(ws, CLOSE_AUTH_FAILED, "session_token invalid or expired")
            return None
        try:
            await ws.send(json.dumps({"type": "handshake_ok", "channel_id": f"browser:{session['id']}"}))
        except Exception:
            return None
        return session


async def _safe_send(ws, frame: bytes) -> None:
    try:
        await ws.send(frame)
    except Exception:  # noqa: BLE001 - the connection is going away; nothing to do
        pass


async def _safe_close(ws, code: int, reason: str) -> None:
    try:
        await ws.close(code=code, reason=reason)
    except Exception:  # noqa: BLE001
        pass
