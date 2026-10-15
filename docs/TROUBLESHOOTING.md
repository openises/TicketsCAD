# Troubleshooting Playbook

**Audience:** sysadmins.
**Format:** symptom → diagnostic steps → fix.

If your symptom isn't here, check [FAQ.md](FAQ.md), then [/help.php → Troubleshooting](../help.php#troubleshooting), then file an issue.

---

## Quick-find by symptom

- [Install + setup](#install--setup)
  - [apt-get install fails](#apt-fails)
  - [Apache won't start](#apache-wont-start)
  - [Apache vhost not loading](#vhost-not-loading)
  - [Migration failed with column-type mismatch](#migration-column-mismatch)
  - [MariaDB strict mode rejecting empty datetime](#strict-mode)
  - [PHP can't connect to MariaDB](#php-cant-connect-mariadb)
  - [MySQL / MariaDB won't start or won't stay running](#mysql-wont-start)
  - ["Save failed" / schema is behind the code](#schema-out-of-date)
  - [`git pull` says "not a git repository" (ZIP install)](#not-a-git-repository)
- [Login + auth](#login--auth)
  - [Locked out of admin account](#admin-lockout)
  - [Forgot all backup codes + lost authenticator](#lost-tfa)
  - [2FA enrollment loop](#tfa-enroll-loop)
  - [Forced-password-change cycle never ends](#pw-change-loop)
  - [Session keeps logging me out after 30 seconds](#session-too-short)
  - [Trusted device cookie ignored](#remember-device-ignored)
- [Dashboard + UI](#dashboard--ui)
  - [App looks empty / "fresh install" after a crash or power loss](#app-empty-after-crash)
  - [Dashboard widgets are blank](#widgets-blank)
  - [Map shows blank tiles](#map-blank-tiles)
  - [SSE indicator stuck gray (no real-time)](#sse-gray)
  - [JSON parse error when calling an API](#json-parse-error)
  - [Dropdowns missing options](#dropdown-empty)
  - [Theme stuck on dark / light despite the toggle](#theme-stuck)
- [Incidents + dispatch](#incidents--dispatch)
  - [New incident form submits but no incident appears](#new-incident-vanished)
  - [Status dropdown reverts to Available after every change](#status-revert)
  - [PAR cycle never fires](#par-never-fires)
- [Communications](#communications)
  - [Chat messages don't persist (refresh wipes them)](#chat-doesnt-persist)
  - [OwnTracks returns 403 Forbidden](#owntracks-403)
  - [DMR bridge `/health` returns "bad bearer"](#dmr-bad-bearer)
  - [Mesh bridge stays disconnected](#mesh-disconnected)
  - [APRS map shows "0 stations" and claims the listener is down](#aprs-zero-stations)
  - [Webhook deliveries all show "failed"](#webhook-failed)
- [Mobile + PWA](#mobile--pwa)
  - [PWA "Add to Home Screen" missing on Android](#pwa-missing)
  - [Mobile dashboard won't scroll past the navbar](#mobile-scroll-stuck)
  - [Clock-in toggle does nothing](#clockin-noop)
- [Backups + recovery](#backups--recovery)
  - [Backup file is huge / takes forever](#backup-too-big)
  - [Restore fails with "table already exists"](#restore-table-exists)
- [Performance](#performance)
  - [Pages slow to load (>3 s)](#slow-pages)
  - [SSE stream uses 100% CPU](#sse-cpu)

---

## Install + setup

### apt-fails

**Symptom:** `apt-get install php8.2` returns `E: Unable to locate package php8.2`.

**Cause:** your Debian/Ubuntu repository doesn't carry PHP 8.2 — typical on older Debian 11 / Ubuntu 22.04 LTS.

**Fix (Debian):**

```bash
sudo apt-get install -y apt-transport-https lsb-release ca-certificates wget
sudo wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | \
  sudo tee /etc/apt/sources.list.d/php.list
sudo apt-get update
sudo apt-get install -y php8.2 php8.2-cli php8.2-fpm php8.2-mysql ...
```

**Fix (Ubuntu):**

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y php8.2 ...
```

---

### apache-wont-start

**Symptom:** `sudo systemctl start apache2` returns "failed", journal shows `Address already in use`.

**Cause:** another service (nginx, an old Apache instance, a Docker container) is bound to port 80 or 443.

**Diagnostic:**

```bash
sudo ss -tlnp | grep -E ':80|:443'
```

**Fix:**

- If nginx is running, decide which web server you want and stop the other.
- If a Docker container is using the port, either stop the container or move TicketsCAD's vhost to a non-conflicting port (then proxy from the front-line server).

**Symptom:** Apache starts but immediately exits with `AH00526: Syntax error on line N of /etc/apache2/sites-enabled/newui.conf`.

**Fix:**

```bash
sudo apache2ctl configtest
```

Read the line number it points at. Common mistakes: missing closing `</VirtualHost>`, typo in module name (`RewriteEngine` not `Rewrite engine`), referenced module not enabled (`a2enmod rewrite`).

---

### vhost-not-loading

**Symptom:** `curl -I https://cad.example.org/` returns the Apache default page or `404 Not Found`, not the TicketsCAD login.

**Diagnostic:**

```bash
sudo apache2ctl -S | grep -i newui
```

If your vhost isn't listed, it wasn't enabled.

**Fix:**

```bash
sudo a2dissite 000-default.conf
sudo a2ensite newui.conf
sudo systemctl reload apache2
```

If the vhost is listed but the page is still wrong, check `ServerName` matches your DNS name AND your client is hitting that name (`curl -v` shows what name was sent).

---

### migration-column-mismatch

**Symptom:** `php sql/run_migrations.php` prints `[ERROR] migration X failed: Column 'foo' already exists` or similar.

**Cause:** the migration tried to `ALTER TABLE ADD COLUMN foo` but the column already exists from a prior partial run.

**Fix:**

All NewUI migrations are *meant* to be idempotent — this error usually means an old hand-edit left the schema in a partial state. Open the failing migration script under `sql/`, find the offending `ALTER`, and either run a defensive `IF NOT EXISTS` form manually or skip the column.

For a fresh install where you don't care about data, the simplest recovery is:

```bash
sudo mariadb -e "DROP DATABASE newui; CREATE DATABASE newui CHARACTER SET utf8mb4;"
cd /var/www/newui && sudo -u www-data php sql/run_migrations.php
```

For a live install with data, **always take a backup first**, then run the failing migration step manually in a transaction so you can roll back if needed.

---

### not-a-git-repository

**Symptom:** updating with `git pull` fails immediately:

```
fatal: not a git repository (or any parent up to mount point /)
Stopping at filesystem boundary (GIT_DISCOVERY_ACROSS_FILESYSTEM not set).
```

**Cause:** you installed by downloading a **ZIP** rather than cloning. A ZIP is
just the files; it does not contain the hidden `.git` folder that records where
the code came from, so there is nothing for git to pull from. Our update videos
say "skip ahead to the update step" — that only applies to a cloned install, and
that is our documentation's mistake, not yours.

**Fix:** you do not need to reinstall and you do not lose anything. Adopt the
folder you already have as a git checkout — four commands, and your
`config.php`, `keys/`, `uploads/` and database are all left alone:

```bash
git init
git remote add origin https://github.com/openises/TicketsCAD.git
git fetch origin
git checkout -f -B main origin/main
```

Then finish the update as normal:

```bash
php sql/run_migrations.php
php tools/check-schema.php
```

Docker installs also need `docker compose up -d --build`.

The full walkthrough — including what to back up first and what to do if a later
pull complains — is in
**[docs/SWITCH-FROM-ZIP-TO-GIT.md](SWITCH-FROM-ZIP-TO-GIT.md)**.

---

### schema-out-of-date

**Symptom:** a screen loads but will not save. The error is a bare
`Save failed: HTTP 400`, or names a column —
`Unknown column 'nims_resource_type' in 'INSERT INTO'`. TicketsCAD may show a
banner reading **"Database schema does not match this version."**

**Cause:** the database STRUCTURE is behind the code. Your records are intact —
a table is missing a column that this version writes to. This happens when:

- a table was dropped and re-created by hand during crash recovery
- the database was restored from a backup taken on an older version
- the files were updated (`git pull`) but the migrations were not run
- a `CREATE TABLE` was copied from somewhere and omitted some columns

**Why the migration runner used to miss this:** it recorded which migration
*scripts had run*, not whether their schema still existed. Drop a table that a
migration created, and every script still showed as applied — so the runner
reported "0 pending" while the app was broken. As of v4.1.1 it checks the
database itself and re-applies automatically when the two disagree.

**Fix:**

```bash
php tools/check-schema.php
```

That reports exactly which tables and columns are missing and changes nothing.
Then:

```bash
php tools/check-schema.php --repair
```

That re-applies the schema migrations and re-checks in a fresh process. The
migrations are idempotent — they add what is absent and never delete data. If
you want to be certain first, take a backup: `php tools/backup_run.php --force`
(the `--force` matters — without it the command only runs if a backup is *due*,
and otherwise prints "No backup due yet" and exits successfully).

`php sql/run_migrations.php` now performs the same check and repairs itself, so
either command works.

**If it still reports missing columns after a repair,** that is a gap in
TicketsCAD rather than something you did wrong — no migration covers that
column. Please open an issue with the output:
<https://github.com/openises/TicketsCAD/issues>

**Where to see it before it bites:** Status → **File & Code Health** shows a
"Database schema vs this version" row on every install.

---

### strict-mode

**Symptom:** Apache error log shows `SQLSTATE[HY000]: General error: 1364 Field 'foo' doesn't have a default value`, or `Incorrect datetime value: '' for column 'bar'`.

**Cause:** MariaDB 10.6+ defaults to STRICT_TRANS_TABLES + ONLY_FULL_GROUP_BY in `sql_mode`, but legacy TicketsCAD code occasionally writes `''` (empty string) for DATETIME columns or relies on column defaults that don't exist.

**Fix:** TicketsCAD already disables these modes per-session in `inc/db.php` (`SET SESSION sql_mode = '...'`). If you still see the error, you're either bypassing that connection helper (don't) or the column is genuinely missing a default (run the self-healing INSERT path in [`api/members.php`](../api/members.php) or add the default manually).

**Verify the per-session sql_mode is taking effect:**

```sql
SELECT @@SESSION.sql_mode;
```

Expected: a list that does NOT include `ONLY_FULL_GROUP_BY` or `STRICT_TRANS_TABLES`.

---

### php-cant-connect-mariadb

**Symptom:** Browser shows `Database connection failed: SQLSTATE[HY000] [2002] No such file or directory`.

**Cause:** PHP can't find the MariaDB Unix socket. Either MariaDB isn't running, or it's at a non-default socket path.

**Diagnostic:**

```bash
sudo systemctl status mariadb
ls -l /var/run/mysqld/mysqld.sock /var/lib/mysql/mysql.sock 2>/dev/null
```

**Fix A — MariaDB not running:**

```bash
sudo systemctl start mariadb
```

**Fix B — Socket is at a non-default path.** Add to `config.php`:

```php
$db_socket = '/var/lib/mysql/mysql.sock';   // or wherever ls -l found it
```

…and update `inc/db.php`'s PDO DSN to include `unix_socket=$db_socket`.

**Fix C — Use TCP instead of socket:**

```php
$db_host = '127.0.0.1';  // forces TCP
```

---

### mysql-wont-start

**Symptom:** The XAMPP Control Panel says **"Error: MySQL shutdown unexpectedly"** — MySQL starts and then stops on its own, over and over, or won't start at all. TicketsCAD then can't reach the database (and on older versions the dashboard just spins). This looks like TicketsCAD is broken, but it isn't: **this is MySQL/MariaDB itself failing to start**, a Windows/XAMPP-level problem. Your data is still on disk in `C:\xampp\mysql\data` — nothing you entered is deleted by this.

**First, read the log the right way.** XAMPP Control Panel → MySQL → **Logs** → `mysql_error.log`. What matters is *how far it gets before it stops*:

- If you see `InnoDB: Database page corruption`, `Plugin 'InnoDB' registration ... failed`, or it **never** prints `InnoDB: ... started; log sequence number ...`, that's an **InnoDB** problem — follow [App looks empty / fresh install after a crash](#app-empty-after-crash), which covers `innodb_force_recovery`.
- If InnoDB **does** start cleanly (`InnoDB: 10.x.x started; log sequence number ...`, no corruption errors) but MySQL still dies — often right after `Server socket created on IP: '::'` and before `ready for connections` — then the problem is **not your data and not InnoDB**. **Do not** use `innodb_force_recovery` for this; it's the wrong tool. Work through the causes below instead.

**Cause 1 — a stuck `mysqld.exe` process** (the most common "won't stay running"). A previous crash can leave a MySQL process running that holds the data files, so the next start can't take over and quits.
- Open Task Manager (`Ctrl+Shift+Esc`) → **Details** tab → look for `mysqld.exe`. If one is listed, select it → **End task**. Then click **Start** in the XAMPP Control Panel again.

**Cause 2 — port 3306 is already in use** (a second MySQL install, Skype, or a stale process holding the port). In a Command Prompt:
```
netstat -ano | findstr :3306
```
If anything is listed, note the PID in the last column and end that process in Task Manager (or change the MySQL port in XAMPP).

**Cause 3 — antivirus is locking or quarantining the data files.** Windows Defender or third-party AV scanning `C:\xampp\mysql\data` can lock a file mid-startup and kill `mysqld` with **no error** in the MySQL log. Open **Windows Security → Virus & threat protection → Protection history** and look for anything mentioning `mysqld.exe` or the xampp folder. If you find it, add an **exclusion** for the whole `C:\xampp` folder.

**Get the real error MySQL is hiding.** The XAMPP log often leaves out the actual fatal line. Run the server by hand to see it printed live:
```
cd C:\xampp\mysql\bin
mysqld.exe --console
```
The last 10–15 lines it prints are the ones that matter (press `Ctrl+C` to stop it). **Windows Event Viewer** is another place the real cause appears: Start → *Event Viewer* → Windows Logs → **Application** → look for an Error from `mysqld.exe` (it names the "faulting module").

**Cause 4 — a corrupt system table in the `mysql` database** (very common after a power loss). If `mysqld --console` ends with something like:
```
[ERROR] Table '.\mysql\db' is marked as crashed and last (automatic?) repair failed
[ERROR] Fatal error: Can't open and lock privilege tables: ...
```
then one of MySQL's internal permission tables is damaged. This is **not your data** — the `mysql` database holds permission bookkeeping; your records are in your own database on the InnoDB engine, which the log above shows starting cleanly. Repair the named table **offline** (MySQL must be stopped, which it already is).

First, check which storage engine that table uses: look in `C:\xampp\mysql\data\mysql\` for the file named after the table (e.g. `db`). A `.MAI` extension means **Aria**; a `.MYI` extension means **MyISAM** (on most XAMPP MariaDB 10.4 installs it's Aria / `.MAI`). Then, from `C:\xampp\mysql\bin`, run the matching repair, using the exact table name the error reported:
```
aria_chk --recover --force "C:\xampp\mysql\data\mysql\db"      # Aria (.MAI)
myisamchk --recover --force "C:\xampp\mysql\data\mysql\db"     # MyISAM (.MYI)
```
Start MySQL again. If it then names a *different* system table (`tables_priv`, `columns_priv`, etc.), repair that one the same way. (As a lighter first attempt you can let the server self-repair on startup with `mysqld.exe --console --aria-recover-options=BACKUP,FORCE`, but if the log already says "last automatic repair failed", go straight to the offline `aria_chk` / `myisamchk` above.)

**If none of that works, you still don't lose your data.** Your incident data is in `C:\xampp\mysql\data`, and (per the log above) InnoDB reads it fine, so it can be recovered onto a fresh install. Moving InnoDB data files across installs correctly is fiddly and easy to get wrong, so **make a copy of the whole `C:\xampp\mysql\data` folder first**, then reach out — that copy guarantees the data is safe, and the exact restore steps depend on your setup.

**Prevent it:** always click **Stop** on MySQL (and Apache) in the XAMPP Control Panel **before** shutting the computer down — a hard power-off with MySQL running is the usual trigger. And turn on TicketsCAD's backups (Settings → Backup / Maintenance) so a bad day is a quick restore instead of a rescue.

**On Linux**, the equivalents are `sudo systemctl status mariadb` and `journalctl -u mariadb -n 50` for the real error, `sudo ss -ltnp | grep 3306` for the port, and `/var/log/mysql/error.log` for the startup log.

---

## Login + auth

### admin-lockout

**Symptom:** You hit the lockout threshold and can't get back in even with the right password.

**Cause:** the lockout window hasn't expired yet (default 15 minutes), or the threshold counter is per-IP and you're behind NAT.

**Fix (immediate unlock via DB):**

```bash
sudo mariadb newui -e "UPDATE user SET locked_until = NULL, failed_login_count = 0 WHERE user = 'admin';"
```

**Better fix (use a backup auth path):** SSH into the VM and reset the password via SQL:

```bash
# Hash a temp password
HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' 'NEW_STRONG_PW')

# Update the user, force change on next login, clear lockout
sudo mariadb newui -e "
UPDATE user
   SET passwd = '$HASH',
       must_change_password = 1,
       locked_until = NULL,
       failed_login_count = 0
 WHERE user = 'admin';
"
```

The admin will be prompted to rotate on next login.

**See:** [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md)

---

### lost-tfa

**Symptom:** User lost both their authenticator app AND their backup codes. They can't log in.

**Cause:** TOTP secrets are encrypted at rest with the TFA key; there's no "decrypt and re-display the QR" path.

**Fix (admin reset):**

Use the admin UI: **Settings → Identity & Security → User Accounts → row → Reset 2FA**. A reason field is required and the action lands in the audit log.

If no admin can get in, SSH to the VM and clear the user's TFA enrollment directly:

```bash
sudo mariadb newui -e "
DELETE FROM user_tfa
 WHERE user_id = (SELECT id FROM user WHERE user='alice');
"
```

Next login, alice will be prompted to re-enroll. **Manual SQL bypass like this is NOT audit-logged** — record the action elsewhere if you need a compliance trail.

---

### tfa-enroll-loop

**Symptom:** User enrolls 2FA, scans the QR, enters a code — and gets bounced back to the enrollment screen.

**Cause A:** server clock skew. TOTP codes only match within ±30 s. If the server clock is wildly off, no code will match.

**Diagnostic:**

```bash
timedatectl status
# Look for "System clock synchronized: yes" and a small offset.
```

**Fix:** install + enable chrony or systemd-timesyncd.

```bash
sudo apt-get install -y chrony
sudo systemctl enable --now chronyd
```

**Cause B:** the TFA encryption key changed between enroll and verify. If you regenerated `keys/tfa.key` mid-enrollment, the secret is now undecryptable.

**Fix:** delete the user's `user_tfa` row, force re-enrollment with the current key:

```sql
DELETE FROM user_tfa WHERE user_id = N;
```

---

### pw-change-loop

**Symptom:** User changes their password, submits, gets "Password changed successfully" — and is immediately prompted to change again.

**Cause:** the new password violates the password policy (history check, complexity, or length), the UI showed success but the server actually rejected. Look at `/api/profile.php?action=change-password` response in browser devtools.

**Fix:** read the rejection reason in the response. Typical: "must not match any of last 5 passwords", "must contain at least one digit", "must be at least 12 characters". Pick a password that satisfies all rules and try again.

You can review (and loosen if necessary) the policy in Settings → Identity & Security → Password Policy.

---

### session-too-short

**Symptom:** User is logged out after a minute or two, even though session timeout is set to 24 h.

**Cause:** browser is blocking the session cookie, or the user is behind a reverse proxy that's stripping the cookie.

**Diagnostic:**

In browser devtools → Application → Cookies, check the `PHPSESSID` cookie. If it's missing or its `expires` is "session", the cookie is dropped on tab close.

**Fix A — same-site policy:** PHP's default `SameSite=Lax` blocks the cookie on cross-site POSTs. If TicketsCAD is on a subdomain different from where the request originates, set in `config.php`:

```php
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', '1');   // required when SameSite=None
```

**Fix B — reverse proxy:** make sure your front-line proxy (nginx, HAProxy) forwards the `Cookie` header and is not stripping `Set-Cookie` in the response.

---

### remember-device-ignored

**Symptom:** "Remember this device" was checked but the user is still prompted for 2FA every login.

**Cause A:** Phase 73bb hardening binds the `tfa_remember` cookie to a fingerprint of UA + Accept-Language + **/24 IPv4 prefix**. If the user's IP changed beyond /24 (different ISP, different cafe wifi, mobile carrier switch), the cookie no longer matches.

**Cause B:** the cookie has expired (default 30 days).

**Cause C:** the user's UA changed (browser update, switched browsers).

**Diagnostic:** check the value of `tfa_remember` cookie in devtools. If the user is on a new IP/24, the cookie won't validate.

**Fix:** none from a security standpoint — this is working as designed. Tell the user that "remember device" is a same-network convenience, not a permanent bypass. CJIS-grade deployments are recommended to leave the window short (≤7 days).

---

## Dashboard + UI

### app-empty-after-crash

**Symptom:** After a hard shutdown or power loss (the laptop died, the plug was pulled, a forced power-off) with MySQL running, TicketsCAD now loads only the top bar and spins forever — or shows a "database not ready / your data is not lost" page — and it looks like a fresh install. You're afraid the data you entered is gone.

**Your data is almost certainly fine.** It is on disk, in MySQL's data directory. What happened is that MySQL didn't shut down cleanly, and InnoDB — the storage engine that holds incidents, assignments, users, and the dashboard layout — hasn't finished recovering, so those tables can't be read yet. This is a MySQL recovery situation, not TicketsCAD deleting anything. (Newer versions of TicketsCAD detect this and show a calm "your data is not lost" page instead of an endless spinner.)

**Recover it — in this order:**

1. **Easiest first.** Stop MySQL, wait ~15 seconds, start it again (XAMPP Control Panel Stop → Start, or `sudo systemctl restart mariadb`), then reload TicketsCAD. Often InnoDB just needs a clean second start to finish recovery.

2. **Make a safety copy before changing anything.** Stop MySQL and copy the entire data directory somewhere safe:
   - XAMPP (Windows): `C:\xampp\<version>\mysql\data`
   - Linux: `/var/lib/mysql`

   That folder **is** your data. As long as you have a copy, nothing can be permanently lost.

3. **Read the error log** for the real cause. XAMPP: Control Panel → MySQL → **Logs** → `mysql_error.log`. Linux: `/var/log/mysql/error.log`. Look near the bottom for lines mentioning `InnoDB` (e.g. "Database page corruption", "log sequence number", "Plugin 'InnoDB' registration ... failed").

4. **If InnoDB won't start, recover the data with force-recovery, then export + reimport:**
   - Add `innodb_force_recovery=1` under `[mysqld]` in `my.ini` / `my.cnf` (XAMPP: `C:\xampp\<version>\mysql\bin\my.ini`). Start at **1**; if MySQL still won't start, try **2**, then **3**. Do NOT go above 3–4, and never write data at 4+.
   - Start MySQL, then **immediately export** the `newui` database — phpMyAdmin → Export, or `mysqldump newui > newui-rescue.sql`. This is the rescue of your data.
   - Remove the `innodb_force_recovery` line, restart MySQL, drop and recreate a clean `newui` database, and import `newui-rescue.sql`.

**Prevent it:** always **Stop** MySQL (and Apache) in the XAMPP Control Panel before shutting the machine down. A hard power-off with MySQL running is the usual cause. Regular backups (Settings → Backup / Maintenance, or `mysqldump`) turn even a total loss into a quick restore — see [BACKUP-RECOVERY-RUNBOOK.md](BACKUP-RECOVERY-RUNBOOK.md).

**If MySQL won't even start or stay running** (the XAMPP panel says "MySQL shutdown unexpectedly"), that's a separate problem — see [MySQL / MariaDB won't start or won't stay running](#mysql-wont-start).

---

### widgets-blank

**Symptom:** Dashboard renders the widget frames but all show empty / zero data.

**Cause:** every widget hits an API endpoint; if the user has zero RBAC role grants OR every endpoint is failing, widgets stay blank.

**Diagnostic:**

Open browser devtools → Network. Filter to XHR/fetch. Reload the page. Find requests to `/api/widgets.php`, `/api/statistics.php`, etc. Look for:

- `403 Forbidden` → RBAC issue (user has no role grants OR role lacks the widget permission)
- `200 OK` with empty array → data is genuinely empty (no incidents yet, etc.)
- `500 Internal Server Error` → server-side bug; check `/var/log/apache2/newui-error.log`
- `JSON parse error` in the response body → see [json-parse-error](#json-parse-error)

**Fix A — zero role grants:**

```bash
sudo mariadb newui -e "SELECT u.user, r.name FROM user u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.user='alice';"
```

If the row's `name` column is NULL, assign a role from Settings → User Accounts.

**Fix B — schema drift on optional columns** (Phase 73a finding):

Look in `/var/log/apache2/newui-error.log` for lines like `[safe_fetch_all] Unknown column 'foo' in ...`. The fix is to either add the missing column manually or run the relevant migration. If you don't know which migration, the lazy fix is:

```bash
cd /var/www/newui && sudo -u www-data php sql/run_migrations.php
```

---

### map-blank-tiles

**Symptom:** Map widget shows the Leaflet grid but no actual tiles.

**Cause A:** the tile provider URL is wrong, or it requires a key TicketsCAD doesn't have.

**Cause B:** the tile provider is rejecting your requests — commonly OpenStreetMap
responding 429/403 to a generic or missing User-Agent, or to excessive volume.

**Cause C (proxy mode):** your *server* cannot reach the tile provider — egress
firewall, no DNS, or a proxy the server does not know about. In proxy mode the
browser talks only to TicketsCAD, so a server-side egress problem looks exactly
like a dead provider.

**Diagnostic:** browser devtools → Network, and look at what the tile requests
are actually going to:

- Requests to `api/tile-proxy.php?...` — you are in **proxy** mode. A `200` with
  a blank image and an `X-Tile-Proxy: error` response header means the server
  reached the endpoint but the upstream fetch failed; the `X-Tile-Proxy-Reason`
  header and your PHP error log (`[tile-proxy]` entries) name the cause. Check
  server egress: `curl -A "TicketsCAD/4 (+https://yourhost)" https://tile.openstreetmap.org/0/0/0.png -o /dev/null -w '%{http_code}\n'`
  from the server itself.
- Requests to `https://tile.openstreetmap.org/...` (or another provider's host) —
  you are in **direct** mode for that basemap. Check the status code there.

Note that both can be true at once: Street and Terrain proxy (and so do Dark
and Light when no CARTO key is set, because they are then OpenStreetMap), while
Satellite, and Dark and Light with a CARTO key, always go direct because Esri's
and CARTO's terms do not permit proxying. If Dark or Light shows "API KEY
REQUIRED" on every tile, CARTO is rejecting the key: check Settings → Tile
Providers → "CARTO key for the Dark and Light maps". See [SECURITY.md § Map tiles: proxy vs direct](../SECURITY.md#map-tiles-proxy-vs-direct).

**Fix A:** Settings → Tile Providers. Use a different provider
or supply the key.

**Fix B (provider rejecting you):** set a contactable **Proxy User-Agent** in
Settings → Tile Providers — OpenStreetMap blocks generic agents. The Cache Status
panel on that page shows what you are currently identifying as.

**Fix C (server has no egress):** either open outbound HTTPS from the server to
your tile provider, or set Tile Mode to `direct` so the browsers fetch tiles
instead. Direct mode exposes each dispatcher's IP and browser to the provider —
it is a trade, not a free fix.

> **Older releases:** before v4.2.3 the "Tile Mode" setting did nothing at all.
> It was stored and defaulted to `proxy`, but no code read it and every install
> fetched tiles directly from the browser. If you were told to switch to proxy
> mode on an earlier version and nothing changed, that is why — the fix is to
> upgrade, not to change the setting again.

---

### sse-gray

**Symptom:** SSE indicator (top-right of navbar) is gray. Real-time updates don't arrive.

**Cause A:** `api/stream.php` is dying after 120 s because PHP's default `max_execution_time` is 120 s but the stream is meant to hold open for 360 s.

**Diagnostic:** check `/var/log/apache2/newui-error.log` for `PHP Fatal error: Maximum execution time of 120 seconds exceeded`.

**Fix:** confirm `api/stream.php` calls `set_time_limit(360)` near the top. (Phase 26C fix; should already be there.)

**Cause B:** EventBus client gave up reconnecting after 20 attempts.

**Diagnostic:** browser devtools → Console. Look for `EventSource failed`. Reload the page.

**Fix:** the indicator goes green within 5 s of page load if SSE is healthy. If not, look at the response code on the `/api/stream.php` request — 401 means auth issue, 500 means server error, 502/504 means a proxy upstream killed the connection (raise the proxy's read timeout to 360 s).

**If there is no response code at all, none of that applies** — see Cause C. Every
branch of the decision tree above assumes the server answered.

**Cause C:** the web server has no free worker to answer with. The request was
accepted and never served.

**Diagnostic:** in devtools → Network the `/api/stream.php` request sits pending
with no status, and `EventSource.readyState` stays at `0` (CONNECTING) rather
than going to `2` (CLOSED). The Diagnostics page — navbar user menu →
Diagnostics — reports *"The live-update stream was accepted but never
answered."* Alongside it the rest of the UI is
intermittently slow in a way that correlates with nothing — on the reported
install the same `api/par.php` measured **63 ms** idle and **23,194 ms** a minute
later. A 502 and "no reply at all" are genuinely different conditions; only this
one has no status code.

**Why a single tab needs two slots:** `inc/navbar.php` loads
`assets/js/event-bus.js` on every page, which opens one `EventSource` to
`api/stream.php`, and `assets/js/diagnostics.js` opens a second one to the same
endpoint. Each is held server-side for `$maxRuntime` and reconnected
immediately, so on the diagnostics page one connection is the navbar's, one is
the check's, and everything else the page does has to fit in what is left — the
check competes with the thing it is checking. (HTTP/1.1 also allows only six
connections per origin, so two streams spend a third of the browser's budget for
that origin before any ordinary request goes out. Worth knowing before adding a
feature that opens its own stream.)

**Fix:** raise the server's concurrent-request capacity, or hold fewer
connections open.

- **IIS on a Windows *client* edition is capped by the OS, not by your
  configuration.** Windows 11 Home allows **3** concurrent requests — which two
  SSE streams exhaust on their own, leaving one slot for the whole application.
  `maxInstances`, `maxConnections`, CPU count and the number of `php-cgi.exe`
  processes make no difference to it. See
  [INSTALL-WINDOWS-IIS.md § 6](INSTALL-WINDOWS-IIS.md#6-windows-client-editions-cap-concurrent-requests).
- **PHP-FPM:** raise `pm.max_children`. A long-lived stream occupies one child
  for its entire lifetime.
- **Apache prefork:** raise `MaxRequestWorkers`, same reasoning.

**Measure the ceiling rather than assuming it.** Fire N requests that each sleep
6 s and time the batch: N=8 finishing in ~6 s means the ceiling is at least 8,
~12 s means 4, ~18 s means 3, ~24 s means 2.

Reported by @rjonesbsink ([#29](https://github.com/openises/TicketsCAD/issues/29)).

---

### json-parse-error

**Symptom:** API endpoint returns valid JSON in browser but the JS that called it complains "Unexpected token <".

**Cause:** PHP error output is being prepended to the JSON. Usually `display_errors=on` in php.ini plus a warning being raised in the endpoint code.

**Fix:** every API endpoint starts with `ini_set('display_errors', '0')` to prevent this. If you see the symptom, either:

- That `ini_set` line is missing from the endpoint → file a bug or patch
- A fatal error is happening *before* that line runs (rare) → check Apache error log for the trace
- A `<?php` whitespace BOM is being emitted (rare but real) → check file encoding with `head -c 4 file.php | xxd`; it should start with `<?` not `EFBBBF <?`

---

### dropdown-empty

**Symptom:** an in-app dropdown (incident types, statuses, organisations) shows no options.

**Cause:** the API endpoint returned an empty array because of schema drift on an optional column. Apache error log will show `[safe_fetch_all] Unknown column ...`.

**Fix:**

```bash
cd /var/www/newui && sudo -u www-data php sql/run_migrations.php
```

Then check the table:

```sql
SHOW COLUMNS FROM in_types;     -- or whichever table backs the dropdown
```

Confirm the columns the code expects are present.

---

### theme-stuck

**Symptom:** dark/light toggle in navbar doesn't change the theme.

**Cause:** `data-bs-theme` attribute on `<html>` isn't being applied. Usually a localStorage write that didn't take effect.

**Fix:** browser devtools → Application → Local Storage → delete `tcad-theme` key. Reload. Toggle again. If it's still stuck, your browser may be blocking localStorage writes for that origin.

---

## Incidents + dispatch

### new-incident-vanished

**Symptom:** The new-incident form submits without error, but no incident shows up on the dashboard / list.

**Diagnostic:** browser devtools → Network → find the POST to `/api/incident-create.php`. Look at the response.

**Common causes:**

- `403 Forbidden` → user role lacks `action.create_incident`
- `500` → server error (read Apache log)
- `200 OK` with success → the incident DID get created but isn't visible to your role; check that you have `screen.incidents` AND are in the right allocates group

**Fix:** look up the incident by id directly:

```sql
SELECT id, scope, status, created_at FROM ticket ORDER BY id DESC LIMIT 5;
```

If it's in the table, the issue is visibility — confirm the dispatcher has the right allocates groups + RBAC perms.

---

### status-revert

**Symptom:** changing a unit's status from "Available" to anything else jumps back to "Available" after a moment.

**Cause:** Phase 33A bug — old SSE event was being processed AFTER the new one, overwriting the local state.

**Fix:** this was patched in Phase 33A; if you're still seeing it, you may be running an older build. `git pull && sudo systemctl reload apache2`.

---

### par-never-fires

**Symptom:** PAR checks are configured on an incident type, but no PAR cycle ever initiates.

**Cause A:** `tools/par_tick.php` isn't being triggered. Check the cron.

```bash
sudo crontab -l -u www-data
```

If `par-scheduler` isn't there, add it (see [INSTALLATION-CHECKLIST.md § Section 12](INSTALLATION-CHECKLIST.md#section-12--cron-for-background-tasks)).

**Cause B:** the incident has no assigned units OR PAR cadence is set to "disabled" on this incident type. PAR cycles only fire on incidents with at least one active assignment AND a non-zero cadence.

**Diagnostic:**

```sql
SELECT id, incident_number, status, par_disabled,
       (SELECT COUNT(*) FROM assigns WHERE ticket_id=ticket.id AND (clear IS NULL OR DATE_FORMAT(clear,'%y')='00')) AS active_assigns
FROM ticket WHERE status = 2 ORDER BY id DESC;
```

---

## Communications

### smtp-send-fails-empty-error

**Symptom:** Settings → Email Configuration → Test Email returns `"Email failed: Send failed: — check your SMTP settings."` with NOTHING between `Send failed:` and the em-dash. SMTP host + port + encryption + From-address all populated correctly. Auth-less Gmail Workspace SMTP relay (no `smtp_user` + `smtp_pass`).

**Cause:** Gmail Workspace's SMTP relay service accepted the connection, accepted EHLO, accepted STARTTLS, accepted MAIL FROM + RCPT TO + DATA + the body — then silently closed the connection after the body without returning a 250 response. The `inc/channels/smtp.php` handler reads the post-body response as the empty string and renders `"Send failed: "`. Almost always means Gmail's relay rejected the sender for policy reasons. Most common policy mismatch: **your server has dual-stack networking (IPv4 + IPv6) and the IPv6 outbound address isn't on the relay's allowed-IPs list**, even though IPv4 is. Gmail's relay rejects silently rather than returning a reason. Reported by beta tester a beta tester 2026-06-26.

**Fix:**

```bash
# Find the outbound IPs your server actually uses
curl -s -4 https://ifconfig.me   # your IPv4
curl -s -6 https://ifconfig.me   # your IPv6 (if any)
```

Then in admin.google.com → Apps → Google Workspace → Gmail → Routing → SMTP relay service → your relay entry → Allowed senders / Only accept mail from the specified IP addresses, add BOTH the IPv4 and IPv6 outputs from above. After saving in the Google admin console, retry Settings → Email Configuration → Test Email — should now succeed.

**Other policy-mismatch causes worth checking** if both IPs are whitelisted and you still get the empty error: the relay's sender-restriction radio is set to "Only addresses I've explicitly listed" but your From address (`email_from` setting) isn't on that list, OR your relay entry has TLS-required selected but your server's outbound TLS cert isn't trusted by Google. Both fix in the same admin panel.

---

### chat-doesnt-persist

**Symptom:** Chat messages appear in real time (via SSE) but disappear when you refresh the page.

**Cause:** the broker / `chat_messages` shadow-schema issue. The INSERT into `chat_messages` fails silently because the columns the code writes don't match the legacy schema.

**Fix:** run the recovery migration:

```bash
cd /var/www/newui
sudo -u www-data php tools/fix_chat_tables.php
```

This DROPs the legacy `chat_messages` and `messages` tables (which were empty anyway) and recreates them with the modern schema the broker expects. Read the decision memo before running on production data.

---

### owntracks-403

**Symptom:** OwnTracks phone app shows "HTTP 403" on every post.

**Cause A (Phase 73v hardening):** the install has no per-member token, no shared secret, AND `owntracks_allow_anonymous=0`. With the Phase 73v fix, the endpoint now fail-closes by default. Old "wide open" behaviour is gone.

**Fix:** mint a per-member token (Settings → User Accounts → row → OwnTracks → Mint Token) and configure it in the OwnTracks phone app under Settings → Connection → Auth (username = member username, password = token).

**Cause B:** the shared secret is misconfigured. Settings → OwnTracks Defaults → confirm `owntracks_secret` matches what the device is sending.

**Cause C (rate limit):** Phase 73x rate-limits ingest at 600 posts/min/IP. If a NAT gateway is multiplexing many devices, you may hit the cap. Raise the cap or proxy from per-device IPs.

---

### dmr-bad-bearer

**Symptom:** the admin panel's "Test /health" button on a DMR channel returns `{"error":"bad bearer"}`.

**Cause:** the bearer token in the bridge daemon's env file doesn't match the SHA-256 hash stored in `dmr_channels.bridge_token`.

**Fix:**

```bash
# On the TicketsCAD admin UI, rotate the token (this DOES invalidate the current one).
# Mint → copy → paste into:
sudo nano /etc/ticketscad/dvswitch-<instance>.env
# Update both DMR_BEARER_TOKEN and DMR_INGEST_TOKEN.
sudo systemctl restart ticketscad-dvswitch@<instance>
```

Verify on dvswitch-host:

```bash
journalctl -u ticketscad-dvswitch@<instance> -n 30
```

You should see `HTTP control listening on :<port>` and no auth-related errors.

---

### mesh-disconnected

**Symptom:** Mesh Console page shows the bridge as "disconnected".

**Diagnostic:** on the meshbridge VM:

```bash
sudo systemctl status meshbridge_v2
journalctl -u meshbridge_v2 -n 50
```

**Common causes:**

- USB device disconnected → check `ls /dev/ttyUSB*` matches the bridge's expected port
- Bearer token mismatch → see [dmr-bad-bearer](#dmr-bad-bearer) (same pattern)
- TicketsCAD unreachable from the bridge VM → `curl -I https://cad.example.org/api/mesh.php?action=poll_outbox` should not error

---

### aprs-zero-stations

**Symptom:** the APRS map shows "0 stations in window" under a banner reading
"APRS-IS receive listener is not active. Last position received 5h ago" — but
the listener is running fine and rows really are arriving. The reported age is
suspiciously close to your server's offset from UTC.

**Fixed in 4.2.2.** If you are on 4.2.1 or earlier and your server is not set
to UTC, you have this bug; `git pull` and reload PHP. Nothing in the database
needs to change — the stored rows were always correct, only the query that
measured their age was wrong.

**Diagnostic** (confirms it is this and not a genuinely dead listener):

```sql
SELECT NOW()                                              AS now_local,
       UTC_TIMESTAMP()                                    AS now_utc,
       MAX(reported_at)                                   AS newest_row,
       TIMESTAMPDIFF(SECOND, MAX(reported_at), NOW())     AS age_real,
       COUNT(*)                                           AS rows_last_hour
  FROM location_reports
 WHERE reported_at > DATE_SUB(NOW(), INTERVAL 1 HOUR);
```

If `age_real` is a few seconds and `rows_last_hour` is large, the listener is
healthy and you are hitting this bug. If `rows_last_hour` is 0, the listener
really is down — check `systemctl status aprs-listener`.

**Cause:** `location_reports.reported_at` stores wall-clock time in the
install's `area_timezone`, but the map's query measured its age against
`UTC_TIMESTAMP()`. On a UTC server the two clocks are identical and nothing
looks wrong, which is why it survived; on any other server every row looked
exactly one UTC offset old, so the one-hour window matched nothing.

---

### webhook-failed

**Symptom:** every webhook delivery shows status=failed in the delivery log.

**Diagnostic:** Settings → Integrations → Webhooks → row → Delivery log. Read the error message.

**Common causes:**

- Receiver returning non-2xx → check the receiver's logs
- TLS cert at the receiver is self-signed → TicketsCAD verifies certs by default; either install the receiver cert in your system trust store or (NOT recommended in prod) disable verification in [`api/webhooks.php`](../api/webhooks.php)
- DNS resolution failing → `dig <receiver-host>` from the TicketsCAD VM
- Receiver rejecting HMAC → make sure the receiver computes HMAC-SHA256 of the **raw request body** with the same secret TicketsCAD has

---

## Mobile + PWA

### pwa-missing

**Symptom:** Android Chrome doesn't show "Add to Home Screen" for `https://cad.example.org/`.

**Cause:** PWA install requires HTTPS, a valid `manifest.webmanifest`, and a service worker registered.

**Diagnostic:** open Chrome DevTools → Application → Manifest. Look for warnings.

**Common fixes:**

- Manifest missing icons of certain sizes → Phase 49 patched the manifest; if you still see issues, check `manifest.webmanifest` is being served with `Content-Type: application/manifest+json`.
- `start_url` is wrong → should be `/index.php` not `/`.
- HTTPS isn't really HTTPS (mixed content) → fix mixed content first.

---

### mobile-scroll-stuck

**Symptom:** mobile dashboard renders but the page won't scroll past the sticky navbar.

**Cause:** Phase 72 fix. If you're seeing this, you're on an older build. `git pull && systemctl reload apache2`.

---

### clockin-noop

**Symptom:** user taps "Clock in" in the navbar, nothing happens.

**Cause A:** user's role lacks `action.self_clock_in`.

**Cause B:** the member has no linked organisation. The clock-in endpoint refuses to create a personal-resource unit for orphan members (Phase 61 fix). Fix: assign the member to an org from Roster → row → Org.

**Cause C:** the responder table is missing the `personal_for_member_id` column (Phase 62 self-heal). The next time someone clock-ins, the system tries to ALTER TABLE to add it; if the DB user lacks ALTER privilege, this fails. Grant ALTER:

```sql
GRANT ALTER ON newui.* TO 'newui'@'localhost';
FLUSH PRIVILEGES;
```

---

## Backups + recovery

### backup-too-big

**Symptom:** the backup archive is hundreds of MB and takes minutes.

**Cause:** `audit_log` and `location_reports` grow rapidly. Both are configured to retain a year by default.

**Fix:** only one of the two is configurable.

- `location_reports` — **Settings → Location History Retention** → reduce from
  90 days to e.g. 30 days.
- `audit_log` — **there is no retention setting**, and no trim job. Corrected
  2026-08-03: this entry used to send you to an "Audit & Compliance" settings
  panel to shorten it. No such panel, and no such control, has ever existed.
  The table simply grows.

Trim the existing rows by hand (CJIS deployments must keep at least 365 days of
audit log — verify your compliance requirements before shortening it):

```bash
sudo mariadb newui -e "DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL 180 DAY;"
sudo mariadb newui -e "DELETE FROM location_reports WHERE received_at < NOW() - INTERVAL 30 DAY;"
```

Future backups will be smaller.

---

### restore-table-exists

**Symptom:** restoring a backup with `mariadb newui < backup.sql` fails with `Table 'foo' already exists`.

**Cause:** you're restoring on top of an existing schema instead of into an empty database.

**Fix:**

```bash
# Drop the database, recreate empty, then restore.
sudo mariadb -e "DROP DATABASE newui; CREATE DATABASE newui CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb newui < /path/to/backup.sql
```

Note: this is destructive. Always verify the backup file is the one you want first.

---

## Performance

### slow-pages

**Symptom:** pages take 3+ seconds to first paint.

**Diagnostic:**

```bash
# Time the API call alone:
time curl -s -o /dev/null -H "Cookie: PHPSESSID=..." https://cad.example.org/api/widgets.php
```

If the API is fast (<200 ms) but the page is slow, the bottleneck is browser-side (slow JS, blocking external scripts).

If the API is slow, look at MySQL's slow-query log:

```bash
sudo mariadb -e "SET GLOBAL slow_query_log='ON'; SET GLOBAL long_query_time=0.5;"
# Reproduce the slow page, then:
sudo tail -100 /var/log/mysql/mariadb-slow.log
```

Common fixes:

- Add an index on a column the query filters by. Use `EXPLAIN <slow-query>` to see if a full scan is happening.
- Increase MariaDB `innodb_buffer_pool_size` to ~50% of available RAM if tables are large.

---

### sse-cpu

**Symptom:** Apache process for `api/stream.php` is using 100% CPU.

**Cause:** the SSE loop is busy-polling instead of sleeping between checks.

**Fix:** confirm `api/stream.php` has `usleep(500000)` or longer between iterations of the event-fetch loop. If not, patch and reload Apache.

If the bug persists, this is a regression — file an issue with the script's PID's flame-graph if you can.

---

## Where to escalate

If your problem isn't here and the FAQ doesn't help:

1. **Check `/var/log/apache2/newui-error.log`** and copy the most recent traceback into the issue you file.
2. **Run the relevant test suite** locally: `php tools/test_all.php` and report which tests fail.
3. **File a GitHub issue** at [openises/TicketsCAD](https://github.com/openises/TicketsCAD/issues) with: TicketsCAD version (from `/about.php`), browser + version, expected vs. actual behaviour, reproduction steps.
4. **For security issues**, do NOT file a public issue — see [SECURITY-POLICY.md](SECURITY-POLICY.md) for the disclosure path.

This playbook is maintained alongside the code. Every fix that lands here came from a real incident — if you solve a problem that isn't here, send a patch.
