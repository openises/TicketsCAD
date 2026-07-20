<?php
/**
 * Phase 17 — screen prefs tests.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/screen-prefs.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 17 — screen prefs ===\n\n";
$pass = 0; $fail = 0;
function ok($n) { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w?" — $w":'') . "\n"; $fail++; }

// Schema
try {
    $r = db_fetch_one(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
        [$prefix . 'user_screen_prefs']
    );
    if ($r) ok('user_screen_prefs table exists');
    else    bad('user_screen_prefs missing');
} catch (Exception $e) { bad('schema: ' . $e->getMessage()); }

// Defaults
$d = prefs_screen_defaults();
if (isset($d['units']) && !empty($d['units']['columns'])) ok('units default catalog present');
else                                                     bad('units defaults missing');

$unitsCols = $d['units']['columns'];
$ids = array_column($unitsCols, 'id');
foreach (['name','handle','type','status','active','updated','par_last_checkin','par_next_due'] as $needed) {
    if (in_array($needed, $ids, true)) ok("units catalog contains '{$needed}'");
    else                                bad("units catalog missing '{$needed}'");
}

// Phase 17 default visibility: PAR columns OFF by default
foreach ($unitsCols as $c) {
    if ($c['id'] === 'par_last_checkin' && empty($c['visible'])) ok("par_last_checkin default-hidden");
    if ($c['id'] === 'par_next_due'     && empty($c['visible'])) ok("par_next_due default-hidden");
    if ($c['id'] === 'name'             && !empty($c['visible'])) ok("name default-visible");
}

// Test user; clean prior state
$testUid = 999999;
prefs_reset($testUid, 'units');

// Get initial → should match defaults
$p = prefs_get($testUid, 'units');
if (!empty($p['columns']) && count($p['columns']) === count($unitsCols)) {
    ok('prefs_get returns merged default catalog when no overrides');
} else {
    bad('prefs_get initial', count($p['columns'] ?? []));
}

// Save an override: hide 'handle', show 'par_next_due'
$override = [
    'columns' => [
        ['id' => 'name',             'visible' => true,  'pos' => 0],
        ['id' => 'handle',           'visible' => false, 'pos' => 1],
        ['id' => 'type',             'visible' => true,  'pos' => 2],
        ['id' => 'status',           'visible' => true,  'pos' => 3],
        ['id' => 'active',           'visible' => true,  'pos' => 4],
        ['id' => 'updated',          'visible' => true,  'pos' => 5],
        ['id' => 'par_last_checkin', 'visible' => false, 'pos' => 6],
        ['id' => 'par_next_due',     'visible' => true,  'pos' => 7],
    ],
    'sort' => ['col' => 'status', 'dir' => 'desc'],
];
if (prefs_set($testUid, 'units', $override)) ok('prefs_set returned true');
else                                          bad('prefs_set failed');

$p = prefs_get($testUid, 'units');
$byId = [];
foreach ($p['columns'] as $c) $byId[$c['id']] = $c;
if (!empty($byId['handle']) && $byId['handle']['visible'] === false) ok('override: handle hidden');
else                                                                 bad('handle override', var_export($byId['handle'] ?? null, true));
if (!empty($byId['par_next_due']) && $byId['par_next_due']['visible'] === true) ok('override: par_next_due shown');
else                                                                            bad('par_next_due override');
if ($p['sort']['col'] === 'status' && $p['sort']['dir'] === 'desc') ok('sort override persisted');
else                                                                bad('sort override', var_export($p['sort'], true));

// Reset
if (prefs_reset($testUid, 'units')) ok('prefs_reset returned true');
$p = prefs_get($testUid, 'units');
$h = null;
foreach ($p['columns'] as $c) if ($c['id'] === 'handle') $h = $c;
if ($h && $h['visible'] === true) ok('after reset, handle visibility back to default');
else                              bad('reset incomplete', var_export($h, true));

echo "\n===========================================\n";
echo "Phase 17 screen prefs: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";
if ($fail > 0) exit(1);
