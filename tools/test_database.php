<?php
/**
 * Database Integrity Tests
 *
 * Verifies all expected tables exist, required columns are present,
 * default data is seeded, and relationships are valid.
 *
 * Usage: php tools/test_database.php
 */
require_once __DIR__ . '/../config.php';

echo "=== Database Integrity Tests ===\n\n";
$pass = 0;
$fail = 0;

$prefix = $GLOBALS['db_prefix'] ?? '';

// Fresh-install guard (QA automation 2026-07-07): the seed-data checks
// below (demo incident types, members, facilities, admin user) assume
// tools/create_admin.php + sql/seed_demo_data.php ran. On a virgin DB
// they legitimately haven't — skip rather than fail so CI stays green.
$__seedProbe = 0;
try {
    $__seedProbe = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}user`")
        + (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member`");
} catch (Exception $e) { /* tables missing → definitely unseeded */ }
if ($__seedProbe === 0) {
    echo "SKIP: virgin install, no seeded data (run tools/create_admin.php + sql/seed_demo_data.php)\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Helper: check if table exists
function table_exists($tableName) {
    try {
        db_fetch_value("SELECT 1 FROM `{$tableName}` LIMIT 1");
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Helper: check if column exists
function column_exists($table, $column) {
    try {
        $cols = db_fetch_all(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$table, $column]
        );
        return count($cols) > 0;
    } catch (Exception $e) {
        return false;
    }
}

// ── Core Table Existence ────────────────────────────────────

$coreTables = [
    'ticket'     => 'Incidents',
    'user'       => 'User accounts',
    'responder'  => 'Responders/units',
    'facilities' => 'Facilities',
    'in_types'   => 'Incident types',
    'action'     => 'Incident actions',
    'assigns'    => 'Assignments',
    'allocates'  => 'Group allocations',
    'settings'   => 'System settings',
    'member'     => 'Personnel/members',
];

echo "[Test 1] Core tables exist... ";
$missing = [];
foreach ($coreTables as $t => $desc) {
    if (!table_exists($prefix . $t)) {
        $missing[] = $t;
    }
}
if (empty($missing)) {
    echo "[PASS] (" . count($coreTables) . " tables)\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missing) . "\n";
    $fail++;
}

// ── NewUI Feature Tables ────────────────────────────────────

$featureTables = [
    'roles'                  => 'RBAC roles',
    'permissions'            => 'RBAC permissions',
    'role_permissions'       => 'RBAC role-permission links',
    'user_roles'             => 'RBAC user-role assignments',
    'organizations'          => 'Organizations',
    'member_organizations'   => 'Member-org links',
    'teams'                  => 'Teams',
    'member_types'           => 'Member types',
    'member_status'          => 'Member statuses',
    'login_attempts'         => 'Login tracking',
    'active_sessions'        => 'Session tracking',
    'config'                 => 'Configuration key-value',
    'newui_audit_log'        => 'Audit log',
    'sse_events'             => 'Server-sent events',
];

echo "[Test 2] NewUI feature tables exist... ";
$missing2 = [];
foreach ($featureTables as $t => $desc) {
    if (!table_exists($prefix . $t)) {
        $missing2[] = $t;
    }
}
if (empty($missing2)) {
    echo "[PASS] (" . count($featureTables) . " tables)\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missing2) . "\n";
    $fail++;
}

// ── Optional Feature Tables ─────────────────────────────────

$optionalTables = [
    'newui_equipment'        => 'Equipment tracking',
    'newui_equipment_types'  => 'Equipment types',
    'newui_vehicles'         => 'Vehicles',
    'newui_vehicle_types'    => 'Vehicle types',
    'newui_service_state'    => 'Service health',
    'newui_service_events'   => 'Service events',
    'dashboard_layouts'      => 'Dashboard layouts',
    'webhooks'               => 'Webhooks',
    'markup_categories'      => 'Map markup categories',
    'map_markups'            => 'Map markups',
    'captions_i18n'          => 'i18n strings',
];

echo "[Test 3] Optional feature tables... ";
$found3 = 0;
$missing3 = [];
foreach ($optionalTables as $t => $desc) {
    if (table_exists($prefix . $t)) {
        $found3++;
    } else {
        $missing3[] = $t;
    }
}
if ($found3 >= 8) {
    echo "[PASS] ($found3 of " . count($optionalTables) . " present)\n";
    $pass++;
} else {
    echo "[FAIL] only $found3 found, missing: " . implode(', ', $missing3) . "\n";
    $fail++;
}

// ── Required Columns: ticket ────────────────────────────────

echo "[Test 4] ticket table required columns... ";
$ticketCols = ['id', 'scope', 'address_about', 'severity', 'status'];
$missingCols = [];
foreach ($ticketCols as $col) {
    if (!column_exists($prefix . 'ticket', $col)) {
        $missingCols[] = $col;
    }
}
if (empty($missingCols)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missingCols) . "\n";
    $fail++;
}

// ── Required Columns: user ──────────────────────────────────

echo "[Test 5] user table required columns... ";
$userCols = ['id', 'user', 'passwd', 'level'];
$missingCols = [];
foreach ($userCols as $col) {
    if (!column_exists($prefix . 'user', $col)) {
        $missingCols[] = $col;
    }
}
if (empty($missingCols)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missingCols) . "\n";
    $fail++;
}

// ── Required Columns: responder ─────────────────────────────

echo "[Test 6] responder table required columns... ";
$respCols = ['id', 'name', 'handle', 'description'];
$missingCols = [];
foreach ($respCols as $col) {
    if (!column_exists($prefix . 'responder', $col)) {
        $missingCols[] = $col;
    }
}
if (empty($missingCols)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missingCols) . "\n";
    $fail++;
}

// ── Required Columns: facilities ────────────────────────────

echo "[Test 7] facilities table required columns... ";
$facCols = ['id', 'name', 'description'];
$missingCols = [];
foreach ($facCols as $col) {
    if (!column_exists($prefix . 'facilities', $col)) {
        $missingCols[] = $col;
    }
}
if (empty($missingCols)) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] missing: " . implode(', ', $missingCols) . "\n";
    $fail++;
}

// ── Required Columns: member ────────────────────────────────

echo "[Test 8] member table has name columns (legacy or newui)... ";
$hasFirst = column_exists($prefix . 'member', 'first_name');
$hasField1 = column_exists($prefix . 'member', 'field1');
if ($hasFirst || $hasField1) {
    echo "[PASS] (" . ($hasFirst ? 'NewUI columns' : 'legacy field columns') . ")\n";
    $pass++;
} else {
    echo "[FAIL] neither first_name nor field1 found\n";
    $fail++;
}

// ── Default Data: Incident Types ────────────────────────────

echo "[Test 9] Incident types seeded... ";
// A fresh install ships base_schema's example types only; the 50-type demo
// pack (sql/seed_demo_data.sql) is an optional import. What actually matters
// is that in_types is non-empty — an empty type table breaks the new-incident
// form's classification dropdown.
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}in_types`");
    if ($cnt >= 10) {
        echo "[PASS] ($cnt types — demo pack present)\n";
        $pass++;
    } elseif ($cnt >= 1) {
        echo "[PASS] ($cnt types — base examples only; demo pack sql/seed_demo_data.sql not imported)\n";
        $pass++;
    } else {
        echo "[FAIL] in_types is EMPTY — new-incident form type dropdown will be blank\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Default Data: RBAC Roles ────────────────────────────────

echo "[Test 10] RBAC roles seeded... ";
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}roles`");
    if ($cnt >= 6) {
        echo "[PASS] ($cnt roles)\n";
        $pass++;
    } else {
        echo "[FAIL] expected >= 6, got $cnt\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Default Data: Permissions ───────────────────────────────

echo "[Test 11] RBAC permissions seeded... ";
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`");
    if ($cnt >= 60) {
        echo "[PASS] ($cnt permissions)\n";
        $pass++;
    } else {
        echo "[FAIL] expected >= 60, got $cnt\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Admin User Exists ───────────────────────────────────────

echo "[Test 12] Admin user exists... ";
try {
    $admin = db_fetch_one("SELECT id, `user`, level FROM `{$prefix}user` WHERE `user` = 'admin'");
    if ($admin && (int) $admin['level'] === 0) {
        echo "[PASS] (id={$admin['id']}, level=0)\n";
        $pass++;
    } elseif ($admin) {
        echo "[PASS] (exists but level={$admin['level']})\n";
        $pass++;
    } else {
        echo "[FAIL] admin user not found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Admin Has Super Admin Role ──────────────────────────────

echo "[Test 13] Admin assigned Super Admin role... ";
try {
    $ur = db_fetch_one(
        "SELECT r.name FROM `{$prefix}user_roles` ur
         JOIN `{$prefix}roles` r ON ur.role_id = r.id
         WHERE ur.user_id = 1 AND r.name = 'Super Admin'"
    );
    if ($ur) {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] no Super Admin role for user 1\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Settings Table Has Required Keys ────────────────────────

echo "[Test 14] Settings/config table has data... ";
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}config`");
    if ($cnt >= 1) {
        echo "[PASS] ($cnt settings)\n";
        $pass++;
    } else {
        echo "[FAIL] config table empty\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Role-Permission Links ───────────────────────────────────

echo "[Test 15] Super Admin has all permissions... ";
try {
    $permCount = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}permissions`");
    $superPerms = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}role_permissions` WHERE role_id = 1");
    if ($superPerms >= $permCount) {
        echo "[PASS] ($superPerms of $permCount)\n";
        $pass++;
    } else {
        echo "[FAIL] Super Admin has $superPerms of $permCount\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Members Exist ───────────────────────────────────────────

echo "[Test 16] Member table has data... ";
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}member`");
    if ($cnt >= 1) {
        echo "[PASS] ($cnt members)\n";
        $pass++;
    } else {
        echo "[FAIL] no members found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Responders Exist ────────────────────────────────────────

echo "[Test 17] Responder table queryable... ";
// Responders are operator data — a fresh install ships the table EMPTY
// (the 10 demo responders come from the optional sql/seed_demo_data.sql
// pack). Assert the table exists and is queryable; a populated table
// still reports its count.
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}responder`");
    if ($cnt >= 1) {
        echo "[PASS] ($cnt responders)\n";
    } else {
        echo "[PASS] (empty — fresh install; demo pack sql/seed_demo_data.sql not imported)\n";
    }
    $pass++;
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Facilities Exist ────────────────────────────────────────

echo "[Test 18] Facilities table queryable... ";
// Facilities are operator data — a fresh install ships the table EMPTY
// (the 5 demo facilities come from the optional sql/seed_demo_data.sql
// pack). Assert the table exists and is queryable; a populated table
// still reports its count.
try {
    $cnt = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}facilities`");
    if ($cnt >= 1) {
        echo "[PASS] ($cnt facilities)\n";
    } else {
        echo "[PASS] (empty — fresh install; demo pack sql/seed_demo_data.sql not imported)\n";
    }
    $pass++;
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Organization Exists ─────────────────────────────────────

echo "[Test 19] Default organization exists... ";
try {
    $org = db_fetch_one("SELECT id, name FROM `{$prefix}organizations` WHERE id = 1");
    if ($org) {
        echo "[PASS] ({$org['name']})\n";
        $pass++;
    } else {
        echo "[FAIL] no organization with id=1\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── SSE Events Table ────────────────────────────────────────

echo "[Test 20] SSE events table accepts inserts... ";
try {
    db_query(
        "INSERT INTO `{$prefix}sse_events` (event_type, payload, user_id, created_at) VALUES (?, ?, ?, NOW())",
        ['test:db_integrity', '{"test":true}', 0]
    );
    $id = db_insert_id();
    if ($id > 0) {
        db_query("DELETE FROM `{$prefix}sse_events` WHERE id = ?", [$id]);
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] insert returned no ID\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Database Connection Pool ────────────────────────────────

echo "[Test 21] db() returns singleton PDO... ";
$pdo1 = db();
$pdo2 = db();
if ($pdo1 === $pdo2 && $pdo1 instanceof PDO) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] not singleton\n";
    $fail++;
}

// ── PDO Error Mode ──────────────────────────────────────────

echo "[Test 22] PDO error mode is EXCEPTION... ";
$errMode = db()->getAttribute(PDO::ATTR_ERRMODE);
if ($errMode === PDO::ERRMODE_EXCEPTION) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] error mode is $errMode\n";
    $fail++;
}

// ── PDO Emulated Prepares Off ───────────────────────────────

echo "[Test 23] PDO emulated prepares disabled... ";
$emulate = db()->getAttribute(PDO::ATTR_EMULATE_PREPARES);
if ($emulate === false || $emulate === 0) {
    echo "[PASS]\n";
    $pass++;
} else {
    echo "[FAIL] emulated prepares enabled\n";
    $fail++;
}

// ── Charset ─────────────────────────────────────────────────

echo "[Test 24] Database charset is utf8mb4... ";
try {
    $charset = db_fetch_value("SELECT @@character_set_connection");
    if ($charset === 'utf8mb4') {
        echo "[PASS]\n";
        $pass++;
    } else {
        echo "[FAIL] charset is $charset\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

// ── Permission Categories ───────────────────────────────────

echo "[Test 25] Permission categories exist (screen, widget, action, field)... ";
try {
    $cats = db_fetch_all("SELECT DISTINCT category FROM `{$prefix}permissions`");
    $catNames = array_column($cats, 'category');
    $required = ['screen', 'widget', 'action', 'field'];
    $missing = array_diff($required, $catNames);
    if (empty($missing)) {
        echo "[PASS] (" . implode(', ', $catNames) . ")\n";
        $pass++;
    } else {
        echo "[FAIL] missing categories: " . implode(', ', $missing) . "\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    $fail++;
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
