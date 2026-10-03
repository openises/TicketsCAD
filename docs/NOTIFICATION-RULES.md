# Notification Rules

**For:** administrators (Super Admin) who set up who gets told about what
**Where:** Settings → Communications & Integrations → **Notification Rules**
**Related:** [Message Routing guide](MESSAGE-ROUTING-GUIDE.md) · [Webhooks integrator guide](WEBHOOKS-INTEGRATOR-GUIDE.md) · [Maintenance runbook](MAINTENANCE-RUNBOOK.md) (the scheduled job that sends the messages)

---

## What a rule is

A rule watches for **one kind of thing** that happens in the CAD and then tells the people you choose, with a message you write.

> *When* a unit is dispatched → *send* an email to the Active911 alert address, saying `{incident_type};{street};{city};{scope}`.

You can email, text, post to a Slack or Telegram channel, send a chat message inside the CAD, or send a browser push. A rule never changes an incident; it only sends messages.

Rules apply to incidents of **every organization** on the installation. (Per-organization rules are not part of this release.)

### Which tool do I use?

| You want to... | Use |
|---|---|
| Tell **people** (an email address, a phone number, a person's account, an email list, or a Slack/Telegram channel) when something happens in the CAD, with a message you write | **Notification Rules** (this page) |
| Copy traffic **between channels and radios** (Meshtastic, Zello, DMR, chat...), or drive the built-in push alerts | [Message Routing](MESSAGE-ROUTING-GUIDE.md) |
| Send CAD events to **other software** (n8n, Zapier, another CAD) as signed JSON | [Webhooks](WEBHOOKS-INTEGRATOR-GUIDE.md) |

Slack and push can be reached by both Notification Rules and Message Routing. Using both for the same thing will notify twice. Radio and mesh destinations (APRS, DMR, Meshtastic, MeshCore) are not offered in a rule; use Message Routing for those.

---

## Who can use it

Only a **Super Admin** can create, change, switch on or off, test or delete rules (permission `action.manage_notification_rules`). The tab does not appear for anyone else, and the server refuses everyone else even if they find the address.

This is deliberate. A rule can mail or text any address with any text and the message arrives as the agency; a Slack or Telegram rule posts to a destination shared by every organization. That is install-wide reach. The permission is "Super Admin only" in the same way as `action.manage_config`: it cannot be granted to Org Admin, Dispatcher or any other role from the Roles & Permissions screen, and a repair script removes it from any role below Super Admin if a database import ever leaves it there.

Every change is written to the audit log (Settings → Audit Log): created, changed (with old and new values), switched on or off, copied, deleted, test-sent, and delivery settings changed.

---

## Before you start

1. **Set up the channel.** The strip at the top of the page shows each channel as *set up* or *not set up*. Click a badge to open that channel's settings: Email Configuration, SMS Configuration, Slack, Telegram, Web Push Notifications, Chat Settings. You can save a rule for a channel that is not set up yet (you are warned); nothing is delivered until it is.
2. **Make sure the scheduler is running.** Messages are put in a queue and sent by a one-minute scheduled job (`tools/pending_messages_tick.php`, see the [Maintenance runbook](MAINTENANCE-RUNBOOK.md)). The strip shows *Scheduler running* or an amber *No scheduler heartbeat* banner. Without the job, the CAD still tries each message once, briefly, when a dispatcher acts, but a delivery that fails is not retried until the next dispatch action. Turn the scheduled job on.
3. **Check that people have addresses.** An email rule needs an email address on the user account (or on the roster member linked to it); an SMS rule needs a mobile number (see below).

---

## Quick start

1. Open **Notification Rules** and choose **New rule**, or **From template**.
2. **When:** pick the event. Optionally narrow it to one severity level or one incident type.
3. **Who and where:** pick the channel, then add the people. The page offers only what the channel can use.
4. **Message:** leave the subject and message blank to use the default for the event, or write your own. Click a field button to insert `{street}` where you are typing.
5. **Review:** the right-hand side shows the message exactly as it would be sent and every delivery it would make, including the ones it would *skip* and why. It uses the same code a real event uses.
6. **Send a test to me**, then **Save**.

---

## Events

| Event | Fires when | Notes |
|---|---|---|
| **New incident** | An incident is created: New Incident form, the external API, or a message turned into an incident | |
| **Unit dispatched** | A unit is assigned to an incident: from the incident page, from the New Incident form, or the external API | Units ticked on the New Incident form produce **one** message naming all of them, not one each. Supports "only the first time for each incident". |
| **Unit cleared** | A unit is cleared from an incident or removed from it | Closing the whole incident is reported by *Incident closed*, not once per unit. |
| **Incident closed** | An incident is closed: by a dispatcher, the external API, or the automatic close | |
| **Incident reopened or rescheduled** | An incident is reopened, or moved to Scheduled | Closing is a separate event. Supports "only the first time". |
| **High-alert incident** | An incident is created at, or raised to, a severity level that Settings → Severity Levels marks as High alert | Fires on the *escalation*, not on every later save at the same level. |
| **HAS broadcast sent** | A dispatcher sends a HAS (all-users) broadcast | Not about an incident, so the severity and type filters do not apply. |

Each event fires **once**, from the place the change is actually made, so it does not matter which screen, API call or automation caused it.

**Filters.** *Severity* means exactly that level (not "that level and above"). *Incident type* means exactly that type. An event with no filter matches everything. A filter that cannot be evaluated never matches, so a rule is never silently broadened.

**Only the first time for each incident** sends one notification for the first matching event on an incident. A delivery that never arrived (it expired or failed) does not count as having told anyone, so the next event may try again.

---

## The message

Subject (up to 255 characters, one line) and message (up to 4000 characters). Fields are written in braces:

| Field | Meaning |
|---|---|
| `{ticket_id}` | Internal incident id |
| `{incident_number}` | Incident case number |
| `{incident_type}` | Incident type name |
| `{scope}` | Incident title |
| `{description}` | Incident description |
| `{severity}` / `{severity_label}` | Severity number / name |
| `{street}` `{city}` `{state}` `{address}` | Location (`{address}` is street and city together) |
| `{lat}` `{lng}` | Map position, if the incident has one |
| `{old_status}` `{new_status}` | For status events |
| `{responder}` / `{units}` / `{unit_count}` | The unit(s) named by the event; `{units}` is a comma-separated list |
| `{message_subject}` `{message}` | The HAS broadcast text |
| `{event}` `{user}` `{time}` `{date}` `{datetime}` | The event name, who caused it, and when |

An unknown field is left as written and the page warns you.

**`{street|clean}`** removes semicolons and line breaks from the value and collapses spaces. Use it when the receiver splits on semicolons or expects one line (Active911 StandardA below).

**Email format** (Delivery settings): *plain text* (default) sends the message as typed. *HTML* escapes every value; the CAD never trusts incident data as markup.

---

## Who receives it

| Channel | How a person is addressed |
|---|---|
| **Email** (`email`, `smtp`) | The person's account email, or the linked roster member's email; typed addresses; an **email list** |
| **SMS** | The account's mobile (`phone_m`), then its other phone (`phone_p`), then the linked roster member's cell number; typed numbers. Numbers are normalised to digits with an optional `+` (7 to 15 digits). |
| **Chat** (`local_chat`), **Push** | The person's account |
| **Slack**, **Telegram** | One post to the one destination set up in Settings. No recipients. |

A rule can have up to 100 recipients; use an email list for larger groups. Recipients that do not fit the channel (a phone number on an email rule) are refused when you save.

If a person has no address for the channel, the rule still saves, the page shows a warning icon next to them, the Preview lists them as *skipped*, and the delivery log records the same reason when a real event happens. Nothing disappears silently.

**Email lists** (Settings → Communications & Integrations → Email Lists) are expanded each time the rule fires. Members who are Suspended or Retired are skipped, and every address is checked. A list that cannot be resolved, or resolves to nobody, is reported as a skipped delivery with the reason; it is never reported as success.

### Personal preferences and quiet hours

Delivery settings → *Personal notification preferences*:

* **Send unless the person has opted out** (default). A person with no saved preferences is always sent to. A saved preference (a channel switched off, quiet hours) is honoured.
* **Built-in defaults until the person chooses**: email and chat on, SMS off, until the person has saved preferences.

A **high-alert** incident always gets through: the incident's severity is marked High alert in Settings → Severity Levels, or the event is *High-alert incident*. Opt-outs and quiet hours never silence it.

*This release has no personal preferences screen.* Preferences can only exist if they were created another way (for example by a database import from an earlier install); with no saved preferences, the default setting above sends to everyone. Choose the second setting only if you understand that it stops all SMS to people who have not got a saved preference.

### Security labels

A rule respects the incident's security label (Settings → Communications & Integrations → Security Labels), the same gate Message Routing applies:

* Sending to a **list** or to a **Slack/Telegram** channel counts as a *broadcast*; sending to a **named person** counts as a *direct* message.
* If the label forbids that kind, the delivery is **skipped**, and the delivery log says which label did it.
* If the label has a **send delay**, the delivery is held until the delay has passed. Settings → Communications & Integrations → Pending Messages can recall (kill) it during the delay; the log then reads *skipped*.

---

## Delivery: the queue, retries and the log

A dispatcher's action never waits for the internet. When an event matches a rule, the CAD:

1. writes one **delivery log** row per recipient (status *Waiting*),
2. puts one entry per delivery in the queue,
3. returns to the dispatcher at once.

The scheduled job sends the queue every minute. When the scheduler is not running, one short bounded attempt (3 seconds) follows the queueing instead.

| Outcome | What the log shows |
|---|---|
| Delivered | **Sent** |
| A temporary failure (network down, relay not answering, provider 5xx) | **Waiting**, with "retrying: ..." and the reason; retried each minute |
| Still not sent after `sched_stale_cutoff_min` (default 60 minutes) | **Expired**: a callout that arrives two hours late is worse than none |
| A configuration failure (channel not set up, address refused, provider 4xx) | **Failed** at once, with the reason; retrying cannot fix it |
| Not attempted by design (opted out, quiet hours, no address, security label) | **Skipped**, with the reason |
| Recalled during a security-label delay | **Cancelled** |

**Circuit breaker.** After two failures in a row on one channel, that channel is paused for 60 seconds and its other waiting messages are not tried (a 100-address list on a dead mail relay costs two timeouts, not a hundred). One dead channel never pauses another. The page shows a red banner while a channel is paused, and the same retry logic resumes by itself.

**Delivery log** (button on the page, or the log icon on a rule's row): filter by rule, status and date; open a row to read the exact subject and message that was sent. A row's status is the *effective* one: if the queue entry was cancelled or expired, the row says so.

**Retention.** Delivery settings → *Keep the delivery log (days)*: the scheduled job deletes older rows (default 180; `0` keeps everything). A delivery still waiting is never deleted.

---

## Testing safely

* **Send a test to me** sends one message, marked `[TEST]`, to **your own** address for the rule's channel. It is the safe default.
* **Send a test to the real recipients** asks first, and lists every destination it will reach. **A pager or an Active911 alert address pages real devices.** Slack and Telegram tests always ask, because they cannot be redirected to you.
* The row's **Test** button sends one test to you for a saved rule.
* Tests are limited to five per five minutes per administrator, are recorded in the audit log, and appear in the delivery log as *(test send)*. A test is never counted as the rule having fired.

**Preview** (always on in the rule form) sends and writes nothing.

---

## Recipes

### Active911

Active911 can read an alert from an email sent to your agency's Active911 alert address. It publishes two formats it can parse from a sender that has no parser of its own: **StandardA** and **Cadpage**. Both templates are in **From template**; add your Active911 alert address as the recipient, and set Email format to *plain text* (the default).

**StandardA** (one line: `NATURE;ADDRESS;CITY;DETAILS`):

* Event: *Unit dispatched*, channel *Email*, **Only the first time for each incident** on
* Message: `{incident_type|clean};{street|clean};{city|clean};{scope|clean}`

**Cadpage** (`NAME: VALUE` lines):

```
CALL: {incident_type}
ADDR: {street}
CITY: {city}
INFO: {scope}
ID: {incident_number}
PRI: {severity_label}
DATE: {date}
TIME: {time}
UNIT: {units}
```

> **Best effort.** Active911's public documentation does not say whether it reads the subject or the body for StandardA, and it does not auto-detect the format. The templates put the line in the body. Confirm with Active911 support which format your agency uses and with a real alert before you rely on it. Active911 recognises the sender address: set it up with them first. The first-unit-only switch matters here: without it each unit dispatched pages again.

### Email supervisors on a high-alert incident

Event *High-alert incident*, channel *Email*, recipients the supervisors. Quiet hours and opt-outs never silence it.

### Post every new incident to Slack or Telegram

Event *New incident*, channel *Slack* (or *Telegram*), no recipients. One line per incident goes to the channel set up in Settings → Communications & Integrations → Slack.

### Email an agency list when a shelter incident opens

Event *New incident*, incident type *Shelter*, channel *Email*, and **Send to an email list**: pick the list.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| The **Notification Rules** tab is missing | Only a Super Admin has it. Ask one. |
| A rule fires but nothing arrives | Open the **Delivery log**. *Skipped* names the reason (no address, opted out, security label). *Waiting* with "retrying" means a delivery problem: check the channel's own settings and the red banner. *Failed* shows the provider's answer. |
| Everything stays *Waiting* | The scheduled job is not running (amber banner). Start it; see the [Maintenance runbook](MAINTENANCE-RUNBOOK.md). |
| A channel shows "paused after N failures" | The provider or relay is down or refusing. Fix it; the next minute's sweep tests it and resumes by itself. |
| A **text** goes to nobody | The person has no mobile number on the account, or on their linked roster member; or *Built-in defaults* is selected and they have no saved preference. The Preview lists them as skipped. |
| An **email list** rule sends to nobody | The delivery log says why: list empty or archived, members Suspended or Retired, addresses invalid. |
| The same alert arrives twice | The same thing is set up in both Notification Rules and Message Routing (Slack or push). |
| Active911 pages once per unit | Switch on **Only the first time for each incident**. |
| `{incident_type}` or another field is blank | Check the event: `{old_status}` only has a value on status events, `{message}` only on a broadcast. |
| Nothing fires for units ticked on the New Incident form | They fire as **one** *Unit dispatched* message after the incident is created. |
| A **test** was refused | Five per five minutes; a real-recipient test needs confirmation; a channel with no address for you has nothing to send to. |

---

## Limits

200 rules, 100 recipients per rule, 255-character subject, 4000-character message, 25 destinations per test send. The delivery log keeps 180 days by default.

## For developers

* The events are defined once, in `inc/notification-events.php`. Writers call `notification_hook($event, $context)` (`inc/notification-hook.php`: one indexed query, loads the engine only when an active rule exists). The engine is `inc/notification-engine.php`; `notification_plan_rule()` is side-effect-free and is what Preview and the real send both use.
* Deliveries: `inc/notification-delivery.php` (log row + `pending_routed_messages` row on the `_notify_rule` pseudo-channel; `pending_sweep()` replays them; per-channel persisted breaker in the `notify_rule_breaker` setting).
* Administration: `api/notification-rules.php` and `inc/notification-rules-admin.php`; the page is `inc/notification-rules-panel.php` and `assets/js/notification-rules.js`.
* Settings (all read **uncached**, each with a writer and a test): `notification_email_format`, `notification_prefs_mode`, `notification_log_retention_days`. Advanced, shared with webhooks and push: `notify_inline_budget_s` (default 3), `notify_breaker_threshold` (2), `notify_breaker_cooloff_s` (60), `sched_stale_cutoff_min` (60).
* Schema: `sql/run_phase155_notification_rules.php` (idempotent, verifies its own outcome, exits non-zero if it cannot). The three tables have one definition, `inc/notification-schema.php`, and a test compares it with `sql/notification_rules.sql`.
