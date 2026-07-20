<?php
/**
 * RBAC enforcement regression suite.
 * Companion spec: specs/rbac-enforcement-2026-06/{spec,plan,tasks}.md.
 *
 * Two layers:
 *   STATIC — every API write endpoint contains its rbac_can('action.X') gate,
 *            every gated page contains rbac_require_screen('screen.X'), the
 *            helpers exist, and the dashboard/layout enforcement points are wired.
 *            (DB-independent; guards against a future edit silently removing a gate.)
 *   DYNAMIC — grant a sandbox user each default role and assert rbac_can() returns
 *            the expected allow/deny per the seeded role→permission matrix
 *            (proves the gate decisions are correct and that no legitimate role is
 *            locked out).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/rbac.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== RBAC enforcement regression ===\n\n";
$pass = 0; $fail = 0;
function ok(string $n): void  { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $w = ''): void { global $fail; echo "[FAIL] $n" . ($w ? " — $w" : '') . "\n"; $fail++; }

// ─────────────────────────────────────────────────────────────────────
// STATIC — API write-endpoint gates
// ─────────────────────────────────────────────────────────────────────
echo "── Static: API action gates ──\n";
$apiGates = [
    'members.php' => 'action.manage_members',
    'teams.php' => 'action.manage_teams',
    'shifts.php' => 'action.manage_schedule',
    'equipment.php' => 'action.manage_members',
    'ics-positions.php' => 'action.manage_teams',
    'map-markups.php' => 'action.manage_map',
    'responder-save.php' => 'action.manage_members',
    'responder-status.php' => 'action.change_unit_status',
    'mobile-data.php' => 'action.change_unit_status',
    'facility-save.php' => 'action.manage_facilities',
    'constituents.php' => 'action.manage_members',
    'constituents-import.php' => 'action.import_data',
    'sop-save.php' => 'action.manage_sop',
    'sop-delete.php' => 'action.manage_sop',
    'training.php' => 'action.manage_members',
    'file-upload.php' => 'action.upload_files',
];
foreach ($apiGates as $file => $code) {
    $src = @file_get_contents("$base/api/$file");
    if ($src === false) { bad("api/$file present"); continue; }
    if (strpos($src, "rbac_can('$code')") !== false) ok("api/$file gates on $code");
    else bad("api/$file missing rbac_can('$code')");
}

// ─────────────────────────────────────────────────────────────────────
// STATIC — page screen gates
// ─────────────────────────────────────────────────────────────────────
echo "\n── Static: page screen gates ──\n";
$pageGates = [
    'new-incident.php' => 'screen.new_incident',
    'incident-list.php' => 'screen.incidents',
    'incident-detail.php' => 'screen.incident_detail',
    'situation.php' => 'screen.situation',
    'unit-detail.php' => 'screen.unit_detail',
    'unit-edit.php' => 'screen.unit_edit',
    'facilities.php' => 'screen.facilities',
    'facility-detail.php' => 'screen.facility_detail',
    'roster.php' => 'screen.roster',
    'teams.php' => 'screen.teams',
    'equipment.php' => 'screen.equipment',
    'vehicles.php' => 'screen.vehicles',
    'constituents.php' => 'screen.constituents',
    'scheduling.php' => 'screen.scheduling',
    'sop.php' => 'screen.sop',
    'reports.php' => 'screen.reports',
    'search.php' => 'screen.search',
];
foreach ($pageGates as $file => $code) {
    $src = @file_get_contents("$base/$file");
    if ($src === false) { bad("$file present"); continue; }
    if (strpos($src, "rbac_require_screen('$code')") !== false) ok("$file gates on $code");
    else bad("$file missing rbac_require_screen('$code')");
}

// Legacy-level admin pages migrated to is_admin()
foreach (['status.php','status-time.php','import-export.php'] as $file) {
    $src = @file_get_contents("$base/$file");
    if ($src === false) { bad("$file present"); continue; }
    if (strpos($src, 'is_admin()') !== false && !preg_match('/\$_SESSION\[.level.\]\s*>\s*1/', $src)) {
        ok("$file uses is_admin() (legacy level check removed)");
    } else {
        bad("$file still uses legacy level check or lacks is_admin()");
    }
}

// ─────────────────────────────────────────────────────────────────────
// STATIC — helpers + dashboard/layout wiring
// ─────────────────────────────────────────────────────────────────────
echo "\n── Static: helpers + dashboard/layout ──\n";
foreach (['rbac_require_screen','dash_can','dash_widget_perm','is_admin','rbac_can'] as $fn) {
    function_exists($fn) ? ok("helper $fn() exists") : bad("helper $fn() missing");
}
// statistics → widget.stats mapping (the catalog gotcha)
if (function_exists('dash_widget_perm')) {
    dash_widget_perm('statistics') === 'widget.stats' ? ok('statistics maps to widget.stats') : bad('statistics mapping wrong', dash_widget_perm('statistics'));
    dash_widget_perm('map') === 'widget.map' ? ok('map maps to widget.map') : bad('map mapping wrong');
}
$idx = @file_get_contents("$base/index.php");
if ($idx !== false) {
    strpos($idx, 'ALLOWED_WIDGETS') !== false ? ok('index.php emits ALLOWED_WIDGETS') : bad('index.php missing ALLOWED_WIDGETS');
    strpos($idx, 'dash_can(') !== false ? ok('index.php gates widgets via dash_can') : bad('index.php widgets not gated');
}
$wm = @file_get_contents("$base/assets/js/widget-manager.js");
if ($wm !== false) {
    strpos($wm, 'widgetAllowed') !== false ? ok('widget-manager.js filters via widgetAllowed') : bad('widget-manager.js not filtering');
}
$lay = @file_get_contents("$base/api/layout.php");
if ($lay !== false) {
    strpos($lay, 'layout_filter_perms') !== false ? ok('layout.php filters returned layout') : bad('layout.php missing perm filter');
}
$denied = @file_get_contents("$base/inc/denied.php");
$denied !== false && strpos($denied, 'Access Denied') !== false ? ok('inc/denied.php themed partial present') : bad('inc/denied.php missing');

// ─────────────────────────────────────────────────────────────────────
// DYNAMIC — role decisions match the seeded matrix (no lock-out)
// ─────────────────────────────────────────────────────────────────────
echo "\n── Dynamic: role allow/deny decisions ──\n";
$sandbox = (int) (db_fetch_value("SELECT id FROM `{$prefix}user` WHERE level > 0 ORDER BY id LIMIT 1") ?: 0);
if (!$sandbox) {
    echo "[SKIP] No non-admin user available; static checks above stand.\n";
} else {
    $pre = db_fetch_all("SELECT * FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
    db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
    register_shutdown_function(function () use ($sandbox, $pre, $prefix) {
        db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
        foreach ($pre as $row) {
            $cols = []; $vals = []; $params = [];
            foreach ($row as $k => $v) { if ($k === 'id') continue; $cols[] = "`$k`"; $vals[] = '?'; $params[] = $v; }
            try { db_query("INSERT INTO `{$prefix}user_roles` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")", $params); } catch (Throwable $e) {}
        }
        rbac_clear_cache();
    });

    $oldUser = $_SESSION['user_id'] ?? null;
    $_SESSION['user_id'] = $sandbox;

    function role_id_by_name(string $name): int {
        global $prefix;
        return (int) (db_fetch_value("SELECT id FROM `{$prefix}roles` WHERE name = ? LIMIT 1", [$name]) ?: 0);
    }
    function as_role(string $roleName): void {
        global $sandbox, $prefix;
        $rid = role_id_by_name($roleName);
        db_query("DELETE FROM `{$prefix}user_roles` WHERE user_id = ?", [$sandbox]);
        if ($rid) {
            // Match the install's user_roles shape (scope_kind etc. optional).
            try { db_query("INSERT INTO `{$prefix}user_roles` (user_id, role_id, scope_kind) VALUES (?,?, 'global')", [$sandbox, $rid]); }
            catch (Throwable $e) { db_query("INSERT INTO `{$prefix}user_roles` (user_id, role_id) VALUES (?,?)", [$sandbox, $rid]); }
        }
        rbac_clear_cache();
    }

    // Expected decisions per role (from the seeded matrix).
    // [roleName => [permCode => expectedBool]]
    $expect = [
        'Dispatcher' => [
            'action.manage_members' => true, 'action.manage_teams' => true,
            'action.change_unit_status' => true, 'screen.new_incident' => true,
            'widget.map' => true,
        ],
        'Operator' => [
            'action.manage_members' => false,   // Operator can't manage roster
            'action.change_unit_status' => true,
            'action.import_data' => false,
            'screen.incidents' => true,
        ],
        'Read-Only' => [
            'action.manage_members' => false, 'action.change_unit_status' => false,
            'action.create_incident' => false,
            'screen.incidents' => true,         // can VIEW
            'widget.map' => true,               // can see the map widget
        ],
        'Field Unit' => [
            'action.manage_members' => false,
            'action.change_unit_status' => true,
        ],
    ];
    foreach ($expect as $role => $checks) {
        $rid = role_id_by_name($role);
        if (!$rid) { echo "[SKIP] role '$role' not seeded\n"; continue; }
        as_role($role);
        foreach ($checks as $code => $want) {
            $got = rbac_can($code);
            if ($got === $want) ok("$role: rbac_can('$code') = " . ($want ? 'true' : 'false'));
            else bad("$role: rbac_can('$code') expected " . ($want ? 'true' : 'false') . " got " . ($got ? 'true' : 'false'));
        }
    }
    if ($oldUser !== null) $_SESSION['user_id'] = $oldUser; else unset($_SESSION['user_id']);
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
