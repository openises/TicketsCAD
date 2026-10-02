"""
fcc_gate.py — server-side FCC station-ID gate for AUTONOMOUS transmissions
(Phase 152 prerequisite #2's Python half, unblocked once prerequisite #3's
browser leg landed — see specs/phase-152-comms-console-v2/plan.md's
"Correction found during implementation" note).

SCOPE — read this before wiring a new leg to a gate. This gate applies
ONLY to audio arriving at a destination channel via the matrix's own
ROUTE table: a coupling/patch relaying one channel's speech onto another,
with no direct human PTT press on that specific destination leg. It does
NOT apply to a channel's own direct human PTT (a strip's PTT button) —
that path doesn't go through MatrixCore's routing at all today
(radio-widget.js talks to the DMR bridge directly) and is handled by the
existing CLIENT-SIDE gate (assets/js/radio-widget.js's fccGateBeforeTx(),
inc/fcc_station_id.php) which can prompt a human to confirm — this gate
cannot, because there is no human "at the mic" for an autonomously-keyed
relay leg. Mirrors fccGateBeforeTx()'s decision table with that one
difference: where the client-side gate shows a confirm() dialog for a
hard-enforced lapsed interval, this gate has nobody to ask, so it refuses
outright (comm_fcc_may_transmit()'s 'lapsed_hard' reason -> refused; the
PHP side already encodes "soft/lapsed -> allow" for exactly this reason —
see inc/comm_fcc_gate.php).

Checked ONCE PER UTTERANCE (a VOX-style live/silent transition), not once
per 20ms tick: an HTTP round-trip to api/matrix-id-check.php on every tick
would both waste the request budget and let a slow PHP response introduce
audio dropouts. The decision is cached for `recheck_secs` (default 5s)
even across a single long utterance, so a transmission that runs past an
ID interval mid-stream is still re-evaluated rather than riding out one
stale "allowed" decision for its whole duration.

Fails CLOSED: any exception talking to the check endpoint (network error,
timeout, non-200, malformed JSON) refuses the relay and logs a warning,
never silently allows an autonomous transmission whose compliance state
is unknown — the same posture this phase's other gates take (a fail-open
default here would mean "if the compliance server is unreachable, ignore
FCC part 97/90 obligations," which is exactly backwards).

This module does NOT call the endpoint's `record` action. `record` exists
for a future capability not built by this prerequisite — the matrix
itself injecting a synthesized station-ID announcement (simulcast TTS,
per plan.md) and then logging that a real ID was actually transmitted.
Nothing here generates that audio, so nothing here has a real ID event to
record; recording one now would create a comm_id_log row implying a
station ID happened when only silence-preserving relay audio did.
"""

from __future__ import annotations

import json
import logging
import time
import urllib.error
import urllib.request
from typing import Callable, Optional

LOG = logging.getLogger("audio-matrix.fcc_gate")

DEFAULT_RECHECK_SECS = 5.0


class FccRelayGate:
    """
    One instance per autonomously-relayed DESTINATION channel needing
    ID-policy enforcement (an amateur/commercial-class comm_channels row
    that a Route delivers audio into). Call tick(source_live) once per
    matrix tick with whether this destination's routed source is
    currently producing non-silent audio; it returns whether that audio
    may be relayed onto the destination's leg RIGHT NOW.
    """

    def __init__(
        self,
        channel_id: int,
        check_fn: Callable[[], dict],
        recheck_secs: float = DEFAULT_RECHECK_SECS,
    ):
        self.channel_id = channel_id
        self._check_fn = check_fn
        self.recheck_secs = recheck_secs

        self._allowed = False
        self._last_reason = "not_yet_checked"
        self._last_zone = "none"
        self._next_check_at = 0.0  # monotonic; 0 forces an immediate first check
        self.checks = 0
        self.refusals = 0

    def tick(self, source_live: bool) -> bool:
        if not source_live:
            # Utterance ended (or never started) -- force a fresh check on
            # the NEXT utterance rather than riding out this one's cached
            # decision into an unrelated later transmission.
            self._next_check_at = 0.0
            return False
        now = time.monotonic()
        if now >= self._next_check_at:
            self._recheck(now)
        return self._allowed

    def _recheck(self, now: float) -> None:
        self.checks += 1
        try:
            result = self._check_fn()
            self._allowed = bool(result.get("allowed"))
            self._last_reason = str(result.get("reason", "unknown"))
            self._last_zone = str(result.get("zone", "none"))
        except Exception as e:  # noqa: BLE001 - never let a bad check crash the tick
            LOG.warning(
                "fcc_gate: check failed for channel %s, refusing (fail-closed): %s",
                self.channel_id, e,
            )
            self._allowed = False
            self._last_reason = "check_failed"
        if not self._allowed:
            self.refusals += 1
        self._next_check_at = now + self.recheck_secs

    def health(self) -> dict:
        return {
            "channel_id": self.channel_id,
            "allowed": self._allowed,
            "reason": self._last_reason,
            "zone": self._last_zone,
            "checks": self.checks,
            "refusals": self.refusals,
        }


def make_check_fn(
    base_url: str,
    token: str,
    channel_id: int,
    regulatory_class: str,
    configured_interval_secs: Optional[int],
    enforce: str,
    callsign: str,
    timeout: float = 5.0,
) -> Callable[[], dict]:
    """
    Build the default HTTP check_fn hitting api/matrix-id-check.php's
    `check` action. Separated from FccRelayGate so tests can inject a
    canned check_fn with no network or PHP at all — mirrors legs/dmr.py's
    injectable-network-seam pattern (_open_stream/_post_audio there).
    """
    body = json.dumps({
        "action": "check",
        "channel_id": channel_id,
        "regulatory_class": regulatory_class,
        "configured_interval_secs": configured_interval_secs,
        "enforce": enforce,
        "callsign": callsign,
    }).encode("utf-8")

    def _check() -> dict:
        req = urllib.request.Request(
            base_url.rstrip("/") + "/api/matrix-id-check.php",
            data=body, method="POST",
        )
        req.add_header("Content-Type", "application/json")
        req.add_header("Authorization", "Bearer " + token)
        with urllib.request.urlopen(req, timeout=timeout) as r:  # noqa: S310 - configured base_url, not user input
            return json.loads(r.read().decode("utf-8"))

    return _check
