"""
LoopbackLeg — the matrix's test double (Phase 114c).

A leg connects a channel to the outside world. The real legs (DMR, Zello,
USRP/AllStar, AudioSocket/SIP) each own their transport; this one owns
nothing — it just records every frame the matrix delivers to it and lets
a test inject frames as if they arrived from the wire. That gives the
matrix a CI harness that never needs a radio or a third-party service
(plan.md cross-cutting requirement: "every adapter gets a loopback/
fixture harness").
"""

from __future__ import annotations

from typing import List, Optional


class LoopbackLeg:
    def __init__(self, core, channel_id: str):
        self.core = core
        self.channel_id = channel_id
        self.captured: List[bytes] = []   # frames the matrix delivered to us

    # matrix -> leg
    def outbound(self, frame: bytes) -> None:
        self.captured.append(frame)

    # leg -> matrix (test injects "received" audio)
    def feed(self, frame: bytes, keyed: bool = True) -> None:
        self.core.inbound(self.channel_id, frame, keyed=keyed)

    # convenience for assertions
    def last(self) -> Optional[bytes]:
        return self.captured[-1] if self.captured else None

    def reset(self) -> None:
        self.captured.clear()
