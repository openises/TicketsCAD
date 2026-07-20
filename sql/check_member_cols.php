<?php
/**
 * Check Member Columns — Dump all columns and a sample row from the member table.
 *
 * Purpose:  Lists every column in the member table with type, null, default, and
 *           extra info. Shows a sample row for quick visual inspection.
 * Usage:    php sql/check_member_cols.php
 * Prerequisites: config.php; member table must exist.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Full column listing and one sample row.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();
$cols = $pdo->query('SHOW COLUMNS FROM member')->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . ' | ' . $c['Extra'] . "\n";
}
// Show a sample row
echo "\nSample row:\n";
$row = $pdo->query('SELECT * FROM member LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if ($row) {
    foreach ($row as $k => $v) echo "  $k = " . ($v ?? 'NULL') . "\n";
}
