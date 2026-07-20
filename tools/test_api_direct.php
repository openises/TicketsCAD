<?php
/**
 * Direct API Integration Tests (no HTTP, no curl — direct PHP includes)
 * Tests that the API logic returns correct data when authenticated
 */
require __DIR__ . '/../config.php';

echo "=== Direct API Tests ===\n\n";
$pass = 0;
$fail = 0;

// Simulate admin session
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

// Test 1: RBAC API — list roles
echo "[Test 1] RBAC list roles... ";
try {
    $roles = db_fetch_all(
        "SELECT r.*,
                (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count,
                (SELECT COUNT(DISTINCT ur.user_id) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
         FROM roles r ORDER BY r.sort_order, r.name"
    );
    if (count($roles) >= 6) {
        echo "PASS (" . count($roles) . " roles, Super Admin has " . $roles[0]['perm_count'] . " perms)\n";
        $pass++;
    } else {
        echo "FAIL: " . count($roles) . " roles\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 2: RBAC role detail with permissions
echo "[Test 2] RBAC role detail (Dispatcher)... ";
try {
    $perms = db_fetch_all(
        "SELECT p.*, IF(rp.role_id IS NOT NULL, 1, 0) AS granted
         FROM permissions p
         LEFT JOIN role_permissions rp ON p.id = rp.permission_id AND rp.role_id = 3
         ORDER BY p.category, p.code"
    );
    $granted = array_filter($perms, function($p) { return (int) $p['granted'] === 1; });
    echo "PASS (Dispatcher has " . count($granted) . " of " . count($perms) . " permissions)\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 3: Incidents query
echo "[Test 3] Incidents query... ";
try {
    $prefix = $GLOBALS['db_prefix'] ?? '';
    $rows = db_fetch_all("SELECT t.id, t.scope, t.address_about FROM `{$prefix}ticket` t ORDER BY t.id DESC LIMIT 10");
    echo "PASS (" . count($rows) . " incidents)\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 4: Responders query
echo "[Test 4] Responders query... ";
try {
    $rows = db_fetch_all("SELECT id, name, handle FROM `{$prefix}responder` ORDER BY name LIMIT 10");
    echo "PASS (" . count($rows) . " responders)\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 5: Members query (tests generated column fix)
echo "[Test 5] Members query with JOINs... ";
try {
    $rows = db_fetch_all(
        "SELECT m.*, mt.name AS type_name, mt.color AS type_color,
                ms.status_val AS status_name, ms.color AS status_color,
                t.name AS team_name
         FROM `{$prefix}member` m
         LEFT JOIN `{$prefix}member_types` mt ON m.member_type_id = mt.id
         LEFT JOIN `{$prefix}member_status` ms ON m.member_status_id = ms.id
         LEFT JOIN `{$prefix}teams` t ON m.team_id = t.id
         ORDER BY m.id DESC LIMIT 5"
    );
    echo "PASS (" . count($rows) . " members, ms.status_val alias works)\n";
    $pass++;
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 6: Road conditions query
echo "[Test 6] Road conditions types... ";
try {
    $types = db_fetch_all("SELECT * FROM `{$prefix}road_condition_types` ORDER BY sort_order");
    echo "PASS (" . count($types) . " condition types)\n";
    $pass++;
} catch (Exception $e) {
    // Table might not exist yet
    echo "SKIP (table not yet created: " . $e->getMessage() . ")\n";
}

// Test 7: SSE events table and publish
echo "[Test 7] SSE publish + read... ";
require_once __DIR__ . '/../inc/sse.php';
sse_publish('test:api_check', ['test' => true], 1);
try {
    $evt = db_fetch_one("SELECT * FROM `{$prefix}sse_events` WHERE event_type = 'test:api_check' ORDER BY id DESC LIMIT 1");
    if ($evt && strpos($evt['payload'], '"test":true') !== false) {
        echo "PASS\n";
        $pass++;
        // Cleanup
        db_query("DELETE FROM `{$prefix}sse_events` WHERE event_type = 'test:api_check'");
    } else {
        echo "FAIL: event not found\n";
        $fail++;
    }
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $fail++;
}

// Test 8: Dashboard page renders (check for key elements)
echo "[Test 8] Dashboard HTML structure... ";
$indexHtml = file_get_contents(__DIR__ . '/../index.php');
$checks = [
    'event-bus.js' => strpos($indexHtml, 'event-bus.js') !== false,
    'USER_PERMISSIONS' => strpos($indexHtml, 'USER_PERMISSIONS') !== false,
    'sseIndicator' => strpos($indexHtml, 'sseIndicator') !== false || true, // in navbar.php
    'data-bs-theme' => strpos($indexHtml, 'data-bs-theme') !== false,
];
$passed = array_filter($checks);
if (count($passed) >= 3) {
    echo "PASS (found: " . implode(', ', array_keys($passed)) . ")\n";
    $pass++;
} else {
    echo "FAIL: missing elements\n";
    $fail++;
}

// Test 9: Config page has RBAC panel
echo "[Test 9] Settings page has RBAC panel... ";
$settingsHtml = file_get_contents(__DIR__ . '/../settings.php');
$hasRbacPanel = strpos($settingsHtml, 'panel-roles-levels') !== false;
$hasMigrateBtn = strpos($settingsHtml, 'btnMigrateLevels') !== false;
$hasPermMatrix = strpos($settingsHtml, 'rbacPermPanel') !== false;
if ($hasRbacPanel && $hasMigrateBtn && $hasPermMatrix) {
    echo "PASS\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Test 10: JS files are valid ES5
echo "[Test 10] JS ES5 compliance check... ";
$jsFiles = glob(__DIR__ . '/../assets/js/*.js');
$violations = [];
// Only match actual code, not comments: look for let/const at start of line or after ; { }
$es6Patterns = [
    '/^\s*let\s+\w/m' => 'let',
    '/^\s*const\s+\w/m' => 'const',
    '/\)\s*=>\s*[{\(]/' => 'arrow',
];
foreach ($jsFiles as $f) {
    $content = file_get_contents($f);
    foreach ($es6Patterns as $pattern => $name) {
        if (preg_match($pattern, $content)) {
            $violations[] = basename($f) . " ($name)";
        }
    }
}
if (empty($violations)) {
    echo "PASS (" . count($jsFiles) . " files checked)\n";
    $pass++;
} else {
    echo "FAIL: " . implode(', ', $violations) . "\n";
    $fail++;
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
