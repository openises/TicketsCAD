# Installing TicketsCAD on Windows + IIS

`docs/INSTALL.md` is written for Debian/Ubuntu and Apache. Everything in it
applies here in principle — the same database, the same migrations, the same
first-admin step — but six things behave differently enough on Windows/IIS to
lose you a day each, and until now they were only recorded in issue comments.

This page covers those six, then points back at the main guide for the rest.

Every item below was found on a real Windows 11 / IIS / PHP 8.4.22 / MySQL 8.0
install by **Ron Jones (@rjonesbsink)**, who traced each one to its mechanism and
verified the fixes through actual HTTP requests rather than only the command
line. Reported as [openises/TicketsCAD#5][i5], [#8][i8], [#18][i18] and [#29][i29].

[i5]: https://github.com/openises/TicketsCAD/issues/5
[i8]: https://github.com/openises/TicketsCAD/issues/8
[i18]: https://github.com/openises/TicketsCAD/issues/18
[i29]: https://github.com/openises/TicketsCAD/issues/29

---

## The short version

| Thing | Why it bites | Fix |
|---|---|---|
| `OPENSSL_CONF` is not set | Windows PHP never creates OpenSSL's default config file, so any EC key generation fails | TicketsCAD now works around it. Set it anyway — see [1](#1-openssl_conf-web-push-and-anything-else-that-makes-a-key) |
| `disable_functions` includes `exec` | `@` does **not** suppress "call to undefined function"; the response body comes back empty | Nothing to do — fixed in the app. See [2](#2-disable_functions-and-empty-responses) |
| MySQL 8.0 ≠ MariaDB | Both are listed as supported and they differ in ways that used to fail silently | See [3](#3-mysql-80-versus-mariadb) |
| IIS ignores `.htaccess` | The shipped directory denies are Apache-only | Install the `web.config` files — see [4](#4-iis-ignores-htaccess) |
| Nothing runs the background jobs | Windows has no systemd, so the two timers the Linux guide relies on simply do not exist. PAR checks never time out and notifications never leave the queue | Create one Task Scheduler entry — see [5](#5-the-background-jobs-need-task-scheduler) |
| Client editions cap concurrency at 3 | Windows 11/10 Home and Pro limit how many requests IIS will handle at once, and two SSE streams use most of it. Looks like a network problem, is not | Use Server, or a different web server — see [6](#6-windows-client-editions-cap-concurrent-requests) |

---

## 1. `OPENSSL_CONF`, Web Push, and anything else that makes a key

### What you would see

Settings → Web Push Notifications → **Generate new key pair**:

```
Keypair generation failed: VAPID keypair generation failed: Unable to create the key
```

True, and it tells you nothing. Two layers down, OpenSSL is being specific:

```
error:07000072:configuration file routines::no such file
```

### Why

Generating any elliptic-curve key by **named curve** — which is every Web Push
VAPID key — requires OpenSSL's configuration file. OpenSSL looks at its
compiled-in default:

```
C:\Program Files\Common Files\SSL\openssl.cnf
```

The Windows PHP distribution never creates that file. It *does* ship a perfectly
good copy at `<PHP_DIR>\extras\ssl\openssl.cnf` — it is just never wired up.

This is not a TicketsCAD problem, or a Web Push problem. It affects any PHP code
on Windows that calls `openssl_pkey_new()` with a `curve_name`.

### What TicketsCAD does about it now

As of the fix for [#8][i8], TicketsCAD locates PHP's own `openssl.cnf` and
generates the key with it explicitly. **Web Push key generation works on a stock
Windows PHP install with no environment changes**, from both Settings and
`php tools/generate_vapid_keys.php`. When the fallback is what produced the key,
it says so, because your host still has an unconfigured OpenSSL and other things
may trip on it.

### Setting it properly anyway

Recommended, since it fixes the underlying condition rather than one symptom of
it.

**Either of the two locations below works on its own**, for both the CLI and the
web UI, and neither requires a reboot. Setting both is defensible belt-and-braces
— it is not a requirement.

**a) The machine environment:**

```powershell
setx OPENSSL_CONF "C:\PHP84\extras\ssl\openssl.cnf" /M
```

**b) IIS FastCGI's own environment collection:**

```powershell
& "$env:windir\system32\inetsrv\appcmd.exe" set config -section:system.webServer/fastCgi `
  "/+[fullPath='C:\PHP84\php-cgi.exe'].environmentVariables.[name='OPENSSL_CONF',value='C:\PHP84\extras\ssl\openssl.cnf']" `
  /commit:apphost
```

Adjust both paths to your actual PHP install location.

### The part that will actually cost you a day

**`php-cgi.exe` processes are pooled, and they survive `iisreset` and a `W3SVC`
restart.** A pooled worker keeps the environment block it was spawned with, so an
environment or `applicationHost.config` change can appear to have had *no effect
whatsoever* — the worker keeps reporting the old value, and stock key generation
keeps failing — long after you have set the variable correctly.

This is the failure mode this page exists to prevent, because its shape is
misleading: you set the variable, you test, nothing changes, and the reasonable
conclusion is that the variable was not the problem. It was. The processes
holding the old environment were simply never replaced.

To make a change take effect, replace the workers:

```powershell
taskkill /F /IM php-cgi.exe
```

An application-pool recycle also spawns fresh children. A reboot certainly does.
`iisreset` alone may not.

> Historical note, since an earlier version of this page said otherwise: this
> guide previously claimed that FastCGI's `environmentVariables` collection
> *replaces* the inherited environment rather than merging with it, and that a
> machine-wide `setx` therefore could not reach the web UI. That is not what
> happens. Ron subsequently tested the full matrix — each location alone,
> with and without a reboot, each run after killing `php-cgi.exe` — and the
> machine variable alone works in the FastCGI worker. The pooled-worker behaviour
> above is the better explanation for what both of us originally observed, and
> the claim it replaced has been removed rather than left standing because it
> sounded plausible.

### Confirming it

```powershell
php -r "var_dump(getenv('OPENSSL_CONF'));"
php -r "var_dump(openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]) !== false);"
```

The second must print `bool(true)`.

Then confirm the **web** side separately, by generating a key from Settings → Web Push Notifications
rather than from the shell. The CLI and the FastCGI worker are different
processes with different environment blocks, and — per the pooling note above —
the worker can be running with a stale one. If the web side disagrees with the
command line, kill `php-cgi.exe` before concluding anything.

### If you are testing the keys by hand

Only relevant if you are verifying the generated keypair yourself rather than
trusting the round-trip test. `minishlink/web-push` takes **two different
encodings** of the same keys:

| Call | Wants |
|---|---|
| `VAPID::validate()` | the base64url **stored form**, as saved in settings |
| `VAPID::getVapidHeaders()` | **raw binary** for both keys — it base64url-encodes them itself |

Passing the stored form to `getVapidHeaders()` fails with `Invalid data: only
uncompressed keys are supported`, which reads like a malformed key rather than a
double-encoding, and sends you looking in the wrong place.

---

## 2. `disable_functions` and empty responses

`disable_functions = shell_exec, exec, system, passthru, popen` is a common
hardening default on Windows/IIS PHP. It is a reasonable thing to have set, and
you should not need to undo it.

The trap: PHP's `@` error-suppression operator does **not** suppress the fatal
"Call to undefined function" that a disabled function raises. With
`display_errors` off — the documented production posture — the failure mode is a
completely empty HTTP response body, which surfaces in the browser as:

```
Unexpected end of JSON input
```

...with nothing anywhere naming the cause.

**This is fixed.** Every affected call site now uses the argv-array form of
`proc_open()`, which is not usually included in the hardening presets that remove
`exec`/`shell_exec`. Converted in commit `8a9ec2a`, covering:

| File | What was breaking |
|---|---|
| `sql/run_migrations.php` | the whole migration runner |
| `tools/install_fresh.php` | fresh install |
| `tools/check-schema.php` | `--repair`, twice |
| `api/health.php` | `/api/health.php`, taking the System Status page with it |
| `inc/tts/engine.php` | text-to-speech binary detection |
| `proxy/ZelloProxyApp.php` | the same, in the Zello proxy |

There is a suite gate (`tests/test_no_shell_command_execution.php`) that fails if
any of them comes back.

**One Windows-specific bonus:** `wmic` does not exist on Windows 11 24H2 or
later, nor on recent Windows Server. The host-uptime figure on the System
Status page therefore tries `wmic` first (it is present on older hosts and
about 4x faster), then falls back to
`powershell.exe -Command (Get-CimInstance Win32_OperatingSystem).LastBootUpTime`,
formatted with `InvariantCulture` so the host's locale cannot change the shape.
The logic lives in `inc/host-uptime.php`; `tests/test_host_uptime.php` gates it.

If NEITHER is available, the page says so and names what it tried — it does not
print a bare "Unknown", because that reads the same as "this host has no uptime"
and tells you nothing about what to fix.

If you would rather allow subprocesses outright, remove `exec` and `shell_exec`
from `disable_functions` — but you do not need to, and leaving them disabled is
the better posture.

---

## 3. MySQL 8.0 versus MariaDB

The README lists both as supported, and they differ in ways that matter.

**`TEXT` columns cannot have a literal `DEFAULT` in MySQL 8.0.** MariaDB permits
it. `sql/dashboard_tables.sql` had one, so on MySQL 8.0 the
`dashboard_layouts` table was never created, every dashboard-layout save failed,
and nothing said why — the installer counted the failed statement and moved on.
Fixed, and the installer now classifies per-statement failures instead of
aggregating them into a number: "already exists" stays quiet, anything else
prints the SQLSTATE, the statement and the driver's message.

**After any install or upgrade on MySQL, run:**

```powershell
php tools\check-schema.php
```

It compares the live schema against `sql\schema_manifest.json` and names anything
missing. `--repair` re-runs the migrations.

Note the limit, because it has caught people out: `--repair` re-runs
`sql\run_*.php` migrations, so it cannot fix a foundational `.sql` file imported
only by `install_fresh.php`. If `check-schema.php` reports a missing **table**
that no migration creates, import that file directly.

---

## 4. IIS ignores `.htaccess`

**The web root is the application root**, so IIS publishes every directory in the
tree unless told otherwise — including `backups/` (complete database dumps),
`inc/` (holds your database password), `sql/` + `tools/`, and (found
2026-08-14, by @rjonesbsink) **`.git/`** (the full repository and its commit
history, over plain HTTP) and **`vendor/`** (every Composer dependency's
exact version — a ready-made list to match against known CVEs).

TicketsCAD ships `.htaccess` denies, and **IIS does not read them**, as
completely as nginx does not.

**What ships for IIS, as of this version:** `web.config` next to every
sensitive directory in this tree — `sql\`, `tools\`, `tests\`, `specs\`,
`inc\`, `apache\`, `coordination\`, `drafts\`, and `services\` (with a
narrower override in `services\meshtastic\` and `services\meshcore\` that
still allows the two `.py` bridge scripts the Mesh Console tells you to
download). It is the same four lines every time:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="false" />
      </requestFiltering>
    </security>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
```

That is **Request Filtering**, which is part of a default IIS installation, so
there is no role service to add. It denies every URL that carries a file name
extension — the archive itself, not merely the directory listing — and
extension-less URLs such as `GET /tools/` with it. A denied request answers
`404`, logged with substatus **404.7**.

> **If you installed v4.2.3 or v4.2.4, replace those two files.** The v4.2.3
> version was invalid and returned **HTTP 500.19** on a stock IIS install
> instead of denying. The directory was unreachable, but only because the
> configuration was broken — the deny rule itself never ran, and anything that
> made the file parse would have opened the directory. v4.2.4 corrected it using
> IIS **URL Authorization**, which works but depends on an optional role
> service; where that service is absent the file 500s again, so the shipped
> files now use Request Filtering instead. A `git pull` picks them up. Why the
> mechanisms differ, and how to add URL Authorization as an optional extra
> layer, is in [`WEB-SERVER-HARDENING.md`](WEB-SERVER-HARDENING.md#iis-windows).

Full instructions and the test to prove it worked are in
[`WEB-SERVER-HARDENING.md`](WEB-SERVER-HARDENING.md#iis-windows). Do not skip the
test — this is the class of problem where everything looks fine right up until it
is found by someone else. **A `200` means exposed; on IIS a `500` means your
`web.config` is broken, not that you are protected — you want `404` (`401` and
`403` are fine too if you added the optional URL Authorization block).** Ask for
a backup **archive by name**, not just for `/backups/`: a directory that answers
`403` can still hand over every file inside it.

TicketsCAD also probes its own public URLs and reports the result on
**Settings → System Health**, so a later `applicationHost.config` edit that re-opens one
of these gets noticed — now including `.git/HEAD` and
`vendor/composer/installed.json` specifically.

> **`.git\` and `vendor\` are different: TicketsCAD writes their `web.config`
> itself, automatically.** Neither can ship as a tracked file in the git
> repository — `.git\` is git's own internal directory (nothing inside it
> exists in any commit), and `vendor\` is excluded by `.gitignore`, which
> blocks re-including anything inside an excluded directory even by name.
> Instead, the app writes both directories' `web.config` the first time any
> page loads after you `git clone` and `composer install` — you do not need
> to do anything by hand for these two. If you ever see either flagged as
> exposed on the System Health page, load any page in a browser once (that is
> the trigger) and re-check.

There is a second layer regardless of web server: every script under `sql\` and
`tools\` refuses to run under a non-CLI SAPI. That is the only protection that
works in any configuration, and it is why an exposed `sql\run_migrations.php`
cannot be triggered over HTTP even if your `web.config` is missing.

### Where the database backups go — check this one by hand

A backup archive is a complete copy of everything in your system, so it is the
single worst file to leave published. On Windows, "outside the web root" is not
where you would guess.

**`C:\inetpub\wwwroot` is not a safe place.** It is the physical path of
**Default Web Site**, which IIS binds to `*:80` on every stock install. A folder
in it is public even when TicketsCAD answers on some other port and carries all
the deny rules above — the deny rules belong to *your* site, and this is a
different one. Run `Get-Website` and look at the `Physical Path` column if you
want to see it for yourself.

This bit TicketsCAD itself. v4.2.3 fixed a real problem by moving backups to
`..\backups`, which is above the web root on Linux and is `C:\inetpub\wwwroot`
here — so on Windows the fix published the archives on port 80 and then reported
the install healthy, because its self-check only ever asked TicketsCAD's own
address. From v4.2.4 the Windows default is:

```
C:\ProgramData\TicketsCAD\backups
```

`C:\inetpub\backups` is equally safe if you would rather keep the archives on
the same volume as the site — `C:\inetpub` is not the physical path of any site.
Whatever you choose, set it in **Settings → Backup / Maintenance → Backup folder**; that
overrides the default and is the fix that needs no shell.

The application pool identity must be able to write there. From an **elevated**
PowerShell prompt, with your pool name:

```powershell
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\TicketsCAD\backups'
icacls 'C:\ProgramData\TicketsCAD\backups' /grant 'IIS AppPool\<YourPool>:(OI)(CI)M'
```

If you are upgrading and already have archives in the old place, move them and
then confirm nothing is left:

```powershell
Move-Item -Path 'C:\inetpub\wwwroot\backups\ticketscad-*' `
          -Destination 'C:\ProgramData\TicketsCAD\backups\'
Get-ChildItem 'C:\inetpub\wwwroot\backups'
```

Anything that was sitting in a published folder should be treated as having been
downloadable — see
[`security/advisory-2026-07-30-exposed-directories.md`](security/advisory-2026-07-30-exposed-directories.md).

TicketsCAD checks the destination itself now, and will tell you on
**Settings → System Health** if the folder it is writing to turns out to be published —
including on port 80, which is the case that was missed. It also says, on the
same row, what its probe cannot see: other hostnames, other ports, and anything
behind a reverse proxy. Reported by @rjonesbsink.

### Where the encryption keys go — the same trap, one directory over

The keys directory holds `private.pem` (which decrypts field-encrypted form
data), `public.pem`, and `tfa.key` (which decrypts **every enrolled
authenticator**). It had the identical defect, and for the identical reason:
until v4.2.4 it was always a sibling of the install directory, so on this
platform it was

```
C:\inetpub\wwwroot\keys
```

— inside Default Web Site again. @rjonesbsink confirmed it was being served:
`GET http://localhost/keys/_probe.txt` returned **200**. A request for
`private.pem` returned 404.3, but only because IIS has no MIME mapping for
`.pem`; that is an accident of the file's name, not a control, and on Apache the
same layout hands the key over as plain text.

From v4.2.4 the Windows default is:

```
C:\ProgramData\TicketsCAD\keys
```

```powershell
New-Item -ItemType Directory -Force -Path 'C:\ProgramData\TicketsCAD\keys'
icacls 'C:\ProgramData\TicketsCAD\keys' /grant 'IIS AppPool\<YourPool>:(OI)(CI)M'
```

**If you already have key files, they are not moved for you.** TicketsCAD keeps
using the old directory for as long as it holds any of the three files, because
half a key move is worse than the exposure: without `tfa.key` every 2FA user is
locked out at once and there is no way back. Settings → System Health names the
directory, says whether it is published, and prints the copy → verify → delete
sequence. Do it when nobody is signing in.

> If **Settings → Two-Factor Auth → Migrate to Dedicated Key** told you
> to "check directory permissions", read the path in that message before you act
> on it. On the reported install the only thing keeping the 2FA key out of a
> folder published on port 80 was that IIS could not write there. Granting write
> access would have completed the exposure, not fixed it. The message names the
> directory now, and warns when it is one nothing should be written to.

To keep the keys somewhere else entirely, add this to `config.php`:

```php
define('FE_KEYS_DIR', 'C:\\ProgramData\\TicketsCAD\\keys');
```

See
[`security/advisory-2026-08-03-fe-keys-dir.md`](security/advisory-2026-08-03-fe-keys-dir.md).

---

## 5. The background jobs need Task Scheduler

**This one is not cosmetic and it does not announce itself.** TicketsCAD has
five background jobs. On Linux they are driven by systemd timers. Windows has
no systemd, so unless you create a scheduled task **they never run at all** —
and everything else keeps working, so there is nothing to notice.

| Job | What stops happening if it never runs |
|---|---|
| `par_tick` | PAR checks are initiated but never time out. A unit that fails to answer is never marked missed — the check appears to run and silently never completes. |
| `pending_messages_tick` | Queued notifications — push, webhooks, SMS, e-mail, Slack — stay queued instead of going out, along with messages held for a security label's kill window. |
| `channel_receive_tick` | Inbound Telegram/Slack messages never get routed to a sender's assigned incident, on any install that has opted a channel in (off by default — harmless if you haven't). |
| `audit_log_purge_tick` | If Settings → Audit Log → Retention is configured with a nonzero window, old audit-log rows are never archived or removed — the table just keeps growing. Disabled by default. |
| `message_log_purge_tick` | Same as above, for the outbound message log (Settings → Pending Messages → Message Log Retention). Disabled by default. |

The last two are documented as "run once a day" — that describes how often
they actually find work to do, not how often it's safe to call them. Both
are simple cutoff-date queries and are harmless (a cheap no-op) to run every
minute alongside the others, which is exactly what the shipped runner does.

On the install that reported this, the first manual run cleared a backlog that
had been accumulating since install day:

```
par_tick: cycles_started=0 units_missed=0 units_expired=19 cycles_expired=16
```

Nineteen units and sixteen cycles, none of which had ever timed out.

### Create the task

One entry, every minute, running all five ticks. One minute is Windows'
minimum repeat interval and it matches every job's polling interval. From an
**elevated** prompt, with the path adjusted to your install:

```powershell
schtasks /Create /TN "TicketsCAD Background Jobs" /SC MINUTE /MO 1 `
  /RU SYSTEM /RL HIGHEST /F `
  /TR "C:\inetpub\wwwroot\TicketsCAD\tools\run-scheduled-jobs.bat"
```

`tools\run-scheduled-jobs.bat` ships with TicketsCAD. It uses `php` from `PATH`;
set `TICKETSCAD_PHP` to a full path if PHP is not on `PATH`. It must be
**`php.exe`, not `php-cgi.exe`** — both scripts refuse to run under a non-CLI
SAPI.

### Verify it is *firing*, not merely registered

These look identical in the Task Scheduler UI, and a registered task that never
fires is the failure this whole section exists to prevent. Watch the run counter
actually move:

1. Settings → **System Health** → **Scheduled background jobs**, note the **Runs** column
2. Wait ~75 seconds
3. Reload — the count must have gone up, and **Last success** must be recent

Or from the shell:

```powershell
schtasks /Query /TN "TicketsCAD Background Jobs" /V /FO LIST
```

If the job has still never run, Settings → System Health says so and tells you what to
check. Prior to v4.2.3 it told you to run `systemctl`, which does not exist here
— if you see that, your install predates this page.

### The Zello proxy has the same gap

`proxy/newui-zello-proxy.service.example` is a systemd unit with
`Restart=on-failure` and log redirection; there was no Windows equivalent, and
`proxy/start-proxy.bat` ends in `pause`, so it is interactive-only and cannot
survive a logoff.

`proxy\start-proxy-service.bat` now ships alongside it — same restart loop, same
log redirection, no `pause`. Register it to start with the machine:

```powershell
schtasks /Create /TN "TicketsCAD Zello Proxy" /SC ONSTART `
  /RU SYSTEM /RL HIGHEST /F `
  /TR "C:\inetpub\wwwroot\TicketsCAD\proxy\start-proxy-service.bat"

schtasks /Run /TN "TicketsCAD Zello Proxy"      # start it now, without rebooting
```

`/SC ONSTART` is the part that matters: a proxy started by hand in a console
window does not come back after a reboot.

---

## 6. Windows client editions cap concurrent requests

This one is Microsoft's licensing, not a TicketsCAD setting and not something
the project can work around. It is here because self-hosting an evaluation on
Windows 11 Home is a reasonable thing to do, and the failure is baffling if you
do not know the cap exists.

**Windows client editions limit how many requests IIS will handle at once**,
regardless of configuration. On Windows 11 **Home** the ceiling is **3** —
measured, see below. Windows Server has no such limit. Windows Pro is reported
to allow more than Home, but only the Home figure here was measured; if you are
on Pro, measure it rather than assuming a number.

### What you would see

Not an error. The application is *intermittently* slow in a way that does not
correlate with load, and the live-update stream never starts. On **v4.2.5 and
earlier** the built-in check reported it like this, and sent you to the network:

```
No response yet from the live-update stream.
EventSource.readyState=0 after 9s. If it stays like this, a proxy or firewall
is likely blocking the long-lived connection.
```

That last sentence was wrong, and is corrected from v4.2.6 — see the end of this
section. `readyState=0` is CONNECTING — no headers ever arrived. Not a 401, not a 500,
not a dropped connection: the request was accepted and never served. On the
reported install the same `api/par.php` measured **63 ms** idle and
**23,194 ms** a minute later, on localhost, single tab, single user, no proxy.

### Why two SSE streams is enough to do it

`inc/navbar.php` opens one `EventSource` on `api/stream.php` for every page, and
the diagnostics page opens a second. Both are long-lived — visible in the IIS
log as concurrent 90-second requests from a single tab:

```
06:20:31  /api/stream.php  90314ms
06:21:35  /api/stream.php  90273ms
06:22:04  /api/stream.php  90317ms
```

With a ceiling of 3, that leaves **one** slot for everything else the
application does. On a normally provisioned host the same two streams cost one
spare connection and nobody notices.

### Confirming it

Measure the ceiling directly rather than trusting any setting. Fire 8 requests
that each sleep 6 s and time the batch:

```
8 concurrent -> ~6s   ceiling >= 8
             -> ~12s  ceiling 4
             -> ~18s  ceiling 3      <- Windows 11 Home
             -> ~24s  ceiling 2
```

None of the usual knobs move it. On the reported install `maxInstances=20`,
`maxConnections` unlimited, 12 logical CPUs, and `php-cgi.exe` never exceeded 4
processes under any load.

### What to do

- **Accept it for single-user evaluation.** Everything works; it is just slow in
  bursts and the diagnostics stream check may not complete.
- **Windows Server**, which has no client-SKU cap.
- **Serve the app with something other than IIS** — Apache or nginx on the same
  Windows box are not subject to it.

Whichever you choose, note the corollary: **any performance or multi-user
conclusion drawn on a Windows client edition is measuring the operating system,
not TicketsCAD.**

TicketsCAD's own diagnostics used to blame "a proxy or firewall" for this
symptom, which sent the reporter through proxy and firewall theories first. From
v4.2.6 a `readyState=0` timeout says the request was accepted but never answered
and names concurrency limits as the thing to check, because a response that
never arrives cannot have been a 502.

Reported by @rjonesbsink ([#29][i29]).

---

## Everything else

Follow [`INSTALL.md`](INSTALL.md) for the parts that are the same:

- Creating the database and user
- `config.php`
- `php sql\run_migrations.php`
- `php tools\create_admin.php`
- First login and post-install settings

Two notes on translating it:

- **Ownership.** The Unix `chown`/`chmod` steps have no direct equivalent. What
  matters is the same: the application pool identity (typically
  `IIS AppPool\<PoolName>`) needs **write** access to `uploads\` and `cache\`
  inside the site, plus the backup folder — which from v4.2.4 is **outside** the
  site, at `C:\ProgramData\TicketsCAD\backups`, and needs its own `icacls`
  grant (see [4](#4-iis-ignores-htaccess)). Everywhere else is **read**. Do not
  grant write access to the whole tree.

  A trap worth knowing about, because it does not present as a permissions
  problem: if the webroot loses its explicit user ACL, `BUILTIN\Users` still
  inherits `ReadAndExecute` from `wwwroot`, so **IIS keeps serving the site
  perfectly while you can no longer edit or `git pull` anything in it**. Nothing
  reports an error until you try to write. Restore it from an elevated prompt:

  ```powershell
  icacls "C:\inetpub\wwwroot\TicketsCAD" /grant "<user>:(OI)(CI)M" /T
  ```
- **Scheduled jobs.** Where the Linux guide uses systemd timers, use **Task
  Scheduler** — see [5](#5-the-background-jobs-need-task-scheduler) for the two
  jobs that TicketsCAD itself requires.

---

## If something else on Windows/IIS bites you

Open an issue at <https://github.com/openises/TicketsCAD/issues>. This page
exists because one administrator wrote up what he hit instead of working around
it privately, and every item on it now either fixes itself or is documented.
