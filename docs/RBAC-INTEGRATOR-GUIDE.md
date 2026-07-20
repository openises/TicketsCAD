# RBAC Integrator Guide — for developers

This is the API surface for any new endpoint or feature that needs to make permission decisions. The user-facing companion is [`RBAC-GUIDE.md`](RBAC-GUIDE.md).

## The one function you'll call most

```php
require_once __DIR__ . '/../inc/rbac.php';

if (!rbac_can('incident.edit', ['org_id' => $incident['org_id']])) {
    json_error('Forbidden', 403);
}
```

That's the whole contract. `rbac_can()` returns `true`/`false`. The first arg is the permission code; the second is an optional context array that scope predicates use to decide whether each grant applies.

### Context keys

| Key | When to pass it | What it does |
|-----|-----------------|--------------|
| `org_id` | The resource is tied to a specific org. | A grant with `scope_kind='org'` matches only when its `scope_id` equals this. Default fallback: `$_SESSION['active_org_id']`. |
| `team_id` | The resource is owned by a team. | Same shape, for `scope_kind='team'`. |
| `owner_id` | The resource has a single owning user (their time entry, their member record, their photo upload). | Required for `scope_kind='self'` to fire. **Pass the user_id of the resource's submitter, not the current actor.** |

You can pass multiple context keys; each grant is evaluated against the combined context. A user with both `Operator (scope=org, id=1)` and `Operator (scope=self)` will pass `rbac_can('time_entry.edit', ['org_id' => 1, 'owner_id' => 5])` either way.

### When NOT to pass context

If you're checking a global permission (`action.manage_config`, `screen.settings`), pass no context. The check still works, and any grant scoped tighter than global will simply not contribute.

### The Super Admin short-circuit

Roles with `is_super=1` (Super Admin by default) bypass `rbac_can()` entirely and return true regardless of context. Don't try to "block" Super Admin with context — by design, the role is unrestricted.

### Aliases

Old codes (`action.edit_incident`) are aliases of canonical codes (`incident.edit`). `rbac_can()` resolves both directions: a check on either will pass if the user holds either through any role. You can use either form in new code, but **prefer the canonical** (`<resource>.<verb>`) — the deprecation timeline removes the aliases in a future minor version.

The naming convention for new permissions:

- `<resource>.<verb>` — `incident.edit`, `time_entry.approve`, `member.delete`.
- Verbs in current use: `view`, `create`, `edit`, `delete`, `close`, `assign`, `dispatch`, `manage` (= create + edit + delete), `send`, `approve`, `reject`, `link`, `update`, `signup`.

When you add a new permission to the catalog, add a row to `sql/run_rbac_v2.php` so it migrates cleanly. Don't insert manually; the runner is idempotent and is the source of truth.

## Granting / revoking from code

Most endpoints don't grant — they just check. When you do need to grant (e.g. an admin UI, an onboarding hook, the migration runner):

```php
require_once __DIR__ . '/../inc/rbac_grant.php';

try {
    $grantId = rbac_grant_role(
        $userId,           // who gets the grant
        $roleId,           // which role
        'org',             // scope_kind: global / org / team / self / delegate
        $orgId,            // scope_id (NULL for global / self)
        '2026-06-08 23:59',// expires_at — string in ANY timezone PHP can parse
        'Sam covering for Pat 6/1-6/8',
        $current_user_id   // granted_by (audit)
    );
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 403);
}
```

`rbac_grant_role()` enforces:

- The granter must hold every permission the target role grants (privilege-escalation guard).
- Scope/scope_id consistency (`global` requires NULL, `org`/`team`/`delegate` require a non-null id).
- Expiry isn't already in the past (DB-clock check, not PHP-clock — works regardless of timezone alignment).
- Delegation depth bounded by `rbac.delegation_max_depth` setting.

Every grant emits an audit log entry; revocation is the same:

```php
rbac_revoke_grant($grantId, 'No longer needed');
```

## Fail-closed at the API edge

`api/auth.php` runs after session validation:

```php
if (_rbac_v2_schema_present() && empty(rbac_user_roles())) {
    audit_log('auth', 'no_roles', 'user', $userId, 'Authenticated user has zero active grants');
    json_error('No roles assigned — contact an administrator', 403);
}
```

So your endpoint can assume any authenticated request has at least one active grant. You don't need to check "does this user have any roles?" — auth.php has already done it.

## Permission catalog discovery

The full list of canonical permissions is in `permissions` (`SELECT code FROM permissions WHERE deprecated_alias_of IS NULL`). To find which roles hold a permission:

```sql
SELECT r.id, r.name
FROM permissions p
JOIN role_permissions rp ON rp.permission_id = p.id
JOIN roles r ON r.id = rp.role_id
WHERE p.code = 'time_entry.approve';
```

For ad-hoc checks during development, use the existing admin UI at *Settings → Roles & Permissions* — it shows the full role × permission matrix.

## Testing

Tests for new endpoints should mirror the patterns in `tests/test_rbac_v2.php`:

- **Schema** — verify any new permissions / role grants you seeded are present.
- **Scope** — confirm the endpoint denies when the user's scope doesn't match the resource.
- **Ownership** — when the endpoint passes `owner_id`, confirm both `owner_id == actor` and `owner_id != actor` give the right answer.
- **Audit** — confirm a row lands in `newui_audit_log` with the expected category/activity.

The `with_session_user($userId, $orgId, callable)` helper in the test suite swaps the `$_SESSION` user and clears the rbac cache around a closure — useful for testing as different users without spinning up real HTTP requests.

## Don't

- Don't gate on `$current_level` (the legacy `user.level` field). Migrate the check to `rbac_can()` before extending old endpoints. The legacy fallback exists only for installs that haven't run the migration.
- Don't add new screen-tied permissions (`screen.X`, `widget.X`). Those are UI-visibility only and a separate phase will clean them up.
- Don't grant roles directly via INSERT into `user_roles`. The grant module enforces the privilege-escalation guard; bypassing it is a security bug.
- Don't use `rbac_can()` inside the `rbac_grant_role()` path — it's already accounted for by `rbac_can_grant()`. Calling rbac_can recursively risks cache-confusion.
- Don't catch and swallow `RuntimeException` from grant module functions. They carry user-facing error messages; surface them.

## See also

- [`RBAC-GUIDE.md`](RBAC-GUIDE.md) — admin-facing.
- `inc/rbac.php` — `rbac_can()`, `rbac_user_permissions()`, alias resolver.
- `inc/rbac_grant.php` — `rbac_grant_role()`, `rbac_revoke_grant()`, `rbac_can_grant()`, `rbac_expire_due_grants()`.
- `sql/run_rbac_v2.php` — schema + seed runner. Add new permissions here.
- `tests/test_rbac_v2.php` — comprehensive regression suite; reading the test names is the fastest way to absorb the contract.
- `specs/rbac-redesign-2026-05/` — spec, plan, decisions, handoff.
