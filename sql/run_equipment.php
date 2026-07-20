<?php
/**
 * Run Equipment — Execute equipment.sql schema migration.
 *
 * Purpose:  Creates equipment tracking tables (newui_equipment_types,
 *           newui_equipment, newui_equipment_log) and seeds default
 *           equipment type categories.
 * Usage:    php sql/run_equipment.php
 * Prerequisites: config.php; equipment.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS and INSERT
 *           IGNORE (backed by a UNIQUE KEY on newui_equipment_types.name).
 *           Safe to run repeatedly. If you are cleaning up an install
 *           that predates the UNIQUE-key change (2026-07-03) — where
 *           this script silently duplicated the 10 canonical types on
 *           every re-run — first run sql/run_dedupe_equipment_types.php
 *           to consolidate the existing duplicates and add the UNIQUE
 *           index, then this script becomes safe again.
 * Output:   OK/ERR per SQL statement; row counts for created tables.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();
$sql = file_get_contents(__DIR__ . '/equipment.sql');
$sql = preg_replace('/^--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$count = 0;
foreach ($statements as $stmt) {
    if (empty($stmt)) continue;
    $count++;
    try {
        $pdo->exec($stmt);
        echo "OK [{$count}]: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 80) . "...\n";
    } catch (PDOException $e) {
        echo "ERR [{$count}]: " . $e->getMessage() . "\n";
    }
}

echo "\nDone ({$count} statements). Checking tables...\n";
foreach (['newui_equipment_types', 'newui_equipment', 'newui_equipment_log'] as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        echo "{$t}: {$c} rows\n";
    } catch (PDOException $e) {
        echo "{$t}: MISSING\n";
    }
}
