<?php
require __DIR__ . '/../config.php';

echo "=== member_organizations columns ===\n";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `member_organizations`");
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  null={$c['Null']}  default={$c['Default']}\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== teams columns ===\n";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `teams`");
    foreach ($cols as $c) echo "  {$c['Field']}  {$c['Type']}  null={$c['Null']}\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== comm_modes data ===\n";
try {
    $modes = db_fetch_all("SELECT id, code, name, icon, fields_json FROM `comm_modes` ORDER BY sort_order");
    foreach ($modes as $m) echo "  [{$m['id']}] {$m['code']} — {$m['name']} (icon:{$m['icon']}) fields: {$m['fields_json']}\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }

echo "\n=== Test org edit: member_organizations has role column? ===\n";
try {
    $cols = db_fetch_all("SHOW COLUMNS FROM `member_organizations` WHERE Field = 'role'");
    echo count($cols) > 0 ? "YES — role column exists\n" : "NO — role column missing!\n";
} catch (Exception $e) { echo "ERROR: " . $e->getMessage() . "\n"; }
