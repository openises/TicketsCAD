# Security policy

TicketsCAD v4 is used by emergency-communications volunteer groups; we
take security seriously. Thank you for taking the time to report
problems responsibly.

## Supported versions

TicketsCAD is maintained by one volunteer. The support model below is
deliberately modest, because a modest policy that is actually met beats an
ambitious one that is not: **one supported line at a time, and that line is the
newest release.**

| Release line | Status |
|---|---|
| **v4.2.x** (current) | **Supported.** Security fixes and bug fixes land here. |
| v4.0.x – v4.1.x | Not supported. Fixes are published in the next v4.2.x release — upgrade to the newest tag. |
| **v3.44.x** (legacy, [`openises/tickets`](https://github.com/openises/tickets)) | **Security and bug fixes only.** No new features; v4 is where development happens. |
| v3.43.x and earlier | End of life. No fixes of any kind. |

**There are no backports and no long-term-support branch.** A fix ships as a new
tag on the current line; it is not applied to an older one. One person cannot
maintain parallel branches honestly, so we do not claim to.

**How a version reaches end of life:** the moment a newer version is tagged. In
practice that is less abrupt than it sounds — releases are small and frequent,
and for a git install the upgrade is `git pull` (see
[`docs/UPDATE-CHECKLIST.md`](docs/UPDATE-CHECKLIST.md)). It does mean that "I am
on 4.1.0 and X is broken" is answered with "please upgrade and tell us whether it
is still broken."

**How to tell what you are running:** Help → About shows the running version. It
reads the git-tracked `VERSION` file, so `git pull` moves it. Compare it against
https://github.com/openises/TicketsCAD/releases — `CHANGELOG.md` carries a dated
section for every release.

**Release cadence:** none is promised. Releases go out when there is something
worth shipping, which has meant several in a week and also nothing for a while.
Watch the repository on GitHub if you want to be told when one lands.

**Security issues in the legacy v3.44 line** go to the private channels below,
not to a public issue on `openises/tickets`. That repository has no security
policy of its own; the channels here cover both.

## Reporting a vulnerability

**Do not open a public issue.** Use one of the following private
channels:

1. **GitHub Security Advisories** — https://github.com/openises/TicketsCAD/security/advisories/new
2. **Email the maintainer** — `ejosterberg@gmail.com`. Use a subject line
   that begins with `[TicketsCAD security]`.

Please include:

- Affected version (commit SHA or `NEWUI_VERSION`)
- A clear description of the issue and the impact
- Steps to reproduce — a minimal proof-of-concept request, payload, or
  setup is ideal
- Whether the issue has been disclosed publicly anywhere

We aim to acknowledge new reports within **3 business days** and provide
a remediation timeline within **10 business days**. Critical issues
(authentication bypass, RCE, data loss, mass account takeover) are
prioritized for same-week patches.

## Reports produced with AI assistance or automated tooling

**AI-assisted reports are welcome. Unverified AI output is not.**

Automated and model-assisted vulnerability discovery has made it very easy to
generate a large number of plausible-looking reports, and a small volunteer
project is exactly the kind of maintainer that gets buried by them. So this
policy states the expectation up front rather than after the fact.

**Send it if you have done this much:**

- **You reproduced it against a running instance** and can say what you did and
  what happened. A finding that only exists as a model's reading of the source
  is a hypothesis, not a report.
- **The affected file and line are real** — quote them. Tools routinely cite
  functions and parameters that do not exist in this codebase.
- **The impact is stated concretely**: what can an attacker do, as which role,
  against which endpoint.
- **You say that tooling was involved, and which.** This is not held against
  you. It tells the triage where to look first and is genuinely useful.

**What will be closed without a detailed reply**, because triage time is the
scarcest thing here:

- Raw scanner or model output pasted in with no reproduction and no analysis.
- Findings against code that is not ours — `vendor/` and `assets/vendor/` are
  third-party (see Scope below).
- Reports whose file, line, function or parameter does not exist in the version
  named. This is the most common failure mode of generated reports.
- "Best practice" observations with no exploitable consequence — a missing
  header on an endpoint that returns no sensitive data, for example.
- Bulk submissions of many low-quality findings at once. A single verified issue
  is worth more than thirty speculative ones and will be treated accordingly.

None of this changes the response commitments above for a report that meets the
bar. It is also not a judgement about the tools: several genuine issues in this
project were found with automated help. The line is verification, not authorship.

## Scope

In scope:

- The PHP code in `api/`, `inc/`, top-level pages, and `proxy/`
- The migration tooling in `tools/install_fresh.php`,
  `tools/import-fcc.php`, and the test suite
- The shipped systemd unit and Apache `.htaccess` files
- The schema as defined in `sql/` and applied by `install_fresh`

Out of scope:

- The legacy `openises/tickets` v3.x codebase, for *functional* bugs — file
  those on that repository. **Security** reports for v3.44 come here instead,
  through the private channels above; see "Supported versions".
- Vulnerabilities **in the upstream code** of third-party libraries under
  `vendor/` and `assets/vendor/` — file those with the upstream project.
  Every one of those libraries is enumerated with its version in our
  published SBOM (see below), so you can identify and report them precisely.
  **In scope for us:** telling us that a library we ship is outdated,
  vulnerable, or wrong in the SBOM. Shipping a known-vulnerable version is
  our problem to fix, even when the defect is upstream.
- Penetration testing of a running instance — please coordinate with the
  operator first
- Findings on a clearly out-of-date deployment that has missed published
  patches

## What you can expect from us

- Confirmation of receipt
- A CVSS or severity assessment
- Coordinated disclosure — we ask for **45 days** to patch + ship before
  public disclosure, longer for issues that require schema changes
- Credit in the audit doc and release notes if you want it

## What TicketsCAD sends outside your network

Your dispatch data is not exported anywhere by default, and **there is no
telemetry, usage reporting, analytics, or update check of any kind** — nothing
in TicketsCAD contacts this project or reports on your installation. A number of
optional features do talk to third parties, and one of them is an AI feature
that can send amateur-radio transcripts to a commercial API. All of it is listed
here so that none of it is a surprise.

### AI features

TicketsCAD **uses** AI services; it does not ship AI models. No model weights are
in this repository.

#### Radio AI — the one feature that sends content to a hosted LLM

**Off by default.** `radio_ai_enabled` is seeded to `0`
(`sql/run_phase85f_radio_ai.php`), and **four** separate things must all be true
before a single byte leaves your server:

1. `radio_ai_enabled` is set to `1`.
2. An Anthropic API key exists at `/etc/ticketscad/anthropic.env`. It is never
   shipped, and no installer creates it — you create it by hand.
3. The `inc/radio_ai_listener.php` daemon is running as a separate process.
4. A DMR radio bridge with speech-to-text is installed and feeding it transcripts.

Miss any one of the four and the feature does nothing at all.

**What is sent when it is switched on** — to `https://api.anthropic.com/v1/messages`,
model `claude-sonnet-4-6` (`inc/radio_ai_client.php`):

- the speech-to-text transcript of the amateur-radio transmission that contained
  the wake word (`claude` by default);
- the caller's amateur callsign and DMR radio ID;
- up to five previous exchanges with that same callsign within a 30-minute window;
- a fixed system prompt containing the operator's callsign and the server's local
  date and time.

**What is never sent: anything from the CAD side.** No incidents, no roster or
personnel records, no patient information, no facilities, no locations, no user
accounts, no database content of any kind. The feature reads amateur-radio
transcripts out of `dmr_messages` and nothing else.

**One thing to be explicit about:** the request enables Anthropic's server-side
`web_search` and `web_fetch` tools, capped at three searches per question. So
Anthropic's servers may perform web searches derived from the caller's question.
That is a second, indirect path off your network, and an egress review should
account for it.

**An operator stays in the loop.** Generated replies are queued as drafts and a
licensed operator approves each one before it is transmitted. Nothing goes over
the air automatically unless an operator deliberately enables the auto-approve
toggle.

**How to keep it off, or turn it off:** nothing — it ships off. On an install
where it was switched on, set `radio_ai_enabled` to `0`, stop the listener
daemon, and delete `/etc/ticketscad/anthropic.env`. Any one of those three stops
it; all three make it unambiguous. Operator documentation:
[`docs/RADIO-AI-ADMIN-GUIDE.md`](docs/RADIO-AI-ADMIN-GUIDE.md).

#### Speech-to-text — runs locally

Transcription runs **on your own server**. Both supported engines — Vosk (the
default) and faster-whisper — load a local model and run in-process. No audio and
no transcript is sent anywhere for recognition. The model files are downloaded
once when you install the radio bridge (faster-whisper pulls from Hugging Face;
Vosk models you stage yourself). After that, recognition needs no network.

#### Text-to-speech — local by default

The default engine, and the bottom of every fallback ladder, is **Piper**, which
runs a local binary against a local voice model and opens no network connection.
`sql/run_tts_engines.php` seeds exactly one engine — `piper-default` — and points
every speech application at it.

Two optional drivers can send text to a third party. Neither exists until an
administrator creates it:

| Driver | Sends to | What leaves |
|---|---|---|
| `deepgram` | `https://api.deepgram.com/v1/speak` | the text to be spoken |
| `openai_compat` | whatever endpoint you configure — documented for a local server such as Kokoro, but it will accept `https://api.openai.com/v1` | the text to be spoken |

Each needs you to add an engine row **and** an API key, which TicketsCAD stores in a
file under the `keys/tts/` directory beside the 2FA keys — outside the web root. Neither is seeded, so a stock install synthesises speech entirely
offline.

### Other services TicketsCAD can contact

| Feature | Contacts | What leaves | Default |
|---|---|---|---|
| **Map tiles** | OpenStreetMap tile servers, or the provider you configure | Tile coordinates — which implies the area a dispatcher is looking at. **Requested by your server** for providers that permit proxying, and by the dispatcher's browser for the rest. See [Map tiles: proxy vs direct](#map-tiles-proxy-vs-direct) below. | **On** — OpenStreetMap is the fallback basemap when no provider is configured |
| **Address lookup (geocoding)** | `nominatim.openstreetmap.org` | The address a dispatcher types into an incident, unit or facility form, or the coordinates of a map click. **Requested by the browser, directly — geocoding is not proxied.** | **On** wherever a "Lookup" button appears |
| **Radar / weather overlays** | RainViewer, NOAA, Iowa State Mesonet | The map viewport | On when an operator enables the radar layer |
| **NWS weather alerts** | `api.weather.gov` | Your state or zone codes, plus the contact string you configure in the User-Agent (the National Weather Service requires one) | **Off** (`weather_alerts_enabled` = `0`) |
| **OpenWeatherMap overlay** | `*.openweathermap.org`, proxied by your server so the key never reaches the browser | Tile coordinates and your API key | **Off** — needs an API key |
| **Callsign lookup** | `opencallbook.com` (default), `callook.info`, or a self-hosted FCC ULS service | The callsign. By default the User-Agent also discloses this install's hostname — set `lookup_ua_detail` to `minimal` to stop that. | On when someone runs a lookup |
| **DMR ID lookup** | `database.radioid.net` | A DMR radio ID or a callsign, only for IDs not already cached locally | On, cache-first |
| **Email, SMS, Slack, webhooks, Web Push** | Your SMTP server; Twilio, BulkVS or Pushbullet; Slack; the webhook URLs you register; your users' browser push services | Only what you configure each to carry | **All off** — unconfigured out of the box |
| **Amateur radio networks** | APRS-IS, BrandMeister/DMR, Zello | Radio traffic. These are public networks by design — anything sent to them is public. | **All off** — unconfigured, and each needs credentials |

### Map tiles: proxy vs direct

Map tiles are the highest-volume outbound request TicketsCAD makes, and the one
that runs continuously. Each tile request tells the provider which patch of
ground is on screen; over a shift, that is a running account of where this
agency's incidents are.

**Settings → Tile Providers → Tile Mode** controls this, and as of
v4.2.3 it does something. (It did not before: the setting was stored, defaulted
to `proxy`, and read by nothing — every install fetched tiles directly from the
browser regardless of what the setting said. If you set it to `proxy` on an
older release, nothing happened.)

| Mode | Who fetches the tile | What the provider sees |
|---|---|---|
| `proxy` (default) | Your server, via `api/tile-proxy.php`, with an on-disk cache | Your server's IP and the User-Agent you configure. One request per tile per cache period, however many dispatchers view it. |
| `direct` | Each dispatcher's browser | Each dispatcher's IP, browser User-Agent, `Referer`, and every tile they pan across |

**What proxy mode does and does not achieve.** It stops the provider seeing
individual dispatchers — their IP addresses, browser fingerprints and
moment-to-moment panning — and the cache means a tile fetched once is not
fetched again. It does **not** make the map area private: your server still
requests tiles for the areas being viewed, so a provider can still infer roughly
where this install operates. The only way to keep map viewports entirely inside
your network is a tile server inside your network (`tile_provider` = custom).

**Not every provider can be proxied**, because not every provider's terms allow
a third party to cache and re-serve their tiles. TicketsCAD refuses rather than
breaching them on your behalf:

| Provider | Proxy | Basis |
|---|---|---|
| OpenStreetMap, OSM Humanitarian | **Yes** | OSMF tile policy permits caching proxies that cache, honour cache headers, identify themselves, and do not pre-fetch |
| USGS National Map (topo, imagery) | **Yes** | US federal public domain, no access constraints |
| OpenTopoMap | **Yes** | CC-BY-SA; the project asks for caching |
| Your own / custom tile URL | **Yes** | Your infrastructure, your call |
| CARTO (Light, Dark basemaps) | No — direct | Free use is scoped to CARTO grantees; no re-serving grant found |
| Esri ArcGIS Online (incl. Satellite) | No — direct | Master Agreement bars storing Data or offering it on behalf of third parties |
| Mapbox | No — direct | Product Terms forbid distributing tiles from a cache or by proxying |
| Google, Bing | No — direct | Terms forbid caching and non-SDK access; both are also unlicensed or retired for this use — migrate away |

Practical consequence: the **Dark**, **Light** and **Satellite** basemaps come
from CARTO and Esri, so those still contact their provider from the browser even
with proxy mode on. **Street** (OpenStreetMap) and **Terrain** (OpenTopoMap) are
proxied. An install that wants tiles never to reach a third party from a
dispatcher's browser should standardise on Street/Terrain, or configure USGS or
an internal tile server. Settings → Tile Providers shows the live per-provider
verdict and the current cache usage.

**Disk.** The cache is bounded: `tile_cache_max_mb` (default 512 MB) with
least-recently-used eviction, and `tile_cache_min_free_mb` (default 1 GB) of
free space that must remain — below it the proxy keeps serving maps but stops
writing new tiles. It never grows without limit.

**Where the cache lives.** `../tile-cache`, a sibling of the application
directory and **outside the web root** — the same treatment backups got in
v4.2.3. The cache is a record of which map areas this install has viewed; inside
the web root it would be readable by anyone who could guess a tile path, which
would disclose the very thing proxy mode conceals. No web-server configuration
is needed to keep it private. On Docker it is the `app_tile_cache` volume.

**Identify yourself.** OpenStreetMap blocks generic User-Agents. Leave
`tile_proxy_user_agent` blank for an automatic value naming this application and
your host, or set a contactable one such as
`Metro ARES CAD (dispatch@example.org)`.

**Geocoding is still direct.** The "Lookup" buttons send the typed address from
the browser to `nominatim.openstreetmap.org`. Tile mode does not affect that,
and it remains the outbound path most likely to carry an actual incident
address.

### Running fully offline or air-gapped

Several TicketsCAD deployments run disconnected by requirement. The core of the
product — incidents, units, roster, assignments, ICS forms, messaging, audit and
RBAC — needs no internet at all. Because there is no telemetry and no update
check, an isolated install does not silently retry, nag, or stall waiting for
something it cannot reach.

Two things do reach out by default, and on an isolated network they simply fail:

- **Map tiles** — the map renders but stays blank. In the default proxy mode the
  failing request is your *server's*, and the map degrades to blank tiles rather
  than breaking the page; markers, units and overlays keep drawing. Point
  `tile_provider` at a tile server inside your network if you have one.
- **Address lookup** — the "Lookup" buttons return nothing. Enter coordinates
  directly instead; nothing else depends on the geocoder.

Everything else is either local (speech-to-text, Piper text-to-speech) or off
until you configure it (Radio AI, weather, messaging, lookups, radio networks).
Stage your speech models while you still have connectivity and there is nothing
further to disable.

## Software Bill of Materials (SBOM)

Every release ships a Software Bill of Materials — a complete list of the
third-party code TicketsCAD contains, with versions and licences — so that
your organisation can answer "does the new vulnerability everyone is talking
about affect our dispatch system?" without reading our source code.

| File | What it is |
|---|---|
| `SBOM.cdx.json` | Machine-readable, CycloneDX 1.6 (ECMA-424). Readable by Dependency-Track, Trivy, Grype, and similar tools. |
| `SBOM.txt` | The same information as plain text, for people. |
| `SBOM.cdx.json.sig` | A detached digital signature over `SBOM.cdx.json`. |
| `SBOM-signing-key.pub.pem` | The public key that checks that signature. |

It is generated from the repository, not written by hand:

```bash
php tools/generate-sbom.php            # regenerate
php tools/generate-sbom.php --check    # fail if the committed SBOM is stale
php tools/generate-sbom.php --verify   # check the signature (needs no private key)
php tools/generate-sbom.php --validate # check it really is valid CycloneDX 1.6
```

`--check` and `--validate` both run in CI on every push and again in the release
script, so the SBOM can neither drift out of date nor stop conforming to the
schema it claims. `--validate` runs the reference JSON-Schema validator (ajv)
against the **official** CycloneDX schema, vendored unmodified at
[`tools/schema/cyclonedx/`](tools/schema/cyclonedx/) so you can check it
offline and diff it against upstream yourself. It needs Node.js on PATH.

This gate exists because we got it wrong: a `SBOM.cdx.json` that declared
`"specVersion": "1.6"` was published while it did **not** satisfy the 1.6
schema. One component carried the licence identifier
`GPL-2.0-with-FOSS-exception`, which SPDX does not define, so the document
failed validation outright and would have been rejected by tooling. Declaring
conformance is not the same as having it, and now something checks.

**Standard.** The SBOM is built to the *2026 Minimum Elements for a Software
Bill of Materials (SBOM)*, published 2026-07-29 by CISA, NSA, FBI and
international partners, which supersedes the 2021 NTIA minimum elements:
https://www.cisa.gov/resources-tools/resources/2026-minimum-elements-software-bill-materials-sbom

**Status: 17 of 17 data fields, 6 of 6 practices**, across 56 components.

### Verify the signature yourself

Do not take our word for any of this. The SBOM is signed, and you can check the
signature without contacting us and without trusting us. You need the three
files that ship with every release and OpenSSL, which is already on macOS and
Linux and comes with Git for Windows:

```bash
base64 -d SBOM.cdx.json.sig > sbom.sig
openssl dgst -sha256 -verify SBOM-signing-key.pub.pem -signature sbom.sig SBOM.cdx.json
```

`Verified OK` means the file is byte-for-byte the one signed with our key.
Anything else means it is not, and you should ask us why. If you would rather
not use the command line, `php tools/generate-sbom.php --verify` does the same
check.

| | |
|---|---|
| Algorithm | ECDSA on NIST P-256 with SHA-256, detached signature |
| Public key | `SBOM-signing-key.pub.pem`, in this repository |
| Public key SHA-256 fingerprint | `XRcJ3AwAm0OzSzjmU8KWkknftutwY36a6z7st2YrU0g=` |
| Private key | Held by the maintainer, off this repository and out of CI. See `docs/SECURITY-POLICY.md` §5.3 for custody, rotation and compromise handling. |

The fingerprint above is also recorded inside the SBOM itself
(`ticketscad:signature-public-key-sha256`). If you want assurance that the key
file is genuinely ours, compare that fingerprint against a copy you obtained
some other way rather than from the same download.

There is no certificate authority and no revocation service behind this key, and
we do not claim otherwise. Its trustworthiness rests on being published openly
in this repository, where a substitution would be visible in the git history.

**One thing we want to be straight about: unknowns are labelled, not guessed.**
Some components ship without any version marker; others — pip packages, Composer
packages, container base images, scripts the browser fetches from a CDN — are
installed on your server or fetched at runtime, so we have no artifact to hash
and cannot state a producer or a licence from evidence. Rather than assume,
those components carry a `ticketscad:unknown` property naming each field we
could not determine and a `ticketscad:unknown-reason` explaining why. The
guidance asks for exactly this ("Explicitly Identifying Unknown Information").
Nothing in the SBOM is withheld. The generator will not produce an SBOM in
which a required field is simply missing rather than declared.

## Existing audit + hardening posture

NewUI v4 went through a multi-session security audit in April 2026.
Every CRITICAL and HIGH finding has been remediated with a regression
test. The project's security posture, key management, and CJIS notes are documented
in [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).

Run the security tests locally before declaring an installation safe:

```bash
php tests/test_security_f001_upload.php          # upload RCE chain
php tests/test_security_f002_feed.php            # feed fail-closed
php tests/test_security_f003_fileupload.php      # legacy file-upload
php tests/test_security_f004_idor.php            # IDOR triplet
php tests/test_security_f007_sse_visibility.php  # SSE per-user filter
php tests/test_security_csrf_bundle.php          # CSRF on writes
php tests/test_api_csrf_coverage.php             # EVERY api/ write is behind a token check (derived from the code)
php tests/test_audit_log_arity.php               # every audit_log() call can run and is shaped right
php tests/test_backup_value_fidelity.php         # a backup restores every value, and the drill can tell
php tests/test_pre_release_fixes.php             # regression bundle
```

## Operator hardening checklist

- **Confirm your web server does not publish the private directories.** This is
  the first item on the list for a reason: on 2026-07-30 an unauthenticated
  `GET /backups/<archive>.zip` returned a complete 110 MB database dump from a
  live install, because the web root is the application root and nothing had
  told the server otherwise. Check yours in one minute — anything answering
  `200` is a problem:

  ```bash
  curl -s -o /dev/null -w 'sql   %{http_code}\n' https://your-site/sql/run_migrations.php
  curl -s -o /dev/null -w 'tools %{http_code}\n' https://your-site/tools/
  # Backups: ask for an ARCHIVE BY NAME. Filenames: Settings -> Backup / Maintenance.
  curl -s -o /dev/null -w 'archive %{http_code}\n' \
       https://your-site/backups/ticketscad-20260728-020000.zip
  ```

  **Do not test backups by requesting `/backups/`.** A `403` on the folder
  proves nothing about the files in it — @rjonesbsink measured a `403` on the
  directory and a `200` on the archive inside it, on the same install, on
  2026-08-02. Any server with directory listing off and no deny rule on files
  behaves that way. If you have no archive yet, take one before you claim this
  is checked.

  TicketsCAD runs the same probes against itself and reports them on
  Settings → System Health ("Web exposure"); with no archive to name it uses a random
  self-test file, and if it can do neither the row reads "Not determined"
  rather than green. **Which file protects you depends on your
  web server:** Apache reads the shipped `.htaccess` (only when `AllowOverride`
  is `All` or `FileInfo`); **nginx never reads `.htaccess` and needs
  [`docs/nginx/ticketscad-hardening.conf`](docs/nginx/ticketscad-hardening.conf)**;
  IIS needs the per-directory `web.config` files. Full instructions:
  [`docs/WEB-SERVER-HARDENING.md`](docs/WEB-SERVER-HARDENING.md). Background:
  [`docs/security/advisory-2026-07-30-exposed-directories.md`](docs/security/advisory-2026-07-30-exposed-directories.md).
- Keep database backups **outside** the webroot. v4.2.3 changed the default to
  `../backups` (a sibling of the install directory, like `keys/`); if you are
  upgrading, move any archives out of the old in-tree `backups/` folder and
  delete the originals. The Status page reports how many are still there.
- Run `php tools/install_fresh.php` after every upgrade — the migration
  ships schema fixes alongside code.
- Set a non-empty `feed_api_key` in Settings → API Keys before exposing
  `api/feed.php` to anything outside the LAN.
- Verify `uploads/.htaccess` exists and contains `php_flag engine off`.
  `install_fresh` writes it if missing.
- Make sure `keys/` lives **outside** the webroot (`/var/www/keys/` for
  the standard layout). PEM files: mode 600, owner = web server user.
- Enable HTTPS for every public install. Session cookies set
  `Secure` automatically when `$_SERVER['HTTPS']` is on.
- Rotate the encryption key (`keys/private.pem`) if compromise is
  suspected — see [`docs/SECURITY-POLICY.md`](docs/SECURITY-POLICY.md).
