# TicketsCAD v4 — NewUI

A modern dashboard rewrite of [TicketsCAD](https://github.com/openises/tickets),
the Computer-Aided Dispatch system for volunteer fire departments,
ARES/RACES amateur-radio groups, CERT teams, small EMS agencies, and
campus security.

NewUI v4 keeps the legacy MariaDB schema (so existing tickets installs can
upgrade in place) but replaces the framesets-and-jQuery-1.4 UI with a
keyboard-first Bootstrap 5 + GridStack + Leaflet stack on PHP 8.2.

```
Status:         v4.0 — open source, active development
License:        GPL-2.0 (matches openises/tickets)
PHP:            8.2 (compatibility tested 8.0–8.4)
DB:             MariaDB 10.4+ / MySQL 8.0
Browser target: evergreen + ES5 fallbacks
```

## Quick start

### Option A — Docker (fastest)

One command brings up the application **and** its database — no need to install
PHP, Composer, or MariaDB yourself. Full guide: **[docs/DOCKER.md](docs/DOCKER.md)**.

```bash
git clone https://github.com/openises/TicketsCAD.git ticketscad && cd ticketscad
cp .env.example .env        # edit the DB passwords
docker compose up -d --build
```

Then open `http://localhost:8081`. The admin password is printed to the log
(`docker compose logs app | grep -i password`); first login prompts you to
change it.

### Option B — install directly on a host

**Prerequisites:** a Debian/Ubuntu host with Apache, PHP 8.2+, MariaDB 10.4+, Composer, and git. If you don't have those, install them first:

```bash
sudo apt-get update && sudo apt-get install -y \
    apache2 libapache2-mod-php php php-cli php-mysql \
    php-mbstring php-curl php-gd php-zip php-xml php-bcmath \
    mariadb-server git composer
sudo a2enmod rewrite headers
```

Clone the repository, then install the PHP dependencies and bootstrap the schema:

```bash
# 1. Clone + install vendor deps
git clone https://github.com/openises/TicketsCAD.git /var/www/newui
cd /var/www/newui
composer install --no-dev    # or `php composer.phar install --no-dev`

# 2. Create the database, copy the config template, edit credentials
sudo mariadb -e "CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
                 CREATE USER 'newui'@'localhost' IDENTIFIED BY 'CHANGE-ME';
                 GRANT ALL ON newui.* TO 'newui'@'localhost';"
sudo cp config.example.php config.php
sudo $EDITOR config.php    # set $db_pass to the password you used above

# 3. Hand ownership to the web-server user, then bootstrap the schema.
#    install_fresh.php auto-imports base_schema.sql + all foundational .sql
#    files + runs every per-feature migration. Idempotent, safe to re-run.
sudo chown -R www-data:www-data /var/www/newui
sudo -u www-data php tools/install_fresh.php

# 4. Create the first admin user. Save the printed temp password —
#    it's the only time it's shown.
sudo -u www-data php tools/create_admin.php --username=admin --email=you@example.org

# 5. Apache vhost so the install is reachable at http://your-host/
#    (skip if you're configuring Apache by hand or using a different webserver)
sudo tee /etc/apache2/sites-available/newui.conf > /dev/null <<'VHOST'
<VirtualHost *:80>
    DocumentRoot /var/www/newui
    <Directory /var/www/newui>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
VHOST
sudo a2dissite 000-default
sudo a2ensite newui
sudo systemctl reload apache2
```

**Step 6 — log in for the first time:**

Open `http://<your-host>/login.php` in a browser. Use the username you set in step 4 (`admin` by default) and the temp password the script printed. On first login you'll be forced to set a permanent password, then prompted to enroll 2FA from Profile → Security.

If the page loads but looks unstyled (raw text, no card, no theme), Apache isn't serving `assets/vendor/bootstrap/*` — check that the directory exists under your project root and that the vhost's `<Directory>` block allows access. The default Debian Apache config will serve them fine; if you're on a different distro or a hardened setup, double-check.

For a production deployment with TLS, hardened vhost, encryption-key directory, and full smoke tests, follow [`docs/INSTALLATION-CHECKLIST.md`](docs/INSTALLATION-CHECKLIST.md) end-to-end — that's the long-form version of the above.

The `tools/install_fresh.php` script detects an empty database, imports
`sql/base_schema.sql` (~110 tables) automatically, then runs the column-
widening + feature migrations. On an upgrade install it sees the existing
tables and skips straight to migrations. Safe to re-run.

For a production deployment with TLS, vhost, encryption keys, and
hardening, follow [`docs/INSTALLATION-CHECKLIST.md`](docs/INSTALLATION-CHECKLIST.md)
end-to-end — it covers Apache + certbot + the encryption-key directory
that the Quick start above omits.

## What's in here

| Path | Purpose |
|------|---------|
| `api/` | 60+ JSON endpoints (incidents, members, facilities, SSE stream, file upload, TFA, etc.). Every state-changing endpoint enforces CSRF + RBAC + per-resource access via `inc/access.php`. |
| `assets/` | ES5 JS, Bootstrap 5, Leaflet, GridStack — no build step. |
| `inc/` | Server-side helpers: `db.php`, `functions.php`, `rbac.php`, `auth.php`, `audit.php`, `access.php` (per-resource ACL), `encrypt.php`, `tfa.php`, `sse.php`, channel adapters under `channels/`. |
| `proxy/` | Zello WebSocket proxy. Linux deploy notes in `proxy/INSTALL-LINUX.md` plus a hardened systemd unit example. |
| `services/meshtastic/` | Python bridge for Meshtastic mesh-radio messaging. |
| `tools/` | Operator scripts: `install_fresh.php`, `import-fcc.php` (ULS dump importer), `test_all.php` (test runner), `security_audit_inventory.php`, etc. |
| `tests/` | 1023 self-tests across 41 files. Mix of unit + integration; runs in <60 s on a workstation. |
| `sql/` | `base_schema.sql` (~110 tables, auto-imported by `tools/install_fresh.php`) plus per-feature migration scripts. |
| `docs/` | Operator + admin guides. Start with [`INSTALLATION-CHECKLIST.md`](docs/INSTALLATION-CHECKLIST.md) for production installs; [`INSTALL.md`](docs/INSTALL.md) is a leaner walkthrough. |

## Security

A multi-session security audit ran in April 2026 against all 94 API
endpoints. Every CRITICAL and HIGH finding has been remediated with a
regression test. The project's security posture, controls, key management, and
CJIS notes are documented in [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md);
report a concern via [SECURITY.md](SECURITY.md).

Highlights of the post-audit hardening:

- **Per-resource access (F-004/5/6)** — every detail endpoint that takes an
  ID parameter (`incident-detail`, `responder-detail`, `location-history`,
  `upload`, `file-upload`) calls `user_can_access_entity()` before reading,
  matching the `allocates`-based group filter the list endpoints use.
- **CSRF on every POST/PUT/DELETE** — verified via
  `tests/test_security_csrf_bundle.php` and the per-finding test files.
- **File upload RCE chain closed (F-001)** — MIME from `finfo_file`,
  extension allowlist via `MIME_TO_EXT` map, canonical extension keyed off
  the verified MIME, `uploads/.htaccess` blocks PHP execution at the
  Apache level.
- **SSE per-user filtering (F-007)** — `sse_events` carries
  `visibility_scope` + `visibility_ids`; `stream.php` builds a per-user
  WHERE clause. Helpers `sse_publish_for_incident/responder/user/admin`
  enforce scope at publish time.
- **Field encryption (RSA + AES-GCM)** with `keys/` outside the webroot.
- **TFA** with TOTP, backup codes, trusted-network CIDR.

Run the security tests in isolation:

```bash
php tests/test_security_f001_upload.php
php tests/test_security_f002_feed.php
php tests/test_security_f003_fileupload.php
php tests/test_security_f004_idor.php
php tests/test_security_f007_sse_visibility.php
php tests/test_security_csrf_bundle.php
php tests/test_pre_release_fixes.php
```

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## Documentation

| Doc | Audience |
|-----|----------|
| [`docs/INSTALL.md`](docs/INSTALL.md) | Administrators bringing up a fresh install |
| [`docs/INSTALLATION-CHECKLIST.md`](docs/INSTALLATION-CHECKLIST.md) | Step-by-step fresh-install checklist |
| [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) | Developer-oriented walkthrough |
| [`docs/NEWUI-USER-GUIDE.md`](docs/NEWUI-USER-GUIDE.md) | End-user / dispatcher walkthrough |
| [`docs/BACKUP-RECOVERY-RUNBOOK.md`](docs/BACKUP-RECOVERY-RUNBOOK.md) | Backup, recovery + incident response |
| [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md) | Security posture, keys, CJIS |
| [`docs/TRACCAR-SETUP.md`](docs/TRACCAR-SETUP.md) | Location tracking — OwnTracks / Traccar / OpenGTS |

## Conventions

- **PHP**: procedural, no framework. PDO prepared statements via
  `db_query()` / `db_fetch_*()`. Suppress `display_errors` at the top of
  every API endpoint so PHP warnings can't corrupt the JSON.
- **JS**: ES5 (no `let`/`const`/arrows), each file an IIFE, plain
  `fetch()` for AJAX, no jQuery.
- **CSS**: Bootstrap 5 utility classes first; per-page sheets when needed;
  light + dark themes via Bootstrap CSS variables.
- **Tests**: `test_*.php` files in `tools/` and `tests/`. Each prints a
  trailing `=== Results: N passed, M failed ===` line that the runner
  greps. Add a test for every CRITICAL/HIGH finding fixed.

## Contributing

Pull requests are welcome. Before opening a PR:

1. Install the QA git hooks once per clone: `bash tools/install-git-hooks.sh`.
   Every commit then runs php-lint on staged files plus the two audit
   gates (`tools/schema_audit.php` — SQL vs. real schema; and
   `tools/api_contract_audit.php` — JS reads vs. API-emitted keys).
2. Run `php tools/test_all.php` — the full suite must pass.
   Without a running Apache, use `NEWUI_TEST_NO_HTTP=1 php tools/test_all.php`
   (skips the `@requires-http` integration files, same mode CI uses).
3. Every push also runs `.github/workflows/qa.yml`: a true fresh install
   (empty MariaDB → `config.example.php` → `tools/install_fresh.php` →
   admin + demo seed) followed by the full suite and both audits. A red
   check on your commit means a fresh install is broken — fix before merge.
4. If you touch an API endpoint, run the schema + API↔JS contract audits
   (`php tools/schema_audit.php`, `php tools/api_contract_audit.php`) and add tests.
5. Follow [SECURITY.md](SECURITY.md) for any vulnerability fixes.

## License

GPL-2.0 — same as the parent [openises/tickets](https://github.com/openises/tickets) project.
See [LICENSE](LICENSE) for the full text.
