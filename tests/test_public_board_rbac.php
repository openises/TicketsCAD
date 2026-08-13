<?php
/**
 * Phase 138 — Public incident board: RBAC (tasks.md C4/C5).
 *
 * Two layers:
 *
 *   1. Reachability through the REAL role_permissions table, post-migration
 *      — not a hand-seeded grant. action.manage_public_board reaches Super
 *      Admin ONLY; action.manage_public_board_org reaches Super Admin +
 *      Org Admin; NEITHER reaches Dispatcher/Operator/Read-Only/Field Unit.
 *
 *   2. The "critical covering case" (security review finding #1): an
 *      Org-Admin-shaped caller must never be able to write another org's
 *      row. Driving this end-to-end over real HTTP with a real login
 *      session is out of proportion to what's actually being protected —
 *      the entire fix IS the branching in pb_resolve_admin_write_org()
 *      (inc/public-board.php), a pure function extracted from
 *      api/public-board-admin.php's save_organization handler for exactly
 *      this reason (same principle as pb_round_coords() being kept pure).
 *      This test drives THAT function directly with every combination
 *      tasks.md C4 calls out, plus the slug-validation rule from C5.
 *
 * @requires-db
 * Usage: php tests/test_public_board_rbac.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — Public board RBAC ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ═══════════════════════════════════════════════════════════════════════
// Part 1 — reachability through role_permissions (real migration data)
// ═══════════════════════════════════════════════════════════════════════

try {
    $hasV2 = (bool) db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope_kind'",
        [$prefix . 'user_roles']
    );
} catch (Throwable $e) { $hasV2 = false; }

$permsExist = false;
try {
    $permsExist = (bool) db_fetch_value(
        "SELECT COUNT(*) FROM " . db_table('permissions') . "
          WHERE `code` IN ('action.manage_public_board', 'action.manage_public_board_org')"
    ) >= 2;
} catch (Throwable $e) { $permsExist = false; }

if (!$hasV2 || !$permsExist) {
    echo "SKIP: RBAC v2 schema / Phase 138 permissions not present on this install — nothing to test.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

function _pb_role_has_perm(string $roleName, string $permCode): bool {
    global $prefix;
    $n = db_fetch_value(
        "SELECT COUNT(*) FROM " . db_table('role_permissions') . " rp
           JOIN " . db_table('roles') . " r ON r.id = rp.role_id
           JOIN " . db_table('permissions') . " p ON p.id = rp.permission_id
          WHERE r.name = ? AND p.code = ?",
        [$roleName, $permCode]
    );
    return (int) $n > 0;
}

t('Super Admin holds action.manage_public_board', _pb_role_has_perm('Super Admin', 'action.manage_public_board'));
t('Super Admin holds action.manage_public_board_org', _pb_role_has_perm('Super Admin', 'action.manage_public_board_org'));

t('Org Admin does NOT hold action.manage_public_board (install-wide)', !_pb_role_has_perm('Org Admin', 'action.manage_public_board'));
t('Org Admin holds action.manage_public_board_org (self-service)', _pb_role_has_perm('Org Admin', 'action.manage_public_board_org'));

foreach (['Dispatcher', 'Operator', 'Read-Only', 'Field Unit'] as $roleName) {
    t("{$roleName} does NOT hold action.manage_public_board", !_pb_role_has_perm($roleName, 'action.manage_public_board'));
    t("{$roleName} does NOT hold action.manage_public_board_org", !_pb_role_has_perm($roleName, 'action.manage_public_board_org'));
}

// ═══════════════════════════════════════════════════════════════════════
// Part 2 — pb_resolve_admin_write_org() — the critical covering case
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- pb_resolve_admin_write_org() ---\n\n";

// A Super-Admin-shaped caller CAN target any org id from the request.
$d = pb_resolve_admin_write_org(true, false, /* callerOrgId */ 7, /* requested */ 42);
t('Board admin: writes the REQUESTED org id (any org)', $d['ok'] === true && $d['org_id'] === 42);

$d = pb_resolve_admin_write_org(true, false, 7, null);
t('Board admin: no org_id in request -> rejected 400 (not silently applied)', $d['ok'] === false && $d['status'] === 400);

// An Org-Admin-shaped caller writing THEIR OWN org — allowed.
$d = pb_resolve_admin_write_org(false, true, /* callerOrgId */ 11, /* requested */ 11);
t('Org-self: writing own org id -> allowed, org_id forced from session', $d['ok'] === true && $d['org_id'] === 11);

$d = pb_resolve_admin_write_org(false, true, 11, null);
t('Org-self: no org_id sent at all -> allowed, defaults to own org from session', $d['ok'] === true && $d['org_id'] === 11);

// ── THE critical case: an Org-Admin-shaped caller attempting to write a
// DIFFERENT org's id than their own session org_id. ──
$d = pb_resolve_admin_write_org(false, true, /* callerOrgId (session, own org) */ 11, /* requested (a DIFFERENT org) */ 999);
t('Org-self: DIFFERENT org id in request -> REJECTED, never applied', $d['ok'] === false);
t('Org-self: DIFFERENT org id -> 403, not silently ignored', $d['status'] === 403);
t('Org-self: DIFFERENT org id -> org_id in the decision is null, never 999', $d['org_id'] === null);

// Org-self with no organization on the account at all.
$d = pb_resolve_admin_write_org(false, true, /* callerOrgId */ 0, null);
t('Org-self: no org on account -> rejected 403 ("No organization on this account")', $d['ok'] === false && $d['status'] === 403);

// Neither permission — always Forbidden regardless of what's requested.
$d = pb_resolve_admin_write_org(false, false, 11, 11);
t('Neither permission: rejected 403 even when requesting own org', $d['ok'] === false && $d['status'] === 403);
$d = pb_resolve_admin_write_org(false, false, 0, 999);
t('Neither permission: rejected 403 (no org, requesting arbitrary org)', $d['ok'] === false && $d['status'] === 403);

// Board-admin ALSO holding the org-self permission (Super Admin's default
// grant per Section A) — the install-wide branch wins, so a Super Admin
// can still target any org, not just their own.
$d = pb_resolve_admin_write_org(true, true, 11, 999);
t('Board admin who ALSO holds org-self: install-wide branch wins (any org reachable)', $d['ok'] === true && $d['org_id'] === 999);

// ═══════════════════════════════════════════════════════════════════════
// C5 — slug validation
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- pb_valid_public_board_slug() (C5) ---\n\n";

// Synthetic fixture values only, deliberately -- not any real install's
// name. tools/release-snapshot.sh scrubs one beta tester's real install
// name out of every file it publishes to the public repo (privacy, on
// purpose), including string literals inside test files -- and this
// test used to pass that real name as fixture data, which the scrub
// rewrote into a value containing a space. A regex-validity assertion
// (this one) breaks when its input value is altered post-scrub; a
// value-echo assertion (seed and expectation both containing the same
// name) does not, because both sides get rewritten identically. Caught
// by the public repo's own CI -- exactly the gate meant to catch
// scrub-induced breakage -- not by anything local, which is why
// tools/release-snapshot.sh now also runs the test suite against its
// OWN staged output before anything is published.
t('valid: lowercase letters', pb_valid_public_board_slug('example-agency'));
t('valid: with digits and hyphens', pb_valid_public_board_slug('example-agency-2'));
t('valid: empty string (no slug set)', pb_valid_public_board_slug(''));
t('invalid: uppercase letters rejected', !pb_valid_public_board_slug('ExampleAgency'));
t('invalid: spaces rejected', !pb_valid_public_board_slug('example agency'));
t('invalid: underscore rejected', !pb_valid_public_board_slug('example_agency'));
t('invalid: slashes rejected (path traversal shape)', !pb_valid_public_board_slug('../etc'));
t('invalid: query-string-shaped input rejected', !pb_valid_public_board_slug('org?x=1'));

// ═══════════════════════════════════════════════════════════════════════
// Part 3 — pb_resolve_caller_org_id() against the REAL user_roles/
// role_permissions tables (final adversarial review, 2026-08-13)
//
// The gap this test file's own docblock (above) used to name explicitly:
// _pb_admin_caller_org_id()'s real session-derived input was NEVER driven
// end-to-end, only pb_resolve_admin_write_org() with a hand-supplied
// $callerOrgId. That let a THIRD instance of the "permission check with a
// widening fallback" pattern ship — a global-scope "Org Admin" grant
// (confirmed LIVE against this install: user_id 3 and 218 both hold role
// "Org Admin" with scope_kind='global') resolved to org #1 via
// org_user_home_id()'s new-record-default fallback, even though the
// caller was never scoped to any specific org. This section drives the
// REPLACEMENT function, pb_resolve_caller_org_id(), against real
// user_roles/role_permissions rows for temporary fake user ids — no
// session, no HTTP, matching this file's existing "drive the pure
// function directly" convention.
// ═══════════════════════════════════════════════════════════════════════

echo "\n--- pb_resolve_caller_org_id() against real user_roles/role_permissions ---\n\n";

// Org Admin (role_id=2) is confirmed above to hold action.manage_public_
// board_org — reuse it rather than seeding a throwaway role+permission.
$orgAdminRoleId = (int) db_fetch_value(
    "SELECT `id` FROM " . db_table('roles') . " WHERE `name` = 'Org Admin' LIMIT 1"
);

if ($orgAdminRoleId <= 0) {
    echo "SKIP: 'Org Admin' role not found — cannot drive Part 3 against real data.\n";
} else {
    // Fake user ids well outside any real range this install would ever
    // assign, so cleanup can never touch a genuine account.
    $uidNone     = 900001138;
    $uidGlobal   = 900001139; // mirrors the live user_id 3 / 218 shape
    $uidOrgOnly  = 900001140;
    $uidTwoOrgs  = 900001141; // ambiguous — two distinct org-scoped grants

    $insertedIds = [];
    try {
        $insertedIds[] = _pb3_grant($orgAdminRoleId, $uidGlobal, 'global', null);
        $insertedIds[] = _pb3_grant($orgAdminRoleId, $uidOrgOnly, 'org', 77);
        $insertedIds[] = _pb3_grant($orgAdminRoleId, $uidTwoOrgs, 'org', 5);
        $insertedIds[] = _pb3_grant($orgAdminRoleId, $uidTwoOrgs, 'org', 6);
        // An EXPIRED org-scoped grant must not count — same "ignore lapsed
        // grants" rule the rest of RBAC applies via expires_at.
        $insertedIds[] = _pb3_grant($orgAdminRoleId, $uidOrgOnly, 'org', 999, '2020-01-01 00:00:00');

        t('user with NO grants at all -> resolves to 0 (no organization)',
            pb_resolve_caller_org_id($uidNone) === 0);

        t('THE LIVE BUG SHAPE: global-scope Org Admin grant -> resolves to 0, NOT org #1 '
            . '(this is the exact shape of real accounts user_id 3 and 218 on this install)',
            pb_resolve_caller_org_id($uidGlobal) === 0);

        t('single org-scoped grant (scope_id=77) -> resolves to 77, not the requester\'s guess',
            pb_resolve_caller_org_id($uidOrgOnly) === 77);

        t('two distinct org-scoped grants (ambiguous) -> resolves to 0, never the lowest/first',
            pb_resolve_caller_org_id($uidTwoOrgs) === 0);

        t('user id <= 0 -> resolves to 0 without querying', pb_resolve_caller_org_id(0) === 0);
    } finally {
        foreach ($insertedIds as $id) {
            if ($id) { try { db_query("DELETE FROM " . db_table('user_roles') . " WHERE `id` = ?", [$id]); } catch (Throwable $e) {} }
        }
    }
}

/** Insert one temp user_roles row and return its id (0 on failure). */
function _pb3_grant(int $roleId, int $userId, string $scopeKind, ?int $scopeId, ?string $expiresAt = null): int {
    try {
        db_query(
            "INSERT INTO " . db_table('user_roles') . " (`user_id`, `role_id`, `scope_kind`, `scope_id`, `expires_at`)
             VALUES (?, ?, ?, ?, ?)",
            [$userId, $roleId, $scopeKind, $scopeId, $expiresAt]
        );
        return (int) db_insert_id();
    } catch (Throwable $e) {
        echo "  (setup failed for _pb3_grant: " . $e->getMessage() . ")\n";
        return 0;
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
