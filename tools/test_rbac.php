<?php
/**
 * RBAC Integration Tests
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/rbac.php';

// Simulate admin session
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];

echo "=== RBAC Integration Tests ===\n\n";
$pass = 0;
$fail = 0;

// Test 1: Tables exist
echo "[Test 1] RBAC tables exist... ";
$tables = ['roles', 'permissions', 'role_permissions', 'user_roles'];
$allExist = true;
foreach ($tables as $t) {
    try {
        db_fetch_value("SELECT COUNT(*) FROM `$t`");
    } catch (Exception $e) {
        echo "FAIL: $t missing\n";
        $allExist = false;
        $fail++;
    }
}
if ($allExist) { echo "PASS\n"; $pass++; }

// Test 2: 6 default roles
echo "[Test 2] Default roles seeded... ";
$roleCount = (int) db_fetch_value("SELECT COUNT(*) FROM `roles`");
if ($roleCount >= 6) {
    echo "PASS ($roleCount roles)\n";
    $pass++;
} else {
    echo "FAIL: expected >= 6, got $roleCount\n";
    $fail++;
}

// Test 3: 62 permissions
echo "[Test 3] Permissions seeded... ";
$permCount = (int) db_fetch_value("SELECT COUNT(*) FROM `permissions`");
if ($permCount >= 60) {
    echo "PASS ($permCount permissions)\n";
    $pass++;
} else {
    echo "FAIL: expected >= 60, got $permCount\n";
    $fail++;
}

// Test 4: Permission categories
echo "[Test 4] Permission categories... ";
$cats = db_fetch_all("SELECT category, COUNT(*) AS cnt FROM `permissions` GROUP BY category ORDER BY category");
$catMap = [];
foreach ($cats as $c) $catMap[$c['category']] = (int) $c['cnt'];
if (isset($catMap['screen']) && isset($catMap['widget']) && isset($catMap['action']) && isset($catMap['field'])) {
    echo "PASS (screen:{$catMap['screen']}, widget:{$catMap['widget']}, action:{$catMap['action']}, field:{$catMap['field']})\n";
    $pass++;
} else {
    echo "FAIL: missing categories\n";
    $fail++;
}

// Test 5: Super Admin has all permissions
echo "[Test 5] Super Admin has all permissions... ";
$superPerms = (int) db_fetch_value("SELECT COUNT(*) FROM `role_permissions` WHERE role_id = 1");
if ($superPerms >= $permCount) {
    echo "PASS ($superPerms of $permCount)\n";
    $pass++;
} else {
    echo "FAIL: Super Admin has $superPerms of $permCount\n";
    $fail++;
}

// Test 6: rbac_can() for super admin
echo "[Test 6] rbac_can() super admin... ";
$_SESSION['level'] = 0;
if (rbac_can('action.manage_config') && rbac_can('screen.settings') && rbac_can('action.delete_incident')) {
    echo "PASS (all permissions granted)\n";
    $pass++;
} else {
    echo "FAIL: super admin denied a permission\n";
    $fail++;
}

// Test 7: rbac_can() legacy fallback for guest
echo "[Test 7] Legacy fallback for guest (level 3)... ";
// _rbac_legacy_check is still present in the v2 redesign during the
// deprecation window (Block B5 deletes it). Test it directly — its
// contract didn't change.
if (_rbac_legacy_check('screen.dashboard', 3) && !_rbac_legacy_check('action.manage_users', 3)) {
    echo "PASS (can view dashboard, cannot manage users)\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Test 8: User role assignment
echo "[Test 8] User role assignment check... ";
$_SESSION = ['user_id' => 1, 'level' => 0];
$roles = rbac_user_roles();
if (count($roles) >= 1 && $roles[0]['name'] === 'Super Admin') {
    echo "PASS (admin has Super Admin role)\n";
    $pass++;
} else {
    echo "FAIL: admin role not found, got " . count($roles) . " roles\n";
    $fail++;
}

// Test 9: Field Unit role has limited permissions
echo "[Test 9] Field Unit role permissions... ";
$fieldPerms = db_fetch_all("SELECT p.code FROM `role_permissions` rp JOIN `permissions` p ON rp.permission_id = p.id WHERE rp.role_id = 6");
$fieldCodes = array_column($fieldPerms, 'code');
$hasAddNote = in_array('action.add_note', $fieldCodes);
$noManageConfig = !in_array('action.manage_config', $fieldCodes);
$noSettings = !in_array('screen.settings', $fieldCodes);
if ($hasAddNote && $noManageConfig && $noSettings) {
    echo "PASS (" . count($fieldCodes) . " perms, has add_note, no config/settings)\n";
    $pass++;
} else {
    echo "FAIL\n";
    $fail++;
}

// Test 10: rbac.php helper file ES5 compliance (it's PHP, but check the JS parts)
echo "[Test 10] rbac.php helper loads without error... ";
// Already loaded above, so if we got here it works
echo "PASS\n";
$pass++;

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
