# Legacy v3.44 → NewUI v4.0 — Term Map

**Audience:** anyone migrating from TicketsCAD v3.44 or earlier. Helps you find the new name for something you remember from the old system.

**Also see:** [GLOSSARY.md](GLOSSARY.md) for full definitions of every current term, and [UPGRADING-FROM-V3.md](UPGRADING-FROM-V3.md) for the actual migration procedure.

---

## TL;DR — what changed at a glance

NewUI is a major-version rewrite. The data model is preserved (your incident history, member roster, and config carry over), but the user-facing language was modernised:

- "Tickets" are called "**incidents**".
- "Actions" (the log entries on an incident) are called "**activities**".
- "Hints" (the canned response codes) are called "**signals**".
- The 1–4 numeric "**level**" on a user account was replaced with named **roles** (Super Admin, Org Admin, Dispatcher, Operator, Read-Only, Field Unit) plus 65 granular **permissions**.
- A **member** is the person; a **responder** is the dispatchable thing (vehicle or, via clock-in, the person as a "personal resource").
- The dispatcher view is now a single Bootstrap page with widgets — no more framesets, no more jQuery 1.4.

You're not losing features. Most things were renamed for clarity. Anywhere v3 was vague (was "level 2" a manager? a dispatcher?), v4 made the answer explicit.

---

## Renames (same concept, new name)

The first three columns of this table are the most important. The fourth tells you whether the underlying database table was also renamed.

| Old term (v3.44) | New term (v4.0) | Why the change | DB table |
|---|---|---|---|
| **Ticket** | **Incident** | Industry-standard CAD language; "ticket" implied IT helpdesk to many users | Table is still `ticket`; UI says "incident" |
| **Action** | **Activity** | Clearer noun for "thing that happened on this incident"; "action" was overloaded with "verb" | Table is still `action` |
| **Hint** | **Signal** | Matches what dispatchers actually call them on the radio ("Signal 7" not "Hint 7") | Table varies by install: `codes` (new) or `hints` (legacy); both work |
| **User level** (1–4 integer) | **Role** (Super Admin / Org Admin / Dispatcher / Operator / Read-Only / Field Unit) | A number forced everyone to remember "what does level 2 mean?" Roles spell it out and let you create custom ones | Table is still `user`; column `level` is kept for backwards compatibility but no longer drives runtime gating (Phase 12) |
| **Org** (`agencies` table in some installs) | **Organisation** | Spelled out for clarity; abbreviation "org" still used in UI | Table renamed to `organisations` if you ran the v4 migrations |
| **Map markup** | **Markup** or **Map overlay** depending on context | "Markup" alone in UI; the table kept its legacy name | Table is still `mmarkup` |
| **Group** (the `allocates` row scope) | **Group** (unchanged) but largely superseded by [Role + Permission](RBAC-GUIDE.md) | Groups still work for per-resource visibility; RBAC is now the primary gate | Table still `allocates` |
| **Dispatch** (verb) / **call** (noun) | **Dispatch** (verb) / **incident** (noun) | "Call" was ambiguous — phone call? Radio call? | n/a |
| **Member** | **Member** (unchanged) but distinct from **Responder** | The role of a "member" got narrowed: it's the person record; if you also want to dispatch the person directly, that's a "personal resource" responder | `member` and `responder` |
| **Unit** | **Unit** or **Responder** (interchangeable) | The thing the dispatcher assigns to an incident | `responder` |
| **Status** | **Status** (unchanged) | Stayed the same; configurable from Settings → Unit Statuses | `un_status` |

### Workflow-naming changes

| Old workflow name | New workflow name | Notes |
|---|---|---|
| New ticket form | **New incident form** | Keyboard-first, single screen, protocol panel above map |
| Login → frameset → app | **Login → dashboard** | Single-page Bootstrap; no framesets |
| Permissions tab | **Roles & Permissions** | Inside Settings → Identity & Security |
| Logs | **Activity** (per-incident) or **Audit log** (system-wide) | Two distinct logs in v4 |
| Page-print | **Print stylesheet** | Browser-native print; explicit print stylesheet ships |
| Mobile companion | **Mobile UI / PWA** | Same code base, mobile-first responsive views; PWA install supported |

---

## New concepts (v4-only, no v3 equivalent)

These features didn't exist in v3.44. Don't waste time looking for the old name — there isn't one.

| New term | What it is | Where to learn more |
|---|---|---|
| **RBAC** | Role-Based Access Control. 65 permissions, 6 default roles, custom roles, time-bound grants. | [RBAC-GUIDE.md](RBAC-GUIDE.md) |
| **TOTP / 2FA / TFA** | Time-based one-time passwords. RFC 6238, 30-second window. Mandatory if your role requires it. | Settings → Login Settings; Training m02 |
| **Backup codes** | Eight single-use codes issued at 2FA enrollment, for use if you lose your authenticator. | Training m02 |
| **Remember device** | Cookie-based 2FA bypass for a configurable window, bound to UA + Accept-Language + /24 IP prefix. | [SECURITY-POLICY.md](SECURITY-POLICY.md) |
| **Force password change** | Admin can flag a user as "must change on next login". Auto-set on new-user creation and admin reset. | [SECURITY-POLICY.md](SECURITY-POLICY.md) |
| **PAR (Personnel Accountability Report)** | Periodic check-in cycle for assigned units. Configurable cadence per incident type. | [PAR-CHECK-GUIDE.md](PAR-CHECK-GUIDE.md) |
| **Personal resource** | A responder auto-created for a member when they clock in. Lets the person be tracked + assigned even with no fleet vehicle. | Training m18 |
| **Clock-in / clock-out** | Members can toggle their on-duty status from the navbar or mobile UI. Drives personal-resource creation and OwnTracks-mode switching. | Training m18 |
| **Location provider** | A configurable source of position data (OwnTracks, APRS, Meshtastic, browser GPS, etc.). Bound to specific responders. | [TRACCAR-SETUP.md](TRACCAR-SETUP.md) |
| **Location inheritance** | A responder's position can come from the personnel currently assigned to it. | — |
| **OwnTracks integration** | Per-member token, HTTP-direct posting, queued config push for rotation. | [OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md) |
| **Meshtastic / MeshCore bridges** | LoRa mesh radio integration; bridge VMs proxy mesh ↔ TicketsCAD HTTPS. | [MESH-BRIDGE-GUIDE.md](MESH-BRIDGE-GUIDE.md) |
| **APRS-IS persistent listener** | Python service that maintains a TCP connection to APRS-IS instead of 5-minute HTTP polling. | [APRS-LISTENER-SETUP.md](APRS-LISTENER-SETUP.md) |
| **DVSwitch / DMR bridge** | Bridges digital-radio (DMR, P25, etc.) onto TicketsCAD with TTS for outbound + STT transcripts for inbound. | [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md) |
| **Geofence** | Polygon or circle alert zone. Notifies when a unit crosses the boundary. | Settings → Maps & Tracking → Alert Zones |
| **Broker / channels** | Unified message fan-out. One `broker_send()` call dispatches to chat, SMS, SMTP, Slack, Meshtastic, DMR, etc. | [GLOSSARY § Broker](GLOSSARY.md#broker) |
| **Routing engine** | Rules that forward messages between channels (e.g. "radio chatter on this talkgroup → post to chat"). | [ROUTING-ENGINE-REFERENCE.md](ROUTING-ENGINE-REFERENCE.md) |
| **SSE / EventBus** | Real-time dashboard updates. Replaces v3's setInterval polling. | [GLOSSARY § SSE](GLOSSARY.md#sse-server-sent-events) |
| **Notification tray** | Bell-icon panel in the navbar showing every event the user has rights to see. | Training m06 |
| **Audit log** (`audit_log` table) | OCSF-aligned, every state-changing action recorded. Distinct from the legacy per-incident `log` table. | [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md) |
| **Webhooks** | Outbound HTTP POSTs to user-configured URLs on subscribed events. HMAC-signed, retried with backoff. | [WEBHOOKS-INTEGRATOR-GUIDE.md](WEBHOOKS-INTEGRATOR-GUIDE.md) |
| **i18n / captions** | Server-side `t($key)` calls plus a database-overlayable translation registry. EN/DE/NL/FR/ES seeded; community-extensible. | [I18N-GUIDE.md](I18N-GUIDE.md) |
| **ICS forms** | Built-in 213, 214, 202, 205, 205a, 213rr, 206, 214a, 221 with Winlink XML export. | Training m07 |
| **Map overlay categories** | Group map markups so dispatchers can toggle them in bulk. | Settings → Maps & Places → Overlay Categories |
| **Major incidents** | Link multiple sub-incidents under a campaign with command structure. | Training m07 |
| **Quick-start wizard** | First-run setup wizard for new installations. | [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md) |
| **Command bar** (`/` prefix) | Type `/` anywhere to open a search-and-dispatch quick action menu. | [Help → Keyboard Shortcuts](../help.php) |

---

## Removed concepts (v3 things that are gone in v4)

| v3.44 concept | v4 status | What to do instead |
|---|---|---|
| Frameset / iframe layout | **Removed.** Single-page Bootstrap 5. | No action needed; the UI is the same root URL |
| jQuery 1.4.2 | **Removed.** NewUI uses vanilla ES5 (no `$`). | If you wrote custom JS that used jQuery, port to `document.querySelector` + `.addEventListener` |
| Older-browser compatibility shims | **Removed.** Current Edge / Chrome / Firefox / Safari only. | Upgrade workstation browsers |
| Legacy password hashes | **bcrypt and legacy MD5** are accepted by `verify_password()` in [`inc/functions.php`](../inc/functions.php); an MD5 hash is re-hashed to bcrypt on the next successful login. | None for bcrypt/MD5 accounts. Accounts still on older legacy formats (MySQL `PASSWORD()`, SHA1, plain) aren't recognized by NewUI and need a password reset. |
| `level=1` admin / `level=4` viewer model | **Replaced** by [RBAC](GLOSSARY.md#rbac). Migration runs `tools/migrate_rbac.php`. | Run the migration; verify role mapping in Settings → User Accounts |
| `log` table as the single source of system events | **Complemented** by `audit_log` (OCSF-aligned). Legacy `log` still written for per-incident activity. | Use `audit_log` for system audit queries; `log` for per-incident activity feed |

---

## Database schema renames

If you query the database directly, here's what to look for. Always confirm with `SHOW TABLES` on your specific install — some legacy tables were never renamed.

| v3.44 table | v4.0 table | Status |
|---|---|---|
| `ticket` | `ticket` | **Unchanged** (we kept the table name to avoid breaking migrations; UI says "incident") |
| `action` | `action` | **Unchanged** (UI says "activity") |
| `hints` (some installs) | `codes` (new installs) | **Either works.** Query falls back |
| `member` | `member` | **Unchanged** |
| `responder` | `responder` | **Unchanged** (new column: `personal_for_member_id`) |
| `allocates` | `allocates` | **Unchanged** (still keyed by `(resource_id, type, group)`) |
| `user` | `user` | **Unchanged** (`level` retained for read; `passwd` rewritten to bcrypt) |
| `agencies` | `organisations` | **Renamed** in installs that ran the v4 org migration |
| `mmarkup` | `mmarkup` | **Unchanged** |
| `log` | `log` + new `audit_log` | **Split.** Per-incident events stay in `log`; system audit goes to `audit_log` |
| `assigns` | `assigns` | **Unchanged** |
| `un_status` | `un_status` | **Unchanged** |
| `in_types` | `in_types` | **Unchanged** (new columns: `par_cadence_seconds`, `par_default_mode`) |
| (no equivalent) | `roles`, `permissions`, `role_permissions`, `user_roles` | **New** — see [RBAC-GUIDE.md](RBAC-GUIDE.md) |
| (no equivalent) | `audit_log` | **New** — see [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md) |
| (no equivalent) | `location_providers`, `location_reports`, `unit_location_bindings` | **New** — see [TRACCAR-SETUP.md](TRACCAR-SETUP.md) |
| (no equivalent) | `geofences`, `dmr_channels`, `dmr_messages`, `mesh_nodes`, `mesh_packet_log` | **New** per feature |
| (no equivalent) | `ics_forms`, `internal_messages`, `message_recipients` | **New** |
| (no equivalent) | `webhooks`, `webhook_deliveries` | **New** |
| (no equivalent) | `active_sessions`, `user_tfa`, `user_password_history` | **New** for CJIS hardening |
| (no equivalent) | `captions`, `languages` | **New** for i18n |
| (no equivalent) | `routes`, `routing_log` | **New** for routing engine |

---

## Permission renames

The legacy v3 `level` integer mapped roughly to the new RBAC roles like this. **Run the level→role migration once** (it's idempotent) when upgrading.

| Legacy `level` | Legacy meaning (informal) | Default v4 role | Notes |
|---|---|---|---|
| **1** | Super admin / sysadmin | **Super Admin** | Bypasses every permission check |
| **2** | Manager / org admin | **Org Admin** | Almost everything except `action.manage_config` |
| **3** | Dispatcher / supervisor | **Dispatcher** | Screens + widgets + operational actions |
| **4** | Viewer / read-only | **Read-Only** | Screens + widgets, no write actions |
| (none) | n/a | **Operator** | Field-operator persona — mobile UI + own status |
| (none) | n/a | **Field Unit** | Like Operator but mobile-only |

You can edit these defaults from Settings → Roles & Permissions, and create custom roles (e.g. "Training Coordinator" with only the scheduling perms).

---

## Common "where did it go?" questions

| "I used to do X via Y. Where is it now?" | Answer |
|---|---|
| Set the dispatcher's level | Settings → User Accounts → Edit → **Role and permission group** |
| Edit signal codes | Settings → Operations → **Signals / Codes** |
| Configure 2FA | Settings → Identity & Security → **Two-Factor Authentication** |
| Add an incident type | Settings → Operations → **Incident Types** |
| Configure a tile provider | Settings → Maps & Places → **Map Providers** |
| Bulk-add map markups | Settings → Maps & Places → **Overlays** → Import (GeoJSON/KML/GPX) |
| See the system event log | Settings → Audit & Compliance → **Audit Log** (separate from the per-incident activity feed) |
| Reset a user's password | Settings → User Accounts → row → **Reset Password** (requires reason for the audit log) |
| Force a user to change password | Same row → toggle **Must Change Password** |
| See who's currently logged in | Settings → Identity & Security → **Active Sessions** |
| Force-logout a user | Same row → **Destroy session** |
| Add a webhook | Settings → Integrations → **Webhooks** |
| See what migrations have run | Settings → System → **Migrations** (read-only) |
| Trigger a manual backup | Settings → System → **Backup** → **Backup now** |

---

## Migration cheat sheet

```bash
# After running the standard upgrade procedure (see UPGRADING-FROM-V3.md):

cd /var/www/newui

# 1. Bring the legacy schema up to the modern column set (idempotent).
php tools/install_fresh.php

# 2. Migrate legacy user.level integers to RBAC role grants (idempotent).
php tools/migrate_rbac.php

# 3. Verify role mapping in the admin UI:
#    Settings → Identity & Security → User Accounts
```

If a column on your install is named differently from what NewUI expects, the [`safe_*` wrappers](GLOSSARY.md#safe_-wrappers) and self-healing INSERT patterns will degrade gracefully — the affected feature will show empty or fail-soft, and the Apache error log will record the schema-drift hit so you can address it.

---

## Where to go next

- **Upgrading?** Read [UPGRADING-FROM-V3.md](UPGRADING-FROM-V3.md) before touching production.
- **Confused by a term you saw in the UI?** Look it up in [GLOSSARY.md](GLOSSARY.md).
- **Stuck on something specific?** Try [TROUBLESHOOTING.md](TROUBLESHOOTING.md) or [FAQ.md](FAQ.md).

If you encounter a v3 term that isn't on this page and you can't find a v4 equivalent, that's a documentation bug — file an issue or patch this file.
