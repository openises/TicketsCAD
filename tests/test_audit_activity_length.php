<?php
/**
 * newui_audit_log.activity is varchar(32). audit_log() catches its own
 * failures so a logging problem never breaks the action it describes -- which
 * means an activity name longer than the column is silently NEVER recorded:
 * the INSERT fails with "Data too long", the failure goes to the PHP error log,
 * and the audit trail simply has no row. Found in Phase 155 when a new audit
 * entry did not appear; two Phase 152 beacon-search names
 * (console.workstation_beacon_search / _matched, 33 and 34 characters) had
 * been in that state since they shipped.
 *
 * This gate reads every audit_log()/audit_admin() call in the application
 * source and fails on a literal category or activity longer than its column,
 * so a too-long name is caught the day it is written, not when someone asks
 * where the audit row went. The column widths are read from the live schema,
 * never hard-coded.
 *
 * @requires-db
 * Usage: php tests/test_audit_activity_length.php
 */
require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "=== audit_log() activity / category names fit their columns ===\n\n";

$widths = [];
foreach (db_fetch_all("SHOW COLUMNS FROM `{$prefix}newui_audit_log`") as $c) {
    if (in_array($c['Field'], ['category', 'activity', 'target_type'], true) && preg_match('/varchar\((\d+)\)/', $c['Type'], $m)) {
        $widths[$c['Field']] = (int) $m[1];
    }
}
t('the schema reports the three string column widths', count($widths) === 3, json_encode($widths));

$root = dirname(__DIR__);
$skip = '#^(tests|tools|specs|docs|vendor|assets|sql|services|cache|uploads|proxy)/#';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$files = [];
foreach ($it as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace(chr(92), '/', substr($f->getPathname(), strlen($root) + 1));
    if (preg_match($skip, $rel)) continue;
    $files[] = $rel;
}
$checked = 0; $bad = [];
foreach ($files as $rel) {
    $src = (string) file_get_contents($root . '/' . $rel);
    $re = '/audit_(?:log|admin)\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*[\'"]([^\'"]*)[\'"](?:\s*,\s*[\'"]([^\'"]*)[\'"])?/';
    if (!preg_match_all($re, $src, $mm, PREG_SET_ORDER)) continue;
    foreach ($mm as $m) {
        // Only calls shaped like audit_log(category, activity, ...): both are
        // identifier-like tokens. (api/aprs-license-accept.php passes a
        // sentence as the second argument -- a different defect, wrong
        // signature, reported separately -- and is not a column-width issue.)
        if (!preg_match('/^[A-Za-z0-9_.:\-]+$/', $m[1]) || !preg_match('/^[A-Za-z0-9_.:\-]+$/', $m[2])) { continue; }
        $checked++;
        if (isset($widths['category']) && strlen($m[1]) > $widths['category']) { $bad[] = "$rel: category '{$m[1]}' (" . strlen($m[1]) . ")"; }
        if (isset($widths['activity']) && strlen($m[2]) > $widths['activity']) { $bad[] = "$rel: activity '{$m[2]}' (" . strlen($m[2]) . ")"; }
        if (isset($m[3]) && $m[3] !== '' && isset($widths['target_type']) && strlen($m[3]) > $widths['target_type']) { $bad[] = "$rel: target_type '{$m[3]}' (" . strlen($m[3]) . ")"; }
    }
}
t('the scan found a realistic number of audit_log() calls with literal names (' . $checked . ')', $checked > 200);
t('no literal category / activity / target_type is longer than its column', $bad === [], implode('; ', $bad));
foreach ($bad as $b) { echo "  $b\n"; }

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
