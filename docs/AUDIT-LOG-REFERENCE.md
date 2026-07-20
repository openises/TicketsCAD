# Audit Log — Reference

**Audience:** compliance officer, security analyst, developer querying the audit trail.
**Schema:** OCSF (Open Cybersecurity Schema Framework) v1.x aligned.
**Implementation:** [`inc/audit.php`](../inc/audit.php), `audit_log` table.
**Admin UI:** Settings → Audit & Compliance → Audit Log.

---

## Table schema

```sql
CREATE TABLE audit_log (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category        VARCHAR(32)  NOT NULL,    -- auth | data | admin | comms | security
    action          VARCHAR(64)  NOT NULL,    -- the verb (login, create, update, ...)
    entity_type     VARCHAR(32)  NULL,        -- user | incident | role | webhook | ...
    entity_id       INT UNSIGNED NULL,
    actor_user_id   INT UNSIGNED NULL,        -- the user who did it (NULL = system / unauth)
    actor_username  VARCHAR(64)  NULL,        -- denormalised for fast read
    ip_address      VARCHAR(45)  NULL,        -- trusted-proxy-aware client IP
    user_agent      VARCHAR(512) NULL,
    summary         VARCHAR(255) NULL,        -- human-readable one-liner
    details_json    JSON         NULL,        -- structured payload, schema varies by action
    status          ENUM('success','failure','partial') DEFAULT 'success',
    severity        ENUM('info','low','medium','high','critical') DEFAULT 'info',
    request_id      CHAR(36)     NULL,        -- correlates with web request
    created_at      DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    PRIMARY KEY (id),
    KEY idx_created   (created_at),
    KEY idx_actor     (actor_user_id, created_at),
    KEY idx_entity    (entity_type, entity_id, created_at),
    KEY idx_category  (category, created_at),
    KEY idx_security  (severity, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## How rows get written

Every API endpoint and internal handler that mutates state calls:

```php
audit_log(
    string  $category,         // 'auth' | 'data' | 'admin' | 'comms' | 'security'
    string  $action,           // 'create' | 'update' | 'delete' | 'login' | 'reset_password' | ...
    ?string $entity_type,      // 'user' | 'incident' | 'role' | ...
    ?int    $entity_id,        // primary key of the affected row
    ?string $summary,          // one-line human-readable
    ?array  $details = null,   // structured details; goes into details_json
    string  $status = 'success',
    string  $severity = 'info'
);
```

The function fills in the rest from session + request context:

- `actor_user_id` and `actor_username` from `$_SESSION` (NULL for unauthenticated / system events)
- `ip_address` via `client_ip()` (trusted-proxy aware; reads X-Forwarded-For if the request came from a CIDR you've trusted)
- `user_agent` from `$_SERVER['HTTP_USER_AGENT']`
- `request_id` from `$_SERVER['HTTP_X_REQUEST_ID']` if present, else a per-request UUID
- `created_at` from `CURRENT_TIMESTAMP(3)` (millisecond precision)

**The function never throws and never blocks.** If the audit table is missing or write fails, the error is silently dropped (an audit-log failure should never cascade into a user-facing failure). Phase 73f added `error_log()` so silent failures land in the Apache log.

---

## Categories

| Category | When | Typical actions |
|---|---|---|
| `auth` | Authentication events | `login`, `logout`, `login_failed`, `lockout`, `tfa_verify`, `tfa_enroll`, `password_change`, `session_expired`, `session_destroyed` |
| `data` | CJI / PII access and modification | `view`, `create`, `update`, `delete`, `export` on incidents, members, constituents, ICS forms |
| `admin` | Privileged administrative actions | `role_create`, `role_assign`, `role_revoke`, `permission_change`, `user_create`, `user_disable`, `user_reset_password`, `config_change`, `view_audit_log` |
| `comms` | Outbound communications | `send_chat`, `send_sms`, `send_email`, `broadcast`, `routing_engine_forward`, `tx_dmr_text` |
| `security` | Security-relevant events that aren't routine auth | `csrf_reject`, `lockout_trigger`, `tfa_reset`, `encryption_key_rotation`, `key_load_failed`, `xss_attempt_blocked`, `webhook_failure` |

---

## Action conventions

Actions follow a verb-style naming. Common verbs:

| Verb | Meaning |
|---|---|
| `create` | New row inserted |
| `update` | Existing row modified (`details` contains a per-field old → new map) |
| `delete` | Soft-delete (`deleted_at` set) |
| `purge` | Hard-delete (row gone) |
| `view` | Read access (logged only when CJI / PII is involved, not on every list page) |
| `export` | Bulk read out of the system (CSV, JSON, XML download) |
| `assign` / `revoke` | Permission or role change |
| `tx` / `rx` | Outbound / inbound on a channel |
| `attempt` / `success` / `failure` | Multi-step actions that may fail (e.g. login attempt → success or failure) |

---

## Severity

| Severity | When |
|---|---|
| `info` | Normal events (login success, incident create, status change) |
| `low` | Minor anomalies (one failed login, single CSRF reject) |
| `medium` | Notable events (rate-limit hit, lockout fired, permission revoked) |
| `high` | Security-relevant events that need review (admin password reset, super-admin grant, encryption key load failure) |
| `critical` | Events that must trigger an alert (mayday, multiple lockouts, attempted privilege escalation) |

Severity is set at write-time by the caller. Many auth events default to `info`; security events default to `medium` or higher.

---

## `details_json` schema per category

The `details_json` field is free-shape, but conventions per category make queries practical.

### `auth` actions

```json
// auth.login (success or failure)
{
  "username_attempted": "alice",
  "tfa_used": true,
  "remembered_device": false,
  "reason": "wrong_password"     // only on failure
}

// auth.password_change
{
  "rotation": "scheduled",        // user-initiated | scheduled | admin_reset
  "old_hash_age_days": 87
}

// auth.lockout
{
  "lockout_until": "2026-06-15T11:00:00Z",
  "failed_attempt_count": 5,
  "window_minutes": 5
}
```

### `data` actions

```json
// data.update (incident)
{
  "changes": {
    "severity": {"old": 3, "new": 2},
    "status":   {"old": "active", "new": "closed"}
  },
  "ref": "INC-2026-00045"
}

// data.view (sensitive field)
{
  "field": "patient.dob",
  "ref": "INC-2026-00045"
}

// data.export
{
  "format": "csv",
  "filter": {"date_from": "2026-06-01", "date_to": "2026-06-15"},
  "row_count": 1247
}
```

### `admin` actions

```json
// admin.role_assign
{
  "target_user_id": 42,
  "target_username": "bob",
  "role_id": 3,
  "role_name": "dispatcher",
  "expires_at": "2026-12-31T23:59:59Z"
}

// admin.config_change
{
  "key": "password_min_length",
  "old_value": "8",
  "new_value": "12"
}

// admin.user_reset_password (CJIS — requires reason)
{
  "target_user_id": 42,
  "target_username": "bob",
  "reason": "user lost their phone and called the help desk",
  "force_change_on_login": true
}
```

### `comms` actions

```json
// comms.send_sms
{
  "to_count": 3,
  "channel": "twilio",
  "body_truncated_to": 140,
  "subject": "Major incident"
}

// comms.tx_dmr_text
{
  "dmr_channel_id": 1,
  "talkgroup": "9990",
  "text_first_120_chars": "All clear at scene",
  "duration_ms": 1416,
  "engine": "piper"
}
```

### `security` actions

```json
// security.tfa_reset
{
  "target_user_id": 42,
  "reason": "lost authenticator and all backup codes"
}

// security.key_load_failed
{
  "key_type": "tfa",
  "key_file": "/var/www/keys/tfa.key",
  "error": "Permission denied"
}
```

---

## Example queries

### Last 24 h of high-severity events

```sql
SELECT created_at, actor_username, action, summary, ip_address
  FROM audit_log
 WHERE severity IN ('high', 'critical')
   AND created_at > NOW() - INTERVAL 1 DAY
 ORDER BY created_at DESC;
```

### Failed login storm

```sql
SELECT ip_address, COUNT(*) AS hits
  FROM audit_log
 WHERE category = 'auth'
   AND action   = 'login_failed'
   AND created_at > NOW() - INTERVAL 1 HOUR
 GROUP BY ip_address
HAVING hits > 10
 ORDER BY hits DESC;
```

### Who touched a specific incident?

```sql
SELECT created_at, actor_username, action, summary
  FROM audit_log
 WHERE entity_type = 'incident'
   AND entity_id   = 12345
 ORDER BY created_at;
```

### Permission changes this quarter

```sql
SELECT created_at, actor_username, action, summary,
       JSON_EXTRACT(details_json, '$.role_name')         AS role,
       JSON_EXTRACT(details_json, '$.permission_code')   AS perm
  FROM audit_log
 WHERE category = 'admin'
   AND action LIKE 'permission%'
   AND created_at > NOW() - INTERVAL 90 DAY
 ORDER BY created_at;
```

### Super-admin role grants ever

```sql
SELECT created_at, actor_username, action,
       JSON_EXTRACT(details_json, '$.target_username') AS target
  FROM audit_log
 WHERE action = 'role_assign'
   AND JSON_EXTRACT(details_json, '$.role_name') = '"Super Admin"'
 ORDER BY created_at;
```

### Slow-query candidates (which actions write the most rows?)

```sql
SELECT category, action, COUNT(*) AS n
  FROM audit_log
 WHERE created_at > NOW() - INTERVAL 7 DAY
 GROUP BY category, action
 ORDER BY n DESC LIMIT 20;
```

---

## Retention

- **Default retention:** 365 days
- **CJIS minimum:** 365 days
- **Config knob:** `settings.audit_log_retention_days`

Enforcement: a dedicated trim script (`tools/audit-log-trim.php`) is planned but not yet shipped. Until it lands, run the trim as SQL on a cron:

```bash
# Inspect current retention setting
mariadb newui -e "SELECT value FROM settings WHERE name='audit_log_retention_days';"

# Trim manually (run as a daily cron):
sudo mariadb newui -e "DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 365 DAY;"
```

Override per-record retention is **not** supported by design — every event has the same retention. If you need longer retention for specific event types, export to a SIEM with longer retention.

---

## Export

### Admin UI

Settings → Audit & Compliance → Audit Log → **Export**.

Filters: date range, category, severity, actor user, entity type.

Formats: JSONL (one event per line, OCSF-aligned), CSV (flat schema, lossy on `details_json`).

The export is a single HTTP download; for large ranges, expect a few-second hold while we stream.

### Programmatic export

```bash
# JSONL, last 30 days, only auth events
curl -s -H "Cookie: PHPSESSID=YOUR_ADMIN_SESSION" \
     "https://cad.example.org/api/audit-log.php?action=export&from=2026-05-15&to=2026-06-15&category=auth&format=jsonl" \
  > /var/log/tcad-audit-may.jsonl
```

The endpoint streams; no pagination needed. Large exports (millions of rows) may take minutes.

### SIEM integration patterns

#### Pattern 1: nightly batch to S3/Splunk

```cron
# crontab -u www-data
30 1 * * * mariadb-dump newui audit_log --where="created_at >= CURDATE() - INTERVAL 1 DAY" | gzip | aws s3 cp - s3://my-bucket/tcad-audit/$(date +\%F).sql.gz
```

#### Pattern 2: real-time via webhook

Subscribe a Splunk HEC / Datadog / ELK endpoint to the `audit.*` events via the [webhooks system](WEBHOOKS-INTEGRATOR-GUIDE.md). Note that audit events are NOT currently fired as webhooks (queued for a future phase); for now use the batch pattern.

#### Pattern 3: SQL replica

Replicate the `audit_log` table to a separate, append-only DB (read-only SIEM-side). Standard MariaDB replication works.

---

## Tamper-resistance

The audit log is append-only by code convention (no endpoint calls `UPDATE` or `DELETE` against `audit_log`). For defence-in-depth, **revoke the DELETE/UPDATE grants from the application's DB user**:

```sql
REVOKE DELETE, UPDATE ON newui.audit_log FROM 'newui'@'localhost';
-- The retention cron uses a separate DB user with DELETE permission ONLY on audit_log.
-- Use that user for any audit-log trim job you wire up.
FLUSH PRIVILEGES;
```

With this in place, even a successful SQL-injection attack on the app would not be able to silently rewrite history.

For higher assurance, write audit rows to an off-box append-only store (S3 with object-lock, AWS CloudTrail-style) in real time. Not yet implemented — file a feature request if you need it.

---

## What's NOT logged

- **Routine page views** that don't touch CJI / PII (dashboard load, settings page open)
- **Read access to non-sensitive lists** (incident list, responder list — these are visible to anyone with the relevant screen perm; logging every view would balloon the table)
- **Detailed per-keystroke actions** in the new-incident form (we log the final save, not every input change)
- **Internal SSE event publishes** (would dwarf real events; covered by application logs)

If your compliance regime requires logging the things in this list, we have to add new audit hooks. File an issue.

---

## OCSF alignment notes

We use OCSF v1.x naming conventions where they apply:

| OCSF field | Our column | Notes |
|---|---|---|
| `class_uid` | (implied by `category`) | OCSF class 3002 (Authentication), 6003 (User Inventory), etc. — we don't store the numeric class explicitly |
| `activity_id` | (derived from `action`) | OCSF activity numbers; we use the verb form |
| `time` | `created_at` (in millis) | We store as DATETIME(3); OCSF expects epoch millis. Conversion happens at export |
| `actor.user.uid` | `actor_user_id` | |
| `actor.user.name` | `actor_username` | |
| `src_endpoint.ip` | `ip_address` | |
| `metadata.event_code` | `action` | |
| `message` | `summary` | |
| `severity_id` | `severity` | OCSF: 1=info, 2=low, 3=medium, 4=high, 5=critical — same |
| `status_id` | `status` | OCSF: 1=success, 2=failure |

The JSONL export emits OCSF-compatible records. CSV export is denormalised for spreadsheet review and may lose nested `details_json` keys.

---

## When you want a new audit hook

If a state change in the codebase isn't being logged and should be, add a call:

```php
require_once __DIR__ . '/../inc/audit.php';

audit_log(
    'admin',
    'config_change',
    'setting',
    null,
    "Changed password policy minimum length from $old to $new",
    ['key' => 'password_min_length', 'old_value' => $old, 'new_value' => $new],
    'success',
    'medium'
);
```

Add the call AFTER the action succeeds (so a failed action doesn't get logged as success). For failures, change `status` to `failure` and `severity` to `medium` or higher.

---

## Where the code lives

| What | Path |
|---|---|
| Audit log writer | [`inc/audit.php`](../inc/audit.php) |
| Audit log admin endpoint | [`api/audit-log.php`](../api/audit-log.php) |
| Trim cron | `tools/audit-log-trim.php` (planned) |
| Export cron template | `tools/audit-log-export.php` (planned) |
| Schema migration | `sql/setup_audit_log.php` (or per-feature migration that creates audit_log) |
| Admin UI | Settings → Audit & Compliance in [`settings.php`](../settings.php) |

---

This reference is maintained alongside the code. If an action that should be logged isn't, that's a bug — file an issue or patch.
