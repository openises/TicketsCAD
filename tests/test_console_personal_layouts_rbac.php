<?php
/**
 * Phase 114b3 — LIVE RBAC boundary for personal console layouts.
 *
 * test_console_personal_layouts.php Part 3 already proves console_view_
 * can_write() itself is correct as a pure function. What that file
 * CANNOT prove is that a real session for a real role actually produces
 * the $canDesign input api/console-views.php feeds it — i.e. that
 * rbac_can('console.design') genuinely reads false for an ordinary
 * Dispatcher and true for an admin role, end to end through the real
 * RBAC grant tables (no session, no HTTP — driving rbac_can() directly
 * against temporary user_roles rows for fake user ids, mirroring test_
 * public_board_rbac.php Part 3's own established technique).
 *
 * Confirms the headline claim of this phase: an ordinary Dispatcher (who
 * already holds screen.console, per rbac.sql/run_phase114a_channel_
 * registry.php's seed) can create/save their OWN personal console view
 * and is refused editing a SHARED one — using the REAL grant tables, not
 * a hand-asserted boolean.
 *
 * @requires-db
 * Usage: php tests/test_console_personal_layouts_rbac.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/rbac.php';
require_once __DIR__ . '/../inc/console-views.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 114b3 — live RBAC boundary for personal console layouts ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

if (!rbac_schema_ready()) {
    echo "SKIP: RBAC v2 schema not present on this database.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$dispatcherRoleId = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = 'Dispatcher' LIMIT 1");
$superAdminRoleId = (int) db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = 'Super Admin' LIMIT 1");

if (!$dispatcherRoleId || !$superAdminRoleId) {
    echo "SKIP: 'Dispatcher'/'Super Admin' roles not found — cannot drive this against real data.\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$uidDispatcher = 900001520; // fake, well outside any real range
$uidSuperAdmin = 900001521;

/** Insert one temp user_roles row and return its id (0 on failure). */
function _cpl_grant($roleId, $userId) {
    global $prefix;
    try {
        db_query(
            "INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`, `scope_kind`, `scope_id`)
             VALUES (?, ?, 'global', NULL)",
            [$userId, $roleId]
        );
        return (int) db_insert_id();
    } catch (Throwable $e) {
        echo "  (setup failed: " . $e->getMessage() . ")\n";
        return 0;
    }
}

$insertedIds = [];
$origSession = $_SESSION ?? [];
try {
    $insertedIds[] = _cpl_grant($dispatcherRoleId, $uidDispatcher);
    $insertedIds[] = _cpl_grant($superAdminRoleId, $uidSuperAdmin);

    // ── Dispatcher session ──────────────────────────────────────────
    $_SESSION['user_id'] = $uidDispatcher;
    rbac_clear_cache();
    $dispatcherHasConsole = rbac_can('screen.console');
    $dispatcherHasDesign  = rbac_can('console.design');
    t('Dispatcher holds screen.console (seeded role grant — the console page itself is reachable)', $dispatcherHasConsole === true);
    t('Dispatcher does NOT hold console.design (this is the boundary Phase 114b3 relies on)', $dispatcherHasDesign === false);

    // Feed those REAL rbac_can() results into the pure gate the API uses —
    // end-to-end, not a hand-asserted boolean.
    $sharedRow = ['id' => 999001, 'owner_user_id' => null, 'name' => 'Shared'];
    $ownPersonalRow = ['id' => 999002, 'owner_user_id' => $uidDispatcher, 'name' => 'Mine'];
    $othersPersonalRow = ['id' => 999003, 'owner_user_id' => 900001522, 'name' => 'Not Mine'];

    $g = console_view_can_write($sharedRow, $uidDispatcher, $dispatcherHasDesign);
    t('Dispatcher (real session) is REFUSED editing a shared view (403)', $g['ok'] === false && $g['status'] === 403);

    $g = console_view_can_write($ownPersonalRow, $uidDispatcher, $dispatcherHasDesign);
    t('Dispatcher (real session) MAY edit their OWN personal view — no console.design required', $g['ok'] === true);

    $g = console_view_can_write($othersPersonalRow, $uidDispatcher, $dispatcherHasDesign);
    t("Dispatcher (real session) is REFUSED (404) editing ANOTHER user's personal view", $g['ok'] === false && $g['status'] === 404);

    // ── Super Admin session ─────────────────────────────────────────
    $_SESSION['user_id'] = $uidSuperAdmin;
    rbac_clear_cache();
    $adminHasDesign = rbac_can('console.design');
    t('Super Admin holds console.design (real grant table, is_super short-circuit)', $adminHasDesign === true);

    $g = console_view_can_write($sharedRow, $uidSuperAdmin, $adminHasDesign);
    t('Super Admin (real session) MAY edit a shared view', $g['ok'] === true);

    $g = console_view_can_write($ownPersonalRow, $uidSuperAdmin, $adminHasDesign);
    t("Super Admin (real session) is STILL REFUSED (404) editing the Dispatcher's personal view — "
        . 'admin status never grants access to someone else\'s personal layout',
        $g['ok'] === false && $g['status'] === 404);
} finally {
    foreach ($insertedIds as $id) {
        if ($id) { try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `id` = ?", [$id]); } catch (Throwable $e) {} }
    }
    $_SESSION = $origSession;
    rbac_clear_cache();
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
