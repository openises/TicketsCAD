<?php
/**
 * Check Personnel Tables — Inspect schema and data for personnel-related tables.
 *
 * Purpose:  Dumps column definitions, row counts, and sample rows for the
 *           certifications, member_certifications, member_types, member_status,
 *           teams, and member tables.
 * Usage:    php sql/check_personnel_tables.php
 * Prerequisites: config.php with valid database credentials.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Per-table column listing, row count, and up to 5 sample rows.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();

$tables = ['certifications', 'member_certifications', 'member_types', 'member_status', 'teams', 'member'];
foreach ($tables as $t) {
    echo "=== {$t} ===" . PHP_EOL;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  " . $c['Field'] . ' | ' . $c['Type'] . ' | Null:' . $c['Null'] . ' | Default:' . ($c['Default'] ?? 'NONE') . PHP_EOL;
        }
        $cnt = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
        echo "Rows: {$cnt}" . PHP_EOL;
        if ($cnt > 0 && $cnt <= 20) {
            $rows = $pdo->query("SELECT * FROM `{$t}` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $parts = [];
                foreach ($r as $k => $v) $parts[] = "{$k}=" . ($v === null ? 'NULL' : (strlen($v) > 40 ? substr($v, 0, 40).'...' : $v));
                echo "  ROW: " . implode(', ', $parts) . PHP_EOL;
            }
        }
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . PHP_EOL;
    }
    echo PHP_EOL;
}
