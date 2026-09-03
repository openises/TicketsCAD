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

# 3. Give the web server the directories it WRITES to — not the whole tree.
#    (chown -R on the whole folder takes .git with it and your next `git pull`
#    fails with "detected dubious ownership". The program files only need to be
#    readable, which they already are.)
sudo chown -R www-data:www-data uploads/ cache/
sudo mkdir -p /var/www/keys && sudo chown www-data:www-data /var/www/keys && sudo chmod 700 /var/www/keys
sudo chown www-data:www-data config.php && sudo chmod 640 config.php

#    Then bootstrap the schema. install_fresh.php auto-imports base_schema.sql +
#    all foundational .sql files + runs every per-feature migration. Idempotent,
#    safe to re-run. Run it AS the web user so anything it creates is owned by it.
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
end-to-end — it covers Apache + certbot + the file-ownership policy and
hardening steps the Quick start above only sketches.

## What's in here

| Path | Purpose |
|------|---------|
| `VERSION` | The single source of truth for the release number. Tracked in git, so `git pull` moves what Help → About reports — **bump this file when cutting a release** (nothing else carries the version). `inc/version.php` reads it; `newui_version()` is what the app calls. |
| `api/` | 60+ JSON endpoints (incidents, members, facilities, SSE stream, file upload, TFA, etc.). Every state-changing endpoint enforces CSRF + RBAC + per-resource access via `inc/access.php`. |
| `assets/` | ES5 JS, Bootstrap 5, Leaflet, GridStack — no build step. |
| `inc/` | Server-side helpers: `db.php`, `functions.php`, `rbac.php`, `auth.php`, `audit.php`, `access.php` (per-resource ACL), `encrypt.php`, `tfa.php`, `sse.php`, channel adapters under `channels/`. |
| `proxy/` | Zello WebSocket proxy. Linux deploy notes in `proxy/INSTALL-LINUX.md` plus a hardened systemd unit example. |
| `services/meshtastic/` | Python bridge for Meshtastic mesh-radio messaging. |
| `tools/` | Operator scripts: `install_fresh.php`, `import-fcc.php` (ULS dump importer), `test_all.php` (test runner), `security_audit_inventory.php`, etc. |
| `tests/` | 13,000+ self-tests across 500+ files (`tests/` plus `tools/test_*.php`; `tools/test_all.php` runs both). Mix of unit + integration; a full run takes several minutes on a workstation. A handful of files need a running web server — skip those with `NEWUI_TEST_NO_HTTP=1`. |
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

## AI features, and what leaves your network

Short version: **your dispatch data is not sent anywhere, there is no telemetry
or update check of any kind, and the one feature that talks to a commercial AI
API ships switched off.** The full accounting is in
[SECURITY.md § What TicketsCAD sends outside your network](SECURITY.md#what-ticketscad-sends-outside-your-network);
here is the summary, because nobody should discover this from the source code.

**Radio AI** (`radio-ai.php`) lets amateur-radio operators ask a question over
DMR and have an answer generated and read back over the air. It is the only
feature that sends content to a hosted large language model —
`https://api.anthropic.com/v1/messages`, model `claude-sonnet-4-6`.

- **Off by default** (`radio_ai_enabled` = `0`), and four things must *all* be
  true before anything leaves: the setting on, an Anthropic API key you create
  by hand at `/etc/ticketscad/anthropic.env`, the listener daemon running, and a
  DMR bridge with speech-to-text feeding it. Miss one and it does nothing.
- **What is sent:** the transcript of the radio transmission that contained the
  wake word, the caller's callsign and DMR ID, up to five prior exchanges within
  30 minutes, and a fixed system prompt. The request also enables Anthropic's
  server-side web search (max three per question).
- **What is never sent:** anything from the CAD side — no incidents, roster,
  patient, facility, location or account data. It reads radio transcripts only.
- A licensed operator approves every generated reply before it is transmitted.

**Speech-to-text runs locally** (Vosk, faster-whisper — in-process, no audio or
transcript leaves the machine). **Text-to-speech is local by default** (Piper);
optional Deepgram and OpenAI-compatible drivers exist but must be created by an
administrator and are not seeded. **No AI model weights ship in this repository.**

**Other outbound traffic.** Map tiles and address lookup
(`nominatim.openstreetmap.org`) are requested by the dispatcher's browser and
are **on by default** — they are the only two things an air-gapped install will
notice failing. Weather alerts, SMS, Slack, webhooks, push, callsign/DMR
lookups, APRS, DMR and Zello are all **unconfigured out of the box**. See
SECURITY.md for the per-service table, the exact content each one sends, and
guidance for fully offline installs.

## Documentation

| Doc | Audience |
|-----|----------|
| [`docs/INSTALL.md`](docs/INSTALL.md) | Administrators bringing up a fresh install |
| [`docs/INSTALLATION-CHECKLIST.md`](docs/INSTALLATION-CHECKLIST.md) | Step-by-step fresh-install checklist |
| [`docs/GETTING-STARTED-FOR-BEGINNERS.md`](docs/GETTING-STARTED-FOR-BEGINNERS.md) | Self-hosters who are not developers |
| [`docs/SWITCH-FROM-ZIP-TO-GIT.md`](docs/SWITCH-FROM-ZIP-TO-GIT.md) | Installed from a ZIP and `git pull` says "not a git repository" |
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

## Credits

TicketsCAD was designed by **[@ashore1008](https://github.com/ashore1008)**,
now **Maintainer Emeritus**, who later transferred stewardship of the project to
the current maintainer. The dispatch model this software is built on is his
work, and v4 is a continuation of that design rather than a departure from it.

Full credits — contributors, and the third-party software TicketsCAD is built
on — are in [AUTHORS.md](AUTHORS.md). Project roles and how decisions get made
are in [GOVERNANCE.md](GOVERNANCE.md).

## License

GPL-2.0 — same as the parent [openises/tickets](https://github.com/openises/tickets) project.
See [LICENSE](LICENSE) for the full text.
