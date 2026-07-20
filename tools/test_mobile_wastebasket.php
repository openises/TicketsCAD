<?php
/**
 * Tests for Mobile Unit + Wastebasket features.
 * Usage: php tools/test_mobile_wastebasket.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;

function ok($cond, $msg) {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS: {$msg}\n";
        $pass++;
    } else {
        echo "  FAIL: {$msg}\n";
        $fail++;
    }
}

echo "=== Mobile Unit + Wastebasket Tests ===\n\n";

// ── 1. Schema: deleted_at columns ──────────────────────────────
echo "-- Schema Tests --\n";

$tables = ['member', 'responder', 'ticket', 'facilities'];
foreach ($tables as $t) {
    $col = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_at'",
        [$t]
    );
    ok($col !== null, "{$t} table has deleted_at column");

    $col2 = db_fetch_one(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'deleted_by'",
        [$t]
    );
    ok($col2 !== null, "{$t} table has deleted_by column");
}

// ── 2. Mileage log table ──────────────────────────────────────
echo "\n-- Mileage Log Table --\n";

$tbl = db_fetch_one(
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mileage_log'"
);
ok($tbl !== null, "mileage_log table exists");

$cols = db_fetch_all(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mileage_log'"
);
$colNames = array_column($cols, 'COLUMN_NAME');
ok(in_array('responder_id', $colNames), "mileage_log has responder_id");
ok(in_array('start_odo', $colNames), "mileage_log has start_odo");
ok(in_array('end_odo', $colNames), "mileage_log has end_odo");
ok(in_array('started_at', $colNames), "mileage_log has started_at");
ok(in_array('ended_at', $colNames), "mileage_log has ended_at");

// ── 3. Soft delete works on member ────────────────────────────
echo "\n-- Soft Delete Tests --\n";

// Insert test member (use field1/field2 for generated column compatibility)
try {
    // Discover any of these columns that have been added as VIRTUAL aliases
    // by tools/install_fresh.php — we have to write to the source legacy
    // field column when that's the case.
    $genCols = db_fetch_all(
        "SELECT COLUMN_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'member'
           AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION != ''
           AND COLUMN_NAME IN ('first_name', 'last_name', 'available')"
    );
    $fieldMap = [];
    foreach ($genCols as $gc) {
        $fieldMap[$gc['COLUMN_NAME']] = trim($gc['GENERATION_EXPRESSION'], '` ');
    }
    $col = function ($logical) use ($fieldMap) {
        return $fieldMap[$logical] ?? $logical;
    };
    $insertCols = [
        $col('first_name') => 'TestSoftDel',
        $col('last_name')  => 'User',
        $col('available')  => 'Yes',
        'updated_at'       => date('Y-m-d H:i:s'),
        'created_at'       => date('Y-m-d H:i:s'),
    ];
    $colNames = array_keys($insertCols);
    $colSql = '`' . implode('`, `', $colNames) . '`';
    $placeholders = rtrim(str_repeat('?, ', count($colNames)), ', ');
    db_query("INSERT INTO `member` ($colSql) VALUES ($placeholders)", array_values($insertCols));
    $testId = (int) db_insert_id();
    ok($testId > 0, "Created test member #{$testId}");

    // Soft delete
    db_query("UPDATE `member` SET `deleted_at` = NOW(), `deleted_by` = 1 WHERE `id` = ?", [$testId]);

    // Should not appear in normal queries
    $row = db_fetch_one("SELECT id FROM `member` WHERE `id` = ? AND `deleted_at` IS NULL", [$testId]);
    ok($row === null, "Soft-deleted member excluded from normal queries");

    // Should appear in wastebasket queries
    $row2 = db_fetch_one("SELECT id FROM `member` WHERE `id` = ? AND `deleted_at` IS NOT NULL", [$testId]);
    ok($row2 !== null, "Soft-deleted member found in wastebasket query");

    // Restore
    db_query("UPDATE `member` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?", [$testId]);
    $row3 = db_fetch_one("SELECT id FROM `member` WHERE `id` = ? AND `deleted_at` IS NULL", [$testId]);
    ok($row3 !== null, "Restored member visible in normal queries");

    // Cleanup
    db_query("DELETE FROM `member` WHERE `id` = ?", [$testId]);
    ok(true, "Cleaned up test member");
} catch (Exception $e) {
    ok(false, "Soft delete test exception: " . $e->getMessage());
}

// ── 4. Soft delete on responder ───────────────────────────────
echo "\n-- Responder Soft Delete --\n";

try {
    db_query(
        "INSERT INTO `responder` (`name`, `handle`, `description`, `type`, `un_status_id`)
         VALUES ('TestUnit', 'TU-99', 'Test unit for soft delete', 1, 1)"
    );
    $rId = (int) db_insert_id();
    ok($rId > 0, "Created test responder #{$rId}");

    db_query("UPDATE `responder` SET `deleted_at` = NOW(), `deleted_by` = 1 WHERE `id` = ?", [$rId]);
    $r = db_fetch_one("SELECT id FROM `responder` WHERE `id` = ? AND `deleted_at` IS NULL", [$rId]);
    ok($r === null, "Soft-deleted responder excluded from normal queries");

    // Restore
    db_query("UPDATE `responder` SET `deleted_at` = NULL, `deleted_by` = NULL WHERE `id` = ?", [$rId]);
    $r2 = db_fetch_one("SELECT id FROM `responder` WHERE `id` = ? AND `deleted_at` IS NULL", [$rId]);
    ok($r2 !== null, "Restored responder visible in normal queries");

    // Cleanup
    db_query("DELETE FROM `responder` WHERE `id` = ?", [$rId]);
    ok(true, "Cleaned up test responder");
} catch (Exception $e) {
    ok(false, "Responder soft delete test: " . $e->getMessage());
}

// ── 5. Mileage log CRUD ──────────────────────────────────────
echo "\n-- Mileage Log CRUD --\n";

try {
    db_query(
        "INSERT INTO `mileage_log` (`responder_id`, `user_id`, `start_odo`, `started_at`)
         VALUES (1, 1, 45230.0, NOW())"
    );
    $mId = (int) db_insert_id();
    ok($mId > 0, "Created mileage log entry #{$mId}");

    // Check open trip
    $m = db_fetch_one("SELECT * FROM `mileage_log` WHERE `id` = ? AND `ended_at` IS NULL", [$mId]);
    ok($m !== null, "Open mileage trip found");

    // End trip
    db_query(
        "UPDATE `mileage_log` SET `end_odo` = 45248.5, `ended_at` = NOW() WHERE `id` = ?",
        [$mId]
    );
    $m2 = db_fetch_one("SELECT * FROM `mileage_log` WHERE `id` = ?", [$mId]);
    ok($m2 !== null && $m2['ended_at'] !== null, "Mileage trip ended");
    ok($m2 !== null && (float)$m2['end_odo'] === 45248.5, "End odometer recorded correctly");

    // Cleanup
    db_query("DELETE FROM `mileage_log` WHERE `id` = ?", [$mId]);
    ok(true, "Cleaned up mileage entry");
} catch (Exception $e) {
    ok(false, "Mileage log test: " . $e->getMessage());
}

// ── 6. PHP syntax check on new files ──────────────────────────
echo "\n-- PHP Syntax Checks --\n";

$files = [
    'mobile.php',
    'api/mobile-data.php',
    'api/wastebasket.php',
];

$phpBin = PHP_BINARY;
foreach ($files as $f) {
    $path = __DIR__ . '/../' . $f;
    $output = [];
    $rc = 0;
    exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $rc);
    ok($rc === 0, "Syntax OK: {$f}" . ($rc !== 0 ? ' — ' . implode(' ', $output) : ''));
}

// ── 7. JS file exists ─────────────────────────────────────────
echo "\n-- File Existence Checks --\n";

$jsFiles = [
    'assets/js/mobile.js',
    'assets/css/mobile-unit.css',
];

foreach ($jsFiles as $f) {
    $path = __DIR__ . '/../' . $f;
    ok(file_exists($path), "File exists: {$f}");
}

// ── Summary ───────────────────────────────────────────────────
echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
