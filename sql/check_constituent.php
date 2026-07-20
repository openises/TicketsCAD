<?php
/**
 * Check Constituent — Locate and inspect the constituent table across databases.
 *
 * Purpose:  Searches tickets, ticketscad, and newui databases for a "constituent"
 *           table. Displays its columns, row count, and a sample row.
 * Usage:    php sql/check_constituent.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Database location, full column listing, row count, and sample data.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

// Find it in any database — use config.php credentials, not hardcoded root
echo "=== Searching for constituent table ===\n";
try {
    $legPdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    $results = $legPdo->query("SELECT TABLE_SCHEMA FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'constituent'")->fetchAll();
    foreach ($results as $r) {
        echo "Found in database: {$r['TABLE_SCHEMA']}\n";
    }
} catch (Exception $e) {
    echo "Search failed: " . $e->getMessage() . "\n";
}

echo "\n=== Constituent Table ===\n";
// Try the tickets database
$dbNames = ['tickets', 'ticketscad', 'newui'];
$foundDb = null;
foreach ($dbNames as $dbName) {
    try {
        $testPdo = new PDO("mysql:host={$db_host};dbname={$dbName};charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $testPdo->query("SELECT 1 FROM constituent LIMIT 1");
        $foundDb = $dbName;
        $pdo = $testPdo;
        echo "Found constituent in database: {$dbName}\n\n";
        break;
    } catch (Exception $e) {
        // not in this db
    }
}

if (!$foundDb) {
    echo "Constituent table not found in tickets/ticketscad/newui databases.\n";
    echo "Trying current database config...\n";
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM constituent")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  " . $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . "\n";
    }
    $count = $pdo->query("SELECT COUNT(*) FROM constituent")->fetchColumn();
    echo "\nRows: {$count}\n";

    $row = $pdo->query("SELECT * FROM constituent LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "\nSample row:\n";
        foreach ($row as $k => $v) echo "  $k = " . ($v ?? 'NULL') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
