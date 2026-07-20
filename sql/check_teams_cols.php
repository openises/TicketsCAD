<?php
/**
 * Check Teams Columns — Dump teams table schema and sample row.
 *
 * Purpose:  Lists all columns in the teams table with type, null, and default
 *           info, plus a sample row for visual verification.
 * Usage:    php sql/check_teams_cols.php
 * Prerequisites: config.php; teams table must exist.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Full column listing and one sample row (or empty notice).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM teams")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . "\n";
}
$row = $pdo->query("SELECT * FROM teams LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "\nSample:\n";
    foreach ($row as $k => $v) echo "  $k = " . ($v ?? 'NULL') . "\n";
} else {
    echo "\n(empty table)\n";
}
