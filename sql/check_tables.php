<?php
/**
 * Check Tables — Display member table contents and search for constituent table.
 *
 * Purpose:  Diagnostic script that shows member table row count and sample data,
 *           then searches all accessible databases for a "constituent" table.
 * Usage:    php sql/check_tables.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Member row count, sample rows, and list of databases containing
 *           a constituent table with column details and sample data.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

// Member table with legacy field names
echo "member table: " . $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn() . " rows\n";
$rows = $pdo->query('SELECT id, field1 AS last_name, field2 AS first_name, field4 AS callsign FROM member LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo "  [{$r['id']}] {$r['first_name']} {$r['last_name']} ({$r['callsign']})\n";

// Try to connect to legacy tickets database
echo "\nChecking legacy database for constituent table...\n";
try {
    $legacyPdo = new PDO("mysql:host={$db_host};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    // Find all databases with a constituent table
    $results = $legacyPdo->query("SELECT TABLE_SCHEMA, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'constituent'")->fetchAll();
    foreach ($results as $r) {
        echo "  Found in {$r['TABLE_SCHEMA']} ({$r['TABLE_ROWS']} rows)\n";
        $conCols = $legacyPdo->query("SHOW COLUMNS FROM `{$r['TABLE_SCHEMA']}`.`constituent`")->fetchAll(); // NOSONAR — TABLE_SCHEMA is from INFORMATION_SCHEMA (trusted system catalog), not user input
        foreach (array_slice($conCols, 0, 12) as $c) echo "    {$c['Field']} ({$c['Type']})\n";
        if (count($conCols) > 12) echo "    ... +" . (count($conCols) - 12) . " more cols\n";
        // Show sample
        $sample = $legacyPdo->query("SELECT * FROM `{$r['TABLE_SCHEMA']}`.`constituent` LIMIT 2")->fetchAll(); // NOSONAR — TABLE_SCHEMA is from INFORMATION_SCHEMA (trusted system catalog), not user input
        foreach ($sample as $s) {
            $show = array_slice($s, 0, 6);
            echo "    Sample: ";
            foreach ($show as $k => $v) echo "$k=" . ($v ?? 'NULL') . " | ";
            echo "\n";
        }
    }
    if (empty($results)) echo "  No constituent table found in any database.\n";
} catch (Exception $e) {
    echo "  Cannot check: " . $e->getMessage() . "\n";
}
