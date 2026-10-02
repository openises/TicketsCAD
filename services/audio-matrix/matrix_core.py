"""
AudioPump v2 — the TicketsCAD audio matrix core (Phase 114c).

Evolves the single-source AudioPump in the DMR bridge
(services/dvswitch/hbp_client.py: one DMR source fanned out to widget
subscribers) into an N-channel x N-channel route matrix: any channel's
audio can be patched into any other with per-route gain, priority, and
ducking. Same 8 kHz / 20 ms / 320-byte frame format (see frame.py).

Design — SINGLE-HOP explicit routing (deliberate, differs from plan.md's
transitive `_route_depth` sketch):
  * A route S -> D mixes S's CURRENT inbound frame into D's OUTPUT each
    tick. It does NOT re-inject into D's inbound, so audio never makes a
    second hop and can never feed back through the matrix itself. A full-
    duplex patch between two channels is simply two routes (A->B and
    B->A); these are safe because legs never echo their own output.
  * Consequence: to carry A's audio to C you add an explicit A->C route;
    there is no automatic A->B->C transitive path. This is the standard,
    loop-proof dispatch-console model and is why no `_route_depth` guard
    is needed. Flagged for Eric — if he wants transitive auto-patching we
    add a bounded-depth expander on top; the single-hop core stays the
    safe substrate underneath.

Regulatory guard: every channel carries a class. amateur <-> commercial
and amateur <-> pstn routes are HARD-BLOCKED unless the route is created
with allow_cross_class=True (an audited operator override). Amateur TX
legs still owe the station-ID / unattended-keying machinery from Phases
85e/85f/112 — that lives in the amateur leg, not here.

The engine is transport-agnostic: a "leg" is any object exposing
    outbound(frame: bytes) -> None      # matrix -> the outside world
and feeding audio in via core.inbound(channel_id, frame). LoopbackLeg
(legs/loopback.py) is the test double; DMR/Zello/USRP legs come next.
"""

from __future__ import annotations

import threading
import time
from collections import deque
from dataclasses import dataclass, field
from enum import Enum
from typing import Callable, Dict, List, Optional, Set

from frame import (
    BYTES_PER_FRAME,
    FRAME_MS,
    SILENCE,
    apply_gain,
    db_to_factor,
    is_silence,
    mix,
)


class RegClass(str, Enum):
    AMATEUR = "amateur"
    COMMERCIAL = "commercial"
    INTERNAL = "internal"
    PSTN = "pstn"


# Cross-class pairs that are forbidden without an explicit audited override.
# (FCC §97.113: no amateur<->business; amateur<->PSTN autopatch is heavily
#  constrained.) internal<->anything is always allowed (dispatch monitoring).
_BLOCKED_PAIRS = {
    frozenset({RegClass.AMATEUR, RegClass.COMMERCIAL}),
    frozenset({RegClass.AMATEUR, RegClass.PSTN}),
}

DUCK_DB = -12.0     # attenuation applied to lower-priority routes when a
                    # higher-priority source into the same destination is live

# Per-channel inbound jitter buffer depth. Legs deliver audio in bursts
# (DMR emits ~60 ms = 3 frames per event; Opus packets vary), but the tick
# consumes ONE 20 ms frame per channel per tick. The queue absorbs the
# burstiness; when it overflows we drop the OLDEST frame (bounded latency,
# same policy as the DMR bridge AudioPump's QUEUE_MAX).
INQ_MAX = 50        # 1 second of 20 ms frames


class RouteError(Exception):
    """Raised when a route is rejected at write time (invalid or blocked)."""


@dataclass
class Channel:
    id: str
    name: str
    reg_class: RegClass = RegClass.INTERNAL
    leg: Optional[object] = None          # has .outbound(frame)
    # Phase 152 prerequisite #2 (Python half) -- an optional per-channel
    # FCC station-ID gate (fcc_gate.FccRelayGate or any object exposing
    # .tick(source_live: bool) -> bool). None (the default, and every
    # channel's behavior before this existed) means "no gate at all" --
    # a direct human PTT leg is NEVER given one, since this gate applies
    # only to audio a Route autonomously relays onto a destination with no
    # operator at the mic for that leg. See fcc_gate.py's own docblock.
    fcc_gate: Optional[object] = None
    # Phase 152 workstation identity (plan.md section 3.5) -- set ONLY on
    # a browser-leg channel (legs/browser.py's BrowserLegServer._handle(),
    # resolved once at WS connect from console_sessions.workstation_token).
    # None for every other channel (DMR, Zello, the intercom hub, loopback)
    # -- the adjacent-transmit-mute filter in MatrixCore.tick() is a no-op
    # the instant either side of a route lacks one, so it can never affect
    # anything but browser-to-browser paths.
    workstation_token: Optional[str] = None
    # runtime inbound jitter buffer (legs append; the tick pops one/frame)
    _inq: deque = field(default_factory=lambda: deque(maxlen=INQ_MAX), repr=False)
    _keyed: bool = field(default=False, repr=False)   # last tick had audio (health/UI)

    def deliver(self, frame: bytes) -> None:
        """Hand a mixed frame to this channel's leg (if any)."""
        if self.leg is not None and hasattr(self.leg, "outbound"):
            self.leg.outbound(frame)


@dataclass
class Route:
    src: str
    dst: str
    gain_db: float = 0.0
    priority: int = 0          # higher wins ducking contests into a dest
    ducking: bool = True       # may this route be ducked under a hotter one?
    enabled: bool = True
    allow_cross_class: bool = False   # audited operator override flag
    # Phase 152 prerequisite #6 -- Unix epoch seconds (float/int), or None
    # for "never expires". READ-TIME enforcement: MatrixCore.tick() checks
    # this on every mix tick and excludes an expired route's contribution
    # exactly like a disabled one -- the matrix service itself refuses to
    # keep relaying expired audio, independent of whether any PHP-side
    # sweep (tools/matrix_expiry_warning_tick.php) has ever run. Never
    # auto-removes the Route object itself -- an expired-but-still-
    # present row still shows in GET /routes (so the console can render
    # "expired, needs renewal" instead of it just vanishing), it simply
    # stops carrying audio.
    expires_at: Optional[float] = None

    @property
    def factor(self) -> float:
        return db_to_factor(self.gain_db)

    def is_expired(self, now: Optional[float] = None) -> bool:
        if self.expires_at is None:
            return False
        return (now if now is not None else time.time()) >= self.expires_at


class MatrixCore:
    """
    Holds the channel + route tables and runs the 20 ms mix tick. Thread-
    safe for control-plane mutation while the tick loop runs.
    """

    def __init__(self, on_audit: Optional[Callable[[str, dict], None]] = None):
        self._channels: Dict[str, Channel] = {}
        self._routes: List[Route] = []
        self._lock = threading.RLock()
        self._running = False
        self._thread: Optional[threading.Thread] = None
        self._on_audit = on_audit   # callback(event_type, detail) for the audit log
        # Phase 152 workstation identity -- dst workstation_token -> set of
        # muted src workstation_tokens ("dst's speakers do not want to hear
        # this src's own transmissions"). Populated at boot from
        # console_workstation_mutes (service.py's load_workstation_mutes())
        # and kept live via control_http.py's /workstation-mutes endpoint.
        self._workstation_mutes: Dict[str, set] = {}

    # ── channel table ────────────────────────────────────────────────
    def add_channel(self, channel: Channel) -> Channel:
        with self._lock:
            if channel.id in self._channels:
                raise RouteError(f"channel {channel.id!r} already exists")
            self._channels[channel.id] = channel
            return channel

    def channel(self, cid: str) -> Optional[Channel]:
        return self._channels.get(cid)

    def channels(self) -> List[Channel]:
        with self._lock:
            return list(self._channels.values())

    def remove_channel(self, channel_id: str) -> bool:
        """
        Phase 152 — every DB-loaded channel (DMR, Zello, etc.) is static for
        the service's lifetime, but a browser-leg console session comes and
        goes with every tab open/close. Removes the channel and every route
        that references it (as src or dst) so a churning session can never
        leave orphaned routes accumulating forever. Safe to call from a
        different thread than the tick loop (same lock as everywhere else).
        """
        with self._lock:
            if channel_id not in self._channels:
                return False
            del self._channels[channel_id]
            before = len(self._routes)
            self._routes = [r for r in self._routes if channel_id not in (r.src, r.dst)]
            removed_routes = before - len(self._routes)
            self._audit("matrix.channel_remove", {
                "channel_id": channel_id, "routes_removed": removed_routes,
            })
            return True

    # ── route table ──────────────────────────────────────────────────
    def add_route(self, route: Route) -> Route:
        with self._lock:
            src = self._channels.get(route.src)
            dst = self._channels.get(route.dst)
            if src is None or dst is None:
                raise RouteError("route references unknown channel")
            if route.src == route.dst:
                raise RouteError("self-route (src == dst) is not allowed")
            for r in self._routes:
                if r.src == route.src and r.dst == route.dst:
                    raise RouteError(f"route {route.src}->{route.dst} exists")
            pair = frozenset({src.reg_class, dst.reg_class})
            if pair in _BLOCKED_PAIRS and not route.allow_cross_class:
                raise RouteError(
                    f"regulatory guard: {src.reg_class.value} <-> "
                    f"{dst.reg_class.value} route blocked (needs override)"
                )
            self._routes.append(route)
            if pair in _BLOCKED_PAIRS and route.allow_cross_class:
                self._audit("matrix.cross_class_route", {
                    "src": route.src, "dst": route.dst,
                    "src_class": src.reg_class.value, "dst_class": dst.reg_class.value,
                })
            self._audit("matrix.route_add", {"src": route.src, "dst": route.dst,
                                             "gain_db": route.gain_db})
            return route

    def remove_route(self, src: str, dst: str) -> bool:
        with self._lock:
            for i, r in enumerate(self._routes):
                if r.src == src and r.dst == dst:
                    del self._routes[i]
                    self._audit("matrix.route_remove", {"src": src, "dst": dst})
                    return True
            return False

    def routes(self) -> List[Route]:
        with self._lock:
            return list(self._routes)

    # ── workstation mutes (Phase 152, plan.md section 3.5) ───────────
    def set_workstation_mute(self, workstation_token: str, muted_workstation_token: str, muted: bool) -> None:
        """
        `workstation_token`'s speakers should (muted=True) or should no
        longer (muted=False) suppress `muted_workstation_token`'s own
        transmissions. Directional by construction -- the caller (PHP's
        console_workstation_set_mute_pair(), or service.py's boot-time
        load) is responsible for calling this twice for a mutual pairing,
        exactly like the two Route rows a full-duplex patch already needs.
        """
        with self._lock:
            if muted:
                self._workstation_mutes.setdefault(workstation_token, set()).add(muted_workstation_token)
            else:
                s = self._workstation_mutes.get(workstation_token)
                if s is not None:
                    s.discard(muted_workstation_token)
                    if not s:
                        del self._workstation_mutes[workstation_token]

    def workstation_mutes(self) -> Dict[str, List[str]]:
        """{workstation_token: [muted_workstation_token, ...]} — for
        GET /workstation-mutes (health/debugging), sorted for stable
        output."""
        with self._lock:
            return {k: sorted(v) for k, v in self._workstation_mutes.items()}

    def _route_muted_for_dst(self, route: Route, dst: Channel, chans: Dict[str, Channel]) -> bool:
        """Is `route`'s contribution suppressed for THIS destination only?
        True only when BOTH sides are real browser-leg channels (a
        workstation_token is None for every DMR/Zello/hub/loopback
        channel) and the destination's workstation currently mutes the
        source's. Never channel-wide: a different destination with no
        such pairing still hears this exact same source normally."""
        if not dst.workstation_token:
            return False
        src_ch = chans.get(route.src)
        if src_ch is None or not src_ch.workstation_token:
            return False
        return src_ch.workstation_token in self._workstation_mutes.get(dst.workstation_token, ())

    # ── audio ingress (a leg appends frames as they arrive) ──────────
    def inbound(self, channel_id: str, frame: bytes, keyed: bool = True) -> None:
        """
        Append one 20 ms frame to a channel's inbound jitter buffer. Legs
        may call this in bursts (a 60 ms DMR event enqueues 3 frames); the
        tick drains one per tick. `keyed` is accepted for API symmetry but
        activity is derived per-tick from frame content.
        """
        ch = self._channels.get(channel_id)
        if ch is None:
            return
        f = frame if len(frame) == BYTES_PER_FRAME else (
            frame[:BYTES_PER_FRAME] + b"\x00" * max(0, BYTES_PER_FRAME - len(frame))
        )
        ch._inq.append(f)   # deque(maxlen) drops the oldest on overflow

    # ── the mix tick ─────────────────────────────────────────────────
    def tick(self) -> None:
        """
        Advance the matrix by one 20 ms frame. For every destination
        channel, gather its enabled inbound routes, apply gain + priority
        ducking, mix, and deliver to the destination leg. Then clear each
        channel's inbound to silence so a source that stops feeding goes
        quiet on the next tick (legs feed every tick while keyed).
        """
        with self._lock:
            chans = self._channels

            # 1. Pull ONE frame per channel from its jitter buffer (or
            #    silence if the buffer is empty). This is the single point
            #    of consumption per tick, so bursty legs don't lose frames.
            cur: Dict[str, bytes] = {}
            for cid, ch in chans.items():
                f = ch._inq.popleft() if ch._inq else SILENCE
                cur[cid] = f
                ch._keyed = not is_silence(f)   # for /health + UI RX lamps

            # 2. Mix per destination from this tick's frames. Phase 152
            #    prerequisite #6: an expired route is excluded exactly
            #    like a disabled one -- read-time enforcement, checked
            #    fresh every tick (never a cached "was valid at load
            #    time" decision), independent of whether any PHP-side
            #    sweep has run. The Route object itself is NOT removed --
            #    it still appears in GET /routes so a stale patch reads
            #    as "expired", not as if it never existed.
            now = time.time()
            routes = [r for r in self._routes if r.enabled and not r.is_expired(now)]
            by_dst: Dict[str, List[Route]] = {}
            for r in routes:
                by_dst.setdefault(r.dst, []).append(r)

            for dst_id, in_routes in by_dst.items():
                dst = chans.get(dst_id)
                if dst is None:
                    continue
                # Highest priority among routes whose source is live now.
                # Phase 152 workstation mute: a route suppressed for THIS
                # destination (_route_muted_for_dst()) counts as though it
                # weren't there at all for BOTH the ducking-priority
                # computation and the mix itself -- a muted source must
                # never be able to duck another, unrelated source into
                # silence on a destination it isn't even allowed to reach.
                hot = None
                for r in in_routes:
                    if self._route_muted_for_dst(r, dst, chans):
                        continue
                    if not is_silence(cur.get(r.src, SILENCE)):
                        hot = r.priority if hot is None else max(hot, r.priority)

                contributions = []
                for r in in_routes:
                    if self._route_muted_for_dst(r, dst, chans):
                        continue
                    f = cur.get(r.src, SILENCE)
                    if is_silence(f):
                        continue
                    factor = r.factor
                    # Duck this route if a strictly-hotter source is live.
                    if r.ducking and hot is not None and r.priority < hot:
                        factor *= db_to_factor(DUCK_DB)
                    contributions.append(apply_gain(f, factor))

                # Phase 152 prerequisite #2 (Python half) -- an FCC gate on
                # the DESTINATION channel refuses the WHOLE relayed mix for
                # this tick when it isn't currently permitted (no callsign
                # on file, or a hard-enforced ID interval has lapsed with no
                # human "at the mic" for this autonomous leg to confirm).
                # `hot is not None` is exactly "this destination's routed
                # source(s) are live right now" -- the same utterance-
                # boundary signal the gate uses to decide when to re-check
                # rather than trusting a stale cached decision. A channel
                # with no gate configured (fcc_gate is None -- every
                # channel's behavior before this gate existed, and every
                # direct-human-PTT leg forever, since those never go
                # through routing at all) is completely unaffected.
                if dst.fcc_gate is not None and not dst.fcc_gate.tick(hot is not None):
                    contributions = []

                dst.deliver(mix(contributions) if contributions else SILENCE)

    # ── run loop (paced 20 ms clock, mirrors the AudioPump cadence) ───
    def start(self) -> None:
        with self._lock:
            if self._running:
                return
            self._running = True
            self._thread = threading.Thread(target=self._run, name="matrix-tick",
                                             daemon=True)
            self._thread.start()

    def stop(self) -> None:
        with self._lock:
            self._running = False
        t = self._thread
        if t is not None:
            t.join(timeout=1.0)

    def _run(self) -> None:
        period = FRAME_MS / 1000.0
        next_tick = time.monotonic()
        while self._running:
            self.tick()
            next_tick += period
            sleep = next_tick - time.monotonic()
            if sleep > 0:
                time.sleep(sleep)
            else:
                # Fell behind — resync rather than spiral (survey risk #2:
                # per-leg latency instrumentation hangs off this drift).
                next_tick = time.monotonic()

    # ── audit helper ─────────────────────────────────────────────────
    def _audit(self, event_type: str, detail: dict) -> None:
        if self._on_audit is not None:
            try:
                self._on_audit(event_type, detail)
            except Exception:
                pass   # an audit failure must never break the matrix
