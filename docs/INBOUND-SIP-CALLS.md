# Inbound SIP/PBX Call Integration (Phase 149)

**Audience:** administrators (setup) and dispatchers (the "Using it" section).
**Status:** fully built, off by default. An install with zero trunks configured
shows no new UI and runs no new background activity.

## What it is

When an agency's phone system (a SIP trunk or PBX — FreePBX/Asterisk, 3CX, a
hosted SIP provider) receives an inbound call, TicketsCAD shows every
qualified, logged-in dispatcher a live, hard-to-miss notice of the ringing
line. The first one to answer claims it with a single click, and that click
opens a New Incident form pre-filled with the caller's number and (once
claimed) their prior-incident history — without ever losing whatever that
dispatcher was already doing.

This is **coordination and screen-pop, not call control.** TicketsCAD never
answers, holds, transfers, or bridges the phone call itself. A companion
adapter process normalizes your PBX's native events into one canonical
webhook shape, the same "small bridge process talks to the vendor, PHP never
does" pattern this project already uses for the DMR radio bridge
(`services/bridge/`) and the Meshtastic bridge (`services/meshtastic/bridge.py`).

Full design rationale: `specs/phase-149-inbound-sip-calls/{spec.md,plan.md}`.

## Setting up a trunk

1. **Settings → Communications & Integrations → Inbound Calls → Inbound
   Calls (SIP/PBX)** (requires the "Manage Inbound Calls" permission,
   `action.manage_calls` — Super Admin and Org Admin by default).
2. Click **New Trunk**. Give it a label (e.g. "Main Dispatch Line"), pick an
   organization if this is a multi-agency install (leave blank for
   install-wide — the common single-agency case), and set:
   - **Wrap-up seconds** (default 90) — how long a call stays shown as
     "wrapping up" after the PBX reports it ended, before folding to fully
     closed. Gives the claimant time to finish the incident write-up.
   - **Reassign grace seconds** (default 20) — how long after a claim any
     OTHER qualified user may instantly "Take" it with no supervisor
     permission and no reason (see "Quick reassignment" below).
   - **Ringing tone bypasses mute** (default on) — for the overwhelmingly
     common deployment (a single volunteer agency's one emergency line), a
     missed call is worse than an unwanted loud tone. Turn this off for a
     non-emergency line where over-alerting is the bigger cost.
3. Save. The trunk's **bearer token is shown exactly once** — copy it
   immediately. If it's lost, use **Rotate Token** to mint a new one (the
   old one stops working immediately).
4. Point your adapter process at `api/sip-ingest.php` with that token as an
   `Authorization: Bearer <token>` header on every webhook.

## The adapter process (`services/sip-bridge/`)

TicketsCAD's PHP tree never speaks SIP, AMI, or ARI directly. A separate,
small process — one per PBX vendor — normalizes your PBX's native events
into ONE canonical JSON contract and POSTs it to `api/sip-ingest.php`:

```json
{
  "event": "ringing | claimed_externally | ended | abandoned",
  "call_id": "<PBX Uniqueid/Linkedid or SIP Call-ID>",
  "caller_number": "+16125551234",
  "caller_name": "CNAM string or null",
  "called_number": "<DID dialed>",
  "event_ts": "2026-08-22T14:03:11Z"
}
```

Which PBX platform(s) your first real deployment targets (FreePBX/Asterisk
AMI/ARI vs. a hosted SIP-trunk provider's own webhook shape) changes what
the adapter needs to speak — the canonical contract into TicketsCAD itself
does not change either way. Building and deploying a specific adapter is an
infrastructure decision made per-install, matching how this project already
treats the DMR bridge and Meshtastic bridge: designed and ready, deployed
only when a real target PBX/trunk exists.

`claimed_externally` is a deliberately narrow, informational-only event
(the PBX saw a physical extension answer with no TicketsCAD claim at all) —
recorded as an audit note, never changing the call's coordination state in
this phase (see "Out of scope" below).

## The scheduled sweep

`tools/inbound_calls_tick.php` runs every 15 seconds (matching the claim-
heartbeat cadence) and does two things:

- Folds a `wrapup` call to `ended` once `wrapup_seconds` has elapsed.
- Flags a `claimed` call whose heartbeat has gone quiet (three missed 15s
  beats, 45s) as **stale** — never auto-releasing the claim.

Install it as a systemd timer (Linux) — see `docs/MAINTENANCE-RUNBOOK.md`'s
"inbound SIP/PBX call sweep" section — or via `tools\run-scheduled-jobs.bat`
on Windows/Task Scheduler. Safe to enable unconditionally: zero configured
trunks means zero rows either sweep ever finds.

## Using it (for dispatchers)

### The banner

The moment a call rings, every logged-in user holding **screen.call_queue**
sees a persistent strip beneath the navbar — never a modal, never a
full-screen takeover. Multiple simultaneous ringing/claimed calls stack as
compact cards, oldest first. Click **Answer** to claim a ringing call; a
**New Incident** tab opens automatically, pre-filled with the caller's
number, with the existing constituent lookup and call-history panel already
triggered — exactly as if you'd typed the number and tabbed out.

If you can't get to the phone in time, the call moves to a **Missed Calls**
section (collapsible, count badge) instead of vanishing — click **Callback**
any time to open the same pre-filled New Incident tab, or **Review** to
clear it from the panel once you've followed up (the record itself is never
deleted).

### Quick reassignment ("Take")

Many SIP/PBX deployments let only one physical extension actually answer a
call — the hardware race can resolve differently from the CAD's software
claim. If someone else's name shows on a call you're actually holding
(or vice versa), click **Take** within the trunk's configured grace window
(default 20s) to instantly correct it — no permission beyond the ordinary
claim permission, no reason required. This is a self-correction of an
honest, mechanical race, not an override of someone else's settled work.

Once the grace window elapses, "Take" is replaced by a supervisor-gated
override that requires the "Manage Inbound Calls" permission and a typed
reason (visible in the audit trail) — overriding a colleague who is, as far
as the system can tell, still genuinely on the call is a supervisor
decision, never a peer one.

### Stale claims

If a claiming browser stops confirming it's still there (crash, lost
network, walked away) while the PBX has not reported the call ended, the
card turns amber ("Stale") for every other qualified user. Click
**Reclaim** — no reason required, since this is recovering from an apparent
technical failure, not a live dispute. The system never silently hands a
stale claim to someone else on its own.

### Keyboard shortcuts

Reachable without the mouse, matching this project's keyboard-first
convention elsewhere (the `/` command bar, arrow-key list navigation):

| Key | Action |
|---|---|
| `↑` / `↓` | Move the highlighted-call cursor among simultaneous calls |
| `A` | Claim (Answer) the highlighted ringing call |
| `T` | Take/Reclaim the highlighted claimed-by-another call (quick-reassign if fresh, low-friction reclaim if stale) |
| `Esc` | Locally acknowledge the highlighted call (e.g. you heard someone else physically pick up) — never touches the server, never affects any other user's view |

None of these fire while typing in a text field, and only while at least
one call is visible in the banner — they never contend with another page's
own shortcuts otherwise.

## Access control

Five independently grantable permissions (deliberately not a reuse of
`screen.constituents` or `action.manage_members`, neither of which means
what this feature needs):

| Code | Gates | Super Admin | Org Admin | Dispatcher | Operator |
|---|---|:-:|:-:|:-:|:-:|
| `screen.call_queue` | See the live banner at all | Y | Y | Y | Y |
| `action.claim_call` | Claim/release/quick-reassign; reclaim a **stale** claim | Y | Y | Y | Y |
| `action.manage_calls` | Reclaim an **active** claim (with reason); configure trunks | Y | Y | N | N |
| `field.caller_history` | See a claimed call's matched identity + prior-incident summary | Y | Y | Y | Y |
| `field.patient_history` | See clinical/patient detail nested in that history | Y | Y | Y | N |

The live, broadcast ring notification itself never carries a matched
identity, address, prior-incident count, or warning flag — only what a
physical caller-ID display would show (number, and which line). Identity
and history are only ever fetched, permission-checked, once a specific
user opens or claims that specific call — via the SAME two permissions
this phase retrofitted onto the previously wide-open
`api/constituents.php` / `api/call-history.php` lookups.

## Out of scope (this phase)

- **Actual call control** — TicketsCAD does not answer, hold, transfer, or
  originate calls. The claim is a software-side coordination record, not a
  command to the PBX.
- **Preventing a busy signal at the telephony layer** — ring-group/hunt-
  group configuration is a PBX concern, not something TicketsCAD's software
  layer can or should try to solve.
- **Outbound webhook subscriptions** for third parties on `call:*` events —
  the mechanism (`inc/webhooks.php`) already exists and can be extended
  later.
- **Command-bar (`/`) support** for calls.
- **Supervisor analytics** (handle-time reporting, abandonment trends) — the
  audit trail this phase builds is the foundation a later reporting phase
  would read from.
