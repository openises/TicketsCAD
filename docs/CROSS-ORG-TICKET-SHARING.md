# Cross-Org Ticket Sharing (GH#70 — Phases 1, 2, and 3, complete)

**Audience:** administrators for routing rules and standing relationships;
**dispatchers** for the manual "Share…" button and for activating a standing
relationship — neither of those two requires admin setup, just the right
permission (granted to Dispatcher and Org Admin by default).

**Status:** all three planned phases are shipped. Off by default on every
install in the sense that matters: a fresh install, and every existing
install until an administrator configures a routing rule or a standing
relationship, or a dispatcher manually shares a specific ticket, has zero
`org_type_routing` rows, zero non-revoked `incident_shares` rows, and zero
`org_relationships` rows — and behaves exactly as it did before this feature
existed. For the design history (why three phases, what each one added, the
options considered and rejected), see
`specs/phase-141-cross-org-ticket-sharing/gh70-summary.md` — this document
is the how-to-configure-and-use guide.

## The problem this solves (GH#70)

TicketsCAD already supports an organization hierarchy
(`organizations.parent_org_id`) and org-scoped visibility walks it — but only
**downward**. A user scoped to org X sees X and everything beneath it in the
tree; it never walks upward to an ancestor, and it never crosses to a
sibling or an unrelated org at all.

That breaks a common multi-agency pattern: one parent dispatch org (e.g.
"County Dispatch") and several child orgs (Sheriff, Fire, EMS). A dispatcher
logged in under the parent takes a call — the incident type is, say, "Law" —
and the ticket genuinely belongs to the Sheriff's office to handle. But the
ticket's `org_id` is the parent, and the Sheriff's session never includes the
parent in its own visible-org set. The ticket doesn't just fail to show up on
the Sheriff's board — it's refused outright if a Sheriff dispatcher somehow
gets a direct link to it. No error, no indication; it simply isn't there.

This feature closes that gap with **three complementary mechanisms**, each
suited to a different shape of need:

1. **Standing type-based routing** — an admin-configured rule that
   auto-shares every future matching ticket, forever, with no per-ticket
   action.
2. **Manual per-incident sharing** — a one-off, human, in-the-moment
   decision to share one specific ticket, made by whoever is actively
   working the call.
3. **Standing relationships with optional time-boxed activation** — a
   named, two-party-consented, durable mutual-aid partnership between two or
   more orgs, which can either grant visibility permanently once approved or
   stay dormant until a dispatcher explicitly "turns it on" for a bounded
   window.

All three feed the same downstream visibility, write-capability, redaction,
audit, and live-push machinery — a ticket looks and behaves the same to a
recipient org no matter which of the three granted the access.

## 1. Standing type-based routing

1. A Super Admin creates a **routing rule** under **Settings → Cross-Org
   Sharing → Cross-Org Ticket Routing**: an owning organization, a target
   organization, a match (an incident-type group, or one specific incident
   type), and an access tier (`view` or `assist`).
2. Whenever a new ticket is created and matches an active rule for its
   owning org, the system automatically creates a **share** — a grant that
   makes that one ticket visible to the target org going forward. This
   happens once, at ticket-creation time, entirely server-side; nothing the
   dispatcher does triggers or blocks it.
3. From that point, the ticket appears on the target org's incident list,
   search results, callboard, and dashboard exactly like one of their own —
   carrying a **"Shared from [Org]"** badge — and is openable via incident
   detail, with the actions available determined by the rule's tier.

Routing rules only affect **future** ticket creation. Creating a rule does
not retroactively share any ticket that already exists; deactivating a rule
does not retroactively un-share any ticket it already shared (see
"Deactivating a rule," below).

### Configuring a routing rule

1. Go to **Settings → Cross-Org Sharing → Cross-Org Ticket Routing**
   (requires the "Manage Cross-Org Ticket Routing Rules" permission —
   Super Admin by default; see "Permissions," below).
2. Click **New Rule**.
3. Choose the **owning organization** — tickets created under this org are
   the ones this rule can auto-share. This choice is permanent once saved.
4. Choose the **target organization** — the org that gains visibility. Also
   permanent once saved.
5. Choose what the rule **matches on**:
   - **Incident type group** — every incident type in that group (e.g.
     every "Law" type). Broader; the simplest option, and what the GH#70
     scenario itself used.
   - **Specific incident type** — one exact incident type only. Narrower; a
     specific-type rule always takes precedence over a group rule for the
     same target org, so you can carve out an exception without touching
     the broader group rule.
6. Choose the **access tier** (see "The two access tiers," below).
7. Save. The rule takes effect for the next ticket created that matches it —
   nothing already open is affected.

**A rule's organization pair and match target are permanent once created.**
Only the access tier can be edited afterward. To change who a rule routes
from/to, or what it matches, deactivate the old rule and create a new one.
This keeps every share's "which rule produced this" attribution unambiguous
for the life of the rule.

### Deactivating a rule

Deactivating a rule stops it from producing any **new** shares. It does
**not** retroactively revoke shares it already created — a responding org
actively working an incident (units assigned, notes written) never silently
loses visibility into it because an admin turned a rule off later. If you
need to revoke visibility into one specific, already-shared ticket, use
"Manually sharing one ticket"'s **Revoke** action, below.

There is no delete action — a rule is created, edited (tier only), and
deactivated, never removed outright. This matches every other
admin-authored rule table in this codebase (ICS form types, the public
board's per-org rows): archive, never hard-delete.

## 2. Manually sharing one ticket

Unlike a routing rule, this is a one-off, per-ticket action available to
whoever is actively working the call — no admin setup required, just the
right permission (see "Permissions," below).

1. Open the incident's detail page. If you hold `action.share_incident` or
   `action.revoke_incident_share` **and** your organization owns the ticket
   (never a ticket you're only viewing via someone else's share or
   relationship — see "The one thing sharing can never do," below), a
   **Share…** button appears in the header action bar.
2. Click it. The modal shows every organization the ticket is currently
   shared with (tier, source — "via rule #N" or the dispatcher's name —
   reason, and when), plus a **Revoke** button per row if you hold
   `action.revoke_incident_share`.
3. To add a new share: pick a target organization, an access tier (`view` or
   `assist` — same two tiers a routing rule uses), and type a short reason
   ("mutual aid requested by IC," "second alarm — need Engine 4"). Submit.
   The target org gains visibility immediately — both via a live SSE push
   (their board updates within seconds, no refresh needed) and via the
   normal poll/reload path as a fallback.
4. To revoke: click **Revoke** on any row — a manual share or a
   rule-originated one, it doesn't matter which; revoking only cares that
   your org owns the ticket. The target org loses visibility immediately:
   the very next event published for that ticket simply stops naming them,
   and if they have the ticket open, their next write attempt is refused.

**Sharing the same ticket with the same org twice.** If a share to a given
org is currently active, a second attempt is rejected with a clear error
("This ticket is already shared with \[Org\] at \[tier\] tier") — it never
silently overwrites the existing grant's tier or who created it. If a prior
share to that org was revoked, sharing again **revives** that same
underlying row (new tier, new reason, freshly attributed to you) rather than
creating a duplicate — even if the original grant came from a routing rule,
the revived one is attributed to you as a deliberate human decision.

### The one thing sharing can never do

**A user whose own visibility into a ticket comes only from an existing
share, or only from a standing relationship (see part 3, below), can never
create — or revoke — a further share on that ticket**, no matter what
permissions they hold, including `assist` tier's full same-org-equivalent
write access. Sharing and revoking are both gated on
`org_ticket_is_owned_by_caller()`, which was built specifically so that no
share, and no relationship-derived grant at any tier, ever satisfies it —
only genuine ownership does. This is what stops an org that was granted
access from re-sharing (or un-sharing) a ticket it doesn't actually own,
chaining access onward to orgs the real owner never agreed to. Enforced
identically whether the action comes through the UI or the API, and proven
by dedicated adversarial regression tests
(`tests/test_org_sharing_anti_chaining.php` for the share-derived case,
`tests/test_org_relationships_anti_chaining.php` for the relationship-derived
case), not just documented as intent.

The identical guarantee extends to relationship authoring: a
relationship-derived viewer of another org's ticket can never propose or
approve a standing relationship "on that org's behalf" either — see part 3's
own two-party consent model, below.

### Live push

Every ordinary ticket event (a note added, a status change, a unit
assigned) reaches every org with an active share **or** an active
relationship-derived grant on that ticket the moment it's published, not
just on the next poll. The mechanism authorizes at *publish* time, not
*connection-open* time: every publish re-checks which orgs currently hold
an active share or a live relationship grant on that specific ticket,
fresh, so a share revoked (or a relationship activation that expires) mid-
connection stops reaching that org starting with the very next event — no
propagation delay, no waiting for the connection to time out and reconnect.

## 3. Standing relationships with optional time-boxed activation

A **standing relationship** is a named, durable group of two or more
organizations who have each explicitly consented to share visibility into
each other's tickets — a genuine mutual-aid partnership, an escalation
path, or a backup-dispatch arrangement, set up once rather than re-created
per incident. Unlike a routing rule (which one Super Admin configures
unilaterally) or a manual share (which requires no consent from the
recipient), a standing relationship requires **every named organization's
own authorized approver to independently agree** before it takes effect.

### Two-party consent — how it actually works

1. An administrator **proposes** a relationship under **Settings →
   Cross-Org Sharing → Standing Relationships**: a name, a relationship
   type (mutual aid, escalation, backup dispatch, or other — descriptive
   only), the initial member organizations, an access tier, a redaction
   profile, and whether the relationship requires a separate activation
   step (see below).
2. If a Super Admin proposes it, every named org's membership is
   auto-approved immediately, and the relationship goes `active` right
   away (once at least two orgs have approved).
3. If an Org Admin or Dispatcher proposes it, **their own organization's**
   membership auto-approves (proposing *is* their org's consent) — but
   every **other** named organization's membership starts `pending`. The
   relationship does not become `active` until each other org's own
   authorized approver — someone whose account is scoped to that org, not
   the proposer — independently approves their own org's row.
4. **A single holdout blocks the whole named group.** If any named org's
   approver rejects instead of approving, the relationship's status
   becomes `rejected` — the group must renegotiate and re-propose, not
   quietly activate with a subset of members. An org can also later
   voluntarily withdraw from an already-active relationship the same way;
   this is not a failure, it just ends that org's participation.

**No one can consent on another org's behalf.** The proposer of a
relationship can never make a different named org's own membership row move
to `approved` — that action is gated on the *acting user's own*
organization membership matching the *row's own* organization, not on who
proposed the relationship or what permissions they hold globally. From Org
B's own admin's point of view, approving Org B's row *is* Org B saying yes
— not Org A vouching for Org B.

### Two independently-configurable ceilings

A relationship sets **two separate values**, not one, because a standing
partnership often needs different answers to "how much can they do" and
"how much can they see":

| Setting | Governs | Values |
|---|---|---|
| **Access tier** | Write capability — can the other org add notes, change status, assign units? | `view` (read-only) or `assist` (full same-org-equivalent write) |
| **Redaction profile** | Which fields the other org sees | `view` (redacted allowlist — see "The two access tiers," below) or `assist` (unredacted) |

These are set independently on purpose. A trusted standing mutual-aid
partner might legitimately get `access_tier=assist` (their dispatchers
should be able to add notes and assign their own units during a joint
response — genuine operational coordination) while still keeping
`redaction_profile=view` (caller PII and patient/medical detail still
shouldn't cross the inter-agency boundary by default, even for a trusted
partner, absent a specific reason to widen it). All four combinations are
legal: a `view`/`assist` pairing (full read visibility, no write access —
"situational awareness only," e.g. an EOC partner who needs to watch but
never touch) is just as legitimate as `assist`/`view`.

### Optional time-boxed activation

By default, a new relationship requires a separate **activation** step
(`requires_activation` is on by default — the conservative posture) — even
after two-party consent makes the relationship `active`, **no visibility
exists yet** until a dispatcher from one of the approved member
organizations explicitly activates it.

1. Once a relationship is `active` (two-party consent complete), any
   dispatcher or admin from an **approved member org** can **Activate** it
   from the same admin page — with a short reason and an optional duration
   (clamped to the relationship's own configured ceiling, if one is set;
   server-enforced, not just a client-side hint).
2. The moment it's activated, every other approved member org immediately
   gains visibility into the activating org's open tickets — including
   tickets that already existed before activation, not just ones created
   afterward — live, via SSE, no refresh needed.
3. When the activation window elapses (or any approved member org
   explicitly deactivates it early), visibility is gone. **This is
   enforced the instant the window closes, on the very next request that
   checks it — not on some later cleanup pass.** See "The read-time
   expiry guarantee," below — this is the single most important property
   of this mechanism.

A relationship can also be configured with `requires_activation` off, in
which case it behaves like an always-on standing grant once two-party
consent is reached — useful for a partnership where visibility should
simply always be there, with no activation ceremony needed.

### The read-time expiry guarantee

**When a relationship's activation window elapses, access is gone
immediately — checked fresh, in the database query itself, on every single
request — never by waiting for a background job to notice and flip a flag.**
A companion cleanup job runs every 5 minutes purely to stamp the closed
activation's audit record for a human reviewing history later; it is not
what revokes access, and it is proven **not** to be: a dedicated test
activates a relationship with a short window, lets it expire with the
cleanup job **never invoked at all**, and confirms visibility and write
access are both gone on the very next check — while the underlying database
row still shows no formal "deactivated" timestamp at that moment
(`tests/test_org_relationships_read_time_expiry.php`).

This matters operationally: if the cleanup job is stopped, misconfigured, or
simply hasn't run yet, **nothing about who can see what changes**. The only
thing that lags is the audit trail's own closing timestamp on that specific
activation record — never access itself.

## Permissions

| Permission | Default grant | Controls |
|---|---|---|
| `action.manage_org_routing` | Super Admin only | Author/edit/deactivate routing rules naming *any* owning organization. |
| `action.manage_org_routing_org` | Super Admin only (**not** granted to Org Admin by default) | Author/edit/deactivate routing rules where the caller's *own* organization is the owning org. |
| `action.share_incident` | Dispatcher, Org Admin | Manually share one incident the caller's org owns with another organization, at a chosen tier, with a reason. |
| `action.revoke_incident_share` | Dispatcher, Org Admin | Revoke an active share (manual or rule-sourced) on an incident the caller's org owns. |
| `action.manage_org_relationships` | Super Admin only | Full CRUD over any standing relationship naming any orgs; approve/reject any pending membership row on behalf of any org; edit ceiling settings. |
| `action.manage_org_relationships_org` | Dispatcher, Org Admin | Propose/administer standing relationships naming the caller's own org as one of the initial members. |
| `action.activate_org_relationship` | Dispatcher, Org Admin | Activate or deactivate a `requires_activation` relationship the caller's own org is an approved member of. |

**Why routing rules stay Super-Admin-only while manual sharing and standing
relationships are broadly granted:** the deciding factor is *who else has to
agree before anything is exposed*, not how the feature is used.

- A **routing rule** takes effect **unilaterally and immediately** — the
  moment a Super Admin saves it, it starts producing shares with no
  counterparty check, veto, or even notification from the receiving org's
  side. Handing that decision to any Org Admin would let a single
  non-Super-Admin unilaterally expose their org's ticket data to a peer org
  forever, with no check from the other side — so it stays Super-Admin-only
  by design.
- A **manual share** is bounded to one ticket, one decision, made once, by
  a person already actively working that specific call — closer in kind to
  assigning a unit or editing an incident (both already broadly granted)
  than to authoring a standing, unbounded rule. The security-critical
  boundary here isn't RBAC anyway — it's `org_ticket_is_owned_by_caller()`,
  re-checked on every single request (see "The one thing sharing can never
  do," above) — so withholding the RBAC code from Dispatcher would add no
  real protection while making the feature unreachable for the exact person
  every use case names.
- **Proposing a standing relationship** is broadly granted for a different
  reason than manual sharing, but arrives at the same broad-grant answer:
  proposing grants **zero visibility by itself**. The security boundary is
  the counterpart org's own independent per-row consent — an Org Admin who
  proposes recklessly accomplishes nothing without a genuinely independent
  org's own authorized approver saying yes. Withholding this from Org Admin
  would make the everyday "propose a mutual-aid relationship" workflow
  unreachable for no corresponding security gain.
- **Activating** an already-consented relationship is its own third,
  separate permission, deliberately gated **per-relationship** against the
  caller's own org's approved membership (not by the RBAC code alone) — a
  dispatcher can only activate a relationship their own org is an approved
  member of, never one their org merely knows exists.

A Super Admin can still hand-grant `action.manage_org_routing_org` to a
specific role via **Settings → Roles & Permissions**, as a deliberate,
auditable, per-install exception.

## The two access tiers

| Tier | What the responding org can do |
|---|---|
| **View** | Read-only. The ticket appears with a redacted field set — see "What `view` tier shows," below. No write action (add a note, change status, assign a unit) is permitted; attempting one is refused. |
| **Assist** | The responding org can add notes, update status, and assign their own units — the same actions a same-org dispatcher has. The full, unredacted field set is shown, the same as same-org access. |

For a routing rule or a manual share, one tier value governs both write
capability and field redaction. For a standing relationship, these are two
independently-configurable settings (`access_tier` and `redaction_profile`
— see "Two independently-configurable ceilings," above) — the table above
still describes what each *value* means, just applied to whichever of the
two settings is being read.

**Neither tier can ever move ownership of a ticket, from any grant source.**
There is no mechanism, at any tier, from a routing rule, a manual share, or
a standing relationship, that lets a responding org take over,
close-and-reassign, or soft-delete a ticket that belongs to another org —
soft-delete in particular is refused unconditionally, at both tiers, even
via the external API with a token that would otherwise have delete rights.
A `full`, ownership-capable tier does not exist anywhere in this feature
(see "Not built yet").

### What `view` tier shows

`view` tier is deliberately dispatch-relevant only, never the full record.
Shown: incident identity and type, the owning organization, location
(street/city/state/lat-lng), severity, status, operational timestamps,
receiving-facility, and each assigned unit's status. Never shown: caller
name/phone, free-text narrative (description/comments/the activity log),
or patient/medical detail.

This is an **allowlist**, not a blocklist — a field has to be explicitly
named to ride through at `view` tier. An unrecognized or newly-added field
is excluded by default, not shown by default, so a future endpoint change
can't accidentally widen what a `view`-tier org sees.

This composes with, and is independent of, the existing per-ticket security
label system: security labels narrow the *values* shown within whichever
fields are visible; this allowlist narrows the field *set* itself. Neither
one widens what the other restricts.

## Audit trail

Every routing-rule create, tier edit, and deactivation is written to the
audit log (`config` category), naming the org pair, match target, and tier.
Every share — whether created automatically by a rule or manually by a
dispatcher — is logged under the same `incident` / `share_created` activity
(the two origins are told apart by the `details` payload — `routing_rule_id`
set vs. `null` — not by a separate activity name). Revoking a share fires a
distinct `incident` / `share_revoked` entry. Every time a shared-with-org
user opens a shared ticket's detail view, a `view_shared` entry is logged,
fired only on the detail-view open, never on every list/dashboard poll.

Every standing-relationship lifecycle event is logged distinctly:
`relationship_proposed`, `relationship_member_approved`,
`relationship_member_rejected`, `relationship_activated`, and
`relationship_deactivated` — the last of these carries an `auto_expired`
flag in its details so an auditor can tell a human stand-down apart from an
activation window simply elapsing. There is no dedicated read-audit event
for relationship-derived ticket views (matching Phase 1's own reasoning for
declining to log every list-endpoint read as a share view — that would be
exactly the flood already avoided there): a relationship, once active,
behaves like ordinary same-org access for the life of the activation
window; only the lifecycle events themselves are logged.

Between the rule-level entries, the per-ticket `share_created`/
`share_revoked`/`view_shared` entries, and the relationship lifecycle
entries, an administrator reviewing an incident after the fact can answer
"which other org(s) could see this ticket, under what rule, whose manual
decision, or which standing relationship, for how long, and was it actually
opened by someone at that org."

## Not built yet

The following remain deliberately out of scope after all three phases —
named here so nobody assumes more shipped than actually did:

- **No ownership transfer / `full` tier.** No mechanism exists, at any
  tier, from any of the three grant sources, for a responding org to take
  over, close on the owning org's behalf, or delete a shared ticket. `view`
  and `assist` are the only two tiers that exist in the schema at all. This
  was never attempted and never accidentally dropped across any of the
  three phases — it is a deliberate, permanent boundary, not a future-phase
  placeholder.
- **No per-rule/per-share/per-relationship redaction configuration.** The
  `view`-tier field allowlist is one fixed set for every rule, every manual
  share, and every relationship's `view` redaction profile on the install;
  you can't configure a narrower or wider set per grant.
- **The target-org picker (manual sharing) and the member-org picker
  (standing relationships) both list every other active organization on
  the install**, not just orgs you already have a standing relationship
  with. This is deliberate — the scenarios these features exist for
  (mutual aid, an IC pulling in a unit from an org you've never worked with
  before, proposing a *brand-new* standing relationship) are often the
  *first* time two orgs interact, so narrowing either picker to "orgs with
  an existing relationship" would make it empty for exactly the cases it
  needs to serve, and would be circular for the relationship picker
  specifically (you'd need an existing relationship to propose one).
- **An owning org's routing rule matches its own `org_id` exactly — no
  descendant-tree matching.** If org A has children B and C, a rule with
  A as the owning org shares only tickets literally created with
  `org_id = A`, not tickets created under B or C. Write one rule per org
  whose tickets you want auto-shared. (This applies to routing rules only
  — a manual share always targets the ticket's actual owning org, and a
  standing relationship's members are named explicitly, so neither is
  affected.)
- **`assist` tier has no per-responder-org write boundary narrower than
  same-org dispatcher access, from any grant source.** An `assist`-tier
  user at the responding org can update the status of, or unassign, *any*
  unit on the shared ticket — including a unit dispatched by the owning
  org — the same reach a same-org co-dispatcher already has. There's no
  narrower "you may only touch units your own org dispatched" rule yet.
  This is an accepted, documented limitation, not a mistake; the ownership
  of the ticket itself is still never at risk (see "The two access tiers,"
  above), and no org can ever chain a further share or relationship onward
  regardless of tier (see "The one thing sharing can never do," above).
- **A session that's both an `allocates`-group member for a ticket and a
  member of a shared-with/relationship-connected org receives the same
  live-push event twice** (two separate SSE rows) — a named, accepted, and
  today structurally unreachable overlap (it would require an `allocates`
  row naming a group belonging to an org *other than* the ticket's owning
  org, which nothing in this codebase's assignment UI ever creates).

## For developers

The design documents (`spec.md`, `plan.md`, `tasks.md`) for each phase live
in `specs/phase-141-cross-org-ticket-sharing/`,
`specs/phase-142-cross-org-manual-sharing/`, and
`specs/phase-143-cross-org-standing-relationships/`, and are the
authoritative technical record — exact schema DDL, exact function
signatures, the full reasoning behind every decision summarized in this
document. `specs/phase-141-cross-org-ticket-sharing/gh70-summary.md` is the
single top-level document to read first for the whole build's story.

**Tables:** `org_type_routing` (the routing rule), `incident_shares` (the
per-ticket grant, whether a rule produced it automatically or a dispatcher
created it by hand — carries `created_by`/`created_by_name`/`share_reason`/
`revoked_by`/`revoked_by_name` from Phase 2), and three Phase-3 tables:
`org_relationships` (the named group; `status` is derived, recomputed
synchronously on every membership change, and safe to read directly since
it changes only via explicit human action, never elapsed time),
`org_relationships_members` (one row per named org, the two-party consent
state), and `org_relationships_activations` (the time-boxed lifecycle — a
`live_key` generated column enforces "at most one live activation per
relationship" as a real database constraint, collapsing the NULLable
`deactivated_at` lifecycle column the same way `org_type_routing.match_key`
collapses a NULLable discriminant; see `docs/PITFALLS-INDEX.md`).

**Code layout:** `inc/org-scope.php` gained exactly three new functions in
Phase 1 (`org_ticket_query_filter()`, `org_can_mutate_ticket()`,
`org_ticket_is_owned_by_caller()`) alongside one extended existing function
(`org_can_see_ticket()`); Phase 3 widened `org_can_see_ticket()`,
`org_ticket_query_filter()`, and `org_can_mutate_ticket()` again with a
relationship-aware OR-branch each, lazily loaded and try/catch-wrapped so a
pre-Phase-3 database behaves unchanged. **`org_visible_ids()` and
`org_ticket_is_owned_by_caller()` have never been modified by any of the
three phases, in any commit** — every grant source is injected exclusively
at the ticket-visibility layer, never at the org-membership layer, which is
what makes the anti-chaining guarantee structural rather than a per-phase
re-implementation. `inc/org-sharing.php` holds all Phase 1/2 logic plus
three small, deliberate Phase-3 widenings to its own choke-point functions
(`org_share_context_for_ticket()` now also consults relationship-sourced
context when `incident_shares` produces nothing, carrying a new
`redaction_tier` key every redaction call site must read instead of
`access_tier`; `org_sharing_apply_list_redaction()` gained a second batched
query). `inc/org-relationships.php` (new in Phase 3) holds every
relationship-specific function: the two-party consent primitive
(`org_relationship_can_act_for_org()`), propose/add-member/approve/reject,
activate/deactivate, and the read-time expiry SQL-fragment helper
(`org_relationship_activation_live_join_sql()` — deliberately returns SQL
text, not a boolean, so nothing on the read side can cache a stale answer).

**SSE:** `inc/sse.php`'s `sse_publish_for_incident()` makes up to three
independent `sse_publish()` calls per event — the original group/entitled
call, Phase 2's `incident_shares`-derived `'org'`-scope call, and Phase 3's
relationship-derived `'org'`-scope call — reusing the identical `'org'`
visibility scope Phase 2 built into `api/stream.php`, which required zero
reader-side changes for Phase 3 to reuse. Two coarse lifecycle event types
(`org_relationship:activated`/`org_relationship:deactivated`) are published
once per activation/deactivation, not per ticket, and wired into
`event-bus.js`'s `SSE_TYPES` array plus each consuming file's existing
coarse-refresh handler — matching this codebase's established pattern for
board-level changes rather than adding new per-ticket client logic.

**Mandatory no-op regression tests** exist for all three phases
(`tests/test_org_sharing_noop.php`, `tests/test_org_sharing_manual_noop.php`
+ `tests/test_org_sharing_sse_noop.php`, `tests/test_org_relationships_noop.php`
+ `tests/test_org_relationships_sse_noop.php`), each proving the feature is
byte-identical to its pre-existing behavior on an install that has never
used it. The anti-chaining boundary has dedicated adversarial regression
tests for both the share-derived case
(`tests/test_org_sharing_anti_chaining.php`) and the relationship-derived
case (`tests/test_org_relationships_anti_chaining.php`). The read-time
expiry guarantee has its own dedicated proof,
`tests/test_org_relationships_read_time_expiry.php` — described in "The
read-time expiry guarantee," above, and treated by this project as the
single most important test in the whole three-phase build.
