# Web server hardening — which server needs which file

TicketsCAD is installed by pointing your web server at the folder you unpacked
it into. That is simple, and it has one consequence worth understanding: **every
folder in that tree is reachable from a browser unless the web server is told
otherwise.** Some of those folders should never be reachable — `backups/` holds
complete database dumps, `inc/` holds the file with your database password,
`sql/` and `tools/` hold command-line scripts.

TicketsCAD ships the rules to close this. **The rules only work on Apache.**
If you run nginx or IIS you must add the equivalent yourself — this page tells
you exactly what to add.

---

## Which do I need?

| Your web server | What protects you | You must… |
|---|---|---|
| **Apache** (XAMPP, Debian/Ubuntu `apache2`, the Docker image) | `.htaccess`, `sql/.htaccess`, `tools/.htaccess` — shipped, arrive with an update | Confirm `AllowOverride` is `All` or `FileInfo` in your vhost. On `AllowOverride None` Apache ignores every `.htaccess` **without warning**. |
| **nginx** | Nothing ships that helps you. nginx never reads `.htaccess`. | Install `docs/nginx/ticketscad-hardening.conf` — see below. |
| **IIS** (Windows) | `web.config` next to every sensitive folder — shipped for `sql/`, `tools/`, `tests/`, `specs/`, `inc/`, `apache/`, `coordination/`, `drafts/`, `services/` (+ narrower overrides in `services/meshtastic/` and `services/meshcore/`); `uploads/` and `cache/` too, but with the opposite shape — those two still serve the file types their endpoints actually accept, deny only script extensions and everything not on that list; written automatically at runtime for `.git/` and `vendor/` (can't ship via git — see below); `keys/` and `backups/` written automatically when created | Nothing, on a stock install. IIS ignores `.htaccess`. |
| **Caddy** | Nothing ships that helps you. | See below. |
| **Docker (the shipped image)** | Apache inside the container, with the shipped `.htaccess` | Nothing extra. `backups/` also lives outside the web root from v4.2.3. |

Whatever you run, **check it** — the last section of this page is a one-minute
test, and TicketsCAD now runs the same test for you on
**Settings → System Health → "Web exposure"**. Note the part about backups there: a
`403` on `/backups/` proves nothing, and you have to ask for an archive by name.

---

## The list

These folders are not part of the web interface and should return 403 or 404:

| Folder | Why it must not be served |
|---|---|
| `backups/` | Full database dumps. Everything in your system, in one file. |
| `inc/` | `inc/db.php` contains your database username and password. |
| `sql/` | Command-line migration scripts, including `run_migrations.php`, which applies database migrations with no login. |
| `tools/` | Command-line maintenance scripts — installers, schema repair, backup and restore, token minting. |
| `tests/`, `specs/`, `coordination/`, `drafts/`, `apache/` | Test suite, design notes, server config examples. No reason to publish them. |
| `vendor/` | Third-party PHP libraries. |
| `keys/` | Encryption keys, if your install has this folder inside the tree. |
| `services/` | Radio/mesh bridge sources — **with one exception**, below. |
| `.git/` | If you installed by cloning, this reconstructs your whole source tree. |

These folders **must stay reachable** — do not add them to any deny list:

`api/`, `assets/`, `proxy/`, `sw/`, `uploads/`, `cache/`, `documentation/`, and
the `.php` pages at the top level. `proxy/dmr-proxy.php` in particular is loaded
over HTTP by the radio widget, so blocking `proxy/` breaks push-to-talk.

### The one exception inside `services/`

Settings → Mesh Bridges (LoRa) gives you a command to install the Meshtastic bridge on your
radio computer, and that command downloads the bridge from your own server:

```
curl -sSfo $HOME/bridge_v2.py 'https://your-site/services/meshtastic/bridge_v2.py'
```

So `services/meshtastic/*.py` and `services/meshcore/*.py` stay downloadable.
Everything else under `services/` does not — a running install keeps
`listener.ini` there (it contains your APRS-IS passcode), possibly `.env` files,
and `services/*/logs/`.

On IIS this is two `web.config` files at different depths, not one:
`services\web.config` denies the whole folder, and
`services\meshtastic\web.config` / `services\meshcore\web.config` each
override it for their own subtree with `<clear />` plus a single
`<add fileExtension=".py" allowed="true" />` — ordinary IIS configuration
inheritance (the `web.config` nearest a request wins). If you ever add your
own subfolder under `services\` that needs to serve something, copy that
narrow-override shape rather than loosening `services\web.config` itself.

---

## Apache

Nothing to install: `.htaccess`, `sql/.htaccess` and `tools/.htaccess` ship with
TicketsCAD and arrive with a `git pull`. Two things to check.

**1. `AllowOverride` must permit them.** In your vhost (`/etc/apache2/sites-available/…`):

```apache
<Directory /var/www/newui>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

`AllowOverride None` — the Apache default in some distributions — means every
`.htaccess` in the tree is ignored silently. There is no warning in the log.

**2. `Options -Indexes`.** Without it Apache generates a browsable listing for
any folder that has no index page. That is how `GET /backups/` came to show a
list of database archives. `-Indexes` must be set in the **vhost**, not in
`.htaccess`: `Options` needs `AllowOverride Options`, and on a host that grants
only `FileInfo` an `Options` line in `.htaccess` makes Apache return **500 for
the entire site**.

The shipped vhost template `apache/newui.conf.example` now has both, plus
`<DirectoryMatch>` denies that work even if `.htaccess` is ignored. Copying it
is the most robust option.

---

## nginx

**nginx never reads `.htaccess`.** Every `.htaccess` in the TicketsCAD tree is
an inert text file as far as nginx is concerned. On a default
`root /var/www/newui;` server block, `https://your-site/backups/…zip` downloads
your database and `https://your-site/sql/run_migrations.php` is handed to
PHP-FPM and executed.

Install the shipped snippet:

```bash
sudo cp docs/nginx/ticketscad-hardening.conf /etc/nginx/snippets/
```

Then inside the `server { … }` block that serves TicketsCAD:

```nginx
server {
    server_name cad.example.org;
    root /var/www/newui;
    index index.php login.php;

    include snippets/ticketscad-hardening.conf;

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

The snippet is written so it does not matter whether the `include` line comes
before or after your `location ~ \.php$` block — the denies use nginx's `^~`
prefix modifier, which beats regular-expression locations regardless of order.
That detail matters: a plain `location /sql/ { deny all; }` would **lose** to
the PHP location and the migration script would still run.

---

## IIS (Windows)

IIS ignores `.htaccess` as completely as nginx does. As of this version,
TicketsCAD ships a `web.config` next to every folder in "The list" above that
is a normal part of the git repository: `sql\`, `tools\`, `tests\`, `specs\`,
`inc\`, `apache\`, `coordination\`, `drafts\`, and `services\` (see the
`services\` note below — it is not a plain deny). Nothing to add by hand for
any of those on a stock install.

**`.git\` and `vendor\` are different, and worth understanding why.** Neither
can carry a git-tracked `web.config`: `.git\` is git's own internal
directory, so nothing inside it exists in any commit and nothing can arrive
there via `git clone` itself; `vendor\` is excluded by `.gitignore`'s
`/vendor/` directory pattern, and git's own rules block re-including a file
inside an excluded directory even by name. TicketsCAD writes both
directories' `web.config` **at runtime instead** — the first page load after
you `git clone` and `composer install` triggers it, and it is a one-line,
idempotent, best-effort call (`served_dir_harden()`, `inc/navbar.php`) that
never touches a file already there. You do not need to do anything for these
two on a normal install; **Settings → System Health** confirms both are
blocked (`.git/HEAD` and `vendor/composer/installed.json` are the two
canaries it asks for by name).

**`keys\` and `backups\`** are written the same runtime way, triggered when
the application actually creates or uses each directory — also nothing to add
by hand.

For reference, the shape every one of these files uses (the runtime-written
ones and the shipped ones are byte-identical in substance):

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

That is the whole file, and it is the same four lines in every folder — the two
shipped ones and any you add. TicketsCAD writes exactly this into the backup
folder as well, when it finds the backups somewhere a web server publishes.

### What it does, and what it does not

`allowUnlisted="false"` with nothing listed as allowed means **every request
whose URL has a file name extension is refused** — `.php`, `.sql`, `.zip`,
`.bak`, anything. That is the property that matters here: it denies **the file**,
not merely the listing. The report that started this work was `/backups/`
answering `403` while the archive inside it answered `200` and handed over a
complete database export. A rule that hides the index and serves the file is not
a fix.

| Request | Answer |
|---|---|
| `GET /backups/archive.zip` | 404 — logged as **404.7**, "File Extension Denied" |
| `GET /sql/run_migrations.php` | 404 / 404.7 |
| `GET /tools/` — no extension at all | 404 / 404.7 (see below) |
| `GET /tools/does-not-exist.php` | 404 / 404.7 — filtering runs before the file is looked up, so a real script and an imaginary one answer identically |

**Extension-less URLs are refused too**, and that is the one part a reader
should not have to assume. With `allowUnlisted="false"` IIS treats "no
extension" as unlisted, so a request for the folder itself is denied on the same
rule. It is why extension-less applications (ASP.NET MVC) have to add
`<add fileExtension="." allowed="true" />` before they work at all; Microsoft's
own note on the subject is titled *"Getting 404.7 error for '/' root requests
after Disabling Allow Unlisted file extension"*. **Do not add a `.` entry to
these files** — it would re-open every folder URL in one line.
`<directoryBrowse enabled="false" />` stays in the file anyway, as an
independent second stop for exactly that case.

Two limits, stated rather than glossed:

* **Inherited allow-lists.** If your server or site already sets
  `allowUnlisted="false"` *and* allow-lists extensions — a hardened setup, not
  a stock one — those entries are inherited into these folders and an
  allow-listed extension would still be served out of them. Stock IIS ships
  `allowUnlisted="true"` with every listed extension denied, so out of the box
  nothing is inherited as allowed. If yours is the hardened case, add
  `<clear />` as the first child of `<fileExtensions>`.
* **This is not the last line of defence.** Every script under `sql\` and
  `tools\` refuses to run under a non-CLI SAPI and answers `403 CLI only`
  before touching the database. That one works on any server in any
  configuration, including none of this.

### Why this and not `<authorization>`

IIS has a second mechanism, URL Authorization
(`<security><authorization><add accessType="Deny" users="*" />`), and v4.2.4
briefly shipped it. It denies correctly and was measured at `401`. It is not
what TicketsCAD ships, for one reason: **Request Filtering is in the default IIS
feature set and URL Authorization is an optional role service.** A `web.config`
naming a section whose module is absent does not fall back to something safe —
IIS answers **500.19** for the whole folder. That blocks by accident, it is
precisely the v4.2.3 bug, and it is what leads an administrator to delete the
file and re-open the folder.

The `401` was measured on a machine where the reporter had just run DISM to
install the feature (he later concluded it had been there all along). So the
`500` failures are confirmed on an untouched host; a `401` on a host where URL
Authorization was never installed is not. A capable administrator investigating
his own machine first concluded the feature was missing — which is the whole
point: you cannot tell by looking.

**Optional extra layer.** If you know the role service is installed and you want
belt and braces, add the `<authorization>` block *underneath* the file-extension
rule — never instead of it:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <requestFiltering>
        <fileExtensions allowUnlisted="false" />
      </requestFiltering>
      <authorization>
        <remove users="*" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
    <directoryBrowse enabled="false" />
  </system.webServer>
</configuration>
```

Install the role service first — Server Manager → Add Roles and Features → Web
Server (IIS) → Security → URL Authorization, or on Windows client
`Enable-WindowsOptionalFeature -Online -FeatureName IIS-URLAuthorization`. If
`web.config` is rejected with *"This configuration section cannot be used at
this path"*, that is the feature missing.

**Copy the block exactly.** Its three lines each look optional and none of them
are; v4.2.3 got all three wrong at once:

| What is easy to write instead | What IIS does |
|---|---|
| `<authorization>` directly under `<system.webServer>` | 500.19 — URL Authorization lives under `<security>` |
| `<deny users="*" />` | 500.19 — that is the ASP.NET element (`system.web`). IIS URL Authorization uses `<add accessType="Deny" …>` |
| `<add accessType="Deny" users="*" />` with no `<remove>` first | 500.19, `0x800700b7` — `applicationHost.config` already has a `users="*"` entry and the collection is keyed on `users`, so yours is a duplicate |

> **Do not add these directory names to site-level Hidden Segments.** Request
> Filtering's hidden segments match **any** segment of the path, not just the
> first, so a site-level `vendor` entry also blocks `assets/vendor/…` and every
> page loses its CSS. The same applies to `tests` (`services/audio-matrix/tests/`)
> and `backups` (`tools/upgrade/backups/`). Per-directory `web.config` files, as
> above, only affect the directory they sit in.

### What still needs confirming on a real IIS host

The behaviour above is read off Microsoft's documentation. TicketsCAD has a test
that models it (`tests/test_iis_webconfig_syntax.php`) but no live IIS, so if
you run one, these are the five checks worth a minute of your time — and please
report anything that disagrees:

1. The shipped `sql\web.config` loads without a 500.19 on a **stock** IIS —
   i.e. `system.webServer/security/requestFiltering` is unlocked for
   `web.config` in a default install, as it is believed to be.
2. `GET /sql/run_migrations.php` answers 404, and the IIS log line for it ends
   in substatus **7**.
3. `GET /tools/` — with no extension — answers 404 as well, rather than a
   directory listing or a default document.
4. `GET /backups/<a real archive>.zip` answers 404. This is the one that
   matters most.
5. `GET /tools/nothing-here.php` answers the same as a real script, with no
   difference a scanner could use to enumerate what exists.

### Site-wide Request Filtering (add to YOUR OWN web.config, not ours)

Every `web.config` TicketsCAD ships is scoped to one directory — `sql/`,
`tools/`, `uploads/`, and so on — and none of them apply site-wide, on
purpose: a broken rule at the site root breaks the whole site (see the
`hiddenSegments` warning above, which did exactly that to `assets/vendor/`
on two live installs). TicketsCAD ships **no web.config at the repository
root**, so it cannot fold the settings below into anything a `git pull`
delivers automatically.

These four are genuinely site-wide (they should apply to every request,
not one directory), which is exactly why they belong in **your own**
site-level `web.config` or in `applicationHost.config` — a file only you
control, not one this project can safely ship. This is a fragment to
merge into the `<security><requestFiltering>` your site-level config
already has (or a new one, if it has none) — **do NOT** also add
`<fileExtensions allowUnlisted="false" />` at the site root the way the
per-directory files above do. That denies every extension for the whole
application, not one directory, and would 404 TicketsCAD entirely:

```
<requestLimits maxAllowedContentLength="26214400"
                maxUrl="4096" maxQueryString="2048" />
<verbs allowUnlisted="true">
  <add verb="TRACE" allowed="false" />
</verbs>
```

- `maxAllowedContentLength` (bytes, default 25 MB shown above) — caps
  request body size. Raise it if your largest configured upload type
  needs more headroom than the default; `api/upload.php`'s own
  `$ALLOWED_EXT_MIME` allowlist and PHP's `upload_max_filesize` /
  `post_max_size` are the other two places a limit like this lives, and
  the smallest of the three wins in practice.
- `maxUrl` / `maxQueryString` (characters) — reject abnormally long
  requests before they reach PHP at all. The defaults above are generous
  for TicketsCAD's own routes; tighten them if your install has no
  reason to see a longer URL.
- The `TRACE` verb block closes the classic Cross-Site Tracing (XST)
  probe, which can read cookies marked `HttpOnly` back out through the
  TRACE echo. TicketsCAD's own session cookie is already `HttpOnly` (see
  `SECURITY-POLICY.md`), so this is defense in depth, not a gap this
  project has hit — block it anyway; nothing in the app issues TRACE.

Every directory-scoped `web.config` this project ships already carries
`allowDoubleEscaping="false"` (rejects double-encoded path segments like
`..%255c..`, which can otherwise smuggle a request past extension- or
path-based rules) — see the `<security><requestFiltering>` block in any
of them for the shape if you want it at the site level too.

### Disable the WebDAV role feature

If the WebDAV Publishing role feature is enabled, its `PUT`/`MOVE`/`PROPFIND`
verbs can write or move files by a path Request Filtering's *extension*
rules never see — a request whose HTTP verb is `PUT` isn't asking to
execute anything, so an extension-based deny does not apply to it the same
way. TicketsCAD's install docs never ask for WebDAV and nothing in the
application uses it. Remove it (PowerShell, run as Administrator):

```powershell
Uninstall-WindowsFeature Web-DAV-Publishing
```

Or via Server Manager: **Remove Roles and Features → Web Server (IIS) →
Common HTTP Features → WebDAV Publishing → uncheck**. If your install
ever needs a WebDAV-based publishing workflow for something unrelated to
TicketsCAD, scope it to its own site or application pool — never the one
serving this application.

---

## Caddy

Add a matcher to your site block:

```caddyfile
cad.example.org {
    root * /var/www/newui

    @blocked path /backups/* /inc/* /sql/* /tools/* /tests/* /specs/* \
                  /coordination/* /drafts/* /apache/* /vendor/* /keys/* \
                  /.git/*
    respond @blocked 404

    @services path /services/*
    @bridge   path /services/meshtastic/*.py /services/meshcore/*.py
    respond @services 404
    file_server @bridge

    php_fastcgi unix//run/php/php8.2-fpm.sock
    file_server
}
```

Caddy evaluates directives in a fixed order, not file order; if the bridge
download stops working, raise its `file_server` above the `respond` with an
explicit `handle` block.

---

## Check your own install

Run these from any machine, replacing the host. **Anything that answers `200`
is a problem.** `401`, `403`, `404` or a connection refusal are all fine.

```bash
curl -s -o /dev/null -w 'sql   %{http_code}\n' https://your-site/sql/run_migrations.php
curl -s -o /dev/null -w 'tools %{http_code}\n' https://your-site/tools/
```

### Backups: ask for an archive, not for the folder

> **A `403` on `/backups/` does not mean your backups are protected.** Reported
> 2026-08-02 by @rjonesbsink, measured on a live install: the folder answered
> `403` while the archive inside it answered `200` and served in full — the
> complete database export. That is the ordinary behaviour of a server with
> directory listing off and no rule denying the files, and it is exactly the
> check a worried admin reaches for first.

Get a filename from **Settings → Backup / Maintenance** (it lists every archive this install
has written), then ask for that file:

```bash
curl -s -o /dev/null -w 'archive %{http_code}\n' \
     https://your-site/backups/ticketscad-20260728-020000.zip
```

Only a request for a file answers this question. If you have no archive yet,
take one first (Settings → Backup / Maintenance → "Back up now") — until then this is
**untested**, which is not the same as protected.

On IIS, **`500` is not a pass.** It means the `web.config` in that directory is
invalid, so the deny rule is not running — the folder is unreachable only for as
long as the file stays broken, and the message tells an attacker exactly where
your application lives. Fix the file until the same request answers `404` — the
shipped rule denies by file extension, and a denied request is a 404 logged with
substatus `404.7`.

TicketsCAD runs the same probes against itself and reports the result on
**Settings → System Health**, in the "Web exposure" row of the File & Code Health card.
For backups it asks for a named archive; when there is no archive to name it
writes a small random self-test file into the folder and asks for that back
instead. If it can do neither, the row reads grey **"Not determined"** rather
than green — an untested install is not reported as a safe one. It is checked
on every visit to that page, so it will tell you if a server upgrade or a config
change ever re-opens one of these.

---

## Belt and braces: why this is not the only defence

Web-server rules are the outer fence, and every install configures its server
differently, so TicketsCAD does not rely on them alone:

* **Every script under `sql/` and `tools/` refuses to run over HTTP.** The first
  line of each is a check that the script was started from a command line; over
  HTTP it answers `403 CLI only` and stops before touching the database. This
  works on any web server with any configuration, including one where the deny
  rules were never installed.
* **Backups are written outside the web root**, and where that is depends on the
  platform:

  | Platform | Default backup directory |
  |---|---|
  | Linux, macOS, the Docker image | `../backups` — a sibling of the install directory |
  | Windows (IIS, XAMPP, anything) | `%ProgramData%\TicketsCAD\backups` |

  **Why Windows is different, and why you should check yours.** v4.2.3 used
  `../backups` everywhere. That is above the web root on `/var/www/newui`, and
  it is `C:\inetpub\wwwroot` on a stock IIS install — the physical path of
  **Default Web Site**, bound to port 80. So the "safe" location was another
  published site, on a port TicketsCAD does not use, carrying none of the rules
  above; a complete database archive was reachable at
  `http://<host>/backups/<archive>.zip`. Corrected in v4.2.4, which also keeps
  listing anything v4.2.3 left there and reports it as Critical. Reported by
  @rjonesbsink.

  `..` is only "outside the web root" if you know what `..` is. Two more layouts
  where it is not: `C:\xampp\htdocs\newui` (`..` is the XAMPP DocumentRoot) and
  `/var/www/html/newui` (`..` is the stock Apache DocumentRoot on Debian and
  Ubuntu). **Settings → Backup / Maintenance → Backup folder** overrides the default on every
  platform and is the reliable fix — point it anywhere outside every site.
* **The encryption keys are written outside the web root too** — and the rule is
  the same shape, for the same reason, because it was the same bug
  (GHSA-3jmh-c6f6-64jc, v4.2.4):

  | Platform | Default keys directory |
  |---|---|
  | Linux, macOS, the Docker image | `../keys` — a sibling of the install directory (unchanged) |
  | Windows (IIS, XAMPP, anything) | `%ProgramData%\TicketsCAD\keys` |

  Until v4.2.4 it was `../keys` everywhere, which on `C:\inetpub\wwwroot\<app>`
  means `C:\inetpub\wwwroot\keys` — inside Default Web Site. It was confirmed
  served: `GET /keys/_probe.txt` → **200**. `GET /keys/private.pem` returned
  404.3, but only because IIS ships no MIME mapping for `.pem`; add one for any
  unrelated reason and the key is served, any mapped extension in that directory
  already is, and **Apache serves `.pem` as plain text** with no MIME allow-list
  to fall back on.

  **Existing keys are never moved for you.** If the old directory still holds
  `private.pem`, `public.pem` or `tfa.key`, that is the directory this install
  keeps using — so an upgrade cannot break field encryption or lock every 2FA
  user out — and Settings → System Health reports it, names it, and prints the
  copy → verify → delete sequence. Override with
  `define('FE_KEYS_DIR', '/your/path');` in `config.php`.

  TicketsCAD also writes a `web.config` and a `.htaccess` into the keys
  directory whenever it creates or touches it, wherever that directory is. Those
  are the same Request Filtering rules described above. They are a mitigation,
  not a reason to leave a private key in a published folder.
* **The Status page probes itself** and says so loudly if any of the three paths
  is still reachable. It also writes a small random file into the backup
  directory and asks this host for it back on the **default** ports — the only
  way to prove from inside the application that another site is publishing that
  folder. A positive result is certain, because the response body has to contain
  a token generated moments earlier.

  **What it cannot see, stated plainly:** it probes the address this install
  answers on, plus ports 80 and 443 on the same hostname. A directory published
  under a different hostname, on some other port, or through a reverse proxy is
  outside its reach, and the Status page says so on the row rather than
  reporting a clean bill of health. Use the `curl` checks above against every
  hostname and port your server answers on.

See also: [`SECURITY.md`](../SECURITY.md), [`docs/SECURITY-POLICY.md`](SECURITY-POLICY.md),
[`docs/security/advisory-2026-07-30-exposed-directories.md`](security/advisory-2026-07-30-exposed-directories.md),
[`docs/security/advisory-2026-08-03-fe-keys-dir.md`](security/advisory-2026-08-03-fe-keys-dir.md).
