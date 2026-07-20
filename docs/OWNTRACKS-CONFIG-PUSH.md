# OwnTracks — Provisioning configs and rotating shared secrets

OwnTracks is the BYO-phone location provider TicketsCAD prefers for personal devices. This guide covers two operational needs:

1. **Onboarding new clients** without making them type a URL + shared secret by hand
2. **Rotating the shared secret** without losing positions during the transition

OwnTracks's own configuration-distribution methods (taken from <https://owntracks.org/booklet/features/remoteconfig/>):

| Method | How it works | Best for |
|---|---|---|
| **HTTP config endpoint** | Client polls `/iOS/Android/clientconfig.json` and applies updates. | Initial provisioning and ongoing config drift |
| **Config-share QR code** | Generate a QR code containing the config as a `owntracks:///?inline=<json>` URL. User scans in the app. | Bulk onboarding at a meet-up or training session |
| **Email-link onboarding** | Email a link in the same `owntracks:///?inline=…` scheme; user taps on phone. | Remote onboarding for users who can't be in-person |
| **MQTT remote-config** | Publish a JSON config payload to `<topic>/cmd` with `_type: setConfiguration`. | Already-onboarded clients on MQTT mode |
| **HTTP remote-config** | TicketsCAD includes a `_type: setConfiguration` payload in the response to a position POST. The client applies it on receipt. | Already-onboarded clients on HTTP mode |

## What we implement today

### 1. HTTP push (`POST /api/owntracks-push.php`)

Returns a config payload as the response to the client's regular position POST. The client applies it transparently — no UI prompt. We use this to push minor changes (URL updates, interval tuning, secret rotation Phase 2).

Body:
```json
{
  "_type": "setConfiguration",
  "configuration": {
    "_type": "configuration",
    "host":  "your-server.example.com",
    "port":  443,
    "tls":   true,
    "url":   "https://your-server.example.com/api/location.php",
    "auth":  true,
    "username": "<member-username>",
    "password": "<member-tracking-token>",
    "deviceId": "<member-uuid>",
    "pubInterval": 60
  }
}
```

The client treats every key as optional; the merge is shallow. You can push just `url` to migrate ingest endpoints without touching other fields.

### 2. QR / email link generator (`mobile-config.php?member_id=N&mode=qr|link|email`)

Generates a one-shot `owntracks:///?inline=<urlencode(json)>` URL. Three delivery modes:

- **QR code** — render to PNG/SVG inline on the screen. Member scans with the OwnTracks app's "Scan QR" feature.
- **Copy link** — admin copies the `owntracks:///` URL and pastes it into Signal / iMessage / etc.
- **Email** — TicketsCAD sends the link as an HTML mail to the member's address. The email includes a one-click "Open in OwnTracks" button.

The generated link bakes in the member's **tracking token** (not their account password). The token can be revoked independently via Settings → Identity & Security → Field Encryption → Tracking Tokens.

### 3. Secret rotation flow

Goal: replace the OwnTracks shared secret (password) for some or all members without ANY position drops.

**Three-phase rollout:**

1. **Phase A — provision new tokens.** Generate the new secret. Insert into `member_tracking_tokens` (or the appropriate auth store) with `valid_from = NOW()` and `valid_until = NULL`. Old token kept active. New + old both accepted by `/api/location.php`.

2. **Phase B — push config update.** Either:
   - Wait for clients to POST a position. The response carries `setConfiguration` with the new password. Most clients pick it up within their `pubInterval` (default 60s).
   - Force-push by sending an email/QR to laggers whose `last_seen_at_with_new_token` is null after 24 hours.

3. **Phase C — retire old token.** After the cutover window (default 7 days, configurable in `owntracks_token_dual_window_days`), revoke old tokens that haven't been used. (A helper to list clients still on the old token — a planned `tools/owntracks-revoke-stale-tokens.php` — is not yet built; for now, review the tokens in the location-provider settings.)

### 4. Stale-client tracking

The `location_reports` table records `auth_token_id` per row. The Owner-Track stale report SQL:

```sql
SELECT m.username,
       m.email,
       t.token_label,
       MAX(lr.received_at) AS last_seen
  FROM member_tracking_tokens t
  JOIN member m ON m.id = t.member_id
  LEFT JOIN location_reports lr ON lr.auth_token_id = t.id
 WHERE t.revoked_at IS NULL
   AND t.created_at < NOW() - INTERVAL :rotation_window_days DAY
 GROUP BY t.id
 ORDER BY last_seen DESC NULLS LAST;
```

A nightly cron emits a CSV: `data/owntracks-stale-tokens.csv`. The admin works through the list, contacts each user individually, then runs the revoke script.

## Implementation status

| Piece | Status |
|---|---|
| HTTP push response carrying `setConfiguration` | **Not yet built.** Scoped here; aim for next sprint. |
| `mobile-config.php?mode=qr` | **Not yet built.** Reuses qrcode.js already loaded by mesh-console. |
| `mobile-config.php?mode=link` | **Not yet built.** Trivial — just `urlencode` + render. |
| `mobile-config.php?mode=email` | **Not yet built.** Wires through existing SMTP. |
| `member_tracking_tokens` schema with valid_from/valid_until | **Partial.** Tokens exist; explicit validity columns missing. |
| `tools/owntracks-revoke-stale-tokens.php` | **Not yet built.** |
| Owner-Track stale report cron + CSV | **Not yet built.** |

This document is the spec for the next OwnTracks-focused phase. Reading it cold should be enough to estimate the work and walk through the implementation review.

## References

- OwnTracks Booklet — Remote Config: <https://owntracks.org/booklet/features/remoteconfig/>
- OwnTracks recorder HTTP mode: <https://owntracks.org/booklet/clients/http/>
- `setConfiguration` payload reference: <https://owntracks.org/booklet/tech/json/#_typesetconfiguration>
- Config-share URL scheme: <https://owntracks.org/booklet/features/configshare/>
