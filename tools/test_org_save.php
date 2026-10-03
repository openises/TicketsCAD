<?php
/**
 * Org-membership save round-trip.
 *
 * Writes role/status/notes onto an existing member_organizations row,
 * reads them back, asserts they actually changed, then restores the
 * original values.
 *
 * Before 2026-07-29 this file kept no score: it echoed "PASS:" on the
 * happy path, echoed "FAIL:" from a catch, and exited 0 either way with
 * no summary line — so tools/test_all.php recorded it as "0 passed, 0
 * failed" and the suite counted it as clean whether the write worked or
 * threw. It also never compared the values it read back, so a silently
 * ignored UPDATE would have printed PASS. Both are fixed here.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/audit.php';

$_SESSION = ['user_id' => 1, 'user' => 'admin'];
$prefix = $GLOBALS['db_prefix'] ?? '';

$pass = 0;
$fail = 0;
function ok(string $name): void { global $pass; echo "  PASS  $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "  FAIL  $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}

echo "=== Org Membership Save Test ===\n\n";

// Get first member org membership
try {
    $mo = db_fetch_one("SELECT * FROM `{$prefix}member_organizations` LIMIT 1");
} catch (Exception $e) {
    echo "SKIP: member_organizations not queryable — {$e->getMessage()}\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}
if (!$mo) {
    echo "SKIP: no member_organizations rows on this install to round-trip against.\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

echo "Testing update on member_id={$mo['member_id']}, org_id={$mo['org_id']}\n";
echo "Current role: " . ($mo['role'] ?: 'null') . "\n";
echo "Current status: {$mo['status']}\n\n";

$restored = false;
try {
    db_query(
        "UPDATE `{$prefix}member_organizations` SET role = ?, status = ?, notes = ?
          WHERE member_id = ? AND org_id = ?",
        ['admin', 'active', 'Test update from CLI', $mo['member_id'], $mo['org_id']]
    );
    ok('UPDATE executed without error');

    // Verify — the point of the test. An UPDATE that silently affects
    // nothing must not read as a pass.
    $updated = db_fetch_one(
        "SELECT * FROM `{$prefix}member_organizations` WHERE member_id = ? AND org_id = ?",
        [$mo['member_id'], $mo['org_id']]
    );
    if (!$updated) {
        bad('row still readable after update');
    } else {
        ($updated['role'] === 'admin')
            ? ok('role persisted as "admin"')
            : bad('role persisted as "admin"', 'got ' . var_export($updated['role'], true));
        ($updated['status'] === 'active')
            ? ok('status persisted as "active"')
            : bad('status persisted as "active"', 'got ' . var_export($updated['status'], true));
        ($updated['notes'] === 'Test update from CLI')
            ? ok('notes persisted')
            : bad('notes persisted', 'got ' . var_export($updated['notes'], true));
    }
} catch (Exception $e) {
    bad('org membership update round-trip', $e->getMessage());
} finally {
    // Restore whatever we found, even if an assertion above threw.
    try {
        db_query(
            "UPDATE `{$prefix}member_organizations` SET role = ?, status = ?, notes = ?
              WHERE member_id = ? AND org_id = ?",
            [$mo['role'], $mo['status'], $mo['notes'], $mo['member_id'], $mo['org_id']]
        );
        $restored = true;
    } catch (Exception $e) {
        // reported below
    }
}

$restored
    ? ok('original values restored')
    : bad('original values restored', 'the row is left holding the test values');

// Canonical summary — tools/test_all.php will not count a file that does
// not print this exact shape, and errors on one that prints nothing.
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
