<?php
/**
 * Run Units Screen Permission — seed `screen.units` and grant it to the
 * same roles that hold every other broadly-visible screen permission.
 *
 * Why this exists (2026-08-21, SPEC-STATUS.md B12):
 *   units.php (the Units/Responders list page) had no
 *   rbac_require_screen() gate at all -- authentication was enforced
 *   (redirect to login.php) and every widget on the dashboard is
 *   individually dash_can()-gated, so this was never an open door, but it
 *   was inconsistent with the other screens (facilities.php, roster.php,
 *   etc.), all of which gate on a screen.* permission. `screen.units` was
 *   ALREADY referenced twice in the codebase before this fix
 *   (inc/access.php's per-entity permission list, api/stream.php's SSE
 *   entitlement map) but only via a loop variable
 *   (`foreach ($perms as $p) { rbac_can($p) }`), never a literal
 *   `rbac_can('screen.units')` call -- which is exactly why
 *   tools/rbac_permission_audit.php's literal-string scan never flagged
 *   it as a phantom code the way it (correctly) doesn't flag
 *   variable-driven call sites at all. The code had never actually been
 *   seeded anywhere. Gating units.php on an unseeded code would have
 *   given every non-admin role a 403 on a page they could reach fine a
 *   moment before -- caught here, before it shipped, not after.
 *
 *   sql/rbac.sql + sql/run_00_rbac.php now seed `screen.units` for FRESH
 *   installs (its category is 'screen', so it's automatically picked up
 *   by every broad screen/widget grant -- Operator, Read-Only, and via
 *   the Org Admin/Dispatcher NOT-IN exclusion lists, none of which name
 *   it). This script reaches EXISTING installs the same way
 *   sql/run_report_perm.php reached them for action.view_reports.
 *
 * Grants: roles 1 (Super Admin), 2 (Org Admin), 3 (Dispatcher),
 * 4 (Operator), 5 (Read-Only). Deliberately NOT role 6 (Field Unit) --
 * Field Unit's own explicit code-allowlist already withholds
 * screen.unit_detail and screen.unit_edit (a mobile responder isn't meant
 * to browse the full unit roster, only their own status via the mobile
 * UI), and screen.units is the list view of the same resource, so it
 * follows the same precedent. Deliberately NOT role 7 (Facility) -- that
 * role is confined to exactly two facility-portal permissions
 * (Phase 145, GH#90); nothing in this script touches it.
 *
 * Usage: php sql/run_units_screen_perm.php   (also runs via sql/run_migrations.php)
 * Safety: idempotent — INSERT IGNORE only, safe to run repeatedly.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';

echo "Units screen permission (screen.units)\n";
echo "=======================================\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// Guard: RBAC tables must exist. On a pre-RBAC install there's nothing to do.
try {
    db_fetch_value("SELECT 1 FROM `{$prefix}permissions` LIMIT 1");
    db_fetch_value("SELECT 1 FROM `{$prefix}roles` LIMIT 1");
} catch (Exception $e) {
    echo "[skip] RBAC tables not present — nothing to seed.\n";
    return;
}

$code = 'screen.units';
$name = 'Units List';
$desc = 'View the units/responders list (units.php)';

try {
    db_query(
        "INSERT IGNORE INTO `{$prefix}permissions`
            (`code`, `name`, `category`, `resource`, `verb`, `description`)
         VALUES (?, ?, 'screen', 'units', 'view', ?)",
        [$code, $name, $desc]
    );
} catch (Exception $e) {
    // Older schema without resource/verb columns — fall back to the minimal set.
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}permissions` (`code`, `name`, `category`, `description`)
             VALUES (?, ?, 'screen', ?)",
            [$code, $name, $desc]
        );
    } catch (Exception $e2) {
        echo "[FAIL] could not seed {$code}: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

$permId = (int) db_fetch_value(
    "SELECT `id` FROM `{$prefix}permissions` WHERE `code` = ?",
    [$code]
);
if ($permId <= 0) {
    echo "[FAIL] {$code} was not seeded\n";
    exit(1);
}
echo "[ok] permission {$code} (id {$permId})\n";

$granted = 0;
foreach ([1, 2, 3, 4, 5] as $roleId) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}role_permissions` (`role_id`, `permission_id`)
             VALUES (?, ?)",
            [$roleId, $permId]
        );
        $granted++;
    } catch (Exception $e) {
        // Role may not exist on this install (custom role set) — not fatal.
        echo "[warn] role {$roleId}: " . $e->getMessage() . "\n";
    }
}
echo "[ok] {$code} granted to {$granted} role(s) "
   . "(1 Super Admin, 2 Org Admin, 3 Dispatcher, 4 Operator, 5 Read-Only)\n";

echo "\nDone.\n";
