# Running TicketsCAD without the internet

**Assessed:** 2026-07-31 · **Against:** commit `872143d` (v4.2.2 line), which
includes the server-side tile proxy that landed the same day ·
**Revised:** 2026-07-31, after **eleven of the twelve defects in §8 were
fixed** — each entry keeps the original finding alongside the after
measurement, so the reasoning survives and a regression is recognisable

The weather that takes your internet away is the weather that fills your
incident board. So "does this still work offline?" is not a nice-to-have
question about this software — it is the design case.

This document answers it honestly. Where a claim was **measured**, it says so
and gives the number. Where it was **read out of the code but not run**, it says
that too. Nothing here is rounded up in the software's favour.

**Contents**

1. [The short answer](#1-the-short-answer)
2. [What works, what degrades, what is gone](#2-what-works-what-degrades-what-is-gone)
3. [Scenario A — you set it up with no internet on purpose](#3-scenario-a--you-set-it-up-with-no-internet-on-purpose)
4. [Scenario B — you have internet, and it fails mid-incident](#4-scenario-b--you-have-internet-and-it-fails-mid-incident)
5. [Map tiles for offline use — what to actually run](#5-map-tiles-for-offline-use--what-to-actually-run)
6. [Offline geocoding — the honest position](#6-offline-geocoding--the-honest-position)
7. [Before you rely on this: a checklist you can actually run](#7-before-you-rely-on-this-a-checklist-you-can-actually-run)
8. [Defect list — what would hang, block or break](#8-defect-list--what-would-hang-block-or-break)
9. [How this was tested](#9-how-this-was-tested)

---

## 1. The short answer

**Yes — you can run the core dispatch loop with no internet at all.** Taking a
call, creating an incident, assigning a unit, changing its status, seeing units
and incidents on a map, running a report and printing all work with the network
cable unplugged, provided the server and the dispatcher's browser are on the
same local network.

This was measured, not assumed. Driving the real writers with all outbound HTTP
refused:

```
1. create an incident                      0.041s   outbound HTTP attempts: 0
2. assign a unit to it                     0.024s   outbound HTTP attempts: 0
3. change the assignment status            0.000s   outbound HTTP attempts: 0
4. change the unit status                  0.168s   outbound HTTP attempts: 0
5. read the incident back                  0.000s   outbound HTTP attempts: 0
6. list open incidents (dispatch board)    0.000s   outbound HTTP attempts: 0
7. a report query                          0.000s   outbound HTTP attempts: 0

Total outbound HTTP attempts across the whole loop: 0
```

The application itself is genuinely self-contained. Every stylesheet, script,
font and icon ships inside the install — there is not one `<script>` or
`<link>` pointing at a CDN anywhere in the product. **No page fails to render
without the internet.** That is unusual and it is worth knowing.

**Three things break, and one of them matters a lot:**

| | What breaks | How bad |
|---|---|---|
| 1 | **The map background is missing for anywhere you have not looked recently.** Your incidents, units, facilities and drawings all still appear, correctly positioned — but on grey where the streets should be. Areas viewed recently keep their background, because the server caches tiles on disk. | **Serious, but improvable today** — see §5, including how to pre-load your response area. |
| 2 | **The address "Lookup" button stops working** — unless you run a geocoder on your own network, which is now possible and is what §6 is about. Without one, it fails in about three seconds and says so; you place incidents by clicking the map. | **Fixable as of 2026-07-31** — see §6. |
| 3 | Weather overlays, radar, callsign lookups, push notifications, SMS/email/Slack alerts and radio bridges to internet services stop. | Expected. They are internet features. |

**The direction that used to be dangerous — having configured the system for
internet and then losing it — has been fixed.** With Web Push turned on, every
dispatch action used to block for about **21 seconds** during an outage. It is
now **0.04 seconds**, because notifications are queued and delivered by a
background job instead of inside the dispatcher's request. Both numbers are
measured, against a black-holed endpoint, through the real writers. See §4 for
what that means in practice and what it costs (notifications arrive up to one
sweep interval later).

---

## 2. What works, what degrades, what is gone

"Degrades" means the feature still does its job with something missing.
"Unavailable" means the feature does nothing until connectivity returns.

### Fully works offline

| Feature | Note |
|---|---|
| Log in, including two-factor | TOTP is computed locally; no internet involved |
| Create / edit / close incidents | |
| Assign, reassign, release units | |
| Unit and assignment status changes | |
| Dispatch call board, incident list, incident detail | |
| Roster, teams, units, vehicles, equipment | |
| Facilities, including bed and capacity tracking | |
| ICS forms (213, 214, 202, 205, 205a, 213rr, 206, 214a, 221) | Including Winlink XML export to a file |
| Internal messaging and local chat | Between users of this install |
| Reports and statistics | |
| Printing, and the print stylesheet | |
| Search | |
| Scheduling, shifts, events, self-signup | |
| Audit log and the audit viewer | |
| Backups and restore | Writes to local disk |
| RBAC, roles, permissions, user management | |
| Real-time updates between browsers (SSE) | Server-to-browser on your own LAN |
| The whole user interface: CSS, JavaScript, fonts, icons | All bundled locally |

### Degrades — usable, but visibly reduced

| Feature | What you lose | What still works |
|---|---|---|
| **Map** | The basemap (streets, terrain, imagery) is grey wherever the server has no cached tile | Areas viewed recently still draw, from the server's tile cache. Every marker, unit, incident, facility, drawing, geofence and overlay renders in the right place regardless. Pan, zoom, click-to-place all work. |
| **Address entry** | The "Lookup" button cannot turn an address into coordinates — *unless the geocoder is on your own network*, in which case nothing is lost at all (§6) | Type the address as free text; click the map to set the pin. Saved places and facilities still autocomplete from your own database. The button fails in ~3s with a plain message rather than hanging. |
| **Reverse lookup** | Clicking the map no longer auto-fills city/street — again, unless the geocoder is local | You type them. The pin is already placed either way, so the incident has its location. |
| **Weather alerts (NWS)** | No new alerts arrive | Alerts already received stay visible |
| **Mesh / radio bridges** | Only bridges reachable on your LAN work | A local Meshtastic or DMR bridge on your own network is unaffected |

### Unavailable offline

| Feature | Depends on |
|---|---|
| Weather overlays and radar | OpenWeatherMap, RainViewer, NOAA, Iowa Mesonet |
| Amateur/GMRS callsign lookup | OpenCallbook or callook.info (falls back to a locally imported FCC table if you have loaded one) |
| DMR ID name resolution | radioid.net (already-seen IDs are cached in your database) |
| Web push notifications to phones | Google/Mozilla/Apple push services |
| SMS, email, Slack notifications | The relevant provider |
| Outbound webhooks | The receiving system |
| APRS-IS and aprs.fi | Internet APRS infrastructure |
| Zello | zello.io |
| Text-to-speech via Deepgram or OpenAI | Those APIs. **A locally installed Piper voice works offline.** |
| The radio-AI feature | api.anthropic.com |
| FCC bulk data and ZIP code refresh | data.fcc.gov, geonames.org (download in advance) |

Address lookup is deliberately **not** in this table any more. It belongs in
"degrades" above, because as of 2026-07-31 it is the one item here you can move
entirely inside your own network — see §6.

---

## 3. Scenario A — you set it up with no internet on purpose

This is the good case, and it is a supported way to run.

### What to expect

The install behaves normally. Nothing hangs, because nothing on a page-render
path calls out to the internet. The features in the "unavailable" list above
simply sit inert: they are **off by default** and stay off unless an
administrator supplies an API key or a hostname.

That default-off posture is real and worth stating precisely. On a stock
install, before an administrator configures anything, the only things that
would ever reach the internet are:

- **Map tiles** — the dispatcher's browser fetching the basemap.
- **Address lookup** — since 2026-07-31 this leaves from the SERVER, not from
  each dispatcher's browser, and only when someone presses "Lookup". Point it
  at a geocoder on your own network (§6) and it never leaves your building at
  all; set it to `Off` and it never happens.

Everything else — weather, push, SMS, email, Slack, webhooks, APRS, Zello,
text-to-speech, the radio-AI — needs a key or a switch first.

### The core dispatch loop, offline

| Can you… | Offline? |
|---|---|
| Create an incident | **Yes** |
| Assign a unit | **Yes** |
| Change a status | **Yes** |
| See units and incidents on a map | **Yes — but on a blank background** unless you set up local tiles (§5) |
| Run a report | **Yes** |
| Print | **Yes** |

### Set-up steps for a deliberately offline install

1. **Install normally.** Nothing about the installer needs the internet, except
   `composer install`, which fetches PHP libraries. Run that once on a machine
   with connectivity and copy the whole install across, or copy the `vendor/`
   directory over separately.
2. **Set up a local map tile server** — §5. This is the single highest-value
   thing you can do.
3. **Import your lookup data in advance**, while you still have connectivity:
   ```
   php tools/update-lookup-data.php      # FCC amateur + GMRS licences, US ZIP codes
   ```
   That populates local tables so callsign and ZIP lookups keep working with no
   internet.
4. **Turn off things that will only ever fail.** In Settings, leave Web Push,
   SMS, email, Slack, webhooks and weather alerts disabled. They are off by
   default; the point is not to switch them on.
5. **Use a local text-to-speech voice** (Piper) rather than Deepgram or OpenAI,
   if you use spoken alerts.
6. **Set up address lookup, or switch it off deliberately** — §6. A self-hosted
   geocoder now works; if you are not running one, set the mode to `Off` so the
   Lookup button says so plainly instead of waiting on something unreachable.

### What an air-gapped install genuinely cannot do

Be clear-eyed about these; none of them are fixable by configuration:

- Turn a street address into map coordinates automatically (§6).
- Notify anyone who is not on your network. No push, no SMS, no email.
- Receive weather warnings.
- Reach any radio network that lives on the internet (Zello, BrandMeister).
  A **local** Meshtastic or DMR bridge on your own LAN is fine.

---

## 4. Scenario B — you have internet, and it fails mid-incident

This is the dangerous scenario, and it deserves the most attention. It is not
the same as scenario A. In scenario A the software knows it has no internet
and never tries. Here, the software has been told the internet exists, so it
keeps trying — and *waiting*.

### The mechanism, in plain terms

When a program asks for something over the internet and the connection has
failed, it does not get told "no". It gets **silence**. So it waits. How long
it waits is set by a *timeout*. If nobody set one, it waits for a very long
time — on a Linux server, around two minutes per attempt.

While it waits, it is holding one of the web server's limited slots for
handling requests. A typical server has about 150. Occupy them all and the
whole CAD stops answering anyone — during the emergency that caused the outage.

### What was measured

Against `203.0.113.1` — an address reserved by standard for documentation and
guaranteed never to be routed, so packets vanish silently, exactly like an
upstream failure:

| Situation | How long the server waits |
|---|---|
| A call with a proper timeout set (5s connect) | **5.00s** |
| A call with only a total timeout (10s) | **10.01s** |
| A call with **no timeout at all** | **21.03s** (Windows) — on Linux roughly **127s** |
| Web Push delivery, 1 subscription | **21.09s** |
| Web Push delivery, 5 subscriptions | **21.10s** |
| Web Push delivery, 20 subscriptions | **21.22s** |
| **A dispatch action — before** (create an incident, push on) | **21.34s** |
| **A dispatch action — after** (same conditions) | **0.04s** |

Two useful and slightly counter-intuitive findings came out of this, and they
mean the situation is **better** than a code review alone would suggest:

- A timeout on the *read* also bounds the *connect* in this codebase's style of
  call. The 5s and 10s limits you see in the code are real limits.
- Web Push sends all its notifications **in parallel**. Twenty phones cost the
  same as one. The cost does not multiply with the size of your roster.

### The headline finding — and what was done about it

**It used to be: with Web Push enabled, every dispatch action blocked for about
21 seconds during an internet outage.** Confirmed by measurement, not
inference — creating an incident took **21.34s** and a unit status change
**21.33s**, driven through the real writers with the endpoints black-holed.

Creating an incident, assigning a unit, changing a status — each records an
audit entry, and the audit entry used to try to notify every phone, webhook,
SMS gateway and Slack workspace *before the dispatcher's screen came back*.
Investigating it turned up a second such path that the code review had missed:
`inc/sse.php` fired webhooks again from `sse_publish()`, so fixing only the
audit path still left a status change costing 3.07s.

**Both now hand the notification to a queue and return.** Delivery is done by
the background sweep that already existed. Measured under the same conditions,
a dispatch action is **0.04s**. Full detail, including the safeguards for an
install with no scheduler and the latency this costs, is in defect **D3**
below.

Web Push is **off by default**. But it is exactly the feature a volunteer
agency turns on, because it is how you get a callout onto a member's phone. So
the agencies most likely to have been affected were the ones using the software
most seriously — which is why this was fixed first.

The same path fans out to **any** outbound channel an administrator has
configured — email, SMS, Slack, webhooks. Those costs used to be **additive on
the same request**; they are now all on the queue together.

### Does the map go blank, or hold its last state?

**It holds its last state for anywhere you have looked recently, and goes grey
elsewhere.** This is better than it used to be, and the reason matters.

Tile requests go through your own server (`tile_mode` defaults to `proxy`),
which keeps a disk cache of the tiles it has fetched. When the upstream
provider becomes unreachable, the proxy implements *stale-if-error*: if it
already holds that tile, it serves the old copy rather than failing
(`api/tile-proxy.php:233`). So:

- **Areas you have viewed recently keep their street background.** For an
  agency working its own patch, that is most of what a dispatcher looks at.
- Areas never viewed — or evicted from the cache — come back as a blank tile.
- **Incident markers, unit positions, facilities, drawings and geofences all
  keep rendering** either way. They come from your own database. You lose the
  streets, not the picture.

Two caveats that stop this being a complete answer:

- **The cache defaults to 512 MB and a 30-day life.** That is generous for a
  county and nowhere near enough for a state. See §5 for raising it and for
  deliberately pre-loading your area.
- **An uncached tile costs 2 seconds before it gives up, and only the first
  few pay it.** After three consecutive transport failures the proxy stops
  contacting that provider for 60 seconds and fails instantly instead, and the
  blank tile is cached in the browser for 60 seconds so a pan back over the
  same ground never reaches the server. A full uncached pan during an outage
  costs about **6 worker-seconds once**, then nothing — it used to cost 200,
  every pan. See defect D2.

There is still no error message. The map just goes partly grey. A dispatcher
who has not been told this will reasonably think the CAD has broken.

### Everything else that fails

These degrade quietly rather than dangerously — a console error, a blank layer,
a button that does nothing:

- Address lookup: fails within about three seconds and says the geocoder did
  not answer — and, after three consecutive failures, stops trying for a minute
  so it costs nothing at all. It no longer says "address not found", which was
  the old behaviour and was not true.
- Weather and radar layers: stay empty.
- Callsign and DMR-ID lookups: return nothing, falling back to local tables.
- Anything already on screen keeps working.

---

## 5. Map tiles for offline use — what to actually run

This is the question you were asked, so here is a real answer.

### First, the free option: warm the cache you already have

Before installing anything, know that TicketsCAD now runs its own tile cache.
`tile_mode` defaults to `proxy`, so every basemap tile a dispatcher loads is
stored on your server and re-served from there — including during an outage.

That gives you a genuinely useful, zero-software offline measure:

> **Pan and zoom around your response area while you still have internet.**
> Every tile you look at is cached. Those areas will still have a street
> background when the connection dies.

Raise the limits first, or the cache will evict your work
(**Settings → Map Settings**):

| Setting | Default | Suggested for offline resilience |
|---|---|---|
| `tile_cache_max_mb` | 512 MB | 4096 or more, if you have the disk |
| `tile_cache_days` | 30 days | up to 9999 (~27 years — as far as this project's testing bothers going, since nothing here claims a literal "forever") |
| `tile_cache_min_free_mb` | 1024 MB | leave as is — it stops the cache filling your disk |

These are two **independent** limits, and raising one does not touch the
other. `tile_cache_days` only bounds how long a cached tile is trusted before
it is re-fetched — it has no effect on eviction. `tile_cache_max_mb` (with
`tile_cache_min_free_mb` as the floor) is a separate, size-based limit: once
the cache hits that ceiling, the least-recently-used tiles are evicted to make
room for new ones, **regardless of how high `tile_cache_days` is set.** Set
`tile_cache_days` to 9999 and leave `tile_cache_max_mb` at 512 and the cache
will still evict old tiles the moment your response area doesn't fit in
512 MB — see the size table further down this section for what your area
actually needs. For a genuinely permanent cache, raise both together.

**Where this runs out:** it only covers what somebody actually looked at. It
is a sensible precaution, not a plan. For a genuinely air-gapped install, or
for guaranteed coverage of your whole district, use a real local tile server
below.

### The recommendation

> **Serve pre-rendered raster tiles from a single MBTiles file on your own
> machine, using `mbtileserver` — one small program with nothing else to
> install — and point TicketsCAD at it with the "custom" tile provider.**

**Why this one, for a volunteer agency with limited IT support:**

1. **It works with TicketsCAD today, with no code changes.** The `tile_provider`
   and `tile_server_url` settings already exist and are already honoured. You
   change a setting; you do not patch anything.
2. **It is one file.** An `.mbtiles` file is a single file you can put on a USB
   stick and carry to an air-gapped machine. Compare that with millions of tiny
   image files, which are painful to copy and hard on a filesystem.
3. **It is one program with no dependencies.** `mbtileserver` is a single
   self-contained executable. No database to install, no Node.js, no Python, no
   import job that runs for two days.
4. **Raster tiles are what TicketsCAD already draws.** The map library it ships
   (Leaflet) displays raster tiles natively. Vector tiles would need new
   software in the browser that TicketsCAD does not currently include.

### The realistic alternatives, and why not

| Option | Verdict |
|---|---|
| **Full self-hosted OpenStreetMap stack** (PostgreSQL + PostGIS + Mapnik + renderd) | The "proper" answer, and the wrong one here. A US state import is hours to days, needs a substantial server, and needs ongoing care. Only sensible if you already have a GIS person. |
| **PMTiles / Protomaps** (single-file vector archive) | Genuinely elegant — one file, no server process at all, and **dramatically smaller** (a whole state in a few hundred MB rather than tens of GB). But it needs a vector renderer in the browser that TicketsCAD does not ship. **This is the right long-term direction for the project** and is recorded as a recommendation below. |
| **Unpacked z/x/y folders on your existing web server** | Zero extra software, which is appealing. But millions of small files are slow to copy, slow to back up, and awkward on Windows. Use MBTiles instead. |
| **Commercial offline tile packs** (Esri, Google, Mapbox) | Their licences generally **forbid** caching or offline redistribution. TicketsCAD's own tile proxy already refuses to cache these providers for that reason. Do not plan around them. |

### Roughly how much disk

Raster tiles get much bigger with each zoom level — each level is four times
the previous one. These are approximations for typical street-map tiles;
**measure your own area rather than trusting these numbers**, because detail
varies enormously between a city and a forest.

| Area | Zoom levels | Approx. tiles | Approx. size |
|---|---|---|---|
| One county (~50 × 50 km) | 0–16 (streets legible) | ~18,000 | **250–400 MB** |
| One county | 0–18 (house-level) | ~285,000 | **3–5 GB** |
| One US state (Minnesota-sized) | 0–14 (town level) | ~185,000 | **2–3 GB** |
| One US state | 0–16 (streets legible) | ~2.9 million | **40–60 GB** |

**The practical guidance:** cover *your response area* at zoom 16–18, and the
surrounding region at zoom 10–12 for context. Do not try to store a whole state
at street detail as raster — that is the point at which the vector option
becomes the right answer instead.

### Where to get the tiles, legally

This matters, and it is easy to get wrong.

- **USGS National Map** (`basemap.nationalmap.gov`) — topographic and imagery
  tiles produced by the US Government. **Public domain.** No attribution
  legally required (crediting them is still good manners), and no usage policy
  forbidding you from caching them. **For a US agency this is the cleanest
  source there is**, and TicketsCAD already supports these providers.
- **OpenStreetMap data** — free to use under the ODbL licence, but you **must**
  keep the "© OpenStreetMap contributors" credit visible on the map. TicketsCAD
  displays it already; do not remove it.
- **Do not bulk-download from `tile.openstreetmap.org`.** The OpenStreetMap
  Foundation's tile usage policy explicitly prohibits it. Those servers are
  donated. Render your own tiles from OSM data, or use USGS.

### Getting tiles onto an air-gapped machine

1. On a machine **with** internet, produce or download your `.mbtiles` file for
   your area.
2. Check its size and note a checksum: `sha256sum yourarea.mbtiles`
3. Copy it to USB, carry it across, verify the checksum matches.
4. Put the file somewhere permanent on the server, e.g.
   `/var/lib/tiles/yourarea.mbtiles`.
5. Run the tile server (as a service, so it survives a reboot):
   ```
   mbtileserver --dir /var/lib/tiles --port 8000
   ```
6. In TicketsCAD: **Settings → Map Settings**
   - Tile provider: **Custom**
   - Tile server URL:
     `http://YOUR-SERVER:8000/services/yourarea/tiles/{z}/{x}/{y}.png`
7. Reload a map page and confirm the basemap draws. Then **unplug the internet
   and confirm it still draws** — that is the only test that counts.

---

## 6. Offline geocoding — the honest position

### Does the documentation you remember exist?

**No.** It does not exist, and it never has.

This was checked thoroughly rather than assumed: every file under `docs/` and
`specs/`, and the entire git history including deleted and renamed files. There
has never been a document in this repository about offline or self-hosted
geocoding.

What **does** exist, and is probably the thing being remembered, is
`specs/phase-offline-01-2026-06/` —
a substantial three-document design for offline operation. But note two things
about it:

- It is **design only. Status: "DESIGN — no implementation yet."** None of it
  was built.
- It is about a **different problem**: a responder's *phone* losing signal in a
  basement and syncing later. It is not about running a server without
  internet, and it does not discuss geocoding.

### What used to be here, and what changed on 2026-07-31

Until 2026-07-31 this section said, accurately, that you could not point
TicketsCAD at an offline geocoder at all. The **Geocoding Provider** dropdown in
Settings was read by nothing: address lookup was done entirely by the
dispatcher's browser, calling `nominatim.openstreetmap.org` from eleven
hard-coded places in the JavaScript, whatever the dropdown said. That was
defect D1, and it is now fixed.

**You can now run address lookup entirely inside your own network.** The rest of
this section is how.

### First, decide whether you need one at all

Be honest about this before spending a weekend on it. For most volunteer
agencies the answer is no, and the four habits below are worth more than a
geocoder — they are also what you fall back on if the geocoder is down:

1. **Click the map to place the incident.** Fully offline, already how the
   interface works, and for responders who know their area it is often faster
   than typing.
2. **Pre-load your places.** Facilities, stations, shelters and common call
   locations live in your own database and autocomplete offline. Time spent
   loading these before an event pays back directly.
3. **Import the ZIP code table** while you have connectivity, so at least
   ZIP-level positioning works.
4. **Type the address as text.** The incident still records it; only the
   automatic coordinate lookup is lost.

Run a self-hosted geocoder if you dispatch to addresses you do not know by
heart, over an area too large to eyeball on a map, and you expect to keep doing
it during an internet outage.

### How the setting works now

Settings → API Keys → **Geocoding**:

| Setting | What it does | Default |
|---|---|---|
| **How lookups are performed** | `Through this server` / `Direct from each browser` / `Off` | Through this server |
| **Geocoding Provider** | Nominatim (public), Nominatim (self-hosted), Photon (self-hosted), LocationIQ, Geoapify, Google, HERE | Nominatim (public) |
| **Your geocoding server address** | e.g. `http://10.0.0.20:8080` — self-hosted providers only | blank |
| **Geocoding API Key** | commercial providers only; **stays on the server** | blank |
| **Keep results for (hours)** | server-side result cache | 24 |
| **Minimum gap between lookups (ms)** | blank = the provider's own published limit | blank |
| **Identify this server as** | blank = automatic, identifying User-Agent | blank |

Two things about the defaults are worth knowing:

- **Through this server** is the default, including on upgrade. It is the only
  mode that can cache and rate-limit centrally, keep an API key off a
  dispatcher's browser, or reach a geocoder on your own network. Note that
  browser-direct lookup *cannot* satisfy the OpenStreetMap Nominatim usage
  policy the shipped provider is governed by — that policy requires caching, a
  contactable User-Agent, and at most one request per second, none of which
  eleven independent browsers can do.
- **Direct from each browser** is kept because some installs genuinely prefer
  it. But it is only offered for providers with no API key and a compatible
  response format; choose it with any other provider and Settings will tell you
  it is running in server mode instead, and why. That is deliberate: a setting
  that silently does something other than what it says is the defect this whole
  change removes.

**Off** disables address lookup completely. Use it on an air-gapped install so
nothing ever tries to reach a geocoder; dispatchers place incidents by clicking
the map, and the Lookup button says so calmly rather than spinning.

Press **Test address lookup** after any change. It performs a real lookup and
reports the provider, the time taken and the error if there is one. Use it —
several providers' adapters are written from the published response format and
have not been exercised against a live paid account here, and Settings labels
them so.

### Standing up your own geocoder

Two realistic options. **Nominatim is the one to try first**: TicketsCAD speaks
its API natively, so you point the setting at it and change nothing else.

Say the quiet part first: **none of this runs on a small VPS.** These are
memory- and disk-hungry, and the machine wants to be a real box on your own
network — a spare desktop in the closet is ideal, and is also the machine that
keeps working when the internet does not.

#### Option A — Nominatim, one state (recommended starting point)

The whole planet is not the goal. One state is.

```bash
# On a spare box with Docker installed, ~100 GB free SSD and 16 GB RAM.
# Pick your state's extract from https://download.geofabrik.de/
mkdir -p /opt/nominatim-data && cd /opt/nominatim-data

docker run -d --name nominatim \
  -e PBF_URL=https://download.geofabrik.de/north-america/us/minnesota-latest.osm.pbf \
  -e IMPORT_WIKIPEDIA=false \
  -e NOMINATIM_PASSWORD=choose-something \
  -p 8080:8080 \
  -v /opt/nominatim-data:/var/lib/postgresql/16/main \
  --shm-size=1g \
  mediagis/nominatim:4.4
```

The container downloads the extract and imports it **unattended**. Watch it with
`docker logs -f nominatim`. Then:

```bash
curl 'http://localhost:8080/search?q=350+S+5th+St,+Minneapolis&format=json&limit=1'
```

When that returns coordinates, put `http://<that-box>:8080` into **Your
geocoding server address**, set the provider to **Nominatim — self-hosted**, and
press **Test address lookup**.

What to expect, honestly:

| | One US state | Whole US |
|---|---|---|
| Source extract | ~250 MB – 1 GB | ~12 GB |
| Disk when imported | **40–100 GB** | **~1 TB** |
| RAM during import | **16 GB** comfortable, 8 GB painful | 64 GB+ |
| Import time (SSD) | **3–8 hours** | **days** |
| RAM to just serve it | 2–4 GB | 16 GB+ |

**Do not attempt the whole US on a spare PC.** Import a state, or the two or
three states you actually dispatch across. A hard disk instead of an SSD turns
hours into overnight.

#### Option B — Photon, if you have the disk but not the patience

Photon (Java + an Elasticsearch-style index) publishes a **prebuilt** index, so
there is no import to run — you download and start it.

```bash
# ~180 GB free during extraction, ~80 GB after; 4-8 GB of JVM heap.
wget https://download1.graphhopper.com/public/photon-db-latest.tar.bz2
tar -xjf photon-db-latest.tar.bz2          # this is the slow part
java -jar photon-*.jar -listen-port 2322
```

Set the provider to **Photon — self-hosted** and the address to
`http://<that-box>:2322`. Country-sized extracts are also published if the
planet index is too much.

TicketsCAD translates Photon's response format on the server, so it works
exactly like the others from the dispatcher's point of view — with one honest
caveat that Settings also shows you: Photon does not supply neighbourhood or
suburb, so the cross-street field stays blank. That is the provider, not a
fault.

#### Once it is running

- **Test it with the internet unplugged.** That is the only test that counts.
  Section 7 has the procedure.
- Leave **Keep results for (hours)** alone. Your own server needs no rate limit,
  but the cache still makes repeat lookups instant.
- Your geocoder is now a thing that can break. It appears on Settings → System Health
  as *Address lookup*, and if it stops answering, TicketsCAD says so rather than
  making dispatchers wait.

---

## 7. Before you rely on this: a checklist you can actually run

Do this *before* the storm, on the machine you will actually use. Each step
tells you what a pass looks like.

### The one test that matters

**Unplug the internet and dispatch a drill incident, end to end.**

Physically disconnect the internet — not Wi-Fi off on the laptop; disconnect
the *site's* connection, or unplug the WAN cable from the router — so the
server and the dispatcher stay on the same LAN but nothing reaches outside.
Then:

| # | Step | Pass looks like |
|---|---|---|
| 1 | Load the dispatch board | Page loads fully. Menus, icons and styling all correct — no unstyled page |
| 2 | Create an incident | Saves within a second or two |
| 3 | Assign a unit | Saves immediately |
| 4 | Change the unit's status | Saves immediately |
| 5 | Open the map | Markers appear in the right places. **Note whether the streets draw.** If grey, §5 is your work item |
| 6 | Close the incident | Works |
| 7 | Run a report | Produces numbers |
| 8 | Print it | Prints |

**If steps 2–4 take more than about two seconds each, stop.** You have found
the scenario-B problem: something is waiting on the internet inside your
dispatch path. Check whether Web Push, webhooks, SMS, email or Slack are
enabled in Settings, and turn off the ones you do not need.

### Time it, so you have a number

With the internet disconnected, use a stopwatch on "assign a unit". Under 2
seconds is healthy — on a current version it should be a fraction of a second,
because notifications no longer go out inside your request (§4, D3). Around
20–30 seconds means you are on a version before 2026-07-31; upgrade. Anything
in between is worth reporting.

### Then check the notifications actually went

The other half of the same change: because delivery moved to a background job,
you should confirm that job exists.

| Check | How | Pass |
|---|---|---|
| Something drains the queue | Settings → System Health → Scheduled jobs | "Notification + pending message sweep" shows a recent successful run — **not** "Has never run" |
| Nothing is silently piling up | Same page | No "N notification(s) queued and undelivered" |
| A callout really arrives | With the internet **connected**, create a drill incident and watch the phone | It arrives within one sweep interval |

### Supporting checks

| Check | How | Pass |
|---|---|---|
| Nothing loads from the internet | Open the browser's developer tools → Network, load a page, look for external hostnames | Only your own server, plus tile/geocode requests |
| Local tiles work | §5 step 7 | Basemap draws with internet disconnected |
| Lookup data is loaded | In your database: `SELECT COUNT(*) FROM fcc_amateur; SELECT COUNT(*) FROM zipcodes;` | Non-zero row counts. If they are empty, run `php tools/update-lookup-data.php` while you still have connectivity |
| Backups run without internet | `php tools/backup_run.php` | Archive written |
| Scheduled jobs are actually running | Settings → System Health → scheduled jobs | No job shows "never run". **A log file still at zero bytes means it never ran** — see the note on missing cron daemons in `CLAUDE.md` |
| The install is healthy | `php tools/check-health.php` | No critical findings |

### Re-check after every upgrade

Timeout behaviour and outbound calls change as features are added. The
automated gate `tests/test_outbound_timeouts.php` catches *new* unbounded calls,
but only the drill above tells you what a dispatcher experiences.

---

## 8. Defect list — what would hang, block or break

Worst first. **Eleven of the twelve were fixed on 2026-07-31 and are struck
through below, with the before-and-after measurements kept** so the reasoning
survives and a regression is recognisable. Only **D9** — which has no fix
available in PHP — remains open.

Nothing here is struck through on the strength of "the code looks right". Every
fix carries a measurement, a gate, or both, and where a claim rests on reading
rather than running, it says so.

| | Defect | State |
|---|---|---|
| D1 | Geocoding Provider setting read by nothing; offline geocoding impossible | fixed |
| D2 | ~200 server-seconds per pan into uncached ground, repeatedly | fixed |
| D3 | Every dispatch action blocked 21–30s with push on and the link down | fixed |
| D4 | Map goes grey with no explanation | fixed |
| D5 | Console paused 1.5s per unreachable bridge, per page load | fixed |
| D6 | Weather tiles cost 10s each, uncached on failure | fixed |
| D7 | Radar catalogue fetched even with radar switched off | fixed |
| D8 | Bulk downloads could hang an operator's terminal for ever | fixed |
| D9 | DNS lookups cannot be bounded from PHP | **open — no code fix exists** |
| D10 | Service worker cached nothing; the FAQ said it did | fixed (documentation) |
| D11 | Terrain basemap blocked by our own CSP | fixed |
| D12 | City-weather icons requested over plain HTTP | fixed |

A note on how to read the fixed entries: the *original finding* is kept in each
one. That is deliberate. Several of these were not one mistake but a shape that
recurs — a setting with a writer and no reader, a per-call timeout that ignores
the aggregate, a gate that can only recognise one spelling of correctness — and
deleting the description would leave only the remedy, which is the half that
teaches nobody anything.

### ~~D1 — You cannot point TicketsCAD at an offline geocoder at all~~
**FIXED 2026-07-31 · TESTED before and after**

*Original finding.* `settings.php:2442` offered a Geocoding Provider dropdown
and an API key field. **No code read either setting** — and not because a
consumer had been removed: `git log --all -S geocoding_provider -- assets/ api/
inc/` was empty, so one was never written, on any branch, in any commit. All
eleven geocoding calls hard-coded `nominatim.openstreetmap.org` in the browser
(`app.js`, `new-incident.js` ×2, `config.js` ×2, `unit-edit.js` ×2,
`facility-edit.js` ×2, `incident-detail.js` ×2). There was no
`api/geocode.php`.

*Three consequences, each independently bad.*

1. An administrator who pointed that dropdown at their own server got nothing
   at all. The setting lied.
2. Offline geocoding was not merely unconfigured, it was **impossible** — and
   for two reasons, either of which alone would have been enough. There was no
   server-side path; and the browser cannot reach a geocoder on your LAN
   anyway, because an HTTPS-served page fetching `http://10.x.x.x` is blocked
   as mixed content, and `inc/security-headers.php` allowlisted
   `nominatim.openstreetmap.org` by name in `connect-src`.
3. Every dispatcher's browser told OpenStreetMap which addresses were being
   typed — which is to say **where the incidents were** — from N separate IP
   addresses, with no cache, no rate limiting and no identifying User-Agent.
   The OSM Foundation's Nominatim usage policy requires results be cached, caps
   use at one request per second, requires a User-Agent that identifies the
   application, and explicitly *recommends* putting a proxy in front. Eleven
   uncoordinated browser fetches can satisfy none of those, so the shipped
   architecture was a standing violation of the one provider it hardcoded.

*What changed.* `inc/geocode.php` + `api/geocode.php` + `assets/js/geocode.js`.
The setting is real, and §6 above is now a set-up guide rather than an
apology. Seven providers including two self-hosted ones; the provider,
mode, cache lifetime, rate limit and User-Agent are all configurable, and the
mode defaults to **server**.

*SSRF.* The client never sends a URL — only an address string or a coordinate
pair. The upstream URL is built server-side from a hardcoded template or the
admin's validated `geocoding_url`. Verified refused: `file://`, `gopher://`,
`ftp://`, `javascript:`, `data:`, scheme-relative `//host`, embedded
credentials, and CRLF request splitting — by the validator, and again inside
the HTTP client so a future caller cannot turn it into a general fetcher.

*Measured, against 203.0.113.1 (RFC 5737, black-holed):*

| | Before | After |
|---|---|---|
| One lookup, provider unreachable | unbounded (browser DNS/TCP budget) | **3.01s** |
| The `new-incident` Lookup button, offline | walked every spelling variant, ~a minute, then said "address not found" — which was not true | **fails on the first, says the geocoder did not answer** |
| Five lookups during an outage | 5 × unbounded | **9.05s** (breaker opens after 3; the 4th and 5th cost 0.00s) |
| Repeat of a cached address | full round trip | **7ms** |

*Two readers that cannot rot.* `tools/geocode_audit.php` (empty baseline) fails
on any geocoder hostname in `assets/js` outside `geocode.js`; its test plants a
probe file and runs the real tool, so the gate cannot decay into a no-op, and
plants a comment-only probe so it cannot cry wolf. And in the shipped server
mode the CSP emits **no geocoder host at all**, so a twelfth hardcoded call
site fails visibly in every browser rather than leaking silently in all of
them. That change also fixed a latent bug: direct mode against any provider
other than Nominatim would have been CSP-blocked, because only Nominatim was
ever named.

Gates: `tests/test_geocode.php`, `tests/test_geocode_audit.php`.

### ~~D2 — Panning into uncached territory costs ~200 server-seconds per pan, repeatedly~~
**FIXED 2026-07-31 · TESTED before and after**

*Original finding.* One tile against a black-holed upstream blocked **5.02s**
(`inc/tile-proxy.php`, correctly bounded per call). But a 1920×1080 map
viewport is about **40 tiles**, so a single pan cost roughly **200
worker-seconds**. Worse, a failed tile was served with `Cache-Control:
no-store`, so the browser forgot the failure instantly and re-requested the
same dead tiles on the next pan — the full cost, repeated, for the whole
outage. A few dispatchers working uncached ground could exhaust the web
server's ~150 request slots.

*What changed.* Three mechanisms, deliberately different in scope:

| | Value | Why |
|---|---|---|
| Upstream connect timeout | **5s → 2s** | It is paid ~40× per viewport, so every second here is forty worker-seconds per pan |
| Upstream total timeout | **12s → 6s** | A tile is tens of kilobytes from a CDN edge |
| Circuit breaker | **3** consecutive transport failures opens it, **60s** cool-off | Per provider, on the server: once upstream is known down, no tile request touches the network until the cool-off expires |
| Negative cache | failed tile now `Cache-Control: private, max-age=60` | Per tile, in the browser: a pan back over the same ground never reaches the server |

A **404 is deliberately not a breaker failure** — that is a healthy provider
telling the truth about its coverage, and counting it would blank the map for
everyone the first time a dispatcher zoomed past the edge of one. Only a
transport failure, a 5xx, a 429, or a non-image body (a captive portal
answering 200 with HTML) counts.

*Measured, same method, same black hole:*

| | Before | After |
|---|---|---|
| One tile | 5.02s | **2.01s** |
| First uncached pan (40 tiles) | 200.6 worker-seconds | **6.04 worker-seconds** (3 upstream attempts, 37 fast-failed) |
| Every subsequent pan | 200.6 worker-seconds | **0.00 worker-seconds** (0 upstream attempts) |
| Ten pans during a sustained outage | ~804s | **~6.1s** |

An open breaker still serves a **cached** tile if it holds one — stale beats
blank. `api/tile-proxy.php?action=status` reports which providers are open and
for how long. Gate: `tests/test_tile_proxy_breaker.php`.

*Verified separately, in a browser, with the shipped Leaflet build:* pointing a
tile layer at the proxy's exact failure reply produced **0 `tileerror` events**,
and the incident marker, unit, facility, geofence and route all remained on the
map — before and after panning. The basemap degrades; the dispatch picture does
not. That is why the failure is a 200 with a real 1×1 transparent PNG rather
than an error status.

### ~~D3 — Every dispatch action blocks 21–30s when push is on and the internet is down~~
**FIXED 2026-07-31 · composition CONFIRMED end-to-end, then fixed**

*Original finding.* Measured against black-holed push endpoints using the real
library: `flush()` blocked **21.09s / 21.10s / 21.22s** for 1, 5 and 20
subscriptions. The composition — that this ran synchronously inside the
dispatcher's request — was read, not run.

*It was confirmed by running it.* Driving the real writers
(`incident_create_internal`, `responder_set_status_internal`) with Web Push
enabled and every endpoint pointed at 203.0.113.1:

```
create an incident ........ 21.34s
change a unit's status .... 21.33s
```

**And the audit had missed half of it.** Fixing only `inc/audit.php` still left
a unit status change costing **3.07s**, because `inc/sse.php:134` fired
webhooks a *second* time from `sse_publish()` — a separate synchronous fan-out
on the same hot path that no reading of the audit trail would have surfaced.

*What changed.* Both call sites now hand the event to
`notify_fanout_dispatch()` (`inc/notify-fanout.php`), which writes one row to
the `pending_routed_messages` queue and returns. Delivery is done by
`tools/pending_messages_tick.php` — the sweep that already existed, already had
a systemd timer, and already reported itself on the Status page.

Nothing about the routing is changed: same event types, same payloads, same
routes, same recipient predicates. Only the moment moved. SSE-originated events
stay **webhook-only** exactly as before — they have never fanned out to push and
must not start, or every chat line would buzz a phone.

Two safeguards, because assuming a scheduler exists is precisely how a job on
this project sat dead for seven weeks:

- **If the sweep has a recent heartbeat**, the dispatch path does no outbound
  network at all. Not a short call — none.
- **If it does not** (no cron daemon, no timer — the state both live servers
  were actually in), the request still queues the row first, then makes one
  best-effort attempt bounded by a 3-second budget and guarded by a circuit
  breaker: two consecutive failures pause outbound delivery for 60 seconds.
- **On a default install** — no webhook subscribers, push off — the fan-out is
  a no-op and writes nothing at all.

*Measured, same method:*

| | Before | After |
|---|---|---|
| Create an incident, sweep running | 21.34s | **0.04s** |
| Change a unit status, sweep running | 21.33s | **0.04s** |
| First action with **no scheduler at all** | 21.34s | **3.40s** (bounded probe) |
| Subsequent actions, breaker open | 21.34s | **0.02–0.04s** |

**Undelivered notifications are not lost.** They sit in the queue as rows an
operator can read, with the last error recorded, and the sweep delivers them
when the link returns (verified: 10 queued during the outage, all 10 sent by
the next sweep). Work older than `sched_stale_cutoff_min` (default 60 minutes)
is recorded **expired** rather than delivered — a callout that arrives an hour
late is worse than none, and the row says why.

**A backlog is visible.** Settings → System Health → Scheduled jobs turns critical and
says so in words: *"10 notification(s) queued and undelivered. Outbound
delivery is paused for 57s after 2 consecutive failures."* The tick also prints
`notify_pending=N oldest=Ns` into the journal. Gate:
`tests/test_notify_fanout.php`.

**The cost of this fix, stated plainly:** a notification now goes out on the
next sweep rather than immediately, so with the shipped 1-minute timer a
callout can be up to **60 seconds** later than it used to be. If you use Web
Push for callouts, run that timer every 15 seconds — see
[`MAINTENANCE-RUNBOOK.md`](MAINTENANCE-RUNBOOK.md), "Scheduled background
jobs". That is a real trade and it was made deliberately: a bounded delay on
every notification is better than a 21-second stall on every dispatch action
plus the risk of the whole CAD stopping.

### ~~D4 — The map goes partly grey with no explanation~~
**FIXED 2026-07-31**

*Original finding.* When the proxy holds no copy of a tile it serves a blank
image (`tile_fail_soft` in `api/tile-proxy.php`). That is the right call — a
blank tile keeps the map usable where a broken-image icon or an error status
would wedge the layer — but the dispatcher got **no message at all**: no toast,
no banner, only console errors and the `X-Tile-Proxy: error` header.

The reassuring half was measured separately and holds: a page built on the
Leaflet build this product ships, with a tile layer pointed at the proxy's exact
failure reply, produced **0 `tileerror` events**; the incident marker, unit,
facility, geofence and route all stayed on the map, before and after panning. So
the basemap genuinely degrades on its own without taking the dispatch picture
with it. What was missing was telling the dispatcher that is what happened —
because a dispatcher who has not been told will reasonably conclude the CAD has
failed, and start restarting things mid-incident.

*What changed.* `assets/js/map-status.js` shows **"Map background unavailable —
incident data is still live"** across the top of the map, and clears it as soon
as tiles load again. The second half of that sentence is the half that matters.

Detecting the failure needs **two** signals, because the two tile modes fail
differently and either one alone would have covered only half the installs:

- **Direct mode** — the browser fetches the provider itself, so a dead link
  produces real `tileerror` events.
- **Proxy mode** — the proxy answers *200 with a genuine transparent PNG*, so
  `tileerror` never fires, deliberately (that is exactly why the markers
  survive). The failure is carried in the `X-Tile-Proxy` header instead, read
  back from the already-cached tile — same-origin, throttled, and served from
  the browser's HTTP cache because a failed tile carries a 60s `max-age`.

`api/tile-proxy.php?action=status` would have been the obvious source, but it
requires `action.manage_config` — a dispatcher cannot read it, and a dispatcher
is exactly who needs telling.

**Configurable:** `map_offline_banner`, on by default. A dispatcher misreading a
grey map as a dead CAD is the more expensive mistake, but an unattended wall
display may prefer the picture uncluttered.

### ~~D5 — The Communications Console pauses 1.5s per unreachable bridge~~
**FIXED 2026-07-31 · TESTED before and after**

*Original finding.* `inc/channel_registry.php` probes each DMR bridge's health
endpoint with a 1.5s timeout — measured at exactly **1.50s** against a
black-holed host. The defect was never the timeout, which is a fair bound for a
bridge on the LAN; it was that the verdict was cached **only within a single
request**, so every Console page load re-probed every bridge from scratch. With
several bridges configured off-site, the Console took several seconds longer to
open, every time, during the outage that made the Console matter.

*What changed.* The verdict is now cached across requests, and deliberately
**asymmetrically**:

| Verdict | Reused for | Why |
|---|---|---|
| down / degraded | **30s** (`bridge_health_down_cache_sec`) | This is the expensive one — the full timeout, every time — and it is the one that does not change minute to minute. |
| connected | **5s** (`bridge_health_up_cache_sec`) | A live bridge answers in milliseconds, so there is little to save; and on a radio console the gap between a bridge dying and an operator seeing it should be as small as we can make it. |

*Measured, same method:* first probe **1.50s**, every subsequent request
**0.001s**.

### ~~D6 — Weather tiles cost 10s each when OpenWeatherMap is unreachable~~
**FIXED 2026-07-31**

*Original finding.* `api/weather-proxy.php` used a 10s timeout, measured at
**10.01s**, paid per tile — so a weather overlay on a dead connection had the
same multiply-by-40 problem as D2, roughly 400 worker-seconds for one pan.
Failures were not negatively cached, so the browser re-requested every dead tile
on the next pan, for the whole duration of the outage.

*What changed.* The same three mechanisms as D2, for the same reasons:

| | Before | After |
|---|---|---|
| Connect timeout | (none set separately) | **3s** |
| Total timeout | 10s (tiles) / 15s (city JSON) | **6s** |
| Circuit breaker | none | **3** consecutive transport failures, **60s** cool-off |
| Failed tile | no cache headers — re-requested on every pan | `private, max-age=60` |

A 404 is deliberately **not** a breaker failure: that is a healthy provider
saying this layer has no tile at this location, and counting it would blank the
overlay for everyone the first time a dispatcher zoomed past the edge of
coverage. Nor is a 401 — a bad API key is a configuration error the breaker must
not hide. A cached tile is still served while the breaker is open; during a
weather event a half-hour-old picture is worth having.

The failure reply is a 1×1 transparent PNG with a 200 status rather than a 502,
for the same reason the tile proxy's is: Leaflet turns an error status into a
`tileerror` and, on some builds, a broken-image tile that wedges the layer.

Only reachable when an OpenWeatherMap key is configured (off by default) and an
operator has switched the overlay on.

### ~~D7 — Radar catalogue is fetched on map pages even when radar is off~~
**FIXED 2026-07-31**

*Original finding.* `situation.php` and `assets/js/map-prefs.js` fetched
`api.rainviewer.com/public/weather-maps.json` **unconditionally** on map page
load, then every five minutes, regardless of whether the operator had enabled
the radar layer. A Situation wall display with radar switched off contacted
RainViewer around the clock. `SECURITY.md` described this as happening only when
radar was switched on, which was not accurate.

It is a browser-side call so it never blocked the server, but it is unnecessary
outbound traffic from a dispatch console and a privacy point worth correcting.

*What changed.* The catalogue is fetched on the radar layer's `add` event, and
the five-minute poll is **stopped** on `remove`. So it runs exactly when an
operator is looking at radar, which is what the documentation always said.

### ~~D8 — Bulk data downloads can hang the operator's terminal forever~~
**FIXED 2026-07-31**

*Original finding.* `tools/refresh-lookups.php` built `curl -sSfL` command lines
with **no `--max-time` and no `--connect-timeout`**, so against a stalled mirror
it waited indefinitely. CLI-only and operator-run, so it could not wedge a
dispatcher — but "I left it running overnight and it neither finished nor
failed" is not an acceptable answer either.

*What changed.* Both downloads now carry
`--connect-timeout 30 --max-time 3600 --retry 2 --retry-delay 5`. The total
budget is generous on purpose: the FCC's complete amateur licence archive is a
few hundred megabytes, and `--retry` covers a mirror that drops mid-file, which
is the common failure rather than a hard refusal.

Its entry in `tests/test_outbound_timeouts.php`'s allowlist is removed — the
gate's staleness assertion *requires* that, which is what stops the list rotting
into a set of permanent exemptions.

**Three bugs in the gates themselves surfaced in the same pass**, all of one
family — a checker that can only recognise one spelling of correctness, and so
cannot see a fix:

- it counted `function_exists('curl_init')` as an unbounded call site, though a
  capability probe opens no connection;
- it examined only the **first string literal** of a command line, so a command
  bounded by concatenation read as unbounded — the same blind spot that once
  hid all 89 writer `INSERT`s from `tools/schema_audit.php`;
- and hoisting the weather proxy's timeout into a named constant, which is what
  made it reviewable, read to a third assertion as having *removed* the bound.

All three are fixed, and the weather assertion now also requires the breaker and
the negative cache — because bounding one call was never the point.

### D9 — DNS lookups cannot be bounded
**Severity: LOW · INFERRED — not tested**

`inc/webhooks.php:551` calls `gethostbynamel()` before every webhook delivery,
as an anti-SSRF check. PHP offers no timeout control over this function.

When a name simply does not exist, this returns in **0.01s** (measured). The
untested case is a *resolver that is itself unreachable* — plausible in an
upstream outage — where the call would block for the resolver's own retry
budget regardless of any timeout the code sets. This was not tested because
doing so would have meant breaking DNS on the test machine.

**Fix: none available in PHP directly, and none has been applied.** This is the
one item on this list that remains open, because the remedy is not code:
configure a reliable local resolver with a short timeout in `/etc/resolv.conf`
(`options timeout:1 attempts:1`), or run a caching resolver on the dispatch
server itself. A resolver on the same machine is the version of this that
survives an outage.

### ~~D10 — The service worker caches nothing, and the FAQ says it does~~
**FIXED 2026-07-31 — by correcting the documentation, deliberately**

*Original finding.* `docs/FAQ.md` stated the PWA "caches static assets so the UI
shell loads offline". `sw.js` has **no `fetch` handler and no cache calls at
all** — it handles push notifications only.

*What changed, and why this way.* The FAQ was corrected and `sw.js` stays
push-only. That choice was put to the same four-lens panel as D1 and came back
9 / 8 / 9 / 9 in favour of correcting the documentation over implementing a
cache. The reasoning is recorded at the top of `sw.js` so the omission is not
read as an oversight by the next person:

- On a LAN install the server **is** the box in the closet. When the internet
  fails the server is still up and still serving these assets in milliseconds.
  A shell cache optimises a problem that configuration does not have.
- A CAD shell without data is worse than an honest error page. A dispatcher who
  reaches a loaded UI showing an empty incident list may reasonably conclude
  there are no active incidents. A failed page load cannot be misread that way.
- Stale cached JavaScript against an upgraded API is this project's documented
  worst bug class, and a service-worker cache makes it invisible *and*
  unfixable by phone: Ctrl-Shift-R does not clear one, and the About page reads
  the version server-side, so the one diagnostic would report the new version
  while the browser ran the old.
- Asset URLs are not consistently versioned yet — several navbar scripts carry
  no `?v=` at all — so a cache-first shell would pin exactly those indefinitely.

**The dissent is worth recording**, because it is real: nobody on the panel had
an answer for the tablet in a truck on flaky cellular. That case is genuinely
unserved, and the honest statement is that offline field work needs cached
*data*, not a cached shell. If it is picked up later, fix asset versioning
first.

*Two real bugs were hiding behind the documentation question and are also
fixed.* `assets/icons/` did not exist, so **every push notification on every
install rendered with no icon** (the files are now generated from the shipped
logo). And both the icon paths and the `navigator.serviceWorker.register()`
call were **absolute** (`/assets/…`, `/sw.js`), so on an install served from a
subdirectory — `http://host/newui/`, which is the documented XAMPP layout — the
registration failed outright and Web Push could never be enabled at all.

Gate: `tests/test_outage_degradation.php` asserts `sw.js` has no `fetch`
handler and makes no Cache Storage call, that the FAQ no longer makes the
claim, that the icons exist, and that neither path is absolute.

### ~~D11 — Terrain basemap is blocked by the app's own security policy~~
**FIXED 2026-07-31**

`inc/security-headers.php` omitted `*.tile.opentopomap.org` from the allowed
image sources while the Terrain basemap was offered in four places, so
selecting Terrain produced a blank map **even with a working internet
connection**. Fixed while wiring the tile proxy; asserted in
`tests/test_outage_degradation.php` so it stays fixed.

### ~~D12 — City-weather icons are requested over plain HTTP~~
**FIXED 2026-07-31**

*Original finding.* `assets/vendor/leaflet/plugins/leaflet-openweathermap.js`
hard-codes `http://openweathermap.org/img/w/{icon}.png`. On any HTTPS install
this is blocked as mixed content, so the icons were already broken,
independently of any outage.

It was worse than one URL: the same file hardcodes `http://` for the station
and aircraft marker icons too, and `openweathermap.org` (without the `tile.`
prefix) was **not in the CSP's `img-src` at all** — only `tile.openweathermap.org`
was. So switching them to `https` alone would still have produced nothing.

*What changed.* The three icon URLs are overridden to `https` **at the call
site** in `assets/js/app.js`, alongside the `baseUrl` and `_urlTemplate`
overrides that were already there, and `openweathermap.org` is added to
`img-src`. The vendored plugin is not patched: it is third-party, hashed
individually in the SBOM, and a local modification would sit in the path of
every future update.

### Recommendations beyond the defects

- **Adopt PMTiles for offline basemaps.** It is the technically correct answer
  and would let a volunteer agency carry an entire state in a few hundred
  megabytes rather than tens of gigabytes. It needs a vector renderer in the
  browser.
- **Add an "offline mode" setting** that switches off every outbound feature at
  once, so an air-gapped install cannot be misconfigured into waiting on
  something unreachable. Still worth doing: address lookup, tiles and weather
  each have their own switch now, but there is no single one.
- **Show connectivity state in the interface.** Partly done: the map now says
  "Map background unavailable — incident data is still live" (D4), and address
  lookup reports its own failures in words. There is still no single indicator
  that says the console has lost the internet.

---

## 9. How this was tested

So you can judge how much weight to put on each claim, and repeat any of it.

**Method.** Failure was simulated with `203.0.113.1`, from the block reserved
by RFC 5737 for documentation and guaranteed never to be routed on the public
internet. Packets to it vanish — no reply, no rejection. That is the shape of
an *upstream outage*, and it is deliberately different from pointing at a
closed port on your own network, which replies instantly and would have made
everything look fine.

**What was measured, by running the real code:**

- The core dispatch loop, through the real writers
  (`inc/incident-write.php`, `inc/assignment-write.php`,
  `inc/responder-write.php`) with the `http://` and `https://` stream wrappers
  replaced by a wrapper that counts and refuses every attempt. Result: **zero
  outbound attempts, 0.27s total**. Test rows were deleted afterwards.
- `tile_http_get()` from `inc/tile-proxy.php` — the real function, not a
  re-implementation: **5.00s** per tile.
- `_chreg_dmr_bridge_state()` from `inc/channel_registry.php`, body verbatim:
  **1.50s**.
- The real `minishlink/web-push` `flush()` with genuine VAPID keys and
  black-holed endpoints: **21.09s / 21.10s / 21.22s** for 1 / 5 / 20
  subscriptions.
- Timeout behaviour of each calling style used in this codebase, to establish
  what the numbers in the code actually mean.

**Added 2026-07-31, while fixing D2 and D3** — all run, none inferred:

- **The composition of D3, which the first pass had only read.** Web Push
  enabled the way an agency enables it, every endpoint black-holed, driven
  through `incident_create_internal()` and `responder_set_status_internal()`:
  **21.34s** and **21.33s**. Confirming it is what exposed the *second*
  synchronous fan-out in `inc/sse.php` that reading the audit path alone would
  never have shown — fixing only `inc/audit.php` still left a status change at
  **3.07s**. After the fix, under identical conditions: **0.04s**.
- **The tile aggregate**, modelling the endpoint's real decision sequence over
  a 40-tile viewport: **200.6 → 6.04 worker-seconds** for the first pan and
  **0.00** for every pan after, against the same black hole.
- **Browser rendering behaviour when tiles fail** — previously listed as
  inferred. A page built on the Leaflet build this product ships, with a tile
  layer pointed at the proxy's exact failure reply: **0 `tileerror` events**, 4
  tiles loaded, and the incident marker, unit, facility, geofence and route all
  still on the map after panning. So "the basemap degrades, the dispatch
  picture does not" is now measured rather than reasoned.

**What is still read but not run**: browser rendering behaviour for D7, D11 and
D12; Linux socket timings (measurements were taken on Windows, where the OS
gives up after ~21s; Linux defaults are longer, around 127s, so **the untimed
cases are worse on a real server than the numbers here**); and DNS behaviour
with an unreachable resolver (D9).

**Automated regression gates.**

- `tests/test_outbound_timeouts.php` scans every outbound call site in the
  codebase and fails if a new one appears without a timeout. It was verified to
  actually catch violations by planting deliberately unbounded calls of all four
  kinds and confirming it failed. Its own documentation lists what it does
  **not** cover — browser `fetch()`, DNS, aggregate cost, and the Python
  services.
- `tests/test_notify_fanout.php` (D3) drives the real dispatch writers against
  a genuinely black-holed endpoint and fails if an action exceeds its
  wall-clock budget, if a notification is dropped rather than queued, or if the
  backlog stops being visible on the Status page.
- `tests/test_tile_proxy_breaker.php` (D2) covers the breaker state machine,
  the 404-is-not-an-outage distinction, the measured bound, and the properties
  that keep a failing basemap from breaking the map.

---

*Corrections welcome. This document is only useful if it is accurate, and its
value comes from being trusted — if you find something here that is wrong,
that is a bug worth reporting.*
