# TicketsCAD NewUI v4 — Training Curriculum

24 video modules, ~15 minutes each. Three tracks of 8 modules:

- **Dispatching** (modules 1–8) — the live-ops use case. Phone rings, address comes in, units roll.
- **Administration** (modules 9–16) — system setup, user/role management, integrations, security.
- **Tools** (modules 17–24) — roster, time keeping, scheduling, reports, member-facing features.

Each module's outline below contains:
- **Audience** — who should watch
- **Prerequisites** — earlier modules to watch first
- **Learning objectives** — what the viewer can do after
- **Outline** — beat sheet for the script (timestamps in minutes)
- **Demo footage** — the screens / actions to record
- **Companion reading** — the matching written-doc section

A trained AI agent producing the video should be able to:
1. Read the outline and matching written-doc section.
2. Produce a script (voiceover + on-screen captions) that matches the outline.
3. Produce a screen-capture plan that hits each of the demo points.
4. Render with a consistent NewUI v4 style (Bootstrap 5, dark/light theme parity, voice tone professional but warm).

---

# Track 1 — Dispatching (modules 1–8)

## Module 1 — Welcome to TicketsCAD: dispatch in 60 seconds

**Audience:** brand-new dispatchers. No prior CAD experience required.
**Prerequisites:** none — this is the entry point.
**Learning objectives:**
- Understand what TicketsCAD is and what problem it solves.
- Recognize the dashboard, command bar, and incident list.
- Know where to go for help.

**Outline:**
- 0:00–0:30  Cold open: phone rings, dispatcher types `/`, an incident is created in 30 seconds. (Shows the speed payoff.)
- 0:30–2:00  What TicketsCAD is — a CAD (Computer-Aided Dispatch) for volunteer fire / ARES / EMS / CERT / campus security. 30 years old, modernized.
- 2:00–4:00  Tour of the default dashboard. Eight widgets explained at 30-foot view: incidents, responders, facilities, stats, log, controls, comms, map.
- 4:00–6:00  Tour of the navbar: Dashboard, Incidents, Personnel, Communications, Settings, Help.
- 6:00–8:00  The command bar — slash to open, type a command. Quick demo: `/new` (start a new incident), `/who` (who's online), `/find` (unified search). Note: `/inc` and `/incidents` jump to the incidents widget — to *create* a new incident, use `/new`. When `/new` opens the form, the cursor lands in **Description** — start typing the call narrative; the regex auto-match selects the Incident Type and Severity for you on Tab.
- 8:00–10:00 Where to find help — the Help page, the keyboard shortcuts panel, the in-app tooltips.
- 10:00–12:00 What you'll learn in this curriculum — the 8 dispatch modules ahead.
- 12:00–13:30 Set expectations: "we'll go from 'never seen this' to 'comfortable at the keyboard during an incident' across the next 8 modules."
- 13:30–15:00 What to do next: log in to the demo install, watch module 2.

**Demo footage:**
- Login screen → Dashboard with all 8 widgets
- Hover each widget header to highlight
- Open command bar (slash)
- Open Help page

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Getting started"

---

## Module 2 — Logging in, sessions, 2FA

**Audience:** every user, not just dispatchers.
**Prerequisites:** Module 1.
**Learning objectives:**
- Log in.
- Enroll in TOTP 2FA.
- Use a backup code.
- Recognise a session-timeout and re-authenticate.

**Outline:**
- 0:00–1:00  The login page. Day/night theme. Username + password. (No more frame-based legacy UI — show a side-by-side comparison if time.)
- 1:00–3:00  First login: forced password change, profile setup.
- 3:00–6:00  Enrolling 2FA. QR code, authenticator apps, the enrollment wizard. (Show on Profile page.)
- 6:00–8:00  Backup codes — how to print/store, when to use them.
- 8:00–10:00 Trusted devices and session length. The "remember this device" checkbox.
- 10:00–11:30 What a session timeout looks like (you'll get a 401 in the middle of a workflow). How to re-authenticate without losing your work.
- 11:30–13:00 Lockout — what happens after 5 failed attempts. How an admin unlocks you.
- 13:00–14:30 The "no roles assigned" 403 — what it means and who to call.
- 14:30–15:00 Recap.

**Demo footage:**
- Login page screenshot (light + dark)
- 2FA enrollment QR + authenticator app (use Google Authenticator)
- Backup-codes screen + print preview
- Session-expired modal

**Companion reading:** `docs/INSTALL-ADMIN-GUIDE.md` § "Authentication" + `docs/RBAC-GUIDE.md` § "When something seems wrong"

---

## Module 3 — Creating an incident: keyboard-first

**Audience:** active dispatchers.
**Prerequisites:** Modules 1–2.
**Learning objectives:**
- Create a new incident in under 60 seconds.
- Use the keyboard exclusively (no mouse).
- Set incident type, severity, location, contact info.
- Trigger geocoding and use the smart-defaults.

**Outline:**
- 0:00–1:00  Cold open: phone rings, full incident creation in 30 seconds, narrated.
- 1:00–3:00  Open the new-incident form. Tab order. The 8 collapsible sections.
- 3:00–5:00  Incident type selector — search, autocomplete, protocol panel appears.
- 5:00–7:00  Location: address autocomplete. Forward + reverse geocoding. Smart city/state.
- 7:00–9:00  Patients, contact info, additional details — when to fill them in vs skip.
- 9:00–11:00 Auto-severity from incident type. Manual override.
- 11:00–13:00 Submit with Ctrl+Enter. The transition to incident-detail.
- 13:00–14:00 Common keyboard pitfalls. (Tabbing past a collapsed section = tabbing TO it; the section opens.)
- 14:00–15:00 Practice exercise: create three incidents, one with each level of severity.

**Demo footage:**
- New-incident form, fully keyboard-driven
- Geocoder hitting a real address
- Protocol panel populated
- Map updating live as address types

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Creating an incident"

---

## Module 4 — Dispatching units, assigning, status changes

**Audience:** active dispatchers.
**Prerequisites:** Module 3.
**Learning objectives:**
- Assign one or more responders to an incident.
- Change unit status (en route, on scene, transporting, available).
- Read the dashboard incidents widget while juggling multiple active calls.

**Outline:**
- 0:00–1:00  The unit-assignment dialog from incident-detail.
- 1:00–3:00  Filtering / searching for the right unit.
- 3:00–5:00  Bulk-assign vs single-assign.
- 5:00–7:00  Unit status changes. Expected sequence (dispatched → en route → on scene → clear). Skipping states.
- 7:00–9:00  The responders widget on the dashboard. Sorting by status, by location, by callsign.
- 9:00–11:00 Multi-incident view — the status board (situation.php).
- 11:00–13:00 What happens if a unit goes to multiple incidents (chained dispatch).
- 13:00–14:00 Tips for high-volume periods (large drills, multi-vehicle accidents).
- 14:00–15:00 Recap + practice prompt.

**Demo footage:**
- Incident detail with unit assignment
- Status-board live view
- Multi-incident dispatch sequence

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Working an incident"

---

## Module 5 — The map, road conditions, geofences, weather

**Audience:** dispatchers, ICS officers.
**Prerequisites:** Module 3.
**Learning objectives:**
- Read the live map.
- Add road conditions and map markups.
- Understand geofence alerts.
- Toggle weather overlays.

**Outline:**
- 0:00–1:00  The map widget on the dashboard, plus the full-screen map.
- 1:00–3:00  Toggling tile providers (OSM, satellite, terrain).
- 3:00–5:00  Drawing road conditions / markups. Polygon, line, point.
- 5:00–7:00  Geofences — what they are. Setting one up. Receiving an enter/exit alert.
- 7:00–9:00  Weather overlays: radar, temperature, precipitation. Tile cache and what it means for performance.
- 9:00–11:00 Following a unit's location in real time. Route playback for after-action review.
- 11:00–13:00 Privacy settings on map (who sees what).
- 13:00–14:00 Recap + practice.
- 14:00–15:00 Hand-off to the next module.

**Demo footage:**
- Full-screen map
- Live unit movement
- Road condition drawing
- Geofence editor + sample alert

**Companion reading:** `docs/LOCATION-PROVIDERS-GUIDE.md`

---

## Module 6 — Communications: chat, SMS, radio, broadcast

**Audience:** dispatchers, ops officers.
**Prerequisites:** Module 4.
**Learning objectives:**
- Send a chat message scoped to an incident.
- Send an SMS / email broadcast.
- Bridge between Meshtastic, Zello, DMR, local chat.
- Send a HAS broadcast alert.

**Outline:**
- 0:00–1:00  Comms widget on dashboard.
- 1:00–3:00  Chat: org-wide channel, incident-scoped channel, DM.
- 3:00–5:00  SMS: composing, recipient selection, broadcast vs targeted.
- 5:00–7:00  Email + the SMTP config link.
- 7:00–9:00  Radio integrations: Meshtastic mode, Zello channel.
- 9:00–11:00 Cross-protocol routing: a Meshtastic message bridged to chat. The Routing rule editor.
- 11:00–13:00 HAS broadcast — what it is, when to use, who receives.
- 13:00–14:00 Practice scenarios.
- 14:00–15:00 Recap.

**Demo footage:**
- Chat widget
- SMS compose modal
- Routing rule editor
- HAS broadcast send

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Communications"

---

## Module 7 — Working a major incident

**Audience:** dispatchers, IC, command-staff.
**Prerequisites:** Modules 3–6.
**Learning objectives:**
- Link multiple incidents into a major incident.
- Set up command structure.
- Drive ICS forms (213, 214, 202).
- Close the major.

**Outline:**
- 0:00–1:00  When to declare a major incident.
- 1:00–3:00  Linking incidents. The cascade-close behavior.
- 3:00–5:00  Major incident command structure. Roles.
- 5:00–7:00  ICS form 213 (general message). When and how to send.
- 7:00–9:00  ICS form 214 (unit log). Filling in periodically.
- 9:00–11:00 ICS form 202 (incident objectives). The big-picture form.
- 11:00–12:30 Winlink XML export — for offline / radio operators.
- 12:30–14:00 Closing the major + cascade close.
- 14:00–15:00 Recap.

**Demo footage:**
- Linking two incidents
- ICS-213 compose + send
- ICS-214 timeline
- Winlink export

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Major incidents and ICS forms"

---

## Module 8 — End of shift: handoff, dispatch log, after-action

**Audience:** dispatchers.
**Prerequisites:** Modules 3–7.
**Learning objectives:**
- Hand off to the next dispatcher cleanly.
- Run a dispatch log report for the shift.
- Generate an after-action document.

**Outline:**
- 0:00–1:00  Handoff philosophy — "the next person walks in cold."
- 1:00–3:00  The dispatch log report. Filtering by shift period.
- 3:00–5:00  After-action report generation. What it includes.
- 5:00–7:00  ICS-214 print/export.
- 7:00–9:00  Lessons-learned notes back to the incident.
- 9:00–11:00 Logging out, locking the workstation.
- 11:00–12:30 Common mistakes at handoff.
- 12:30–14:00 The status board you leave behind.
- 14:00–15:00 Recap + transition to admin track.

**Demo footage:**
- Dispatch log filtered by date
- After-action report PDF
- ICS-214 print

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "End of shift"

---

# Track 2 — Administration (modules 9–16)

## Module 9 — Installing TicketsCAD NewUI v4

**Audience:** sysadmins, IT volunteers.
**Prerequisites:** none — admin entry point.
**Learning objectives:**
- Install LAMP/WAMP/XAMPP.
- Get NewUI on disk and configured.
- First-login as the super admin.

**Outline:**
- 0:00–1:30  System requirements. Linux + Apache + MariaDB; Windows + XAMPP also supported.
- 1:30–4:00  Walk-through of `tools/install_fresh.php` — what it does, when to run.
- 4:00–7:00  Configuring `config.php` — DB credentials, paths, encryption keys.
- 7:00–9:00  First login. Forcing the super-admin password change.
- 9:00–11:00 Verifying with the postcheck tool.
- 11:00–13:00 Common installation gotchas (file permissions on uploads/, MySQL strict mode, missing PHP extensions).
- 13:00–14:30 Troubleshooting checklist.
- 14:30–15:00 Recap + transition to module 10.

**Demo footage:**
- Fresh XAMPP install on Windows
- Run `php tools/install_fresh.php` end-to-end
- First login + password change

**Companion reading:** `docs/INSTALL-ADMIN-GUIDE.md` § "Installation"

---

## Module 10 — Upgrading from legacy v3.44

**Audience:** sysadmins of existing v3.44 installs.
**Prerequisites:** Module 9 (or skip if you know LAMP).
**Learning objectives:**
- Run the upgrade preflight.
- Take a backup.
- Run the upgrade orchestrator.
- Verify post-upgrade.
- Roll back if needed.

**Outline:**
- 0:00–2:00  Why upgrade? What's different in v4.
- 2:00–4:00  Pre-upgrade checklist: backup, downtime window, communicate to users.
- 4:00–6:00  `php tools/upgrade/preflight.php` — reading the report.
- 6:00–8:00  Taking a baseline snapshot (`postcheck.php --snapshot pre.json`).
- 8:00–10:00 Running `php tools/upgrade/run.php` — what to expect.
- 10:00–12:00 Postcheck verification (`--compare pre.json`).
- 12:00–13:30 Cut-over: side-by-side vs full replacement.
- 13:30–14:30 Rollback walkthrough.
- 14:30–15:00 Recap.

**Demo footage:**
- A real v3.44 → v4 upgrade in a VM
- Preflight output
- Run output
- Postcheck delta report

**Companion reading:** `docs/UPGRADING-FROM-V3.md`

---

## Module 11 — User management: accounts, lockout, password reset

**Audience:** admins.
**Prerequisites:** Module 9.
**Learning objectives:**
- Create / edit / disable user accounts.
- Reset a user's password.
- Unlock a locked account.
- Force a re-login org-wide.

**Outline:**
- 0:00–1:30  The User Accounts panel.
- 1:30–4:00  Creating a user. Username, password, level, member-link.
- 4:00–6:00  Editing existing users. The "can_login" flag.
- 6:00–8:00  Password reset — admin-initiated.
- 8:00–10:00 Unlocking a locked account (lockout settings).
- 10:00–12:00 Forcing re-login (session invalidation).
- 12:00–13:30 Disabling vs deleting (don't delete unless you're sure).
- 13:30–15:00 Recap.

**Demo footage:**
- User Accounts panel
- New user wizard
- Lockout audit log

**Companion reading:** `docs/INSTALL-ADMIN-GUIDE.md` § "Users"

---

## Module 12 — RBAC overview: roles, permissions, scopes

**Audience:** admins.
**Prerequisites:** Module 11.
**Learning objectives:**
- Understand the role / permission / grant model.
- Pick the right default role for a new user.
- Explain scope (global / org / team / self / delegate).

**Outline:**
- 0:00–2:00  Why RBAC instead of a numeric level. The mental model in 60 seconds.
- 2:00–4:00  Tour of the 6 default roles.
- 4:00–6:00  Permissions catalog — a quick scroll-through.
- 6:00–8:00  Scopes: global / org / team / self / delegate. Examples for each.
- 8:00–10:00 Time-bound grants: on-call coverage, training periods.
- 10:00–12:00 The privilege-escalation guard — why you can't grant a role you don't have.
- 12:00–13:30 The audit trail.
- 13:30–15:00 Recap. Pointer to module 13 for the per-role detailed walk-through.

**Demo footage:**
- Roles tab on roles.php
- Permission matrix per role
- Scope explainer animation

**Companion reading:** `docs/RBAC-GUIDE.md`

---

## Module 13 — Building custom roles

**Audience:** admins.
**Prerequisites:** Module 12.
**Learning objectives:**
- Create a new role.
- Assign permissions via the matrix.
- Avoid common pitfalls (privilege escalation, accidentally super, missing prerequisites).

**Outline:**
- 0:00–2:00  When to create a custom role vs use a default.
- 2:00–5:00  Creating a "Driver" role (example): can change_unit_status, view incidents, no admin.
- 5:00–8:00  Creating a "Limited Dispatcher" role (example): can dispatch but not delete incidents or manage users.
- 8:00–10:00 Granting the new role to users.
- 10:00–12:00 Mistakes to avoid: granting `action.manage_roles` accidentally, forgetting `screen.X` permissions for screens the role uses.
- 12:00–14:00 Testing your role: log in as a test user with the role.
- 14:00–15:00 Recap.

**Demo footage:**
- Role editor
- Permission matrix toggling
- Test login as the new role

**Companion reading:** `docs/RBAC-GUIDE.md` § "Default roles" + `docs/RBAC-INTEGRATOR-GUIDE.md` (skip developer parts).

---

## Module 14 — Time-bound grants, delegation, audit

**Audience:** admins.
**Prerequisites:** Module 12.
**Learning objectives:**
- Grant a role with an expiry.
- Use delegation for vacation coverage.
- Toggle "require separate approver" for stricter ops.
- Read the audit log.

**Outline:**
- 0:00–2:00  When to use expiry: training, on-call, fill-in.
- 2:00–4:00  Granting "Sam covers June 1-8."
- 4:00–6:00  Delegation depth setting (0/1/2/3) and why depth=1 is usually right.
- 6:00–8:00  Self-approval: rbac.require_separate_approver — volunteer vs paid-staff defaults.
- 8:00–10:00 Auto-approve mode for time entries.
- 10:00–12:00 The audit trail tab. Filtering by user, activity, date.
- 12:00–13:30 CSV export for compliance.
- 13:30–15:00 Recap.

**Demo footage:**
- Grant Role modal with expiry
- Delegation example
- Audit-trail CSV download

**Companion reading:** `docs/RBAC-GUIDE.md` § "Time-bound grants" + § "Self-approval"

---

## Module 15 — Integrations: SMTP, SMS, Meshtastic, Zello, DMR

**Audience:** admins.
**Prerequisites:** Module 9.
**Learning objectives:**
- Configure SMTP and send a test email.
- Configure SMS provider (Twilio / BulkVS / Pushbullet).
- Configure Meshtastic bridge (MQTT / serial / TCP).
- Configure Zello channel.
- Configure DMR (BrandMeister or DVSwitch).

**Outline:**
- 0:00–1:30  Why integrate.
- 1:30–4:00  SMTP: settings panel, test send, troubleshooting.
- 4:00–6:30  SMS: provider choice, credentials, test send.
- 6:30–9:00  Meshtastic: hardware overview, bridge service, MQTT vs serial vs TCP.
- 9:00–11:00 Zello: account, channel, test broadcast.
- 11:00–13:00 DMR: BrandMeister API key vs DVSwitch.
- 13:00–14:00 Troubleshooting common issues.
- 14:00–15:00 Recap.

**Demo footage:**
- SMTP test
- SMS test
- Meshtastic hardware (B&W or render)
- Zello channel config
- DMR settings

**Companion reading:** `docs/INSTALL-ADMIN-GUIDE.md` § "Integrations"

---

## Module 16 — Backups, restores, security recovery

**Audience:** admins.
**Prerequisites:** Module 9.
**Learning objectives:**
- Take a manual backup.
- Schedule automated backups.
- Restore from a backup.
- Rotate the encryption key.
- Recover from a security incident.

**Outline:**
- 0:00–1:30  Why backups matter.
- 1:30–4:00  Manual backup via the admin UI (browser download).
- 4:00–6:30  Filesystem-saved backups (server-side).
- 6:30–9:00  Scheduling: cron / Task Scheduler.
- 9:00–11:00 Restoring: full DB + uploads.
- 11:00–13:00 Encryption key rotation: when, why, how.
- 13:00–14:00 Security incident response checklist.
- 14:00–15:00 Recap.

**Demo footage:**
- Backup admin panel
- Cron job creation
- Restore walkthrough
- Key rotation tool

**Companion reading:** `docs/SECURITY-RECOVERY-GUIDE.md` + `docs/ENCRYPTION-KEY-LIFECYCLE.md`

---

# Track 3 — Tools (modules 17–24)

## Module 17 — The membership roster

**Audience:** all users.
**Prerequisites:** Module 1.
**Learning objectives:**
- Find a member by name, callsign, phone, team.
- View member detail.
- Edit a member (if permitted).
- Use FCC lookup for amateur radio callsigns.

**Outline:**
- 0:00–1:30  The roster page tour.
- 1:30–4:00  Search and filter.
- 4:00–6:30  Member detail panel: contact info, certifications, callsigns, equipment, notes.
- 6:30–9:00  Editing a member. Permissions matter — what you can change depends on your role.
- 9:00–11:00 FCC lookup: amateur callsign → license details auto-fill.
- 11:00–13:00 Org membership: members in multiple orgs.
- 13:00–14:00 Soft delete (wastebasket).
- 14:00–15:00 Recap.

**Demo footage:**
- Roster page
- FCC lookup live
- Member edit form
- Wastebasket

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Roster"

---

## Module 18 — Time keeping

**Audience:** all members + admins.
**Prerequisites:** Module 17.
**Learning objectives:**
- Log a time entry for yourself.
- Approve or reject a member's time entry (admin).
- Use the auto-approve setting.
- Run a time-summary report.

**Outline:**
- 0:00–1:30  Why time tracking matters (compliance, drill credit, grant funding).
- 1:30–4:00  The Time Log card on member detail. Logging an entry.
- 4:00–6:30  Activity types: Net, Drill, Public Service Event, Training, etc.
- 6:30–9:00  As an admin: the Pending Time Approvals page. Approve / reject.
- 9:00–11:00 Auto-approve modes: off / on / by_activity_type.
- 11:00–13:00 Reports: license expirations, time summary, inactive members.
- 13:00–14:00 The 30-day cap on back-dating.
- 14:00–15:00 Recap.

**Demo footage:**
- Log Time modal
- Pending Time Approvals page
- Auto-approve setting toggle
- Time-summary report

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Time tracking" + `docs/RBAC-GUIDE.md` § "Self-approval"

---

## Module 19 — Scheduling: shifts, events, self-signup

**Audience:** all members.
**Prerequisites:** Module 17.
**Learning objectives:**
- View the schedule.
- Sign up for an open shift.
- Create a shift template (admin).
- Manage event assignments.

**Outline:**
- 0:00–1:30  The scheduling page tour.
- 1:30–3:30  Calendar views: day, week, month.
- 3:30–6:00  Self-signup for open slots.
- 6:00–8:30  Admin: creating shift templates with role requirements.
- 8:30–11:00 Events: planned activities (drills, public service).
- 11:00–13:00 Permission profiles: who can manage what.
- 13:00–14:00 Reminder: this is where the "shift-aware permissions" feature (future phase 7c) plugs in.
- 14:00–15:00 Recap.

**Demo footage:**
- Calendar view
- Self-signup flow
- Shift template editor

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Scheduling"

---

## Module 20 — Teams, ICS positions, certifications

**Audience:** admins, ICS officers.
**Prerequisites:** Module 17.
**Learning objectives:**
- Create teams and add members.
- Set NIMS resource typing.
- Track certifications and expirations.
- Assign ICS positions.

**Outline:**
- 0:00–1:30  Why teams matter in dispatch.
- 1:30–4:00  Teams page tour.
- 4:00–6:30  Many-to-many membership (a person can be on multiple teams).
- 6:30–9:00  NIMS typing.
- 9:00–11:00 Certifications: tracking, expiration alerts.
- 11:00–13:00 ICS positions: roster assignments per incident.
- 13:00–14:00 Reports: license-expiration, team-staffing.
- 14:00–15:00 Recap.

**Demo footage:**
- Teams page
- NIMS dropdown
- Certification expiry chart

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Teams"

---

## Module 21 — Equipment and vehicle tracking

**Audience:** equipment officers, admins.
**Prerequisites:** Module 17.
**Learning objectives:**
- Track inventory (radios, vehicles, personal gear).
- Assign equipment to members.
- Run a maintenance / inspection schedule.

**Outline:**
- 0:00–1:30  The equipment & vehicles pages.
- 1:30–4:00  Adding equipment. Categories: radio, vehicle, PPE, custom.
- 4:00–6:30  Assigning to members. Issue date, return date.
- 6:30–9:00  Maintenance scheduling: inspection intervals, alerts.
- 9:00–11:00 Vehicle-specific fields: VIN, plate, mileage log.
- 11:00–13:00 Inventory reports.
- 13:00–14:00 Bulk import / export.
- 14:00–15:00 Recap.

**Demo footage:**
- Equipment page
- Assignment workflow
- Vehicle mileage log

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Equipment & vehicles"

---

## Module 22 — Reports

**Audience:** admins, ICS, board members.
**Prerequisites:** various.
**Learning objectives:**
- Run any of the 12+ standard reports.
- Filter by period, responder, incident.
- Export to CSV.
- Print a hardcopy.

**Outline:**
- 0:00–1:30  The reports page tour.
- 1:30–3:30  Incident reports: full-detail, summary, dispatch log, unit log, facility log, after-action.
- 3:30–6:00  Personnel reports: roster snapshot, time summary, license expirations, dues due, inactive members, DMR ID inventory.
- 6:00–9:00  Period filters and custom date ranges.
- 9:00–11:00 CSV export workflow.
- 11:00–13:00 Print-friendly formatting.
- 13:00–14:00 What's NOT here (cross-org rollups, KPI dashboards) — in the backlog.
- 14:00–15:00 Recap.

**Demo footage:**
- Reports page with each button hit
- Custom date range
- CSV download
- Print preview

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Reports"

---

## Module 23 — Mobile interface and field use

**Audience:** field unit members.
**Prerequisites:** Module 1.
**Learning objectives:**
- Open the mobile interface.
- Update unit status from the field.
- Send a chat message.
- Upload a photo.
- Install as a PWA.

**Outline:**
- 0:00–1:30  When to use mobile vs. desktop.
- 1:30–3:30  PWA install (Add to Home Screen).
- 3:30–6:00  Status updates from the field.
- 6:00–8:30  Sending a chat message scoped to your incident.
- 8:30–11:00 Photo upload (incident attachments).
- 11:00–13:00 Offline mode: what works without connectivity.
- 13:00–14:00 Battery and data tips.
- 14:00–15:00 Recap.

**Demo footage:**
- Phone screen recording
- PWA install dialog
- Status update flow
- Photo upload

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Mobile"

---

## Module 24 — Putting it all together: a multi-org training drill

**Audience:** all.
**Prerequisites:** modules 1–23.
**Learning objectives:**
- Run a coordinated drill exercising every major feature.
- Identify gaps in the org's TicketsCAD setup.
- Hand off to the next person to keep training going.

**Outline:**
- 0:00–1:30  Why drill?
- 1:30–4:00  Pre-drill checklist: roster current, schedule loaded, integrations green.
- 4:00–7:00  Drill scenario walkthrough: multi-incident, multi-unit, ICS forms, comms across radio + chat.
- 7:00–10:00 Common gotchas seen in drills.
- 10:00–12:00 After-action: report run, lessons learned recorded.
- 12:00–13:30 What to do next: build your org's own training schedule.
- 13:30–14:30 Wrap-up: the curriculum, the docs, the support channels.
- 14:30–15:00 Sign-off.

**Demo footage:**
- Drill scenario from start to finish
- After-action report

**Companion reading:** `docs/NEWUI-USER-GUIDE.md` § "Drills" + the wiki

---

## Add-on Module 27 — Location tracking with Traccar + OwnTracks

**Status:** focused add-on, not part of the core 24-module sequence. Aimed at agencies adding GPS integration after initial deploy. Numbered m27 because m25 and m26 are existing add-ons.
**Audience:** admins / dispatchers integrating with OwnTracks-app smartphones, Traccar Server fleets, Traccar Client phones, or legacy OpenGTS GPS modems.
**Prerequisites for the viewer:** m02, m05, m11, m15, m17.
**Estimated runtime:** 20–25 minutes (≈1.5× the typical module — justified by four distinct setup paths and the per-device-token workflow).
**Brief / outline:** training-scripts/m27-location-tracking-traccar-owntracks.md — this is the input for the video-generating agent; the camera-ready script and the finished video are produced from it.
**Companion reading:** [docs/TRACCAR-SETUP.md](TRACCAR-SETUP.md).

Why an add-on rather than slotted in-sequence: most agencies decide whether they want hardware GPS integration months after their initial TicketsCAD deploy, often after first running a real exercise and realizing they want unit position on the map. Treating this as an add-on lets the core curriculum stay focused on what every deployment needs.

---

# Production notes for the AI agent

- **Voice:** professional but warm. First-person "we" / "you." Avoid jargon-without-definition — assume zero CAD background for the first 2–3 modules of each track, then build up.
- **Visuals:** prefer real screen recordings over slides. Slides only for orientation in the first 30 seconds and the recap at the end.
- **Theme:** record both day and night theme at least once each across the curriculum so viewers see both.
- **Captions:** burn in always. Subtitles for accessibility and noisy-environment viewing.
- **Length:** aim for 14:30–15:00. Going long is worse than going short. Cut to chapters in YouTube.
- **Test data:** use the demo install with sample seed data. Don't show real member info. Use generic names ("Jane Doe", "Sample Fire Co.").
- **Re-recordable bits:** if the UI changes, the modules most likely to need re-recording are 9 (install), 10 (upgrade), 12 (RBAC overview), 13 (custom roles). Plan for these to be refreshed every minor version.
- **Companion reading alignment:** every claim made on video should appear in the companion reading section, and vice versa. If the video says "click Settings → Roles & Permissions," the matching written doc should also say "Settings → Roles & Permissions" — same path, same wording.

The full set of modules totals ~6 hours of video. Watching all of them is not the goal — most users only need modules 1–3 + their role-specific ones. Producing the curriculum with consistent voice/style/structure means viewers can drop in and out without friction.
