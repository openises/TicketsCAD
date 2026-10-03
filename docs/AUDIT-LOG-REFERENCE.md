# Audit Log — Reference

**Audience:** compliance officer, security analyst, developer querying the audit trail.
**Schema:** a custom InnoDB table, loosely inspired by OCSF's category/activity/severity
vocabulary — see [OCSF alignment notes](#ocsf-alignment-notes) for exactly how far that
goes and where it stops.
**Implementation:** [`inc/audit.php`](../inc/audit.php), table `newui_audit_log`.
**Admin UI:** Settings → Audit Log.

Every fact in this document was checked against the running code on 2026-08-03: the
`CREATE TABLE` in `audit_ensure_table()`, the `audit_log()` writer, the `api/audit-log.php`
viewer endpoint, and a live install's actual data (`newui_audit_log` had 17,868 rows at the
time of writing). Where an example query appears below, it was run against that install
through the PHP CLI, not typed from memory.

**A note on why this rewrite exists:** an earlier version of this document described a
table called `audit_log` with columns `action`, `entity_type`, `entity_id`, `status`
(an ENUM), and `request_id` — none of which exist. That schema was apparently designed
at some point but the table that actually got built (`newui_audit_log`, in
`inc/audit.php`) took a different shape, and this document was never updated to match.
This is the project's own documented "schema-mismatch" disease, applied to its own
documentation. Every section below has been re-derived from the real table.

---

## Table schema

This is the literal `CREATE TABLE` from `audit_ensure_table()` (`inc/audit.php`), which
runs on every request to `api/audit-log.php` and `api/dashboard-audit.php` — the table is
created lazily on first use, not from `sql/base_schema.sql`. (`php sql/setup_audit_log.php`
also creates it, and writes/reads back one test row, useful for verifying a fresh install
by hand.)

```sql
CREATE TABLE IF NOT EXISTS newui_audit_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    event_time    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id       INT          DEFAULT NULL,
    user_name     VARCHAR(64)  DEFAULT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    category      VARCHAR(32)  NOT NULL,
    activity      VARCHAR(32)  NOT NULL,
    severity      TINYINT      NOT NULL DEFAULT 1,
    target_type   VARCHAR(48)  DEFAULT NULL,
    target_id     VARCHAR(64)  DEFAULT NULL,
    summary       VARCHAR(512) NOT NULL,
    details       JSON         DEFAULT NULL,
    KEY idx_event_time (event_time),
    KEY idx_category   (category),
    KEY idx_user_id    (user_id),
    KEY idx_target     (target_type, target_id),
    KEY idx_severity   (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Notes on things that surprise people coming from the old doc:

- `target_id` is `VARCHAR(64)`, not an integer — `audit_log()` casts whatever you pass to
  a string before writing. Most target ids are numeric-looking strings, but not all (a
  setting name like `audit_log_retention_days` is a valid `target_id`).
- `details` is a native `JSON` column named `details`, not `details_json`.
- `event_time` has second precision (`DATETIME`), not millisecond precision.
- There is no `status` column at all — success/failure isn't tracked as a separate field.
  A failed login is its own `activity` value (`login_failed`), not a `login` row with
  `status='failure'`.
- `severity` is a plain `TINYINT` (0–5), not an `ENUM` of word labels. Word labels exist
  only as a display layer — see [Severity](#severity) below.

---

## How rows get written

```php
function audit_log(
    string  $category,
    string  $activity,
    ?string $targetType = null,
    $targetId = null,
    string  $summary = '',
    ?array  $details = null,
    int     $severity = AUDIT_INFO
): bool
```

Call it after the action succeeds:

```php
require_once __DIR__ . '/../inc/audit.php';

audit_log('personnel', 'create', 'member', $id, "Created member '{$name}'", [
    'callsign' => 'KC9ABC',
    'team'     => 'HF Radio Team',
]);
```

The function fills in the rest from session and request context — you never pass these:

- `user_id` / `user_name` from `$_SESSION['user_id']` / `$_SESSION['user']` (both `NULL`
  for unauthenticated or system-originated events)
- `ip_address` via `client_ip()` (`inc/client-ip.php`) — trusted-proxy-aware, so it reads
  `X-Forwarded-For` only from an address you've configured as a trusted proxy, not from
  whatever the request claims
- `event_time` from the database's `NOW()`
- `severity`, if you don't pass one — see [Severity](#severity) below for the default map

**`audit_log()` never throws and never blocks the caller.** A write failure (missing
table, bad connection) is caught, sent to `error_log()`, and the function returns `false`
— the action that triggered the audit call still succeeds. The same is true of the
notification fan-out described next: it is wrapped in its own `try`/`catch` so a webhook
or push failure can never take down the audit row it's attached to.

**Side effect you should know about:** after a successful insert, `audit_log()` checks an
explicit allowlist (`_audit_to_webhook_event()` in [`inc/webhooks.php`](../inc/webhooks.php))
mapping specific `(category, activity, target_type)` triples to business-event names like
`incident.created` or `member.updated`. If your call matches an entry, the event is hand
ed to `notify_fanout_dispatch()` (`inc/notify-fanout.php`), which queues it for delivery
to webhooks, Web Push, and other subscribers. `admin`, `config`, and `security` category
rows are **deliberately absent from that allowlist** — see
[SIEM integration](#siem-integration) below for why this is not the same thing as "the
audit log gets exported."

There are three thin wrappers around `audit_log()` you'll see in the codebase:
`audit_data_access()` (category `data`, activity `view`, for logging access to sensitive
fields), `audit_login()` (category `auth`, with its own action→severity map for
`login`/`logout`/`lockout`/`password_change`/etc.), and `audit_admin()` (category
`config`, for admin CRUD). None of these are required — calling `audit_log()` directly is
equally correct.

### Querying from PHP instead of the admin UI

`audit_get_log(array $filters, int $limit = 100, int $offset = 0)` (also in
`inc/audit.php`) is a library function for filtered, paginated reads — used today by
`tools/test_login_security.php`. It's the function to reach for from a script or tool.
The admin viewer endpoint, `api/audit-log.php`, does **not** call it — it implements its
own equivalent (and slightly more capable — it adds sort-column selection and enriches
`ticket`/`incident` targets with the human-readable incident number) query directly.
Functionally they agree on every column name; if you're writing new tooling, either is a
correct reference for what's queryable.

---

## Categories

The doc comment at the top of `inc/audit.php` defines the intended vocabulary:

| Category | When |
|---|---|
| `auth` | login, logout, session, password changes |
| `config` | system settings, display, map, API keys |
| `personnel` | members, teams, certifications, training, ICS positions |
| `incident` | tickets, assignments, status changes |
| `asset` | vehicles, equipment, checkout/checkin |
| `data` | import, export, bulk operations, sensitive-field access |
| `system` | service events, errors, maintenance |
| `comms` | Zello, messaging, alerts |

`category` is a free-text `VARCHAR(32)` — nothing enforces this list at the database
level, and the codebase has grown past it. A distinct-value count against the live
install (17,868 rows, 2026-08-03) also showed `par`, `rbac`, `security`,
`personnel_unit`, `weather`, `routing`, `external_api`, `map`, `admin`, and `equipment`
in real use, ordered roughly by volume: `par` (roll-call/PAR events) and `rbac` (role
grants/revokes) are the two heaviest categories on this particular install, ahead of
`auth`. If you're writing a query that filters on `category`, check
`SELECT DISTINCT category FROM newui_audit_log` on your own install rather than assuming
only the eight above exist.

**`vendor`** (towing / roadside dispatch, GH#148): `provider.create|update|suspend|unsuspend|retire|delete`,
`list.create|update|retire|delete|set_default`, `member.add|remove|move|move_to_end`,
`service_type.save`, `settings.update` (before and after of every key that changed, severity Medium),
`dispatch.create|update|offer|outcome|status|note|void` (void is Medium) and `history.export` (Low). The durable
record of who was offered a job is the append-only `vendor_dispatch_ledger` table, **not** this log, because audit
retention may purge these rows. See `docs/VENDOR-DISPATCH-GUIDE.md`.

---

## Activities

`activity` is likewise free text (`VARCHAR(32)`). `_audit_default_severity()` in
`inc/audit.php` recognizes this set for its default-severity lookup:

```
create, update, delete, login, logout, export, import,
assign, unassign, activate, deactivate, error, view
```

Anything not in that map defaults to `AUDIT_INFO` (1) unless the caller passes an
explicit severity. `audit_log()` also takes an optional trailing `$actor` argument
(`['id' => ..., 'name' => ...]`; the constant `AUDIT_ACTOR_SYSTEM` is the one background
work uses) which overrides the session lookup, so a row written by a timer or an
automatic sweep says **System** with a NULL `user_id` instead of naming whichever
dispatcher's request it happened to run inside (Phase 155). In practice the codebase uses many activity values beyond this list —
`status_change`, `config_change`, `grant`/`revoke`, `zone_update`, `rotate`,
`console.view_create`, `channels.sync`, `clock_in`/`clock_out`, `apply_override` — each
caller names the verb that fits the action; there's no fixed enum to conform to. When
adding a new audit call, prefer an existing verb from a similar call site (`grep -rn
"audit_log('yourcategory'" api/ inc/`) over inventing a new one, purely for query
consistency.

---

## Severity

`severity` is an integer 0–5, defined as constants in `inc/audit.php`:

| Value | Constant | Label (`audit_severity_label()`) | Bootstrap badge color (`audit_severity_color()`) |
|---|---|---|---|
| 0 | `AUDIT_UNKNOWN` | Unknown | secondary |
| 1 | `AUDIT_INFO` | Info | info |
| 2 | `AUDIT_LOW` | Low | success |
| 3 | `AUDIT_MEDIUM` | Medium | warning |
| 4 | `AUDIT_HIGH` | High | danger |
| 5 | `AUDIT_CRITICAL` | Critical | danger |

If a caller doesn't pass `$severity` (or passes `AUDIT_INFO` explicitly), `audit_log()`
looks it up from `_audit_default_severity($activity)` — `delete` and `error` default to
`AUDIT_HIGH`; `export` to `AUDIT_LOW`; `import`/`deactivate` to `AUDIT_MEDIUM`; everything
else in the recognized-activity list defaults to `AUDIT_INFO`. Callers frequently override
this — `audit_login()` maps `lockout` to `AUDIT_CRITICAL` and `password_change` /
`login_blocked` to `AUDIT_HIGH` regardless of the generic default, and the retention purge
(below) always logs its own purge event at `AUDIT_HIGH`.

---

## `details` — real examples, not invented ones

`details` is a nullable `JSON` column. There's no fixed shape — every caller passes
whatever array is useful for that specific event, and `audit_log()` JSON-encodes it with
`JSON_UNESCAPED_UNICODE`. The rows below are **real rows pulled from a live install**
(`SELECT ... FROM newui_audit_log WHERE category = ? ORDER BY id DESC LIMIT 1`), not
hypothetical shapes:

```
category=auth    activity=login          target=user#1
  {"username":"testadmin","ip":"127.0.0.1","user_agent":"TestRunner/1.0"}

category=data    activity=view           target=ticket#123
  {"fields":["patient_name","medical_notes"]}

category=asset   activity=status_change  target=responder#1035
  {"old_status_id":1,"new_status_id":2,"incidents_logged":1,"timestamps_set":1}

category=config  activity=config_change  target=settings
  {"key":"lockout_max_attempts"}

category=incident activity=create        target=ticket#10762
  {"in_types_id":1,"severity":0,"status":2,"signal":null,
   "patient_count":0,"assigned_count":0,"incident_number":"26-0575"}

category=rbac    activity=revoke         target=user_role#9257
  {"user_id":2,"role_id":5,"scope_kind":"global","scope_id":null,
   "reason":"F7 audit cleanup","revoked_by":null}
```

Phase 155 added these incident rows (also real shapes):

```
category=incident activity=status_change  target=ticket#412     (webhook: incident.status_changed)
  {"ticket_id":412,"incident_number":"26-0071","old_status":3,"new_status":2,
   "old_status_label":"Scheduled","new_status_label":"Open","transition":"activated",
   "source":"scheduled_activation","actor_type":"system","actor_id":null,
   "actor_name":"System","booked_date":"2026-10-04 18:00:00","via_external_api":false}
   -- written by the status writer once per REAL change (user_name is "System" for
      the scheduled-activation and auto-close routes, never a dispatcher whose
      browser happened to run the sweep); no incident text in it by design

category=incident activity=reserve         target=assigns#9      (reservation id; NOT a webhook)
  {"ticket_id":412,"responder_id":77,"reservation_id":9,"role":"Medic",
   "booked_date":"2026-10-04 18:00:00","promotes_at":"2026-10-04 17:30:00"}
category=incident activity=reserve_release target=assigns#9      (released, or the incident closed)
category=incident activity=reserve_promote target=assigns#9      (the booked time arrived; the dispatch itself is the
                                                                   ordinary `assign` row -> assign.created)
category=incident activity=reserve_blocked target=assigns#9      (held for a dispatcher: the unit was busy)
```

Treat these as illustrations of the pattern ("small, flat-ish, includes enough to
reconstruct the change"), not a contract — a `data.view` row on your install may carry a
different field list than the one above, because it's whatever the calling code passed.
If you need to know the exact shape a specific feature writes, `grep` that feature's
`audit_log(` call sites rather than trusting this table.

---

## Example queries

Every query below was run against a live install through
`db_fetch_all()` (same connection path the application itself uses) and returned real
rows — not hand-verified for syntax only.

### Last 24 h of high-severity events

```sql
SELECT event_time, user_name, category, activity, summary, ip_address
  FROM newui_audit_log
 WHERE severity >= 4
   AND event_time > NOW() - INTERVAL 1 DAY
 ORDER BY event_time DESC;
```

### Failed login storm

```sql
SELECT ip_address, COUNT(*) AS hits
  FROM newui_audit_log
 WHERE category = 'auth'
   AND activity = 'login_failed'
   AND event_time > NOW() - INTERVAL 1 HOUR
 GROUP BY ip_address
HAVING hits > 10
 ORDER BY hits DESC;
```

### Who touched a specific incident

```sql
SELECT event_time, user_name, activity, summary
  FROM newui_audit_log
 WHERE target_type = 'ticket'
   AND target_id   = '12345'
 ORDER BY event_time;
```

`target_id` is a string column — quote the value even though it looks numeric.

### Role grants and revokes this quarter

```sql
SELECT event_time, user_name, activity,
       JSON_EXTRACT(details, '$.role_id') AS role_id,
       JSON_EXTRACT(details, '$.user_id') AS target_user_id
  FROM newui_audit_log
 WHERE category = 'rbac'
   AND event_time > NOW() - INTERVAL 90 DAY
 ORDER BY event_time;
```

### Busiest category/activity pairs (which events write the most rows?)

```sql
SELECT category, activity, COUNT(*) AS n
  FROM newui_audit_log
 GROUP BY category, activity
 ORDER BY n DESC LIMIT 20;
```

---

## Retention

**Note on table/column names in this section:** the actual audit table is
`newui_audit_log` (see [`inc/audit.php`](../inc/audit.php)), with columns
`event_time`, `category`, `activity`, `severity` (integer 0-5), `target_type`,
`target_id`, `summary`, `details`. The rest of this document now uses those same
real names throughout — this note is a holdover from when this section was the
only part of the file that had been corrected; it can be removed the next time this
file is touched.

- **Default retention:** disabled (`audit_log_retention_days` = `0` — keep
  everything forever). Upgrading TicketsCAD never starts deleting your audit
  history on its own; an administrator turns this on deliberately.
- **CJIS minimum:** 365 days. TicketsCAD does **not** enforce this as a
  floor — it warns (in Settings and in the save response) when you configure
  a value below 365, but does not block it. Your agency's own retention
  obligations may differ from another agency's, and TicketsCAD has no way to
  know which apply to your install.
- **Config knob:** `settings.audit_log_retention_days` (the `settings` table,
  read via `get_variable()` — not the separate `config` table read by
  `get_setting()`; see CLAUDE.md's "TWO settings stores" entry if that
  distinction is new to you).
- **Archive before delete.** A purge never bare-deletes. Every run first
  writes a gzip-compressed NDJSON archive (one JSON object per removed row,
  one file per run) to a directory outside the web root
  (`AUDIT_ARCHIVE_DIR` — platform-aware, mirrors where database backups live;
  see [`inc/audit-retention.php`](../inc/audit-retention.php)), verifies it,
  and only THEN deletes the archived rows — by exact row id, never a
  re-evaluated time-range predicate, so a row a concurrent request happens to
  insert with an old-looking timestamp can never be deleted without first
  being archived.
- **Manifest table:** `audit_log_purges` records every run — `ran_at`,
  `cutoff_date`, `rows_purged`, `archive_filename`, `archive_sha256`
  (verify an archive against this later with `sha256sum`),
  `triggered_by` (`scheduled` | `manual`), `triggered_by_user_id`, `status`
  (`ok` | `failed`), `detail`. A failed attempt gets a row too, not just a
  log line — see [Tamper-resistance](#tamper-resistance) below for why a
  purge can fail.
- **The purge audits itself.** After a successful delete, the purge writes
  its own `newui_audit_log` row (category `admin`, activity
  `audit_log_purge`) naming the cutoff, the row count, and the archive — so
  the fact that a purge happened is itself part of the permanent record, and
  because the row is written strictly after the delete it commits, its own
  timestamp can never fall inside the range it just purged.
- **Enforcement:** a daily scheduled job (`tools/audit_log_purge_tick.php`,
  wired into Settings → System Health → Scheduled Jobs as job key
  `audit_log_purge`) plus an on-demand "Purge now" button in
  Settings → Audit Log → Retention & Purge. Gated by a dedicated RBAC
  permission, `action.manage_audit_retention` (granted only to role 1, Super
  Admin, in `sql/rbac.sql` / `sql/run_00_rbac.php`). Check current status:

```bash
mariadb newui -e "SELECT value FROM settings WHERE name='audit_log_retention_days';"
mariadb newui -e "SELECT ran_at, cutoff_date, rows_purged, status FROM audit_log_purges ORDER BY id DESC LIMIT 5;"
```

Override per-record retention is **not** supported by design — every event
has the same retention. If you need longer retention for specific event
types, get them into your own archive or a SIEM (see below) before your
configured cutoff.

---

## Export

There is **no built-in bulk export** of the general audit log today — this corrects the
previous version of this section, which described an `api/audit-log.php?action=export`
endpoint and an admin "Export" panel with JSONL/CSV format options. Neither exists;
`api/audit-log.php` accepts no `action` or `format` parameter and always returns a JSON
page of at most 200 rows (`limit`, capped at 200, with `offset` for pagination).

What actually exists:

- **The general Audit Log viewer** (Settings → Audit Log) is search/filter/sort/paginate
  only — there is no export button on that panel.
- **The RBAC-scoped "Audit Trail" tab** (Settings → Roles & Permissions → Audit Trail;
  `assets/js/roles-audit.js`) has an **"Export CSV" button**, but it only exports the
  rows currently loaded on screen (whatever the active filters returned, up to the 200-row
  page size fetched from `api/audit-log.php?category=rbac&...`) — built client-side in the
  browser with `Blob`/`URL.createObjectURL`, not a server-side streaming export. It's a
  convenience for "grab what I'm looking at," not a compliance export tool.
- **`api/audit-log.php` itself is pageable** — `limit` (max 200) and `offset` let a script
  walk the entire table in pages if you're authenticated as an admin (or hold
  `action.view_audit`). That's the closest thing to a programmatic export path today; it
  is not a purpose-built one.

If you need a genuine bulk export — for a CJIS evidence packet, or to seed a SIEM — the
practical options with the current schema are:

```bash
# Full table, as NDJSON, via mariadb-dump-adjacent select-into-outfile-style tooling
# is not wired up either; the simplest reliable path with what ships today is a
# straight mysqldump of the table, or a script that walks api/audit-log.php with
# limit/offset until `total` is exhausted. Neither is provided out of the box.
mariadb-dump newui newui_audit_log --where="event_time >= '2026-05-01'" > audit-may.sql
```

If you build a proper export tool for this, it belongs at a path like
`tools/audit-log-export.php` — no such file exists yet.

---

## SIEM integration

**There is no general-purpose "ship every audit row to a SIEM" mechanism.** What exists
instead is narrower and worth understanding precisely, because it's easy to
mis-read as the same thing:

`audit_log()` checks each row it writes against an explicit allowlist —
`_audit_to_webhook_event()` in [`inc/webhooks.php`](../inc/webhooks.php) — that maps a
small set of `(category, activity, target_type)` triples to named business events
(`incident.created`, `member.updated`, `assign.created`, and so on; see the function for
the full current list). A match gets queued for delivery to whatever webhook subscribers
you've registered, via the general [webhooks system](WEBHOOKS-INTEGRATOR-GUIDE.md).

Critically: **`admin`, `config`, and `security` category rows are deliberately absent
from that allowlist**, per the comment at the top of `_audit_to_webhook_event()` — "Anything
not in the map fires nothing — admin/config/security audit rows are deliberately absent
and CANNOT leak to external subscribers." That's most of what a compliance-minded reader
would actually want in a SIEM (privilege changes, config changes, security events). The
webhook system was built for operational integrations (notify an external system when an
incident is created), not as an audit-export channel, and it deliberately excludes the
audit-heavy categories to avoid becoming one by accident.

If you want to ship the actual audit log to an external SIEM, nothing built-in does it
today. Patterns that would work against the current schema, offered as suggestions —
not shipped capability:

**Nightly batch dump.** The most reliable low-effort option, since it needs nothing new:

```cron
30 1 * * * mariadb-dump newui newui_audit_log --where="event_time >= CURDATE() - INTERVAL 1 DAY" | gzip > /var/backups/tcad-audit-$(date +\%F).sql.gz
```

**SQL replica.** Standard MariaDB replication to a read-only, append-only replica gives a
SIEM-side copy without touching the application. This is the pattern the tamper-resistance
section below is written to be compatible with.

**A dedicated export endpoint.** If you need this often enough to justify building it,
the shape would be an admin-gated endpoint that pages through `newui_audit_log` (the same
query `api/audit-log.php` already runs, without the 200-row cap) and streams NDJSON. This
does not exist yet — file an issue or write it against `action.view_audit`.

---

## Tamper-resistance

The audit log is append-only by application code — no endpoint calls `UPDATE` or `DELETE`
against `newui_audit_log` directly; the only thing that ever deletes from it is the
retention purge described above, and only after archiving what it's about to remove. For
defence-in-depth, you can **revoke the DELETE/UPDATE grants from the application's DB
user**:

```sql
REVOKE DELETE, UPDATE ON newui.newui_audit_log FROM 'newui'@'localhost';
FLUSH PRIVILEGES;
```

With this in place, even a successful SQL-injection attack against the app could not
silently rewrite history.

**This has a real trade-off with the retention feature above, stated plainly rather than
hidden:** revoking DELETE also disables the automated purge — it cannot delete what it has
archived. TicketsCAD detects this BEFORE doing any work (`audit_retention_check_delete_capability()`
runs a zero-row `DELETE ... WHERE 1=0` privilege probe — safe, because it can never match
a real row, but MySQL/MariaDB evaluate the privilege to run the statement before evaluating
the `WHERE` clause) and fails loudly: the manifest (`audit_log_purges`) gets a row with
`status='failed'` naming the denial, Settings → Audit Log → Retention & Purge shows a red
status, and Settings → System Health → Scheduled Jobs shows the job as failed rather than
quietly skipped. `tools/audit_log_purge_tick.php` always connects with the application's
own configured DB credentials — it does not accept a separate, more-privileged connection.
So today the choice is binary: leave DELETE granted and let the built-in job run, or revoke
it and purge manually/off-box on your own schedule, using the same archive-then-delete
manifest this feature already produces. See
[CJIS-POSTURE.md § Auditing and Accountability](CJIS-POSTURE.md#54--auditing-and-accountability)
for the compliance framing of this trade-off.

For higher assurance, write audit rows to an off-box append-only store (S3 with object
lock, or similar) in real time. Not implemented — file a feature request if you need it.

---

## What's NOT logged

- **Routine page views** that don't touch sensitive data (dashboard load, settings page
  open) — only `audit_data_access()` calls (category `data`, activity `view`) log reads,
  and those are opt-in per call site, not automatic on every GET.
- **Every list-page render** (incident list, responder list) — these are gated by RBAC
  screen permissions, but rendering the list itself isn't an audit event; logging every
  view would balloon the table (the live install's busiest categories, `par` and `rbac`,
  are already the top two by volume without list-view logging).
- **Internal SSE event publishes** — the real-time event stream (`inc/sse.php`) is a
  separate mechanism from the audit log; publishing an SSE event doesn't itself write an
  audit row unless the code path that triggered it also calls `audit_log()`.

If your compliance regime requires logging something in this list that isn't covered, a
new audit hook is a small, additive change — one `audit_log()` call at the point the
event happens. See [When you want a new audit hook](#when-you-want-a-new-audit-hook)
below.

---

## OCSF alignment notes

`inc/audit.php` describes itself as an "OCSF-inspired lightweight audit logger" — inspired
is the operative word. TicketsCAD borrows OCSF's general shape (an event has a category, an
activity/verb, a severity, an actor, a target, and structured details) but does **not**
implement the Open Cybersecurity Schema Framework's actual wire format: there's no numeric
`class_uid`, no `activity_id`, no `severity_id`/`status_id` enum, and no OCSF-shaped JSON
export. If a downstream tool expects real OCSF, it needs a translation layer you'd write
yourself — TicketsCAD gives you the raw columns to build one from, not a shipped one.

Rough correspondence, for orientation only:

| OCSF concept | Nearest TicketsCAD column | Gap |
|---|---|---|
| `class_uid` (numeric event class) | `category` (free-text string) | No numeric mapping exists |
| `activity_id` (numeric verb) | `activity` (free-text string) | No numeric mapping exists |
| `time` | `event_time` | Second precision, not epoch millis |
| `actor.user.uid` | `user_id` | |
| `actor.user.name` | `user_name` | |
| `src_endpoint.ip` | `ip_address` | |
| `message` | `summary` | |
| `severity_id` | `severity` | Values 0–5 happen to line up with OCSF's severity scale numerically, but nothing enforces or documents that correspondence in code |
| `status_id` | — | No equivalent column; success/failure is expressed as a distinct `activity` value (e.g. `login` vs `login_failed`), not a status field |

---

## When you want a new audit hook

If a state change in the codebase isn't being logged and should be, add a call after the
action succeeds (so a failed action doesn't get logged as though it worked):

```php
require_once __DIR__ . '/../inc/audit.php';

audit_log(
    'config',
    'config_change',
    'setting',
    'password_min_length',
    "Changed password policy minimum length from {$old} to {$new}",
    ['key' => 'password_min_length', 'old_value' => $old, 'new_value' => $new],
    AUDIT_MEDIUM
);
```

For an action that can fail partway, log the failure as its own distinct `activity`
(e.g. `import_failed` alongside `import`) at `AUDIT_HIGH` or `AUDIT_CRITICAL`, rather than
inventing a status field this schema doesn't have.

---

## Where the code lives

| What | Path |
|---|---|
| Audit log writer + reader (`audit_log()`, `audit_get_log()`, `audit_ensure_table()`) | [`inc/audit.php`](../inc/audit.php) |
| Audit log admin viewer endpoint | [`api/audit-log.php`](../api/audit-log.php) |
| Dashboard "Recent activity" widget endpoint (`widget.audit_log` permission) | [`api/dashboard-audit.php`](../api/dashboard-audit.php) |
| Retention/purge logic | [`inc/audit-retention.php`](../inc/audit-retention.php) |
| Retention/purge admin endpoint | [`api/audit-retention.php`](../api/audit-retention.php) |
| Retention/purge scheduled tick (job key `audit_log_purge`) | [`tools/audit_log_purge_tick.php`](../tools/audit_log_purge_tick.php) |
| Retention/purge migration (table + RBAC + default setting) | [`sql/run_phase133_audit_retention.php`](../sql/run_phase133_audit_retention.php) |
| Manual table setup / verification tool | [`sql/setup_audit_log.php`](../sql/setup_audit_log.php) |
| RBAC-scoped "Audit Trail" tab (category=rbac, with CSV-of-current-page export) | [`assets/js/roles-audit.js`](../assets/js/roles-audit.js) |
| Webhook event allowlist (`_audit_to_webhook_event()`) | [`inc/webhooks.php`](../inc/webhooks.php) |
| Admin UI | Settings → Audit Log in [`settings.php`](../settings.php); Retention & Purge controls in the same panel |

---

This reference is maintained alongside the code. If an action that should be logged isn't,
that's a bug — file an issue or patch. If you find another sentence in this file that
isn't true against the current code, that's also a bug — the whole reason this rewrite
happened is that the previous version drifted for months without anyone noticing.
