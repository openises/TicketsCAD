<?php
/**
 * Run Major Incidents — Create major incident linking and command structure tables.
 *
 * Purpose:  Creates major_incidents, major_incident_links, and
 *           major_incident_command tables for grouping related incidents
 *           under a unified command structure.
 * Usage:    php sql/run_major_incidents.php
 * Prerequisites: config.php; major_incidents.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS. Safe to re-run.
 * Output:   OK/ERR per SQL statement.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// Remove SQL comments and split on semicolons
$sql = file_get_contents(__DIR__ . '/major_incidents.sql');
// Remove single-line comments
$sql = preg_replace('/^--.*$/m', '', $sql);
// Split on semicolons not inside quotes
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
        echo "  SQL: " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 120) . "\n";
    }
}

echo "\nDone ({$count} statements). Checking tables...\n";
try {
    $c1 = $pdo->query('SELECT COUNT(*) FROM newui_major_incidents')->fetchColumn();
    echo "newui_major_incidents: {$c1} rows\n";
} catch (PDOException $e) {
    echo "newui_major_incidents: MISSING - " . $e->getMessage() . "\n";
}
try {
    $c2 = $pdo->query('SELECT COUNT(*) FROM newui_major_incident_links')->fetchColumn();
    echo "newui_major_incident_links: {$c2} rows\n";
} catch (PDOException $e) {
    echo "newui_major_incident_links: MISSING - " . $e->getMessage() . "\n";
}
