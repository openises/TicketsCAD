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
| **Annually** | Encryption-key rotation, CJIS recert, full penetration test |

---

## Continuous — automated

These run on cron or systemd timers. Set up in [INSTALLATION-CHECKLIST.md § Section 12](INSTALLATION-CHECKLIST.md#section-12--cron-for-background-tasks). Verify they're firing:

```bash
sudo crontab -l -u www-data
# OR
sudo systemctl list-timers --all | grep -i newui
```

| Job | Cadence | What it does | If it stops |
|---|---|---|---|
| `tools/expire_grants.php` | hourly | Removes time-bound role grants past `expires_at` | Users keep elevated access past intended window |
| `tools/par_tick.php` | every minute | Fires PAR cycles for active incidents per cadence; marks missed acks; posts escalation chat | PAR doesn't fire; manual PAR still works |
| `tools/pending_messages_tick.php` | every minute | Delivers queued broker messages | Outbound notifications stall |
| audit-log trim *(planned)* | daily 03:00 | Will drop `audit_log` rows past retention — no script yet; run as SQL: `DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 365 DAY;` | DB bloat over time |
| location-reports trim *(planned)* | daily 03:30 | Same idea for `location_reports`; same workaround | DB bloat; map slowness |
| backup *(planned)* | daily 02:00 | No all-in-one script yet — use `mysqldump` via cron per [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) | No fresh backup if a disaster hits |
| `certbot renew` | twice daily (auto) | Renews Let's Encrypt cert | TLS cert expires; site breaks |

**Verify the audit-trim is doing its job:**

```sql
SELECT MIN(created_at), MAX(created_at), COUNT(*) FROM audit_log;
```

The MIN should be roughly `today - retention_days`. If MIN is older, the trim job isn't running.

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

Settings → Audit & Compliance → Audit Log. Filter by:

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
