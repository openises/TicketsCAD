# Message Routing Engine — Configuration Guide

**Version:** Phase 99v, ships in NewUI v4.0.0-dev
**Audience:** TicketsCAD administrators who configure how alerts, notifications, and cross-channel messages flow between users and external systems
**Companion video:** *(10-min walkthrough — to be produced by the training agent from this document)*

---

## 1. What the routing engine is

TicketsCAD generates a lot of events. New incidents, status changes, unit assignments, mayday calls, mileage updates, geofence enters/exits, mutual-aid requests — all of these can fan out to your team through whatever channels make sense for your operation. The **message routing engine** is the configurable rules engine that decides:

1. **Which events become outbound traffic** — every event, or only certain types
2. **Where they go** — push notifications, Meshtastic, DMR, Zello, SMS, email, Slack, local chat
3. **Who receives them** — everyone on the destination channel, OR only specific users defined by a predicate (e.g. "units currently assigned to the incident")

Without the routing engine, you'd be stuck with either an all-or-nothing firehose (every user gets every event) or hard-coded delivery rules baked into the application. Routing rules let each install customize the flow for the operation they actually run.

## 2. The two axes — channels and recipients

A route has two dimensions you configure:

### Channel axis — *where* the message goes

A channel is a transport: a way messages get delivered. TicketsCAD has 10+ registered channels:

| Channel | Direction | Notes |
|---|---|---|
| `local_chat` | both | In-app chat broker, always on |
| `push` | outbound | Web push notifications to browser/mobile (per-user) |
| `slack` | outbound | Slack workspace via incoming webhook or bot token |
| `sms` | outbound | Generic REST / Twilio / BulkVS / Pushbullet |
| `smtp` | outbound | Email via SMTP |
| `email` | outbound | Alias for smtp |
| `meshtastic` | both | LoRa mesh (Meshtastic-Android compatible) |
| `meshcore` | both | LoRa mesh (MeshCore) |
| `dmr` | both | DMR amateur radio via BrandMeister or DVSwitch |
| `aprs` | both | APRS network (text + objects) |
| `audit_event` | virtual | Not a real transport — a meta-channel that routes consume for audit-driven events |

### Recipient axis — *who* gets the message

By default, a route's destination channel broadcasts: whoever's on the Meshtastic mesh hears the Meshtastic message; whoever's in the Slack channel sees the Slack message. This is the legacy behaviour and works for channels that have their own audience.

**Phase 99v added recipient predicates.** A route can specify a JSON predicate that resolves to a set of TicketsCAD user IDs at fire-time. The router then delivers to each of those users via the destination channel, instead of broadcasting. This is essential for the push channel (which is inherently per-user) and useful for any channel where you want narrow targeting.

## 3. How the engine works end-to-end

Walk through a single event:

```
1. Something happens
   (a dispatcher creates an incident, a unit changes status, a
   geofence is breached, an audit_log row is written...)

2. The app fires a message
   broker_send('local_chat', $message)  or  audit_log(...)  etc.

3. router_evaluate(channel, direction, message) is called
   This pulls every enabled message_routes row whose source_channel
   matches and whose direction allows it.

4. For each matching route, the router checks filters_json
   (incident type, severity, keywords, etc.). Filters narrow which
   events the route handles.

5. If the route has recipient_predicate_json, the resolver runs it:
   "users whose responder is assigned to this incident" →
   [29, 47, 53]. If the predicate matches zero users, the route is
   logged as 'skipped' and no traffic goes out.

6. The route is forwarded to the destination channel's send handler.
   For 'push', _push_channel_send queries push_subscriptions for
   each resolved user, builds a notification, and queues it through
   the WebPush library. For 'slack', _slack_send posts to the
   webhook. Each channel knows how to deliver its own traffic.

7. The forwarding is logged in routing_log with status
   (forwarded / skipped / failed / loop_blocked) and the result.

8. SSE publishes a routing:forwarded event so admin sessions
   watching the Settings → Message Routing → Activity tab see it
   in real time.
```

Loop prevention is built in: each forward marks the message with the route id so the same rule can't fire twice on the same message, and a depth counter (max 5) prevents runaway chains across rules that source from each other's destination channels.

## 4. The six recipient predicates

These are the predicates available in Phase 99v-1. Each resolves a routed message + the live database to a set of user IDs.

### 4.1 `assigned_to_incident`

Users whose responder has an active (un-cleared) assignment for a given incident.

```json
{
  "predicate": "assigned_to_incident",
  "params": { "ticket_id": "$payload.ticket_id" }
}
```

**Use case:** "When the incident is updated, push to the units currently working it." This is the default seed-route behaviour and matches what the Phase 99t band-aid filter was trying to do.

The `$payload.ticket_id` reference reads the ticket ID from the routed message at fire-time. You can also hard-code a ticket id (rare; useful for one-off routes during exercises).

### 4.2 `responder_status_in`

Users whose responder is currently in any of the named statuses.

```json
{
  "predicate": "responder_status_in",
  "params": { "status_names": ["Available", "On Scene"] }
}
```

**Use case:** "When a major incident is declared, notify everyone who's currently Available so they can self-assign." Or: "When PAR is initiated, alert everyone On Scene."

Status names match against `un_status.status_val` case-insensitively. Be careful with locally-edited status names — if you renamed "On Scene" to "Arrived" the predicate needs the new name.

### 4.3 `member_of_team`

Users whose member record is in `team_members` for any of the listed team IDs.

```json
{
  "predicate": "member_of_team",
  "params": { "team_ids": [3, 7] }
}
```

**Use case:** "Severe weather → alert the Skywarn team." Team IDs come from the Teams page. A user on multiple of the listed teams is only counted once (DISTINCT).

### 4.4 `user_id_in`

A literal list of user IDs. Useful for direct targeting or for testing rules with known users.

```json
{
  "predicate": "user_id_in",
  "params": { "user_ids": [29, 30] }
}
```

**Use case:** "Critical security alerts → just the two CIOs." Most installs won't use this in production, but it's the predicate you reach for when authoring a rule and want to verify "yes, this delivers to who I expect."

### 4.5 `org_member`

Users whose home organization OR any role-scoped org assignment matches one of the listed organizations.

```json
{
  "predicate": "org_member",
  "params": { "org_ids": [1, 2] }
}
```

**Use case:** Multi-org installations (county-wide deployment with sub-org chapters). "When the county EOC posts a mutual-aid request, alert org 1 (Bemidji), org 2 (Hubbard), and org 7 (Cass)." Org IDs come from the Organizations admin page.

### 4.6 `rbac_can`

Users whose roles grant a named permission code (e.g. `action.view_major`).

```json
{
  "predicate": "rbac_can",
  "params": { "permission_code": "screen.situation" }
}
```

**Use case:** This is the predicate to reach for when the audience is defined by **role**, not by **assignment or team**. "Send to anyone who has dispatcher-level access." "Alert anyone who can view major incidents." It composes well with `any_of` for "any of these permissions."

Permission codes come from the Permissions Matrix at `/roles-matrix.php`. Common ones for routing:

| Permission | Audience |
|---|---|
| `screen.situation` | Dispatchers + admins with the full-screen situation view |
| `screen.dashboard` | Anyone who uses the main dashboard |
| `screen.callboard` | Dispatchers using the call board |
| `widget.incidents` | Users with the dispatch incidents widget |
| `action.view_major` | Users authorized to see major incidents |
| `action.manage_routing` | Routing administrators |

## 5. Composition — any_of, all_of, none_of

Predicates can be nested into trees to express joint conditions.

### `any_of` — union

The user matches if ANY of the conditions match.

```json
{
  "type": "any_of",
  "conditions": [
    { "predicate": "assigned_to_incident",
      "params": {"ticket_id": "$payload.ticket_id"} },
    { "predicate": "responder_status_in",
      "params": {"status_names": ["Available"]} }
  ]
}
```

"Users assigned to this incident **or** currently Available."

### `all_of` — intersection

The user matches only if ALL conditions match.

```json
{
  "type": "all_of",
  "conditions": [
    { "predicate": "member_of_team", "params": {"team_ids": [3]} },
    { "predicate": "responder_status_in",
      "params": {"status_names": ["Available"]} }
  ]
}
```

"Skywarn team members who are also currently Available."

### `none_of` — complement (used inside `all_of`)

The user matches if NONE of the listed conditions match.

```json
{
  "type": "all_of",
  "conditions": [
    { "predicate": "rbac_can",
      "params": {"permission_code": "screen.dashboard"} },
    { "type": "none_of", "conditions": [
        { "predicate": "user_id_in", "params": {"user_ids": [29]} }
    ]}
  ]
}
```

"Everyone with dashboard access EXCEPT user 29."

`none_of` is only meaningful inside an `all_of` — by itself it returns the complement against ALL users on the install, which is rarely what you want.

## 6. Configuring routes today

Until Phase 99v-4 ships the visual builder, routes are configured in **Settings → Message Routing**:

1. Click **+ New Route**
2. Fill in **Name** (descriptive — appears in the routing log and audit trail)
3. Set **Source channel** — what kind of event the route consumes. For audit-driven events (incident created/updated, unit status changes), use `audit_event`.
4. Set **Destination channel** — where to deliver.
5. Set **Direction** — `outbound` for events leaving the system, `inbound` for events arriving, `both` for symmetric forwarding.
6. **Filters** (optional) — narrow by incident type, severity, keywords. JSON shape: `{"incident_type":[37,40], "severity_min":3}`.
7. **Recipient predicate** (optional) — JSON predicate from §4-5. If blank, the channel broadcasts.
8. **Transform** (optional) — modify the message body before delivery (prefix, priority override).
9. **Priority** — lower number fires first when multiple routes match. Doesn't change WHO gets it; just the order.
10. **Enabled** — toggle on. Use the Test button to dry-run a route against a sample message and see who it would deliver to.

## 7. Common configurations — ready-to-paste

These are starter templates. Adjust the IDs and codes for your install.

### 7.1 Push to assigned units (ships as a default seed route)

```json
{
  "predicate": "assigned_to_incident",
  "params": { "ticket_id": "$payload.ticket_id" }
}
```

A field responder gets a push notification only when an event affects an incident they're currently assigned to. Field responders without an assignment stay quiet.

### 7.2 Push to all dispatchers / situational-awareness users

```json
{
  "type": "any_of",
  "conditions": [
    { "predicate": "rbac_can", "params": { "permission_code": "screen.situation" } },
    { "predicate": "rbac_can", "params": { "permission_code": "widget.incidents" } }
  ]
}
```

Anyone who has access to the situation screen OR the incidents widget gets every event. Operationally, this is your "dispatch firehose" route — the people running incidents from the dashboard get the full feed regardless of which specific units are involved.

### 7.3 Major incident → Slack + push to everyone authorized

Route 1: `dest_channel = "slack"`, no predicate (broadcasts to the configured Slack channel).
Route 2: `dest_channel = "push"`, predicate:

```json
{ "predicate": "rbac_can", "params": { "permission_code": "action.view_major" } }
```

When a major incident is declared, the Slack channel gets a notification AND every user with major-incident view rights gets a push.

### 7.4 Mayday → push to anyone on-scene at the same incident

```json
{
  "type": "all_of",
  "conditions": [
    { "predicate": "assigned_to_incident",
      "params": { "ticket_id": "$payload.ticket_id" } },
    { "predicate": "responder_status_in",
      "params": { "status_names": ["On Scene"] } }
  ]
}
```

A Mayday call alerts only the people physically on the same scene — not the dispatcher, not the off-scene units. Useful for RIT activation.

### 7.5 Severe weather → Skywarn team + anyone with weather-related role

```json
{
  "type": "any_of",
  "conditions": [
    { "predicate": "member_of_team", "params": { "team_ids": [3] } },
    { "predicate": "rbac_can",
      "params": { "permission_code": "action.weather_response" } }
  ]
}
```

(Adjust the team_ids and permission_code to your install.)

### 7.6 Mutual-aid → notify a different org's dispatchers

```json
{
  "type": "all_of",
  "conditions": [
    { "predicate": "org_member", "params": { "org_ids": [2] } },
    { "predicate": "rbac_can",
      "params": { "permission_code": "screen.situation" } }
  ]
}
```

When your install fires an outbound mutual-aid event, only dispatchers in the receiving org (org_id=2) get the push.

### 7.7 Maintenance window → silence all routes for a user

```json
{
  "type": "all_of",
  "conditions": [
    { "predicate": "rbac_can",
      "params": { "permission_code": "screen.dashboard" } },
    { "type": "none_of", "conditions": [
        { "predicate": "user_id_in",
          "params": { "user_ids": [29] } }
    ]}
  ]
}
```

User 29 is on vacation and asked not to be pinged. Everyone else with dashboard access still gets the route. Cleaner than disabling their entire push subscription.

## 8. Default seed routes that ship

TicketsCAD ships with these routes pre-installed; they're idempotent — re-running the migration won't insert duplicates, and admins can disable or edit them freely without breaking the migration runner.

| Name | What it does |
|---|---|
| **Phase 99v seed: incident events → push, recipients assigned to incident** | Mobile users assigned to an incident get a push when the incident updates. Replaces the Phase 99t hand-rolled filter. |
| **Phase 99v seed: incident events → push, recipients with dispatch screen access** | Users with `screen.situation` OR `widget.incidents` permission get a push for all incident events. Preserves the desktop dispatcher firehose. |

Together these two routes give you the "field units get only their assignments; dispatchers get everything" behaviour out of the box.

## 9. The push channel is special

Most channels broadcast to whoever's listening — Meshtastic to the mesh, Slack to the configured channel, DMR to the talkgroup. The push channel is per-user: each push goes to one user's browser subscription endpoints. That means **the push channel only delivers when a recipient predicate is set**. A route with `dest_channel = "push"` and no predicate logs a 'failed' status with the message "push channel requires a recipient predicate."

Push is enabled implicitly when VAPID keys are configured in Settings → Notifications. You don't need to add it to the enabled-channels list separately.

Push delivery uses the existing minishlink/web-push library, so endpoint health (404/410 marking, gone-stamping, retry behaviour) is consistent with the rest of the push system. The route just decides WHO gets the push; the channel adapter handles delivery + health tracking.

## 10. Troubleshooting

### "My route never fires"

Check:
1. Is the route **enabled**?
2. Does the **source channel** match what the app is firing? Audit-driven events fire with source `audit_event`, not the channel of the source operation.
3. Do the **filters** (incident type, severity, keywords) actually match the event? Test with a known-matching event.
4. Is there a **higher-priority route** that matches first and stops the chain? Check the routing log for the same message id.

Use the **Test** button on the route to dry-run a sample message — it shows whether the route would fire and which recipients it would resolve to.

### "The predicate resolves to zero users"

Test the predicate against current data:
- `assigned_to_incident` — verify the ticket has actual `assigns` rows with non-null `clear`, and that the responders have linked user_ids.
- `responder_status_in` — status name spelling and case must match `un_status.status_val`.
- `member_of_team` — team IDs must come from your install, not from another install you copied a config from. Check the Teams page.
- `rbac_can` — make sure the permission code actually exists in your install. The Permissions Matrix lists them all.

The routing log captures every "matched zero users" outcome so you can trace these.

### "Users get duplicate pushes"

Most commonly: two routes both fire for the same event with overlapping recipient sets. The browser de-dups by notification tag, so the user sees one notification — but the wire-level fan-out is doubled. Audit your routes for overlap.

The other cause during the Phase 99v rollout: `push_fire()` was running both the legacy direct-broadcast loop AND the routing engine in parallel. After the cleanup commit (which removes the legacy loop), only the routing engine fires push.

### "A user is in the route's recipient set but didn't get a push"

Two layers to check:
1. **The route resolved them as a recipient.** Verify via the route's Test button.
2. **They have an active push subscription.** Check Settings → Notifications → Subscriptions. If their subscription has `last_error LIKE 'gone:%'` or `LIKE 'fail:%'`, the browser revoked or rejected it. They need to re-subscribe.

## 11. Audit trail

Two tables capture what the routing engine does:

- **`routing_log`** — one row per route fire. Columns: route_id, source_channel, dest_channel, source_message_id, dest_message_id, status (`forwarded`/`skipped`/`failed`/`loop_blocked`/`security_blocked`/`queued`), error, summary, timestamp.
- **`audit_log`** (via the `newui_audit_log` table) — one row per route create/edit/delete, plus the per-cell grant changes on roles. Includes the acting user.

The Settings → Message Routing → Activity tab tails `routing_log` so admins can watch live. SSE publishes `routing:forwarded` events to admin sessions.

## 12. What's coming (Phase 99v-4 onwards)

The current configuration UI requires hand-editing the JSON predicate. The next slices ship:

- **99v-4 (next)** — Visual predicate builder in Settings → Message Routing. Dropdown for the 6 predicates, typed parameter inputs, a **Preview** button that shows "this rule would currently reach N users: [list]" before saving. JSON escape hatch stays available for nested compositions.
- **99v-5** — Expanded help docs (this document, embedded in `/help.php`).
- **99v-6** — Visual tree builder (drag-and-drop `any_of`/`all_of` composition cards). Deferred until usage warrants the complexity.

---

## Appendix A — Predicate quick reference

| Predicate | Params shape | Resolves to |
|---|---|---|
| `assigned_to_incident` | `{ticket_id: int or "$payload.ticket_id"}` | Users with active assigns row for ticket |
| `responder_status_in` | `{status_names: [str, ...]}` | Users whose responder is in any named status |
| `member_of_team` | `{team_ids: [int, ...]}` | Users in any listed team |
| `user_id_in` | `{user_ids: [int, ...]}` | Literal list |
| `org_member` | `{org_ids: [int, ...]}` | Users in any listed org |
| `rbac_can` | `{permission_code: str}` | Users whose roles grant the permission |

## Appendix B — Composition quick reference

```json
{
  "type": "any_of" | "all_of" | "none_of",
  "conditions": [ { predicate or nested type }, ... ]
}
```

`any_of` = union, `all_of` = intersection, `none_of` = complement (use inside `all_of`).

## Appendix C — Files & code references

- `inc/router.php` — Main routing engine (`router_evaluate`, `router_forward`)
- `inc/router_recipients.php` — Predicate resolver (`router_recipients_resolve`, the 6 predicates, schema bootstrap)
- `inc/channels/push.php` — Push channel adapter (`_push_channel_send`)
- `inc/push.php` — VAPID + WebPush plumbing (`push_fire`)
- `tests/test_router_recipients.php` — 20-test regression suite for the resolver
- `api/rbac.php?action=permission_audit` — Where permission codes for `rbac_can` come from
- `/roles-matrix.php` — Browse permission codes by category
- `specs/phase-99v-routing-engine-recipients/spec.md` — Original design spec
