# Access Chain — how a user ends up seeing data

If a user logs in and the dashboard isn't what you expected, the cause is almost always somewhere in the **access chain**. This doc explains the chain in plain English, the common misconfigurations, and how to fix each one.

## The chain in 60 seconds

```
user account  →  RBAC role     (what they can DO)
              →  member record →  organization(s)   (what data they SEE)
```

When a user logs in:

1. The **user account** authenticates them (username + password + optional 2FA).
2. The user account's **RBAC role** decides what they can DO (create incidents, approve time, manage members, etc.). Missing role = the API returns 403 "No roles assigned."
3. The user account may be **linked to a member record** via the `member` field. If it is, the member's **organization assignments** decide what data the session is SCOPED to — which incidents, which roster, which facilities count as "theirs."
4. If the user is NOT linked to a member, OR the linked member has no organizations, the session has **no active organization** → no org filter applied → they see data across ALL organizations.

The two scoping outcomes:
- **Scoped to one org** (the normal case): linked to a member that belongs to an org → sees only that org's data
- **Unscoped — sees everything** (service / integration / super-admin case): no member link, OR linked to a member with no org → no filter → sees every org's data

Neither outcome is wrong — they suit different account types. The warning banners just make sure you're picking the right one on purpose.

## The common misconfigurations

### 1. No RBAC role granted

**Symptom:** Every API call returns 403 `"No roles assigned — contact an administrator"`.

**Cause:** The user account exists but no `user_roles` row was created for it.

**Self-heal:** As of 2026-06-02, the `Settings → User Accounts` form auto-grants the matching RBAC role based on the user's legacy `level` field every time you save the form. So this shouldn't happen for accounts created through the UI.

**Manual fix:** `Personnel → Roles & Permissions → User Grants`, click **Grant Role**, pick the user, pick a role, save.

### 2. User account not linked to a member (probably wrong for people, right for service accounts)

**Symptom:** User logs in, sees data from EVERY organization rather than the one they belong to.

**Cause:** The `user.member` field is empty. With no member link, the login flow can't derive an organization → `active_org_id` is null → no org filter is applied → the session is "unscoped" and sees all orgs' data.

**When this is correct:** Service / integration accounts (an APRS poller, a Meshtastic bridge, a backup audit tool) that legitimately need cross-org access. Super admins reviewing data across the whole install.

**When this is wrong:** A normal person who should only see their own agency's data. Most accounts fall here.

**Fix:** `Settings → User Accounts`, click the user, use the **Link to Member** picker to associate them with their roster record. The Save button will prompt you with a confirm dialog when you try to save an unlinked user — that's your reminder to either confirm the unscoped intent or link a member.

### 3. Linked, but the member belongs to no organizations (same impact as #2)

**Symptom:** Same as #2 — user logs in and sees data from every organization.

**Cause:** `user.member` is set, but `member_organizations` has zero rows for that member. Same outcome as no member at all — `active_org_id` is null, no filter applied, sees all orgs.

**Fix:** `Personnel → Roster`, find the member, scroll to **Organizations** section, click **+ Add to Organization**, pick an org. The roster page shows a yellow warning when the list is empty so the contextual fix is right there. The Save dialog on the user-account form also warns when you pick a member with no orgs.

**Note:** A member can belong to multiple organizations. The user's session uses one **active org** at a time — the first one returned by the query, ordered by `organizations.sort_order`. Org switching mid-session is a future capability.

### 4. Legacy `allocates` group filter (rare after the v3.44 migration)

**Symptom:** User has RBAC, linked member, member has orgs — but specific widgets (incidents, responders, facilities) still show empty.

**Cause:** The legacy `allocates` table has a per-resource group-membership filter that predates RBAC. Before the modern access chain existed, this was the *only* way to scope what a non-admin user could see. As of 2026-06-02, the four widget APIs (`incidents`, `responders`, `facilities`, `statistics`) bypass this legacy filter when the user holds the corresponding RBAC permission (`screen.incidents`, `responder.view`, `facility.view`, etc.) — so RBAC alone is enough.

**Fix:** Usually not needed. If you see this happening, confirm the user's RBAC role includes the screen/view/widget permission for the resource. If it does and you still see empty data, the `allocates` table may have an old explicit-deny rule for this user — clear it via direct SQL or contact support.

**Future:** Phase 7d (in `specs/future-phases.md`) fully retires the `allocates` table by folding its group concept into RBAC as a new `scope_kind='group'`. After that ships, this misconfiguration mode disappears entirely.

## How RBAC roles map to legacy levels

Older docs and the User Accounts form still show a numeric "Level" dropdown. That's the legacy authority field from v3.44 — it's still there for backward compatibility but **RBAC role is the real authority** post-migration. The level→role auto-grant maps them consistently:

| User form **Level** | Auto-granted **RBAC role** | What it means |
|---|---|---|
| 0 — Super | **Super Admin** | Bypasses every permission check. Reserve for site owners. |
| 1 — Administrator | **Org Admin** | Manages org-level config, members, incident types. |
| 2 — Operator | **Dispatcher** | Full operational access — create/edit/dispatch incidents. |
| 3 — Guest | **Read-Only** | View screens + widgets + a few field permissions. The "demo viewer" role. |
| 4 — Member / Unit | **Field Unit** | Mobile responder — status updates, notes, photo upload, location sharing. |
| 5–8 — Stats / Service / Facility / etc. | **Read-Only** | Equivalent to Guest for backward compatibility. |

If you need a finer-grained role assignment than this map allows, go to `Personnel → Roles & Permissions` and either pick a different role or build a custom one with exactly the permissions you want.

## What happens during a legacy v3.44 → NewUI v4 upgrade

When you upgrade an existing v3.44 install:

1. The migration runner (`tools/migrate_rbac.php`, called once during the upgrade) walks every existing user record and creates a `user_roles` row using the level→role map above. Every legacy user ends up with a modern RBAC grant.
2. The user's existing `allocates` group memberships stay intact in the legacy table. The four widget APIs honour BOTH the new RBAC grant AND the legacy allocates — when either says "you can see this," you see it.
3. The user's `member` link and their `member_organizations` rows are preserved as-is. Org chain unchanged.
4. Phase 7d (planned, not yet shipped) will fold the legacy `allocates` table into RBAC as `scope_kind='group'`. After that, "legacy" stops being a concept here entirely.

There is no scenario where an upgraded user *loses* access. The migration is additive — they keep what they had AND gain a modern RBAC grant.

## When in doubt

Three diagnostic queries an admin can run:

```sql
-- Does this user have an RBAC grant?
SELECT user.user, user.level, COUNT(user_roles.id) AS grants
FROM user
LEFT JOIN user_roles ON user_roles.user_id = user.id
WHERE user.user = 'username-here'
GROUP BY user.id;
-- If grants = 0, you've hit misconfiguration #1.

-- Is this user linked to a member?
SELECT user.user, user.member,
       CONCAT(m.last_name, ', ', m.first_name) AS member_name
FROM user
LEFT JOIN member m ON m.id = user.member
WHERE user.user = 'username-here';
-- If member is NULL, misconfiguration #2.

-- Does the linked member have any orgs?
SELECT u.user, m.last_name, COUNT(mo.id) AS orgs
FROM user u
LEFT JOIN member m ON m.id = u.member
LEFT JOIN member_organizations mo ON mo.member_id = m.id AND mo.status = 'active'
WHERE u.user = 'username-here'
GROUP BY u.id;
-- If orgs = 0, misconfiguration #3.
```

## TL;DR for site admins

- Create a user → form auto-grants the role from level. You're good on authentication.
- Want them scoped to one agency? **Link to a member that belongs to that agency's organization.**
- Want them to see everything (service account, super admin)? **Leave the member link empty.** The Save dialog will ask you to confirm that's what you meant.
- Confirm dialog on the user-account form + yellow warning on the roster-member page tell you when you've left the member without an org. Neither blocks the save — they just make sure you chose on purpose.
- "Legacy" is shrinking. By the time Phase 7d ships, the second authorization axis (`allocates`) goes away entirely and there's only one access chain to reason about.
