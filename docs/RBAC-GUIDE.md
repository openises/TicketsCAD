# RBAC Guide — for Org Admins

NewUI v4.0 ships with a **role-based access-control (RBAC)** system that lets you decide who can do what — incident dispatch, member edits, time approvals, system config — without hand-rolling level checks. This guide is for **administrators**, not developers. The companion file [`RBAC-INTEGRATOR-GUIDE.md`](RBAC-INTEGRATOR-GUIDE.md) covers the API surface for developers.

## The mental model in 60 seconds

1. **Permissions** are atoms. `incident.create`, `time_entry.approve`, `roster.view` — about 130 of them. You don't grant permissions to people directly.
2. **Roles** are bundles of permissions. NewUI ships 6 default roles: Super Admin, Org Admin, Dispatcher, Operator, Read-Only, Field Unit. You can create more.
3. **Grants** are how a user gets a role. A grant carries **scope** (where the role applies) and an optional **expiry** (when it stops applying).
4. When a user tries to do something, NewUI asks: *does the user hold a grant whose role includes this permission, whose scope is satisfied right now, and whose expiry hasn't passed?* If yes, the action proceeds. If no, the API returns 403.

That's it. Everything else is implementation detail.

## Default roles

| Role | Default permissions | Use it for |
|------|--------------------|-----|
| **Super Admin** | Everything (`is_super=1` short-circuit) | Site owner. Bypasses all checks. |
| **Org Admin** | Everything except a growing list of admin-only actions — `action.manage_config`, `action.manage_roles`, and the narrowly-held permissions in the table below | Per-organization administrator. Can manage members, incidents, schedule, but not the system itself. |
| **Dispatcher** | Full operational access (no `delete_incident`, no role mgmt, no system config, no bulk import) | The person on the radio answering calls. |
| **Operator** | Screens / widgets / fields + key actions (add notes, change unit status, self-signup, send chat, dispatch) | Generic privileged user — most volunteers. |
| **Read-Only** | Screens (no settings/new-incident/import-export) + widgets + 3 field permissions | Auditors, oversight, training observers. **Default for new users.** |
| **Field Unit** | Mobile-shaped subset: dashboard, incident detail, scheduling, status updates, photos | Tablets and phones in the field. |

### Narrowly-held permissions (not in any default role but Super Admin)

Some actions are deliberately kept off the default roles because they are a
bigger hammer than the everyday equivalent. They exist as normal permissions
and show up automatically in the Roles UI (the UI lists permissions
dynamically), so an admin can grant them to any role — they are simply not
granted by default.

| Permission code | Name | Default grant | What it gates |
|-----------------|------|---------------|---------------|
| `action.bulk_delete_members` | Bulk Delete Members | **Super Admin only** | The roster's multi-select bulk-removal flow. Single-member delete is unaffected (it stays on `action.manage_members`). |
| `action.manage_public_board` | Manage Public Incident Board (install-wide) | **Super Admin only** | Install-wide public-board settings — master enable switch, per-type publish rules, address-precision ceiling (Phase 138). Org Admin gets only `action.manage_public_board_org` (its own org's URL/self-service). Off by default on every install. |
| `action.manage_ics_form_types` | Manage Custom ICS Form Types (install-wide) | **Super Admin only** | Author/edit/deactivate custom ICS form types naming any owning org (Phase 140, GH#69). Org Admin gets only `action.manage_ics_form_types_org` (its own org's custom types). |
| `action.manage_org_routing` | Manage Cross-Org Ticket Routing Rules (install-wide) | **Super Admin only** | Author/edit/deactivate cross-org ticket auto-routing rules naming any owning org (Phase 141, GH#70). See [`CROSS-ORG-TICKET-SHARING.md`](CROSS-ORG-TICKET-SHARING.md). |
| `action.manage_org_routing_org` | Manage Own Org's Cross-Org Ticket Routing Rules | **Super Admin only** (deliberately **not** granted to Org Admin by default — a routing rule exposes the creating org's own data to a different org, with no two-party consent mechanism in Phase 1) | Author/edit/deactivate cross-org ticket routing rules where the caller's own org is the owning org. Exists so a Super Admin can hand-grant it per-install without a schema change. |
| `action.manage_org_relationships` | Manage Cross-Org Standing Relationships (install-wide) | **Super Admin only** | Full CRUD over any standing cross-org relationship naming any orgs; approve/reject any pending membership row on behalf of any org; edit ceiling settings (Phase 143, GH#70). See [`CROSS-ORG-TICKET-SHARING.md`](CROSS-ORG-TICKET-SHARING.md). |

### Feature-specific permissions with a broader default grant

Not every feature-specific permission is narrowly held — some are scoped to
a single ticket/action rather than an install-wide setting, so they ship
broadly granted from day one:

| Permission code | Name | Default grant | What it gates |
|-----------------|------|---------------|---------------|
| `action.share_incident` | Share Incident With Another Organization | Dispatcher, Org Admin | Manually share one incident the caller's org owns with another organization, at a chosen tier, with a reason (Phase 142, GH#70). See [`CROSS-ORG-TICKET-SHARING.md`](CROSS-ORG-TICKET-SHARING.md). |
| `action.revoke_incident_share` | Revoke a Cross-Org Incident Share | Dispatcher, Org Admin | Revoke an active manual or rule-sourced share on an incident the caller's org owns. |
| `action.manage_org_relationships_org` | Manage Own Org's Cross-Org Standing Relationships | Dispatcher, Org Admin | Propose/administer standing cross-org relationships naming the caller's own org as one of the initial members (Phase 143, GH#70). A deliberate departure from `action.manage_org_routing_org`'s narrower posture — proposing grants zero visibility by itself; the security boundary is the counterpart org's own per-row consent. |
| `action.activate_org_relationship` | Activate/Deactivate a Cross-Org Standing Relationship | Dispatcher, Org Admin | Activate or deactivate a `requires_activation` relationship the caller's own org is an approved member of (Phase 143, GH#70). |

These are broader than `action.manage_org_routing`/`action.manage_org_routing_org`
above on purpose: a manual share is a one-off decision bounded to a single
ticket, made by whoever is actively working that call — closer in kind to
`action.assign_unit` than to authoring a standing rule with unbounded future
scope. The real protection for this feature isn't RBAC — it's
`org_ticket_is_owned_by_caller()`, re-checked on every request, which no
share at any tier can ever satisfy (so a shared-in org can never re-share or
revoke a ticket it doesn't own, no matter what role it holds).

**To turn on bulk roster removal for another role** (e.g. Org Admin or a
custom "Roster Manager" role):

1. Go to **Config » Roles**.
2. Open the role you want to grant it to.
3. Tick **Bulk Delete Members** (under the *action* category) and save.

That role's members will then see the checkbox column and bulk-actions bar on
the roster. Because it is a normal permission, a scoped Org Admin grant only
applies within that org (see Scopes below).

> **Fresh installs** seed this via `sql/rbac.sql` (Super Admin only).
> **Existing installs** get it from the standard migration runner —
> `php sql/run_migrations.php` auto-discovers and applies
> `sql/run_bulk_delete_member_perm.php` (the same runner the in-app
> "migrations pending" banner triggers). To apply it in isolation you can also
> run `php sql/run_bulk_delete_member_perm.php` directly; it is idempotent and
> safe to run repeatedly — it creates the permission if missing and (re-)grants
> it to Super Admin only.

## Scopes

Every grant carries a *scope* that decides where its permissions apply:

- **Global** — the role works everywhere. Reserved for Super Admin and platform-level roles.
- **Org** — the role applies only when the active organization matches. Required for the per-org admin model.
- **Team** — the role applies only when acting on entities owned by the named team.
- **Self** — the role applies only when the resource is owned by the actor (their own time entry, their own member record). This is how you grant "edit your own profile" without granting "edit anyone's profile."
- **Delegate** — temporary handoff. Pat goes on call from June 1–8, you grant Sam Pat's role with `scope=delegate`, `scope_id=<Pat's user id>`, `expires_at=2026-06-08 23:59`. The grant ends automatically. Hop count is bounded by the `rbac.delegation_max_depth` setting (default 1 — direct hand-off only).

Scope is enforced by `rbac_can()` at API time. An Org Admin grant for org #1 does not apply when the user switches to org #2.

## Time-bound grants

Every grant has an optional `expires_at`. When set, the grant is filtered out of `rbac_can()` once `NOW()` passes the expiry. The row stays in the database (for audit history) until either an admin revokes it explicitly or the **Expire Due Grants** sweep runs.

Use cases:

- **On-call coverage.** Grant Dispatcher to a backup with `expires_at` = end of the shift.
- **Training periods.** New volunteer gets Operator with `expires_at` six months out; reassign or extend after performance review.
- **Investigations.** Auditor needs Read-Only access for two weeks; expiry guarantees their access removes itself.

## The privilege-escalation guard

You can **never** grant a role with permissions you don't already hold. The server checks this on every grant attempt:

1. If you're Super Admin, you can grant anything.
2. Otherwise you must hold `action.manage_roles` (or its alias `roles.manage`) **in the same scope** you're trying to grant.
3. Every permission in the target role must be one you hold (or an alias of one you hold).

If any of those fail, the grant is rejected at the API layer with a 403. The UI never shows the option but you couldn't bypass it via curl either — the guard lives in `inc/rbac_grant.php::rbac_can_grant()`.

The same rule applies to **revoke**: you can't revoke a grant whose scope/role you couldn't have granted yourself.

## Self-approval

Volunteer organizations often let members approve their own time entries — a member logs the time, an admin checks the queue, and a glance is enough to mark it approved. Stricter environments (paid staff, regulatory audit, separation of duties) need a different person to approve.

Toggle the setting **Require separate approver** in *Settings → Roles & Permissions → RBAC Settings*:

- **Off (default)** — Anyone with `time_entry.approve` can approve any entry, including their own.
- **On** — The system blocks self-approval at the `rbac_can()` layer, regardless of role. The actor must be different from the entry's submitter.

The setting is read on every approve check and applies to every approve-style verb (currently `time_entry.approve`; future approve-style permissions inherit the rule).

## Audit trail

Every grant, revoke, and expiry is written to `newui_audit_log` with:

- `category = 'rbac'`
- `activity = 'grant'` / `'revoke'` / `'expire'`
- `target_type = 'user_role'`
- `target_id = grant_id`
- `details` (JSON) — user_id, role_id, scope_kind, scope_id, expires_at, reason, granted_by

You can query the log directly (`SELECT * FROM newui_audit_log WHERE category = 'rbac'`) or — once the audit-tab UI lands — via the in-app dashboard.

## Common operations (UI walkthrough)

### Granting a role with expiry

1. *Settings → Roles & Permissions → User Grants*.
2. Click **Grant Role**.
3. Pick the user, role, scope (and scope ID if not global/self), expiry, and a reason. The reason is for your future self.
4. **Grant**. The grant appears in the list; the user's effective permissions update on their next request.

### Revoking a grant

1. *User Grants* list — find the row.
2. Click the trash icon. Confirm.
3. Audit-logged automatically; the user's effective permissions update on their next request.

### Sweeping expired grants

API: `POST /api/rbac.php` with `{action: 'expire_due_grants'}`.

There is no scheduled cron in NewUI today; an admin can wire `php tools/expire_grants.php` (TODO) into a system cron, or simply call the API endpoint periodically. Expired grants are excluded from `rbac_can()` whether or not they've been swept — the sweep is bookkeeping, not a security boundary.

### Toggling the security settings

*Settings → Roles & Permissions → RBAC Settings*. Two switches:

- **Require separate approver** — see above.
- **Delegation max depth** — 0 disables delegation, 1 allows direct hand-offs (recommended), 2–3 allow longer chains.

Save. Effect is immediate.

## Migrating from `user.level`

NewUI's older permission model was a single `user.level` integer (0 = super, 1 = admin, 2 = operator, 3 = guest, etc.). On every install or upgrade, `tools/install_fresh.php` runs `sql/run_rbac_v2.php` which:

1. Backs up `user_roles` to `user_roles_pre_v2_backup`.
2. Migrates any user who has no role assignments by mapping `user.level` to a default role (level 0 → Super Admin, level 2 → Dispatcher, etc.).
3. Adds the v2 columns (`scope_kind`, `expires_at`, `granted_by`, ...) to existing rows.

After migration, `user.level` is **read-only**. The system uses RBAC for every decision.

The old permission codes (`action.edit_incident`) still work — they're aliased to canonical codes (`incident.edit`) so any legacy code calling `rbac_can('action.edit_incident')` keeps passing as long as the user has either form. The sunset of the deprecated codes is a separate phase ([rbac-codes-cleanup-2026-?]) once the v3 → v4 upgrade story is settled.

## When something seems wrong

- **A user can't see something they should** — check their grants list. Did they get a `scope='self'` grant where they need `scope='global'`? Has an old grant expired?
- **A user can see something they shouldn't** — check whether they hold the canonical permission, the deprecated alias, or both. Audit log shows when each grant landed.
- **An Org Admin or Dispatcher can do something that table says they shouldn't be able to** (an admin-only action from the tables above succeeds for them) — this is a real class of bug this project fixed 2026-08-16: the default Org Admin/Dispatcher grant is seeded as "everything except a literal list of excluded codes," and that list can leak two ways — a role holding an excluded code's canonical alias (created later by the RBAC v2 migration, which a list written earlier can't have named), or a role that was granted the code directly before it was ever added to the exclusion list. Both are now self-healing on every re-seed (`php sql/run_00_rbac.php` or a fresh `sql/rbac.sql` import repairs both leak paths automatically), but if you're on an install that hasn't re-run the seed since 2026-08-16, re-run it. `tests/test_rbac_canonical_alias_leak.php` is the regression coverage.
- **An action returns 403** — read the response body. The API tells you which permission failed. Find a role that holds that permission, grant it (with the right scope), and retry.

## See also

- [`RBAC-INTEGRATOR-GUIDE.md`](RBAC-INTEGRATOR-GUIDE.md) — for developers writing new endpoints.
- `tests/test_rbac_v2.php` — 48 regression tests; a useful read if you want to understand the boundaries.
