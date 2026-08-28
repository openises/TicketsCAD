# Changelog

All notable changes to TicketsCAD (NewUI v4) are documented here.
The format loosely follows [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Fixed

- **GH#115** — `check-schema.php --repair` could not add `org_id` to
  `facilities`/`responder`/`teams`/`newui_equipment`/`newui_vehicles` on an
  install that had never touched one of those entity types. A dedicated
  migration now adds the column for all five tables during the normal
  migration pass, with a graceful skip for a table that doesn't exist yet on
  this install.
- **GH#117** — the Zello proxy diagnostics and Settings troubleshooting panel
  gave Linux/Apache-only remedies even on Windows installs, though the
  Windows launchers (`proxy/start-proxy.bat`, `proxy/start-proxy-service.bat`)
  ship in the same repo. Diagnostics now names both platforms' real launchers,
  the WebSocket-closed-before-open message no longer blames a reverse-proxy
  misconfiguration on a direct (non-`wss://`) connection, and the Settings
  troubleshooting panel now has a Windows PowerShell equivalent alongside
  each of its four Linux commands.
- **GH#118** — clicking the X on an attached unit (Incident Detail →
  Attached Units) silently did nothing: the click handler referenced an
  out-of-scope `ticketId`, throwing a `ReferenceError` before the request was
  ever sent (the same defect class as GH#98, in the neighboring handler).
  Fixed with the same resolve-first / build-before-disable / added `.catch()`
  pattern as the GH#98 fix.

### Fixed (CI / self-hosted fresh-install pipeline)

- Three pre-existing migration-ordering gaps in the fresh-install pipeline,
  found while diagnosing GH#115's own CI run: `sql/run_00_rbac.php`'s
  `admin_only` classification could run before `sql/run_rbac_v2.php` had
  created that column; `sql/rbac.sql`'s own classification pass ran before
  three Phase 114 permission codes existed; and
  `sql/run_phase148_fcc_station_id.php` could run before
  `sql/run_phase73i_dvswitch_schema.php` had created `dmr_channels`. All
  three are silent no-ops on a fresh install (not visible failures), each
  fixed with a small, idempotent reconciliation migration.

## [4.2.26] — 2026-08-26

### Security

- **RBAC "admin-only" is now a real, structural database column
  (`permissions.admin_only`), not a hand-maintained exclusion-list string —
  closing a bug class hit five separate times.** A permission's admin tier
  (0=unrestricted, 1=Org Admin or above, 2=Super Admin only) is now
  consulted directly by every grant-writing code path: the live Roles &
  Permissions UI (`api/rbac.php`), and — the actual fix for the most recent
  incident — `sql/run_rbac_v2.php`'s canonical-alias mirror step, which
  previously copied a role's grant onto a newly-created canonical
  permission code with no regard for whether that role's tier was
  sufficient. `sql/rbac.sql`'s and `sql/run_00_rbac.php`'s broad
  "everything except" seed statements also gained a structural
  `admin_only`-based filter alongside their existing exclusion-list text.
  `tools/rbac_exclusion_leak_audit.php` gained a new, primary,
  mechanism-agnostic check against the live column plus a classification-
  drift cross-reference. See `inc/rbac_admin_only.php` and
  `tests/test_rbac_admin_only.php`.

### Removed

- **The public beta-tester sign-up form and its database table
  (`beta-tester.php`, `beta_tester_applications`) have been removed
  entirely.** This was an internal recruitment tool for the maintainer's
  own beta program that was never intended to ship as part of the
  software — it shipped anyway. An install upgrading past this version
  will have the form, its pretty URL (`/beta-tester`), and the table
  removed automatically; any existing application data is backed up to a
  timestamped SQL file under the install's backup directory before the
  table is dropped. See `sql/run_retire_beta_tester_applications.php`.

### Fixed

- **A print button click could hang for 2-3 minutes in Safari.** Reported
  and root-caused by a community member: any open real-time event stream
  (`EventSource`) on the page stalls Safari's *programmatic*
  `window.print()` for minutes — reproduced with a 4-line minimal test
  page — while the keyboard shortcut is unaffected. A new shared
  `window.appPrint()` closes both of the app's SSE streams before
  printing and reopens them once the dialog closes; every print button
  and print action in the app now goes through it.
- **Inbound SIP/PBX calls: claiming, releasing, or reassigning a call
  didn't update other dispatchers' screens in real time.** The claim
  itself always worked correctly, but two files were missing a required
  include, so the real-time notification silently never fired — a second
  dispatcher had to manually refresh to see a call had been claimed.
- **Personnel "Members by Type"/"Members by Status" summary panels showed
  every member as Unassigned**, and the ICS Qualifications list, Time
  Entries, and the Personnel Compliance dashboard could show members with
  no name at all. All four read an old, no-longer-written internal
  column instead of the one the app actually writes to. Two of the fixed
  queries were delete-safety guards that had never actually been able to
  block deleting a member type or status still in use — they now do. The
  Compliance dashboard additionally had an unrelated database error that
  had it failing outright on every install; fixed in the same pass.
- **A unit's arrival time could read as ambiguous on a receiving
  facility's own portal view** when a patient was actually transported
  to a *different* facility — e.g. showing a bare "arrived 14:32" for a
  unit that had arrived somewhere else entirely. The timing text now
  names the actual destination when it isn't the facility currently
  viewing it.
- **A Field Unit user who was only linked to a unit through an active
  crew assignment** (not team or organization membership) couldn't
  acknowledge a Personnel Accountability Report (PAR) check-in for that
  unit, even though the mobile app already recognized them as crewing it.
- **"Last login" on the User Accounts page always showed "never,"**
  regardless of how recently someone had actually signed in — the column
  was displayed but never written by any code path. Existing accounts'
  history is backfilled automatically from the audit log on upgrade.
- **The Recent Activity dashboard widget didn't populate until manually
  refreshed**, and its entries showed a raw internal type/id (e.g.
  "user #3") instead of a readable name.
- **A configured session timeout longer than the site-wide default could
  be silently capped at the shorter, site-wide value** — PHP's own
  session garbage collector wasn't being reconciled with either setting,
  so a session could be logged out early regardless of which one an
  administrator had actually configured.
- **The ICS Form Builder's custom "Table" field type couldn't save a
  column configured with a dropdown (Select) — the save silently did
  nothing, with no error shown.**
- **A widget's "Save Snapshot" dropdown menu could render behind the
  widget's own content**, making its options unreachable by mouse.
- **A permission check that had been failing open (403) forever for
  certain roles kept retrying every 10 seconds, indefinitely**, on the
  incident detail page's Personnel Accountability Report card.
- **A Windows install re-running the installer could unnecessarily
  regenerate its encryption keys** on a second run, due to a path check
  that didn't account for Windows' key-storage location.

## [4.2.25] — 2026-08-22

### Added

- **Inbound SIP/PBX call integration for incident intake (Phase 149,
  closes #104).** When an agency's phone system (a SIP trunk or PBX —
  FreePBX/Asterisk, 3CX, a hosted SIP provider) receives an inbound call,
  every qualified, logged-in dispatcher now sees a live, hard-to-miss
  banner beneath the navbar showing the ringing line — never a modal,
  never a full-screen takeover. The first one to answer claims it with a
  single click, which opens a New Incident tab pre-filled with the
  caller's number and, once claimed, their prior-incident history —
  without disturbing whatever that dispatcher was already doing. This is
  coordination and screen-pop, not call control: TicketsCAD never
  answers, holds, transfers, or bridges the call itself. A separate
  small adapter process per PBX vendor normalizes native PBX events into
  one canonical webhook contract and posts it to the new
  `api/sip-ingest.php`, the same "a small bridge process talks to the
  vendor, PHP never does" pattern already used for the DMR radio bridge
  and the Meshtastic bridge; a reference adapter ships at
  `services/sip-bridge/`.
  - **Quick reassignment ("Take").** Many SIP/PBX deployments let only
    one physical extension actually answer a call, so the hardware race
    can resolve differently from the CAD's software claim. Within a
    configurable grace window (default 20 seconds) any other qualified
    dispatcher can instantly correct whose name is on a call with one
    click and no reason required — a self-correction of an honest,
    mechanical race, not an override. Past the grace window, reassigning
    an apparently-still-active claim requires the separate
    supervisor-gated "Manage Inbound Calls" permission and a typed,
    audited reason.
  - **Staleness detection.** If a claiming browser goes quiet (crash,
    lost network, walked away) while the PBX has not reported the call
    ended, the card turns amber for every other qualified dispatcher, who
    can reclaim it with one click — the system never silently reassigns
    a stale claim on its own.
  - **Full keyboard control** — arrow keys move the highlighted-call
    cursor among simultaneous calls, `A` claims, `T` takes/reclaims, and
    `Esc` locally acknowledges a call without touching the server —
    matching this project's keyboard-first convention elsewhere.
  - **Missed calls** move to a collapsible panel instead of vanishing,
    with one-click callback into the same pre-filled New Incident tab.
  - **Administration.** Settings → Communications & Integrations →
    Inbound Calls lets an administrator define one or more trunks (label,
    optional per-organization scope, wrap-up seconds, reassignment grace
    window, whether the ringing tone bypasses mute), each with its own
    bearer token for adapter authentication (shown once, rotatable).
    Five independently grantable RBAC permissions gate the feature at
    every layer, from seeing the banner at all through claiming, managing
    trunks, and viewing a claimed caller's identity/incident/patient
    history — the live ring notification itself never carries anything
    beyond what a physical caller-ID display would show.
  - **Ships fully built and completely inert until configured.** An
    install with zero trunks defined shows no new UI, runs no new
    background activity, and its scheduled 15-second sweep is a
    provable no-op — matching this project's standing "off by default"
    ship discipline for every prior major feature. Verified directly,
    not assumed: a dedicated regression test drives the real scheduled
    sweep, the real list/ingest API endpoints, and the real banner
    render function against a genuinely empty install and confirms
    nothing fires, nothing leaks, and nothing renders. Also live-verified
    end to end — ring through claim, reassignment, heartbeat, staleness,
    reclaim, PBX-reported end, wrap-up fold, and the resulting incident's
    full audit trail — against real dedicated throwaway sessions on both
    training.ticketscad.com and bloomington-auxcomm, with independent
    database-state verification at every step and all fixtures confirmed
    cleaned up afterward. Full design and setup guide:
    `docs/INBOUND-SIP-CALLS.md`. Deploying an adapter for a specific PBX
    is a separate, per-install infrastructure decision, matching how this
    project already treats the DMR and Meshtastic bridges.

### Fixed

- **GH#103: Import/Export member CSV exported blank names on plain-column
  installs, and the import side made it worse.** `inc/import-export.php`'s
  export read ONLY the legacy `field1`/`field2`/`field4`/`field6` columns
  for Last Name/First Name/Callsign/Email — on any install where members
  are actually written through the roster (named columns, not the legacy
  `field*` ones), every exported row came back with blank names. The
  mirror bug on import: it wrote CSV data to the legacy column
  unconditionally, while the roster reads the named columns with no
  fallback, so an imported member showed up completely nameless despite
  the import reporting success with the correct row count. Same root
  cause as GH#95 (`api/reports.php`), in a different file, so that fix
  never reached it. `export_csv()` now reads
  `COALESCE(NULLIF(named, ''), legacy)` for these four columns (matching
  GH#95's approach); `execute_import()` resolves the real write target
  per install via the new `db_generated_column_map()`
  (`inc/functions.php`) — writing the legacy column when the named one is
  a GENERATED mirror of it (unchanged behavior), or the named column
  directly when it's the real, independently-writable one (the fix). The
  Import/Export screen's search filter got the same fix. `member.phone`
  deliberately keeps the old legacy-only behavior — the roster actually
  reads `phone_cell`, a third column this pair doesn't reach either way,
  so remapping it would not have fixed anything; documented, not silently
  claimed as fixed. Audited every other `legacy`-aliased target
  (facility, in_types, team) and confirmed none of them have this
  bug shape — their named columns either don't exist on any install
  checked, or are otherwise not the column the rest of the app reads —
  so they're deliberately left unchanged.

- **GH#103 follow-up: Teams CSV import failed on a genuinely fresh
  install.** Caught by CI's fresh-install job on the first push of the fix
  above — this dev database's live `teams` table has real DEFAULTs on
  `sub-group`/`by`/`from`/`on`/`ttypes_id`/`leader`/`leader_dpty` (an
  untracked fix set them directly at some point), but
  `sql/base_schema.sql`'s own CREATE TABLE never gained one for any of
  them, so importing a team via CSV always threw MySQL 1364 on any
  genuinely fresh install — for every row, regardless of which columns
  the CSV included. `inc/team-write.php`'s real Teams-UI writer already
  supplies placeholders for these; `execute_import()` now does too, via
  the same `default`-fill mechanism `in_types.description` already used
  (relaxed to also apply to non-importable columns) plus the existing
  `audit_cols` mechanism for `by`/`from`/`on`.

- **Internal: corrected a false "tool bug" diagnosis in
  `dead_control_audit.php`'s phantom-column check.** While building Phase
  149, a scan-order/file-count sensitivity was suspected in the phantom-
  column check. Direct reproduction on an isolated clean checkout
  disproved it — the tool has no cache and no count-keyed state. The real,
  already-documented mechanism is the check's table-blind ambiguity:
  Phase 149's genuinely new `inbound_calls` table has real, written, read
  `reviewed_at`/`reviewed_by` columns, and once that write/read evidence
  exists anywhere in the tree, the same column names get credited to
  every other table that happens to share them — including the unrelated
  `beta_tester_applications` table. No user-facing behavior changed; the
  baseline comment was corrected to the accurate explanation and a
  permanent regression test was added.

## [4.2.24] — 2026-08-21

### Security

- **A fourth instance of the RBAC exclusion-list privilege leak — a live
  migration was still re-granting `action.manage_config` to Org Admin on
  every fresh install.** `sql/run_phase12_org_admin_manage_config.php`
  predates every later decision that made `action.manage_config`
  Super-Admin-only, and — unlike the two leak mechanisms closed on
  2026-08-16 — it is a still-executing migration that actively re-creates
  the grant, not a stale one left over from before the exclusion list
  existed, so it was invisible to that repair. Because it sorts
  lexicographically before `sql/rbac.sql`'s own repair statements in the
  install pipeline, it ran on every fresh install regardless. Confirmed
  live: a plain, freshly-granted Org Admin account with no other history
  resolved full admin access. Fixed by reversing the script's own
  behavior (grant → revoke); because the migration runner tracks each
  script's hash, the fix self-propagates to every existing install
  (including both of our hosted beta deployments) on its next routine
  migration run.
- **`require_https` is now a real, live HTTPS-verification signal instead
  of a checkbox that enforced nothing.** It now drives a dismissible
  admin banner, a live-state box in Settings, and a warn-severity System
  Health row, all reading one canonical verification function that never
  trusts a spoofable header — by explicit design this is detection and
  disclosure only, and never blocks, redirects, or refuses a request in
  any state. While building this, an unscoped `SetEnvIf X-Forwarded-Proto
  "https" HTTPS=on` line was found and fixed on our own training vhost: a
  line like this makes *any* client that can reach the vhost — not just a
  real reverse proxy — able to forge the "this is TLS" signal the
  verification mechanism exists to require proof of. The setting had
  always been off there, so nothing was silently broken for users, but
  turning it on would have shown "verified" for connections that were
  never actually proven so. New `docs/HTTPS-VERIFICATION.md` covers
  configuring Trusted Reverse Proxies and this exact trap for anyone
  running a similar reverse-proxy/CDN topology.

### Added

- **FCC §97.119 station-ID enforcement for the DMR/BrandMeister radio
  widget (closes a long-open gap where the console's "AMATEUR — ID
  required" badge was purely decorative).** The system-generated
  transmissions (weather bulletins, AI-drafted responses) already
  suffixed a station ID correctly, but a live dispatcher keying up the
  widget had no tracking or enforcement at all. Now: a per-channel
  configurable ID interval (capped at the FCC's 10-minute regulatory
  maximum) and enforcement level (off / soft / hard), a live countdown
  and conversation-elapsed display, and Monitoring ID / End Conversation
  controls — all built around the rule's actual timing model (the
  10-minute clock is anchored to the last ID, tracks the *conversation*
  rather than each individual transmission, and never nags an operator to
  come back on the air during legal silence). "Hard" enforcement requires
  an explicit one-click operator acknowledgment before a transmission
  proceeds past the deadline — it never blocks a transmission outright,
  since software must not itself suppress or force amateur radio traffic.
- **A real Settings panel — and a real kill switch — for Radio AI
  (Claude on Radio).** Previously the feature's enable flag, wake word,
  channel list, topic scope, and model choice had no writer anywhere in
  the app; arming or disarming it required a direct SQL update. Settings
  → Communications & Integrations → Voice now writes all five through the
  standard admin settings endpoint, Super-Admin-only. The master switch
  is a genuine kill switch — the listener re-reads it every poll cycle
  (default 5s), so disabling it takes effect within seconds with no
  daemon restart — and toggling it writes a dedicated high-severity audit
  log entry.
- **An admin UI for the audio-matrix patch routing table.** The matrix's
  route table (`comm_routes`) had a schema and a live reader in the
  Python audio-matrix service, but no writer anywhere in the app, so the
  `action.manage_matrix` permission gated nothing real. A new interactive
  channel×channel patch grid (linked from the Communications Console and
  the Settings sidebar) now creates, edits, and deletes routes — mirroring
  the live service's own validation rules exactly, including the FCC Part
  97.113 cross-class regulatory guard, so the admin UI can never create a
  route the service would silently refuse to load. A route created
  through the UI was verified byte-for-byte consumable by the real,
  unmodified matrix service against a live database. Still open: no
  install currently runs the matrix service itself as a daemon, so the
  routes this UI writes aren't live anywhere yet — that's a separate
  infrastructure decision.
- **Communications Console: real Select / Monitor / Mute / Volume /
  Simulselect, and personal or shareable operator layouts.** The
  console-designer's Monitor and Mute controls previously carried a
  visible "future" badge; they're now fully wired to the real Zello and
  radio widget audio output, following the standard commercial-console
  select/monitor model — an untouched console still plays every channel
  exactly as before, and attenuation only appears as the deliberate
  result of an operator pressing Select. Text-only channels get the same
  three-state model translated into UI prominence (highlighting,
  activity-flash suppression) instead of a literal volume. Simulselect
  lets an operator hold one button to key Zello and DMR radio
  simultaneously for channels enrolled in it — the "page out over radio
  and Zello at once" case. Per-operator layout preferences can now be
  kept personal or shared with the team.
- **A generic audit for the RBAC exclusion-list privilege-leak bug class,
  wired into CI and the pre-commit hook.** This shape of bug (an
  admin-only permission silently handed back to a lower role through a
  stale grant, a canonical-alias rename, or a still-live migration) has
  now been found and fixed four separate times. `tools/rbac_exclusion_
  leak_audit.php` parses the actual exclusion lists directly out of the
  seed SQL — generic over any role, not a hand-maintained list of known
  codes — and checks the live database for a direct or aliased leak, drift
  between the two seed files, and any migration that grants an excluded
  code outright, so a fifth instance is caught automatically going
  forward rather than by chance during an unrelated fix.
- **Missing page-level RBAC gates on the dashboard and the Units page.**
  Both were reachable by any authenticated session regardless of role,
  unlike every other authenticated page in the app. The dashboard now
  gates on the existing `screen.dashboard` permission; Units gates on a
  new `screen.units` permission, granted to every role except Field Unit
  (matching the existing unit-detail/unit-edit precedent).
- **Mileage Log report.** A neutral trip-log/utilization report under
  Reports — organization, vehicle, driver, incident link, odometer,
  miles, notes — with Vehicle/Driver/Organization filters and two
  breakdowns (by organization, by vehicle). Deliberately not
  billing-flavored: no rate tables, no invoice/payment status, no
  IRS-mileage-rate conversion. Chosen via a 5-persona design review (fire
  chief / ARES volunteer / patient-transport coordinator / campus security
  / sysadmin).
- **A prioritized research brief on DMR/AMBE speech-to-text accuracy**
  (49 ideas from an 8-lens multi-agent brainstorm, tiered by cost and
  impact) plus a measurement harness — pure-PHP word/character error rate
  scoring and a real-call corpus sampler — to validate any of those ideas
  before Radio AI feature work resumes on top of them. Measurement only;
  the live transcription path is untouched, and the harness ships with
  zero labeled ground truth yet.

### Fixed

- **Backup could be permanently, silently blocked by a misconfigured free-
  space reserve.** A real install had `backup_min_free_mb` set to a value
  clearly meant as bytes (a "1 GB" entry that landed as ~1 billion),
  which — after the unit conversion the setting is supposed to receive —
  demanded roughly a petabyte of free space no disk could ever satisfy,
  quietly refusing every backup for two days before anyone noticed. The
  value is now clamped to a sane ceiling both when it's saved and when
  it's read (so an install that already has a bad value self-heals with
  no migration needed), and the Status page now distinguishes "this
  reserve exceeds the volume's total capacity" — a configuration error no
  amount of freed space can fix — from an ordinary low-disk-space warning.
- **Personnel reports could silently show stale or empty data depending on
  how an install's `member` table columns were built.** Six Personnel
  reports (license expirations, roster snapshot, DMR inventory,
  membership due, inactive members, time summary) read only the legacy
  `field1`/`field2`/etc. columns; on installs where the human-readable
  named columns (last name, first name, callsign, email, phone, etc.) are
  independently writable rather than generated from the legacy ones, data
  saved through the roster UI never appeared in these reports. Every
  affected query now prefers the named column and falls back to legacy,
  correct on either kind of install. A follow-up fix corrected one column
  pair (`available`/`field8`) where a plain fallback wasn't enough — that
  field's default value is indistinguishable from an explicit "yes", so
  it now treats an explicit "no" from either column as authoritative
  rather than letting an unwritten default mask a real legacy value.
- **The lookup-data updater (FCC amateur/GMRS/ZIP data) crashed outright
  on hosts that disable PHP's shell-execution functions** — a common
  hardening setting unrelated to whether the tool it was trying to shell
  out to is installed. It tried `exec()`-based extraction and streaming
  import first and fell back to safe, in-process alternatives only as a
  last resort; both are now tried in the safe order first, with an
  explicit, non-fatal message naming the actual restriction when every
  fallback is genuinely unavailable.
- **Adding a second patient to an incident, or opening an incident that
  already had two or more, could silently drop a patient row.** The
  placeholder-clearing logic matched on a shared Bootstrap CSS class that
  also appears inside a real rendered patient row, so once one patient
  row existed, adding another (or loading several) could wipe it from the
  screen. No data was ever lost from the database — only the display was
  affected — and the guard now checks for the placeholder specifically
  rather than any element sharing its styling.
- **The per-unit "Dest" (destination facility) dropdown on the incident
  detail page could silently fail to save**, appearing to the dispatcher
  as if the destination had simply reverted on the next status change.
  The control referenced an undeclared variable while building its save
  request, so the request was never actually sent — the field was fixed,
  and now carries regression coverage that failed to exist for it before.
- **A facility using the facility portal could see units responding to a
  shared incident that were actually bound for a *different* facility**,
  including that unit's arrival timestamps — on a real multi-casualty
  call, this could have led a facility to hold a bed or stand up a trauma
  team for a patient who was never coming to them. The portal now
  resolves each unit's actual destination the same way the bed-automation
  logic already does, and filters the units list to it; a facility that
  is an incident's own origin (not just a receiving destination) is
  unaffected and still sees every responding unit.
- **Saving a non-empty OwnTracks location-tracking default fatally
  crashed the request**, calling a function that didn't exist anywhere in
  the codebase — discovered and fixed along with a second instance of the
  identical undefined-function call found in the same file shortly after.
- **`mileage_log` entries were silently lost for the two most common
  Send-To targets.** A mileage value entered through a unit-status prompt
  only produced a structured `mileage_log` row when the status's "Send
  To" target was "Incident record" — the other two targets, "Action Log"
  (Settings' own labeled default) and "Unit record", recorded the value
  in the action-log note text only, never as structured data. All three
  targets now write a structured `mileage_log` row; target continues to
  control only where the note text is also shown. `mileage_log` also
  gained a session-derived `org_id` column, backfilled best-effort from
  `ticket.org_id` for rows with a resolvable ticket link.
- **Facility Board's capacity display checked the wrong table's
  existence.** It gated loading bed/capacity data on whether the
  now-removed `newui_facility_capacity` table existed, rather than
  `facility_capacity` — the table its own capacity fetch actually reads.
  Worked only by coincidence, since the dead table used to be created on
  every install too.

### Removed

- **Dropped the unused legacy `requests` table** (v3 mutual-aid resource
  requests) — zero rows and zero code references on every install
  checked; org attribution is already handled by `ticket.org_id`. The
  separate, unrelated `access_requests` table (facility/account access
  requests) is untouched.
- **Retired the dead, unauthenticatable APRS-IS listener**
  (`services/aprs-is/listener.py`) — it POSTed to `/api/location.php` with
  only a CSRF token and no session, and that endpoint requires an
  authenticated session and dispatcher/admin RBAC, so it could never
  actually authenticate. It never ran anywhere. The maintained listener,
  `services/aprs/aprs_listener.py`, writes directly to the database and
  is unaffected; setup docs now point at it.
- **Dropped two more dead tables no code ever read or wrote:**
  `newui_facility_capacity` (a superseded facility-capacity design; the
  live model is `capacity_categories` + `facility_capacity`) and the
  legacy `webhooks` table (superseded by `webhook_subscriptions`, which
  every code path already used). Both are dropped on existing installs
  only after confirming there's nothing real in them — see
  `sql/run_facility_capacity_legacy_table_drop.php` and
  `sql/run_webhooks_legacy_table_drop.php`.

## [4.2.23] — 2026-08-19

### Security

- **RBAC canonical-alias and pre-exclusion-grant privilege leak — Org Admin
  and Dispatcher could reach Super-Admin-only permissions.** `sql/rbac.sql`/
  `sql/run_00_rbac.php` grant those two roles "everything except" a literal
  exclusion list of admin-only permission codes, but two independent,
  purely-additive mechanisms could each hand an excluded code back anyway:
  the RBAC v2 migration links every old code to a canonical `<resource>.
  <verb>` alias a literal exclusion list can never name in advance, and a
  role could hold an excluded code directly from before it was ever added
  to the list — neither of which a purely-additive seed import ever
  retroactively revokes. Confirmed live and complete on both of our
  hosted beta deployments — a full defeat of the
  Org-Admin/Super-Admin boundary on both hosts — and closed twice: once for
  the alias path, once broadened to cover direct pre-exclusion grants. Both
  seed files now carry self-healing repair statements that re-run on every
  import, so the leak cannot silently recur. Both hosts' audit logs were
  reviewed for every Org Admin against every leaked permission back to when
  the boundary first regressed — no evidence it was exploited.
- **IDOR: `facility-capacity.php`'s summary endpoint bypassed facility-access
  scoping.** The single-facility `?facility_id=X` path has always checked
  facility access before returning data; the `?summary=1` path a few lines
  above it ran an unfiltered join across every facility with no access
  check at all, so any authenticated non-admin user could see bed/capacity
  data for facilities that refuse them individually through the sibling
  path. Fixed by routing both paths through the same access filter.

### Added

- **Cross-org ticket sharing (GH#70), all three phases.** A multi-agency
  install with a parent dispatch org and child agencies (Sheriff, Fire,
  EMS) can now share visibility into a ticket across org boundaries,
  closing a gap where a parent-created incident was invisible to the
  agency actually meant to respond:
  - **Auto-routing** — admin-configured rules (owning org + incident type
    → target org, at `view` or `assist` tier) automatically share a
    matching ticket the moment it's created.
  - **Manual sharing + live push** — a Dispatcher or Org Admin can share
    (or revoke) any individual ticket ad hoc from the incident detail
    page, with the change appearing instantly for the receiving org
    instead of on next poll.
  - **Standing relationships + activation windows** — named, multi-org
    relationships with genuine two-party consent (every named org must
    individually approve before it can see anything) and an optional
    time-boxed activation window whose expiry is enforced fresh on every
    access check, never by a background job that could lag.
  - An org whose only access to a ticket is itself share-derived — even at
    the `assist` tier, which grants full write access — can never
    re-share or route that ticket onward to a third org. Full guide:
    `docs/CROSS-ORG-TICKET-SHARING.md`.
- **Custom ICS form types (GH#69).** Agencies can now define their own ICS
  form types under Settings, alongside the nine built-in forms (213, 214,
  202, 205, 205a, 213rr, 206, 214a, 221) — the built-in types' own code
  paths are untouched. Once a custom form instance is saved, its field
  layout is frozen to that instance, so a later edit to the type's
  definition never changes how an already-submitted form displays or
  prints.
- **Interval reporting (GH#64).** A new Intervals tab on the Reports page
  computes turnout, travel, response, scene, and transport time from the
  six assignment milestones (dispatched / responding / on scene / en
  route to facility / arrived at facility / clear), with period-wide
  averages and breakdowns by incident type and unit.
- **Facility-scoped portal accounts (GH#90).** A new Facility role gives a
  hospital or shelter contact a genuinely confined, no-navbar view of only
  the incidents inbound to their own facility, plus a self-service
  capacity/status update path — replacing v3's facility login level, which
  redirected to a facility-specific page but enforced no real access
  restriction.
- **Chat Bridge is now real (GH#89).** The four Chat Settings checkboxes
  (Telegram/Slack/Email/Mesh) have saved since June with no effect; each
  now creates or toggles a real Message Routing rule forwarding local chat
  to that channel. A direct/private local chat message can never be
  forwarded externally, regardless of a route's configured filters.
- **Configurable severity levels (GH#88).** Severity levels — previously
  fixed at Normal/Elevated/Critical — are now admin-editable under
  Settings: add, rename, recolor, and reorder. Every part of the app that
  previously hardcoded its own copy of the three-level scale now reads
  from one shared definition.
- **Dead-control audit tool (GH#91), and a sweep of its findings.** A new
  permanent audit (in the same family as the schema/API-contract/RBAC
  audits) flags a settings control or database column with a write path
  and no reader. The initial sweep found and disabled 31 dead settings
  controls beyond the ones already reported, and resolved 18 `user` table
  columns (10 dropped as confirmed dead, 7 marked as intentionally
  reserved, 1 stale comment corrected in place).

### Fixed

- **GH#92 — the CJIS login-notice default could be silently truncated
  during install.** `settings.value` was widened from varchar(512) to TEXT
  as a step that ran after the seed migrations rather than before, so the
  812-character CJIS notice seeded during that window landed truncated at
  exactly 512 characters (or aborted the seeder outright under strict SQL
  mode). Fixed by widening the column immediately after the base schema
  import, with a repair migration for installs that already seeded the
  truncated value.
- **GH#87 — auto-set severity showed one level on screen and saved a
  different one.** The client and two server-side readers each had their
  own hardcoded interpretation of the incident type's severity column,
  disagreeing on 33 of 37 real incident types on the reporting install,
  always under-reporting urgency. Fixed by both sides reading the same
  severity-levels table (see GH#88 above), which closes this by
  construction.
- **GH#82/GH#83 — assigning an already-busy unit silently reset its
  status, and the per-status Dispatch level was never enforced.**
  Assigning a unit a second active call unconditionally promoted it to
  Dispatched, overwriting whatever it was actually doing (On Scene,
  Transporting, etc.), and dropped its original call off its own mobile
  screen. The admin-configured Dispatch level (warn/block) is now
  actually read and enforced at assignment time, combined with an implied
  warn whenever Multi-Assign is off and the unit already has other active
  work.
- **GH#84 — notification rules could only ever target three of the eleven
  registered broker channels.** The channel column was restricted to
  email/SMS/local chat/all; widened to accept any registered channel
  (Slack, Telegram, push, APRS, DMR, Meshtastic, MeshCore, SMTP included).
  Also fixed: a numeric (user-id) notification recipient never resolved on
  any install, because the lookup read a `user` column that has never
  existed.
- **GH#81 — every in-app documentation link 404'd on IIS.** Links pointed
  directly at `docs/*.md`, which IIS has no MIME mapping for by default;
  Apache serves `.md` as plain text, which is why this went unnoticed in
  this project's own dev environment for months. All 15 links now route
  through the app's own documentation viewer.
- **GH#80 — Road Conditions had no way to look up coordinates from an
  address.** Added the same address-lookup control the Places panel
  already uses.
- **GH#79 — Major Incident creation was effectively unreachable from New
  Incident.** The "New" button next to the Major Incident picker opened a
  second blank incident form instead of creating a major incident; it now
  creates one inline and selects it.
- **GH#78 — the dashboard's Live Tracking toggle never worked.** The
  checkbox was spliced into the layer control's options after the control
  had already been built, and even then would have controlled an empty
  placeholder layer instead of the real unit-tracking layer.
- **GH#77 — mobile notes and location reports ignored crew-only unit
  assignments.** A user with no personal responder row of their own,
  assigned to a unit only via crew assignment, passed the mobile page's
  own unit-identification check but was rejected when reporting location
  or had their notes attributed to no responder. Both paths now share the
  same resolver the page header already used.
- **GH#76 — the roster's Team dropdown and the Team Memberships card could
  disagree on a member's team.** The dropdown wrote the legacy
  single-value `member.team_id` column while every other team surface
  read/wrote the many-to-many `team_members` table. `team_members` is now
  the sole internal source of team assignment; the dropdown is retired.
- **GH#71/GH#73/GH#74 — the EOC map showed stale "Updated" ages and stray
  live-GPS markers with no way to hide them.** Ported and hardened from
  two community pull requests (credited to ethanhawkes-gif): the Units
  table now considers the actual tracking timestamp, not just
  status-change time, and the live-GPS overlay is now its own toggleable
  layer instead of always-on.
- **GH#68 — patient insurance, receiving facility, and facility-contact
  fields were unreachable in NewUI.** These columns existed in the schema
  (carried over from v3) with no read or write path since the patient
  feature first shipped; restored, including a new admin-manageable
  Insurance Types list.
- **A directory-ownership race could silently disable the server-side
  geocode and tile caches.** Whichever process (a CLI script or the web
  server) first created the cache directories left them owned by that
  account; if a CLI process won the race, the web server could never
  write to its own cache, and the failure was invisible by design (a
  cache it cannot write to is not treated as an error). Both directories
  are now provisioned ahead of time by the permissions-repair tool, and
  their writability is now surfaced on the System Health page.
- **The map tile cache's Duration ceiling (365 days) blocked genuinely
  offline-capable installs from caching indefinitely.** Raised to 9999
  days; this was a UI-only limit — the underlying cache logic already
  supported any positive value.
- **The Map Overlay Category editor's four chained `prompt()`/`confirm()`
  dialogs (including asking for a color as raw hex text) replaced with a
  real modal**, matching the existing Places-panel editor's pattern — a
  color picker, a live icon preview, and a real checkbox for default
  visibility.
- **Windows fresh-install SQL import could fail on a migration file
  containing an inline comment that itself ends in a semicolon**
  (several exist in the RBAC seed file's exclusion lists), producing a
  syntax error from a statement split mid-list. Fixed the Windows-only
  import path's statement splitter to be comment- and string-literal-aware.
- **Documented that `docker compose pull` doesn't work for the
  locally-built app image** — it's never published to a registry, so the
  correct update path is `git pull` + `docker compose up -d --build`.

## [4.2.22] — 2026-08-16

### Fixed

- **EOC Display's incidents/units panel rendered under the navbar**: not
  the scroll-overflow issue fixed in 4.2.21 — a separate, unrelated bug.
  The site's own accessibility "skip to content" script has a fallback
  (for pages without a `<main>` element) that hunts for a content
  container to jump to, and its selector matched the EOC Display's own
  layout div and silently renamed its id on every page load. That broke
  the CSS rule giving the panel its position below the header, dropping
  it to the raw top of the page, under the navbar. Fixed generally — the
  fallback no longer touches any element that already has an id, since
  that always means the app relies on it elsewhere. A follow-up audit
  found the identical bug silently affecting two more pages that had
  never been reported — the Dispatch Call Board and the Facility Board —
  fixed by the same patch. Independently confirmed by Ron Jones
  (@rjonesbsink).
- **Address-lookup Test button and geocode cache-clear fataled on every
  use**: a missing `require` meant the endpoint called a logging function
  that was never loaded, so both actions failed with a raw PHP error
  regardless of provider. Reported (with the fix) by kmk1971.
- **DMR live-audio monitor played nothing despite correct audio arriving
  from the bridge**: the browser's live-audio stream died roughly every
  10-12 seconds, even during an active transmission with a continuous
  flow of data — silence between transmissions (completely normal on a
  quiet talkgroup) was being misread as a dead connection due to a bug in
  how the relay read the bridge's stream. Rewritten to no longer treat
  quiet periods as a failure; verified against a real gap longer than the
  old failure window. Reported by kmk1971.

## [4.2.21] — 2026-08-16

### Added

- **Facility-leg tracking**: `assigns.u2fenr`/`u2farr` ("unit to facility
  en route"/"arrived") existed in the schema since the v3 carryover and
  were already read by two API endpoints, but nothing in v4 ever wrote
  them, and unit statuses had no way to map to this leg of a call. Reported
  by a beta tester as an open question; the concrete symptom underneath was
  that a status pointed at the closest existing option (On Scene) silently
  did nothing, since On Scene was already stamped from the original
  dispatch. Unit statuses can now be mapped to dedicated Facility En Route
  / Facility Arrived actions, wired into both status-change surfaces (the
  dashboard/mobile unit-status widget and the incident-detail page's own
  per-assignment dropdown), the incident-detail assignment table (a new
  Facility column and lifecycle states), the per-responder ICS-214 personal
  timeline, the conservative-mode straggler-heal logic, and PAR's cadence
  tracking.

### Fixed

- **Incidents report was missing its responder filter**: the Incidents tab
  on the Reports page had no way to filter by unit, unlike its sibling
  reports.
- **Situation display's incident/unit list could look cut off the screen**
  when it was actually just scrollable: with an ordinary amount of active
  data, the list overflowed its panel and the scrollbar was easy to miss.
  A visible fade now appears at the bottom edge when there's more content
  below, and the scrollbar itself is wider and higher-contrast.
- **Settings statuses save silently dropped the two new facility-leg
  options** on first release of that feature — fixed before it shipped
  broken.
- **ICS-214 personal timeline's "authored by this responder's own
  account" source never returned anything** on any real install (an
  internal column-name mismatch).

## [4.2.20] — 2026-08-15

### Security

- **RBAC permission-code audit tool + 29 dead-code fixes**, built after an
  External API report from Ron Jones (@rjonesbsink): `api/external/v1/
  incidents.php`'s read-only incident-list gate referenced two permission
  codes (`action.view_incident`/`action.view_incidents`) that had no row in
  the `permissions` table — so the endpoint was reachable by Super Admin
  tokens only, with no role configuration able to grant it, despite looking
  fully configurable. A new `tools/rbac_permission_audit.php` (wired into
  the pre-commit hook and CI) finds every `rbac_can()`/`rbac_require_screen()`
  call referencing a dead code; it found 29 across the app, all fixed —
  either corrected to the real code or removed as redundant against a
  working sibling in the same OR-chain.
- **Fixed a schema-ordering bug found by that same audit**: 6 permissions
  (`action.manage_par`, `action.manage_mesh_bridges`,
  `action.kill_pending_message`, `action.recall_routed_message`,
  `action.set_incident_security`, `action.manage_security_labels`) had
  never been seeded on a genuinely fresh install, ever — five phase
  migration scripts seeded them using `resource`/`verb` columns that a
  later-sorting migration added, so their INSERTs threw "Unknown column"
  and were silently swallowed. The three columns are now part of the
  `permissions` table's original schema.
- **Fixed a privilege-tier alias-merging bug** that fixing the above
  exposed: two permission codes from different categories can derive the
  same canonical `<resource>.<verb>` form by naming coincidence, and the
  RBAC v2 migration silently merged them. Confirmed live:
  `screen.reports` ("can see the Reports screen") and `action.view_reports`
  (admin-only aggregate reports, per this project's own seed comment) both
  derive to `reports.view` — a Read-Only user holding only the former
  silently also satisfied the latter. The migration now refuses to alias
  across the screen/widget/field ("can see it") vs action ("can do it")
  boundary, with a one-time retroactive repair for any install that
  already merged two tiers this way.

### Fixed

- **GH#47** — the EOC display's map zoom and layers controls collided with
  the page header's higher stacking layer (a `.leaflet-top` positioned
  control under a `z-index:1030` header) and with the incidents overlay
  panel, making the layers toggle barely visible and the zoom buttons
  unresponsive. Reproduced live via Playwright; the layers control now
  sits below the header, the zoom control moved to the one corner neither
  obstruction reaches.
- **GH#65** — reopening a closed incident could silently re-close it within
  seconds, with no audit trail. A manual close never cleared a stale
  `auto_close_scheduled_at` marker armed by the all-clear path, so a later
  reopen raced an already-expired timer; separately, every `audit_log()`
  call in the auto-close sweep lacked a lazy-require guard, so the most
  frequent caller (the SSE stream endpoint, which runs the sweep on every
  tick) could never actually record a close. Both fixed, with a one-time
  repair migration for any ticket already stuck in the bad state.
- **GH#66** — the Zello widget logged a spammy, informationless status line
  on every channel join AND leave (Zello sends `on_channel_status` on
  both, with the status unchanged); deduped per channel. Also fixed
  `users_online` collapsing "not reported" and "reported as zero" into
  the same displayed value.
- **GH#67** — the mobile Patients section always reported "Patients (0) /
  None," even on an incident with a patient, because it read a field the
  incident-detail API has never returned. Now fetches `api/patients.php`
  directly, matching the desktop page and this file's own Call History
  section, which hit the identical bug shape previously.
- **GH#64** — pointing a second unit status at "On Scene" silently did
  nothing to the incident timeline once that milestone was already
  stamped (the timestamp is write-once); the status-config help text now
  says so.

## [4.2.19] — 2026-08-14

### Security

- **`.git/` and `vendor/` were reachable over HTTP on IIS** — a continuation of
  GHSA-rrp6-pqhj-w5wj, reported by Ron Jones (@rjonesbsink). Neither directory
  can ship a tracked `web.config` the way `sql/`/`tools/` do (`.git/` is git's
  own internal directory; `vendor/` is excluded by `.gitignore`'s directory
  pattern), so both are now hardened at runtime — `served_dir_harden()` fires
  on the next page load after a clone + `composer install`. Also closed six
  more directories with no IIS coverage at all (`apache/`, `coordination/`,
  `drafts/`, `inc/`, `specs/`, `tests/`, `services/` with narrow `.py`-only
  overrides for the two Mesh Console bridge scripts).
- **New comprehensive security-practice blueprint**, following a CIS/NIST-
  aligned research pass: `docs/security/architecture.md` (threat model, trust
  boundaries, a full CIS Controls v8 self-assessment across all 18 controls,
  and a CIS Microsoft IIS 10 Benchmark gap analysis across all 7 categories)
  and `docs/security/maintenance.md` (the maintenance cadence this project
  never had in writing — dependency scanning, cryptographic-currency review,
  SonarQube triage process, backup drills, key rotation, with an append-only
  log). Cross-references the existing CJIS mapping and CISA OSS conformance
  self-assessment rather than duplicating them.
- **Every open SonarQube finding investigated and resolved**: a Dockerfile fix
  (`services/dvswitch/docker/Dockerfile`) so the documented Docker install no
  longer bakes `DMR_MASTER_PASSWORD`/`DMR_BEARER_TOKEN` into the image layer,
  plus a non-root container user; a SQL string-building tightening in
  `inc/backup_schedule.php` converted to a real bound parameter. The remaining
  findings were confirmed genuine false positives, documented both inline and
  in SonarQube's own resolution.
- **627 accessibility findings fixed**: every form input across the
  application now has a proper `<label for>` or `aria-label`, confirmed by a
  fresh SonarQube scan (`Web:InputWithoutLabelCheck`: 627 → 0).

### Added

- **Quick Notes** — `/log <text>` in the command bar captures a timestamped
  personal note without leaving the keyboard; bare `/log` opens the
  management page. Notes are strictly private — ownership is the access
  control, with no RBAC permission gating them and no path for one user to
  see another's list. From the notes page, each note can be copied (default)
  or moved into an incident's activity log, an open ICS-214's activity log
  (original capture time preserved), or a personal corner of the existing
  SOP-Wiki. The prior `/log` command bar entry (jump to the incident activity
  log widget) is renamed to `/activity`; `/logs` remains as an alias.
- **Public Board now defaults every incident type to Never Publish.** A
  safety-first default flip for the credential-free public incident board
  (shipped disabled by default in v4.2.17) — an admin must now explicitly
  enable each incident type before it can appear on a public board, rather
  than every type publishing by default unless a keyword downgraded it.

### Fixed

- **The second extra-data prompt on unit statuses (GH#52) never worked on
  three of the five places a status change can happen.** It worked correctly
  on the dashboard's Responders widget and the Incident Detail page, but the
  Unit Detail page, the mobile interface, and the `/s` command bar had never
  been taught slot 2 existed — a status configured to collect both a
  destination facility and a starting mileage reading would silently only
  ever ask for the first, on those three screens. Fixed all three to chain
  slot 1 then slot 2, matching the pattern already proven correct elsewhere;
  the command bar's refusal check also now covers a status needing only slot
  2, which previously bypassed it and failed confusingly at the server.
- **The command bar's skip-link accessibility fallback was stealing the
  command bar's own `id="commandBar"`**, renaming it to `id="main-content"`
  and leaving the dashboard's skip-link focusing an empty div.

## [4.2.18] — 2026-08-14

### Added

- **Reports now link through to the record behind each row.** Incident
  numbers, unit names, facility names, personnel names, and team names
  across every report open the matching incident/unit/facility/roster/team
  page directly, instead of being plain text you had to search for by
  hand. The Incident Summary report's type breakdown also drills through
  to that type's filtered incident list on click. The visible cell text
  never changes — the internal database id used to build the link never
  appears on screen, only in the link target.

### Security

- **Round 2 of GHSA-x9x6-w4fg-pmcc: the Zello-audio relocation could land
  recordings inside another site's document root on Windows.** The v4.2.15
  fix moved recordings to "a sibling of the app root" — on a stock
  Windows/IIS install (`C:\inetpub\wwwroot\<app>`), that sibling is
  `C:\inetpub\wwwroot` itself, the Default Web Site's own root. Reported
  live by @rjonesbsink: upgrading and running the documented relocation
  migration moved 210 recordings from an unfirewalled port onto the port
  his firewall actually admits. Fixed to the same standard as BACKUP_DIR
  and FE_KEYS_DIR: the Windows default is now `%ProgramData%\TicketsCAD\
  zello-audio`, every location that could hold a recording is hardened
  unconditionally (new default, the old sibling location, the original
  in-tree location), and the relocation migration rescues files from every
  earlier location. The same sweep found four more directories with the
  identical unfenced pattern (weather-proxy circuit-breaker state, DMR
  bridge health state, the geocode cache, the tile cache) and fixed them
  the same way; the last two were also missing from `docker-compose.yml`
  entirely.

### Fixed

- **The Reports page's "(Period)" summary cards could show numbers that
  looked impossible**, like "32 Closed (Period)" next to "1 Total
  (Period)". `closed_in_period` filtered on `problemend` (when an
  incident was closed) while `total_in_period` and the report table below
  it both filtered on `date` (when it was opened) — two cards on the same
  panel were answering different questions. Aligned to the same date
  basis so the cards and the table always reconcile.
- **The EOC display's Units/Facilities layer toggles still didn't work
  after the v4.2.17 fix.** That fix correctly swapped a dead global
  reference for `sitLayersControl`, the variable every other overlay on
  the page already uses — but `sitLayersControl` was declared with `var`
  *inside* `initMap()`, invisible to the sibling functions that reference
  it, so every page load threw a silently-swallowed `ReferenceError`.
  Fixed by declaring the shared variable at the same top-level scope
  `map`/`tileLayer`/`markerGroup` already use. Reported by @cbyrdmo,
  originally traced by @rjonesbsink, GitHub #47.

## [4.2.17] — 2026-08-13

### Added

- **Public Incident Board** — a credential-free, read-only board of an
  agency's currently active incidents, for a public website or a lobby
  display. Off by default on every install. Configure it from
  Settings → Public Incident Board: a per-incident-type visibility rule
  (full detail, address-precision-limited, presence-only, or never
  publish), an address-precision ceiling a Security Label can only make
  coarser, and a per-organization board URL. Two permission levels: an
  install-wide administrator manages the master switch and the default
  rules; an Org Admin can enable and configure only their own
  organization's board. Enabling either the master switch or an
  organization's board requires checking an explicit acknowledgment that
  some incident types (medical, mental health, welfare check, domestic
  violence, and similar) default to presence-only for this reason — the
  checkbox is enforced on both the page and the save request, not just
  client-side. See `docs/PUBLIC-INCIDENT-BOARD.md` for the full setup and
  redaction model.
- **Outbound message log retention** — Settings → Pending Messages gained
  a configurable retention window (in days) for the outbound message log,
  enforced by a systemd-timer-driven cleanup job alongside the project's
  other scheduled jobs (GH#42).
- **A second configurable extra-data prompt on unit statuses** — a status
  can now collect two pieces of information from a responder (e.g. a
  destination facility AND a reason) instead of one, configured from the
  same status-edit form (GH#52).

## [4.2.16] — 2026-08-13

### Fixed

- **A table with a GENERATED column made every automatic backup silently
  unrestorable.** The dump named columns from `SHOW COLUMNS` (which lists
  generated columns) but read row values from `SELECT *` (which excludes an
  invisible generated column while still including a visible one MySQL will
  not accept back via `INSERT`). Backups reported success and produced a
  plausible file either way; restoring one hit "Column count doesn't match
  value count" or "value specified for generated column... is not allowed."
  The dump now names columns explicitly and excludes anything generated, so
  the column list and the row values always match. Affects `user_roles`,
  `teams`, `member`, `member_time_entries`, `mileage_log`, and
  `newui_facility_capacity` on any install using MySQL 8 or MariaDB with
  generated columns. Reported by @rjonesbsink, GitHub #53.
- **A security label's code silently lost its first letter, or vanished
  entirely for an all-caps code.** The sanitizer lowercased the input
  *after* already stripping every character outside `[a-z0-9_]` -- so
  `strtolower()` never had anything left to lower, `Medical` saved as
  `edical`, and `HIPAA` sanitized to an empty string and was dropped from
  the write with no error. Lowercase now happens first. Reported by
  @rjonesbsink, GitHub #55.
- **The "Save to Server Filesystem" button on Settings → Backup / Maintenance
  ignored your configured backup directory.** The field's value was
  hardcoded to the pre-4.2.3 in-tree path regardless of what `backup_dir`
  was actually set to, so a manual save landed in a different place than
  scheduled backups -- the one place a web server might still reach. The
  field now shows the real configured directory, and an emptied field
  falls back to it server-side instead of refusing to submit. Reported by
  @rjonesbsink, GitHub #56.
- **The After Action Report never showed the incident number you actually
  recognize.** The report header and summary card showed only the internal
  database id ("Incident #53"), with no way to confirm you pulled the report
  you meant to -- risky on any install more than a year old, where the
  yearly incident-number reset can make small numbers collide with old
  internal ids. The report now leads with the incident number, with the
  internal id alongside for cross-referencing. (The input field itself was
  already fixed under GH#51 to accept the incident number directly.)
  Reported by @rjonesbsink, GitHub #57.

## [4.2.15] — 2026-08-12

### Security

- **Recorded Zello voice traffic was served without authentication.**
  `cache/zello-audio/` held one Opus file per transmission, and the archive
  UI played them by pointing straight at the static file — no session, no
  permission check, no audit entry — unlike the equivalent DMR recording
  path, which already required `action.dmr_receive`. `api/zello-messages.php`
  also had no permission check at all, so any authenticated account, any
  role, could enumerate and download every retained recording. New
  `api/zello-audio.php` mirrors the DMR pattern: requires a new
  `action.zello_receive` permission, resolves recordings by database id
  rather than a client-supplied path, and audit-logs each playback. New
  recordings now write to a private directory outside the web root; a
  migration relocates existing ones, and web-server rules deny the legacy
  in-tree path as defense-in-depth. Reported by @rjonesbsink with a full
  CVSS derivation (High, 7.5). GHSA-x9x6-w4fg-pmcc.

## [4.2.14] — 2026-08-09

### Fixed

- **The command bar's `/s` status shortcut could reject a status that was
  genuinely configured, just spelled differently.** `/s <unit> os` only
  recognized installs using the exact label "On Scene" — an install using
  "At Scene" for the same real-world state (a wording choice, not a typo)
  got "not configured on this install" and had to add a duplicate status
  to work around it. The matcher now tries multiple known synonyms for a
  handful of common statuses ("On Scene"/"At Scene" today), so either
  spelling resolves correctly. Reported by Chris Byrd, GitHub #44.
- **Removing a unit's location provider didn't remove it.** The unit-edit
  "Remove" (✕) button on a Location Sources row called the same action as
  the enable/disable toggle switch — which only ever deactivates a
  binding, never deletes it. Clicking Remove on an already-disabled
  provider was a true no-op: the row stayed in the list, strikethrough
  and all, forever. Remove now actually deletes the binding. Reported by
  Chris Byrd, GitHub #45.

## [4.2.13] — 2026-08-08

### Added

- **Audit Log can now be exported as CSV or JSON**, matching whatever
  filters are applied in the browse view. Admin-only — stricter than the
  `action.view_audit` permission that gates plain browsing, since exporting
  the whole dataset in one shot is treated as more sensitive than paginated
  reading. Requested by Chris Byrd in the course of investigating issue #37.

### Fixed

- **Places' new Lookup button could write a truncated, wrong state value**
  instead of a real abbreviation — typing an address with no state filled
  in and clicking Lookup wrote the first four letters of the returned state
  name (e.g. `Minnesota` → `MINN`) instead of resolving it to `MN`. Fixed
  to resolve against the same state list used everywhere else in the app.
  Also hardened the server-side save to enforce real per-column length
  limits instead of a single blanket cutoff. Regression in the Places edit
  screen shipped in 4.2.12 (#39).
- **Deleting a message from Sent did nothing.** The delete action only
  ever recognized a message from the recipient's side (removing it from
  their inbox); there was no way to represent "the sender removed their
  own copy," so a Sent-view delete matched zero rows and silently no-opped.
  Sent messages can now be deleted independently of what happens to the
  recipient's copy. Reported by Chris Byrd, GitHub #42.
- **Emptying the Wastebasket undercounted what it purged, and said so
  confusingly.** ICS Forms are deliberately never hard-deleted (restorable
  operational records only) — the empty action always skipped them, but
  the confirmation and result messages never said so, reading as though
  the purge had failed or missed something. The response now names what
  was purged and what was left in place and why. Also fixed a race where
  the item-count refresh could overwrite that message a moment after it
  appeared. Reported by Chris Byrd, GitHub #43.

## [4.2.12] — 2026-08-08

### Added

- **Equipment activity log entries can now be deleted**, admin-only with no
  ownership exception — modeled on the existing ICS Forms delete, but
  without its creator-may-delete-their-own-draft carve-out, since an
  equipment log entry is a straight audit-trail record rather than a
  work-in-progress document. Soft delete, restorable from Settings →
  Wastebasket. Requested by Chris Byrd, GitHub #38.
- **Places now has a real edit screen.** The old edit flow only ever
  prompted for name, street, and city — state and lat/long could be set
  once on creation but never touched again. One modal now backs both
  creating and editing a Place, with every field (including a Lookup
  button that geocodes a typed address into latitude/longitude). Requested
  by Chris Byrd, GitHub #39.

### Fixed

- **A self-hosted upgrade via `git pull` could silently miss a dependency
  security patch.** `vendor/` is gitignored, so a fix delivered as a
  Composer hook (like the #31 Web Push vendor patch) only applies if the
  operator runs `composer install`/`update` — which `docs/UPDATE-CHECKLIST.md`
  never told them to do. Added as a checklist step, and named as a third
  failure class alongside the two the doc already covered. Reported by
  Ron Jones, GitHub #31.

## [4.2.11] — 2026-08-07

### Fixed

- **A contact with no phone number or email on file could never be
  recognized on a re-import**, so running the same import file again —
  even once — silently inserted a second copy of every such contact, with
  nothing shown to indicate it had happened. Import now also matches by
  name and street address when neither a phone nor an email is available,
  so a repeat import merges instead of duplicating. Deliberately
  conservative: a real phone/email match still wins first, and a name by
  itself is still never enough on its own to merge two records. Reported
  by Chris Byrd, GitHub #37.

## [4.2.10] — 2026-08-07

### Fixed

- **Equipment activity log showed the wrong person checking equipment in or
  out.** The log's "By:" line is who performed the action, stored as the
  logged-in user's account id — but was being looked up in the personnel
  roster instead of the account list, two separate id sequences that only
  occasionally happen to match. Whichever unrelated roster member shared
  that number showed up instead of the real person. Reported by Chris Byrd,
  GitHub #34.

- **System Overview showed inflated counts for Facilities, Units, and
  Personnel** — the only three of the six counted there that support
  delete/restore. The counts included anything sitting in the wastebasket;
  the real list pages already excluded it, which is why those pages showed
  the correct numbers. Incident Types and Teams (no delete/restore on
  either) were already correct. Reported by Chris Byrd, GitHub #36.

- **New Incident's responder-assignment list could show a unit twice.** If a
  unit was ever deleted and re-added under the same name — a normal cleanup
  step — the old deleted copy stayed in that list right alongside its
  replacement. Fixed by excluding deleted units, matching how the rest of
  the app already treats them. Reported by Chris Byrd, GitHub #40.

- **A one-time backup space refusal could show as a current problem for up
  to a day.** The "backup was refused, not enough room" warning on System
  Status was only ever rewritten when a real backup attempt ran, so on the
  default 24-hour schedule a condition that had already cleared could keep
  reading as urgent — while the live free-space number shown right next to
  it already looked fine. The warning now re-checks live conditions before
  it's shown, and the temp-directory space (the guard checks that too, not
  just the backup folder) is now visible alongside it. Reported by Chris
  Byrd, GitHub #32.

- **Adding an org-scoping column could fail outright on an older table**,
  silently, with a database error that was only ever logged and never
  surfaced — found on this project's own Teams table, which reported a
  normal, current row format everywhere it was checked but still refused
  the change. Now retried once against a version of the table that gets
  the same result but succeeds.

## [4.2.9] — 2026-08-06

### Fixed

- **Updating to v4.2.8 could duplicate every team in the list.** `teams` had
  a primary key on `id` and nothing else — no unique constraint on the team
  name — so `sql/membership.sql`'s starter-team seed was never actually
  protected by its own `INSERT IGNORE` the way its sibling seeds are.
  Whenever that file's tracked content changed, the standard "safe to
  re-import" install/update path re-inserted the same four starter teams
  again. A real UNIQUE constraint on the team name now makes that guarantee
  true. Existing duplicates are merged automatically on update — the copy
  with more members is kept, any team members on the removed copy are
  moved over first (a member on both copies is kept once, not doubled), and
  nothing is deleted that wasn't a true duplicate. Saving a team with a
  name already in use now shows a clear message instead of a database
  error. Reported by Chris Byrd, Google Group.

## [4.2.8] — 2026-08-06

### Fixed

- **A CSRF token was only ever generated for a page if it happened to have 2
  or more configured languages.** `window.CSRF_TOKEN` — the value 10 different
  JS files (Equipment, Teams, Scheduling, the command bar, and others) send
  on every write — was assigned only inside the language-switcher's
  conditional block, which does not render at all on the default,
  single-language install. Every affected feature silently sent an empty
  token on such installs, surfacing as "Invalid CSRF token" on Equipment
  saves and a bare, unexplained "HTTP 403" on Team saves (the two errors
  look different only because Team's own error-handling separately
  discarded the server's real error message — also fixed below). The token
  is now generated once, unconditionally, on every page. Reported by Chris
  Byrd, Google Group.

- **An upgrade install's Equipment "assigned member" dropdown showed
  "null, null" for most members.** The member-picker query read only the
  modern `first_name`/`last_name` columns with no fallback to the legacy
  `field1`/`field2` columns many upgrade installs still carry their member
  names in — the same pattern already fixed for Teams' member dropdowns in
  4.1.x. `api/vehicles.php` had the identical un-COALESCEd query; fixed
  alongside rather than waiting for a matching report. Reported by Chris
  Byrd, Google Group.

- **The Team "Type" dropdown has been unconditionally blank since the
  feature was first built.** `team_types` has never had a seed row in this
  project's history — confirmed against the legacy v3.44 database dump —
  and had no admin screen to add one either. A default set of 9 types
  (Command, Fire, EMS/Medical, Search & Rescue, Law Enforcement,
  Communications, CERT, Logistics, General) is now seeded on
  install/upgrade. Separately, the dropdown's label read a `name` field the
  underlying query never emits — the real column is `type` — so even a
  manually-added type would have rendered as "Unknown". Reported by Chris
  Byrd, Google Group.

- **Team save failures showed a bare "HTTP 403" with no explanation.**
  `assets/js/teams.js`'s request helpers threw on any non-2xx response
  before parsing the JSON body, discarding the server's real
  `{"error": "..."}` message — the same failure Equipment's save correctly
  surfaced, because its helper happened to parse the body first. Both
  helpers now read the real error message when one is sent.

### Added

- **A management screen for Team Types** (Settings → Team Types), the gap
  behind the previous fix's default seed. `member_types` has had an admin
  CRUD screen since early on; `team_types` never did — the only way to add
  one was hand-editing the database. Mirrors the existing Member Types
  panel: add, rename, and delete a type, with delete blocked while any team
  still uses it.

## [4.2.7] — 2026-08-06

### Security

- **The 4.2.3 backup fix moved database archives INTO a web-served directory on
  Windows.** 4.2.3 closed the directory-exposure advisory by moving backups
  "above the web root", computed as the parent of the application directory.
  That is correct on a Linux layout — `/var/www/newui` gives `/var/www` — and
  inverted on a standard Windows one: `C:\inetpub\wwwroot\TicketsV4` gives
  `C:\inetpub\wwwroot`, the physical path of Default Web Site, bound to port 80.
  XAMPP behaves the same way. So on those hosts **upgrading is what created the
  exposure** — no misconfiguration was required and no instruction had to be
  followed — and the Status page reported the install healthy throughout,
  because its exposure check probes only the URL TicketsCAD itself is served on
  and an archive published by a different site on a different port is invisible
  to it. The Windows default is now `%ProgramData%\TicketsCAD\backups`, which is
  a site root under no stock configuration; the POSIX default is unchanged
  because it was correct. Existing archives are never moved for you — the 4.2.3
  location is tracked as a historical directory so they stay listed and are
  never pruned, and a Critical note names 4.2.3 as the cause rather than
  implying operator error. The check now verifies the DESTINATION with a
  short-lived random-token canary on the default ports as well as the
  application's own, counting a `200` only when the body contains the token, and
  states what it could not see — another hostname, another port, a reverse
  proxy — including on a passing result. Reported by Ron Jones
  (@rjonesbsink), who tested what the shipped fix actually did on his own server
  rather than assuming it had worked. See GHSA-rrp6-pqhj-w5wj.

- **The RSA private key and the 2FA encryption key were written into a
  web-served directory on Windows** (GHSA-3jmh-c6f6-64jc, reported by Ron Jones
  / @rjonesbsink). `FE_KEYS_DIR` was `NEWUI_ROOT . '/../keys'` on every
  platform, with the intent documented as "one level ABOVE the install
  directory, on purpose … so the private key is not HTTP-reachable". That is
  true on Linux and backwards on Windows: IIS sites are subdirectories of a
  *served* `C:\inetpub\wwwroot`, so the keys landed in
  `C:\inetpub\wwwroot\keys` — inside Default Web Site, bound to port 80 — and
  XAMPP has the identical shape. The directory was confirmed served:
  `GET /keys/_probe.txt` returned **200**. `GET /keys/private.pem` returned
  404.3, but only because IIS ships no MIME mapping for `.pem`; that is an
  accident of the file's name, any *mapped* extension in the folder is served,
  a single `staticContent` entry removes it, and **Apache serves `.pem` as
  plain text** with no allow-list at all. The folder had no `web.config` and no
  `.htaccess`. Same root cause as the `BACKUP_DIR` regression, one directory
  over — the third time this assumption has been implemented independently, so
  the three helpers that answer "is this directory published, and can we fence
  it?" now live once, in `inc/served-dir.php`, with `backup_*` names kept as
  delegating wrappers. **The Windows default is now
  `%ProgramData%\TicketsCAD\keys`**; POSIX is unchanged, because it was correct
  there. **Existing keys are never moved**: the historical location is checked
  first and, if it holds `private.pem`, `public.pem` or `tfa.key`, that is the
  directory the install keeps using — an upgrade cannot break field encryption
  or lock every 2FA user out, and a half-completed key move is worse than the
  exposure it would fix. Instead, Settings → System Health gains an **Encryption key
  location** row that grades the directory from local evidence, proves
  reachability with the random-token canary on the default ports (the probe is
  now shared with the backups check rather than copied), lists the key files
  found there, prints platform-correct copy → verify → delete instructions, and
  states what it could not see even when it passes; `tools/check-health.php`
  prints the same on the command line. The `define()` is **guarded**, so
  `define('FE_KEYS_DIR', …)` in `config.php` now works — it never did, because
  PHP cannot redefine a constant and the application always got there first.
  Deny rules (`web.config` using Request Filtering, plus `.htaccess`) are
  written beside the keys unconditionally, wherever they live, and beside the
  text-to-speech API keys as well. `inc/tfa.php`, `api/tfa-key.php` and
  `tools/tfa-migrate-key.php` no longer guess the directory independently — a
  `tfa.key` written where `inc/field-encrypt.php` is not looking would un-enrol
  everyone. And the 2FA migration error, which said "Failed to generate key
  file. Check directory permissions." and thereby pointed administrators at
  granting write access to a folder published on port 80, now names the
  directory and says when it is one nothing should be written to.
  `tests/test_fe_keys_dir_platform.php` (111 assertions) drives the real
  resolver against real directories on both platform shapes, exercises the
  config.php override in a fresh process because a guarded define cannot be
  tested any other way, and asserts the upgrade case explicitly: an install
  whose keys are already in the old place keeps them.
- **The web-exposure self-check reported "no non-public directory answered"
  while a database archive was being served, and the published advisory taught
  the same wrong test.** Reported by @rjonesbsink from a live install:
  `/backups/` answered **403 while the archive inside it answered 200 and
  downloaded in full** — the complete database export. That is not an unusual
  server; it is what any server with directory listing off and no deny rule on
  files does, and on Apache `Options -Indexes` alone produces it. So a `403` on
  the folder is simultaneously the most reassuring answer a server can give and
  worthless as evidence — and it was both the fallback our own check used and
  the one-minute command the Critical advisory told frightened operators to run.
  The check now asks for a **file** or declines to answer: a real archive named
  from any backup directory inside the served tree (the `backup_dir` setting and
  both historical defaults, not just the one location the old code globbed);
  failing that the existing random-token canary, which is conclusive without
  putting an archive URL into a proxy or CDN log; failing both, a distinct
  **"Not determined"** state that renders grey on Settings → System Health, counts in
  the page's unknown bucket, and prints `[????]` from `tools/check-health.php`.
  A directory request can no longer produce a "blocked" verdict at all, and an
  install with no backup yet is reported as untested rather than safe. The
  canary also gained the application's own URL prefix, without which it could
  not reach an in-tree backups directory on a subdirectory install. Every copy
  of the manual check is corrected — the advisory (with a "corrected" note, so
  anyone who ran the old one knows to re-run), `docs/WEB-SERVER-HARDENING.md`,
  `SECURITY.md`, `docs/INSTALL.md`, `docs/INSTALLATION-CHECKLIST.md` and
  `tools/check-health.php` — each now naming an archive and saying plainly why
  the folder request does not count.
- **The IIS deny rules shipped in v4.2.3 did not deny — they returned HTTP
  500.19.** `sql/web.config` and `tools/web.config` (and the template
  `backup_harden_dir()` writes into `backups/`) were invalid IIS configuration.
  The directories were unreachable, but only because the configuration threw:
  the rule never ran, so anything that made the file parse would have reopened
  them, and the 500 page discloses the application's physical path meanwhile.
  Reported with a per-variable test matrix by @rjonesbsink on stock IIS 10 /
  Windows 11. Three independent defects, each fatal on its own: `<authorization>`
  was a direct child of `<system.webServer>` instead of sitting under
  `<security>`; `<deny users="*" />` is the ASP.NET element, where IIS URL
  Authorization wants `<add accessType="Deny" users="*" />`; and that entry
  collides with the `users="*"` rule `applicationHost.config` ships at server
  level unless a `<remove users="*" />` precedes it (`0x800700b7`), which is a
  property of the *default* IIS configuration and so failed on every stock
  install. A fourth, non-fatal: the accompanying
  `<hiddenSegments><add segment="." /></hiddenSegments>` answered 200 — it
  neither errored nor blocked, so both mechanisms in the file were inert. The
  hidden-segments rule is removed rather than repaired (hidden segments match
  **any** path segment, so a site-level entry for `vendor` also blocks
  `assets/vendor/` and unstyles every page — this project has done that to two
  live sites before). Every shipped `web.config` — `sql/`, `tools/` and the
  template `backup_harden_dir()` writes — now carries **one** mechanism,
  `<security><requestFiltering><fileExtensions allowUnlisted="false" />`.
  Request Filtering is part of a default IIS installation; URL Authorization —
  the form measured at 401, and what the first repair shipped — is an optional
  role service, and a `web.config` naming a section whose module is absent
  answers 500.19 instead of denying, which is this same bug wearing a different
  hat. (The 401 was measured after the reporter installed that feature by hand;
  the 500s are what reproduce untouched.) The rule denies the **file** and not
  merely the listing — the distinction the backups report turned on — and
  extension-less URLs such as `GET /tools/` with it; a denied request is a `404`
  logged with substatus `404.7`. URL Authorization is documented as an optional
  extra layer for administrators who have the role service, never as the only
  rule. `tests/test_iis_webconfig_syntax.php` parses every shipped and
  documented `web.config`, rejects all four broken shapes structurally, rejects
  URL Authorization used alone, and models the documented filtering behaviour so
  that "the archive is denied, not just the listing" is executable rather than
  remembered. The IIS instructions in `docs/WEB-SERVER-HARDENING.md` and
  `docs/INSTALL-WINDOWS-IIS.md` carried the same broken snippet and advised
  site-level hidden segments including `vendor`; both are corrected, both now
  describe one mechanism and its limits — including what has **not** been
  measured on a live IIS — and the self-check says plainly that a `500` on IIS
  is a broken config rather than protection.

- **Directories that are not part of the web interface — including database
  backups — were served over HTTP.** The documented install points the web root
  at the application root, so every directory in the tree was published unless
  the operator had configured their web server not to, and nothing shipped told
  it to. Confirmed from the public internet against a live install on
  2026-07-30: `GET /backups/<archive>.zip` returned **HTTP 200 and a 110 MB
  database dump with no authentication**, `GET /backups/` listed every archive,
  `GET /sql/` and `GET /tools/` listed 181 and 109 internal scripts,
  `GET /sql/run_migrations.php` **applied database migrations**, and
  `GET /inc/db.php` was served.

  **Check your own install in one minute** — anything answering `200` is
  affected:

  ```bash
  curl -s -o /dev/null -w 'sql   %{http_code}\n' https://your-site/sql/run_migrations.php
  curl -s -o /dev/null -w 'tools %{http_code}\n' https://your-site/tools/
  # Backups: ask for an ARCHIVE BY NAME (filenames: Settings -> Backup / Maintenance).
  # A 403 on /backups/ proves nothing about the files inside it.
  curl -s -o /dev/null -w 'archive %{http_code}\n' \
       https://your-site/backups/ticketscad-20260728-020000.zip
  ```

  Four independent defences now ship, because no single one covers every
  install:

  - **Deny rules in the repository** — the root `.htaccess`, plus
    `sql/.htaccess`, `tools/.htaccess` and `web.config` files for IIS. They
    arrive with the update instead of having to be added by hand. The Apache
    vhost template also stopped enabling directory indexes (it said
    `Options Indexes FollowSymLinks`, which is how `GET /backups/` came to
    return a browsable list).
  - **An nginx configuration snippet, because `.htaccess` does nothing there.**
    `docs/nginx/ticketscad-hardening.conf`, with
    `docs/WEB-SERVER-HARDENING.md` stating plainly which server needs which
    file. **If you run nginx or IIS, the shipped `.htaccess` files do not
    protect you and you must apply the equivalent.**
  - **Every one of the 296 scripts under `sql/` and `tools/` refuses to run
    over HTTP** (`403 CLI only`, before loading configuration or touching the
    database). This is the layer that works on any web server in any
    configuration — including one where no deny rules were ever installed —
    and `tests/test_web_exposure_hardening.php` fails the suite if a new script
    ever lands without it.
  - **Backups moved above the web root.** The default is now `../backups`, a
    sibling of the install directory, on the same reasoning that already put
    the encryption keys in `../keys`. **Nothing is moved or deleted for you:**
    archives written by an earlier version stay where they are, stay listed and
    downloadable in Settings → Backup / Maintenance, and are never touched by retention
    pruning. Settings → System Health tells you how many are still in a served
    directory and gives you the command to move them. Docker installs get the
    `app_backups` volume remounted at `/var/www/backups`; the volume and its
    contents carry over unchanged.

  **The install now checks itself.** Settings → System Health has a "Web exposure" row
  that probes `backups/`, `sql/` and `tools/` over HTTP against this server and
  shows a red banner if any of them answers — so a later nginx upgrade or vhost
  edit that quietly re-opens one of these is reported rather than assumed away.

  If your `backups/` directory was reachable, treat the database as disclosed
  and work through
  `docs/security/advisory-2026-07-30-exposed-directories.md`, which covers
  checking your access logs, which credentials to rotate, and what to tell your
  members.

### Fixed

- **Push notifications and RSA field encryption could not find their own
  OpenSSL configuration through a real web server, even after GH#8's fix —
  and every openssl.cnf candidate path in this codebase was being searched
  in the wrong directory.** GH#8 fixed VAPID keypair generation on a host
  whose OpenSSL cannot find its config file (the common case on stock
  Windows PHP), but never touched the SEPARATE encryption key
  `minishlink/web-push` generates for every push message, in vendored code
  with no config-aware fallback of its own — an install could show "Keypair
  configured" and then fail every actual send, forever, because
  `inc/push.php` never even loaded `inc/vapid-keygen.php`. A composer
  post-install/post-update hook (`tools/patch_vendor_webpush.php`) now
  patches that one vendored call site to reuse the same config resolution,
  idempotently, failing the build loudly rather than silently if a future
  `minishlink/web-push` release changes the method enough that the patch
  can no longer apply. The "Generate New Keypair" action also now runs a
  live self-test of the send path and reports plainly when a valid keypair
  still cannot actually send, instead of only ever reporting keygen
  success. Found and reported by Ron Jones (@rjonesbsink), GH #31.

  Verifying that fix in a browser against real Apache — not only running
  the CLI test suite — surfaced a second, more fundamental bug behind it:
  every candidate `openssl.cnf` path, in both `inc/vapid-keygen.php` and an
  identical copy in `inc/field-encrypt.php` (RSA field encryption), was
  built from `dirname(PHP_BINARY)` — the calling SAPI's own executable, not
  PHP's directory. Under `apache2handler`, `PHP_BINARY` is Apache's own
  `httpd.exe`, so `dirname(PHP_BINARY)` pointed at Apache's bin directory
  and none of the candidates under it had ever existed; every prior test of
  this fallback ran under CLI PHP, where `PHP_BINARY` happens to genuinely
  sit inside PHP's own directory, so the fallback had never actually been
  exercised through a real request on any web server. Both files now
  resolve via `php_ini_loaded_file()` first, which every SAPI populates
  correctly.

- **Settings → Facilities kept showing a facility after it was deleted from
  the main Facilities screen.** A second, separate read path
  (`api/config-admin.php`) queried the table directly with no `deleted_at`
  filter — the same class of bug GH#52 fixed once already for
  `api/facilities.php`, in an endpoint nobody had touched since. Reported by
  Chris Byrd, Google Group.

- **A vehicle's owner went blank, with nothing to explain why, after that
  person was permanently removed from the roster.** `newui_vehicles.member_id`
  has no foreign key. Permanently deleting a member (the wastebasket "purge"
  action) already cleaned up `member_certifications` / `member_callsigns` /
  `member_organizations` / `member_comm_identifiers`, but never touched
  vehicles that member owned — left a dangling reference with nothing to
  resolve. Purging a member now clears them from any vehicle they owned.
  Soft-deleted members are also excluded from the owner-selection dropdown
  going forward, the same GH#52-class gap as the Facilities fix above, in a
  third read path. Reported by Chris Byrd, Google Group.

- **A `proc_open` pipe deadlock froze the entire Zello proxy on Windows, and
  silently truncated synthesised speech everywhere.** `stream_set_blocking()`
  cannot put a `proc_open` pipe into non-blocking mode on Windows: it returns
  `false` and the stream stays blocking. Two functions written independently —
  `ZelloProxyApp::runPipe()` and `tts_run_pipe()` — both relied on it working,
  and in both the timeout guard sat *after* a blocking read, so the guard was
  unreachable and could not fire. `runPipe()` blocked waiting for stderr bytes
  Piper never sends while the child filled the stdout pipe buffer; because it
  runs inside a ReactPHP loop, **the whole proxy stopped** — no upstream
  traffic, no browser clients served, recovery by killing `piper.exe` by hand.
  `tts_run_pipe()` never drained stderr at all and then reported `ok=true`,
  `detail='ok'` with the audio cut to one buffer, which no caller could detect.
  Reproduced and measured on this project's Windows box: a verbatim copy of the
  `runPipe` loop captured exactly 16,384 of 200,000 bytes and then wedged past
  its 5 s guard until killed externally at 25 s; `tts_run_pipe` given a 1 s
  guard against a child exiting at 6 s returned at 6.11 s. All three descriptors
  are now temp files, so nothing can block on a full pipe in either direction
  and the deadline is reachable — the same cases now complete in 0.11 s and
  0.12 s with every byte captured, and a genuine timeout returns `null` instead
  of passing a truncated buffer off as audio. Behaves identically on POSIX. A
  third instance of the same pattern in `tests/test_dmr_bridge_http.php` (where
  it could have hung CI rather than failing it) is fixed too, and a tree sweep
  now gates any new pairing of `proc_open` with `stream_set_blocking`. Reported
  by Ron Jones (@rjonesbsink), with a working diff and before/after
  measurements. ([#28](https://github.com/openises/TicketsCAD/issues/28))

- **Spoken read-outs played roughly 1.5× too slowly on Windows, underrunning on
  almost every frame.** `pumpTtsFrame()` sent exactly one voice frame per
  periodic-timer tick, which is only correct if ticks land on schedule. A
  periodic timer is a floor, not a promise: Windows' default timer quantum is
  ~15.6 ms, so a 20 ms request rounds up to two quanta. Measured against the
  real `StreamSelectLoop` here — nominal 20 ms delivered a 31.15 ms mean
  (1.56×), nominal 10 ms delivered 15.32 ms — which stretched a 608-frame clip
  (12,160 ms of audio) to 18.29 s of wall time with 608 of 608 frames arriving
  after the receiver needed them. Audibly choppy on a live channel. How many
  frames are due is now derived from the wall clock rather than from a count of
  ticks, with the timer running at half the frame interval so a backlog always
  has a tick to ride out on. It is self-correcting — a late tick emits the
  backlog — and it cannot run fast, because the answer comes from the clock. A
  100 ms pre-roll banks a jitter buffer, without which a corrected *rate* still
  sounds rough as the receiver starts on frame 0 with nothing buffered; larger
  leads finish early and risk clipping the tail. The same clip now measures
  12.06 s (0.99×) with 1 underrun. The completion log reports the wall-vs-audio
  ratio, which is what made the problem measurable in the first place. The
  pacing arithmetic lives in `proxy/tts_pacer.php` so the code the proxy runs is
  the code under test. Reported and verified by ear on a real transmission by
  Ron Jones (@rjonesbsink).
  ([#28](https://github.com/openises/TicketsCAD/issues/28))

- **The TTS deployment guide's `ffmpeg` default is unreachable for a Windows
  service.** `zello_tts_ffmpeg_bin` defaults to the bare name `ffmpeg`, resolved
  on `PATH` — genuinely all it takes on Debian, which is what the guide
  described. The common Windows installers put it on the *per-user* `PATH`,
  while the proxy runs as a service (typically `SYSTEM`) whose environment has
  no such entry, so the lookup fails at the point of use rather than at
  configuration and the read-out just produces no audio. Documented, with the
  fix: give an absolute path. Reported by Ron Jones (@rjonesbsink).
  ([#28](https://github.com/openises/TicketsCAD/issues/28))

- **The diagnostics blamed a proxy or firewall for a failure that has no
  response code.** When the live-update stream timed out, the check said "a
  proxy or firewall is likely blocking the long-lived connection" regardless of
  what the browser actually reported. `EventSource.readyState=0` is CONNECTING:
  the request was accepted and *no headers ever arrived*, so there is no HTTP
  status to read — a genuinely different condition from a 502, and one the
  documented decision tree in `docs/TROUBLESHOOTING.md` could not express
  because every branch of it assumed the server answered. The reporter worked
  through proxy and firewall theories first, in that order, because that is what
  the application told him to check; the actual cause was IIS on Windows 11 Home
  capping concurrent requests at 3, which the navbar's stream and the
  diagnostics page's own second stream exhaust between them. `readyState=0` now
  says the request was accepted but never answered and names connection limits
  first; `readyState=1` is separated out as a buffering problem; and both the
  Windows/IIS install guide and the troubleshooting entry document the cap, how
  to measure it, and why a single tab needs two long-lived slots. Reported by
  Ron Jones (@rjonesbsink).
  ([#29](https://github.com/openises/TicketsCAD/issues/29))

- **The documentation sent operators to menu items that do not exist.** Nine
  runbooks, three security advisories and five places in the application's own
  text told the reader to open a settings menu item called **Status**. There
  has never been one: `inc/config-sidebar.php` renders that link with the label
  **System Health**. A second, **Backup**, was the same shape of wrong — the
  item reads **Backup / Maintenance**. One of the affected documents is a
  *Critical* security advisory, so the reader was mid-incident when they went
  looking for something that was not there. Sweeping the whole tree against the
  real menu turned up 130 more: *Communications* (the section is
  **Communications & Integrations**), *Maps & Places*, *Audit & Compliance*,
  *Active Sessions*, *Users*, *Web Push*, *FCC Lookup* and a dozen others,
  every one a name the menu has not carried for months. All are corrected.
  Two were worse than a stale label: **`docs/CJIS-POSTURE.md` and
  `docs/TROUBLESHOOTING.md` both described an audit-log retention setting, and
  an `audit-log-trim` cron, that have never existed** — `audit_log` is never
  pruned. Both entries now say so.

  This is the schema-mismatch pattern one layer further out — a document
  confidently naming something that is not there — and nothing could catch it,
  because nothing compared the words in the docs to the words in the menu. A
  human reviewing a training video found it. Now
  `tests/test_doc_navigation_labels.php` parses the labels out of the sidebar
  source on every run and fails on any documented settings path that names
  something else. It derives rather than lists, deliberately: a gate carrying
  its own copy of the menu would keep approving the old name after a rename,
  which is the same defect wearing a test's clothes. Paths belonging to a
  *third-party* app's Settings menu — ATAK, OwnTracks, the Traccar Client —
  are correct as written and are listed, with a stated reason each, in
  `tools/doc_nav_label_exceptions.txt`. One consequence worth knowing when
  writing a correction note: the gate cannot tell an instruction from a
  quotation, so name a wrong label in prose rather than as a path.

- **The Voice & Speech "Test" button could never play audio, on any install.**
  Synthesis worked, the engine reported green, the API answered `success: true`
  and the interface said it was playing a sample — and there was silence. The
  shipped Content-Security-Policy had no `media-src` directive, so `<audio>`
  fell back to `default-src 'self'`, and `'self'` does not cover the `data:`
  URI the endpoint returns. Nothing was misconfigured: it was the shipped
  policy against the shipped endpoint, so every install had it. The policy now
  carries `media-src 'self' data: blob:` and nothing wider — no hosts, no
  wildcards, and `default-src` is untouched. The same gap was silently blocking
  **live incoming Zello voice**, which is delivered to `<audio>` as a `blob:`
  MediaSource URL, so that is fixed by the same line. Separately, the test
  button used to announce success *before* calling `play()` and then discard
  every playback error, which is what made this undiagnosable rather than
  merely broken — the browser stated the exact reason and the code deleted it.
  It now reports what actually happened. Reported by Ron Jones
  (@rjonesbsink), who instrumented the playback path to recover the error the
  interface was throwing away. GH #27.

- **Deleting an incident did not stop it being served, and on the dispatch
  board it did not stop it being *worked*.** `deleted_at` was checked in the
  External API only when deciding whether a write could proceed, never when
  deciding what to return, so both the list and the detail endpoint handed back
  soft-deleted incidents in full — street address, caller name, narrative — with
  no field marking them as deleted. The same term was missing from
  `api/incidents.php`, which is what the dispatcher board and the dashboard
  widget read, and there the consequence was worse. An incident deleted while
  **open** keeps `status = 2`, so it matched the board query forever: one
  reported install had a deleted incident sitting on the board as a live open
  call for 22 hours. An incident deleted while **closed** reappeared whenever
  anything re-stamped `problemend`. Both read paths in both endpoints now filter
  soft-deleted incidents, as the four sibling External API endpoints already
  did; a deleted incident returns `not_found` rather than being served as
  though live. There is no `?include_deleted` option — deliberately; the
  wastebasket is the permission-gated place to look at deleted records.
  Reported by Ron Jones (@rjonesbsink), who then extended it to the internal
  board after noticing a deleted incident greyed out among live ones. GH #25.

- **Deleted incidents could not be recovered, because the wastebasket could not
  list them.** The wastebasket asked `ticket` for columns named `nature` and
  `address`. Neither exists — they are `scope` and `street` — so the query
  raised an error, the surrounding guard swallowed it and returned an empty
  list, and every soft-deleted incident was invisible in the one screen that
  exists to bring it back. This shipped alongside the fix above on purpose:
  closing the read paths without it would have left a mistakenly deleted
  incident hidden everywhere *and* unrecoverable, which is worse than the leak.
  Found while fixing GH #25.

- **GH #25's fix covered two endpoints; a sweep of the rest found 77 more
  places that read `ticket`.** Eric's own closing comment on the issue
  predicted it: "a grep for `FROM .*ticket` without a `deleted_at` term
  would probably find others. It does." This is that sweep, done properly
  rather than folded in silently. 53 were genuine gaps and are fixed:
  statistics, reports, the incident detail/list/search pages, the
  wall-display call board (a separate endpoint from the dispatch board GH
  #25 already fixed — same "deleted-while-open never ages out" bug),
  facility detail, call history, the External API's note-adding endpoint,
  PAR's overdue-broadcast roster, the unit list/detail "current assignment"
  displays, major-incident linking and cascade-close, the incident export to
  Winlink, event-zone coverage, and the canonical unit-assignment writer
  (`inc/assignment-write.php`) — a soft-deleted incident can no longer
  receive a new unit assignment through ANY path, dispatcher UI or API
  alike. The other 24 are left alone on purpose, with a stated reason each:
  audit/history views that must keep showing what happened even after the
  incident they're about was deleted (the audit log, a message's historical
  incident attachment, a logged time entry's incident label, a responder's
  personal ICS-214 timeline); the incident-number collision check, which
  must see a deleted incident's number to avoid reissuing it — the same
  shape of exception Eric named explicitly on the issue; a handful of
  internal config/permission resolvers that never serve incident content in
  the first place; and pre-wastebasket compatibility fallbacks. New
  permanent gate: `tools/soft_delete_audit.php` resolves the codebase's
  `$where`-array query-building idiom (not just literal SQL strings) well
  enough to tell a real gap from a query that already excludes deleted rows
  through a variable, and fails the suite on any NEW `ticket` read site that
  doesn't — so the next one added doesn't reopen this quietly. GH #25.

- **Timestamps were measured against the wrong clock on any server not set to
  UTC.** TicketsCAD stores date/time columns as local wall-clock time in your
  install's configured `area_timezone`, but several places compared those
  stored values against UTC. On a UTC server the two are the same instant, so
  this was invisible to us; on every other server the affected features were
  off by exactly your UTC offset — five hours for US Central. **If your server
  is not on UTC, you were affected.** No database changes are needed: the
  stored data was always correct, only the queries reading it were wrong. Pull
  and reload PHP.

  What you will see fixed:

  - **APRS map.** It no longer reports "0 stations in window" with a banner
    claiming "APRS-IS receive listener is not active. Last position received
    5h ago" while the listener is healthy. Station ages now read in seconds,
    and the listener status reflects reality.
  - **Mesh / ATAK packet ages.** Packets delivered by a bridge were stamped in
    UTC while packets logged directly were stamped locally, so the same table
    held two clocks. "Recently heard node" windows never expired (so bridge
    failover could pick a dead bridge), ATAK event ages could show as
    negative, and multi-bridge hop-latency figures were nonsense.
  - **External API tokens.** A token with an expiry date stopped working one
    UTC offset early — up to five or six hours before the time shown in the
    admin panel, which kept displaying it as Active the whole time.
  - **Unit staleness and "last updated" displays.** Units on the incident
    detail page and the units list were flagged stale when they were not, and
    unit history timestamps, the OwnTracks diagnostics page, and chat messages
    you had just sent all rendered one UTC offset off.

- **The DMR bridge could not work on Docker at all, and TicketsCAD sent the
  wrong bearer token to every bridge.** Two independent defects, both reported
  with exact reproductions by @kmk1971 against a private HBLink3 master
  ([openises/tickets#10](https://github.com/openises/tickets/issues/10)).

  - **The bridge's HTTP control surface never started on Docker.**
    `services/dvswitch/hbp_client.py` started its control server only when
    `DMR_BEARER_TOKEN`, `DMR_PIPER_BIN` *and* `DMR_PIPER_VOICE` were all set —
    but the two Piper variables appear nowhere under
    `services/dvswitch/docker/`, so on every Docker deployment port 18091
    never opened. The DMR side kept working and the entrypoint still printed
    "HTTP control on 18091", so the bridge looked healthy; the only symptom
    was the CAD unable to connect. The surface now starts on the bearer token
    alone — `/health`, `/audio-stream`, `/tx/audio` and `/tx/stream` never
    needed speech synthesis. Only `/tx/text` does, and it now answers 503
    naming the two variables to set. The container image ships without a
    speech engine by design (voice models are 50-110 MB, per-voice licensed,
    and language-specific); mount one to enable `/tx/text` — see
    `docs/RADIO-DMR-DOCKER.md`. The entrypoint no longer announces a port it
    has not bound.

  - **Every unattended call to the bridge answered 401.** The bearer token was
    stored as a SHA-256 digest, and eight callers then read that column and put
    it in an `Authorization: Bearer` header. Hashing at rest is right for a
    credential you *verify* and wrong for one you *present*: the CAD is a
    client here and needs the value, not a digest of it. Only the Test dialog
    worked, because it asks an operator to paste the plaintext by hand — so
    live audio, push-to-talk, health polling, weather read-outs and the
    radio-AI responder had never authenticated on any install. The token is
    now stored in the form the callers need; it is still shown exactly once,
    at mint time, and is never returned by any GET.

    **If you already have a DMR channel, its stored token is a hash and cannot
    be recovered.** `php sql/run_migrations.php` detects this and names the
    affected channels, and they are flagged **token unusable** in
    Settings → Communications & Integrations → DMR. Two ways to repair, both leaving the
    channel working: paste the token you saved at mint time into that
    channel's Test dialog — a successful `/health` is the bridge confirming
    the value, so TicketsCAD adopts it with no bridge restart — or rotate the
    token and update the bridge's `DMR_BEARER_TOKEN`.

  - Also from the same report: the **TX 0.5 s 1 kHz tone** button posted to
    `/tx/test`, which the native HBP bridge had never implemented (it 404'd);
    it is now implemented. The unused `channel_recent_calls` action proxied
    `/calls/recent`, which likewise does not exist there, and has been removed
    — `channel_recent_messages` already serves that panel from local rows and
    works while the bridge is offline.

### Added

- **A vehicle's owner can now be a specific agency, not just a person.** The
  only way to mark a vehicle as agency-owned was a boolean "Agency Vehicle"
  checkbox with no identity attached to it — so an agency-owned vehicle's
  owner column always read blank, which was the other half of the "Vehicle
  Owner … null records" report above once the dangling-reference bug was
  fixed. The Owner field on a vehicle is now a three-way choice — Person,
  Agency, or none — reusing the existing `organizations` table (the same one
  Settings → Organizations already manages; no new concept, no new
  admin screen). Picking an agency implies "agency vehicle" automatically,
  derived server-side rather than trusted from the client alone, so the
  existing privacy-redaction exemption for agency vehicles can't be defeated
  by a form that forgets to also check a separate box. An existing vehicle
  that only ever had the checkbox checked, with no specific agency, opens in
  the new form as Agency-type with no agency selected — pick one and save;
  no backfill or migration of old data required. Reported by Chris Byrd,
  Google Group.

- **Inbound routing to the sender's assigned incident — feature complete,
  closes GH #23 (Phase 134, Step 5 of 5, Model 3).** The last piece:
  `router_evaluate()` now calls `mi_attach_message_to_assigned_incidents()`
  (Step 3) unconditionally on every genuinely-new inbound message —
  independent of which, if any, routes match, and separate from the
  existing Phase 111 `attach_action='add_note'` mechanism, which serves a
  different purpose (a single designated "active event"). A new seeded
  route (`source_channel='*'`, `dest_channel='local_chat'`,
  `direction='inbound'`) is the Model 1 floor: every inbound message from a
  polled channel reaches general dispatch chat, resolved sender or not,
  assigned incident or not — both consumers run, never either/or, so a
  dispatcher watching general chat still sees a message go by even when it
  also landed on a specific incident. A message the router itself
  forwarded onward does not re-trigger the resolver a second time. No new
  RBAC permission — the resolution/attach logic runs unattended, not
  behind a user action. With this step, GH #23's full path (a field unit's
  Telegram or Slack message routing to the incident they're actually
  assigned to, instead of only general dispatch chat) is live end to end;
  polling itself still requires an operator to opt a channel in via
  Settings (Step 4), off by default. See
  `specs/phase-134-inbound-routing-model3/plan.md` for the complete
  5-step build record.

- **Inbound routing to the sender's assigned incident — the poller (Phase
  134, Step 4 of 5, GH #23 Model 3).** A new `tools/channel_receive_tick.php`
  scheduled job (60s, same shape as `par_tick`/`pending_messages_tick`) polls
  every broker channel that has declared itself pollable AND been opted in
  by an operator — two new Settings checkboxes, "Poll for inbound messages"
  on the Telegram and Slack panels, both off by default; nothing is ever
  polled without an explicit admin action. De-duplication now lives in
  `broker_receive()` itself rather than per-adapter: a message is
  `INSERT IGNORE`'d into the `inbound_message_dedupe` table (Step 1) before
  it is logged or routed, so Telegram's and Slack's eager cursor advancement
  (Step 2) can never double-ingest a re-delivered message, and any future
  poll-based channel gets the same guarantee automatically just by declaring
  a `dedupe_key`. A channel that repeatedly fails backs off on a capped
  exponential curve (1, 2, 4… up to 60 minutes) rather than hammering a
  broken upstream every tick; a successful poll clears the backoff
  immediately. `sched_job_required('channel_receive_tick')` follows this
  project's "shipped default is not usage" rule: the job reads as not-required
  on a fresh or CI install (both opt-ins default off) and turns required —
  naming which channel — the moment an operator flips either switch. Windows'
  `run-scheduled-jobs.bat` and the systemd timer docs in
  `docs/MAINTENANCE-RUNBOOK.md` both gained the third tick alongside the
  existing two. No inbound message is attached to an incident yet — that's
  Step 5, which wires the Step 3 resolver into the routing path; this step
  only gets messages flowing through the existing (currently empty) route
  set. See `specs/phase-134-inbound-routing-model3/plan.md` §9 for the
  remaining step.

- **Inbound routing to the sender's assigned incident — the resolver (Phase
  134, Step 3 of 5, GH #23 Model 3).** New `mi_assigned_incident_ticket_ids(int
  $memberId): array` (`inc/message-incident.php`) resolves a member to the
  open incidents their unit is currently assigned to, and a new sibling,
  `mi_attach_message_to_assigned_incidents()`, attaches an inbound message to
  every one of them — a sender assigned to two open incidents gets a note on
  both, per the spec's explicit v1 decision; no primary-unit gate is needed to
  ship this. The plan's original SQL sketch guessed a `responder.member_id`
  column; that column does not exist on this schema (confirmed via `SHOW
  COLUMNS`) — the resolver instead reuses the two real linkages
  `inc/comm_resolve.php`'s reverse function already established
  (`unit_personnel_assignments` for multi-person units, `responder.
  personal_for_member_id` for personal units), just walked in the opposite
  direction. An open assignment alone isn't enough to resolve: the query also
  joins `ticket` and filters `deleted_at` explicitly, because nothing cascades
  a soft-delete onto `assigns` rows — the exact "stranded assigns" class of bug
  this project has hit before. An unresolved sender, or a resolved sender with
  no open assignment, is a deliberate silent no-op here — the Model-1 general-
  chat fallback is a separate, later step (§6), not this function's job.
  `assigns` fixtures in the new tests are created and cleared through the real
  `assign_create_internal()`/`assign_update_status_internal()` writers, not
  hand-inserted, per this project's repeated "test asserts against state the
  real writer never produces" failure class; a ticket is soft-deleted through
  `incident_soft_delete_internal()` to prove the exclusion, not a hand-written
  `UPDATE`. No poller or router wiring yet — this step is the resolver and its
  tests only; see `specs/phase-134-inbound-routing-model3/plan.md` §9 for the
  remaining steps.

- **Inbound routing to the sender's assigned incident — real Telegram/Slack
  receivers (Phase 134, Step 2 of 5, GH #23 Model 3).** `_telegram_receive()`
  and `_slack_receive()` (`inc/channels/telegram.php`, `inc/channels/slack.php`)
  are no longer stubs — both are now thin, security-hardened wrappers around a
  real `getUpdates`/`conversations.history` fetch, each delegating all
  filtering to a pure function (`_telegram_parse_updates()` /
  `_slack_parse_messages()`) with no curl, no database, and no globals, so the
  filtering logic itself is testable with hand-built fake API responses rather
  than a live network call. Telegram's cursor (`telegram_update_offset`)
  advances past every update it sees — including traffic from an unrelated
  chat — so a burst of off-topic messages can never pin the poller forever;
  Slack's cursor (`slack_last_ts`) does the same across filtered-out bot/
  system messages. Slack additionally filters `bot_id` and any non-empty
  `subtype` (a trap Telegram's `getUpdates` never presents, so nothing in a
  Telegram-first reading of this code would have anticipated it) and resolves
  a configured channel *name* to its stable ID once, caching the result
  (`slack_resolved_channel_id`/`_for`) and invalidating the cache the moment
  the configured name changes — scoped to `types=public_channel` only, because
  Slack's `conversations.list` fails the *entire* call with `missing_scope`
  the moment `private_channel` is requested alongside a token that only has
  public access. Both channels' `broker_register()` entries gain
  `'pollable' => true` + a declared `dedupe_key` (`update_id` / `ts`) — a
  capability flag the not-yet-built poller (Step 4) will read, so channels
  that never opt in (starting with `local_chat`) are structurally excluded
  with no allowlist to keep in sync. The pre-existing security gate,
  `tests/test_telegram_channel_security.php`, is updated rather than weakened:
  its old invariant ("`_telegram_receive()` returns a bare `[]`") is
  necessarily gone now that the function does real work, replaced by three
  stronger checks driven through real child processes — fails closed with no
  bot token/chat id configured, fails closed on a malformed chat id, and
  (via a balanced-brace extraction of the function body, so a nested `if {
  }` can't truncate the match) never returns Telegram's raw API response
  directly into the routing engine, only through the chat-filtering pure
  function. No poller exists yet to call either receiver in production — this
  step is receivers and their tests only; see
  `specs/phase-134-inbound-routing-model3/plan.md` §9 for the remaining
  steps.

- **Inbound routing to the sender's assigned incident — dedupe table +
  channel seed (Phase 134, Step 1 of 5, GH #23 Model 3).** Foundation for
  routing a field unit's Telegram/Slack message to the incident they're
  actually assigned to, instead of only general dispatch chat. A new
  `inbound_message_dedupe` table (real `UNIQUE KEY (channel, external_id)`
  — verified by inserting the same pair twice through the migration, not
  by reading the DDL) will let the poller (a later step) advance its
  cursor eagerly without risking duplicate ingestion. Two new `comm_modes`
  rows (`telegram`, `slack`) let a member's Telegram username or Slack
  member id be recorded through the existing generic Roster → Comm/
  Location IDs UI — no new UI code needed. `inc/comm_resolve.php`'s
  sender-resolution reverse-map gains `telegram => username` and
  `slack => user_id`, verified end-to-end (a real member record resolves
  correctly from a real identifier row, including a check that a Slack id
  never accidentally resolves as a Telegram handle). This step is schema
  and seeds only — no live polling, no message ingestion; see
  `specs/phase-134-inbound-routing-model3/plan.md` §9 for the remaining
  steps.

- **Structured incident disposition — schema, seeds, and permission
  (Phase 132, Step 1 of 5).** Foundation for closing out GH #16: a new
  `ticket_disposition` lookup table (six seeded cross-discipline codes —
  Resolved / Handled, Unfounded, Cancelled, Duplicate Call, Referred to Other
  Agency, No Action Necessary — each with a stable, never-renamed `code` for
  export/integration alongside a renameable label) and `ticket.disposition_id`
  (nullable — every historical incident stays disposition-less forever, no
  backfill). A new setting, `disposition_required_on_close`, defaults **off**
  so upgrading never changes an existing install's close behaviour. Captions
  seeded in all five shipped languages. A new RBAC permission,
  `action.manage_dispositions`, gates *managing* the disposition list —
  scoped Super-Admin-only like `action.manage_config`; selecting a disposition
  on a call needs no permission (see the writer below). This step was schema
  and seeds only, no writer or API — see
  `specs/phase-132-incident-disposition/tasks.md` for the remaining steps.

- **Structured incident disposition — writer, close enforcement, API (Phase
  132, Step 2 of 5).** A disposition can now actually be set: `set_disposition`
  is a new `api/incident-update.php` action, and `update_status` accepts an
  optional `disposition_id` alongside a close. Every change — including a
  re-set to the same value — writes an `audit_log` row, so a disposition that
  changed late or was re-confirmed unchanged is traceable either way. A
  **retired** disposition can never be newly assigned, but an incident that
  already carries one keeps reading it back unchanged even after retirement —
  retiring only removes a choice from *future* selection. When
  `disposition_required_on_close` (Step 1, off by default) is turned on, a
  close with no disposition is refused with a dispatcher-facing message
  instead of a generic failure; an open incident with no disposition stays
  entirely normal, the gate only fires at the close transition itself.
  `auto_close.php`'s background sweep is deliberately **exempt** from this
  gate — the same Phase 129 PAR lesson applies here: a background close with
  no human present must not start silently failing every sweep the moment an
  admin turns on an unrelated setting. No UI yet (Step 3+); this step is the
  writer and API only.

- **Structured incident disposition — Settings panel (Phase 132, Step 3 of
  5).** A new "Incident Dispositions" panel (Settings → Application —
  Dispatch → Incident Dispositions) lets an admin add, edit, retire, and
  reactivate dispositions, and surfaces the `disposition_required_on_close`
  enforcement toggle in the same panel. Retiring is never a delete — a
  retired disposition stays visible in the admin list and on any incident
  that already carries it, it just can't be newly assigned going forward. A
  disposition's `code` (the stable export/integration key) is locked once
  created; only the label, description, discipline, org scope, sort order,
  and requires-comment flag can change later. A new standalone endpoint,
  `api/dispositions.php`, gates on the dedicated `action.manage_dispositions`
  permission from Step 1 rather than the broader `action.manage_config` —
  deliberately not folded into `api/config-admin.php`, whose shared gate
  would have defeated the point of a separate permission. Incident-detail
  dropdowns and reports/export are Steps 4-5, still to come.

- **Structured incident disposition — incident-detail dropdowns (Phase
  132, Step 4 of 5).** A disposition can now be set from an incident
  itself, not just via the API: a dropdown beside the Activity Log's note
  box lets a dispatcher set or change the disposition at any time, and a
  second dropdown appears in the close-action controls when closing,
  pre-filled with the incident's current value if one is already set. Both
  are filtered to the incident type's discipline (`in_types.group`) plus
  any always-offered (`discipline=''`) dispositions — with a **hard
  invariant**: a type with no discipline tag, or one that matches no
  active disposition, falls back to the FULL active list rather than ever
  showing an empty or truncated dropdown. The filtering is presentation
  only; the server still only enforces existence and active status.  When
  `disposition_required_on_close` is on and no disposition is available, a
  close attempt is blocked client-side with a clear message before it ever
  reaches the API — the server's own refusal (Step 2) remains the real
  enforcement boundary. New read-only endpoint `api/dispositions-picker.php`
  (no `action.manage_dispositions` required — mirrors `api/un-statuses.php`'s
  pattern of a small reference-list endpoint, distinct from Step 3's
  admin-only `api/dispositions.php`).

- **Structured incident disposition — reports, export, feed (Phase 132,
  Step 5 of 5 — feature complete, closes GH #16).** The Incident Summary
  report gains a disposition breakdown alongside its existing incident-type
  breakdown, with every undispositioned incident counted under "No
  Disposition" rather than dropped from the totals — the NULL case is the
  normal state for most historical incidents, not an error. The incident
  export target gains `disposition_code` (the stable code, never the
  renameable label), and the live-incidents feed (JSON/RSS/Atom) carries
  the same code for any open incident that already has one set. Reaching
  a value from a JOINed table (`ticket_disposition.code`, not a same-table
  rename) needed more than the export system's existing same-table
  `legacy` alias, so `inc/import-export.php` gained a small, generic
  `joins` + `sql` mechanism — kept minimal, and every other export target
  (which declares no `joins`) produces byte-for-byte the same SQL as
  before.

- **Audit-log retention and purge is a real, working setting.** A prior
  session, asked why CJIS's 365-day retention floor was satisfied, was told to
  document that the audit log is simply never pruned — and Eric rejected that
  outright: "This is an issue to fix, not redefine. The solution is to build
  the setting and ensure it works." A new setting,
  `audit_log_retention_days` (Settings → Audit Log → Retention & Purge), is
  **off by default** (`0` = keep everything forever — upgrading never starts
  deleting anyone's history on its own). Turned on, a daily job — plus an
  on-demand "Purge now" button — **archives every row older than the
  threshold to a gzip-compressed file on disk before deleting anything**, so
  the live table shrinks but the record survives. A manifest table
  (`audit_log_purges`) records every run — cutoff, row count, archive
  filename, a sha256 to verify the file later, who or what triggered it — and
  every successful purge writes its own audit-log entry, after the delete
  commits, so the record of the purge outlives the purge. CJIS Security
  Policy §5.4 cites 365 days as a *minimum*; TicketsCAD warns, but does not
  block, a lower value — different agencies answer to different retention
  rules, and this software cannot know which apply to a given install. A new
  RBAC permission (`action.manage_audit_retention`, Super Admin only, same
  tier as `action.manage_config`) gates changing the setting or triggering a
  manual purge. This also resolves a real tension with this project's own
  tamper-resistance advice (revoking DELETE on the audit table from the
  application's DB user): the purge now probes for that condition *before*
  doing any work and fails loudly — visible on Settings and on the Scheduled
  Jobs status page — rather than silently doing nothing, which is exactly
  what "revoke DELETE" should be expected to cause. See
  `docs/AUDIT-LOG-REFERENCE.md` § Retention and `docs/CJIS-POSTURE.md` § 5.4.

- **Whether an unavailable unit appears on a PAR roll call is now your
  decision.** It used to be nobody's: the standby filter matched status names
  by substring, and "unavailable" contains "available", so a unit marked
  unavailable was dropped from every PAR roster on every install — by accident,
  invisibly, with no setting able to bring it back. The two statuses are now
  told apart properly (matched on the status *group*, `av` versus `unav`, and by
  prefix rather than substring), and a new setting at **Config → App
  Preferences → PAR Checks** decides what happens to the unavailable ones.
  **The default is to include them.** A PAR asks whether every crew committed
  to the incident is accounted for, not whether every crew is working, and an
  assigned unit that has gone unavailable may mean the apparatus is out of
  service *or* that the crew has stopped answering — from the console those look
  identical. Including them costs one extra acknowledgement when it is the
  former; excluding them lets a roll call report itself complete when it is the
  latter. Agencies that treat "unavailable" strictly as a vehicle state, and
  clear the assignment when a crew leaves, can switch it off. The trade-off is
  explained at the control itself and in the user guide and in-app help. Raised
  by Eric Osterberg as a decision that genuinely varies by agency.

- **ICS forms can be deleted.** Until now they could not be — by anyone. Once a
  form was saved it was permanent: no delete for its author, none for an
  administrator, no path at any privilege level. Reported by Chris Byrd, who
  noted he could switch a finalized form back to draft and still not remove it.
  ICS forms are operational records rather than UI clutter — a finalized
  ICS-214 is the documentary artefact of a real incident — so deleting one is
  treated as a records-retention decision: **a draft may be deleted by whoever
  created it**, **a finalized form is administrator-only** (the new
  `action.delete_ics_form` permission, held by Super Admin and Org Admin by
  default and grantable to any role from the Roles UI), and **every delete is
  soft**. Deleted forms move to Settings → Wastebasket, keep their contents
  intact, and restore in full. Nothing hard-deletes an ICS form — not the
  per-row purge button, not "Empty wastebasket", not any other path — and every
  delete writes an audit entry naming the form, its type, its incident if
  linked, who did it, and whether they acted as the author or as an
  administrator. Saving is now also refused for a form sitting in the
  wastebasket, so a deleted record cannot be edited back into existence without
  an administrator restoring it first.

- **Clock-consistency audit** (`tools/timezone_audit.php`). A static gate,
  wired into the pre-commit hook and CI alongside the schema and API-contract
  audits, that fails the build when a query measures a locally-stamped column
  against a UTC clock — including the PHP `gmdate()` and JavaScript `+ 'Z'`
  forms of the same mistake. This class of bug cannot be felt on the UTC
  machines CI runs on, so it needed a check that does not depend on the
  server's timezone to notice.

## [4.2.6] - 2026-08-03

### Fixed

- **On Windows, sending a TTS message over Zello could freeze the entire proxy
  for up to 30 seconds — not just that message, every dispatcher's connection.**
  `stream_set_blocking()` does nothing to a `proc_open` pipe on Windows; the
  stream stays blocking regardless. Two independently-written functions relied
  on it working, and in both the timeout guard sat after a blocking read, so it
  could never fire. One case measured stuck at exactly one pipe buffer —
  16,384 of 200,000 bytes — until killed externally. Fixed by switching both to
  temporary files instead of pipes, which removes the blocking read entirely: a
  600 KB round trip that previously wedged now completes in about 0.1 seconds.
  A third instance of the same pattern was found and fixed in a test harness,
  where it would have hung CI rather than failed it. Reported by Ron Jones
  (@rjonesbsink).
- **Zello audio paced by counting timer ticks instead of the clock ran 50%
  slow on Windows and dropped nearly every frame.** Windows' ~15.6ms timer
  granularity means a nominal 20ms tick actually lands around 31ms. Pacing now
  derives how many frames are due from the wall clock rather than counting
  ticks, so a late tick catches up instead of compounding: a 12-second clip
  that previously took 18+ seconds and underran on 608 of 608 frames now plays
  in real time with 1 underrun. Reported by Ron Jones (@rjonesbsink).
- **Documentation named a Settings menu item that does not exist.** The labels
  "Status" and "Backup" appeared across install guides, runbooks and advisories
  as if they were Settings sidebar entries; the real labels are System Health
  and Backup / Maintenance. A gate
  now derives the actual navigation labels from the source that renders them and
  fails on any documented path that doesn't exist — 159 stale paths corrected in
  this pass, including two that described a compliance mechanism (an audit-log
  retention setting) that had never been built. That gap is being closed
  properly in a follow-up release rather than left as a documentation note.

## [4.2.5] - 2026-08-03

A correctness release. The most important item closes a bug that could tell a
dispatcher every unit was clear while a crew was still assigned.

### Fixed

- **On MySQL 8.0, auto-close reported "all units clear" and closed incidents
  with crews still assigned.** `clear = ''` throws error 1525 on MySQL 8.0 —
  an empty string is not a valid `DATETIME` — and `inc/auto_close.php` caught
  the failure and returned `0`, which is the specific value that authorises a
  close. The audit log then recorded the closure as fact. Nine sites carried
  the same literal, two of them beyond the seven originally reported: one was
  written `` `clear` = '' `` with backticks, so a search for the plain spelling
  missed it and it had no `catch` at all, throwing out of every status change
  through a conditional edge; another had the same fault on `ticket.deleted_at`
  where the fallback query dropped *both* the `deleted_at` and the `status`
  filter, so the incident picker silently listed deleted and closed incidents.
  The count now returns `-1` and logs when it cannot be taken, auto-close
  declines, and the sweep skips **without discarding the schedule**. Reported by
  Ron Jones (@rjonesbsink), who also supplied a fix.
- **The "Test" button under Voice & Speech could never play audio, and live
  Zello voice was blocked by the same gap.** The Content-Security-Policy had no
  `media-src`, which does not fall back to `'self'` for `data:` or `blob:` URIs
  — both are opaque origins. `api/tts.php` returns a `data:` URI and the Zello
  widget binds a `MediaSource` object URL, so both were refused. The Test button
  also announced success *before* calling `play()` and discarded the failure in
  an empty `catch`, which is why this presented as unexplainable rather than
  merely broken. Reported by Ron Jones (@rjonesbsink).
- **Soft-deleted incidents were returned in full by the External API and the
  dispatcher board.** The read paths in `api/external/v1/incidents.php` and
  `api/incidents.php` had no `deleted_at` term; only the write guards did.
  Fixing it surfaced a second defect: the wastebasket's own projection asked
  `ticket` for columns that do not exist, so the query threw, the guard
  swallowed it, and deleted incidents were **invisible in the recovery UI** —
  hidden everywhere *and* unrecoverable. Reported by Ron Jones (@rjonesbsink).
  Note this release fixes the two reported endpoints; a wider pass over the
  remaining incident read paths is tracked separately.
- **Documentation named a menu item that does not exist.** Guidance across the
  runbooks, install guides and security advisories pointed readers at a
  Settings sidebar entry called "Status". The menu item is **System Health**;
  the entry some docs called "Backup" is **Backup / Maintenance**. An operator
  following a Critical advisory went looking for something that was not there.
  The published advisories have been corrected in place, and a gate now derives
  the real labels from the navigation source and fails on any documented path
  that does not exist.

### Added

- **PAR roll calls can now include units marked unavailable, and do so by
  default.** Previously an `available`/`unavailable` comparison matched by
  substring, so a unit marked *unavailable* was dropped from the roster by
  accident. Matching is now exact. Whether an out-of-service unit belongs in a
  roll call is an agency decision, so it is a setting
  (`par_include_unavailable_units`) documented at the control and in the user
  guide. **The default is to include them**: an assigned unit that goes
  unavailable may mean the apparatus is out of service, or that the crew has
  stopped answering, and those look identical from the console. Including costs
  one extra acknowledgement; excluding lets a roll call report itself complete
  while a crew is unaccounted for.

## [4.2.4] - 2026-08-03

**A security release, and if you run TicketsCAD on Windows it is urgent.
Updating to 4.2.3 is what created two of the problems below — no
misconfiguration was needed and no instruction had to be followed.**

Three advisories accompany this release. Two are new; the third was published
with 4.2.3 and has been corrected, because the one-minute self-check it told you
to run does not prove what it said it proved.

- **[GHSA-p579-pg9g-fvw5](https://github.com/openises/TicketsCAD/security/advisories/GHSA-p579-pg9g-fvw5)**
  — Critical. 4.2.3 moved database backups "above the web root", computed as the
  parent of the install directory. That is correct on Linux (`/var/www/newui` →
  `/var/www`) and **inverted on Windows**: `C:\inetpub\wwwroot\TicketsV4` gives
  `C:\inetpub\wwwroot`, the document root of IIS's Default Web Site on port 80.
  XAMPP does the same thing. So on those hosts the upgrade moved every archive
  out of one published directory into another one.
- **[GHSA-3jmh-c6f6-64jc](https://github.com/openises/TicketsCAD/security/advisories/GHSA-3jmh-c6f6-64jc)**
  — High. The same mistake, one directory over: the RSA field-encryption private
  key and the 2FA encryption key were written to `<install>/../keys`, which is
  outside the web root on Linux and inside a served one on Windows. That
  directory was confirmed reachable — a control file in it returned HTTP 200.
  `private.pem` returned 404 only because IIS ships no MIME mapping for `.pem`,
  which is an accident of the file's name rather than a control, and **Apache
  serves it as plain text**.
- **[GHSA-rrp6-pqhj-w5wj](https://github.com/openises/TicketsCAD/security/advisories/GHSA-rrp6-pqhj-w5wj)**
  — Critical, published with 4.2.3, **now corrected**. It told you to request
  `https://your-site/backups/` and read a `403` as "blocked". On a real install
  the folder answered `403` while the archive inside it answered `200` and
  downloaded in full. Any server with directory listing off and no rule denying
  files behaves that way, and on Apache that is the default.

### If you checked before, check again

The old check was wrong, so a clean result from it means nothing. Ask for **an
actual archive by name** — get a filename from Settings → Backup / Maintenance — and ask every
site and port your server publishes, not only the one TicketsCAD runs on:

```bash
curl -s -o /dev/null -w 'archive %{http_code}\n' \
     https://your-site/backups/ticketscad-20260728-020000.zip
```

`403`, `404` or `401` on a real filename is good. `200` means that archive is
being served. A `403` on the folder proves nothing either way.

### Security

- **Backups and encryption keys now default outside every site root on Windows**
  (`%ProgramData%\TicketsCAD\...`). POSIX defaults are unchanged, because they
  were correct. **Nothing is moved for you** — an interrupted key move is worse
  than the exposure it would fix, and an install whose keys are already in the
  old location keeps using them, so upgrading cannot break field encryption or
  lock every 2FA user out. Settings → System Health gains rows that grade both
  directories, prove reachability with a short-lived random-token canary, print
  platform-correct move instructions, and say plainly what they could not see.
- **The exposure check can no longer answer "safe" from a directory request.**
  It names a real archive, or writes a canary and asks for that back, or reports
  a distinct grey **"Not determined"**. An install with no backup yet is
  reported as untested, which is not the same as safe.
- **The IIS `web.config` files shipped in 4.2.3 did not deny anything** — they
  returned HTTP 500.19 on a stock install, so the directory was blocked by the
  file being invalid rather than by the rule working. Three independent defects,
  each fatal alone. If you see 500.19 on a directory after upgrading, **replace
  the file rather than deleting it**; deleting it restores the exposure.
- **Hidden Segments is no longer recommended anywhere.** Our own hardening
  documentation told IIS administrators to add segments for `backups`, `inc`,
  `sql`, `tools`, `tests`, `specs`, `vendor` and `keys`. That rule matches *any*
  path segment, so `vendor` also blocks `assets/vendor/` and serves every page
  unstyled — and it does not protect the directory either. If you applied it,
  remove those entries.

### Fixed

- **Windows system uptime** no longer depends on `wmic`, removed in Windows 11
  24H2. It falls back to PowerShell and, where neither is available, says why
  instead of reporting "Unknown".
- **The routing engine reference documented filter keys the engine never reads.**
  An unrecognised filter key is ignored rather than rejected, so a rule copied
  from that page saved cleanly and then matched as though the condition were
  absent — a route meant to narrow to one incident type fired on all of them.

### Credits

Every security item in this release was reported by **Ron Jones**
([@rjonesbsink](https://github.com/rjonesbsink)), who tested what the shipped
fix actually did on his own server rather than assuming it had worked, and
reported each finding privately with a verified correction.

## [4.2.3] - 2026-08-02

**A security release. Please update, and please run the one-minute self-check
below even if you cannot update today.**

Two security advisories are published alongside this release. The first is the
more serious of the two and affects a default installation:

- **[GHSA-rrp6-pqhj-w5wj](https://github.com/openises/TicketsCAD/security/advisories/GHSA-rrp6-pqhj-w5wj)**
  — *Critical.* Private directories, including database backups, were served
  over HTTP on a default install.
- **[GHSA-984v-rw78-3223](https://github.com/openises/TicketsCAD/security/advisories/GHSA-984v-rw78-3223)**
  — *Moderate.* The External API's "require TLS" setting did not enforce TLS.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker:
`git pull && docker compose up -d --build`.

> ### ⚠ If you run behind a reverse proxy, one setting needs your attention
>
> This applies if something else terminates HTTPS and passes the request to
> TicketsCAD — Cloudflare, Nginx Proxy Manager, IIS ARR, a load balancer.
>
> The TLS fix works by no longer taking a request header's word for whether the
> connection was encrypted, and that header is exactly how your proxy tells
> TicketsCAD the original request was HTTPS. **List your proxy in the
> `trusted_proxies` setting**, or legitimate External API requests will
> correctly be refused with `426`. The default is `127.0.0.1,::1`, which covers
> the same-host case only. The refusal now explains itself rather than failing
> silently, but you still have to make the change.

### Check your own install — one minute

> **Amendment, 2026-08-02:** the backups line below originally read
> `curl .../backups/` — a request for the bare folder. Reported by Ron Jones
> (@rjonesbsink): a `403` there does not mean your backups are protected. It is
> the ordinary behaviour of a server with directory listing off and no rule
> denying the files inside, and a live install measured exactly that — `403` on
> the folder, `200` and a full database export on the archive inside it. If you
> ran the original version of this check and saw `403`, **re-run the corrected
> version below**; it asks for a file, which is the only request that actually
> answers the question.

```bash
curl -s -o /dev/null -w 'sql   %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools %{http_code}\n' https://your-site/tools/
```

`403` or `404` is good for both. **`200` means you are affected** — see the
advisory. `301`/`302` is inconclusive; re-run against the address you land on.

For backups, get a real archive filename from **Settings → Backup /
Maintenance** (it lists every archive this install has written) and ask for
that file specifically:

```bash
curl -s -o /dev/null -w 'archive %{http_code}\n' \
     https://your-site/backups/ticketscad-20260728-020000.zip
```

`403` or `404` is good. **`200` means that archive is being served.** If you
have no archive yet, take one first (Settings → Backup / Maintenance →
"Back up now") — until then this is **untested**, which is not the same as
protected. From this release onward TicketsCAD runs these same checks against
itself and reports the answer on **Settings → System Health**, in the *Web
exposure* row.

### Security

- **Your database backups were downloadable from the web, with no login.** The
  install instructions point the web server at the application folder, so every
  directory in it was published unless an administrator had blocked them by
  hand — and nothing that shipped told them to. `backups/` was the worst of it:
  a complete database archive, including every password hash. `sql/` and
  `tools/` were browsable, `inc/db.php` served the database credentials, and
  `sql/run_migrations.php` *executed* when requested over HTTP. Confirmed from
  the public internet against a live install, not inferred. Four independent
  layers now ship: backups moved above the web root, deny rules in the
  repository for Apache and IIS, an nginx snippet plus documentation, and a
  CLI-only guard on every script under `sql/` and `tools/` that works on any
  web server in any configuration. See GHSA-rrp6-pqhj-w5wj, which includes
  what to do if your backups directory was exposed.
- **The External API's "require TLS" setting did not require TLS.** With the
  setting on, a plain-HTTP request carrying a valid token was answered `200`
  with real data instead of `426`. Two independent bypasses: the check trusted
  the caller-supplied `X-Forwarded-Proto` header, which defeats it on **every**
  web server, and on IIS it additionally never fired at all, because IIS
  reports plain HTTP by setting a variable to the string `"off"` and the check
  asked only whether that variable was empty. Reading data still required a
  valid token, so this is not an authentication bypass — but a control the
  operator switched on reported success while doing nothing, so integrations
  were configured over plain HTTP and kept working. Reported privately by
  [@rjonesbsink](https://github.com/rjonesbsink). See GHSA-984v-rw78-3223.
- **Outbound webhook deliveries now carry replay protection, and the
  integrator guide now matches the wire.** Deliveries were signed over the
  request body alone, with no timestamp, nonce or delivery id anywhere in the
  request — so a captured delivery re-sent unchanged at any later time still
  verified as authentic, and nothing in it could justify rejection. Deliveries
  now carry `X-Webhook-Timestamp` inside the signed material and
  `X-Webhook-Delivery` as a stable idempotency key.

  **This does not break existing receivers.** The new scheme arrives as
  `X-Webhook-Signature-V2`; `X-Webhook-Signature` keeps exactly its current
  meaning until you set `webhook_legacy_signature` off. `webhook_replay_
  tolerance_sec` (default 300) sets the advertised freshness window.

  **If you build a receiver, re-read the guide.** It previously described a
  timestamped scheme, a `delivery_id` and a JSON envelope that were never
  implemented, and omitted the `sha256=` prefix the code actually sends — so a
  receiver written from it computed the wrong digest *and* compared it against
  the wrong string, and rejected every genuine delivery. `docs/WEBHOOKS-
  INTEGRATOR-GUIDE.md` now describes what is actually sent.
- **Saving a Settings panel wiped the stored secret it never showed you.** The
  panels mask secrets on display, then wrote the mask back on save, silently
  destroying the stored value.
- **The CAD sent the DMR bridge a token hash instead of the token**, and the
  bridge's Docker control surface never started at all.
- **Subprocesses are now spawned without a shell**, closing a class of command
  injection, and two probes that had never worked were fixed.
- **The geocoder gate was itself reachable over HTTP.**

### Added

- **Map tiles can be proxied by your own server.** `tile_mode` is now real: a
  server-side tile proxy with a per-provider policy, so installs behind a
  restrictive network — or blocked by a tile provider's Referer rules — can
  still show a map.
- **Net check-ins can be captured in one keystroke** and worked entirely from
  the keyboard, matching the rest of the dispatch interface.
- **A Telegram channel adapter**, with a test button and a setup guide.
  Contributed by [@rjonesbsink](https://github.com/rjonesbsink) as a pull
  request against the public repository.
- **The Geocoding Provider setting does something.** It was previously
  presented as a choice that had no effect.
- **Address lookup is reported on the Status page**, emitter and reader
  together.

### Fixed

- **An internet outage stalled every dispatch action for 21 seconds.** Outbound
  calls now have gated timeouts, and the notification sweep no longer pays a
  full timeout per row. What the product does and does not do without an
  internet connection is now documented and measured rather than assumed.
- **Web Push key generation now works on stock Windows PHP**, and Windows/IIS
  has a setup guide.
- **Background jobs never ran on Windows**, and the advice for fixing it said
  to run `systemctl`.
- **The web-server hardening rules denied `assets/vendor/`**, so Bootstrap and
  Leaflet returned 403 and the interface rendered unstyled. If you applied the
  hardening by hand before this release, take the corrected rules.
- **Two buttons on the unit form submitted the page** instead of running their
  handler — a `<button>` with no `type` inside a `<form>`.
- **Deploy no longer takes the operator's backup directory away from them**,
  and a permission repair can no longer abort an otherwise healthy deploy.
- **Zello reconnection backoff never escalated**, because transport-level
  success reset the counter.
- **A channel's destination is bound to its credential**, not to the message.
- **Map layers you turned off are remembered**, not only the ones you turned on.
- **The one label on incident detail that no administrator could translate** is
  now a caption key like every other.
- Two schema columns that only a fresh install ever received, and an
  `owntracks_outbox` column in the same position, are now created on existing
  installs too.
- Four reported defects where the product's own documented remedy was itself a
  dead end.

### Changed

- **New dashboard widgets are held to the interface conventions** by an
  automated gate, so they look and behave like the existing ones.
- **The release process can no longer silently revert public-only changes.** A
  release is a full-tree replace, so a pull request merged only in the public
  repository used to disappear at the next release with nothing to show for it.
  The snapshot now compares against the public repository and refuses to
  publish if it would discard anything.
- The SBOM now covers two packages our own installer installs but the bill of
  materials had missed.
- The README undercounted the test suite by a factor of four.

## [4.2.2] - 2026-07-30

A security and reliability release. **It closes a privilege-escalation hole in
the permissions system — please update.** It also revives two background jobs
that had never once run on a real install (one of them the Personnel
Accountability Report roll-call), fixes a clock bug that made a healthy radio
position feed look dead on any server not set to UTC, and repairs a test suite
that had been quietly counting empty files as passes.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker:
`git pull && docker compose up -d --build`.

> ### ⚠ The migration step is required this release, not optional
>
> Several fixes below *are* migrations. This release no longer guesses a user's
> permissions from the pre-v4 "level" column when their roles cannot be read —
> guessing is what the security hole was made of. An install that has not been
> migrated is therefore **refused at the login screen**, with the exact command
> to run printed on it. Anyone already signed in is not thrown out mid-incident;
> they see a banner instead. Running the migration clears it.

After upgrading, check three things:

1. **Settings → User Accounts** and **Settings → Roles & Permissions** —
   confirm every account has the role you expect. The one-time migration that
   assigns roles to accounts carried over from v3 had never actually worked,
   so some accounts may have had no role at all.
2. **Settings → System Health** — a new *Scheduled jobs* row reports
   whether the background jobs this install needs have ever run.
3. **Your scheduler.** If you use Personnel Accountability Reports, delayed
   message release, or automatic backups, confirm something on the server is
   really running the tick scripts — see
   [docs/MAINTENANCE-RUNBOOK.md](docs/MAINTENANCE-RUNBOOK.md). Dropping a file
   into `/etc/cron.d` on a machine with no cron daemon installed fails silently,
   and minimal cloud images routinely ship without one.

### Security
- **Privilege escalation: almost any signed-in account could edit roles and
  permissions.** The endpoint that manages the role system was itself guarded by
  the *old* permission system it replaced — and the old check ran first, so an
  account whose legacy "level" was 0 or 1 skipped the role check entirely. Since
  v4 stopped writing that column, every account created since reads as 0. In
  practice a Dispatcher — or anyone else — could grant themselves any
  permission. The endpoint now asks the role system, on the endpoint that
  manages roles. Six other endpoints (audit log, callsign lookup, compliance,
  vehicles, time entries, languages) carried the same "old system OR new system"
  shape and were closed with it.
- **The pre-v4 "level" system is gone, not deprecated.** It kept coming back
  after v4 declared it dead for one reason: the one-time migration that assigns
  roles to carried-over accounts had never run successfully on any install. It
  queried a column name that does not exist, the error was swallowed, the script
  reported success, and a silent fallback answered permission questions from the
  old column instead. Broken migration, hidden by a caught error, concealed by a
  fallback. The migration is fixed, it now re-checks its own result and fails
  loudly if any account was left without a role, and the fallback has been
  deleted. When permissions cannot be read, the answer is now **no**.
- **Duplicate and orphaned administrator grants.** The uniqueness rule meant to
  stop duplicate role grants never applied to organisation-wide grants, so every
  run of the migration pipeline appended another copy — hundreds on
  long-lived installs. Worse, the seed granted Super Admin to user number 1
  whether or not such an account existed, leaving grants addressed to nobody
  that a future account created with that number would silently inherit. Both
  are fixed and existing databases are cleaned up by the migration.

### Fixed
- **Organisation Admins could not run a single report.** The Reports page let
  them in and the reports API turned them away, because the two halves checked
  different permission systems. Reports now use a new `action.view_reports`
  permission, granted to Super Admin and Organisation Admin; the
  organisation-scoped filtering that was written for exactly this case now
  actually runs.
- **Personnel Accountability Report roll-calls never fired on their own.** The
  scheduled task that starts a PAR on cadence, and that marks a unit *missed*
  when its answer window closes, had never executed. PAR worked only if a
  dispatcher pressed **Initiate** by hand, and an unanswered roll-call produced
  silence. Restarting it needed care rather than enthusiasm: an overdue sweep
  with no upper bound would have raised missed-PAR alarms about incidents closed
  weeks earlier, and a life-safety alert about something that is not happening
  now teaches crews to ignore the one that is. Work more than
  `sched_stale_cutoff_min` minutes past due (default 60) is therefore recorded
  as *expired* and not acted on. Nothing is deleted, and an operator can release
  an expired message by setting it back to pending.
- **Turning PAR off froze it instead of quieting it.** Housekeeping was behind
  the same switch as the feature, so cycles in flight when you switched off
  stayed in flight — and switching PAR back on months later could resume a
  month-old roll-call and escalate it. Switching off now expires stale cycles
  quietly and starts nothing; nothing is ever escalated while PAR is off.
- **PAR was looking at closed incidents.** The scheduler and the rest of the
  feature disagreed about which incident statuses count as live, and the half
  that was wrong was the half that had never run.
- **Automatic backups were not being scheduled at all on some servers.** 4.2.0
  made the scheduler tick on page loads; this release documents and ships the
  supported way to schedule it on a server with no cron daemon (a systemd timer,
  with `Persistent=true` so a machine switched off at the scheduled hour backs up
  at next boot rather than skipping the day), plus the check that tells you
  whether a scheduler exists at all instead of assuming one does.
- **The APRS map reported "0 stations" while the receiver was healthy.** Position
  timestamps are stored in the install's local time; the map was comparing them
  against UTC, so on any server not set to UTC the window matched nothing and the
  page also claimed the listener was inactive. Ten more instances of the same
  mistake were found and fixed in the same sweep: mesh packet ages (which could
  read as negative), external API tokens expiring up to a full time-zone offset
  early while the admin panel still showed them active, several "last heard"
  ages in the browser, and the chat widget's own echo of a message you just sent.
  A new check runs on every build so this cannot come back — it is invisible on a
  UTC server and silently wrong everywhere else, which is most volunteer
  installs.
- **A fresh install reported itself as critically broken.** The new
  scheduled-jobs health check treated a security label that ships enabled by
  default as evidence the delayed-message queue was in use, so a brand-new
  deployment went red before an administrator had touched anything. It now looks
  for a message actually waiting in the queue.
- **`php tools/test_all.php` was counting silence as success.** The runner
  decided each file's result from one line of its output, so a file that stopped
  early — or exited cleanly without reporting anything — was printed exactly like
  a clean pass. Fourteen files, roughly 290 real checks, were contributing
  nothing to a headline number used as proof the release was sound. Files that
  report no result are now their own category, they turn the run red on their
  own, and their output is printed so you can see why. The suite reads **4434
  passed, 0 failed** on this release.
- **Documentation told you to check a log file that no longer proves anything.**
  Four places said an empty tick log means the job never ran. That is true of a
  cron line and false of the systemd timers that replaced it, which log to the
  journal — so the advice had inverted itself and now made a perfectly healthy
  job look dead. Replaced with checks that actually distinguish the two.

### Added
- `action.view_reports` permission (Super Admin and Organisation Admin).
- A **Scheduled jobs** row on Settings → System Health, fed by a
  heartbeat the background jobs write themselves — so it cannot report a run that
  did not happen. It goes red only for jobs this install actually needs.
- `sched_stale_cutoff_min` setting: how far past due background work may be
  before it is expired rather than acted on. Default 60 minutes; 0 disables.

### Changed
- Settings pages now require the administrative *manage configuration*
  permission rather than the broader *view settings* one, so an Operator no
  longer reaches them.
- The Software Bill of Materials was regenerated and re-signed for this version
  (it records the application version, so a version bump invalidates the old
  signature). **The signing key has not changed** — the published fingerprint is
  still `XRcJ3AwAm0OzSzjmU8KWkknftutwY36a6z7st2YrU0g=`, and the verification
  steps in [SECURITY.md](SECURITY.md) are unchanged.

### Removed
- The pre-v4 `user.level` permission fallback, its allow-lists, and the writing
  of that value into the session at login. Every gate in the application is now
  a role/permission check. An automated check runs on every build and fails on
  any comparison against the old column outside the short, reviewable migration
  path.

## [4.2.1] - 2026-07-29

Fixes the test suite that 4.2.0 shipped. **Nothing else changed** — no behaviour
change, no schema change, and every 4.2.0 artifact (the SBOM, its signature and
the public key) was correct and still verifies.

**Upgrading:** `git pull`. No migration. Docker: `git pull && docker compose up
-d --build` — but if you are coming from **4.1.x**, do the backup rescue in the
4.2.0 notes below **first**.

### Fixed
- **`php tools/test_all.php` reported two failures on a fresh clone.** Two
  assertions in `tests/test_sbom.php` inspect `tools/release-snapshot.sh`, which
  the release snapshot deliberately excludes from itself — so it is absent from
  every published copy by design. 4.2.0 was the first release to ship that test
  file, so the problem had never appeared outside the development repository.
  You were being told something was wrong when nothing was. Those assertions now
  skip when the release script is not present, and still run where it is.

  Verified in both shapes: 63 passed / 0 failed in the development tree, and
  60 passed / 0 failed with one skip in a fresh clone of the published v4.2.0
  tag — the exact place the failure showed up.

## [4.2.0] - 2026-07-29

Automatic backups now actually run, the Software Bill of Materials is published
signed so you can verify it yourself, and CSRF protection is enforced on six
endpoints where the check silently never ran.

A minor release rather than a patch: backup management is new functionality, not
a bug fix. (4.1.3 was tagged in the development repository on 2026-07-28 and
never published; its security content ships here.)

> ### ⚠ Docker installs: rescue your backups BEFORE you update
>
> Backups were being written inside the container, to a path that was **not** a
> volume. `docker compose up -d --build` — the documented update step — replaces
> the container and discards that layer. Taking a backup and then updating
> destroyed the backup in the same breath.
>
> This release moves backups into a named volume, but a volume is seeded from
> the image, never from a running container, so **existing backups cannot be
> migrated automatically.** Copy them out first:
>
> ```bash
> # 1. Copy the backups out of the running container FIRST:
> docker compose cp app:/var/www/html/backups ./backups-rescued
>
> # 2. Now pull and rebuild (this is the step that would have destroyed them):
> git pull && docker compose up -d --build
>
> # 3. Put them back, into the volume this time:
> docker compose cp ./backups-rescued/. app:/var/www/html/backups
> docker compose exec app chown -R www-data:www-data /var/www/html/backups
> ```
>
> If step 1 reports that the path does not exist, you had no on-container
> backups and there is nothing to migrate — just rebuild. Full procedure in
> [docs/DOCKER.md](docs/DOCKER.md) §4. `uploads/`, `cache/` and `keys/` were
> already volumes and are unaffected.

**Upgrading:** `git pull` then `php sql/run_migrations.php`. Docker: do the
rescue above, then `git pull && docker compose up -d --build`.

After upgrading, check **Settings → Backup / Maintenance**. Automatic backups
may have been switched on for a long time without ever having produced a file.

### Added
- **Automatic backups that run.** The scheduler function had existed since 4.1.0
  and was called from nowhere — an install without cron or Task Scheduler (the
  common case, and the exact case the feature was written for) reported backups
  as ON and produced nothing. Page loads now tick the scheduler, after the
  response is sent, never on a save.
- **Backup controls** in Settings → Backup / Maintenance: enable/disable,
  interval, retention by count/age/size, backup directory, and a **Back up now**
  button. A **Backups card** on Status → System Health goes amber when backups
  are stale and red when one was refused or none has ever succeeded.
- **Backups cannot fill the disk.** A free-space floor (default 1 GB, checked on
  both the backup and temp filesystems, which are often different) and a folder
  ceiling (default 5 GB). On this hardware a full disk is not degradation, it is
  an outage, possibly mid-incident. The newest archive is never deleted, and the
  first backup is never blocked. Retention now matches only files this
  application wrote, so it can no longer delete unrelated archives that happen
  to share the directory.
- **A signed Software Bill of Materials.** `SBOM.cdx.json` (CycloneDX 1.6, 56
  components) ships with a detached signature `SBOM.cdx.json.sig` (ECDSA P-256 /
  SHA-256) and the public key needed to check it,
  `SBOM-signing-key.pub.pem`. Verify it yourself, without contacting us:

  ```bash
  base64 -d SBOM.cdx.json.sig > sbom.sig
  openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json
  # -> Verified OK
  ```

  or `php tools/generate-sbom.php --verify`. This closes the last of the 17 data
  fields in CISA's 2026 SBOM Minimum Elements: TicketsCAD now meets **17 of 17
  data fields and 6 of 6 practices**. `SBOM.txt` is the human-readable
  rendering. See [SECURITY.md](SECURITY.md).
- **A tracked `VERSION` file**, so the version you see is the code you are
  running.

### Security
- **CSRF was not enforced on six code paths.** `api/messaging-send.php`,
  `api/push-admin.php`, `api/talkgroups.php` (its POST branch and, separately,
  its DELETE branch), `api/aprs-watchlist.php` and `api/aprs-license-accept.php`.
  Five wrapped the gate in `if (function_exists('csrf_check'))` — naming a
  function that does not exist, so the check was skipped rather than failed. The
  talkgroups DELETE branch had no CSRF call at all. Verified against a live host:
  every endpoint now rejects a missing and a wrong token with a JSON 403 and
  leaves the row intact, confirmed by SQL rather than by the API's own reply.
- **Marked and Bootstrap are served from this repository, not a CDN.** Groups
  that operate disconnected should not lose page rendering with the uplink. The
  `marked` reference was also unpinned, so the browser ran whatever the CDN
  served that day; it is now 12.0.2, recorded in the SBOM with its hash.

### Fixed
- **Docker: backups were destroyed by the update that followed them.** See the
  warning above. `docker-compose.yml` now mounts a volume for `backups/` and the
  entrypoint creates it writable.
- **The displayed version could never change.** `NEWUI_VERSION` was defined only
  in `config.php`, which is gitignored, so a completely correct `git pull` left
  the About page showing the install-day version — one install reported
  `4.0.0-dev` against 4.1.3 code. The tracked `VERSION` file now wins, with
  `config.php` as a fallback for odd deployments. Asset cache-busters finally
  move on a pull, too.
- **A fatal error in an API returned an empty body.** A PHP `Error` (TypeError,
  ArgumentCountError, a failed `require`, memory exhaustion) escaped
  `catch (Exception)` and, with `display_errors` off, killed the request *after*
  its writes had committed — reported in the field as "Unexpected end of JSON
  input" on an action that had actually worked. `inc/api_guard.php` now converts
  any fatal into a JSON 500 with a log reference.
- **SOP Markdown had never rendered for anyone.** `sop.php` loaded `marked` from
  a CDN that the application's own Content-Security-Policy blocks, so it fell
  back to plain text on every install, online or offline. Nobody reported it
  because the fallback is readable.
- **Five documents told administrators to `chown -R www-data:www-data .`** That
  takes `.git` with it, so the reader's next `git pull` stops with "fatal:
  detected dubious ownership" — and it was never necessary. Corrected: the tree
  stays with whoever runs git; `uploads/` and `cache/` go to the web server;
  `backups/` is shared (mode 2770) because both the CLI and the web server
  write there.
- **Soft-delete columns never reached upgraded installs.**
- **MySQL 8.0 rejected `dashboard_layouts` at install time**, and hid the reason.
- **The mesh bridge delete endpoint returned an empty JSON response.**
- **Settings silently blanked stored secrets** when a masked field was saved
  untouched, and masked boolean toggles as though they were secrets.
- **The SBOM declared CycloneDX 1.6 and did not conform to it.** One component
  (`mysql-connector-python`) carried the licence identifier
  `GPL-2.0-with-FOSS-exception`, which SPDX does not define, so the document
  failed the official schema outright and would have been rejected by
  Dependency-Track, Trivy and anything else that validates. The licence is now
  the SPDX expression `GPL-2.0-only WITH Universal-FOSS-exception-1.0`, taken
  from Oracle's own `LICENSE.txt`. More to the point, **nothing had ever checked
  the claim**: the generator now validates its output against the official
  CycloneDX schema — vendored unmodified at `tools/schema/cyclonedx/` so you can
  check it offline — and refuses to write a document that does not conform, with
  `php tools/generate-sbom.php --validate` enforced in CI and in the release
  script.
- **The prior SBOM contained incorrect entries.** Everything published before
  this release listed `qrcode 1.5.3` by soldair, which this application does not
  use — it ships `qrcode-generator 1.4.4` by Kazuhiko Arase, a different project
  by a different author. It also listed `pymysql` and `meshcore-cli`, neither of
  which is imported anywhere; the real packages are `mysql-connector-python` and
  `meshcore`. **Anyone who matched those entries against vulnerability data was
  checking the wrong software, and would have missed advisories for the software
  they are actually running.** It further listed 20 of 31 Composer packages, all
  at stale versions, and gave every browser library the version string
  `"bundled"`, which matches nothing. It had not been regenerated since
  2026-06-13 and still described `4.0.0-dev`. Rebuilt from the shipped files
  themselves, 32 components to 56. The release script now also verifies the SBOM
  against the tree that is actually **published**, which caught two further
  errors before this release shipped: a component listed that only a
  development-notes file referenced, and a per-file hash that did not match the
  file as shipped.

### Known limitation
The signature is **detached** (`SBOM.cdx.json.sig`). CycloneDX 1.6 also defines
an in-document `signature` property, which this release does not use — so
`cyclonedx-cli verify` reports no signature even though the detached one is
valid. Use the `openssl` command above, or `--verify`. Native in-document
signing is planned for a follow-up release.

### Verified
The signature was checked with the OpenSSL **command line** — a different
implementation from the PHP extension that produced it — from a directory
containing only the three files a recipient receives, and from a fresh clone of
this repository. A one-byte change to `SBOM.cdx.json` is rejected. The SBOM is
byte-reproducible across operating systems, so you can regenerate it with
`php tools/generate-sbom.php` and compare instead of trusting us. Suite: 3880
tests passing.

## [4.1.2] - 2026-07-26

Fixes three things a **brand-new install** was missing. Found by doing something
that should have been routine and was not: cloning the public tag onto an empty
database and running the documented install steps. On its first run, the
self-check added in 4.1.1 reported that a fresh install did not satisfy its own
schema.

**Upgrading:** `git pull` then `php sql/run_migrations.php` (Docker:
`git pull && docker compose up -d --build`).

### Fixed
- **`responder_notes` was created by no migration.** Two endpoints created it
  on the fly just before writing, so *saving* a unit note worked — but three
  others read it, so on an install where no note had been written yet the Notes
  Log report queried a table that did not exist. It is now created at install
  time like any other table.
- **`permission_review_dismissals` had the same problem**, created on demand by
  the RBAC code. It is also one of the tables a user lost to crash recovery —
  and because nothing ever created it, no repair could put it back.
- **`user_tfa.last_used_counter`** (two-factor replay protection) only appeared
  the first time somebody enrolled in 2FA. It is now part of the schema from the
  start.
- **The schema check missed tables it only reads.** It covered tables written
  with an explicit column list, so a table the code merely reads from could be
  dropped without anything noticing — of four tables one user actually lost,
  only two would have been named. Coverage now includes every table the code
  touches: **169 tables, 1011 columns**, up from 128.

### Verified
On a genuine fresh install — public tag, empty database, documented steps — the
self-check passes. Dropping all four of the tables that user lost and running
the ordinary `php sql/run_migrations.php` (no flags) names all four, repairs
them, and passes the re-check; a team saves afterwards through the real save
path; and the Notes Log query works before any note exists.

## [4.1.1] - 2026-07-26

TicketsCAD can now check — and repair — its own database structure.

Every health check up to now was about *files*: permissions, stale code,
missing libraries. None of them could see the failure that actually costs
self-hosters their evening: a database whose **structure** has fallen behind the
code, so a screen loads fine and then refuses to save with a bare
`HTTP 400`.

**Upgrading:** `git pull` then `php sql/run_migrations.php` (Docker:
`git pull && docker compose up -d --build`).

### Added
- **`php tools/check-schema.php`** — reports exactly which tables and columns
  your database is missing, and changes nothing. **`--repair`** re-applies the
  schema migrations and re-checks in a fresh process. The migrations are
  idempotent and delete nothing.
- **A "Database schema vs this version" row** on Status → File & Code Health, so
  drift is visible before someone hits it.
- **A save that fails on a missing column now says so** — which column, that
  your data is intact, and the command to fix it — instead of an unexplained
  `HTTP 400`.
- [docs/TROUBLESHOOTING.md#schema-out-of-date](docs/TROUBLESHOOTING.md#schema-out-of-date).

### Fixed
- **The migration runner no longer reports health it has not verified.** It
  decided "already applied" from its own tracker table, which records whether a
  migration *script ran* — not whether the schema that script produced still
  exists. So if a table was dropped during crash recovery, or the database was
  restored from an older backup, every script still read as applied: the runner
  did nothing and reported everything up to date while the app was broken.
  Recovering required `--force`, and nothing suggested it. It now asks the
  database, and re-applies automatically when the two disagree.
- **The commit-time schema gate could not see any of the writers.** It examined
  each SQL string in isolation, but the code builds queries by concatenation —
  so the `INSERT` keyword and the column list never appeared together and all 89
  writer statements were skipped. That is why last release's `teams` problem
  reached a user instead of being caught. The gate now reads concatenated SQL,
  and a generated manifest of every column the code writes to (128 tables, 1008
  columns) is checked against your live database.

## [4.1.0] - 2026-07-26

A resilience release. Everything here comes from real installs run by real
people this week — a power loss that looked like total data loss, a Docker CAD
with no way to run the radio bridge, and a database table that existed in two
incompatible shapes at once.

**Upgrading:** back up first, then run the migrations as usual
(`php sql/run_migrations.php`). Two schema-normalizing migrations are included;
both are idempotent, neither deletes a row, and both report anything they cannot
safely decide rather than guessing.

### Added
- **Automatic backups, on by default.** A daily backup runs on its own — no
  setup, no scheduler required (it also works from cron or Windows Task
  Scheduler via `tools/backup_run.php`). Interval, retention and destination are
  configurable, and a warning appears if there has been no recent verified
  backup.
- **Backups are verified, not assumed.** Every archive is reopened and checked
  to contain a real database dump before it counts as a success.
- **A restore tool — `tools/restore.php`.** There previously was no way to
  restore a backup. It is dry-run by default, verifies the archive before
  touching anything, and takes a safety backup of the current database first, so
  restoring the wrong file is itself undoable.
- **`restore.php --drill` — prove a backup restores.** Restores a backup into a
  throwaway database, reports how many tables and rows came back next to what is
  live, then drops it. Your real database is only read.
- **Docker deployment for the DMR bridge** — `services/dvswitch/docker/` and
  [docs/RADIO-DMR-DOCKER.md](docs/RADIO-DMR-DOCKER.md). Runs the bridge and its
  AMBE vocoder together, configured entirely by environment variables, and
  refuses to start if the vocoder is not answering (a bridge with a dead vocoder
  otherwise connects normally and passes silence).
- **[A getting-started guide for beginners](docs/GETTING-STARTED-FOR-BEGINNERS.md)**
  — what TicketsCAD is, how to open it, the address-versus-folder gotcha, and
  free links for learning the command line, Docker and git.

### Fixed
- **A damaged database table no longer looks like an empty list.** After an
  unclean shutdown a single unreadable table could make a whole screen render
  empty — which reads as "my data is gone" when the records are safe. Affected
  screens now say which table is damaged, that the data is likely recoverable,
  and where the repair steps are.
- **Teams could exist with no name, and on some installs the Teams screen would
  not load or save.** The `teams` table had two competing definitions, so the
  columns an install ended up with depended on the order the setup scripts ran,
  and the built-in seed wrote to columns that later became read-only — leaving
  four unnamed teams. There is now one canonical definition, and
  `sql/run_teams_schema_normalize.php` brings any install onto it.
- **The same hazard is now impossible.** `member`, `member_types`,
  `member_status` and `constituents` were each defined by two different files as
  well; all are consolidated, and a test now fails the build if any table is
  ever defined twice again.
- **MySQL troubleshooting** for two situations that cost users an evening each:
  MySQL not starting or not staying running, and recovering a crashed table
  after a power loss. See [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md).
- Docker: the guide now states plainly that updating requires
  `git pull` **and** `docker compose up -d --build` — a pull alone does not
  update a running container.

## [4.0.6] - 2026-07-25

### Fixed
- After a crash or power loss with MySQL running, the dashboard could spin
  forever and look like a fresh install — because MySQL hadn't finished
  recovering and the incident tables couldn't be read yet. The data was never
  lost, but nothing said so. The dashboard now detects "database reachable but
  its tables can't be read" and shows a calm **"Your data is not lost"** page —
  with what to check and a link to recovery steps — instead of an endless
  spinner. A genuine, readable empty database (a real fresh install) is
  unaffected.
- Added a TROUBLESHOOTING.md section, "App looks empty / fresh install after a
  crash or power loss," with the safe recovery procedure (back up the data
  folder first, `innodb_force_recovery`, export, reimport) and prevention.

## [4.0.5] - 2026-07-24

### Fixed
- [docs/TRACCAR-SETUP.md](docs/TRACCAR-SETUP.md): documented that installs serving
  NewUI from a subdirectory (a "dual mode" setup where NewUI runs alongside the
  legacy app under `/newui/`) must include that prefix in the position-forwarder
  URL — e.g. `https://<host>/newui/api/location.php?provider=traccar`. A missing
  prefix is the usual cause of an HTTP 404 from Traccar's forwarder.

## [4.0.4] - 2026-07-24

### Added
- **[docs/HTTPS-SETUP.md](docs/HTTPS-SETUP.md)** — a step-by-step guide to putting
  HTTPS in front of TicketsCAD, with recipes for four situations: a public domain
  (Caddy + automatic Let's Encrypt), no open ports (Cloudflare Tunnel), a LAN with
  a domain (Caddy + DNS validation), and a LAN with no domain (mkcert).
- For installs that deliberately run on plain HTTP, an **administrator can now
  acknowledge** the "not encrypted" reminder. Acknowledging quiets it for 7 days,
  after which it returns on the next admin sign-in and must be re-acknowledged
  (each acknowledgment is audit-logged) — so the reminder can be quieted without
  ever being permanently forgotten. Non-admins and the login page keep the gentle
  dismissible note.
- Diagnostics now shows a "Connection encrypted (HTTPS): yes/no" row.

### Fixed
- Docker on small hosts (Raspberry Pi, low-RAM VMs): added troubleshooting for
  `container ticketscad_db is unhealthy` — the database container exiting before it
  becomes healthy, usually from out-of-memory (build + database competing for RAM),
  a 32-bit OS (MariaDB 11 is 64-bit only), or a half-initialized data volume. See
  [docs/DOCKER.md](docs/DOCKER.md).
- The "skip to content" accessibility link no longer lands on a warning banner
  (it now targets the page's real content).

## [4.0.3] - 2026-07-24

### Fixed
- Map overlays: renaming a map markup (marker, line, circle, or polygon) no
  longer erases its shape. A rename now updates only the name and leaves the
  geometry and colour intact. (GH #3)
- Location ingest (Traccar / OwnTracks / OpenGTS): opening the ingest URL in a
  web browser to test it used to return `{"error":"Not authenticated"}`, sending
  people to chase a non-existent authentication problem. The endpoint now answers
  a browser with a clear "this URL is correct — it accepts POST only, and this is
  not an auth failure" message. Position forwarding itself was always POST and is
  unaffected.
- Upgrade orchestrator (`tools/upgrade/run.php`): the one-command legacy → v4
  upgrade could fail two ways — the pre-upgrade database backup silently produced
  an empty file, and the schema-migration steps aborted with
  "Cannot redeclare step()". Both are fixed: the backup falls back to the built-in
  PDO dump when `mysqldump` can't authenticate, and each migration step now runs
  as an isolated subprocess.

## [4.0.2] - 2026-07-21

### Added
- Call-sign lookup: a new **OpenCallbook** provider ([opencallbook.com](https://opencallbook.com))
  that resolves both amateur radio **and GMRS** call signs in a single query, and
  is now the default provider. Configurable under Settings → Lookup Services, alongside
  the existing local-database, callook.info (amateur-only), and self-hosted
  FCC-ULS-API options.
- A configurable **lookup identity (User-Agent)** for internet call-sign lookups:
  send this site's name along with the software name and version (full), or the
  software name and version only (minimal).

### Fixed
- GMRS call-sign lookups returned "No Record Found" because the previous default
  (callook.info) only covers the amateur database. Installs still on that default
  are automatically migrated to OpenCallbook; deliberate offline choices (local
  database / self-hosted FCC-ULS-API / disabled) are left unchanged.

### Changed
- docs/DOCKER.md: expanded the "Upgrading" section — back up first, pin to a
  release tag, verify migrations ran, and how to roll back.

## [4.0.1] - 2026-07-20

### Added
- Docker: an optional `voice` compose profile that runs the Zello + DMR
  push-to-talk relays alongside the app — `docker compose --profile voice up -d`
  — reusing the app image (nothing extra to build). The app's Apache
  reverse-proxies the browser WebSocket paths (`/zello-ws`, `/dmr-ws`) to the
  relay containers. See docs/DOCKER.md section 8a. (The hardware DMR/AMBE bridge
  and Meshtastic still run on the host — they need a physical radio.)

## [4.0.0] - 2026-07-19

First public release of the NewUI v4 rewrite of TicketsCAD — a from-scratch,
keyboard-first dashboard rewrite of the legacy
[TicketsCAD](https://github.com/openises/tickets) Computer-Aided Dispatch system
(v3.44.x), keeping the same MariaDB schema so existing installs can upgrade in
place. See the README for the feature set and install instructions.

### Added
- Per-unit OwnTracks device tracking: a unit/vehicle can carry its own tracked
  device, provisioned from the unit's Location Sources.

### Fixed
- Mass-casualty bed counts: two units transporting to two different hospitals now
  decrement each facility independently. A receiving facility set on a unit's
  status is always that unit's per-unit destination.
- Incidents are referenced by their case number (not the internal database id)
  throughout close/note/create prompts, report exports, and the activity feed.

### Security
- The Settings API no longer returns stored secret values (SMTP / SMS / Slack /
  etc.) to the browser; secret fields report only whether a value is set.
