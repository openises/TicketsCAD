<?php
/**
 * Check Constituent (v3) — Inspect constituents and contacts table schemas.
 *
 * Purpose:  Displays column definitions, row counts, and sample data from the
 *           constituents and contacts tables in the newui database.
 * Usage:    php sql/check_constituent3.php
 * Prerequisites: config.php; constituents and contacts tables must exist.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Column types, row counts, and sample row from each table.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

echo "=== constituents table ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM constituents")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  " . $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . "\n";
}
$count = $pdo->query("SELECT COUNT(*) FROM constituents")->fetchColumn();
echo "\nRows: {$count}\n";

$row = $pdo->query("SELECT * FROM constituents LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($row) {
    echo "\nSample:\n";
    foreach ($row as $k => $v) echo "  $k = " . (is_null($v) ? 'NULL' : (strlen($v) > 60 ? substr($v, 0, 60) . '...' : $v)) . "\n";
}

echo "\n=== contacts table ===\n";
$cols2 = $pdo->query("SHOW COLUMNS FROM contacts")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols2 as $c) {
    echo "  " . $c['Field'] . ' | ' . $c['Type'] . "\n";
}
$count2 = $pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
echo "Rows: {$count2}\n";
