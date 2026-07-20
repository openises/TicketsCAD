<?php
/**
 * Run Vehicles — Create vehicle management tables and seed types.
 *
 * Purpose:  Executes vehicles.sql to create newui_vehicle_types and
 *           newui_vehicles tables. Seeds default vehicle type categories.
 * Usage:    php sql/run_vehicles.php
 * Prerequisites: config.php; vehicles.sql in same directory.
 * Safety:   Idempotent. SQL uses CREATE TABLE IF NOT EXISTS and INSERT IGNORE.
 *           Safe to run repeatedly.
 * Output:   OK/ERR per SQL statement; row counts for created tables.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// Remove SQL comments and split on semicolons
$sql = file_get_contents(__DIR__ . '/vehicles.sql');
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
    $c1 = $pdo->query('SELECT COUNT(*) FROM newui_vehicle_types')->fetchColumn();
    echo "newui_vehicle_types: {$c1} rows\n";
} catch (PDOException $e) {
    echo "newui_vehicle_types: MISSING - " . $e->getMessage() . "\n";
}
try {
    $c2 = $pdo->query('SELECT COUNT(*) FROM newui_vehicles')->fetchColumn();
    echo "newui_vehicles: {$c2} rows\n";
} catch (PDOException $e) {
    echo "newui_vehicles: MISSING - " . $e->getMessage() . "\n";
}
