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
- [Login + auth](#login--auth)
  - [Locked out of admin account](#admin-lockout)
  - [Forgot all backup codes + lost authenticator](#lost-tfa)
  - [2FA enrollment loop](#tfa-enroll-loop)
  - [Forced-password-change cycle never ends](#pw-change-loop)
  - [Session keeps logging me out after 30 seconds](#session-too-short)
  - [Trusted device cookie ignored](#remember-device-ignored)
- [Dashboard + UI](#dashboard--ui)
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

**Cause B:** OpenStreetMap blocked your IP for excessive requests (the default tile.openstreetmap.org Referer policy).

**Diagnostic:** browser devtools → Network. Look at the tile requests (`https://tile.openstreetmap.org/.../X.png`). What's the status code?

**Fix A:** Settings → Maps & Tracking → Map Providers. Use a different provider or supply the key.

**Fix B:** switch to Docker-style proxy mode (Settings → Maps → Map Providers → mode = "proxy"). The server fetches tiles and re-serves them without leaking the dispatcher's IP/Referer.

---

### sse-gray

**Symptom:** SSE indicator (top-right of navbar) is gray. Real-time updates don't arrive.

**Cause A:** `api/stream.php` is dying after 120 s because PHP's default `max_execution_time` is 120 s but the stream is meant to hold open for 360 s.

**Diagnostic:** check `/var/log/apache2/newui-error.log` for `PHP Fatal error: Maximum execution time of 120 seconds exceeded`.

**Fix:** confirm `api/stream.php` calls `set_time_limit(360)` near the top. (Phase 26C fix; should already be there.)

**Cause B:** EventBus client gave up reconnecting after 20 attempts.

**Diagnostic:** browser devtools → Console. Look for `EventSource failed`. Reload the page.

**Fix:** the indicator goes green within 5 s of page load if SSE is healthy. If not, look at the response code on the `/api/stream.php` request — 401 means auth issue, 500 means server error, 502/504 means a proxy upstream killed the connection (raise the proxy's read timeout to 360 s).

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

**Symptom:** Settings → Email → Test Email returns `"Email failed: Send failed: — check your SMTP settings."` with NOTHING between `Send failed:` and the em-dash. SMTP host + port + encryption + From-address all populated correctly. Auth-less Gmail Workspace SMTP relay (no `smtp_user` + `smtp_pass`).

**Cause:** Gmail Workspace's SMTP relay service accepted the connection, accepted EHLO, accepted STARTTLS, accepted MAIL FROM + RCPT TO + DATA + the body — then silently closed the connection after the body without returning a 250 response. The `inc/channels/smtp.php` handler reads the post-body response as the empty string and renders `"Send failed: "`. Almost always means Gmail's relay rejected the sender for policy reasons. Most common policy mismatch: **your server has dual-stack networking (IPv4 + IPv6) and the IPv6 outbound address isn't on the relay's allowed-IPs list**, even though IPv4 is. Gmail's relay rejects silently rather than returning a reason. Reported by beta tester a beta tester 2026-06-26.

**Fix:**

```bash
# Find the outbound IPs your server actually uses
curl -s -4 https://ifconfig.me   # your IPv4
curl -s -6 https://ifconfig.me   # your IPv6 (if any)
```

Then in admin.google.com → Apps → Google Workspace → Gmail → Routing → SMTP relay service → your relay entry → Allowed senders / Only accept mail from the specified IP addresses, add BOTH the IPv4 and IPv6 outputs from above. After saving in the Google admin console, retry Settings → Email → Test Email — should now succeed.

**Other policy-mismatch causes worth checking** if both IPs are whitelisted and you still get the empty error: the relay's sender-restriction radio is set to "Only addresses I've explicitly listed" but your From address (`email_from` setting) isn't on that list, OR your relay entry has TLS-required selected but your server's outbound TLS cert isn't trusted by Google. Both fix in the same admin panel.

---

### chat-doesnt-persist

**Symptom:** Chat messages appear in real time (via SSE) but disappear when you refresh the page.

**Cause:** the broker / `chat_messages` shadow-schema issue documented in `specs/broker-schema-2026-06/decision-memo.md`. The INSERT into `chat_messages` fails silently because the columns the code writes don't match the legacy schema.

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

**Cause B:** the shared secret is misconfigured. Settings → Maps & Tracking → OwnTracks → confirm `owntracks_secret` matches what the device is sending.

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

Verify on dvswitch-01:

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

**Fix:** trim retention in Settings → Audit & Compliance:

- audit_log retention → reduce from 365 days to e.g. 180 days (CJIS minimum is 365 days, so verify your compliance requirements first)
- location_reports → reduce from 90 days to e.g. 30 days

Then trim the existing rows once (no dedicated trim script exists yet — run as SQL):

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
