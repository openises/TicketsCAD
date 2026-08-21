# FCC §97.119 Station-ID Compliance (DMR/BrandMeister radio widget)

**Audience:** administrators and dispatchers using the DMR radio widget on an
amateur-radio-linked channel. **Phase 148** (2026-08-20), closing
`specs/SPEC-STATUS.md` section B3 and
`specs/phase-85e-fcc-station-id/spec.md`.

## The legal requirement, in one sentence

47 CFR §97.119: every amateur radio station must transmit its assigned
callsign **at the end of each conversation** and **at least every 10 minutes
during** one. The regulated unit is the **conversation**, not the individual
transmission — a brief pause for the other party to answer is part of the
same conversation, not the end of it.

## What this feature does

TicketsCAD's DMR/BrandMeister radio widget (the floating widget opened from
the RADIO button, used for TG 3127 or any other BrandMeister-linked
talkgroup) now tracks and displays each dispatcher's own station-ID timing,
and — depending on the channel's configured enforcement level — reminds or
requires the operator to include their callsign.

**It does not, and cannot, transmit an ID on the operator's behalf during a
live human PTT.** There is no speech-to-text confirmation in this phase (see
[Whisper-based detection](#future-whisper-based-id-confirmation) below), so
the software has no way to know what the operator actually said. What it
*can* do:

- Track when each operator last confirmed an ID, per channel.
- Show a live countdown and a colored bar (green / yellow / red).
- Let the operator fire a standalone **Monitoring ID** or a closing
  **End conversation** ID — real transmissions the bridge actually sends.
- Warn (soft mode) or require an acknowledgment (hard mode) when a
  transmission's timer has expired.
- Ask the operator, after a transmission made while the timer was in the
  yellow/red zone, whether that transmission included their callsign — and
  only record it if they say yes.

## Setting your callsign

Every dispatcher who will transmit on an amateur channel needs their own
callsign on file: **Profile → Callsign**. This is the same `user.callsign`
field used elsewhere in the system. If it's blank, the Push-to-Talk button
is disabled with an explanatory tooltip — the software cannot attribute a
transmission to an operator it doesn't know the callsign of.

Per FCC rules, the **operator's own callsign** identifies the transmission,
not the bridge's or the club's repeater callsign — even though the physical
DMR hotspot/repeater connection is shared across every dispatcher who uses
the widget.

## The widget

Opening the radio widget on a channel with station-ID enforcement on shows
a panel between the transport controls and the Push-to-Talk button:

```
Callsign: N0NKI                Conversation: 1:42 elapsed
[████████████░░░░░░░] 5:28 left
[🎙️ Monitoring ID]   [🛑 End conversation]
```

- **The countdown bar** is anchored to the time of your *last confirmed
  station ID* on this channel — never to the time of your last transmission,
  and never to when the current conversation started. Green under 80% of
  the interval elapsed, yellow from 80–100%, red past 100%.
- **Red does not mean a violation occurred.** It means your *next*
  transmission, if you make one, needs to include your callsign. Staying
  silent past the red mark is completely legal — the timer is not a
  background alarm and the widget never nags you to come back on the air.
- **Conversation: elapsed** is purely informational — it shows how long
  since you (or the widget infers) started this exchange. It does not
  control compliance.
- **🎙️ Monitoring ID** fires a standalone `"<CALLSIGN> monitoring."`
  transmission and resets your timer. Use it any time — before your first
  transmission of a shift, to proactively announce you're on frequency, or
  to reset the window if you know you'll be quiet for a while and want to
  transmit again later without re-explaining yourself.
- **🛑 End conversation** closes out the current conversation. If your most
  recent transmission already included your callsign, this just clears the
  marker. If it didn't, it fires a standalone `"<CALLSIGN> clear."` closing
  ID for you.
- **The banner** ("Include your callsign in this transmission") appears
  only when the timer is in the yellow or red zone — never on every
  transmission, and never as a popup that interrupts you mid-sentence.

### After a transmission

If you release Push-to-Talk while the timer was in the yellow or red zone,
the widget asks: *"Did that transmission include your callsign?"* Answer
honestly — Yes records the ID and resets your timer; No (or dismiss) leaves
the timer as it was, so the next transmission will ask again. There is no
speech-to-text check behind this; it's a direct question, not an assumption.

## Enforcement levels (per-channel, admin-configured)

**Settings → Communications & Integrations → DMR → (edit a channel) →
Station-ID enforcement.** Every DMR channel today is BrandMeister-linked
amateur RF (see `inc/channel_registry.php`'s `dmr_bm` adapter — always
`regulatory_class=amateur`), so this applies to every configured channel:

| Level | Behavior |
|---|---|
| **Off** | No countdown, no panel, no gating. Reserved for a future channel that isn't real RF-linked amateur traffic (e.g., a private simplex test network). |
| **Soft** (default) | Countdown + banner only. Never blocks a transmission. |
| **Hard** | When the timer has expired, pressing Push-to-Talk shows a confirmation dialog ("Your last station ID was N minutes ago. Include your callsign, `<CALLSIGN>`, in this transmission. Continue?") before the transmission proceeds. |

**"Hard" never silently blocks a transmission the operator insists on
making.** This is a deliberate design choice, not an oversight: an
automated system that could refuse to transmit during an actual emergency
is a worse outcome than an occasional missed ID, and both the original
design spec for this feature and the `fcc-amateur-station-id` skill this
build follows are explicit that station-ID enforcement must never suppress
legitimate traffic. "Hard" mode is a forced acknowledgment, never a
server-side refusal.

**Station-ID interval** is also configurable per channel, capped at 600
seconds (FCC's 10-minute regulatory maximum). An administrator may set it
lower for a safety margin; the software refuses to accept a value above 600.

## The "AMATEUR" badge on the Communications Console

The Communications Console (`console.php`) has always shown an "AMATEUR —
ID required" badge on any amateur-classified channel strip. As of this
phase, that badge also carries a small live status dot (grey/green/yellow/
red) reflecting the logged-in operator's own real countdown state on that
channel, sourced from the same status the radio widget uses. Hovering the
badge shows the detail in its tooltip.

## What's tracked, and where

| Table | Purpose |
|---|---|
| `dmr_channels.id_interval_seconds` / `.id_enforce` | Per-channel policy (admin-configured). |
| `dmr_id_log` | Append-only log of every station-ID event: who, when, and how (a confirmed live transmission, a Monitoring ID, or an End-conversation closing ID). This is the *only* source of "when did this operator last ID" — always computed live as `MAX(id_at)`, never cached. |
| `dmr_ptt_state` | Informational per-(channel, operator) bookkeeping: last transmission time and whether a conversation is currently considered open. Neither field feeds the compliance calculation — only `dmr_id_log` does. |

Every station-ID event is also recorded in the audit log
(`action = 'fcc_id'`), viewable by administrators through the standard
audit log viewer, with the callsign, channel, and source (confirmed
transmission / Monitoring ID / End-conversation ID).

## Design notes for anyone extending this

- The timing model is documented in full, with the reasoning behind every
  rule, in `inc/fcc_station_id.php`'s own docblock — read that before
  changing any of the countdown/zone/compliance logic. It follows the
  `fcc-amateur-station-id` internal skill precisely: the regulated unit is
  the *conversation*, the 10-minute clock is anchored to the last
  *confirmed ID* (never last transmission, never conversation start), and
  the clock creates an obligation on the operator's *next* transmission —
  never a background alarm that fires during silence.
- `api/dmr-station-id.php` is the single endpoint behind all of this:
  `?action=status` (read), and `confirm_tx` / `monitoring_id` /
  `end_conversation` (the three real actions, RBAC-gated on the existing
  `action.dmr_transmit` permission, CSRF-protected).
- The system-generated TTS paths (`inc/weather_alerts.php`'s weather
  bulletins, `inc/radio_ai_client.php`'s AI-drafted responses) already
  handled their own station-ID suffix before this phase and are unchanged
  by it — they're a different case (a one-shot automated announcement is
  itself a complete one-transmission conversation) from the live-human-PTT
  gap this phase closes.

## Future: Whisper-based ID confirmation

The original design for this feature (`specs/phase-85e-fcc-station-id/spec.md`,
step 85e-5) always planned a later phase where the existing Whisper/Vosk
speech-to-text pipeline (already used for DMR receive transcription) would
confirm whether a live transmission's audio actually contained the
operator's callsign, replacing the current self-report prompt with a real
check. That phase has not been built — it's flagged as future work, not a
silently-dropped requirement.
