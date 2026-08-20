# After Every Update (Self-Hosted Installs)

This checklist exists because three classes of post-update breakage keep
biting self-hosted installs that deploy with `git pull` (or a ZIP download):

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

3. **Stale `vendor/`.** It is gitignored, so `git pull` never touches it.
   A fix that ships as a Composer dependency bump or a patch applied via a
   Composer hook (see step 2) is silently absent until `composer install`
   or `composer update` actually runs — reported by Ron Jones (@rjonesbsink)
   in [#31][i31], where the [#8][i8] Web Push key-generation fix had shipped
   in the release but the send-path half of it lived in a vendored file that
   only a Composer run would touch.

TicketsCAD NewUI **detects and warns about the first two — it never
auto-fixes**. If you manage your file permissions your own way, keep doing
that; the health check will tell you if something is actually broken. The
third has no detector yet; running step 2 every time is the mitigation.

[i8]: https://github.com/openises/TicketsCAD/issues/8

## The checklist

Run these after every `git pull`, in order. Commands are **examples to
adapt** — substitute your actual web server user (`www-data` on
Debian/Ubuntu Apache, `apache` on RHEL, your php-fpm pool user, etc.) and
your actual install path.

### 1. Fix ownership and permissions (example — adapt to your policy)

> **Do not `chown -R` the whole install directory.** Earlier versions of this
> checklist said to. It is wrong twice over: it hands `.git` to the web server,
> so your next `git pull` stops with
> `fatal: detected dubious ownership in repository at '/var/www/newui'`
> (git ≥ 2.35.2, CVE-2022-24765); and it is unnecessary, because the web server
> only needs to **read** the program files — ownership has nothing to do with
> that, mode `644`/`755` does.

**Who needs to own what**

| Path | Owner | Why |
|---|---|---|
| the install directory itself | **the user who runs `git`** | whoever owns `.git` is who can `git pull`. Pick that user once and keep it. |
| `uploads/` | web server user | attachments + map overlays (`api/upload.php`) |
| `cache/` | web server user | weather tiles, Zello audio |
| `../backups/` — e.g. `/var/www/backups` | **you**, group = web server user, mode `2770` | written by BOTH `php tools/backup_run.php` on the CLI (as you) and Settings → Backup / Maintenance and the cron entry (as the web user). Give it away entirely and the CLI backup fails with `could not write archive`. **One level ABOVE the install directory since v4.2.3**, like `../keys/`: inside the tree, `GET /backups/<archive>.zip` handed a complete database dump to anyone who asked. If you have archives in the old `backups/` folder, move them — see below. |
| the keys directory — `/var/www/keys` on Linux, `C:\ProgramData\TicketsCAD\keys` on Windows | web server user, mode `700` | 2FA + RSA field-encryption keys. git never touches it, so it is not part of a post-pull fix-up — see INSTALLATION-CHECKLIST.md Section 6. **The exact path is platform-dependent — see the note below**, and Settings → System Health prints the one this install is actually using. |
| the geocode cache and tile cache directories — `../geocode-cache` / `../tile-cache`, e.g. `/var/www/geocode-cache` | web server user, mode `775` | address-lookup and map-tile caching (`inc/geocode.php`, `inc/tile-proxy.php`). Like `../backups/`, these live ABOVE the install directory and do not exist on a fresh clone. Unlike backups, only the web server writes to them — **do not** apply the shared/setgid treatment here. If `sudo php tools/fix-permissions.php` (below) is never run before the first geocode lookup or map pan, whichever process reaches the directory first becomes its owner — including a CLI health-check run over SSH — which is exactly the bug that made this row necessary (2026-08-19). Settings → System Health reports whether each one can actually be written to, not merely whether it exists. |

> **Why the keys directory is not simply "one level up" (v4.2.4, GHSA-3jmh-c6f6-64jc)**
>
> Until v4.2.4 this table said the keys live one level above the install
> directory *"on purpose … so the private key is not HTTP-reachable"*, and
> `FE_KEYS_DIR` was `NEWUI_ROOT . '/../keys'` on every platform.
>
> That reasoning is true on Linux and **backwards on Windows**. A Linux install
> at `/var/www/newui` gives `/var/www`, which no server publishes. An IIS site
> at `C:\inetpub\wwwroot\TicketsV4` gives `C:\inetpub\wwwroot\keys` — *inside*
> Default Web Site's root, bound to port 80. XAMPP is the same shape
> (`C:\xampp\htdocs\newui` → `C:\xampp\htdocs`, the DocumentRoot). The directory
> was confirmed to be served.
>
> So the default is per-platform: a sibling of the install directory on Linux
> and macOS (unchanged — correct there), `%ProgramData%\TicketsCAD\keys` on
> Windows. **Keys you already have are not moved**: if the old location still
> holds `private.pem`, `public.pem` or `tfa.key`, that is where this install
> keeps reading them, and Settings → System Health tells you if that directory is
> published. Nothing is relocated for you — losing `tfa.key` un-enrols every
> 2FA user at once, so that decision is yours, and the Status page prints the
> exact commands.
>
> To put the keys anywhere you like, add `define('FE_KEYS_DIR', '/your/path');`
> to `config.php`. That define is honoured as of v4.2.4; before then the
> application's own `define()` always won.

```bash
# SHORTCUT: `sudo php tools/fix-permissions.php` does all of the below for you.
# It works out which account serves this install rather than assuming www-data,
# repairs only the directories the application writes to, leaves a directory
# that already works exactly as it is, and refuses to touch anything that could
# carry a .git with it. `--check` reports without changing anything.
#
# EXAMPLES ONLY — substitute YOUR web server user: www-data (Debian/Ubuntu),
# apache (RHEL/Rocky/Fedora), _www (macOS), or your php-fpm pool user.
cd /var/www/newui

# The two directories PHP writes to inside the tree:
sudo chown -R www-data:www-data uploads/ cache/

# The backup directory lives ABOVE the install directory (v4.2.3+), so that no
# web server configuration can serve a database dump. It does not exist on a
# fresh clone — create it and SHARE it, so both you and the web server can write:
mkdir -p ../backups
sudo chown -R "$(id -un)":www-data ../backups/
sudo chmod 2770 ../backups/       # setgid: new archives inherit the group

# Upgrading an install that already has archives in the old in-webroot folder?
# Move them, then make sure nothing is left behind:
[ -d backups ] && mv backups/ticketscad-* ../backups/ 2>/dev/null; ls backups

# Program files only need to be READABLE (this needs no chown at all):
sudo find . -path ./.git -prune -o -type d -exec chmod 755 {} \;
sudo find . -path ./.git -prune -o -type f -exec chmod 644 {} \;
```

If your tree is *already* owned by the web server (older installs followed the
whole-tree advice), you have two consistent options — pick one:

```bash
# a) keep the web server as the owner, and run git as it:
sudo -u www-data git -C /var/www/newui pull --ff-only

# b) take the tree back, and give the web server only what it writes:
sudo chown -R "$(id -un)":www-data /var/www/newui
sudo chmod -R g+rX /var/www/newui
sudo chown -R www-data:www-data /var/www/newui/uploads /var/www/newui/cache
```

If you manage permissions your own way (ACLs, a deploy user in the
web group, setgid directories, ...), **keep doing that** — skip this
step. The health check (step 4) will tell you if something is broken.

### 2. Update Composer dependencies

```bash
composer install --no-dev --optimize-autoloader
```

`vendor/` is gitignored, so `git pull` never touches it on its own — a
dependency version bump, a new package, or a **security patch applied to a
vendored file via a Composer hook** (for example the `minishlink/web-push`
patch from [#31][i31], which only reapplies itself when this command runs)
all land only if this step runs. Skipping it after a `git pull` is exactly
the shape of "the code changed, the fix reportedly shipped, and the bug is
still there" — the checklist existed for the two failure modes above before
this, but a `git pull`-only upgrade path missing this step is the same
disease as either of them: a change genuinely reached your disk and never
took effect. Idempotent — safe to run every time, even when nothing
actually changed. If you upgrade from a downloaded ZIP instead of `git
pull`, run this too; it's not git-specific, only `vendor/` being untracked
is what makes it necessary.

[i31]: https://github.com/openises/TicketsCAD/issues/31

### 3. Reload the web server (clears opcache)

Always do this after a pull — it is cheap and it is the only reliable way
to make sure the new PHP code is actually what's running:

```bash
sudo systemctl reload apache2
# or, if you serve PHP through php-fpm:
sudo systemctl reload php8.2-fpm
```

A *reload* is graceful (no dropped connections); you do not need a full
restart.

### 4. Apply database migrations

```bash
php sql/run_migrations.php
```

Idempotent — safe to run every time. Admins also get an in-app banner
when migrations are pending.

### 5. Run the health check

```bash
php tools/check-health.php
```

Prints `[OK]` / `[WARN]` / `[CRIT]` / `[UNKN]` lines and, for every real
problem, the suggested fix command (echoed, never executed). Exit codes:
`0` all ok, `1` warnings and/or undetermined checks, `2` critical.

**Whose access is reported:** the question that matters is whether the
*web server* can write these directories, not whether you can. The tool
works out which account serves the application and answers for that
account, so running it over SSH as yourself gives the same verdict as the
browser. It reports at the top which account it used and how it decided —
a running `apache2`/`nginx`/`php-fpm` worker, the ownership of the
install's own runtime directories, or the server's configuration files.

Until v4.2.3 it answered for whoever invoked it, so an SSH run reported
`5 critical` and told you to `chown` directories that were already
correct, while **Settings → System Health** said OK about the very same
install. If you have been ignoring this tool's output for that reason,
it is worth another look.

**`[UNKN]` is not a finding.** It means the account could not be
established, so no verdict was reached — deliberately, rather than
guessing one. To resolve it, tell the install which account it is:

```php
// config.php — substitute your own web server account
define('NEWUI_WEB_USER', 'www-data');   // apache · nginx · http · your hosting account
```

The same report is also available in a browser, where it runs as the web
server itself and therefore never needs to work anything out:

- **API:** `GET /api/health-check.php` (admin-gated JSON), or
- **UI:** **Settings → System Health** (`status.php#health`) — the
  "File & Code Health" card shows the directories table, any unreadable
  files, the opcache configuration, and the stale-code detector.

The CLI's unreadable-files scan reflects the invoking user by design — it
catches root-owned `0600`/`0700` files left behind by a root `git pull`.

## What the health check looks at

| Check | What it catches | Severity |
|---|---|---|
| Required-writable dirs (`uploads/`, `uploads/overlays/`, `cache/`, `cache/weather/`, `cache/zello-audio/`), judged for the web server account | Uploads, map overlays, weather tiles, and Zello voice recordings failing to write | Missing-but-creatable = warn (the app creates these on demand); exists-but-unwritable = **critical**; web server account undeterminable = `unknown` |
| Unreadable files in `assets/js/` and `api/`, plus the 20 most-recently-modified `.php`/`.js` files | New files from a root `git pull` that the web server cannot read (silent 404s) | **critical** |
| opcache `validate_timestamps=0` | Code changes on disk not taking effect until reload | warn |
| `inc/health-check.php`'s compiled build stamp vs the same file on disk | The server executing stale opcache'd code right now | **critical** — reload apache2/php-fpm |
| A `define('NEWUI_VERSION', …)` left in `config.php` | A dead line from before the version moved to the tracked `VERSION` file — the reported version is correct either way | informational |

When any **critical** issue exists, admins see a red banner on every page
linking to `status.php#health`.

## If something is flagged

The tool tells you the suggested command for each finding, for example:

```
sudo chown -R www-data:www-data /var/www/newui/uploads   # adjust 'www-data' to YOUR web server user
sudo systemctl reload apache2   # or: sudo systemctl reload php8.2-fpm
```

Nothing is ever executed for you. Review, adapt, run, then re-check.

## 6. Confirm the version actually moved

Open the user menu (top right) → **About**. The version there is read from the
git-tracked `VERSION` file, so after a successful `git pull` **it changes on its
own** — no config edit needed. If it did not change, the pull did not land or
the web server is still serving stale code (step 2).

> Installs created before 2026-07 have a `define('NEWUI_VERSION', …)` in their
> `config.php`. It is ignored now and the About page is still correct; the health
> check mentions the line so you can delete it. (Before that change the version
> lived *only* in `config.php` — which git never touches — so About showed the
> install-day version forever, and "check About to prove the update worked" was
> advice that could never work.)
