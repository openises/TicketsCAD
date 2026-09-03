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
$sig = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE_V2'] ?? '';
$ts  = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

// Reject if the timestamp is more than 5 minutes from now (replay
// protection). abs() also rejects stamps implausibly far in the future.
if ($ts === '' || !ctype_digit($ts) || abs(time() - (int)$ts) > 300) {
    http_response_code(401);
    exit('stale');
}

// The signature arrives as "sha256=<hex>". Strip the prefix first.
if (stripos($sig, 'sha256=') === 0) $sig = substr($sig, 7);

// HMAC over "<timestamp>.<raw body>".
$expected = hash_hmac('sha256', $ts . '.' . $raw, $SECRET);
if (!hash_equals($expected, $sig)) {
    http_response_code(401);
    exit('bad sig');
}

// Genuine: process it. Dedupe on the delivery id — retries of the same
// logical delivery re-present the SAME value.
$deliveryId = $_SERVER['HTTP_X_WEBHOOK_DELIVERY'] ?? '';
// if (already_seen($deliveryId)) { http_response_code(200); exit('dup'); }

$evt = json_decode($raw, true);
error_log('TicketsCAD webhook: ' . $evt['event_type']
          . ' data=' . json_encode($evt['data']));

// Respond 2xx within 5 s to acknowledge.
http_response_code(200);
echo 'ok';
```

> **Upgrading from before v4.2.3?** Deliveries used to be signed over the
> body alone, with no timestamp and no delivery id, and the header to read
> was `X-Webhook-Signature`. That header is still sent so existing
> receivers keep working, but it offers **no replay protection** — a
> captured delivery replayed later verifies against it forever. Move to
> `X-Webhook-Signature-V2` as shown above. See
> [Signature verification](#signature-verification).

Save, expose via your web server, register the URL in TicketsCAD.

### 3. Trigger a test delivery

In the webhook row, click **Send Test**. TicketsCAD sends a `test` event, signed and headed exactly like a real delivery:

```json
{
  "event_type": "test",
  "timestamp": "2026-06-15T10:00:00Z",
  "data": {
    "message": "This is a test webhook from TicketsCAD NewUI.",
    "version": "4.0"
  }
}
```

Check your receiver's log + check the **Delivery Log** tab in TicketsCAD. Both should show success.

If you see `failed` → see [Troubleshooting](#troubleshooting) below.

### 4. Testing against a receiver on your own network

TicketsCAD refuses to deliver to loopback, link-local or RFC1918 addresses
by default — an SSRF guard, so that a compromised admin account can't point
a webhook at `169.254.169.254` and harvest cloud metadata credentials.

**Do not work around this by pointing a real webhook at a public capture
service** (webhook.site and friends). The URL is the only access control on
those, and anyone who learns it sees your full delivery bodies *and* the
signature header — which is exactly how an attacker obtains the valid
delivery they need in order to replay one.

Instead, allow your own host explicitly. An admin adds the hostname suffix
to the `webhook_url_allowlist` setting (newline-separated):

```
localhost
dev.internal.example.org
```

Deliveries to matching hosts are then permitted, and everything else stays
blocked. Remove the entry when you're done. See
[EXTERNAL-API.md §10](EXTERNAL-API.md) for the full rule set.

---

## Wire format

### HTTP request

```
POST /your-path HTTP/1.1
Host: hooks.example.com
Content-Type: application/json
User-Agent: TicketsCAD-Webhook/4.0
X-Webhook-Event: incident.created
X-Webhook-Timestamp: <unix epoch seconds>
X-Webhook-Delivery: <uuid>
X-Webhook-Signature-V2: sha256=<hex>
X-Webhook-Signature: sha256=<hex>

{...JSON body...}
```

| Header | Purpose |
|---|---|
| `X-Webhook-Event` | Dotted-namespace event identifier. Same as the body's `event_type`. |
| `X-Webhook-Timestamp` | Unix epoch seconds at which **this transmission** was signed. A retry carries the retry's own time, not the original's. Covered by the signature. |
| `X-Webhook-Delivery` | Delivery uid. **The idempotency key** — every retry and operator replay of one logical delivery repeats the same value. |
| `X-Webhook-Signature-V2` | `sha256=` + `HMAC-SHA256(secret, "<timestamp>.<raw_body>")`, hex. **Verify this one.** |
| `X-Webhook-Signature` | Legacy `sha256=` + `HMAC-SHA256(secret, raw_body)`, hex. No replay protection; kept only so pre-v4.2.3 receivers keep working. An admin can disable it with the `webhook_legacy_signature` setting. |

### JSON body shape

Every event has the same envelope:

```json
{
  "event_type": "incident.created",
  "timestamp": "2026-06-15T10:00:00Z",
  "data": {
    // event-specific contents
  }
}
```

| Field | Purpose |
|---|---|
| `event_type` | Dotted-namespace event identifier. Same as the `X-Webhook-Event` header. |
| `timestamp` | RFC 3339 time at which TicketsCAD **generated** the event. On a retry this is still the ORIGINAL generation time, so do **not** use it for freshness checks — use the `X-Webhook-Timestamp` header, which is stamped per transmission. |
| `data` | Event-specific contents. See [event catalogue](#event-catalogue). |

The delivery id lives in the `X-Webhook-Delivery` **header**, not in the body.

---

## Signature verification

### The scheme

`X-Webhook-Signature-V2` = `sha256=` + `HMAC-SHA256(timestamp + "." + raw_body, secret)` — hex-encoded.

Where `timestamp` is the `X-Webhook-Timestamp` header value (string), and `raw_body` is the literal request body bytes. **Strip the `sha256=` prefix before comparing.**

### Why include the timestamp?

Without it, an attacker who captured one valid delivery could replay it forever. The timestamp lets you reject deliveries older than ~5 min, limiting replay attacks to a small window.

Because the timestamp is *inside* the signed material, an attacker cannot simply re-stamp a captured delivery to get past your freshness check — changing the timestamp invalidates the signature.

The window TicketsCAD advertises is configurable per install via the `webhook_replay_tolerance_sec` setting (default `300`, clamped to 30–86400). Pick a value on your side that matches, allowing for clock skew; run NTP on both ends.

### Two signature headers, and which to use

| Header | Signed material | Replay-safe | Use it? |
|---|---|---|---|
| `X-Webhook-Signature-V2` | `"<timestamp>.<raw_body>"` | Yes | **Yes** |
| `X-Webhook-Signature` | `raw_body` only | **No** | Only until you have migrated |

Before v4.2.3 only the second existed, and the timestamped scheme this guide describes was documented but **not implemented** — a receiver written from that version of this page could not verify anything. If you built one and gave up on verification, this is why; please re-enable it against `-V2`. Reported by Ron Jones (@rjonesbsink).

Once every receiver on your install reads `-V2`, an admin can stop the legacy header being sent by setting `webhook_legacy_signature` to `0`.

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
    sig = request.headers.get('X-Webhook-Signature-V2', '')
    ts  = request.headers.get('X-Webhook-Timestamp', '')

    if not ts.isdigit() or abs(time.time() - int(ts)) > 300:
        abort(401, 'stale')

    # The signature arrives as "sha256=<hex>".
    if sig.startswith('sha256='):
        sig = sig[7:]

    expected = hmac.new(SECRET, f'{ts}.'.encode() + raw, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, sig):
        abort(401, 'bad sig')

    # Dedupe key — retries repeat this value.
    delivery_id = request.headers.get('X-Webhook-Delivery', '')

    evt = json.loads(raw)
    print(f"event: {evt['event_type']} data={evt['data']} delivery={delivery_id}")
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
    let sig   = req.get('X-Webhook-Signature-V2') || '';
    const ts  = req.get('X-Webhook-Timestamp') || '';

    if (!/^\d+$/.test(ts) || Math.abs(Date.now()/1000 - Number(ts)) > 300) {
        return res.status(401).send('stale');
    }

    // The signature arrives as "sha256=<hex>".
    if (sig.startsWith('sha256=')) sig = sig.slice(7);

    const expected = crypto.createHmac('sha256', SECRET)
        .update(`${ts}.`).update(req.body).digest('hex');

    // Constant-time comparison.
    const sigBuf  = Buffer.from(sig, 'hex');
    const expBuf  = Buffer.from(expected, 'hex');
    if (sigBuf.length !== expBuf.length ||
        !crypto.timingSafeEqual(sigBuf, expBuf)) {
        return res.status(401).send('bad sig');
    }

    // Dedupe key — retries repeat this value.
    const deliveryId = req.get('X-Webhook-Delivery') || '';

    const evt = JSON.parse(req.body.toString());
    console.log(`event: ${evt.event_type} delivery=${deliveryId}`, evt.data);
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
4. **Leaving the `sha256=` prefix on.** The header value is `sha256=<hex>`; compare only the hex part.
5. **Reading the wrong header.** `X-Webhook-Signature` (no `-V2`) is the legacy body-only digest and will never match a `<ts>.<body>` computation.
6. **Checking freshness against the body's `timestamp`.** That is the event's *generation* time and does not change on a retry, so a legitimate retry looks stale. Use the `X-Webhook-Timestamp` header.

---

## Delivery semantics

### What we guarantee

- **At-least-once delivery.** If we can't reach you, we retry. You may get the same delivery twice (deduplicate on the `X-Webhook-Delivery` header).
- **In-order within a delivery id.** A single retried delivery is delivered in order, but TWO different events (different delivery ids) may arrive out of order.
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
| `incident.primary_changed` | Primary/responsible unit set or cleared (Phase 151, GH#138) — **off by default**; fires only on installs with the Primary Unit setting enabled (Settings → Incident Lifecycle) | `ticket_id`, `previous_responder_id`, `previous_responder_name`, `new_responder_id`, `new_responder_name`, `reason` (`manual` \| `auto_single_unit` \| `unassigned`), `set_by`, `via_external_api` |

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

Stored as a JSON array in `webhook_subscriptions.event_filters_json`. The webhook fires only if at least one of its subscriptions matches the event type.

Filtering is server-side (we don't waste a network round-trip if you're not subscribed).

---

## Idempotency

You WILL get duplicate deliveries occasionally. Dedupe on the `X-Webhook-Delivery` header — every retry and operator replay of one logical delivery repeats the same value.

Simple PHP example:

```php
$deliveryId = $_SERVER['HTTP_X_WEBHOOK_DELIVERY'] ?? null;
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
| `subscription_id` | FK to `webhook_subscriptions` (the live column — see note below) |
| `event_type` | The event name |
| `delivery_uid` | UUID shared across retries and operator replays; sent as `X-Webhook-Delivery` |
| `attempt` | 1, 2, 3, … up to the subscription's `retry_policy_json.max_attempts` (default 5) |
| `payload` | The JSON we sent |
| `http_status` | HTTP code we got back |
| `response_body` | The response body |
| `duration_ms` | How long the delivery attempt took |
| `error` | Free-text reason on failure |
| `dead_lettered_at` | When this delivery was marked `dead_letter` (NULL until then) |
| `replayed_from_id` | Set when this row is an operator-triggered replay of an earlier delivery |
| `created_at` | When THIS attempt was made |
| `status` | `pending`, `success`, `failed`, `retried`, `dead_letter` |

> **Note:** `webhook_deliveries` also has a legacy `webhook_id` column, kept
> NULLable for backward compatibility with the retired bare `webhooks`
> table — it is never populated by current code. Always join on
> `subscription_id`.

View in Settings → Integrations → Webhooks → row → Delivery Log. Useful queries:

```sql
-- Failure rate per subscription in last 24 h
SELECT s.name,
       SUM(CASE WHEN d.status='success' THEN 1 ELSE 0 END) AS ok,
       SUM(CASE WHEN d.status IN ('failed','dead_letter') THEN 1 ELSE 0 END) AS bad
  FROM webhook_subscriptions s
  JOIN webhook_deliveries d ON d.subscription_id = s.id
 WHERE d.created_at > NOW() - INTERVAL 1 DAY
 GROUP BY s.id;

-- Dead-lettered deliveries needing manual re-fire
SELECT * FROM webhook_deliveries
 WHERE status = 'dead_letter'
 ORDER BY created_at DESC LIMIT 50;
```

The delivery log itself is retained according to `webhook_delivery_log_retention_days` (default 30; raise for compliance).

---

## Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Test ping returns `succeeded` but real events don't arrive | Event subscription doesn't include the event type | Settings → Webhooks / Events → row → Edit → add the event to Subscribed Events |
| Delivery log shows `failed: ssl certificate problem` | Receiver TLS cert is self-signed or expired | Fix the cert; don't disable TLS verification on the TicketsCAD side |
| Delivery log shows `failed: timeout` | Receiver is too slow | Have receiver return 200 immediately, process async |
| Delivery log shows `failed: 401 bad sig` | HMAC computed wrong (very common!) | Verify you're signing `<ts>.<raw_body>`, not just the body. Use constant-time compare. |
| Delivery log shows `failed: 410 gone` | Webhook auto-disabled | Receiver returned 410; admin must re-enable |
| Every delivery is duplicated | Receiver isn't returning 2xx in time, so TicketsCAD retries | Speed up receiver OR dedupe on `X-Webhook-Delivery` |
| Receiver rejects every delivery as `bad sig` | Reading `X-Webhook-Signature` (legacy, body-only) against a `<ts>.<body>` computation, or leaving the `sha256=` prefix on the value | Read `X-Webhook-Signature-V2` and strip `sha256=` before comparing |
| Receiver rejects every delivery as `stale` | Checking freshness against the body's `timestamp` (generation time, unchanged on retries) instead of the `X-Webhook-Timestamp` header | Use the header; also check clock sync on both ends |

See also [TROUBLESHOOTING.md § webhook-failed](TROUBLESHOOTING.md#webhook-failed).

---

## Security checklist

For each webhook receiver:

- [ ] Use a unique secret per webhook (don't share across services)
- [ ] Verify the HMAC signature on every request (never trust unsigned traffic)
- [ ] Reject deliveries with stale timestamps (≥ 5 min)
- [ ] Use constant-time comparison for the signature
- [ ] Treat `X-Webhook-Delivery` as the dedup key
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
| Schema | `webhook_subscriptions` (`sql/run_phase94_external_api.php`), `webhook_deliveries` (`sql/run_webhooks.php`) |
| Tests | tests/test_webhook_delivery.php, tests/test_webhook_replay_protection.php |

---

This guide is maintained alongside the code. If the wire format changes, this doc is wrong — file a patch.
