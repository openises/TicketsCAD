# Installation Checklist — TicketsCAD NewUI v4.0

**Audience:** sysadmin setting up a fresh install.
**Goal:** blank Debian 13 or Ubuntu 24.04 VM → working dispatcher login in 60 minutes.
**Format:** checkable steps with expected output. If something doesn't match expected output, see the [TROUBLESHOOTING.md](TROUBLESHOOTING.md) entry that step references.

This checklist assumes you'll serve TicketsCAD over HTTPS in production. If you must use HTTP, also read [the RSA proxy install guide](../proxy/INSTALL-LINUX.md) once you finish here.

---

## Section 0 — Before you start

Confirm you have:

- [ ] A reachable VM with at least **2 vCPU, 4 GB RAM, 40 GB disk** (smaller works but new-incident form will feel laggy on phones).
- [ ] SSH access as a sudo user.
- [ ] A DNS name that resolves to the VM (e.g. `cad.example.org`). HTTPS certificates require a real name; `localhost` works only for evaluation.
- [ ] An email address for Let's Encrypt registration (or your own internal CA).
- [ ] The PHP / DB credentials you intend to use (write them down — you'll need them three times).

Time check: if you have everything above, allow **60 minutes** for a first install. Subsequent installs after you've done one are usually 25–30 minutes.

---

## Section 1 — System packages

Install Apache, PHP 8.2, MariaDB, Python (for the bridges + scripts), and the supporting tooling.

### Debian 13 / Ubuntu 24.04

```bash
# Update package lists
sudo apt-get update

# Core stack
sudo apt-get install -y \
  apache2 \
  mariadb-server \
  php php-cli php-fpm php-mysql php-curl php-xml php-mbstring \
  php-zip php-gd php-bcmath php-intl php-json \
  python3 python3-venv python3-pip \
  git curl unzip ca-certificates \
  certbot python3-certbot-apache
```

- [ ] Apache installed (`apache2 -v` shows ≥ 2.4)
- [ ] PHP 8.2 installed (`php -v` shows `PHP 8.2.x` or newer)
- [ ] MariaDB installed (`mariadb --version` shows ≥ 10.6)
- [ ] Python 3 ≥ 3.10 (`python3 --version`)
- [ ] git, curl, unzip, certbot present

If PHP version is below 8.2, add the Sury repository (Debian) or Ondrej Surý PPA (Ubuntu) and re-install. NewUI requires 8.2 minimum.

**See troubleshooting:** [`apt errors during install`](TROUBLESHOOTING.md#apt-fails)

---

## Section 2 — Enable required Apache + PHP modules

```bash
sudo a2enmod rewrite headers ssl proxy proxy_fcgi setenvif
sudo systemctl restart apache2
```

- [ ] `sudo apachectl -M | grep -E 'rewrite|ssl|proxy_fcgi'` lists all three
- [ ] Apache restart succeeded (`systemctl status apache2` shows active/running)

PHP module health check:

```bash
php -m | grep -E '^(pdo_mysql|mbstring|curl|xml|zip|bcmath|gd|json)$' | sort
```

You should see **all 8** of those names. If any are missing, install `php-<name>` and rerun.

**See troubleshooting:** [`Apache won't start`](TROUBLESHOOTING.md#apache-wont-start)

---

## Section 3 — MariaDB secure setup

Run the interactive secure-installation wizard.

```bash
sudo mysql_secure_installation
```

Recommended answers:

- Enter current root password: **(blank, just press enter)**
- Switch to `unix_socket` auth: **n** (keep password auth so PHP can connect)
- Set root password: **Y** — pick a strong one and store it (NOT in this checklist)
- Remove anonymous users: **Y**
- Disallow remote root login: **Y**
- Remove test database: **Y**
- Reload privileges: **Y**

- [ ] Root password set
- [ ] Anonymous users removed
- [ ] Remote root login disabled

Now create the application database + user. **Substitute a real password.**

```bash
sudo mariadb -u root -p
```

```sql
CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'newui'@'localhost' IDENTIFIED BY 'CHANGE_ME_TO_SOMETHING_STRONG';
GRANT ALL PRIVILEGES ON newui.* TO 'newui'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

- [ ] Database `newui` exists (`sudo mariadb -e "SHOW DATABASES;" | grep newui`)
- [ ] User `newui` can connect (`mariadb -u newui -p newui -e 'SELECT 1;'` returns `1`)

**See troubleshooting:** [`MariaDB strict mode rejecting empty datetime`](TROUBLESHOOTING.md#strict-mode)

---

## Section 4 — Clone the code

```bash
sudo mkdir -p /var/www
cd /var/www
sudo git clone https://github.com/openises/TicketsCAD.git newui
sudo chown -R www-data:www-data newui
```

- [ ] `/var/www/newui/api/auth.php` exists
- [ ] `/var/www/newui/inc/db.php` exists
- [ ] Permissions are `www-data:www-data` recursively (`ls -ld /var/www/newui` shows it)

The repo is large (60 MB+ with history). On constrained bandwidth, use `--depth 1`.

**Note on future `git pull` operations:** because we chown'd everything to `www-data:www-data`, your operator account can no longer write to the tree. Use `sudo` for all subsequent git operations:

```bash
sudo -u www-data git -C /var/www/newui pull --ff-only
```

Doing it as `www-data` (instead of `sudo git pull` as root) keeps file ownership consistent — saves you re-chowning after every pull. If you'd rather use your own user freely, change the ownership pattern to `<your-user>:www-data` with `g+w` group permissions — that lets you write and lets Apache read/execute. Either pattern works; pick one and stick with it.

---

## Section 5 — Configure NewUI

```bash
sudo cp /var/www/newui/config.sample.php /var/www/newui/config.php
sudo nano /var/www/newui/config.php
```

Edit the constants at the top:

```php
$db_host   = 'localhost';
$db_name   = 'newui';
$db_user   = 'newui';
$db_pass   = 'CHANGE_ME_TO_SOMETHING_STRONG';  // same as Section 3
$db_prefix = '';                                 // leave empty for new installs
$base_url  = 'https://cad.example.org';          // YOUR DNS NAME — must be HTTPS in prod
```

Then make the config file readable by Apache **only**:

```bash
sudo chown www-data:www-data /var/www/newui/config.php
sudo chmod 640 /var/www/newui/config.php
```

- [ ] `config.php` exists with your real DB password
- [ ] File mode is `640` (`ls -l /var/www/newui/config.php` shows `-rw-r-----`)
- [ ] `$base_url` matches the DNS name you'll set up TLS for

**Important:** `config.php` is in `.gitignore`. Never commit it. Eric's standing rule: NEVER include `config.php` in deploy tarballs either.

---

## Section 6 — Create the encryption-key directory

NewUI stores per-install secrets (the TFA key, optional RSA keypair) outside the web root.

```bash
sudo mkdir -p /var/www/keys
sudo chown www-data:www-data /var/www/keys
sudo chmod 700 /var/www/keys
```

- [ ] `/var/www/keys` exists and is `700 www-data:www-data`

NewUI auto-generates `/var/www/keys/tfa.key` on first 2FA enrollment if the directory is writable. If you're deploying in a read-only-FS scenario, create `tfa.key` manually:

```bash
sudo -u www-data dd if=/dev/urandom of=/var/www/keys/tfa.key bs=32 count=1
sudo chmod 600 /var/www/keys/tfa.key
```

- [ ] (optional) `tfa.key` exists and is exactly 32 bytes

**See:** [SECURITY-POLICY.md](SECURITY-POLICY.md)

---

## Section 7 — Apache vhost (HTTP only — TLS comes from certbot in Section 8)

Drop in a **port-80-only** vhost. Certbot in Section 8 reads this file, generates a parallel `:443` vhost (`newui-le-ssl.conf`) with the SSL bits + an HTTP→HTTPS redirect, and enables both. Trying to author both `:80` and `:443` blocks here causes certbot to choke ("vhost already serving SSL"). **Substitute your hostname.**

```bash
sudo nano /etc/apache2/sites-available/newui.conf
```

```apache
<VirtualHost *:80>
    ServerName cad.example.org
    DocumentRoot /var/www/newui

    <Directory /var/www/newui>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block direct access to internal directories — certbot will
    # copy these rules into the :443 vhost it generates.
    <DirectoryMatch "/var/www/newui/(inc|specs|tools|sql|tests|keys|services)">
        Require all denied
    </DirectoryMatch>

    # Block the config and any .key files
    <FilesMatch "(config\.php|\.key|\.env)$">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/newui-error.log
    CustomLog ${APACHE_LOG_DIR}/newui-access.log combined
</VirtualHost>
```

Enable + reload:

```bash
sudo a2dissite 000-default.conf
sudo a2ensite newui.conf
sudo apache2ctl configtest    # expect: "Syntax OK"
sudo systemctl reload apache2
```

- [ ] `apache2ctl configtest` says `Syntax OK`
- [ ] `curl -I http://cad.example.org/` returns `200`

**See troubleshooting:** [`Apache vhost not loading`](TROUBLESHOOTING.md#vhost-not-loading)

---

## Section 8 — TLS via Let's Encrypt

```bash
sudo certbot --apache -d cad.example.org --agree-tos -m you@example.org
```

Pick "Redirect HTTP to HTTPS" when prompted.

- [ ] Certificate issued (look for "Congratulations!")
- [ ] `https://cad.example.org/` loads in a browser
- [ ] `curl -I https://cad.example.org/` returns `200` (or `302` to login.php)
- [ ] Browser shows the padlock

**Note:** if your VM isn't reachable from the public Internet, certbot's HTTP-01 challenge will fail. Use DNS-01 or your internal CA instead.

---

## Section 8.5 — Apply the schema (one command)

`tools/install_fresh.php` is the canonical schema-bootstrap entry point. On a fresh, empty database it imports:

1. `sql/base_schema.sql` (~110 legacy tables: `settings`, `user`, `ticket`, `member`, `responder`, `facilities`, etc.)
2. Every foundational `sql/*.sql` file (`rbac.sql` → roles + permissions + user_roles, `audit_log.sql` → newui_audit_log, `comm_identifiers.sql` → comm_modes, `messaging.sql`, `geofences.sql`, `ics_forms.sql`, `tfa.sql`, and ~25 more)
3. Every per-feature `sql/run_*.php` migration via the master runner
4. Then the column-widening + virtual-alias + RBAC-v2 column modifications

On an existing v3.44 upgrade install, it sees the legacy tables and skips straight to the modernization steps. On a re-run, every step's check-then-apply pattern detects what's already done and reports `[SKIP]`.

```bash
cd /var/www/newui
sudo -u www-data php tools/install_fresh.php 2>&1 | tee /tmp/install.log
```

(Substitute `newui` here and in every `sudo mariadb newui ...` example in this checklist with whatever database name you actually created back in Section 3 — e.g., `tickets_ody_prod`, `newui_dev`, etc.)

Expected output ends with something like:

```
=== Result: N applied, M already in place, K failed ===
```

A few `[fail]` lines are expected on a first run — most often `keys/ directory exists` if the encryption-key directory hasn't been created yet (see Section 6) or the running user doesn't have write permission to the parent directory. Those don't block login.

- [ ] `sudo mariadb newui -e "SHOW TABLES;" | wc -l` shows ≥ 200 (most installs land at 210-220)
- [ ] `sudo mariadb newui -e "SHOW TABLES LIKE 'user';"` returns a row (and likewise `ticket`, `roles`, `permissions`, `comm_modes`)
- [ ] `sudo mariadb newui -e "SELECT COUNT(*) FROM settings;"` returns a number

> **Need to wipe and reinstall from scratch?** There's a separate `sql/base_schema_RESET_DESTRUCTIVE.sql` that contains `DROP TABLE IF EXISTS` for each table. It's intentionally not part of the normal install flow. Use it only when rebuilding a test/staging instance you intend to wipe, and only after backing up any data you want to keep:
>
> ```bash
> # ONLY for wipe-and-reinstall — destroys all data in target tables
> sudo mariadb-dump newui > backup-$(date +%F).sql   # back up FIRST
> sudo mariadb newui < sql/base_schema_RESET_DESTRUCTIVE.sql
> ```

**Advanced:** if you specifically want to call only the per-feature migration runner (e.g., after editing a single `sql/run_*.php` and needing to re-apply), `sudo -u www-data php sql/run_migrations.php` still works standalone. `install_fresh.php` invokes it internally; the standalone path is for targeted re-runs only.

---

## Section 9 — (folded into Section 8.5)

Earlier versions of this checklist had a separate Section 9 calling `sql/run_migrations.php` directly. That step is now done automatically inside `tools/install_fresh.php` (Section 8.5). If your local copy still has a Section 9 with `php sql/run_migrations.php`, skip it — it's a no-op after `install_fresh.php` runs.

---

## Section 10 — Create the first admin user

Use the bootstrap CLI script. It hashes the password with bcrypt cost 12, assigns the Super Admin role, sets `must_change_password=1` so the new admin is forced to rotate on first login, and forces 2FA enrollment via the existing login flow:

```bash
cd /var/www/newui
sudo -u www-data php tools/create_admin.php \
    --username=admin \
    --email=you@example.org
```

The script prints the generated 14-character temp password to stdout — **copy it now, the script won't print it again.**

If the username already exists (e.g., upgrading from an old install that had a seeded `admin` user, OR a re-run on a partially-set-up DB), the script refuses unless you pass `--force`. `--force` will rotate the existing password and re-grant Super Admin. Read the standing rule in `CLAUDE.md` ("NEVER reset / change passwords on live user accounts") before using `--force` on a deployed system — for an install that's never been logged into yet, it's the intended path.

```bash
# Only if username already exists and you're sure you want to rotate:
sudo -u www-data php tools/create_admin.php \
    --username=admin \
    --email=you@example.org \
    --force
```

After it prints the temp password, log in:

1. Browse to `https://cad.example.org/login.php`
2. Enter `admin` + the temp password
3. The forced-rotation flow (`must_change_password=1`) sends you straight to Profile to set a permanent password
4. From Profile → Security, enroll 2FA — scan the QR code with an authenticator app (Aegis, 1Password, Authy, Microsoft, Google), save the 8 backup codes
5. Sign out + sign back in with your new password + a fresh TOTP code

- [ ] `tools/create_admin.php` printed `OK: created user 'admin' (id=N)` with a temp password
- [ ] You can log in via `https://cad.example.org/login.php`
- [ ] Rotated to a permanent password on first login
- [ ] 2FA enrolled, 8 backup codes saved somewhere safe (password manager)
- [ ] Dashboard loads with widgets

---

## Section 11 — Smoke test

After login, click through these to confirm baseline health.

| Page | What you should see |
|---|---|
| Dashboard (`/`) | Widgets render: Map (Leaflet shows), Incidents (empty list), Responders, Stats |
| Settings (`/settings.php`) | Sidebar lists categories; clicking each loads a panel |
| New Incident (`/new-incident.php`) | Two-column form with map on the right |
| Roster (`/roster.php`) | Shows your admin user as the sole member |
| Mobile (`/mobile.php`) | Mobile dashboard renders (try in a phone-size browser window) |
| Help (`/help.php`) | In-app help index renders |
| About (`/about.php`) | Project info + version |

Server-side health:

```bash
# SSE stream opens and stays open
curl -N -s -H "Cookie: PHPSESSID=YOUR_SESSION_COOKIE" \
     https://cad.example.org/api/stream.php | head -5
```

You should see `event: ping\ndata: {}\n\n` within a few seconds.

- [ ] Every page in the table renders without errors in the browser console
- [ ] Apache error log (`tail -50 /var/log/apache2/newui-error.log`) is empty or shows only benign info lines
- [ ] PHP error log (`/var/log/php8.2-fpm.log` or `/var/log/apache2/error.log`) is empty

**See troubleshooting:** [`Dashboard widgets are blank`](TROUBLESHOOTING.md#widgets-blank)

---

## Section 12 — Cron for background tasks

NewUI has two timed jobs that need a periodic trigger.

```bash
sudo crontab -e -u www-data
```

Add:

```cron
# Expire time-bound RBAC grants (hourly).
# Sweeps user_roles rows whose expires_at has passed.
5 * * * * php /var/www/newui/tools/expire_grants.php > /dev/null 2>&1

# PAR scheduler tick (every minute).
# Auto-initiates 'scheduled' PAR cycles for active incidents whose cadence
# has elapsed, marks missed acks, posts escalation chat.
* * * * * php /var/www/newui/tools/par_tick.php > /dev/null 2>&1

# Pending-message delivery tick (every minute) for queued broker messages.
* * * * * php /var/www/newui/tools/pending_messages_tick.php > /dev/null 2>&1
```

- [ ] `sudo crontab -l -u www-data` shows the jobs
- [ ] After waiting an hour, `/var/log/apache2/newui-error.log` shows no cron errors

**Backups:** there is currently no all-in-one `backup.php` script. Configure backups separately via `mysqldump` — see [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) for the script template.

**Audit-log + location-reports trim:** dedicated trim scripts are planned but not yet shipped. Until they land, run `DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 365 DAY;` (and similar for `location_reports`) from a periodic SQL job.

Alternative to cron: systemd timers. Templates can be derived from the existing unit files under `services/dvswitch/` and `services/aprs-is/`.

---

## Section 13 — Optional integrations

Each integration has its own admin guide. None are required for basic dispatch.

- **DMR bridge (DVSwitch)** — [DVSWITCH-ADMIN-GUIDE.md](DVSWITCH-ADMIN-GUIDE.md) — requires a separate VM
- **Meshtastic / MeshCore** — [MESH-BRIDGE-GUIDE.md](MESH-BRIDGE-GUIDE.md) — requires a USB LoRa node
- **APRS-IS** — [APRS-LISTENER-SETUP.md](APRS-LISTENER-SETUP.md) — needs an APRS-IS callsign + passcode
- **OwnTracks** — [OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md) — phone-side app install + token mint
- **Zello (Network Radio)** — end-user guide: [ZELLO-SETUP-GUIDE.md](ZELLO-SETUP-GUIDE.md); operator/proxy install: [../proxy/INSTALL-LINUX.md](../proxy/INSTALL-LINUX.md) — needs the PHP WebSocket proxy daemon running on the same host + an Apache `<Location /zello-ws>` reverse-proxy snippet (cPanel/WHM-specific instructions included in the proxy install doc)
- **SMS** — Settings → Integrations → SMS — configure Twilio / BulkVS / Pushbullet credentials
- **Email** — Settings → Integrations → Email — configure SMTP credentials
- **Slack** — Settings → Integrations → Slack — paste a Slack webhook URL

---

## Section 14 — Production hardening

Before you let anyone real log in:

- [ ] **Backups configured.** [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md). Verify a restore on a separate VM before you trust it.
- [ ] **Password policy set** to your org's standard. Settings → Identity & Security → Password Policy.
- [ ] **2FA required** for at least admin + dispatcher roles. Settings → Identity & Security → Two-Factor Authentication → Required Roles.
- [ ] **Session timeout** set to a sane value (default 24 h is fine for most; CJIS environments want 30 min). Settings → Identity & Security → Session Timeouts.
- [ ] **Lockout policy** set. 5 attempts / 5 min window / 15 min lockout is the default.
- [ ] **Trusted CIDRs** configured if you use office LAN as a long-session bypass. Same panel.
- [ ] **Audit log retention** set to your org's standard (default 365 days; CJIS requires 1 year minimum).
- [ ] **PAR cadence** picked per incident type (Settings → Operations → Incident Types).
- [ ] **Org branding** set (Settings → Welcome → Branding).
- [ ] **Run the security review checklist** in [SECURITY-POLICY.md](SECURITY-POLICY.md).
- [ ] **DNS, TLS, firewall** all set per your org's network policy.
- [ ] **CJIS check** (if applicable) — review [CJIS-POSTURE.md](CJIS-POSTURE.md).

---

## Section 15 — You're done

If every checkbox in this document is ticked:

- Production-ready single-server install ✅
- TLS, RBAC, 2FA, lockout, audit log, backup, schema migrations ✅
- Ready to invite your first batch of real users

Next steps:

1. Walk through the [training curriculum](TRAINING-CURRICULUM.md) yourself to learn the dispatcher workflow.
2. Send new users to [`/quick-start.php`](../quick-start.php) — the built-in onboarding wizard.
3. Subscribe to the upgrade-notification webhook (Settings → System → Updates) so you know when patches ship.
4. Schedule your first quarterly disaster-recovery drill from [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md).

---

## Reverse install — uninstall instructions

If you need to wipe a test install:

```bash
sudo systemctl stop apache2
sudo a2dissite newui.conf
sudo rm -rf /var/www/newui /var/www/keys
sudo certbot delete --cert-name cad.example.org
sudo mariadb -e "DROP DATABASE newui; DROP USER 'newui'@'localhost'; FLUSH PRIVILEGES;"
sudo systemctl start apache2
```

This is irreversible. Take a backup first if there's anything you might want.

---

## Got stuck?

- **Symptom-based help:** [TROUBLESHOOTING.md](TROUBLESHOOTING.md)
- **Common questions:** [FAQ.md](FAQ.md)
- **Architecture questions:** [GLOSSARY.md](GLOSSARY.md) + [/help.php](../help.php)
- **File an issue:** [GitHub Issues](https://github.com/openises/TicketsCAD/issues)

This checklist is maintained alongside the code. If a step is wrong or unclear, file a doc patch — broken docs are bugs.
