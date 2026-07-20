<?php
/**
 * Check Constituent (v2) — Search newui tables for constituent/contact tables.
 *
 * Purpose:  Lists tables in the newui database matching "const" or "contact",
 *           then inspects constituents.php and api/constituents.php for table refs.
 * Usage:    php sql/check_constituent2.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Matching table names, include paths from constituents.php, and
 *           table references from the API endpoint.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

// The constituent table is likely in the newui db (since constituents.php uses the newui config)
// Let's search all tables in our database
echo "=== Tables in newui database ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN, 0);
foreach ($tables as $t) {
    if (strpos(strtolower($t), 'const') !== false || strpos(strtolower($t), 'contact') !== false) {
        echo "  * {$t}\n";
    }
}

// Check constituents.php to see what DB it connects to
echo "\n=== Checking constituents.php config ===\n";
$file = file_get_contents(__DIR__ . '/../constituents.php');
// Look for require/include of config or db
if (preg_match_all('/require.*?[\'"]([^"\']+)[\'"]/', $file, $m)) {
    foreach ($m[1] as $inc) echo "  Includes: {$inc}\n";
}

// Check the API endpoint
echo "\n=== Checking api/constituents.php ===\n";
$apiFile = @file_get_contents(__DIR__ . '/../api/constituents.php');
if ($apiFile) {
    // Look for table references
    if (preg_match_all('/(?:FROM|INTO|UPDATE)\s+[`]?(\w+)[`]?/i', $apiFile, $m)) {
        echo "  Table references: " . implode(', ', array_unique($m[1])) . "\n";
    }
} else {
    echo "  api/constituents.php not found\n";
}
