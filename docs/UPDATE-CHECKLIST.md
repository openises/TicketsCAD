# After Every Update (Self-Hosted Installs)

This checklist exists because two classes of post-update breakage keep
biting self-hosted installs that deploy with `git pull`:

1. **File ownership/permissions.** When you run `git pull` as root (or any
   user other than the web server user), *new* files and directories the
   pull creates are owned by that user. Depending on your umask, the web
   server may not be able to read them. The symptom is brutal and silent:
   a new JS file or API endpoint simply 404s. If the unreadable file is
   something like `assets/js/event-bus.js`, ALL real-time updates die with
   no visible error.

2. **Stale opcache.** PHP's opcache caches compiled code. If
   `opcache.validate_timestamps=0` (common on tuned production servers),
   the running server keeps executing the OLD code after a pull until
   apache2/php-fpm is reloaded — so fixes "don't take effect" even though
   the files on disk are correct.

TicketsCAD NewUI **detects and warns about both — it never auto-fixes**.
If you manage your file permissions your own way, keep doing that; the
health check will tell you if something is actually broken.

## The checklist

Run these after every `git pull`, in order. Commands are **examples to
adapt** — substitute your actual web server user (`www-data` on
Debian/Ubuntu Apache, `apache` on RHEL, your php-fpm pool user, etc.) and
your actual install path.

### 1. Fix ownership and permissions (example — adapt to your policy)

```bash
# EXAMPLES ONLY — adapt user/group/path to your server:
sudo chown -R www-data:www-data /var/www/newui
sudo find /var/www/newui -type d -exec chmod 755 {} \;
sudo find /var/www/newui -type f -exec chmod 644 {} \;
```

If you manage permissions your own way (ACLs, a deploy user in the
web group, setgid directories, ...), **keep doing that** — skip this
step. The health check (step 4) will tell you if something is broken.

### 2. Reload the web server (clears opcache)

Always do this after a pull — it is cheap and it is the only reliable way
to make sure the new PHP code is actually what's running:

```bash
sudo systemctl reload apache2
# or, if you serve PHP through php-fpm:
sudo systemctl reload php8.2-fpm
```

A *reload* is graceful (no dropped connections); you do not need a full
restart.

### 3. Apply database migrations

```bash
php sql/run_migrations.php
```

Idempotent — safe to run every time. Admins also get an in-app banner
when migrations are pending.

### 4. Run the health check

```bash
php tools/check-health.php
```

Prints `[OK]` / `[WARN]` / `[CRIT]` lines and, for every problem, the
suggested fix command (echoed, never executed). Exit codes: `0` all ok,
`1` warnings, `2` critical.

**CLI caveat:** on the command line, writability answers reflect the
*CLI* user, not the web server user. The **authoritative** check runs as
the web user:

- **API:** `GET /api/health-check.php` (admin-gated JSON), or
- **UI:** **Settings → System Health** (`status.php#health`) — the
  "File & Code Health" card shows the directories table, any unreadable
  files, the opcache configuration, and the stale-code detector.

The CLI's unreadable-files scan is still valid — it catches root-owned
`0600`/`0700` files left behind by a root `git pull`.

## What the health check looks at

| Check | What it catches | Severity |
|---|---|---|
| Required-writable dirs (`uploads/`, `uploads/overlays/`, `cache/`, `cache/weather/`, `cache/zello-audio/`) | Uploads, map overlays, weather tiles, and Zello voice recordings failing to write | Missing-but-creatable = warn; exists-but-unwritable = **critical** |
| Unreadable files in `assets/js/` and `api/`, plus the 20 most-recently-modified `.php`/`.js` files | New files from a root `git pull` that the web server cannot read (silent 404s) | **critical** |
| opcache `validate_timestamps=0` | Code changes on disk not taking effect until reload | warn |
| Running `NEWUI_VERSION` (compiled) vs the version string on disk | The server executing stale opcache'd code right now | **critical** — reload apache2/php-fpm |

When any **critical** issue exists, admins see a red banner on every page
linking to `status.php#health`.

## If something is flagged

The tool tells you the suggested command for each finding, for example:

```
sudo chown -R www-data:www-data /var/www/newui/uploads   # adjust 'www-data' to YOUR web server user
sudo systemctl reload apache2   # or: sudo systemctl reload php8.2-fpm
```

Nothing is ever executed for you. Review, adapt, run, then re-check.
