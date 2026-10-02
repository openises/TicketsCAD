# Communications Console — Operator & Admin Guide (Phase 152 rebuild)

This is the operator-facing guide to the rebuilt Communications Console
(`console.php`). It covers what a dispatcher and an admin can actually do
today. For the competitive research and design rationale behind these
choices, see `docs/COMMS-CONSOLE-RESEARCH.md` and
`specs/phase-152-comms-console-v2/{spec.md,plan.md,tasks.md}`.

Everything in this phase ships **off by default** on every install — a
solo or 1-2 person organization can use the console exactly as before and
never see most of what's described here.

**Admin note:** sections 2 and 6 (real per-channel audio, the dispatcher
intercom) require the `services/audio-matrix/` Python daemon to be
installed and running with its browser leg live — see
`docs/AUDIO-MATRIX-SETUP.md` for that installer. Positions (section 5) and
workstation-mute pairing (section 7) are pure PHP/DB and work with no
Python service at all.

## 1. The strip bank

Each enabled channel (DMR, Zello, the dispatcher intercom, text channels)
gets one strip. An admin authors which controls each strip shows — its
width, hotkey, and label/color — in the **Console Designer**
(`console-designer.php`, linked from the toolbar when you hold
`console.design`, or "My Views" for a personal layout with no admin
permission needed).

Every strip can show, depending on its template and the channel's
capabilities:

- **Select** — this is "my active channel" for keyboard/hardware PTT and
  gain purposes.
- **Monitor** — hear this channel at a reduced level even when something
  else is selected (on by default; turn off to fully silence a channel
  you don't want to hear at all right now).
- **Mute** — always wins over Select/Monitor.
- **Volume** — a per-strip level slider.
- **PTT** — see section 2.
- **A per-strip hotkey** — an admin-assigned key (F1-F12 or a single
  character) that toggles Select on that one strip.

## 2. Real per-channel audio ("Matrix Audio" / "Join Intercom")

Today, DMR (`dmr_bm`/`dmr_local`) and the dispatcher intercom
(`intercom_dd`, see section 6) can each carry **real, independent
per-channel audio** through the audio-matrix service — meaning two
different DMR talkgroups (or the intercom and a DMR channel) can be
monitored and keyed completely independently, at independently
controlled levels. Zello does not have this yet and stays a **launcher**
(a dashed-outline button that opens the shared Zello widget) — a
deliberate scope decision (see plan.md), not an oversight.

A strip with real audio available shows a checkbox:

- **DMR strips**: labeled "Matrix Audio." Off by default — checking it
  connects your microphone (a real permission prompt, only on this
  explicit click, never automatically) and switches that ONE strip from
  the shared Radio widget to its own independent audio path. Unchecking
  it tears the connection down and returns to the shared widget.
- **The dispatcher intercom strip**: labeled "Join Intercom" instead,
  since there's no legacy widget to fall back to — this is the only way
  onto that channel at all.

Once engaged, the strip shows a real, held-down PTT button (not a click)
— the same kind of control as the simulselect master button. A **red
disconnect banner** appears if the underlying session drops, and a short
beep confirms an actual transmission started (never merely that you
clicked — see the strip's own fail-loud design in plan.md's Prerequisite
7).

## 3. The patch rail and group coupling

Any `screen.console` holder with `action.patch_create` can create a
**same-class** patch between two channels — select two strips' patch
checkboxes and a confirmation bar appears at the top. **Cross-class**
patches (bridging amateur and commercial/PSTN audio) need
`action.patch_cross_class` and require an audited, time-boxed
acknowledgment — never a silent bridge across FCC-regulated boundaries.

Selecting **3 or more** channels creates a **group** instead of a single
patch — one named object with one shared expiry and one-click teardown,
rather than three separate pairwise patches drifting apart.

**Known gap, flagged rather than silently accepted (found 2026-09-08, a
persona review specifically covering mixed-mode DMR/Zello bridging):**
the patch rail's cross-class guard above covers STANDING patches only.
The Group Transmit ("Simulselect") widget — the small navbar dropdown
described in §5 below — lets an operator manually check ANY combination
of transmit-capable channels and key them together with one PTT press,
completely independent of the patch rail, with no cross-class check and
no audit entry of its own. Because Zello's own `regulatory_class` ships
`internal` (the same tier as this console's internal dispatch traffic,
never a member of any blocked pair), a DMR talkgroup and a Zello channel
checked together in Group Transmit are never flagged either way — by the
patch rail's rules (which never see this path at all) or by anything
else. Today this is the operator's own judgment call, the same as a
human manually keying two separate radios at once, which is legal,
ordinary dispatch practice — but unlike the patch rail's own standing
bridges, there is deliberately no acknowledgment step or audit trail
here yet, and whether Group Transmit should get one (and whether Zello's
`internal` classification is correct for THIS kind of momentary, human-
driven combination, as opposed to a standing automated bridge) is an
open design question, not yet decided. Flagged in `specs/phase-152-
comms-console-v2/tasks.md` for a real decision before this ships beyond
dev-only.

The patch rail (a persistent bar above the strip bank, live via SSE — it
never polls) shows every active patch/group, who created it, remaining
time, and a one-click break. It's hidden entirely until at least one
patch exists.

**The dispatcher intercom (`intercom_dd`) can never be patched or
coupled to anything else, by design** — it's a dispatcher-only party
line, never a mixing-bus member, with no override of any kind. Attempting
it is always refused, at both the persistent-patch layer and the
ephemeral browser-leg layer.

## 4. Recall — catching up, not working an active incident

The **Recall** button opens a secondary panel (nothing loads until you
open it) showing recent activity across every channel and mode,
chronological within each group, with a per-channel "Replay last"
button for voice-capable channels. This is explicitly a catch-up tool,
not a live working surface — it doesn't auto-refresh and it's not where
you'd want to sit during an active incident.

Every strip also has its own one-press **Replay last** shortcut for the
same purpose, scoped to just that channel.

## 5. Positions — named seats (optional)

An admin can define named **positions** ("Dispatch 1", "Net Control") on
`console-positions-admin.php` (`action.manage_positions`), each with its
own working channel set. Any `screen.console` holder can log into any
position from the console page's own position bar — there's no
reservation and no forced takeover; logging into a seat someone else is
already in is allowed and shows both people present, never an eviction.

The position bar shows:

- Who's logged into which position right now (a live presence list,
  refreshed periodically — a heartbeat marks a stale entry, but nothing
  ever auto-logs anyone out).
- A **"Clear at handoff"** button that resets your position's read-state
  for its working channels to "seen as of right now" — so the next
  person sitting down doesn't inherit a false "you missed this" flag for
  traffic you already handled.

Installs with zero positions configured never see this bar at all.

## 6. The dispatcher-to-dispatcher intercom

Every install gets one always-on **Dispatcher Intercom** channel
(`intercom_dd:main`) — a shared party line for talking to another
dispatcher or position without keying a channel the field can hear. No
FCC station-ID requirement applies to it (it's internal-class), and it
can never be patched or coupled elsewhere (section 3). Join it the same
way as DMR's Matrix Audio (section 2) — the checkbox reads "Join
Intercom." Works with plain user-to-user calling even on an install with
no positions configured at all.

## 7. Workstation identity and "don't play my neighbor back to me"

If two dispatchers sit near each other, both monitoring the same
channel, one's transmission can echo back through the other's speakers —
a real acoustic problem, not a software bug. The console addresses this
with a **persistent workstation identity** (independent of who's logged
in — it survives a login change on the same browser, but not a browser
data wipe or a different device) and a **"Nearby workstations"** panel:

- Click **"Hearing yourself? Nearby workstations"** on the console's
  workstation bar to open it.
- Name your own desk (e.g., "Desk 1") so a neighbor can recognize it.
- Toggle "Don't play [neighbor]'s own transmissions to me" for the
  workstation causing the echo — this sets up the pairing in **both
  directions** in one click, since fixing the echo needs the *neighbor*
  to mute you, not the other way around.
- A persistent badge shows any active pairing so it's never forgotten
  after the initial setup.

**Acoustic auto-discovery** (if enabled — an install-wide toggle, Super
Admin only) replaces picking a neighbor by name: click "Search
automatically" and every other active workstation plays a brief,
near-inaudible tone; your own microphone listens for it and creates the
matching pairing automatically. This is a best-effort convenience, not a
guaranteed result — manual entry always works as the reliable fallback.

**Important, honest limitation:** this mute only works for a genuinely
digital, same-process audio path. It does **not** (and structurally
cannot) filter the echo you'd hear from a shared DMR/Zello talkgroup —
that's a real RF/network round-trip the software has no visibility into
once your voice leaves the matrix — nor does it currently filter the
dispatcher intercom's own shared party line (a separate, deferred piece
of work). See `specs/phase-152-comms-console-v2/tasks.md`'s own detailed
write-up if you need the full technical reasoning.

## 8. Physical PTT (foot switch / gamepad)

A connected USB foot switch that presents as a simple gamepad (button 0)
keys whichever currently-**selected** strip has real audio engaged
(section 2) — press and hold to transmit, release to stop. A keyboard
fallback (the backtick/grave key, deliberately not Space or an F-key,
both already used elsewhere on this page) does the same thing for pedals
that emulate a keystroke instead, or if you have no pedal at all.

If nothing selected has real audio to send, pressing either shows a
brief on-screen notice rather than doing nothing silently — so you know
the press registered but there's nothing to key yet.

**Building your own pedal:** `hardware/foot-switch-ptt-digispark/` has a
complete DIY build guide (parts list, wiring, an Arduino sketch, and
troubleshooting) for a $2-5 Digispark-based USB gamepad pedal, plus
purchasing guidance for an off-the-shelf alternative if you'd rather not
build one.

## 9. Who can do what (RBAC summary)

| Action | Permission | Default holders |
|---|---|---|
| View/use the console | `screen.console` | Every operator role |
| Create a same-class patch/group | `action.patch_create` | Dispatcher and above |
| Create a cross-class patch | `action.patch_cross_class` | Org Admin and above |
| Manage positions (admin) | `action.manage_positions` | Org Admin and above |
| Manage the full audio matrix | `action.manage_matrix` | Org Admin and above |
| Toggle acoustic-discovery install-wide | `action.manage_config` | Super Admin only |
| Design shared console views | `console.design` | Org Admin and above |
| Build a personal console view | *(none — any `screen.console` holder)* | Everyone |

Renaming your own workstation or setting a mute pairing involving your
own current workstation needs only `screen.console`; doing either to a
workstation you don't currently occupy needs `action.manage_positions`.
