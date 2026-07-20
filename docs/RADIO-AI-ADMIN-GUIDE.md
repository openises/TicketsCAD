# Radio AI — Administrator Guide

**Audience:** sysadmin standing up the Claude-on-amateur-radio feature on a TicketsCAD instance.
**Goal:** wake-word transcripts captured on a watched channel get drafted by Claude, an operator approves them via the web UI, and the bridge transmits the response.
**Prerequisite:** working DVSwitch DMR bridge (see [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md)) with both RX and TX paths verified, plus the new `ticketscad-hbp-client.service` systemd unit running (see "Bridge supervision" below).

This is the Phase 85f system. It is **experimental** — the use cases it's built for today are net-control assistance, message-passing relay, and standing up an automated net controller before human operators are online. It deliberately lives on a separate page (`/radio-ai.php`) from the dispatch console so the primary CAD experience stays focused.

---

## Architecture

```
 ┌───────────────────┐         ┌───────────────────────┐
 │ Caller's DMR      │ DMR/IP  │   BrandMeister relay  │
 │ radio  "Claude,   │────────►│   (TG 3127 etc.)      │
 │ what's the…?"     │         └───────────┬───────────┘
 └───────────────────┘                     │
                                           │ HBP UDP
                                           ▼
                  ┌─────────────────────────────────────────────┐
                  │  bridge: hbp_client.py                      │
                  │  (systemd: ticketscad-hbp-client.service)   │
                  │   - audio-stream SSE  (RX to operators)     │
                  │   - /tx/text endpoint (TTS → on-air)        │
                  │     ↳ 0.75 s pre-roll silence prepended     │
                  │     ↳ dry_run flag skips on-air for testing │
                  └────────────────┬────────────────────────────┘
                                   │ HTTP ingest (per-call rows)
                                   ▼
                  ┌─────────────────────────────────────────────┐
                  │  echo_bot.py (Whisper STT)                  │
                  │   - faster-whisper transcription            │
                  │   - POST /api/dmr-ingest.php                │
                  └────────────────┬────────────────────────────┘
                                   ▼
                  ┌─────────────────────────────────────────────┐
                  │  TicketsCAD database                        │
                  │   dmr_messages (transcripts + audio_path)   │
                  │   radioid_users (DMR ID → callsign cache)   │
                  │   ai_pending_responses (operator queue)     │
                  │   ai_conversations + _messages (context)    │
                  │   settings (radio_ai_*)                     │
                  └────────────────┬────────────────────────────┘
                                   │ poll every 5 s
                                   ▼
                  ┌─────────────────────────────────────────────┐
                  │  inc/radio_ai_listener.php  (CLI daemon)    │
                  │   - wake-word match on new transcripts      │
                  │   - calls Claude API via radio_ai_client    │
                  │   - inserts pending_approval rows           │
                  └────────────────┬────────────────────────────┘
                                   │ operator polls every 10 s
                                   ▼
                  ┌─────────────────────────────────────────────┐
                  │  /radio-ai.php  (browser)                   │
                  │   - assets/js/radio-ai-approval.js          │
                  │   - GET  /api/radio-ai-pending.php          │
                  │   - POST /api/radio-ai-decide.php           │
                  │     ↳ approve → POST bridge /tx/text        │
                  └─────────────────────────────────────────────┘
```

---

## What needs to be in place before you start

1. DVSwitch bridge running, authenticated to BrandMeister, audible RX in the web widget (see DVSWITCH-ADMIN-GUIDE.md).
2. Whisper STT producing transcripts that land in `dmr_messages.transcript` for new calls.
3. `radioid_users` cache populated for the operators on your channel (the bridge's writer + the new `tools/radioid_fetch_unknowns.php` keep this populated automatically — see below).
4. An **Anthropic API key** with billing enabled. The system uses `claude-sonnet-4-6` by default with `effort=low` to keep cost manageable; budget around US$0.50–$2 per 100 drafts depending on length.
5. At least one TicketsCAD user with the **`action.dmr_transmit`** RBAC permission. That user is the only one who can approve drafts. The same permission gates manual PTT, so an operator who's allowed to key the radio is allowed to release AI drafts.

---

## Initial setup

### 1. Schema migration (one-time)

```bash
php /var/www/newui/sql/run_phase85f_radio_ai.php
```

Creates the three tables (`ai_pending_responses`, `ai_conversations`, `ai_conversation_messages`) and seeds the settings rows with safe defaults (everything disabled, conservative limits). Idempotent — safe to re-run.

### 2. Store the Anthropic API key

The listener daemon reads the key from `/etc/ticketscad/anthropic.env`. Create the file:

```bash
sudo install -m 0640 -o root -g www-data /dev/null /etc/ticketscad/anthropic.env
sudo vim /etc/ticketscad/anthropic.env
```

Acceptable formats:
```
ANTHROPIC_API_KEY=sk-ant-api03-...
```
or bare:
```
sk-ant-api03-...
```

The file should be `mode 0640 root:www-data` so Apache + the listener can read it but unprivileged users on the host can't. The listener will refuse to run if the file is world-readable.

### 3. Configure settings

The seed values are safe but you'll need to flip `radio_ai_enabled` to `1` to actually start listening. From an admin SQL prompt (or via the settings page once that admin UI lands):

```sql
UPDATE settings SET value = '1'        WHERE name = 'radio_ai_enabled';
UPDATE settings SET value = 'claude'   WHERE name = 'radio_ai_wake_word';        -- default
UPDATE settings SET value = '3'        WHERE name = 'radio_ai_channel_ids';      -- CSV of dmr_channels.id
UPDATE settings SET value = '75'       WHERE name = 'radio_ai_max_response_words';
UPDATE settings SET value = '50000'    WHERE name = 'radio_ai_daily_token_budget';
```

| Setting | Purpose | Sane range |
|---|---|---|
| `radio_ai_enabled` | Master kill-switch. `0` makes the listener idle without consuming Anthropic credit. | 0 or 1 |
| `radio_ai_wake_word` | Case-insensitive substring matched with word boundaries on each transcript. | A single short word; "claude" works well. |
| `radio_ai_channel_ids` | Comma-separated `dmr_channels.id` values. Empty = all channels. | Just the channels you want monitored. |
| `radio_ai_max_response_words` | Soft cap surfaced in the system prompt; Claude is told to stay under this. | 50–100. Voice traffic should be short. |
| `radio_ai_auto_discard_seconds` | How long a pending row sits before being auto-marked discarded. Not implemented yet — currently just metadata. | 60–180. |
| `radio_ai_topic_scope` | A label that scopes which system prompt to use. | `ham_general_science` (default). |
| `radio_ai_daily_token_budget` | Soft daily ceiling on Anthropic input+output tokens. | 20k–200k depending on activity. |
| `radio_ai_model` | Anthropic model ID. | `claude-sonnet-4-6` (default). |

### 4. Run the listener daemon

For development:
```bash
cd /var/www/newui
php inc/radio_ai_listener.php
```

The listener logs to stdout. Watch for `starting — poll every 5s, batch 10, max age 600s`. Each wake-word hit logs `msg #NNN: queued as pending #NNN (caller=N0XYZ, q="...")` followed by `msg #NNN: draft ready (NN+NN tokens)`.

For production, wrap it in systemd. A unit template:

```ini
# /etc/systemd/system/ticketscad-radio-ai-listener.service
[Unit]
Description=TicketsCAD Radio AI wake-word listener
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/newui
EnvironmentFile=/etc/ticketscad/anthropic.env
ExecStart=/usr/bin/php /var/www/newui/inc/radio_ai_listener.php
Restart=on-failure
RestartSec=10
StandardOutput=journal
StandardError=journal

ProtectSystem=strict
ReadWritePaths=/tmp
NoNewPrivileges=yes
PrivateTmp=yes

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ticketscad-radio-ai-listener
sudo journalctl -u ticketscad-radio-ai-listener -f
```

### 5. Bridge supervision (Phase 85f-10)

`hbp_client.py` should run under the dedicated systemd unit shipped with the repo:

```bash
sudo install -m 0644 services/dvswitch/ticketscad-hbp-client.service.example \
    /etc/systemd/system/ticketscad-hbp-client.service
sudo install -m 0640 -o root -g ticketscad services/dvswitch/hbp-client.env.example \
    /etc/ticketscad/hbp-client.env
sudo vim /etc/ticketscad/hbp-client.env       # fill in DMR_BEARER_TOKEN, DMR_OPERATOR_ID, DMR_DEFAULT_TG
sudo systemctl daemon-reload
sudo systemctl enable --now ticketscad-hbp-client
```

This replaces any manual `nohup` invocation that was running the bridge — without the unit, a crash leaves the bridge silently down.

Verify auto-restart works:
```bash
sudo kill -9 $(systemctl show -p MainPID --value ticketscad-hbp-client)
sleep 8
sudo systemctl is-active ticketscad-hbp-client      # should print "active"
```

### 6. Give an operator the permission

```sql
INSERT INTO user_roles (user_id, role_id)
SELECT u.id, r.id
  FROM user u, roles r
 WHERE u.username = 'YOUR_OPERATOR'
   AND r.code     = 'role.dmr_operator';
```

…or assign whatever role you're using locally that contains `action.dmr_transmit`. See [RBAC-GUIDE.md](RBAC-GUIDE.md) for the role-management UI.

---

## Operating the system day to day

### Watching the queue

The operator opens `/radio-ai.php`. The page polls `/api/radio-ai-pending.php` every 10 seconds and renders any rows in `pending_generation`, `pending_approval`, `filtered`, or recent `error` status. See [RADIO-AI-USER-GUIDE.md](RADIO-AI-USER-GUIDE.md) for the operator flow.

### Dry-run mode

The page has a **Dry run** toggle that adds `dry_run: true` to the bridge POST. The bridge then runs the full Piper + ffmpeg + pre-roll-silence pipeline but skips `send_to_master()` so nothing goes on-air. Use this for:

- New deployment verification — confirm Claude is drafting, the operator UI is responsive, the bridge accepts TX requests.
- After a code change to the listener, the API, or the bridge — confirm no regression.
- Operator training — let a new dispatcher rehearse the workflow without bothering live traffic.

### Auto-approve

Page has an **Auto-approve** toggle that auto-fires Approve on any incoming `pending_approval` row. Three hard safeties (per project-radio-ai-separation memory decisions):

1. **Two-hour ceiling** — independent watchdog checks the expiry every 15 s and flips OFF the moment 2 h elapses, regardless of traffic.
2. **Page-closed = OFF** — auto-approve state lives in the browser's localStorage and is bound to operator presence.
3. **Filtered drafts never auto-fire** — `status=filtered` rows always require a human click. The content filter exists because something tripped it.

For unattended scenarios (e.g., overnight Skywarn net warmup), the operator should leave the page open, enable Dry run + Auto-approve to rehearse without TX, then disable Dry run once they're satisfied the listener is producing useful drafts.

### Callsign cache hygiene

The bridge's writer now (Phase 86-archive followup) does a local `radioid_users` lookup at ingest time so cached IDs land with their callsign on the row directly. For IDs not yet in the cache, run the backfill periodically:

```bash
php /var/www/newui/tools/radioid_fetch_unknowns.php --batch=50
```

Output:
```
[hh:mm:ss] scanning for unknown DMR IDs (batch=50, sleep=0.75s)
[hh:mm:ss] found N uncached ID(s)
[hh:mm:ss]   3127202 -> N0NKI / Eric J
...
[hh:mm:ss] fetch summary: N cached, N not-in-radioid, 0 errors
[hh:mm:ss] backfilled radio_callsign on N dmr_messages row(s)
```

Suggested cron:
```cron
17 * * * *  /usr/bin/php /var/www/newui/tools/radioid_fetch_unknowns.php --batch=50 >/dev/null 2>&1
```

The script respects radioid.net's caching guidance — 0.75 s spacing between requests by default, configurable via `--sleep=N`. Stops after 5 consecutive errors so we don't hammer them when their API is degraded.

### Watching cost

Each pending row stores `api_tokens_in` and `api_tokens_out`. To see what you've spent today:

```sql
SELECT SUM(api_tokens_in) tokens_in, SUM(api_tokens_out) tokens_out
  FROM ai_pending_responses
 WHERE DATE(created_at) = CURDATE();
```

Roughly: $3 per million input tokens + $15 per million output tokens for `claude-sonnet-4-6`. A typical 60-word draft is ~80 tokens out, ~400–800 tokens in (depending on conversation history). So 100 drafts/day costs roughly $0.04–$0.15.

The `radio_ai_daily_token_budget` setting is a soft ceiling — the listener won't enforce it yet. If you want hard enforcement, query the table at the top of `radio_ai_listener_loop()` and skip if over budget.

---

## Security model

### Trust boundaries

1. **Operator browser → `radio-ai-decide.php`** — session auth + CSRF token + RBAC check. The endpoint is the only path that POSTs to the bridge's `/tx/text`. Operators never see the bridge token.
2. **`radio-ai-decide.php` → bridge** — bearer auth using `dmr_channels.bridge_token`. Token is stored only in the database (encrypted column if you've enabled it; otherwise plaintext in MariaDB on the host) and read at decision time. Never sent to the browser.
3. **Listener daemon → Anthropic API** — TLS, API key from `/etc/ticketscad/anthropic.env` (mode 0640).
4. **Listener daemon → database** — local UNIX socket / shared host; same trust boundary as Apache.

### What gates a transmission

A draft becomes on-air audio only when ALL of:

- Operator is authenticated (`auth.php` 401s otherwise).
- Operator has `action.dmr_transmit` RBAC (decide 403s otherwise).
- POST body's `csrf_token` matches the operator's session token.
- Row exists, is currently in `pending_approval` or `filtered`, and is locked `FOR UPDATE` (atomic against double-fire).
- Row's channel maps to an enabled `dmr_channels` row with a bridge_host/port/token.
- Bridge returns HTTP 200 with `ok: true`. If the bridge errors, status moves to `error` and the row stays visible for retry.

### What the content filter catches

`inc/radio_ai_client.php` does regex screens on the draft for:

- URL-like patterns (`https?://`, `www\.`)
- Phone numbers (US-style 10-digit + international `+CC`)
- Profanity (a configurable list)
- Common social-handle patterns (`@username`)

Anything flagged sets `status=filtered` and surfaces a warning banner on the card. The operator must explicitly approve a filtered draft — auto-approve skips them entirely. This is **defense in depth, not infallible**: amateur operators are the final responsible party, the filter is there to catch obvious problems before they reach a human's screen.

### Prompt injection

The system prompt isolates the caller's transcript inside delimiters and instructs Claude to treat it as data, not instructions. Claude has not yet been observed to follow operator-targeted instructions embedded in a caller's transcript, but the assumption is that it eventually will try. The mitigation is the operator review step — every draft goes through a human before keying the radio.

### Audit trail

Each approve/reject/edit decision writes `decided_at` + `decided_by` (user.id) to `ai_pending_responses`. The bridge logs every `/tx/text` request to its journal. Together these reconstruct who-approved-what-and-when.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Listener log says "starting" but never queues anything | `radio_ai_enabled = 0`, or the wake-word never matches, or the channel isn't in `radio_ai_channel_ids`. | Check the settings table; tail the listener log when you transmit "Claude, test" yourself. |
| Listener queues rows but every one transitions to `error` | Anthropic API key missing / invalid / out of credit; or the model ID in `radio_ai_model` is wrong. | Check the listener's error_msg in `ai_pending_responses`; verify `/etc/ticketscad/anthropic.env` has a working key. |
| Operator clicks Approve and the row goes to `error` with `bridge HTTP 0` | Bridge is down or unreachable from the TicketsCAD host. | `systemctl status ticketscad-hbp-client`; `curl -s http://<bridge>:18091/health`. |
| Operator clicks Approve and the row goes to `error` with `bridge HTTP 401` | The `bridge_token` in `dmr_channels` doesn't match what's in the bridge's `/etc/ticketscad/hbp-client.env`. | Re-paste the token; restart the bridge. |
| Drafts arrive but with wrong callsigns or bare DMR IDs | The radioid cache hasn't seen these IDs. | Run `php tools/radioid_fetch_unknowns.php`. |
| Auto-approve doesn't seem to fire | Browser was closed (state is per-session) or the 2-hour ceiling expired. Filtered drafts also never auto-fire. | Re-check the toggle on the page. |
| `Dry run OK — 0 packets, 0 ms` | Bridge ran the dry-run path but Piper or ffmpeg failed silently. | Check the bridge journal: `journalctl -u ticketscad-hbp-client --since "5 min ago"`. |

---

## Related docs

- [RADIO-AI-USER-GUIDE.md](RADIO-AI-USER-GUIDE.md) — operator-facing workflow.
- [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md) — DMR bridge setup that this builds on.
- [RBAC-GUIDE.md](RBAC-GUIDE.md) — how to grant `action.dmr_transmit`.
- [SECURITY-POLICY.md](SECURITY-POLICY.md) — broader security model for TicketsCAD.
- `tools/radioid_fetch_unknowns.php` — callsign cache backfill.
- `tools/smoke_85f4_decide.php` — end-to-end CLI smoke test against `dry_run=true`.
- `services/dvswitch/ticketscad-hbp-client.service.example` — bridge supervision unit.
- `services/dvswitch/hbp-client.env.example` — bridge env template.
