<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/audit.php';

$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Org Membership Save Test ===\n\n";

// Get first member org membership
$mo = db_fetch_one("SELECT * FROM `member_organizations` LIMIT 1");
if (!$mo) { echo "No member_organizations records to test.\n"; exit; }

echo "Testing update on member_id={$mo['member_id']}, org_id={$mo['org_id']}\n";
echo "Current role: " . ($mo['role'] ?: 'null') . "\n";
echo "Current status: {$mo['status']}\n";

// Try updating
try {
    db_query(
        "UPDATE `member_organizations` SET role = ?, status = ?, notes = ? WHERE member_id = ? AND org_id = ?",
        ['admin', 'active', 'Test update from CLI', $mo['member_id'], $mo['org_id']]
    );
    echo "UPDATE succeeded.\n";

    // Verify
    $updated = db_fetch_one("SELECT * FROM `member_organizations` WHERE member_id = ? AND org_id = ?", [$mo['member_id'], $mo['org_id']]);
    echo "New role: {$updated['role']}\n";
    echo "New notes: {$updated['notes']}\n";

    // Restore
    db_query("UPDATE `member_organizations` SET role = ?, notes = ? WHERE member_id = ? AND org_id = ?",
        [$mo['role'], $mo['notes'], $mo['member_id'], $mo['org_id']]);
    echo "\nRestored original values.\n";
    echo "PASS: Org membership update works.\n";
} catch (Exception $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}
