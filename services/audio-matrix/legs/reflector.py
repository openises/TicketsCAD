"""
ReflectorLeg — the leg for a pure-hub channel with no external transport of
its own (Phase 152, found while wiring the dispatcher intercom onto the
audio matrix).

matrix_core.py's own docblock is explicit that its single-hop design does
NOT re-inject a destination's mixed output back into that same channel's
inbound: "It does NOT re-inject into D's inbound, so audio never makes a
second hop." That is exactly right for every channel that has a REAL leg
(DMR, a future Zello/SIP leg) — those channels' inbound is fed
independently by their own transport, so a route INTO them (e.g. a
dispatcher's talk route keying the real radio) and a route OUT OF them
(e.g. another dispatcher monitoring it) are two genuinely different signals
carried by the SAME channel object, and re-injecting would be a real,
audible feedback path.

`intercom_dd:main` (and any future pure "everyone patched to one shared
channel, no radio/PSTN underneath it" hub) is the one case where that
reasoning does NOT hold: it has NO leg loading it with independent
content (services/audio-matrix/service.py's load_channels() leaves
Channel.leg = None for every adapter with no attach_*_leg() step), so
under the plain single-hop model a talk route INTO it is silently
discarded (Channel.deliver() no-ops with leg=None) and a listen route OUT
of it is permanently silent — the "party line" a 5-persona review
explicitly chose for this feature (over a phone-directory model) could
never actually carry audio between two dispatchers. This was a real,
shipped-but-non-functional gap: the intercom_dd catalog entry, its
always-present channel row, and its leaf rules refusing to ever be patched
elsewhere all landed and tested cleanly, because none of those tests
proved audio actually reaching a second listener through the hub.

ReflectorLeg closes that gap the smallest way that preserves the
single-hop safety model rather than replacing it: whatever the matrix
computes as this channel's own mixed OUTPUT (from routes routed INTO it)
is fed straight back in as this channel's own INPUT for the NEXT tick.
This is not a second transport and not a new hop through some OTHER
channel — it is the same channel id leaving and re-entering by
construction, one 20ms tick later, which is:
  - Safe from runaway feedback: a self-route (channel_id -> itself) is
    already structurally rejected by MatrixCore.add_route() ("self-route
    (src == dst) is not allowed"), so nothing can amplify a reflected
    frame by routing it back into the SAME reflection again.
  - Bounded, disclosed latency: exactly one 20ms tick, the same order of
    magnitude as every other jitter-buffer hop in this design, not a
    growing delay.
  - A KNOWN, deliberately NOT-fixed limitation, not a silent one: a
    dispatcher who is BOTH talking on the intercom AND has their own
    listen route to it will hear a one-tick-delayed copy of their own
    voice (the hub has no per-listener "everyone except the speaker"
    view — every listener's route reads the SAME single reflected
    stream). Real per-listener self-exclusion needs either N parallel
    per-destination mixes at the hub or restructuring the single-hop
    model, both real architecture work out of scope for what this fix is
    -- making the party line carry audio AT ALL. Flagged for a later pass
    if it proves to matter in practice; the workstation-mute feature this
    same session builds elsewhere addresses a DIFFERENT and unrelated
    problem (acoustic room echo between two separate physical
    workstations), not this same-process digital reflection case.
"""

from __future__ import annotations


class ReflectorLeg:
    def __init__(self, core, channel_id: str):
        self.core = core
        self.channel_id = channel_id

    # matrix -> leg: the channel's own freshly-computed mix, fed straight
    # back in as this channel's own next-tick input.
    def outbound(self, frame: bytes) -> None:
        self.core.inbound(self.channel_id, frame)
