<?php
/**
 * Setup Audit Log — Create newui_audit_log table and verify with a test entry.
 *
 * Purpose:  Calls audit_ensure_table() to create the audit log table, then
 *           writes and reads back a test entry to verify the system works.
 * Usage:    php sql/setup_audit_log.php
 * Prerequisites: config.php; inc/audit.php with audit_ensure_table() and
 *                audit_log() functions.
 * Safety:   Idempotent. Creates table only if missing. Adds one test entry
 *           each time it runs.
 * Output:   Table creation status, column listing, test entry write/read.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/audit.php';

// Simulate session for testing
$_SESSION['user_id'] = 1;
$_SESSION['user'] = 'admin';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

echo "Creating audit log table..." . PHP_EOL;
$ok = audit_ensure_table();
echo $ok ? "  OK" : "  FAILED";
echo PHP_EOL;

// Verify structure
$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM newui_audit_log")->fetchAll(PDO::FETCH_ASSOC);
echo "Columns: " . count($cols) . PHP_EOL;
foreach ($cols as $c) {
    echo "  " . $c['Field'] . ' | ' . $c['Type'] . PHP_EOL;
}

// Test write
echo PHP_EOL . "Writing test entry..." . PHP_EOL;
$result = audit_log(
    'system',
    'create',
    'audit_log',
    null,
    'Audit logging system initialized',
    ['version' => '1.0', 'ocsf_inspired' => true]
);
echo $result ? "  Written OK" : "  FAILED";
echo PHP_EOL;

// Read back
$row = $pdo->query("SELECT * FROM newui_audit_log ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo PHP_EOL . "Last entry:" . PHP_EOL;
foreach ($row as $k => $v) {
    echo "  {$k} = " . ($v === null ? 'NULL' : $v) . PHP_EOL;
}

echo PHP_EOL . "Audit log setup complete." . PHP_EOL;
