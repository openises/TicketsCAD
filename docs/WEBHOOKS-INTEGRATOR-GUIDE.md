# Webhooks — Integrator Guide

**Audience:** developers building a system that receives TicketsCAD webhook deliveries.
**TicketsCAD-side admin:** Settings → Integrations → Webhooks. UI lets you register endpoints, pick events, and view delivery log.
**Code reference:** [`api/webhooks.php`](../api/webhooks.php), [`inc/webhooks.php`](../inc/webhooks.php).

---

## What a webhook is, in TicketsCAD's words

When something happens in TicketsCAD that you've subscribed to, we send an HTTP POST to your URL with a JSON body. We sign the body so you can verify it really came from us. If your receiver fails (network blip, 500 response, timeout), we retry with exponential backoff. Every attempt — success and failure — lands in the delivery log so you can audit.

You don't need a TicketsCAD account or login to receive webhooks. Just a public-ish HTTPS endpoint and the per-webhook secret.

---

## Quick start: receive your first event

### 1. Register your URL in TicketsCAD

1. Log in as admin → **Settings → Integrations → Webhooks**.
2. Click **Add Webhook**.
3. Fill in:
   - **Name:** something descriptive like "Slack alerts"
   - **URL:** `https://hooks.example.com/tcad-events`
   - **Events:** pick from the [event catalogue](#event-catalogue) below
   - **Secret:** click **Generate** or paste your own (32+ hex chars recommended)
4. Save. The secret is shown ONCE — copy it now.

### 2. Build a minimal receiver

Here's a 30-line PHP receiver that verifies signatures and logs payloads.

```php
<?php
// /var/www/hooks/tcad-events.php

$SECRET = 'paste-the-secret-from-tcad-here';

$raw = file_get_contents('php://input');
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$ts  = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

// Reject if timestamp is more than 5 minutes old (replay protection).
if (abs(time() - (int)$ts) > 300) {
    http_response_code(401);
    exit('stale');
}

// HMAC over "<timestamp>.<raw body>".
$expected = hash_hmac('sha256', $ts . '.' . $raw, $SECRET);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    exit('bad sig');
}

// Genuine: process it.
$evt = json_decode($raw, true);
error_log('TicketsCAD webhook: ' . $evt['event_type'] . ' for ' . $evt['entity_id']);

// Respond 2xx within 5 s to acknowledge.
http_response_code(200);
echo 'ok';
```

Save, expose via your web server, register the URL in TicketsCAD.

### 3. Trigger a test delivery

In the webhook row, click **Send Test**. TicketsCAD sends a `test.ping` event:

```json
{
  "event_type": "test.ping",
  "delivered_at": "2026-06-15T10:00:00Z",
  "entity_type": null,
  "entity_id": null,
  "payload": {
    "message": "This is a test from TicketsCAD"
  }
}
```

Check your receiver's log + check the **Delivery Log** tab in TicketsCAD. Both should show success.

If you see `failed` → see [Troubleshooting](#troubleshooting) below.

---

## Wire format

### HTTP request

```
POST /your-path HTTP/1.1
Host: hooks.example.com
Content-Type: application/json
User-Agent: TicketsCAD-Webhook/1.0
X-Webhook-Signature: <hex>
X-Webhook-Timestamp: <unix epoch seconds>
X-Webhook-Event: incident.created
X-Webhook-Delivery: <uuid>

{...JSON body...}
```

### JSON body shape

Every event has the same envelope:

```json
{
  "event_type": "incident.created",
  "delivered_at": "2026-06-15T10:00:00Z",
  "entity_type": "incident",
  "entity_id": 12345,
  "delivery_id": "550e8400-e29b-41d4-a716-446655440000",
  "tcad_version": "4.0.0-dev",
  "tcad_install": "cad.example.org",
  "payload": {
    // event-specific contents
  }
}
```

| Field | Purpose |
|---|---|
| `event_type` | Dotted-namespace event identifier. Same as `X-Webhook-Event` header. |
| `delivered_at` | RFC 3339 timestamp when TicketsCAD generated the delivery. |
| `entity_type` | The resource this event is about (`incident`, `responder`, `user`, etc.). |
| `entity_id` | Numeric primary key of the resource. |
| `delivery_id` | UUID. Use this for idempotency — if you've seen this delivery id before, skip. |
| `tcad_version` | TicketsCAD version that produced the event. |
| `tcad_install` | Hostname of the install. Useful when you receive from multiple deployments. |
| `payload` | Event-specific data. See [event catalogue](#event-catalogue). |

---

## Signature verification

### The scheme

`X-Webhook-Signature` = `HMAC-SHA256(timestamp + "." + raw_body, secret)` — hex-encoded.

Where `timestamp` is the `X-Webhook-Timestamp` header value (string), and `raw_body` is the literal request body bytes.

### Why include the timestamp?

Without it, an attacker who captured one valid delivery could replay it forever. The timestamp lets you reject deliveries older than ~5 min, limiting replay attacks to a small window.

### Reference implementations

#### Python

```python
import hashlib, hmac, time, json
from flask import Flask, request, abort

SECRET = b'paste-the-secret'
app = Flask(__name__)

@app.post('/tcad-events')
def receive():
    raw = request.get_data()  # raw bytes, NOT request.json which re-encodes
    sig = request.headers.get('X-Webhook-Signature', '')
    ts  = request.headers.get('X-Webhook-Timestamp', '')

    if abs(time.time() - int(ts)) > 300:
        abort(401, 'stale')

    expected = hmac.new(SECRET, f'{ts}.'.encode() + raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig):
        abort(401, 'bad sig')

    evt = json.loads(raw)
    print(f"event: {evt['event_type']} entity_id={evt['entity_id']}")
    return 'ok', 200
```

#### Node.js (Express)

```js
const express = require('express');
const crypto = require('crypto');
const SECRET = 'paste-the-secret';
const app = express();

// Crucial: get the raw bytes BEFORE express.json() reparses them.
app.use('/tcad-events', express.raw({ type: 'application/json' }));

app.post('/tcad-events', (req, res) => {
    const sig = req.get('X-Webhook-Signature') || '';
    const ts  = req.get('X-Webhook-Timestamp') || '';

    if (Math.abs(Date.now()/1000 - Number(ts)) > 300) {
        return res.status(401).send('stale');
    }

    const expected = crypto.createHmac('sha256', SECRET)
        .update(`${ts}.`).update(req.body).digest('hex');

    // Constant-time comparison.
    const sigBuf  = Buffer.from(sig, 'hex');
    const expBuf  = Buffer.from(expected, 'hex');
    if (sigBuf.length !== expBuf.length ||
        !crypto.timingSafeEqual(sigBuf, expBuf)) {
        return res.status(401).send('bad sig');
    }

    const evt = JSON.parse(req.body.toString());
    console.log(`event: ${evt.event_type} entity_id=${evt.entity_id}`);
    res.send('ok');
});

app.listen(3000);
```

#### Bash (for ad-hoc testing)

```bash
# Verify a captured delivery:
RAW=$(cat captured-body.json)
TS=1718454000
SIG=$(printf "%s.%s" "$TS" "$RAW" | openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')
echo "$SIG"
```

### Common verification mistakes

1. **Reparsing the body before HMAC.** Frameworks that auto-decode JSON give you a re-encoded body, which won't match the bytes we signed. Always sign / verify against the literal request bytes.
2. **Constant-time comparison.** Don't use `==`. Use `hash_equals` (PHP), `hmac.compare_digest` (Python), `crypto.timingSafeEqual` (Node).
3. **Forgetting the `.`** between timestamp and body. The format is `<ts>.<body>` not `<ts><body>`.

---

## Delivery semantics

### What we guarantee

- **At-least-once delivery.** If we can't reach you, we retry. You may get the same delivery twice (deduplicate on `delivery_id`).
- **In-order within a delivery_id.** A single retried delivery is delivered in order, but TWO different events (different delivery_ids) may arrive out of order.
- **2xx = accepted.** Any 2xx response (200, 201, 202, 204) is treated as "you got it, we're done".
- **Non-2xx = retry.** 4xx (except 410) and 5xx + timeouts trigger retry.

### What we DON'T guarantee

- **Exactly-once delivery.** You must dedupe.
- **Strict global ordering.** If event A happens before event B, you might receive B before A.
- **Reordering on retry.** A retry might race a new delivery.

### Retry schedule

| Attempt | Delay before this attempt |
|---|---|
| 1 (initial) | 0 (immediate) |
| 2 | 30 s |
| 3 | 2 min |
| 4 | 10 min |
| 5 | 1 h |
| 6 | 6 h |
| 7 | 24 h |
| (after 7) | give up; mark `permanently_failed` |

7 attempts span 31+ hours. After that we stop retrying and the delivery row is marked `permanently_failed`. You can manually re-fire from the admin UI.

### Special status codes

- **410 Gone** — TicketsCAD interprets as "you've moved this endpoint" and auto-disables the webhook (admin re-enables after fixing the URL).
- **Timeout > 30 s** — counted as a retry-able failure.
- **TLS verification failure** — counted as permanent failure (no retry); admin must fix the cert or disable verification (NOT recommended).

---

## Event catalogue

**Canonical list — audit-driven (2026-06-28).** The authoritative
mapping lives in [`inc/webhooks.php`](../inc/webhooks.php)'s
`_audit_to_webhook_event()` allowlist. The TicketsCAD-side full
list is in [EXTERNAL-API.md §10](EXTERNAL-API.md#10-webhook-subscriptions-cross-link).
This section is the integrator-side mirror — the same 26 events
with full payload-field detail.

Per Decision #4 in the Phase 94 design, webhook firing is
**explicit-allowlist only**: an audit row that doesn't match a
tuple in the map will NOT fire any webhook even if a future feature
adds it. To add a new event type you must add a one-line entry to
the map AND document it here. This is deliberate — admin / config
/ security audit rows can't leak to external subscribers by
accident.

### Incidents

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `incident.created` | New incident saved | `incident_number`, `incident_type_id`, `severity`, `scope`, `address`, `lat`, `lng`, `status`, `created_by` |
| `incident.updated` | Any field changed | `changes` object (per-field old → new), `updated_by` |
| `incident.closed` | Status set to closed/terminal | `closed_at`, `closed_by`, `duration_seconds` |
| `incident.reopened` | Closed incident reopened | `reopened_by`, `previous_close_time` |
| `incident.deleted` | Soft-deleted | `deleted_by` |
| `incident.note_added` | Activity note added | `note_id`, `note_text`, `note_by` |

### Assignments

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `assign.created` | Responder assigned to an incident | `incident_id`, `responder_id`, `responder_name`, `assigned_by`, `role?` |
| `assign.removed` | Responder unassigned from an incident | `incident_id`, `responder_id`, `removed_by` |

### Responders (units/equipment)

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `responder.created` | New unit/responder added | `id`, `name`, `handle`, `description`, `created_by` |
| `responder.updated` | Responder field changed | `id`, `changes` object, `updated_by` |
| `responder.deleted` | Soft-deleted | `id`, `deleted_by` |
| `responder.status_changed` | Unit status changed (Available/Enroute/etc.) | `id`, `old_status`, `new_status`, `incident_id?`, `changed_by` |

### Members (personnel)

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `member.created` | New personnel record | `id`, `first_name`, `last_name`, `callsign?`, `created_by` |
| `member.updated` | Member field changed | `id`, `changes`, `updated_by` |
| `member.deleted` | Soft-deleted | `id`, `deleted_by` |
| `member.status_changed` | Member status changed (On-duty/Off-duty/etc.) | `id`, `old_status_id`, `new_status_id`, `changed_by` |
| `member.location_updated` | Position update from any provider (browser GPS / APRS / OwnTracks / Traccar / etc.) | `id`, `lat`, `lng`, `accuracy?`, `provider`, `reported_at` |

### Facilities

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `facility.created` | New facility added | `id`, `name`, `type?`, `lat`, `lng`, `created_by` |
| `facility.updated` | Facility field changed | `id`, `changes`, `updated_by` |
| `facility.deleted` | Soft-deleted | `id`, `deleted_by` |

### Teams

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `team.created` | New team created | `id`, `name`, `created_by` |
| `team.updated` | Team field changed | `id`, `changes`, `updated_by` |
| `team.deleted` | Team hard-deleted | `id`, `deleted_by` |

### Incident-type configuration

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `incident_type.created` | Admin added a new incident type | `id`, `name`, `severity?`, `created_by` |
| `incident_type.updated` | Incident-type field changed | `id`, `changes`, `updated_by` |
| `incident_type.deleted` | Incident type removed | `id`, `deleted_by` |

### Attachments

| Event type | When | `payload` fields (excerpt) |
|---|---|---|
| `attachment.created` | File uploaded + attached to a parent (incident, facility, responder, etc.) | `id`, `parent_type`, `parent_id`, `filename`, `mime`, `size_bytes`, `uploaded_by` |
| `attachment.deleted` | Attachment deleted | `id`, `parent_type`, `parent_id`, `deleted_by` |

### Aspirational events — NOT YET WIRED

The following events were drafted in the original Phase 94 design
and have schema support, but they are **not in the current
allowlist** so they do NOT fire today. Listed here so integrators
know what's on the roadmap and don't write code that waits forever
for them. Each requires (a) the relevant feature code to call
`audit_log()` with the right tuple AND (b) a one-line addition to
the allowlist in `inc/webhooks.php`.

`incident.assigned`, `incident.unassigned`, `incident.status_changed`,
`incident.major_linked`, `incident.par_initiated`, `incident.par_ack`,
`incident.par_overdue`, `responder.clocked_in`, `responder.clocked_out`,
`responder.mayday`, `user.created`, `user.updated`, `user.disabled`,
`user.password_reset`, `user.tfa_reset`, `auth.login_success`,
`auth.login_failed`, `auth.locked_out`, `comms.chat_message`,
`comms.broadcast`, `comms.dmr_call_complete`, `comms.mesh_message`,
`config.role_changed`, `config.role_assigned`, `config.role_revoked`,
`config.permission_changed`, `geofence.enter`, `geofence.exit`,
`system.backup_completed`, `system.backup_failed`,
`system.update_available`, `test.ping`.

If you need any of these for your integration, open a feature
request — they're cheap to add once there's a use case to drive
the testing.

---

## Subscription model

You don't have to subscribe to every event. Per webhook, pick:

- **Specific event types** (e.g. `incident.created`, `responder.mayday`) — comma-separated list
- **Categories** with `*` wildcard (e.g. `incident.*`, `auth.*`)
- **All events** with just `*`

Stored as a JSON array in `webhooks.subscribed_events`. The webhook fires only if at least one of its subscriptions matches the event type.

Filtering is server-side (we don't waste a network round-trip if you're not subscribed).

---

## Idempotency

You WILL get duplicate deliveries occasionally. Dedupe on `delivery_id`.

Simple PHP example:

```php
$deliveryId = json_decode($raw, true)['delivery_id'] ?? null;
if ($deliveryId && already_processed($deliveryId)) {
    http_response_code(200);
    exit('duplicate-ok');
}
process_event($evt);
mark_processed($deliveryId);
```

`mark_processed` could be a Redis SET with TTL, a row in a dedup table, or a memcached entry. TTL of 7 days covers TicketsCAD's max retry window.

---

## Rate limits

Currently: no built-in rate limit on outbound webhooks. If a receiver is slow, deliveries queue up.

Best practice for receivers:

- Respond 200 within 5 s. Do real work async (queue + worker).
- If you must do work synchronously, set your timeout to 25 s (under our 30 s default).

If you need TicketsCAD to throttle outbound, file an issue.

---

## The delivery log

Every attempt (success and failure) writes a row to `webhook_deliveries`:

| Column | Purpose |
|---|---|
| `id` | PK |
| `webhook_id` | FK to `webhooks` |
| `event_type` | The event name |
| `delivery_id` | UUID shared across retries |
| `attempt_number` | 1, 2, 3, … up to 7 |
| `request_body` | The JSON we sent |
| `response_status` | HTTP code we got back |
| `response_body` | Trimmed to 4 KB |
| `error` | Free-text reason on failure |
| `created_at` | When THIS attempt was made |
| `next_retry_at` | Next attempt time (NULL on success / permanent failure) |
| `status` | `pending`, `succeeded`, `failed`, `permanently_failed` |

View in Settings → Integrations → Webhooks → row → Delivery Log. Useful queries:

```sql
-- Failure rate per webhook in last 24 h
SELECT w.name,
       SUM(CASE WHEN d.status='succeeded' THEN 1 ELSE 0 END) AS ok,
       SUM(CASE WHEN d.status IN ('failed','permanently_failed') THEN 1 ELSE 0 END) AS bad
  FROM webhooks w
  JOIN webhook_deliveries d ON d.webhook_id = w.id
 WHERE d.created_at > NOW() - INTERVAL 1 DAY
 GROUP BY w.id;

-- Permanently-failed deliveries needing manual re-fire
SELECT * FROM webhook_deliveries
 WHERE status = 'permanently_failed'
 ORDER BY created_at DESC LIMIT 50;
```

The delivery log itself is retained according to `webhook_delivery_log_retention_days` (default 30; raise for compliance).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Test ping returns `succeeded` but real events don't arrive | Event subscription doesn't include the event type | Settings → row → Edit → add the event to Subscribed Events |
| Delivery log shows `failed: ssl certificate problem` | Receiver TLS cert is self-signed or expired | Fix the cert; don't disable TLS verification on the TicketsCAD side |
| Delivery log shows `failed: timeout` | Receiver is too slow | Have receiver return 200 immediately, process async |
| Delivery log shows `failed: 401 bad sig` | HMAC computed wrong (very common!) | Verify you're signing `<ts>.<raw_body>`, not just the body. Use constant-time compare. |
| Delivery log shows `failed: 410 gone` | Webhook auto-disabled | Receiver returned 410; admin must re-enable |
| Every delivery is duplicated | Receiver isn't returning 2xx in time, so TicketsCAD retries | Speed up receiver OR dedupe on `delivery_id` |
| Receiver gets event that doesn't match `entity_id` | Stale dedupe cache returning old data | Clear receiver dedup cache; check delivery_id matches |

See also [TROUBLESHOOTING.md § webhook-failed](TROUBLESHOOTING.md#webhook-failed).

---

## Security checklist

For each webhook receiver:

- [ ] Use a unique secret per webhook (don't share across services)
- [ ] Verify the HMAC signature on every request (never trust unsigned traffic)
- [ ] Reject deliveries with stale timestamps (≥ 5 min)
- [ ] Use constant-time comparison for the signature
- [ ] Treat `delivery_id` as the dedup key
- [ ] Log every received delivery on YOUR side too (defence in depth)
- [ ] Don't echo the payload back in the response body (avoid mirroring untrusted data)
- [ ] Rotate the secret periodically (admin UI → Rotate Secret; receiver swaps secret)
- [ ] Restrict the receiver to specific source IPs if you can pin TicketsCAD's egress IP

---

## Where the code lives

| What | Path |
|---|---|
| Admin endpoint | [`api/webhooks.php`](../api/webhooks.php) |
| Delivery engine | [`inc/webhooks.php`](../inc/webhooks.php) |
| Cron job for retries | [`tools/webhook_retry_tick.php`](../tools/webhook_retry_tick.php) — run on a schedule (see [`tools/newui-webhook-retry.service.example`](../tools/newui-webhook-retry.service.example) + `.timer.example`) |
| Schema | `webhooks`, `webhook_deliveries` tables (`sql/run_webhooks.php`) |
| Tests | tools/test_webhooks.php (if present) |

---

This guide is maintained alongside the code. If the wire format changes, this doc is wrong — file a patch.
