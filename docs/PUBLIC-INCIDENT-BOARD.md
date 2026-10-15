# Public Incident Board (Phase 138)

**Audience:** administrators. If you're a dispatcher, see the "Public
Incident Board" section of `docs/NEWUI-USER-GUIDE.md` instead — there is
nothing you need to configure per-incident.

## What it is

A public, credential-free, internet-facing page (`public-board.php`) and
JSON API (`api/public-board.php`) showing a redacted view of currently
open incidents. It's meant to be linked from an agency's public website,
or loaded full-screen on a lobby display. **It is off by default, and
most agencies will never need to turn it on.**

Two independent switches exist:

- **The shared board** (`Config → Communications & Integrations → Public
  Board`, "Manage Public Board" permission) — one board covering every
  eligible incident regardless of organization, `public-board.php` with
  no `?org=` parameter.
- **Per-organization boards** — each organization can separately opt into
  its own URL (`public-board.php?org=<slug>`), filtered to only that
  org's incidents. This is independent of the shared board's switch; an
  org can publish its own URL whether or not the shared board is on.

## The two-permission model

Configuration is split into two RBAC permissions, deliberately, by blast
radius:

| Permission | Who holds it | Controls |
|---|---|---|
| `action.manage_public_board` | Super Admin only | The shared board's master switch, address precision ceiling, excluded groups, default publish delay, rate limiting, the board's map tiles (see "Map tiles" below), and the Incident Type Rules panel (which incident *types* never publish or show presence-only — this data is shared across every organization, since `in_types` is one table, not per-org). |
| `action.manage_public_board_org` | Super Admin + Org Admin | Enable/disable and set the URL slug for the caller's **own** organization's board only. The organization id is resolved server-side from the caller's actual RBAC grant — never from anything the browser sends, and never guessed from session state — so an Org Admin cannot reach another organization's row even by crafting a request. |

An Org Admin who opens `public-board-admin.php` sees only the
Organizations panel (their own row) — the master switch, address
precision, Incident Type Rules, and rate-limiting panels are all
Super-Admin-only and simply don't render for them.

## Nothing publishes until you say so

**Every incident type defaults to Never Publish** (2026-08-14 — this
changed from the original "publish everything unless a keyword downgrades
it" default). A type only appears on any board — shared or per-org — once
an admin explicitly unchecks "Never Publish" for it in the Incident Type
Rules panel. This applies to every existing type on upgrade as well as any
new type you create afterward.

**Visibility (Full vs. Presence-only) only matters for a type you've
already opted in.** Presence-only shows a generic label, time, and unit
count — no address, no narrative, no specific type. Full shows everything.
Neither has any effect while Never Publish is still checked.

## Before you enable anything: review the Incident Type Rules panel

The shared `in_types` table drives what publishes on **every** board,
shared or per-org. Every time you attempt to flip a switch from off to on,
the system checks whether any incident type you've opted IN to publishing
(Never Publish unchecked) whose name, group, or description looks
medical/crisis-shaped (a fixed keyword list — "medical", "welfare check",
"mental health", "crisis", "overdose", "abuse", "suicide", "cardiac",
"arrest", "casualty", "missing", "unconscious", "stroke", "respiratory",
"seizure", and similar) is still set to **Full** visibility, and blocks
the save with a warning until you explicitly acknowledge it.

**This is a heuristic, not a guarantee.** It catches types whose type
name, group, or description contain one of the fixed keywords — it
cannot read your mind about a type named something the list doesn't
recognize, and it only looks at types you've already opted in to
publishing at all. Review the Incident Type Rules panel yourself before
enabling either switch, and think carefully before opting in any type
handling medical, mental-health, domestic violence, welfare-check, or
juvenile-involved calls — Presence-only is a lighter-weight option than
Full for exactly this reason.

An Org Admin enabling their own org's board sees the same warning (with
read access to the same underlying list) even though they cannot open the
Incident Type Rules panel themselves — ask a Super Admin to review and
adjust it if the warning names types your organization actually uses.

## Redaction, independent of the two switches above

Every incident that clears eligibility (open, a real/non-orphaned
incident type, not in an excluded group, past its publish delay, and not
blocked by its own Security Label — see below) is redacted before it
ever reaches either board:

- **Security Labels always apply.** An incident whose resolved Security
  Label sets `routing_allow_broadcast = 0` (e.g. Restricted or
  Confidential) never appears on the board at all — no count, no hint
  that anything was withheld. A surviving incident's address and map-pin
  detail can be capped *coarser* by its label's own
  `eoc_show_address`/`eoc_show_map_marker` settings, but never finer than
  the board's own precision ceiling.
- **Address precision** is a single board-wide ceiling — Exact, Block
  (street name only), City (city/state only), or Hidden (no address or
  pin at all) — configured in the master-switch panel. A Security Label
  can only make an individual incident coarser than this ceiling, never
  finer.
- **`eoc_show_scope`** (a separate flag from `eoc_show_address`) is
  honored by the trusted, keyed feed (`api/feed.php`) but has no effect
  on the public board itself — the board never includes incident
  narrative/scope text in its output at all, by design.

## Operational prerequisites

- **Configure Trusted Proxies before enabling anything behind a reverse
  proxy or CDN.** The board's rate limiter keys on the visitor's IP
  address, resolved via `inc/client-ip.php`. If this install sits behind
  a proxy that isn't listed under `Config → Network → Trusted Proxies`,
  every visitor's request collapses onto the proxy's own IP, sharing one
  rate-limit bucket — the board can appear to lock out real visitors, or
  (if you raise the limit to compensate) offer far less protection than
  the configured number implies.
- **`Cache-Control: public, max-age=15`** is set on the JSON API
  response deliberately, so a CDN or reverse-proxy cache in front of this
  install may legitimately cache and serve a 15-second-old snapshot to
  multiple visitors — this is intended (it reduces load and is the whole
  point of the short public cache window), not a bug, but be aware a
  fronting cache can briefly serve a slightly stale board rather than a
  guaranteed-live one.
- **An untyped or orphaned incident is always excluded.** The eligibility
  query inner-joins `in_types` — an incident whose `in_types_id` doesn't
  resolve to a real row (which shouldn't normally happen, but can after
  data repair or migration issues) is dropped, not shown with its
  type-based rules bypassed.
- **The map is collapsed by default** behind a "Show map" toggle on the
  public page, for low-bandwidth visitors (rural residents, lobby
  displays on whatever connection is available) — every fact the map
  would show is already duplicated as text in the incident cards.

## Map tiles

The board's map shows **OpenStreetMap** by default. A Super Admin can
point it at a different public tile service in the **Map tiles for the
public board** panel of `public-board-admin.php`. The setting applies to
the shared board and every organization's board alike. Two settings:

| Setting | Meaning |
|---|---|
| `public_board_tile_url` | The tile address, e.g. `https://tiles.example.org/{z}/{x}/{y}.png`. **Empty means OpenStreetMap** — the default, and what every install shows until someone changes it. |
| `public_board_tile_attribution` | The map credit shown in the corner of the map. Plain text, up to 200 characters. Used only when a tile address is set; OpenStreetMap's own credit is shown otherwise. |

**What choosing an address publishes — read this before changing it:**

- **Anyone who can open the public board can see the address.** Their
  browser downloads the tiles from it directly; the request does not pass
  through your TicketsCAD server.
- **Never use an address containing an API key, token, password or
  signature.** Everything in the address is published to every visitor,
  who can copy it and spend your quota.
- **Do not use a private or internal address** such as your own offline
  tile server on your network. The address is published to every visitor
  (which tells them about your internal network), and it usually will not
  work for them anyway, because their browsers cannot reach your network.
- **The server behind the address must cope with traffic from the
  public**, and you must follow that service's terms of use. Every
  visitor's browser counts against it.

**Why the board does not use your main map settings or the tile proxy.**
The rest of TicketsCAD follows `Settings → Tile Providers`, and can fetch
tiles through TicketsCAD's own tile proxy (`api/tile-proxy.php`). Neither
is used for the public board: the main tile configuration may carry an API
key (Mapbox, CARTO), and the tile proxy only answers signed-in users
so it can never become an open relay that strangers use to download tiles
through your server. The board therefore has its own setting, which
publishes only the two values above and nothing else.

**What the server checks when you save:**

- Refused (with a message, nothing is saved): an address that does not
  start with `https://` or `http://` and a server name (this rules out
  `javascript:`, `data:` and addresses starting with `//`); a user name or
  password in the address (`user:password@`); an address without `{z}`,
  `{x}` and `{y}`; the `{key}` and `{q}` placeholders (and any placeholder
  other than `{z}`, `{x}`, `{y}`, `{s}` and `{r}`); spaces or control
  characters; more than 2048 characters.
- Saved, with a warning on screen: a query string that looks like it
  carries a key (`key=`, `apikey=`, `api_key=`, `token=`,
  `access_token=`, `sig=`, `signature=` — some providers issue keys that
  are meant to be public, so this is your call); a server name that looks
  private or internal (a private IP address, `localhost`, a single-word
  name, `.local`, `.lan`, `.internal` and similar); a custom address with
  no map credit.
- The map credit is stored as typed (control characters removed, at most
  200 characters). The board escapes it before the map shows it, so
  markup in it is displayed as text, never run.

If a value somehow ends up in the database that would not pass these
checks (edited directly in SQL, say), the board ignores it and shows
OpenStreetMap. The public board page only requests tiles once it has
loaded these settings, so a board pointed elsewhere never fetches
OpenStreetMap tiles first.

## Checking whether it's on

`Settings → System Health` reports the public board's status (disabled /
enabled, and for each enabled board, whether its live JSON response
actually matches what the database expects). This is the fastest way to
confirm whether either switch is currently on without digging through
the admin panels.

## See also

- `specs/phase-138-public-incident-board/{spec.md,plan.md,tasks.md}` —
  full design record, including documented deviations from the original
  plan.
- `docs/NEWUI-USER-GUIDE.md` — the dispatcher-facing summary.
- `inc/public-board.php` — the redaction/eligibility library; every rule
  described above is implemented and unit-tested there.
