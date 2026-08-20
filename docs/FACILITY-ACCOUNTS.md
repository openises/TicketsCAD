# Facility Accounts (GH#90 — Phase 145)

**Audience:** administrators linking a user account to a facility; the
facility staff who use the resulting portal.

**Status:** shipped. Off by default in the sense that matters — a fresh
install has `user.facility_id = 0` on every account, and nothing changes
until an administrator explicitly links an account to a facility via the
User Accounts form.

## The problem this solves (GH#90)

The legacy v3 system had a `LEVEL_FACILITY` user level that redirected a
facility login to `facility_board.php` — a board scoped to incidents at, or
inbound to, that one facility. It looked like access control but wasn't:
the level enforced *nothing*. A facility user got exactly the same screens
as any other user and could create incidents, edit records, and reach every
admin page. The "facility" behavior began and ended with the post-login
redirect.

NewUI v4 never rebuilt this. A hospital, shelter, or receiving facility that
wants to see its own inbound traffic, or self-report its own status
(diversion, bed capacity), has had no way to log in and see anything
scoped to itself — only a dispatcher acting on its behalf through the
existing Facilities admin screens.

This feature is that missing piece, built with the confinement v3 never
had: a facility account can reach exactly one page, see exactly its own
facility's incidents, and touch exactly its own facility's status/capacity
— nothing else, enforced at three independent layers rather than a single
post-login redirect.

## Setting up a facility account

1. **Settings → User Accounts → Add User** (or edit an existing account).
2. Set **Role and permission group** to **Facility**.
3. A **Linked Facility** field appears — pick the facility this account is
   scoped to. This is required; the save is rejected without it.
4. Set a username and password as normal. The account otherwise behaves
   like any other user account (self-service password change, optional
   2FA, force-password-change-on-first-login, etc.) — those standing
   account-security features are unaffected by facility confinement.
5. Save. The Roster/User Accounts table shows a small facility badge under
   the Role column for any account linked this way.

Every link and unlink is written to the audit log
(`auth` / `facility_link_set` or `facility_link_cleared`), same as any
other RBAC role grant.

**One account per facility is the expected shape** — this feature does not
build a per-staff-member login system for a facility; it links one shared
login to one facility, the same way v3's mechanism did.

## What the facility sees

Logging in with a facility-linked account redirects straight to
**the Facility Portal** (`facility-portal.php`) — there is no dashboard, no
navbar, nothing else in the application surface to navigate to. This is
deliberate: nothing else in the app applies to this account type, so there
is nothing to link to.

The portal shows:

- **Incidents at, or inbound to, this facility** — status **Open** or
  **Scheduled** only (closed/historical incidents are not shown, matching
  v3's own filter). A ticket qualifies if the facility is the incident's
  origin, its ticket-level receiving facility, **or** the per-unit
  destination on any individual responding unit (the Phase 116
  mass-casualty case, where different ambulances on the same call can be
  routed to different hospitals).
- **Severity**, incident type, a brief description, and address.
- **Responding units** — identity (name/handle) and current status
  timeline, including when a unit went en route to this facility and when
  it arrived (`assigns.u2fenr` / `u2farr`). Individual crew rosters and
  per-assignment comments are **not** shown — the same "never leak a
  roster" boundary this codebase already applies to cross-org ticket
  sharing (`inc/org-sharing.php`'s view-tier redaction).
- **Patient count only** — if an incident has patients attached, the
  portal shows how many, not names, DOB, gender, or clinical narrative.
  This is a deliberate, conservative starting point: nothing in this
  codebase enforces field-level redaction on patient data today (the
  `field.view_patient` permission exists but is not wired to any live
  code path), so a facility account showing anything richer than a count
  would be a new PHI-exposure decision, not an engineering default. If a
  future need calls for more (e.g. age/sex/chief-complaint for real
  pre-arrival clinical handoff), that is Eric's call to make explicitly,
  not something this phase assumed.

## Self-service status and capacity reporting

The portal's right-hand panel lets the facility:

- **Report its own status** (open / closed / full / diversion, from the
  install's configured `fac_status` list) with an optional note.
- **Report bed/capacity counts** per category (ICU beds, ER beds, shelter
  spots, etc. — the same category list the dispatcher-facing Facility
  Capacity screen uses).

Both write through a dedicated endpoint (`api/facility-portal.php`), never
the dispatcher-facing `api/facility-capacity.php` — see
["Why a new endpoint"](#why-a-new-endpoint-not-a-reused-one) below. Every
write is scoped server-side to the session's own linked facility; there is
no code path that accepts a client-supplied facility id for a write.

## How confinement actually works

This is the part v3 never had, so it is documented in full rather than
asserted. See `inc/facility-scope.php`'s own docblock for the
implementation-level detail; this is the operator-facing summary.

A facility account is identified by exactly one fact:
`$_SESSION['facility_id'] > 0`, set once at login from `user.facility_id`
(never trusted from client input at any other point). That one fact drives
three independent, redundant enforcement layers:

1. **Permission confinement.** `inc/rbac.php`'s grant cache forces
   `is_super = false` and intersects every permission the account might
   otherwise hold down to exactly `screen.facility_portal` and
   `action.facility_self_report` — regardless of what role grants actually
   exist in the database for that account. This was proven adversarially:
   a test account holding a genuine Super Admin grant *and* a facility
   link still has `is_admin()` return `false` and `rbac_can()` deny
   everything outside the two-code allowlist the moment facility
   confinement is active.
2. **API confinement.** Every `api/*.php` endpoint funnels through the
   shared `api/auth.php` include. A facility-confined session is refused
   there, before any endpoint-specific logic runs, unless the requested
   script is `facility-portal.php`, `profile.php`, or `tfa.php`. This
   blocks endpoints that have no permission gate of their own — including
   a real, pre-existing gap in the dispatcher-facing
   `api/facility-capacity.php?summary=1`, which has no RBAC check at all.
3. **Page confinement.** `force_pw_change_redirect()` — already called by
   62 of the application's 70 top-level pages, right after each page's own
   login check — also redirects a facility-confined session to
   `facility-portal.php` unless the current page is on the same small
   allowlist.

A regression test (`tests/test_facility_scope_confinement.php`) walks
every real page and API filename currently on disk (not a hand-picked
sample) and asserts the allowlist contains exactly the expected three
names in each case — a new endpoint added later is refused by default,
and widening the allowlist requires an explicit, deliberate test change.

## Why a new endpoint, not a reused one

The dispatcher-facing incident list/detail pages and
`api/facility-capacity.php` were deliberately **not** reused with a filter
bolted on. Two reasons:

- `api/facility-capacity.php`'s `GET ?summary=1` endpoint has no RBAC gate
  at all today — any authenticated session can already read every
  facility's capacity. Adding facility confinement as a *filter* on top of
  that endpoint would still leave the endpoint's other actions unscoped
  for a facility session unless every one of them was separately audited
  and patched.
- A bolted-on filter is one more thing that can be bypassed by a future
  edit to the shared endpoint that forgets the facility case exists. A
  small, dedicated, self-contained endpoint (`api/facility-portal.php`)
  scopes every query to the session's own `facility_id` by construction —
  there is no other facility's data it is capable of returning, because it
  never accepts one as input.

## Schema notes (GH#90 / GH#91)

`user.level`, `user.responder_id`, and `user.facility_id` were three
v3-era columns nothing in v4 read. This feature resolves the fate of two of
them and leaves the third to GH#91's broader cleanup:

- **`user.facility_id`** — **repurposed**, not dropped. It is now the real
  link between a login and a facility, as described above. The column's
  shape (`int(7) NOT NULL DEFAULT 0`) was left exactly as-is; only its
  comment was updated (`sql/run_phase145_facility_accounts.php`).
- **`user.responder_id`** — **dropped**. Confirmed via a full-tree grep
  that nothing reads it; the Field Unit role links a login to a unit from
  the *other* direction (`responder.user_id` /
  `responder.personal_for_member_id`, resolved by
  `mobile_resolve_responder_id()` in `api/mobile-data.php`), so this
  column was never the mechanism for that either.
- **`user.level`** — **left alone**. Already fully dead as an
  authorization signal since Phase 128 (`inc/rbac.php`'s deleted
  `_rbac_legacy_check()`). This feature takes no position on dropping the
  column itself — that decision belongs to GH#91's broader ~15-column
  `user` table cleanup, and is unblocked either way by this feature.

## RBAC role

A 7th built-in role, **Facility**, holds exactly two permissions:
`screen.facility_portal` and `action.facility_self_report`. Unlike the
other six built-in roles (seeded once, at the very start of an install's
life, before any custom role can exist), this role is resolved **by name**
everywhere in the codebase, never by a hardcoded id. `roles.id` is a plain
`AUTO_INCREMENT`, and a real, months-old install can already have custom
roles occupying low ids by the time this feature's migration first runs —
confirmed live during development, where a pre-existing custom role
already held the id this feature's first draft tried to hardcode. This is
the same lesson `run_phase11d_mobile_first.php` already documented for
Field Unit's id.

The role itself is **not** the security boundary — see "How confinement
actually works" above. It exists so the account has something coherent to
hold in the Roles & Permissions UI and so `rbac_can()` resolves sensibly
for the two portal actions.
