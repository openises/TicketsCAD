# Routing Engine — Reference

**Audience:** admins configuring message routing rules, developers extending the engine.
**Implementation:** [`inc/router.php`](../inc/router.php).
**UI:** Settings → Communications → Message Routing.

The routing engine forwards messages between [channels](GLOSSARY.md#channel) using user-configured rules. Voice transcripts from DMR, mesh chat, SMS, Slack, internal chat, and HTTP webhooks all flow through the same [broker](GLOSSARY.md#broker), so a single routing rule can bridge any combination.

---

## Mental model

```
                ┌────────────────────────────────────────────┐
                │       broker_send(channel, message)         │
                │       broker_receive(channel, message)      │
                └─────────────────┬──────────────────────────┘
                                  │
                                  ▼
                ┌──────────────────────────────────┐
                │   router_evaluate(channel,        │
                │       direction, message)         │
                │                                   │
                │   1. Discard untrusted metadata   │
                │   2. Load enabled routes for ch.  │
                │   3. For each route:              │
                │        - Match filters            │
                │        - If matched: forward      │
                │   4. Log every decision           │
                └──────────────────┬───────────────┘
                                   │
                       (each matching route)
                                   ▼
                ┌──────────────────────────────────┐
                │   router_forward(route, message)  │
                │   - Apply transform              │
                │   - Set _is_routed_forward = 1   │
                │   - broker_send(dest, forwarded) │
                └──────────────────────────────────┘
```

**Key idea:** "all matches fire". A single inbound message can trigger zero, one, or many routes — they don't compete; they're independent rules. If you want exclusive routing, use the priority field to order them and make filters mutually-exclusive.

---

## Route schema

Each row in the `routes` table:

| Column | Type | Purpose |
|---|---|---|
| `id` | int | PK |
| `enabled` | tinyint | 0 = rule is ignored |
| `priority` | int | Lower = evaluated first (within all-matches-fire semantics, this just controls log order) |
| `name` | varchar(120) | Human-readable label for the admin UI |
| `source_channel` | varchar(32) | The channel the inbound message arrived on (`meshtastic`, `dmr`, `local_chat`, `slack`, `sms`, etc.). `*` matches any channel. |
| `direction` | enum | `inbound`, `outbound`, or `both` |
| `dest_channel` | varchar(32) | The channel the forwarded copy gets sent to |
| `routing_kind` | enum | `broadcast` (most messaging) or `direct` (one-to-one) |
| `filters_json` | JSON | See [Filters](#filters) below |
| `transform_json` | JSON | See [Transforms](#transforms) below |
| `chat_channel` | varchar(64) | Optional override of `message.channel` on the forwarded copy |
| `created_at` / `updated_at` | datetime | Audit |
| `created_by` | int | User id of the admin who created it |

### Example row

```sql
INSERT INTO routes
  (enabled, priority, name, source_channel, direction,
   dest_channel, routing_kind, filters_json, transform_json)
VALUES
  (1, 100, 'DMR TG9990 → dispatch chat',
   'dmr', 'inbound',
   'local_chat', 'broadcast',
   '{"talkgroup":["9990"],"min_severity":null}',
   '{"prefix":"[DMR] ","channel_override":"dispatch"}');
```

When voice arrives on the DMR channel for TG 9990, this rule synthesises a chat message with body `"[DMR] " + transcript` and posts it to the `dispatch` chat channel.

---

## Filters

`filters_json` is a JSON object. Every present key is ANDed. Empty / missing keys are ignored.

### Supported filter keys

| Key | Type | Matches when |
|---|---|---|
| `incident_type` | string[] | `message.incident_type_id` (or `message.ticket.in_types_id`) is in the list |
| `severity` | int[] | `message.severity` ≤ each value (filter is "max severity"; lower = more urgent in TicketsCAD's convention) |
| `min_severity` | int | `message.severity` ≤ this value |
| `priority` | string[] | `message.priority` in list (`urgent`, `high`, `normal`, `low`) |
| `sender_role` | string[] | `message.sender_role` in list (RBAC role codes) |
| `talkgroup` | string[] | For DMR: `message.talkgroup` in list (as strings) |
| `keywords` | string[] | Any keyword appears in `message.body` (case-insensitive substring) |
| `exclude_keywords` | string[] | If any of these appears in `message.body`, the route is SKIPPED |
| `chat_channel` | string[] | `message.channel` (chat sub-channel) in list |
| `direction` | string | Redundant with the route's `direction` column; if present, also enforced |
| `time_window` | object | `{"start_hour": 6, "end_hour": 22, "weekdays": [1,2,3,4,5]}` — restrict to certain hours/days. Times in install timezone. |

### Filter examples

**Only urgent + high-priority chat:**
```json
{"priority": ["urgent", "high"]}
```

**Only DMR traffic on TG 31xxx that doesn't mention "test":**
```json
{
  "talkgroup": ["31001", "31002", "31003"],
  "exclude_keywords": ["test", "drill"]
}
```

**Only major-incident messages from dispatchers, on weekdays 06:00–22:00:**
```json
{
  "incident_type": ["MAJOR"],
  "sender_role": ["dispatcher", "supervisor"],
  "time_window": {"start_hour": 6, "end_hour": 22, "weekdays": [1,2,3,4,5]}
}
```

---

## Transforms

`transform_json` reshapes the forwarded copy. Like filters, every present key applies; missing keys leave the field unchanged.

| Key | Effect |
|---|---|
| `prefix` | Prepended to `body`. Supports `{source}` substitution → the source-channel code. |
| `suffix` | Appended to `body`. |
| `body_template` | Full replacement using `{field}` substitution: `{body}`, `{source}`, `{talkgroup}`, `{sender}`. |
| `priority_override` | Replace the message's priority. |
| `channel_override` | Replace the chat sub-channel for the forwarded copy. |
| `recipient_override` | Replace the recipient (e.g. force-broadcast). |
| `truncate` | Integer max-length for body. |

### Transform examples

**Prefix DMR traffic with the talkgroup and route to dispatch:**
```json
{
  "prefix": "[DMR TG{talkgroup}] ",
  "channel_override": "dispatch"
}
```

**Cap message length when bridging to SMS (which has a 160-char limit):**
```json
{
  "truncate": 140,
  "suffix": " (…)"
}
```

**Reformat a Slack post into a structured dispatch announcement:**
```json
{
  "body_template": "Dispatch from Slack ({sender}): {body}",
  "priority_override": "high"
}
```

---

## Loop prevention

Routing forwards a message → the forwarded copy travels through the broker → the broker calls `router_evaluate` on it → which could match another route → which forwards again → infinite loop.

Two defences (both hardened in Phase 73u):

### 1. `_is_routed_forward` trust flag

`router_forward()` sets `_is_routed_forward = 1` on every forwarded copy. `router_evaluate()` and `router_forward()` BOTH only honour caller-supplied `_routed` and `_route_depth` when this flag is set.

Without this guard, a caller of `broker_send` could preset `_routed = [all_route_ids]` or `_route_depth = 99` to silently bypass routing. The Phase 73u fix discards untrusted metadata; routing starts fresh on any non-forwarded input.

### 2. `_routed` set + `_route_depth` counter

For trusted forwards:

- `_routed` is the list of route IDs already applied to this message. A route that's in this list is skipped.
- `_route_depth` is the count of forward hops. When it reaches `ROUTER_MAX_DEPTH` (5), no further forwarding fires.

So a chain `chat → DMR → mesh → SMS` (4 hops) works; a longer chain or any loop is cut off.

---

## All-matches-fire semantics

If three rules all match the same inbound message, all three forward independently. They don't compete; they don't share state.

### When this is what you want

```
DMR TG9990 inbound
  ├─ Route A: → local chat (for dispatcher visibility)
  ├─ Route B: → SMS to on-call duty officer
  └─ Route C: → audit-log entry tagged "radio traffic"
```

Three forwards, three independent results.

### When this isn't what you want

If you want exclusive routing — "if it matches Route A, don't also fire B" — use mutually-exclusive filters.

Example: route urgent messages one way, non-urgent another.

```
Route A (priority 10): filters={"priority":["urgent"]}, dest=on-call SMS
Route B (priority 20): filters={"priority":["normal","low"]}, dest=chat
```

Because the priority field is a "high" or a "low" per message (never both), exactly one rule matches.

---

## Channel adapters

The `dest_channel` of a route must correspond to a [broker](GLOSSARY.md#broker) channel adapter. Current adapters:

| Channel code | File | Status |
|---|---|---|
| `local_chat` | [`inc/channels/local_chat.php`](../inc/channels/local_chat.php) | Production |
| `smtp` | [`inc/channels/smtp.php`](../inc/channels/smtp.php) | Production (configure SMTP credentials) |
| `email` | [`inc/channels/email.php`](../inc/channels/email.php) | Alias for `smtp` |
| `sms` | [`inc/channels/sms.php`](../inc/channels/sms.php) | Production (Twilio / BulkVS / Pushbullet) |
| `slack` | [`inc/channels/slack.php`](../inc/channels/slack.php) | Production (Slack incoming webhook) |
| `meshtastic` | [`inc/channels/meshtastic.php`](../inc/channels/meshtastic.php) | Production (mesh bridge VM running) |
| `dmr` | [`inc/channels/dmr.php`](../inc/channels/dmr.php) | Stub — basic shape, real implementation via DVSwitch bridge |
| `zello` | `inc/channel_registry.php` (stub) | Stub — registered, awaiting Zello Work API impl |

Routes to a stub channel will log `failed` in the routing log with reason `not_implemented`. Watch the log when you enable a stub channel.

---

## Dry-run testing

Before enabling a rule, test it against synthesised inputs:

```php
// Settings → Communications → Message Routing → row → Test
// Or via API:
POST /api/routing.php
{
  "action": "router_test",
  "route_id": 42,
  "test_message": {
    "channel": "dmr",
    "direction": "inbound",
    "talkgroup": "9990",
    "body": "all clear at scene",
    "priority": "normal"
  }
}
```

Response:

```json
{
  "matched": true,
  "filter_results": {
    "talkgroup": "matched (9990 in [9990])",
    "exclude_keywords": "matched (no exclusion hit)"
  },
  "would_forward_to": "local_chat",
  "transformed_body": "[DMR] all clear at scene",
  "would_actually_send": false
}
```

The `would_actually_send: false` tells you the dry-run didn't actually call `broker_send` — perfect for verifying a rule without spamming dispatch.

---

## Routing log

Every evaluation writes a row to `routing_log`:

| Column | Purpose |
|---|---|
| `id`, `created_at` | PK + timestamp |
| `route_id` | Which route was evaluated |
| `source_channel` | Where the message arrived |
| `dest_channel` | Where the forwarded copy was sent (NULL on no-match) |
| `source_message_id` | FK to the `messages` table for the source |
| `dest_message_id` | FK for the forwarded copy (NULL on no-match) |
| `status` | `forwarded`, `failed`, `skipped`, `loop_blocked`, `not_implemented` |
| `error` | Free-text reason on failure |
| `summary` | Short human-readable description |

View it in **Settings → Communications → Message Routing → Activity Log**.

Useful queries:

```sql
-- Why did this message NOT get forwarded?
SELECT * FROM routing_log
 WHERE source_message_id = 12345
 ORDER BY created_at;

-- Top 10 failure modes in the last week
SELECT error, COUNT(*) c
  FROM routing_log
 WHERE status = 'failed'
   AND created_at > NOW() - INTERVAL 7 DAY
 GROUP BY error
 ORDER BY c DESC LIMIT 10;

-- Routes that have never matched anything
SELECT r.id, r.name
  FROM routes r
  LEFT JOIN routing_log l ON l.route_id = r.id
 WHERE l.id IS NULL
   AND r.enabled = 1;
```

---

## Common patterns

### Pattern: radio chatter → chat for dispatcher visibility

```
DMR (inbound) → local_chat (channel=dispatch)
Mesh (inbound) → local_chat (channel=dispatch)
```

Transforms add a `[DMR TGxxx]` or `[Mesh @nodename]` prefix so dispatchers can tell sources apart.

### Pattern: urgent messages → SMS to on-call

```
* (inbound) → sms (recipient = on_call_number)
filters: {"priority":["urgent"]}
```

The `*` source matches any channel. Combine with `exclude_keywords:["test","drill"]` so test traffic doesn't page anyone.

### Pattern: cross-bridge translation (mesh ↔ DMR)

```
Mesh (inbound) → DMR (talkgroup=9990, broadcast)
DMR (inbound, talkgroup=9990) → Mesh (broadcast)
```

The loop-prevention `_routed` set keeps these from chaining infinitely.

### Pattern: routing-engine "off" switch

Disable all routes via:

```bash
sudo mariadb newui -e "UPDATE routes SET enabled = 0;"
```

Or per-route via the admin UI's toggle. The routing engine continues to log decisions but takes no forwarding action.

---

## Performance notes

- Each `router_evaluate` is one cached query against `routes` for that source channel; the cache is per-request, so a high-throughput inbound (thousands of msgs/sec) won't hit the DB once-per-message.
- The actual `broker_send` to the destination channel is what costs time. Slack and SMS adapters block on the external API; for high-volume bridging, configure adapter timeouts (`broker.timeout_ms` setting) so a slow adapter doesn't stall the source channel.
- For very high throughput, consider moving forwarding to a queue (planned for a future phase; not yet implemented).

---

## When to write custom channel adapters

The broker channel registry ([`inc/broker.php`](../inc/broker.php)) accepts any PHP file in `inc/channels/` that calls:

```php
broker_register('my_channel', [
    'name'    => 'My channel',
    'send'    => '_my_channel_send',     // PHP function
    'receive' => '_my_channel_receive',  // PHP function (optional)
    'status'  => '_my_channel_status',   // PHP function (optional)
]);
```

`_my_channel_send($message)` must return `['success' => bool, 'error' => ?string]`. The broker calls it; you handle the actual outbound delivery (HTTP POST, MQTT publish, hardware write, etc.).

Once registered, routes can target `my_channel` as their `dest_channel` and the engine doesn't know or care about the implementation.

For inbound traffic, your channel adapter should call `broker_receive('my_channel', $message)` when a message arrives. The broker will then call `router_evaluate` to fan out routes.

---

## Security considerations

1. **`_is_routed_forward` enforcement** (Phase 73u) — without this, any caller that can reach `broker_send` could bypass routing entirely. Don't disable.
2. **Channel access control** — there's currently no per-channel ACL beyond the global `action.send_chat` / `action.manage_routing` permissions. If you need "Dispatcher A can send to channel X but not channel Y", file an issue; this is queued for a future phase.
3. **Audit trail** — every forwarded message hits `routing_log`. Don't disable; it's the only way to investigate "why did this go where it went".
4. **Loop prevention** — `ROUTER_MAX_DEPTH = 5` is hard-coded. Don't change without understanding the consequences (mesh networks with cross-bridges can legitimately want 6+ hops).

---

## Where the code lives

| What | Path |
|---|---|
| Routing engine | [`inc/router.php`](../inc/router.php) |
| Broker | [`inc/broker.php`](../inc/broker.php) |
| Channel adapters | [`inc/channels/*.php`](../inc/channels/) |
| Admin API | [`api/routing.php`](../api/routing.php) |
| Admin UI | Settings → Communications → Message Routing in [`settings.php`](../settings.php) |
| Schema migration | [`sql/run_routing.php`](../sql/run_routing.php) |
| Tests | [`tests/test_routing.php`](../tests/test_routing.php) (41 tests) |

---

This reference is maintained alongside the code. The 41 routing tests in `tests/test_routing.php` are the executable spec; if the engine ever does something other than what's documented here, that's a bug.
