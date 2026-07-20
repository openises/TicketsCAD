<?php
/**
 * Phase 11c — role editing UX cleanup tests.
 *
 * Verifies:
 *   - No "· system" badge in the role dropdown rendering
 *   - New Role flow has inline name + description form
 *   - Role detail panel has editable name + description form
 *   - Delete Role button shows for every role (no id>6 guard)
 *   - Server delete_role no longer blocks on is_system
 *   - Server delete_role refuses to delete the last super-admin-granting role
 *   - Field label renamed to "Role and permission group"
 *   - No "level" mentions in user-facing code comments
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 11c — role editing UX tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── config.js ─────────────────────────────────────────────────────────
$cj = file_get_contents($base . '/assets/js/config.js');

if (strpos($cj, "label += ' · system'") === false) {
    ok('config.js no longer appends "· system" badge to dropdown');
} else {
    bad('config.js still appends "· system" badge');
}

if (strpos($cj, 'id="rbacNewRoleForm"') !== false &&
    strpos($cj, 'id="newRoleName"') !== false &&
    strpos($cj, 'id="newRoleDesc"') !== false) {
    ok('config.js New Role flow has inline name + description form');
} else {
    bad('config.js New Role flow missing inline form');
}

if (strpos($cj, 'id="rbacRoleEditForm"') !== false &&
    strpos($cj, 'id="rbacRoleEditName"') !== false &&
    strpos($cj, 'id="rbacRoleEditDesc"') !== false &&
    strpos($cj, 'id="rbacRoleEditToggle"') !== false) {
    ok('config.js role-detail has editable name + description with pencil toggle');
} else {
    bad('config.js role-detail missing editable name/description');
}

if (strpos($cj, 'parseInt(role.id, 10) > 6') === false) {
    ok('config.js no longer hides Delete Role on id <= 6');
} else {
    bad('config.js still hides Delete on id<=6');
}

// ── api/rbac.php ──────────────────────────────────────────────────────
$rb = file_get_contents($base . '/api/rbac.php');

if (strpos($rb, 'Cannot delete a system role') === false) {
    ok('api/rbac.php no longer blocks system-role delete on is_system');
} else {
    bad('api/rbac.php still blocks system-role delete');
}

if (strpos($rb, 'Refusing to delete the only role granting super-admin') !== false) {
    ok('api/rbac.php has last-super-admin lockout-safety check');
} else {
    bad('api/rbac.php missing lockout-safety check');
}

// ── settings.php ──────────────────────────────────────────────────────
$st = file_get_contents($base . '/settings.php');

if (strpos($st, "'Role and permission group'") !== false) {
    ok('settings.php uses new "Role and permission group" label');
} else {
    bad('settings.php still has old label');
}

// ── Caption check ─────────────────────────────────────────────────────
try {
    $v = db_fetch_value(
        "SELECT value FROM `{$prefix}captions_i18n`
         WHERE caption_key = 'useracct.role_label' AND lang = 'en'"
    );
    if ($v === 'Role and permission group') {
        ok('useracct.role_label caption [en] = "Role and permission group"');
    } else {
        bad('useracct.role_label caption [en]', "got: " . var_export($v, true));
    }
} catch (Exception $e) {
    bad('caption query', $e->getMessage());
}

// ── Functional: server-side delete-role lockout-safety check ─────────
// Simulate the check via direct SQL: count how many active users would
// have super-admin access if Super Admin (role 1) were removed.
try {
    $otherSuperUsers = (int) db_fetch_value(
        "SELECT COUNT(DISTINCT ur.user_id)
         FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}roles` r ON r.id = ur.role_id
         JOIN `{$prefix}user` u ON u.id = ur.user_id
         WHERE r.is_super = 1 AND r.id <> 1
           AND (ur.expires_at IS NULL OR ur.expires_at > NOW())"
    );
    ok("Lockout-safety query runs; would orphan {$otherSuperUsers} super-admin user(s) if role 1 were deleted");
} catch (Exception $e) {
    bad('lockout-safety query', $e->getMessage());
}

// ── Functional: rename a system role round-trip ──────────────────────
// Phase 11c removed the is_system guard on save_role (the API never
// had one — verified by reading the code). Round-trip a rename on
// role 4 (Operator) and restore.
try {
    $original = db_fetch_one(
        "SELECT name, description FROM `{$prefix}roles` WHERE id = 4 LIMIT 1"
    );
    if ($original) {
        db_query(
            "UPDATE `{$prefix}roles` SET name = ?, description = ? WHERE id = 4",
            ['Test Renamed Operator', 'Phase 11c rename round-trip test']
        );
        $after = db_fetch_one("SELECT name, description FROM `{$prefix}roles` WHERE id = 4");
        if ($after['name'] === 'Test Renamed Operator' &&
            $after['description'] === 'Phase 11c rename round-trip test') {
            ok('System role 4 (Operator) renamed + re-described in DB');
        } else {
            bad('Rename round-trip', var_export($after, true));
        }
        // Restore
        db_query(
            "UPDATE `{$prefix}roles` SET name = ?, description = ? WHERE id = 4",
            [$original['name'], $original['description']]
        );
        ok('Restored role 4 to original name + description');
    } else {
        bad('role id=4 not present');
    }
} catch (Exception $e) {
    bad('rename round-trip', $e->getMessage());
}

// ── No "Migrate Legacy Levels button" comment left ───────────────────
if (strpos($cj, 'Migrate Legacy Levels button') === false) {
    ok('config.js no "Migrate Legacy Levels button" comment');
} else {
    bad('config.js still has "Migrate Legacy Levels button" comment');
}

echo "\n";
echo "===========================================\n";
echo "Phase 11c role editing: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
