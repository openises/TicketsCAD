# Maintenance Runbook

**Audience:** sysadmin running a production TicketsCAD install.
**Goal:** keep the system healthy, secure, backed up, and current.

This is the "what to do regularly" companion to [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md) (one-time setup) and [TROUBLESHOOTING.md](TROUBLESHOOTING.md) (when something breaks).

---

## Cadence overview

| Cadence | What to do |
|---|---|
| **Continuous** (cron / systemd timers) | RBAC grant expiry, PAR scheduler, audit-log trim, automated backup |
| **Daily** (10 minutes) | Glance at error log, confirm last backup succeeded, check active-sessions count |
| **Weekly** (30 minutes) | Apply OS security patches, verify backup restore on a test VM, review audit log for anomalies |
| **Monthly** (1–2 hours) | Apply TicketsCAD updates, refresh TLS cert (if not auto), review user list for stale accounts, run SonarQube scan |
| **Quarterly** (half day) | Full disaster-recovery drill, password policy review, RBAC role audit, dependency upgrade |
| **Annually** | Encryption-key rotation, CJIS recert, full penetration test, cryptographic-currency review |
| **Every 2 years** *(maintainer, not operators)* | Rotate the SBOM signing key |

Two of these are the **project maintainer's** jobs rather than an operator's,
because they concern what we publish rather than what you run: signing the SBOM
at each release, and rotating the signing key. They are listed here so the
schedule is complete and so nothing silently lapses. See the maintainer section
at the end of this document.

---

## Continuous — automated

These run on cron or systemd timers. Set up in [INSTALLATION-CHECKLIST.md § Section 12](INSTALLATION-CHECKLIST.md#section-12--cron-for-background-tasks).

> ### Before you trust any of this: confirm a scheduler actually exists
>
> On 29 July 2026 both servers running TicketsCAD were found to have
> `/etc/cron.d/par_tick` and `/etc/cron.d/pending_msg_tick` installed since
> 11 June — and **no cron daemon installed at all**. Neither job had executed
> once in seven weeks. Their log files were still zero bytes. Writing a file
> into `/etc/cron.d` on a host without cron fails *completely silently*:
> no error, no log, nothing to distinguish it from a working scheduler.
> Minimal Debian cloud images routinely ship without `cron`.
>
> ```bash
> systemctl is-active cron                          # "not-found" => no cron daemon
> systemctl list-timers --all | grep -i ticketscad  # is a timer scheduled instead?
> journalctl -u ticketscad-par-tick -n 20           # what the last runs actually did
> ```
>
> Do **not** judge a timer by `/var/log/par_tick.log`. That file belonged to the
> cron line; the timer units set `StandardOutput=journal`, so it stays zero bytes
> on a perfectly healthy host. Reading it as "never ran" inverts the original
> bug — a dead scheduler that looked configured becomes a live one that looks
> dead — and sends you fixing something that works.
>
> **Settings → System Health → File & Code Health → Scheduled background jobs** now
> shows the last successful run of each job and turns red when one stops or
> has never started. It reads the `scheduled_job_runs` heartbeat, which the tick
> writes itself, so it cannot report a run that did not happen. Check there
> first; it is the surface that was missing.

Verify they're firing:

```bash
sudo systemctl list-timers --all | grep -Ei 'newui|ticketscad'
sudo crontab -l -u www-data          # only meaningful if cron is installed
php tools/check-health.php           # includes the Scheduled background jobs section
```

### Scheduled background jobs — systemd timer units

Use these when there is no cron daemon (they need no extra package). One
`.service` + one `.timer` per job; `Persistent=true` so a machine that was
switched off catches up at next boot instead of skipping silently.

`/etc/systemd/system/ticketscad-par-tick.service`:

```ini
[Unit]
Description=TicketsCAD PAR scheduler tick
After=network.target mariadb.service mysql.service

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/newui
ExecStart=/usr/bin/php /var/www/newui/tools/par_tick.php
StandardOutput=journal
StandardError=journal
TimeoutStartSec=300
```

`/etc/systemd/system/ticketscad-par-tick.timer`:

```ini
[Unit]
Description=Run the TicketsCAD PAR scheduler every minute

[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
AccuracySec=15s
Persistent=true
Unit=ticketscad-par-tick.service

[Install]
WantedBy=timers.target
```

The pending-message sweep is identical with the name and ExecStart changed —
`ticketscad-pending-msg.service` running
`/usr/bin/php /var/www/newui/tools/pending_messages_tick.php`, and
`ticketscad-pending-msg.timer` pointing `Unit=` at it.

The audit-log retention purge (Phase 133, 2026-08-03) is the same shape again
— `ticketscad-audit-purge.service` running
`/usr/bin/php /var/www/newui/tools/audit_log_purge_tick.php`, and
`ticketscad-audit-purge.timer` pointing `Unit=` at it — but **daily**, not
every minute:

```ini
[Timer]
OnBootSec=10min
OnUnitActiveSec=1d
AccuracySec=1h
Persistent=true
Unit=ticketscad-audit-purge.service
```

This job is a genuine no-op (`Settings → Audit Log → Retention & Purge` is
disabled by default) unless an administrator has turned on
`audit_log_retention_days` — nothing runs it into deleting anything until
that setting is nonzero, so installing this timer on an install where
retention is off is safe and harmless. See
[AUDIT-LOG-REFERENCE.md § Retention](AUDIT-LOG-REFERENCE.md#retention) for
what the job does and how it archives before it deletes.

The inbound channel poll (Phase 134, 2026-08 — GH #23 Model 3) is the same
shape again — `ticketscad-channel-receive-tick.service` running
`/usr/bin/php /var/www/newui/tools/channel_receive_tick.php`, and
`ticketscad-channel-receive-tick.timer` pointing `Unit=` at it — **every
minute**, same interval as `par-tick`:

```ini
[Timer]
OnBootSec=2min
OnUnitActiveSec=1min
AccuracySec=15s
Persistent=true
Unit=ticketscad-channel-receive-tick.service
```

Like the audit-log purge, this job is a genuine no-op — 0 channels polled —
unless an administrator has opted a channel in via Settings → Telegram /
Settings → Slack ("Poll for inbound messages", off by default on both).
Installing this timer unconditionally is safe and harmless; nothing is
polled until an operator turns a specific channel's inbound polling on.

The message-log retention purge (GH #42, 2026-08-13) is the same shape again
— `ticketscad-message-log-purge.service` running
`/usr/bin/php /var/www/newui/tools/message_log_purge_tick.php`, and
`ticketscad-message-log-purge.timer` pointing `Unit=` at it — daily, same
cadence as the audit-log purge:

```ini
[Timer]
OnBootSec=10min
OnUnitActiveSec=1d
AccuracySec=1h
Persistent=true
Unit=ticketscad-message-log-purge.service
```

**Unlike** the audit-log purge and channel-poll timers above, this one is
**not** safe to skip once the setting is turned on: the Status page's
scheduled-jobs check considers a job "required" the moment its governing
setting is enabled (`message_log_retention_days` > 0 — Settings → Pending
Messages → Message Log Retention), regardless of whether the timer exists yet. Turn
the setting on WITHOUT installing this timer and the job is stuck at "never
run" forever, which the health check correctly reports as **Critical** — it
is not a false alarm, it is telling you the purge you asked for isn't
running. Install the timer FIRST, or leave the setting at its default (`0`,
disabled) until you do.

#### If you use Web Push, SMS, e-mail, Slack or webhooks: run that one every 15 seconds

Since 2026-07-31 the pending-message sweep also **sends the outbound
notifications**. They used to go out inside the dispatcher's own request, which
cost 21 seconds per dispatch action whenever the internet was down (measured;
see `docs/OFFLINE-OPERATION.md` §8, defect D3). Moving them to this job made a
dispatch action take 0.04s — and made **this timer's interval the delay on
every callout**.

With the 1-minute interval above, a push notification can be up to a minute
behind the incident. For a volunteer agency being paged out, that is worth
tightening:

```ini
[Timer]
OnBootSec=1min
OnUnitActiveSec=15s
AccuracySec=5s
Persistent=true
Unit=ticketscad-pending-msg.service
```

The sweep is cheap when there is nothing to do — one indexed `SELECT` returning
no rows — so a 15-second interval is not a meaningful load. Leave `par-tick` at
a minute.

If **no** scheduler is installed, the software does not silently stop notifying
anybody: the request still queues the notification and then makes one
best-effort attempt, bounded by a 3-second budget and paused for 60 seconds
after two consecutive failures. But that is a fallback, not the design.
Settings → System Health → Scheduled jobs will say so, in red.

Install and prove them:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now ticketscad-par-tick.timer ticketscad-pending-msg.timer \
  ticketscad-channel-receive-tick.timer
# Only if you have turned on audit-log retention (Settings → Audit Log → Retention & Purge):
sudo systemctl enable --now ticketscad-audit-purge.timer
# Only if you have turned on message-log retention (Settings → Pending Messages → Message Log Retention) --
# but install this ONE before turning the setting on, not after (see note above):
sudo systemctl enable --now ticketscad-message-log-purge.timer
sudo systemctl list-timers --all | grep ticketscad
sudo journalctl -u ticketscad-par-tick.service -n 20 --no-pager

# Remove the cron.d files that never worked, so nobody trusts them again:
sudo rm -f /etc/cron.d/par_tick /etc/cron.d/pending_msg_tick
```

### The stale-work cutoff — why a restarted job does not flush its backlog

Both sweeps act on "everything overdue". That is right for a job running
every minute and dangerous for one restarted after an outage: the first tick
would deliver seven weeks of held messages at once, and raise PAR alarms
about incidents that closed a month ago.

Work more than **`sched_stale_cutoff_min`** minutes past due (default **60**;
`0` disables the cutoff) is therefore recorded as **expired** and *not* acted
on. Nothing is deleted — the rows stay, with the reason, the scheduled time
and the governing setting written into them, so both
*"why did I get this?"* and *"why did I NOT get this?"* have an answer:

```sql
-- messages that were held back, and why
SELECT id, channel, target, scheduled_send_at, send_error
  FROM pending_routed_messages WHERE status = 'expired';

-- PAR cycles that were closed out instead of escalated retroactively
SELECT id, ticket_id, initiated_at, notes
  FROM par_cycles WHERE status = 'expired';
```

To deliberately release an expired message, set it back to `pending` with a
fresh `scheduled_send_at`. Expiry is reversible by design.

| Job | Cadence | What it does | If it stops |
|---|---|---|---|
| `tools/expire_grants.php` | hourly | Removes time-bound role grants past `expires_at` | Users keep elevated access past intended window |
| `tools/par_tick.php` | every minute | Fires PAR cycles for active incidents per cadence; marks missed acks; posts escalation chat | PAR doesn't fire; manual PAR still works. **Flagged on the Status page** |
| `tools/pending_messages_tick.php` | every minute | Delivers routed messages held for their security-label kill window | Held messages never leave the queue; after `sched_stale_cutoff_min` they expire undelivered. **Flagged on the Status page** |
| `tools/audit_log_purge_tick.php` | daily | Archives (gzip NDJSON, written first) then removes `newui_audit_log` rows older than `audit_log_retention_days`, if that setting is nonzero. Off by default. | Nothing — the job is only *required* once retention is turned on, at which point a missed run is **flagged on the Status page** exactly like the two above. Disabled installs are never nagged about it. |
| `tools/channel_receive_tick.php` | every minute | Polls opted-in broker channels (Telegram, Slack) for inbound messages; routes them via `broker_receive()` (dedup + logging + whatever routes exist). Off per-channel by default (`telegram_poll_inbound` / `slack_poll_inbound`). | Nothing — required only once at least one channel's inbound polling is turned on (Settings → Telegram / Slack), at which point a missed run is **flagged on the Status page**. Disabled/unconfigured installs are never nagged about it. |
| `tools/message_log_purge_tick.php` | daily | Removes outbound message-log rows (SMS/e-mail/Slack delivery-status rows) older than `message_log_retention_days`, if that setting is nonzero. Off by default. | Nothing while disabled. Once `message_log_retention_days` is turned on, install the timer BEFORE (not after) — otherwise the job is stuck at "never run" and the Status page reports it **Critical**, correctly. |
| location-reports trim *(planned)* | daily 03:30 | Same idea for `location_reports`; same workaround | DB bloat; map slowness |
| backup *(planned)* | daily 02:00 | No all-in-one script yet — use `mysqldump` via cron per [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) | No fresh backup if a disaster hits |
| `certbot renew` | twice daily (auto) | Renews Let's Encrypt cert | TLS cert expires; site breaks |

**Verify the audit-log purge is doing its job** (only meaningful once
`audit_log_retention_days` is nonzero):

```sql
SELECT MIN(event_time), MAX(event_time), COUNT(*) FROM newui_audit_log;
SELECT ran_at, cutoff_date, rows_purged, status, detail FROM audit_log_purges ORDER BY id DESC LIMIT 5;
```

The MIN should be roughly `today - retention_days`. If MIN is older, check
the latest `audit_log_purges` row: a `status='failed'` row usually means the
application's DB user has had its DELETE grant revoked (see
[AUDIT-LOG-REFERENCE.md § Tamper-resistance](AUDIT-LOG-REFERENCE.md#tamper-resistance))
— that is the expected, loudly-reported result of following this project's
own tamper-resistance advice, not a bug.

---

## Daily — 10 minutes

### 1. Eyeball the Apache error log

```bash
sudo tail -200 /var/log/apache2/newui-error.log
```

What "normal" looks like: empty, or a handful of `[client … access denied]` lines (legitimate auth rejections).

What "abnormal" looks like:

- Repeated `PHP Fatal error` traces → fix or roll back the most recent code change
- Repeated `SQLSTATE` errors → DB connection issue or schema-drift hit. See [TROUBLESHOOTING.md § strict-mode](TROUBLESHOOTING.md#strict-mode)
- `[sse._sse_groups_for_resource] ... allocates lookup failed` → SSE scope-filter is hitting an exception; check the `allocates` table

### 2. Confirm last night's backup succeeded

```bash
ls -lh /var/backups/newui/$(date -d yesterday +%Y-%m-%d)*
sudo tail -50 /var/log/newui-backup.log
```

Expected: an `.sql.gz` file ≥ 1 MB (a healthy backup grows with your audit log) and a log line ending `[OK] backup completed`. If missing → see [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md).

### 3. Glance at active sessions

Settings → Identity & Security → Active Sessions. Look for:

- Sessions from IPs you don't recognise → investigate
- A single user account with many sessions → either shared credentials (bad) or testing
- Sessions that have been open ≥ 24 h → expected for office workstations on trusted CIDRs

### 4. Check SSE health

```bash
# As any logged-in user with a session cookie:
curl -N -s -H "Cookie: PHPSESSID=YOUR_SESSION" https://cad.example.org/api/stream.php | head -5
```

Expected: `event: ping` within 5 seconds. If not, see [TROUBLESHOOTING.md § sse-gray](TROUBLESHOOTING.md#sse-gray).

---

## Weekly — 30 minutes

### 1. OS security patches

```bash
sudo apt-get update
sudo apt-get upgrade -s | grep -i security    # dry-run preview
sudo apt-get upgrade
```

If `apache2`, `php8.2`, `mariadb-server`, or `openssl` updated, reload the affected service:

```bash
sudo systemctl reload apache2
sudo systemctl restart mariadb     # restart, not reload, after DB upgrade
sudo systemctl restart php8.2-fpm
```

Smoke-test after each restart: log in via browser, dispatch a test incident.

### 2. Verify backup restore on a test VM

Schedule a different day each week so a real disaster doesn't catch a "broken since Monday" backup.

```bash
# On a separate VM (test box, ephemeral):
sudo apt-get install -y mariadb-server
sudo mariadb -e "CREATE DATABASE newui_restore_test;"
gunzip -c /tmp/copied-backup.sql.gz | sudo mariadb newui_restore_test
sudo mariadb newui_restore_test -e "SELECT COUNT(*) FROM ticket; SELECT COUNT(*) FROM user;"
```

The counts should match what's in production (allow for what changed between backup and now).

### 3. Audit log review for anomalies

Settings → Audit Log. Filter by:

- Category = `security`, status = `failure` → failed logins, bad CSRF tokens, lockouts
- Category = `admin` → role/grant changes (any new super-admin grants since last week?)
- Category = `comms` → broadcast messages (was each one authorised?)

Investigate anything unexpected. Filing an audit-log query export to a SIEM is the long-term answer — see [AUDIT-LOG-REFERENCE.md](AUDIT-LOG-REFERENCE.md).

### 4. Stale account scan

```sql
SELECT user, last_login, locked_until
FROM user
WHERE last_login < NOW() - INTERVAL 90 DAY
   OR last_login IS NULL
ORDER BY last_login;
```

For each: disable, prompt the user to re-enroll, or document why the account is dormant (vacation, seasonal volunteer).

---

## Monthly — 1–2 hours

### 1. TicketsCAD update

NewUI updates ship via git. Check the [release notes](https://github.com/openises/TicketsCAD/releases) for breaking changes first.

```bash
cd /var/www/newui
sudo git fetch origin
sudo git log HEAD..origin/main --oneline    # see what's coming
```

Read the commits. If nothing concerns you:

```bash
# Snapshot the DB first.
sudo mariadb-dump --single-transaction newui | gzip > /var/backups/newui/pre-upgrade-$(date +%F).sql.gz

# Pull and apply.
sudo git pull origin main
sudo -u www-data php sql/run_migrations.php

# Reload Apache.
sudo systemctl reload apache2

# Smoke test (5 min):
# - Login works
# - Dashboard renders
# - New incident form submits
# - SSE stream is green
```

If anything breaks: `git checkout <previous-commit-sha>`, restore the DB backup, file an issue.

### 2. TLS cert refresh

Certbot auto-renews if it can. Verify:

```bash
sudo certbot certificates
# Each cert should show "VALID" with > 30 days remaining.
```

If not auto-renewing, set up the systemd timer:

```bash
sudo systemctl enable --now certbot.timer
```

If you use an internal CA, schedule a manual cert refresh annually.

### 3. SonarQube scan

If you've set up the SonarQube infrastructure:

```bash
cd /var/www/newui
sonar-scanner.bat \
  -Dsonar.projectKey=ticketscad-newui \
  -Dsonar.host.url=http://your-sonarqube:9000 \
  -Dsonar.token=YOUR_TOKEN
```

Review new findings. Triage CRITICAL/HIGH; document MEDIUM/LOW choices.

### 4. User list housekeeping

- Disable accounts of personnel who've left
- Demote roles where actual duties have changed
- Audit the "Super Admin" role membership — should be ≤ 3 humans

---

## Quarterly — half day

### 1. Disaster recovery drill

Pretend the production VM is gone. Stand up a fresh VM, restore from the most recent off-site backup, point a test DNS name at it, and verify everything works.

```bash
# 1. New VM, run INSTALLATION-CHECKLIST sections 1-7 (system + Apache + MariaDB + code + config + vhost + TLS)
# 2. Skip Section 9 (don't run new migrations yet)
# 3. Restore the production DB backup into the empty database:
sudo mariadb newui < /path/to/latest-prod-backup.sql
# 4. Copy /var/www/keys/ from prod over (encryption keys are NOT in the SQL backup)
# 5. Now run Section 9 to apply any new migrations
sudo -u www-data php sql/run_migrations.php
# 6. Smoke test as in Section 11
```

The whole drill should take ≤ 2 hours after the second time you do it. If it takes longer, fix what was slow before next quarter.

**Document the time it took**, the steps that surprised you, and the gaps in the runbook. Update those gaps before the next drill.

### 2. Password policy review

- Are the lockout thresholds catching real attacks or just frustrating users?
- Is the password rotation interval being respected? Check `password_changed_at` distribution.
- Are backup codes being issued and saved by users? Check `user_tfa.backup_codes_json` non-empty.

### 3. RBAC role audit

Settings → Roles & Permissions. For each role, ask:

- Does any active user actually have this role?
- Are the assigned permissions still appropriate?
- Should the role be split (too much) or merged (too thin)?

Delete unused custom roles. Document the rationale for each kept role.

### 4. Dependency upgrade

Check upstream versions:

```bash
# PHP
php -v

# MariaDB
mariadb --version

# Bootstrap / Leaflet / etc. — see assets/vendor/
ls assets/vendor/
```

Upgrade to latest minor versions in a test environment first. Major-version upgrades (PHP 8.2 → 8.4, MariaDB 10.x → 11.x) need their own dedicated planning session.

---

## Annually

### 1. Encryption-key rotation

The TFA key (`keys/tfa.key`) and RSA keypair (`keys/rsa-*.pem`) should be rotated. The rotation procedure is in [SECURITY-POLICY.md](SECURITY-POLICY.md).

The short version:

1. Generate new keys.
2. Run `tools/tfa-migrate-key.php` to re-encrypt every TFA secret with the new TFA key.
3. RSA keys: re-encrypt every `ENC2:` blob with the new public key.
4. Update `keys/*.pem` and `keys/tfa.key` atomically.
5. Restart Apache.
6. Verify a TFA login works post-rotation.

### 2. CJIS recertification (if applicable)

If your install handles CJI:

- Refresh the CJIS Security Policy mapping in [CJIS-POSTURE.md](CJIS-POSTURE.md) against the current version of the policy.
- Re-attest each control's implementation status.
- Update password / session / lockout / encryption policies if CJIS standards changed.

### 3. External penetration test

Engage a third party to test the install. They should be given:

- A read-only role for some pages
- A dispatcher role
- A super-admin role on a separate, throwaway VM (NEVER prod)
- The OWASP TicketsCAD test plan (no such doc exists yet — see [SECURITY-POLICY.md](SECURITY-POLICY.md) for the closest thing)

Address findings within agreed timeframe; document any accepted-risk items.

### 4. Cryptographic-currency review

Once a year, check that every algorithm and key size in the cryptographic
inventory ([SECURITY-POLICY.md](SECURITY-POLICY.md) §5.1) is still considered
current, against NIST SP 800-57 Part 1 and SP 800-131A. Today that is bcrypt
(cost 12), AES-256-GCM, RSA-2048 with OAEP, and ECDSA P-256 with SHA-256 for the
SBOM signature. Record the review date and outcome even when nothing changes —
"we looked and it was fine" is the evidence an auditor asks for.

Watch specifically for the post-quantum transition: NIST's timeline retires
RSA-2048 and ECDSA P-256 for signatures around 2030-2035. That is not urgent
now, but it is the reason this review exists rather than being a one-off.

---

## Maintainer tasks (not operator tasks)

These belong to whoever publishes TicketsCAD, not to the people running it.
They are here so the schedule is complete.

### Every release — sign the SBOM

A release cannot be cut against a stale SBOM; `tools/release-snapshot.sh` step 0
enforces that. Signing is a separate step and is not enforced, so it is easy to
forget:

```bash
php tools/generate-sbom.php --sign-key=<path to the private key>
php tools/generate-sbom.php --verify        # must print [OK]
```

Commit `SBOM.cdx.json`, `SBOM.txt` and `SBOM.cdx.json.sig` together. The
generator will not re-sign an unchanged SBOM whose signature still verifies, so
running it when nothing changed is harmless.

The private key lives outside the repository and never enters CI — see
[SECURITY-POLICY.md](SECURITY-POLICY.md) §5.3 for its custody. CI verifies with
the public key; it does not sign.

### Every 2 years — rotate the SBOM signing key

Full procedure, including what to do if the key is ever compromised, is in
[SECURITY-POLICY.md](SECURITY-POLICY.md) §5.3. Rotate sooner on suspected
compromise or when the maintainer role changes hands. Publish the new
fingerprint in the release notes; keep the old public key in git history so
previously published releases stay verifiable.

---

## Health metrics worth tracking

If you have a monitoring system (Grafana, Datadog, Prometheus), wire these up:

| Metric | Healthy range | Where to read it |
|---|---|---|
| Apache 5xx rate | < 0.1 / min | access log |
| `api/stream.php` open connections | ≤ active dispatcher count | `ss -tn` filter on port + `php-fpm status` |
| MariaDB slow-query count (>500 ms) | < 10 / hour | `mariadb-slow.log` |
| `audit_log` row count growth | linear with usage | `SELECT COUNT(*) FROM audit_log` periodically |
| `location_reports` row count growth | proportional to active units | same |
| Disk free on `/var` | > 20% | `df -h /var` |
| MariaDB connections in use | < 80% of max_connections | `SHOW GLOBAL STATUS LIKE 'Threads_connected'` |
| Failed login rate | < 5 / min | `audit_log` category=auth, status=failure |

---

## When things go really wrong

Escalation order:

1. **In-app feature unavailable** → [TROUBLESHOOTING.md](TROUBLESHOOTING.md), then [FAQ.md](FAQ.md)
2. **Suspected security incident** → [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md)
3. **Total outage** → [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md)
4. **None of the above resolves it** → file an issue; for security, follow the responsible-disclosure path in [SECURITY-POLICY.md](SECURITY-POLICY.md)

---

## "I'm new and just took this over from someone else" — first-month checklist

- [ ] Read [INDEX.md](INDEX.md), this runbook, and [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- [ ] Verify you can SSH to the VM as a sudo user
- [ ] Verify you can log in to TicketsCAD as the super-admin
- [ ] Confirm `crontab -l -u www-data` shows the expected jobs
- [ ] Confirm a backup ran in the last 24 h
- [ ] Successfully restore a backup to a test VM
- [ ] Walk through the [training curriculum](TRAINING-CURRICULUM.md) end to end
- [ ] Run `php tools/test_all.php` and confirm the result matches the documented expected pass count
- [ ] Read the most recent quarter of `audit_log` entries (10-min scan, just to know what normal looks like)
- [ ] Identify your monitoring + alerting (set up if absent)
- [ ] Schedule your first DR drill
- [ ] Subscribe to GitHub release notifications for the upstream repo

---

This runbook is the single source of truth for "what does the sysadmin do?" If you find yourself doing a recurring task that isn't documented here, add it. Bugs and oversights welcome as patches.
