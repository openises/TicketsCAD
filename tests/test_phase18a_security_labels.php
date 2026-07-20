<?php
/**
 * Phase 18a — security labels engine tests.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/security-labels.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 18a — security labels ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// Schema
foreach (['security_labels', 'pending_routed_messages'] as $t) {
    $r = db_fetch_one("SELECT TABLE_NAME FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                      [$prefix . $t]);
    if ($r) ok("table {$t} exists");
    else    bad("table {$t} missing");
}
foreach (['security_label_override_id','security_set_by','security_set_at','security_reason'] as $col) {
    $r = db_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                      [$prefix . 'ticket', $col]);
    if ($r) ok("ticket.{$col} present");
    else    bad("ticket.{$col} missing");
}
$r = db_fetch_one("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                  [$prefix . 'in_types', 'default_security_label_id']);
if ($r) ok('in_types.default_security_label_id present');
else    bad('in_types.default_security_label_id missing');

// Seed labels
$all = seclabel_get_all();
if (count($all) >= 3) ok("seed labels present (" . count($all) . " rows)");
else                  bad("expected ≥3 seed labels, got " . count($all));

$std = seclabel_get_by_code('standard');
if ($std && (int)$std['is_default'] === 1) ok("'standard' is the default");
else                                       bad("'standard' should be default");

$conf = seclabel_get_by_code('confidential');
if ($conf && (int)$conf['routing_send_delay_secs'] === 60) ok("'confidential' has 60s send_delay");
else                                                       bad("'confidential' send_delay wrong");
if ($conf && (int)$conf['eoc_show_scope'] === 0) ok("'confidential' hides EOC scope");
else                                              bad("'confidential' should hide scope");

// Default resolution
$d = seclabel_default();
if ($d && $d['code'] === 'standard') ok('default() returns Standard');
else                                  bad('default()', var_export($d, true));

// Resolution priority — pick a ticket
$tid = (int) db_fetch_value("SELECT id FROM `{$prefix}ticket` ORDER BY id ASC LIMIT 1");
if ($tid > 0) {
    // Without override
    db_query("UPDATE `{$prefix}ticket` SET security_label_override_id = NULL WHERE id = ?", [$tid]);
    $r = seclabel_resolve($tid);
    if (in_array($r['_resolved_from'], ['incident_type', 'system_default'], true)) {
        ok("resolve(): falls back to type/system default ({$r['_resolved_from']})");
    } else {
        bad('resolve no-override fallback', $r['_resolved_from']);
    }

    // With override
    $confId = (int) $conf['id'];
    $apply = seclabel_apply_override($tid, $confId, 'unit test', 1);
    if (!empty($apply['ok'])) ok('apply_override: Confidential applied with reason');
    else                      bad('apply_override: ' . ($apply['error'] ?? '?'));

    $r2 = seclabel_resolve($tid);
    if ($r2['_resolved_from'] === 'incident_override' && (int)$r2['id'] === $confId) {
        ok('resolve(): override wins');
    } else {
        bad('resolve override', var_export($r2, true));
    }

    // Required reason check
    $rApply = seclabel_apply_override($tid, $confId, '', 1);
    if (!empty($rApply['error']) && strpos($rApply['error'], 'eason') !== false) {
        ok('apply_override: empty reason rejected for Confidential');
    } else {
        bad('empty-reason should have been rejected for required-reason label');
    }

    // Clear
    seclabel_clear_override($tid, 1);
    $r3 = seclabel_resolve($tid);
    if ($r3['_resolved_from'] !== 'incident_override') ok('clear_override: revert OK');
    else                                                bad('clear_override didn\'t clear');
}

// Create / Update / Delete
$newId = seclabel_create([
    'code' => 'test_label',
    'name' => 'Unit Test Label',
    'sort_order' => 500,
    'is_default' => 0,
    'badge_bg_color' => '#888',
    'badge_text_color' => '#fff',
]);
if ($newId > 0) ok("create(): new label id={$newId}");
else            bad('create() returned 0');

$upd = seclabel_update($newId, ['name' => 'Renamed Test Label', 'routing_send_delay_secs' => 15]);
if ($upd) ok('update(): renamed + delay set');
else      bad('update returned false');

$check = seclabel_get($newId);
if ($check && $check['name'] === 'Renamed Test Label' && (int)$check['routing_send_delay_secs'] === 15) {
    ok('update(): values persisted');
} else {
    bad('update values not persisted', var_export($check, true));
}

$del = seclabel_delete($newId);
if (!empty($del['ok'])) ok('delete(): unused label deleted');
else                     bad('delete error: ' . ($del['error'] ?? '?'));

// Refuse to delete default
$delDef = seclabel_delete((int) seclabel_default()['id']);
if (!empty($delDef['error']) && strpos($delDef['error'], 'default') !== false) {
    ok('delete(): refuses to delete the default label');
} else {
    bad('delete(): should have refused default', var_export($delDef, true));
}

// RBAC permissions seeded
foreach (['action.set_incident_security','action.kill_pending_message',
          'action.recall_routed_message','action.manage_security_labels',
          'screen.view_eoc_display'] as $code) {
    $r = db_fetch_value(
        "SELECT 1 FROM `{$prefix}permissions` WHERE code = ? LIMIT 1", [$code]);
    if ($r) ok("permission {$code} seeded");
    else    bad("permission {$code} missing");
}

echo "\n===========================================\n";
echo "Phase 18a security labels: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
