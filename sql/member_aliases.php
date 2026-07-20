<?php
/**
 * Add NewUI-compatible alias columns to the legacy member table.
 *
 * The legacy table uses field1/field2/field4 etc.
 * The NewUI APIs expect first_name/last_name/callsign.
 *
 * This migration adds GENERATED (virtual) columns that alias the legacy fields.
 * Virtual columns don't take disk space — they read from the underlying field.
 *
 * Usage: php sql/member_aliases.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$pdo = db();

// Check if we need to do anything
$cols = $pdo->query("SHOW COLUMNS FROM member")->fetchAll(PDO::FETCH_COLUMN, 0);
if (in_array('first_name', $cols)) {
    echo "Member table already has first_name column — no migration needed.\n";
    exit(0);
}

if (!in_array('field1', $cols)) {
    echo "Member table doesn't have field1 — unknown schema. Aborting.\n";
    exit(1);
}

echo "Adding virtual alias columns to legacy member table...\n";

$aliases = [
    ['first_name',      'field2', 'VARCHAR(28)'],
    ['last_name',       'field1', 'VARCHAR(28)'],
    ['callsign',        'field4', 'VARCHAR(16)'],
    ['email',           'field6', 'VARCHAR(48)'],
    ['phone',           'field7', 'BIGINT'],
];

foreach ($aliases as $a) {
    $alias = $a[0];
    $source = $a[1];
    $type = $a[2];

    if (in_array($alias, $cols)) {
        echo "  {$alias} already exists — skipping.\n";
        continue;
    }

    try {
        $pdo->exec("ALTER TABLE `member` ADD COLUMN `{$alias}` {$type} GENERATED ALWAYS AS (`{$source}`) VIRTUAL");
        echo "  Added: {$alias} → {$source}\n";
    } catch (Exception $e) {
        echo "  Failed {$alias}: " . $e->getMessage() . "\n";
    }
}

// Verify
echo "\nVerification:\n";
$rows = $pdo->query("SELECT id, first_name, last_name, callsign FROM member LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "  [{$r['id']}] {$r['first_name']} {$r['last_name']} ({$r['callsign']})\n";
}

echo "\nDone!\n";
