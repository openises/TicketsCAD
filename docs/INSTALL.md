# NewUI v4 — Fresh Install

This is the end-to-end procedure for setting up TicketsCAD NewUI v4 on a fresh host. If you're **upgrading from v3.44** see [UPGRADING-FROM-V3.md](UPGRADING-FROM-V3.md) instead — that path preserves your existing data and runs alongside the legacy install during the cut-over.

Target audience: a sysadmin with `sudo` access on a fresh Debian/Ubuntu VM. Plan on **20–40 minutes** for the base install (PHP + DB + vhost + admin user). Optional features (DMR radio, Meshtastic bridge, etc.) add another 30–60 minutes each — they have their own guides linked at the end.

---

## TL;DR (compressed)

```bash
# As root or with sudo:
apt-get install -y apache2 libapache2-mod-php php php-cli php-mysql \
    php-mbstring php-curl php-gd php-zip php-xml php-bcmath \
    mariadb-server git composer
a2enmod proxy proxy_http proxy_wstunnel rewrite headers

# Database
mysql -e "CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
          CREATE USER 'newui'@'localhost' IDENTIFIED BY 'CHANGE_ME';
          GRANT ALL ON newui.* TO 'newui'@'localhost'; FLUSH PRIVILEGES;"

# Code
git clone https://github.com/openises/TicketsCAD.git /var/www/newui
cd /var/www/newui
composer install --no-dev --optimize-autoloader

# Config
cp config.example.php config.php
sed -i "s/CHANGE_ME_DB_USER/newui/; s/CHANGE_ME_DB_PASS/CHANGE_ME/" config.php
chown -R www-data:www-data /var/www/newui

# Apache vhost
cp apache/newui.conf.example /etc/apache2/sites-available/newui.conf
sed -i "s/ticketscad.example.com/your.host.name/" /etc/apache2/sites-available/newui.conf
a2dissite 000-default
a2ensite newui

# Apply schema (auto-loads sql/base_schema.sql on an empty DB,
# then runs every modernization migration in order). Safe to re-run.
sudo -u www-data php tools/install_fresh.php

# Create the first admin user (prints temp password to stdout — copy it now)
sudo -u www-data php tools/create_admin.php --username=admin --email=you@example.com

# Reload Apache
systemctl reload apache2
```

Open `http://your.host.name/` and log in with the admin credentials the script printed.

> **Note:** previously this TL;DR called `sql/run_migrations.php` directly, which assumes a base schema is already loaded. On a true fresh install with an empty database, that path silently leaves the `user` / `ticket` / `member` tables uncreated (migrations only ALTER existing tables; they don't CREATE the base set). The current `tools/install_fresh.php` auto-detects an empty DB and imports `sql/base_schema.sql` first — that's the safe entry point for both fresh and upgrade installs.

The rest of this doc explains each step and what to do when something doesn't go to plan.

---

## Prerequisites

| Component | Minimum | Recommended |
|---|---|---|
| OS | Debian 11 / Ubuntu 22.04 | Debian 13 / Ubuntu 24.04 |
| PHP | 8.0 | 8.2 or newer |
| Database | MariaDB 10.5 or MySQL 5.7 | MariaDB 10.11 or newer |
| Web server | Apache 2.4 | same |
| Composer | 2.x | latest |
| Disk | 2 GB free | 10+ GB if you'll enable DMR (faster-whisper models, recordings) |
| RAM | 1 GB | 2 GB; 4 GB if running DMR features on the same host |

**Required PHP extensions:** `pdo_mysql`, `mbstring`, `curl`, `gd`, `zip`, `xml`, `bcmath`, `openssl`, `json`, `intl`, `session`. The base `php` meta-package on Debian/Ubuntu pulls most of these; `php-bcmath` and `php-zip` are sometimes separate packages.

Verify after install:
```bash
php -m | grep -iE 'pdo_mysql|mbstring|curl|gd|zip|xml|bcmath|openssl|json|intl'
```

If any are missing, install the matching `php-<ext>` Debian package and reload Apache.

---

## Step 1 — Install OS packages

```bash
sudo apt-get update
sudo apt-get install -y \
    apache2 libapache2-mod-php \
    php php-cli php-mysql php-mbstring php-curl php-gd php-zip \
    php-xml php-bcmath php-intl \
    mariadb-server \
    git composer curl
```

Enable the Apache modules the vhost template uses:

```bash
sudo a2enmod proxy proxy_http proxy_wstunnel rewrite headers
sudo systemctl restart apache2
```

`proxy_wstunnel` is required only if you'll enable the DMR or Zello WebSocket features; enabling it preemptively is harmless.

---

## Step 2 — Create the database

```bash
sudo mysql <<SQL
CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'newui'@'localhost' IDENTIFIED BY 'PICK_A_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON newui.* TO 'newui'@'localhost';
FLUSH PRIVILEGES;
SQL
```

If you're using a remote DB host, replace `'localhost'` with the application host's address or `'%'` for any host (less secure — only do this if you trust the network).

---

## Step 3 — Get the code

```bash
sudo git clone https://github.com/openises/TicketsCAD.git /var/www/newui
cd /var/www/newui
```

Or, if you have a release tarball:
```bash
sudo mkdir -p /var/www/newui
sudo tar -xzf ticketscad-newui-vX.Y.Z.tar.gz -C /var/www/newui --strip-components=1
cd /var/www/newui
```

Install PHP dependencies via Composer:

```bash
sudo composer install --no-dev --optimize-autoloader
```

This populates `/var/www/newui/vendor/`. **Don't skip this** — several core features (Ratchet for WebSockets, PHPMailer for email-attachments-as-files, etc.) live in `vendor/`.

If `composer install` complains about memory, give it more:
```bash
sudo php -d memory_limit=512M /usr/bin/composer install --no-dev --optimize-autoloader
```

Set ownership so Apache (`www-data`) can read and write where it needs to:

```bash
sudo chown -R www-data:www-data /var/www/newui
sudo find /var/www/newui -type d -exec chmod 0755 {} \;
sudo find /var/www/newui -type f -exec chmod 0644 {} \;
sudo chmod -R u+rwX,g+rwX /var/www/newui/cache /var/www/newui/uploads /var/www/newui/backups 2>/dev/null || true
```

---

## Step 4 — Configure `config.php`

```bash
sudo cp config.example.php config.php
sudo vim config.php       # set DB host, user, pass, name, base URL
sudo chown www-data:www-data config.php
sudo chmod 0640 config.php  # readable by Apache + group, not world
```

Minimum values to set:
- `$db_host = 'localhost';`
- `$db_user = 'newui';`
- `$db_pass = '<the password from step 2>';`
- `$db_name = 'newui';`
- `$db_prefix = '';` (leave empty unless you're sharing a database with another app)
- `$base_url = 'https://your.host.name';` (no trailing slash)

---

## Step 5 — Install the Apache vhost

```bash
sudo cp apache/newui.conf.example /etc/apache2/sites-available/newui.conf
sudo vim /etc/apache2/sites-available/newui.conf
```

Change `ServerName ticketscad.example.com` to your actual hostname. If you serve multiple hostnames (e.g. cloudflare tunnel front + internal LAN name), add them as `ServerAlias` lines.

Disable the default site, enable yours, validate, reload:

```bash
sudo a2dissite 000-default
sudo a2ensite newui
sudo apache2ctl configtest
sudo systemctl reload apache2
```

If `configtest` complains about `proxy_wstunnel` not being loaded, re-run `sudo a2enmod proxy proxy_wstunnel` (it's needed for the DMR `/dmr-ws` location — comment that block out if you're not running radio).

---

## Step 6 — Apply database schema + seeds

```bash
cd /var/www/newui
sudo -u www-data php sql/run_migrations.php
```

The orchestrator discovers every `sql/run_*.php`, applies the ones that aren't in the `_migrations` tracking table yet, and stops on the first failure. On a fresh DB you should see ~70 migrations applied.

Re-running is safe — applied migrations are skipped (the runner compares filename + SHA-256 hash).

If anything fails, the runner stops with a diagnostic. Common causes:
- DB user lacks `CREATE` / `ALTER` / `INSERT` privileges (use `GRANT ALL` not just `SELECT, INSERT`)
- Wrong PHP extension missing (`php-mysql` is the most common)
- `_migrations` table partial — recreated automatically on re-run; check the error first

Verify after:
```bash
sudo -u www-data php sql/run_migrations.php --list | tail -3
# should print: Already applied: 70   Pending: 0
```

---

## Step 7 — Create the first admin user

NewUI ships without a default admin account on purpose — every install picks its own credentials. Use the bootstrap tool:

```bash
sudo -u www-data php tools/create_admin.php --username=admin --email=you@example.com
```

The script:
- Generates a random 14-character password
- Hashes it with bcrypt (cost 12) and writes the user row
- Assigns the `Super Admin` role
- Prints the username + temp password to stdout — copy it before the terminal scrolls

If you'd rather set a known password, pass `--password=<pw>` explicitly (less secure — your shell history will contain it).

---

## Step 8 — First login + post-install settings

Open `https://your.host.name/login.php` and sign in with the username + temp password from step 7. You'll be prompted to set a new password.

Once logged in, the **Config** menu in the top nav opens the settings panel. Walk through these once on a fresh install:

| Panel | What to set | Why |
|---|---|---|
| **Org Info** | Org name, time zone, default state | Branding + correct date display |
| **Email (SMTP)** | smtp_host, smtp_port, smtp_user, smtp_pass, email_from | Welcome emails + alerts |
| **Security** | Min password length, session timeout, login lockout threshold | Defense baseline |
| **Map** | Tile provider, default center lat/lng/zoom | Dispatch map starts where you want it |
| **Audio Alerts** | Per-event tone patterns | Optional but useful |

After SMTP is configured, send a test email from the panel's "Send test" button. If it doesn't arrive, check `journalctl -u apache2 -e` for SMTP errors and verify the credentials.

---

## Optional feature add-ons

These have their own setup guides — install only what you need.

| Feature | Guide |
|---|---|
| **DMR radio integration** (BrandMeister bridge, voice TX/RX, dispatch radio widget) | [RADIO-DMR-INSTALL.md](RADIO-DMR-INSTALL.md) |
| **Radio AI** (Claude responses to amateur callers, operator-in-the-loop) | [RADIO-AI-ADMIN-GUIDE.md](RADIO-AI-ADMIN-GUIDE.md) — requires DMR install first |
| **Meshtastic** (LoRa mesh integration) | [MESH-BRIDGE-GUIDE.md](MESH-BRIDGE-GUIDE.md) |
| **APRS** (persistent listener vs polling) | [APRS-LISTENER-SETUP.md](APRS-LISTENER-SETUP.md) |
| **OwnTracks** (member GPS via HTTP) | [OWNTRACKS-CONFIG-PUSH.md](OWNTRACKS-CONFIG-PUSH.md) |
| **Cross-protocol routing** (chat ↔ radio ↔ SMS) | [ROUTING-ENGINE-REFERENCE.md](ROUTING-ENGINE-REFERENCE.md) |
| **i18n / multi-language** | [I18N-GUIDE.md](I18N-GUIDE.md) |
| **RBAC roles + permissions** | [RBAC-GUIDE.md](RBAC-GUIDE.md) |

---

## Verification checklist

You should be able to do all of these on a clean install:

- [ ] `https://your.host.name/` loads the login page (not a 403 / 500)
- [ ] Logging in as admin lands on the dashboard, no pending-migrations banner
- [ ] Config → Email → "Send test" delivers to your inbox
- [ ] Creating a fake incident from the dashboard works
- [ ] `sudo -u www-data php sql/run_migrations.php --list` reports `Pending: 0`
- [ ] `sudo apache2ctl configtest` reports `Syntax OK`
- [ ] `journalctl -u apache2 --since "10 min ago" | grep -iE "error|warn"` is quiet

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| 403 on every page | Apache can't read `/var/www/newui/`, OR `Require all granted` not active | `sudo chown -R www-data:www-data /var/www/newui`; verify the `<Directory>` block in the vhost |
| 500 on every page | PHP can't connect to DB, OR a required extension is missing | `sudo tail /var/log/apache2/newui-error.log`; check `php -m`; verify `config.php` |
| Yellow banner: "Database migrations pending" | New code was deployed but `sql/run_migrations.php` wasn't run | Run the orchestrator; banner clears |
| "No SMTP transport" when sending test email | SMTP settings empty OR `inc/channels/smtp.php` not loaded | Verify settings are in DB (`SELECT * FROM settings WHERE name LIKE 'smtp%'`); restart Apache |
| **Notifications aren't delivered** — browsers subscribe, but no push ever arrives | The `minishlink/web-push` PHP library isn't installed. `vendor/` is gitignored, so a deploy that skipped (or failed) `composer install` has push enabled but nothing to send with. | Run `composer install --no-dev --optimize-autoloader` in the install dir, then reload Apache. Confirm on the **Diagnostics** page (Help → Diagnostics → *Web Push library: loaded*) or **Settings → Web Push** (the red banner clears). **Shared hosting without Composer/SSH:** run `composer install` on any machine that has PHP+Composer, then upload the resulting `vendor/` directory to the install dir. |
| Radio button missing from nav | `action.dmr_receive` permission missing from the user's role, OR DMR not enabled | RBAC → Roles → grant `action.dmr_receive`; see RADIO-DMR-INSTALL.md |
| Map blank | Default tile provider unreachable, OR `def_lat/def_lng` unset | Config → Map → set a known tile provider |

---

## What NOT to do

- **Don't manually edit the `_migrations` table.** Let the runner manage it.
- **Don't `git pull` over an existing install without running migrations afterwards.** Stale schema + new code is the failure mode the migration runner exists to prevent.
- **Don't `chmod 0777` to "fix" a permission issue.** Use `www-data` ownership instead.
- **Don't disable HTTPS by routing browser traffic over plain HTTP from outside the host's LAN.** Sessions, password reset tokens, and 2FA secrets all flow through. The Apache vhost template is plain HTTP because most deployments terminate TLS at a Cloudflare tunnel or upstream proxy; if yours doesn't, wrap the vhost in `*:443` and add Let's Encrypt.
- **Don't skip the admin-user bootstrap step.** If you forget and try to log in with `admin/admin`, NewUI will refuse — there's no default account on purpose.

---

## Related docs

- [UPGRADING-FROM-V3.md](UPGRADING-FROM-V3.md) — upgrade path from v3.44 (preserves data)
- [INSTALLATION-CHECKLIST.md](INSTALLATION-CHECKLIST.md) — printable pre-flight + post-flight checklist
- [SECURITY-POLICY.md](SECURITY-POLICY.md) — what the security model assumes about your deployment
- [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md) — what to back up and how to restore
- [MAINTENANCE-RUNBOOK.md](MAINTENANCE-RUNBOOK.md) — log rotation, cache purging, member purges
