<?php
/**
 * Check Log Table — Inspect legacy log and newui_audit_log tables.
 *
 * Purpose:  Displays column definitions, row counts, and recent entries from the
 *           legacy `log` table, then checks if newui_audit_log exists.
 * Usage:    php sql/check_log_table.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Column types, row count, recent 5 entries from log; existence
 *           check for newui_audit_log.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

echo "=== log table ===" . PHP_EOL;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM `log`")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  " . $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . PHP_EOL;
    }
    $cnt = $pdo->query("SELECT COUNT(*) FROM `log`")->fetchColumn();
    echo "Rows: {$cnt}" . PHP_EOL;
    if ($cnt > 0) {
        echo PHP_EOL . "Recent 5 entries:" . PHP_EOL;
        $rows = $pdo->query("SELECT * FROM `log` ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $parts = [];
            foreach ($r as $k => $v) $parts[] = "{$k}=" . ($v === null ? 'NULL' : (strlen($v) > 60 ? substr($v, 0, 60) . '...' : $v));
            echo "  " . implode(', ', $parts) . PHP_EOL;
        }
    }
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . PHP_EOL;
}

// Check if newui_audit_log already exists
echo PHP_EOL . "=== newui_audit_log ===" . PHP_EOL;
try {
    $cols = $pdo->query("SHOW COLUMNS FROM newui_audit_log")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) echo "  " . $c['Field'] . ' | ' . $c['Type'] . PHP_EOL;
} catch (Exception $e) {
    echo "  Does not exist (expected)" . PHP_EOL;
}
