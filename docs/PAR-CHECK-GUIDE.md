# Personnel Accountability Report (PAR) Check — User & Implementation Guide

**Audience:** Dispatchers, mobile units, IC / Safety officers, system
administrators. Some sections assume some emergency-services
background — they're marked "(operational)" so admins can skip.

**Last reviewed:** 2026-06-12 — covers everything through Phase 27.

---

## What a PAR check is (operational)

A **Personnel Accountability Report** is a structured roll-call that
the Incident Commander (IC) or dispatcher uses to confirm every member
of every assigned crew is accounted for and safe. PAR checks are how
emergency services prevent the worst-case scenario: a firefighter
trapped in a collapsing structure while everyone outside assumes
they're "somewhere else on the scene."

A PAR has two outcomes:

- **PAR OK** — every crew has been contacted and reports all members
  present and safe.
- **PAR FAIL** — at least one crew did not report, or reported a
  member missing/injured. This triggers a Mayday.

PAR checks are triggered:

1. **On demand** by the IC ("All units, PAR")
2. **On a schedule** ("PAR every 20 minutes during interior ops")
3. **On a triggering event** (collapse, flashover, missing-person
   report, evacuation horn, change of operational period)

## Industry background

There is **no single legally-binding standard** that prescribes exactly
when to run a PAR. The closest references in the U.S. volunteer fire
service are:

- **NFPA 1500** (*Standard on Fire Department Occupational Safety,
  Health, and Wellness Program*) — requires a fire department to
  implement an accountability system. It does **not** prescribe a
  specific cadence.
- **NFPA 1561** (*Standard on Emergency Services Incident Management
  System and Command Safety*) — requires the IC to perform
  accountability checks at "regular intervals" without defining the
  interval.
- **OSHA 29 CFR 1910.134** ("two-in/two-out") — applies to IDLH
  atmosphere entry, not a PAR cadence per se.
- **FEMA / USFA ICS** training material — examples typically cite 20
  to 30 minutes during structural firefighting interior ops, but
  acknowledges the IC sets the cadence.

In practice, agencies adopt their own SOPs, typically expressed as
"PAR every N minutes during *X type of operation*." Twenty minutes
during interior structural fire is the most-cited convention; for
wildland, EMS standby, RACES net coverage, and CERT canvasses the
cadence is much longer or only triggered on events.

**TicketsCAD does not pick a cadence for you.** Each agency configures
the cadence that matches its SOP. We default to 20 minutes only
because that's a common starting point — adjust freely.

## Per-unit timer model (Phase 31, 2026-06-12)

PAR cadence is **per-unit**, not per-incident. Each assigned unit has its
own clock; the alarm fires when the **first** unit's clock reaches the
configured cadence.

### What resets a unit's clock

A unit's PAR timer resets to "now" on any of these events:

1. **Status transition** to a status whose admin-configured "Resets PAR
   Timer" flag is on. By default the migration seeds this flag for any
   status whose Incident Action is `Dispatched`, `Responding`, or
   `On Scene`. Admin can toggle for any other status in
   **Settings → Unit Statuses → Resets PAR Timer**.
2. **Successful PAR ack** for that unit (mobile self-ack or
   dispatcher ack-on-behalf).
3. **Status change** in general — if the unit's current `un_status`
   row has Resets PAR=on, `responder.status_updated` counts.

### What does NOT reset a unit's clock

- Acks by other units in the same cycle (each unit acks for itself).
- A dispatcher initiating a PAR cycle (the cycle is a *check*, not a
  reset; only individual acks reset).
- Status transitions to statuses where Resets PAR=off (e.g. Out of
  Service, At Facility, In Quarters by default — admin can change).

### How the incident-level alarm fires

For each uncleared assigned unit on an incident:

  `unit_next_due = unit_last_reset + cadence_minutes`

The incident's overall `next PAR due` is:

  `MIN(unit_next_due)` across all uncleared assigned units

When that minimum hits, an urgent broadcast goes out via the
notification tray (Phase 29B) demanding accountability for all
assigned units. Each unit acks individually; their clock resets on
their own successful ack.

### Worked example

| Time | Event | TA timer | TC timer | First-due |
|---|---|---|---|---|
| 10:00 | TA dispatched | 10:00 | — | — |
| 10:02 | TC dispatched | 10:00 | 10:02 | TA at 10:20 |
| 10:08 | TA on-scene | 10:08 | 10:02 | TC at 10:22 |
| 10:15 | TC on-scene | 10:08 | 10:15 | TA at 10:28 |
| 10:28 | TA timer expires → broadcast | 10:08 | 10:15 | now |
| 10:30 | TA acks PAR | 10:30 | 10:15 | TC at 10:35 |
| 10:35 | TC timer expires → broadcast | 10:30 | 10:15 | now |

Assumes 20-minute cadence. The first unit to expire drives the
broadcast; each ack resets only that unit.

## Design decisions specific to TicketsCAD

A few choices that depart from anything written down in NFPA / FEMA
documents:

1. **Configurable hierarchy of cadence.** Agency default → per
   incident type → per incident → in-cycle override. The IC can change
   cadence mid-incident without rewriting the SOP. See [Configuration](#configuration)
   below.
2. **Mobile self-ack.** Units with the TicketsCAD mobile app can ack
   themselves via a floating banner. The model assumes "if your phone
   is working you are presumably alive and reachable" — saves the
   dispatcher from having to roll-call by radio.
3. **Dispatcher ack-on-behalf with channel + transcript.** When a unit
   replies by voice radio, phone, or in person instead of via the
   mobile app, the dispatcher opens an "Ack on behalf" form and records
   *what the unit said*. The transcript lands in the audit log and in
   the per-unit ICS-214. Phase 27A (2026-06-12).
4. **Mayday auto-trigger** if any unit is still pending past the
   escalation threshold (default 2 missed cycles). A separate
   always-visible Mayday button at the top of every page is the manual
   counterpart.
5. **Standby units are excluded** from PAR by default — they're not
   committed to the incident, so demanding an ack is noise.
6. **Multi-agency awareness** — every PAR cycle is replicated to any
   linked major-incident or mutual-aid record so other agencies see
   the same picture.
7. **No "official" experience-based cadence.** We considered "ramp
   cadence based on rookie/veteran ratio" — we couldn't find any
   published precedent for it and tabled it. If your agency develops
   one, talk to Eric.

---

## Dispatcher workflow

### Manual PAR-now

1. On the **Incident Detail** page for the active incident, the PAR
   card is on the right column, below the map. (The header also shows
   a **PAR active** badge with the next-due countdown so you can see
   PAR status from anywhere on the page.)
2. Click **Initiate PAR**. The system:
   - Creates a new `par_cycles` row
   - Creates one `par_unit_acks` row per assigned unit (excluding
     standby), all in `pending` state
   - Emits an SSE event so every connected client (including mobile
     units) sees the prompt instantly
   - Plays a tone on mobile-app screens
3. As units acknowledge — via the mobile app, voice, phone, or in
   person — the table updates. Each unit shows its state badge
   (pending / acked / missed / aborted).

### When a unit replies by voice over radio

1. The unit is still showing **pending** in the ack table.
2. Click **Ack on behalf** on that unit's row. The
   acknowledgement modal opens.
3. Fill it in **based on what they said**:

   | Field | Use it for |
   |---|---|
   | **How did they reply?** | Voice over radio · Phone call · In person · Other |
   | **Personnel accounted for** | The number they reported ("4 personnel all OK") |
   | **What did they say?** | Verbatim if practical: `"Tanker-2, 4 personnel all OK, working west flank"` — goes into the audit log AND the after-action ICS-214 |
   | **Private dispatcher notes** | Internal only — e.g. `"voice sounded strained — flag for follow-up"` |

4. Click **Record acknowledgement** (or **Ctrl+Enter** anywhere in
   the modal — dispatcher-first keyboard).
5. The row flips to **acked** and shows a channel badge
   (`voice radio`, `phone`, etc.) so an after-action review can see at
   a glance which units self-acked vs were dispatcher-acked.

### When a unit fails to reply

1. The cycle's first-window timer (default 60 seconds, configurable)
   expires; the unit's state stays `pending`.
2. After the retry-window (default 120 seconds) the unit flips to
   `missed`.
3. After **`escalate_after_misses` consecutive missed cycles**
   (default 2), the system auto-triggers Mayday for that unit.
4. The dispatcher can manually convert `pending` to `aborted` (e.g.
   if the unit has been confirmed by other means) by clicking the
   row — the modal lets you change the channel to "Other / dispatcher
   initiative" and add a note.

### Mayday

- A **Mayday** button is visible at the top of every screen. It does
  NOT require PAR to be enabled. Clicking it broadcasts to every
  connected client, plays a distinct tone, and freezes the incident
  status to "Mayday in progress."
- The auto-Mayday triggered by PAR escalation behaves identically.

## Mobile unit workflow

1. While at least one mobile-app session is open, the unit can be
   acked from the floating PAR banner that appears at the bottom of
   every page when a PAR cycle is initiated.
2. The banner has a single big **PAR OK** button + a **Comments**
   field. The button ack reports the assumed member count
   (last-known headcount). If the member count has changed, type the
   correct number first.
3. The banner stays visible until the dispatcher closes the cycle.

## Configuration

### Cadence resolution hierarchy

When the scheduler decides the next PAR for an incident, it walks five
layers from most-specific to most-generic. The first layer that has a
value wins:

| Priority | Source | UI location |
|---|---|---|
| 1 (highest) | Per-incident override (set on the incident itself) | Incident Detail → PAR card → "Override" input |
| 2 | Per-incident-type cadence | Settings → Incident Types → "PAR Cadence Override (min)" |
| 3 | Agency default (`par_config.scope='agency_default'`) | Settings → PAR Checks → Agency Default |
| 4 | System setting `par_default_cadence_min` | Settings → PAR Checks → Defaults |
| 5 | Hardcoded fallback (20 min) | Code |

A cadence of `0` at any level means "disable PAR for this scope."

### Useful settings (admin → Settings → PAR Checks)

| Setting | Default | What it does |
|---|---|---|
| `par_default_cadence_min` | 20 | Layer-4 fallback in minutes |
| `par_first_window_s` | 60 | How long to wait for first ack before warning |
| `par_retry_window_s` | 120 | Extra time before flipping unit to `missed` |
| `par_max_misses` | 2 | Escalate to Mayday after this many consecutive misses |
| `par_escalation_chat_channel` | (empty) | Chat channel that gets a PAR-failure broadcast |

### Enabling PAR system-wide

PAR features are **off by default** (`par_enabled` setting). The
top-of-page **Mayday** button works regardless; the per-incident PAR
card, scheduler cron, and mobile banner all require the master switch.
Enable in **Settings → PAR Checks → Enable PAR features**.

When PAR is disabled, the PAR card on every incident shows an
activation hint instead of going invisible — admins shouldn't have to
guess where the master switch is.

## Scheduler

The PAR scheduler runs as a cron task hitting
`tools/par_tick.php`. Recommended schedule: every minute. The
scheduler:

- Iterates every open incident with PAR enabled
- Computes `par_due_at` for each
- For incidents past due: initiates the next cycle
- For cycles past first-window: warns
- For cycles past retry-window: marks units `missed`
- For units at `par_max_misses`: auto-triggers Mayday

The scheduler logs every action to `audit_log` so an after-action
review can reconstruct exactly when each prompt fired.

## After-action: ICS-214 export

Every PAR ack (including `comments` from the radio transcript) flows
into the per-responder ICS-214 export at
**Unit Detail → ICS-214 dropdown → Download XML (last 24h / 7d)**.

The exported XML is Winlink-compatible. Each entry contains a
timestamp + activity description in the canonical ICS-214 activity-log
format. The export bundles three data sources for the chosen
responder:

- PAR acks (`par_unit_acks` rows in the date range)
- Dispatch / response / on-scene / clear timestamps (`assigns`)
- Free-form action log entries authored by the responder's user
  account

If a non-admin user clicks the export they get **only their own
responder's log**. Admin can export any responder.

## Audit log

Every action — initiate, ack (with channel + transcript), missed,
escalate, Mayday — writes a row to `audit_log` with category `par`,
target `incident_id`, summary text, and JSON details. The events live
forever (no automatic pruning) and feed the after-action review.

## Multi-agency

If an incident is linked to a major-incident record (Phase 16e), every
PAR cycle posted on a child incident also fires on the parent so a
mutual-aid agency sees the same accountability picture without
double-acking. The parent incident's PAR card shows the rollup status
across all child incidents.

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| "I clicked Initiate PAR but nothing happened" | Master switch (`par_enabled`) is off. Activate in Settings → PAR Checks. |
| "Mobile units aren't getting the prompt" | SSE connection. Check System Health → SSE indicator at top-right of navbar — green dot = good. |
| "Header badge says PAR active but no card on the right" | Browser may not have refreshed after the page initialized. Hard refresh (Ctrl+F5). |
| "I set cadence on an incident type but it isn't applied" | The override hierarchy resolves at *cycle time*, not at incident creation. Cycle has to start after the override is set. |
| "Ack on behalf modal says 'cycle not found'" | Cycle was already closed (success or abort). Refresh the PAR card. |
| "An out-of-service unit shows up in PAR" | Mark it `Standby` (Unit Statuses with `incident_action='' `) instead of dispatched. Standby is excluded by default. |

## File pointers (for developers maintaining this)

| Surface | Path |
|---|---|
| PAR engine helpers | `inc/par.php` |
| PAR API endpoint | `api/par.php` |
| Scheduler cron | `tools/par_tick.php` |
| Mobile floating banner | `assets/js/mobile-par.js` |
| Dispatcher panel + ack modal | `assets/js/incident-detail.js` |
| Header badge | `incident-detail.php` (search `parHeaderBadge`) |
| ICS-214 exporter | `api/ics214-par-export.php` |
| Settings panel | `settings.php#par-checks` |
| Schema migrations | `sql/run_phase16*_par_*.php` |
| Original design spec | `specs/phase-16-par-checks-2026-06/spec.md` |

## Change log

| Date | Phase | What changed |
|---|---|---|
| 2026-06-12 | 27A | Dispatcher ack-on-behalf modal with channel + transcript + private notes. Replaces naive browser `prompt()`. |
| 2026-06-12 | 27B | PAR-active header badge on Incident Detail with cadence + next-due countdown. |
| 2026-06-12 | 27C | This guide. |
| 2026-06-11 | 26A | Per-incident-type cadence UI exposed. Personal ICS-214 export from PAR acks. |
| 2026-04-05 | 16e | Mayday auto-trigger + multi-agency + standby decisions. |
| 2026-04-05 | 16d | Per-incident override + reports. |
| 2026-04-05 | 16c | Mobile floating banner ack + comments. |
| 2026-04-05 | 16b | Scheduler cron + cycle-window enforcement + escalation. |
| 2026-04-05 | 16a | Initial PAR schema + dispatcher manual PAR-now + ack UI. |

---

## Questions for your agency before you go live

Walk through this with whoever owns your SOP — these are the decisions
TicketsCAD makes you make:

1. **What's your PAR cadence**, per operation type? (Suggested
   starting points: 20 min interior fire, 60 min wildland, 30 min
   EMS standby, 60 min RACES net, on-event only for CERT canvass.)
2. **Should mobile self-ack count?** Some agencies require *the
   dispatcher* to ack every unit by voice. If so, disable mobile
   self-ack in Settings → PAR Checks.
3. **First-window + retry-window** — 60s/120s are reasonable
   defaults but a noisy fireground may need longer.
4. **`par_max_misses` before Mayday escalation.** Default 2. Set to
   1 if your agency wants instant Mayday on first miss.
5. **Channel for PAR failure broadcasts.** Pick a chat channel
   (`par_escalation_chat_channel`) so anyone scrolling can see when
   PAR fails. Empty = post nowhere (just the audit log).
