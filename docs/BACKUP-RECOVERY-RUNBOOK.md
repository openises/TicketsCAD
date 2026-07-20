# Backup + Recovery — Runbook

**Audience:** sysadmin.
**Goal:** never lose more than 24 h of data; recover from a full-VM loss within 2 hours.
**Cross-refs:** [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md) · [MAINTENANCE-RUNBOOK.md](MAINTENANCE-RUNBOOK.md) · [SECURITY-POLICY.md](SECURITY-POLICY.md)

---

## What gets backed up

TicketsCAD's state lives in three places. A complete backup covers all three.

### 1. The MariaDB database (the most important)

`mysqldump` of the `newui` database. This contains:

- Incidents (`ticket`, `action`, `assigns`)
- People (`user`, `member`, `responder`)
- Configuration (`settings`, `roles`, `permissions`, `role_permissions`)
- Audit + activity history (`audit_log`, `log`)
- Communication state (`chat_messages`, `messages`, `routing_log`)
- All bridge state (`dmr_channels`, `dmr_messages`, `mesh_nodes`, `mesh_packet_log`)
- Map data (`mmarkup`, `geofences`, `places`)
- Everything you've ever entered

**Size:** typically 100 MB – 5 GB depending on usage. Audit log and location_reports dominate.

### 2. Encryption keys (mandatory — without them, restored data is unusable)

In `/var/www/keys/` (or wherever `FE_KEYS_DIR` is configured):

- `tfa.key` — 32 bytes; encrypts all TOTP secrets + backup codes. Without it: every user has to re-enroll 2FA.
- `rsa-public.pem` / `rsa-private.pem` (if you use HTTP field encryption) — without them: every encrypted form value is unreadable.

**Size:** tiny (a few KB). Critical.

### 3. Uploaded files

In `/var/www/newui/uploads/` (or whatever `UPLOAD_DIR` is set to):

- File attachments on incidents
- ICS form exports
- Custom audio alert tones
- Backup tarballs themselves (if you store them in-tree, which we recommend against)

**Size:** varies wildly. Could be GB if your install attaches photos to incidents.

### What's NOT backed up

- The OS (`/etc`, installed packages) — re-provision from scratch via [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md)
- Apache config / TLS certs — re-issue from Let's Encrypt or your CA
- The TicketsCAD code itself — re-clone from git
- Cron jobs — re-add per [INSTALLATION-CHECKLIST.md § Section 12](INSTALLATION-CHECKLIST.md#section-12--cron-for-background-tasks)

The principle: TicketsCAD-the-software is reproducible from git. Only YOUR data + keys + uploads need backup.

---

## Backup methods

Three supported paths. Pick one (or combine).

> **Note on tooling:** TicketsCAD does not currently ship a packaged backup CLI — there is no `tools/backup.php` you can run. Until one lands, the patterns below use standard `mysqldump` + `tar`. They produce the same artefact a packaged tool would.

### Method A — In-app database backup (admin UI)

For ad-hoc snapshots before risky changes:

1. Settings → System → **Backup** → **Backup now**.
2. The browser downloads a `.sql.gz` of the database.
3. Move it somewhere safe (NOT the same VM).

**Caveat:** the in-app backup captures only the database. Encryption keys (`/var/www/keys/`) and uploads (`uploads/`) are NOT included — you must back those up separately on the filesystem.

### Method B — Filesystem snapshot (recommended for full coverage)

Captures the database + keys + uploads in one archive.

```bash
DATE=$(date +%F-%H%M)
DEST=/var/backups/newui/tcad-${DATE}.tar.gz
mkdir -p /var/backups/newui

# 1. Dump the DB (consistent without blocking writes).
sudo mariadb-dump --single-transaction --routines --triggers newui \
  > /tmp/newui-${DATE}.sql

# 2. Pack DB + keys + uploads into a single tarball.
sudo tar czf "$DEST" \
    -C /tmp newui-${DATE}.sql \
    -C / var/www/keys \
    -C /var/www/newui uploads

# 3. Clean up the loose dump.
sudo rm /tmp/newui-${DATE}.sql

ls -lh "$DEST"
```

The archive contains:

```
newui-YYYY-MM-DD-HHMM.sql       <- database dump
var/www/keys/                    <- tfa.key + rsa-*.pem (CRITICAL for restore)
uploads/                         <- file attachments
```

### Method C — Cron (automated, recommended)

Add to root crontab:

```cron
# Daily backup at 02:00. Rotates; keeps 30 days locally.
0 2 * * * /usr/local/bin/tcad-backup.sh > /var/log/newui-backup.log 2>&1
```

Where `/usr/local/bin/tcad-backup.sh` is:

```bash
#!/bin/bash
set -e

DATE=$(date +%F-%H%M)
BACKUP_DIR=/var/backups/newui
DEST="${BACKUP_DIR}/tcad-auto-${DATE}.tar.gz"
mkdir -p "$BACKUP_DIR"

# Dump the database consistently
mariadb-dump --single-transaction --routines --triggers newui \
  > "/tmp/newui-${DATE}.sql"

# Pack DB + keys + uploads
tar czf "$DEST" \
    -C /tmp "newui-${DATE}.sql" \
    -C / var/www/keys \
    -C /var/www/newui uploads

rm "/tmp/newui-${DATE}.sql"

# Rotate: keep last 30 days locally
find "${BACKUP_DIR}" -name 'tcad-auto-*.tar.gz' -mtime +30 -delete

# Mirror to off-site (REQUIRED — local-only backup is no backup)
# Pick ONE of these:

# Option 1: AWS S3
# aws s3 cp "${DEST}" s3://my-bucket/tcad-backups/

# Option 2: rclone to any cloud
# rclone copy "${DEST}" remote:tcad-backups/

# Option 3: rsync over SSH
# rsync -avz "${DEST}" backup-host:/backups/tcad/

# Option 4: scp to a NAS
# scp "${DEST}" nas.local:/volume1/backups/tcad/

# Verify the mirror succeeded — fail loudly if not
# (your script here)

echo "[OK] backup completed: $(ls -lh ${DEST} | awk '{print $5}')"
```

**Mark executable:**

```bash
sudo chmod +x /usr/local/bin/tcad-backup.sh
sudo touch /var/log/newui-backup.log
sudo chown www-data:www-data /var/log/newui-backup.log
```

**A backup that isn't off-site isn't a backup.** Pick one of the mirror options above and configure it.

---

## Off-site checklist

Without an off-site copy, a single VM-loss event wipes everything. Configure ONE of these:

- [ ] AWS S3 / Backblaze B2 / Wasabi (cheap object storage; enable versioning + lifecycle)
- [ ] A separate VM in a different datacenter
- [ ] An on-prem NAS with daily snapshots
- [ ] (Encrypted) USB drive at a different physical location, rotated weekly

The 3-2-1 rule: **3** copies, on **2** different media, **1** off-site. Production deployments should never operate with fewer than two copies.

---

## Backup verification

A backup that hasn't been verified is wishful thinking.

### Weekly: confirm the file exists and is non-trivial

```bash
ls -lh /var/backups/newui/tcad-auto-$(date -d yesterday +%F)*
```

Expect a `.tar.gz` between 10 MB and several GB. Empty or under 1 MB → silent failure; investigate.

### Weekly: spot-check the contents

```bash
LATEST=$(ls -t /var/backups/newui/tcad-auto-*.tar.gz | head -1)
tar -tzf "$LATEST" | head -20
# Expect to see: db.sql, keys/, uploads/ (if included), README.txt
```

### Weekly: full restore drill on a separate VM

The only way to know your backups work is to actually restore one. See [Recovery](#recovery) below.

---

## Recovery

### Scenario 1: I dropped a single table by accident

Most efficient — restore just that table.

```bash
# 1. Extract the SQL dump from the latest backup
LATEST=$(ls -t /var/backups/newui/tcad-auto-*.tar.gz | head -1)
mkdir /tmp/restore-$$
tar -xzf "$LATEST" -C /tmp/restore-$$/ db.sql

# 2. Get the CREATE + INSERTs for the table
awk '/^-- Table structure for table `incidents`/,/UNLOCK TABLES;/' \
    /tmp/restore-$$/db.sql > /tmp/restore-incidents.sql

# 3. (Optional) review:
less /tmp/restore-incidents.sql

# 4. Apply (will fail if the table still exists — DROP first if so)
sudo mariadb newui < /tmp/restore-incidents.sql

# 5. Clean up
rm -rf /tmp/restore-$$/ /tmp/restore-incidents.sql
```

### Scenario 2: My DB got corrupted but VM is fine

Restore the whole DB on the same VM.

```bash
# 1. Stop incoming writes
sudo systemctl stop apache2

# 2. Backup the current (corrupted) DB to a side file first — you might need it
sudo mariadb-dump newui > /tmp/corrupted-$(date +%F-%H%M).sql

# 3. Drop + recreate empty
sudo mariadb -e "DROP DATABASE newui; CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 4. Restore the latest known-good backup
LATEST=$(ls -t /var/backups/newui/tcad-auto-*.tar.gz | head -1)
mkdir /tmp/restore-$$
tar -xzf "$LATEST" -C /tmp/restore-$$/
sudo mariadb newui < /tmp/restore-$$/db.sql

# 5. Restart Apache
sudo systemctl start apache2

# 6. Smoke test
curl -I https://cad.example.org/login.php
# Expect: 200 OK

# 7. Clean up
rm -rf /tmp/restore-$$/
```

**Data loss:** anything after the most recent backup's snapshot time.

### Scenario 3: Full VM loss (DR drill / real)

The runbook. Allow 2 hours the first time; 30-60 min once practiced.

#### Step 1 — Provision a new VM

Follow [INSTALLATION-CHECKLIST.md § Sections 1-7](INSTALLATION-CHECKLIST.md) (system packages → Apache modules → MariaDB setup → clone the code → config → keys dir → Apache vhost).

**Don't run Sections 8-10 yet** (no TLS, no migrations, no admin user). We're going to restore over the empty DB instead.

#### Step 2 — Get the most recent off-site backup

```bash
# Pick the most recent (from S3, the NAS, etc.)
# For S3:
aws s3 ls s3://my-bucket/tcad-backups/ | sort | tail -3
aws s3 cp s3://my-bucket/tcad-backups/tcad-auto-2026-06-15.tar.gz /tmp/
```

#### Step 3 — Restore the keys + uploads + DB

```bash
cd /tmp
tar -xzf tcad-auto-2026-06-15.tar.gz

# Keys (critical for TFA + field encryption to work)
sudo cp keys/* /var/www/keys/
sudo chown -R www-data:www-data /var/www/keys
sudo chmod 700 /var/www/keys
sudo chmod 600 /var/www/keys/*

# Uploads (if present in backup)
if [ -d uploads ]; then
  sudo cp -r uploads/* /var/www/newui/uploads/
  sudo chown -R www-data:www-data /var/www/newui/uploads
fi

# Database
sudo mariadb -e "DROP DATABASE IF EXISTS newui; CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb newui < db.sql
```

#### Step 4 — Apply any new migrations

Backups are SQL dumps from when they were taken. If your TicketsCAD code is newer than the backup, run migrations to catch up:

```bash
cd /var/www/newui
sudo -u www-data php sql/run_migrations.php
```

#### Step 5 — TLS + DNS

Either reuse the old hostname (point DNS at the new VM's IP, run certbot) or use a new hostname (update `config.php`'s `$base_url`, run certbot).

```bash
sudo certbot --apache -d cad.example.org --agree-tos -m you@example.org
```

#### Step 6 — Restart everything

```bash
sudo systemctl restart apache2 mariadb
```

#### Step 7 — Smoke test

```bash
curl -I https://cad.example.org/login.php
```

Then log in via browser as the super-admin. Verify:

- [ ] Dashboard renders with widgets
- [ ] Incident list shows historical data
- [ ] Map shows historical units (positions may be stale if no location updates since restore — that's expected)
- [ ] User list intact
- [ ] 2FA works (the TFA secrets decrypt because keys/tfa.key was restored)
- [ ] At least one historical chat message displays

If anything's off, you didn't restore something. Check the tar contents.

#### Step 8 — Resume normal operation

- [ ] Cron jobs re-installed
- [ ] Backup script re-configured (and verified — the new VM should be backing up by tomorrow night)
- [ ] Bridges (DVSwitch, Meshtastic, APRS) re-configured to point at the new install URL
- [ ] OwnTracks devices point at the new URL if hostname changed
- [ ] Webhook subscribers tested
- [ ] Users notified that the system is back

#### Step 9 — Post-mortem

- [ ] Document what went wrong (what triggered the loss)
- [ ] Document the recovery (what worked, what was slow)
- [ ] Update this runbook with anything that surprised you
- [ ] Schedule the next DR drill before you forget

---

## Encryption key escrow

The TFA key (`/var/www/keys/tfa.key`) is the lynchpin. Without it, TFA enrollments become unrestorable scrambled bytes.

### Recommendations

1. **Don't lose it.** Treat it like a database password.
2. **Back it up** WITH the database, in the same encrypted backup (so if you have access to one, you have access to the other).
3. **Also escrow it separately** to handle the case where the backup is intact but the key file got corrupted: a 1Password vault, a secure USB in a safe, a sealed envelope in a colleague's filing cabinet.
4. **Document the escrow location** in your sysadmin runbook — NOT in source control, NOT in this file.
5. **Test escrow recovery** every quarter as part of the DR drill.

If the key file is lost AND no escrow copy exists:

- Every user has to re-enroll 2FA from scratch
- Backup codes are scrambled (won't decrypt; users need new ones via admin reset)
- HTTP field-encryption blobs (`ENC2:` prefix) are permanently undecryptable

See [SECURITY-POLICY.md](SECURITY-POLICY.md) for full details.

---

## Special considerations

### DMR call audio archives

If you enabled `DMR_AUDIO_RECORD=1` for the DVSwitch bridge, audio files accumulate in `/var/log/ticketscad-dvswitch/audio/` on `dvswitch-01`. These are NOT included in the standard TicketsCAD backup script — they're on a different VM.

Cover them with their own backup:

```bash
# On dvswitch-01:
0 3 * * * rsync -az /var/log/ticketscad-dvswitch/audio/ backup-host:/backups/tcad-dmr-audio/
```

### `audit_log` size growth

Audit log grows linearly with usage and stays for the retention period (default 365 days). Backup size grows accordingly.

If backups get unwieldy, you can split the audit log into its own dump:

```bash
sudo mariadb-dump newui --tables audit_log > audit-only-$(date +%F).sql.gz
# And exclude it from the main dump in the next run.
```

This complicates restore (you have to apply both files), but keeps the routine backup snappy.

### `location_reports` size

Same shape — grows with active units. Default retention 90 days. Trim more aggressively if storage matters.

### Hot vs. cold backups

The default `mysqldump --single-transaction` is a **hot** backup — consistent without locking. Safe to run on production during business hours.

If you want a true **cold** snapshot (no writes during backup): stop Apache, dump, restart Apache. Costs you ~30 s of downtime per backup. Usually not worth it.

---

## Common pitfalls

| Mistake | Consequence | Mitigation |
|---|---|---|
| Backup file stored only on the same VM | Single VM loss = total loss | Off-site mirror (Method C) |
| Encryption keys not backed up | TFA breaks on restore | Include `keys/` in the backup AND escrow separately |
| Never tested restore | Backup might not actually work | Quarterly DR drill |
| Restoring on top of existing schema | "Table already exists" errors | `DROP DATABASE; CREATE DATABASE;` first |
| Mixed-version restore (new app, old DB) | Schema mismatches | Run `sql/run_migrations.php` after restore |
| `config.php` committed to backup | Credentials leak if backup leaks | Backup script writes `config.php` separately, encrypted |
| Forgot to point bridges at new URL | DMR/mesh/APRS bridges break silently | Post-restore checklist (Section 8 above) |
| Lost the TFA key WITH the DB | Every user has to re-enroll | Triple-redundant key escrow |

---

## RTO / RPO targets

- **RPO** (recovery point objective — how much data can you afford to lose): **24 h** with daily backups; **1 h** with hourly DB dumps.
- **RTO** (recovery time objective — how long can downtime last): **2 h** with practised DR; **30 min** with a hot-standby replica.

If your org needs sub-hour RPO/RTO, the daily-tarball approach won't cut it — you need MariaDB binlog streaming to a hot standby. That's an architecture decision beyond this runbook.

---

## Quarterly DR drill checklist

Do this every quarter. Block off 4 hours.

- [ ] Spin up a fresh VM (different region/datacenter than production if possible)
- [ ] Pull the most recent off-site backup
- [ ] Run through Scenario 3 above
- [ ] Time each step; compare to last quarter's times
- [ ] Document any step that was confusing or out-of-date in this runbook
- [ ] Tear down the test VM
- [ ] File a follow-up issue for any gap discovered

After three drills, the second time is faster than the first, and the third faster than the second. After that, plateau — but you'll have caught every gap.

---

## Where the code lives

| What | Path |
|---|---|
| Backup script | None shipped — use `mariadb-dump` + `tar` per Method B/C above (a packaged `tools/backup.php` is planned) |
| Restore helper | None shipped — use `mariadb` + `tar -x` per the recovery scenarios above |
| Admin UI | Settings → System → Backup (in [`settings.php`](../settings.php)) |
| Cron script template | Above (Method C) |
| `tools/upgrade/ROLLBACK.md` | [Upgrade rollback procedure](../tools/upgrade/ROLLBACK.md) |
| Encryption key lifecycle | [SECURITY-POLICY.md](SECURITY-POLICY.md) |

---

Your data is your responsibility. This runbook gets you the technical procedures; the organisational discipline of running them on schedule is yours.
