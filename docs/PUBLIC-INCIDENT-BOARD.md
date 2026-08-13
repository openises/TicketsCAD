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
| `action.manage_public_board` | Super Admin only | The shared board's master switch, address precision ceiling, excluded groups, default publish delay, rate limiting, and the Incident Type Rules panel (which incident *types* never publish or show presence-only — this data is shared across every organization, since `in_types` is one table, not per-org). |
| `action.manage_public_board_org` | Super Admin + Org Admin | Enable/disable and set the URL slug for the caller's **own** organization's board only. The organization id is resolved server-side from the caller's actual RBAC grant — never from anything the browser sends, and never guessed from session state — so an Org Admin cannot reach another organization's row even by crafting a request. |

An Org Admin who opens `public-board-admin.php` sees only the
Organizations panel (their own row) — the master switch, address
precision, Incident Type Rules, and rate-limiting panels are all
Super-Admin-only and simply don't render for them.

## Before you enable anything: review the Incident Type Rules panel

The shared `in_types` table drives what publishes on **every** board,
shared or per-org. At migration time, and every time you attempt to flip
a switch from off to on, the system checks whether any incident type
whose name, group, or description looks medical/crisis-shaped (a fixed
keyword list — "medical", "welfare check", "mental health", "crisis",
"overdose", "abuse", "suicide", and similar) is still set to **Full**
visibility, and blocks the save with a warning until you explicitly
acknowledge it.

**This is a heuristic, not a guarantee.** It catches types whose type
name, group, or description contain one of the fixed keywords — it
cannot read your mind about a type named something the list doesn't
recognize. Review the Incident Type Rules panel yourself before enabling
either switch. For any type handling medical, mental-health, domestic
violence, welfare-check, or juvenile-involved calls, set its visibility
to **Presence-only** (shows a generic label, time, and unit count — no
address, no narrative, no specific type) or **Never publish** (excluded
entirely), rather than relying on the warning alone.

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
