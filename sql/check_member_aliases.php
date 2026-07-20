<?php
/**
 * Check Member Aliases — Verify key member columns and member_status table.
 *
 * Purpose:  Shows type/null/default info for specific member columns (type_id,
 *           status_id, team_id, name fields, etc.) and dumps member_status rows.
 * Usage:    php sql/check_member_aliases.php
 * Prerequisites: config.php; member and member_status tables.
 * Safety:   Read-only. Safe to run multiple times — makes no schema changes.
 * Output:   Column definitions for target fields, member_status schema and data.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';
$pdo = db();
$cols = $pdo->query("SHOW COLUMNS FROM member")->fetchAll(PDO::FETCH_ASSOC);
$targets = ['member_type_id','member_status_id','team_id','available','first_name','last_name','callsign','email','phone'];
foreach ($cols as $c) {
    if (in_array($c['Field'], $targets)) {
        echo $c['Field'] . ' | ' . $c['Type'] . ' | Extra:' . $c['Extra'] . PHP_EOL;
    }
}
// Also check member_status for name vs status_val
echo PHP_EOL . "=== member_status columns ===" . PHP_EOL;
$ms = $pdo->query("SHOW COLUMNS FROM member_status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ms as $c) echo "  " . $c['Field'] . ' | ' . $c['Type'] . PHP_EOL;
$rows = $pdo->query("SELECT * FROM member_status")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) { print_r($r); }
