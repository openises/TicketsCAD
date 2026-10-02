<?php
/**
 * GH#152 (d3xter, 2026-09-28) — OwnTracks's real published monitoring
 * scale (https://owntracks.org/booklet/tech/json/) is -1=Quiet, 0=Manual,
 * 1=Significant, 2=Move. api/owntracks-config.php had the whole scale
 * shifted up by one (0=Quiet..3=Move, a value OwnTracks has never had)
 * since it was written — every value this app ever pushed to a real
 * OwnTracks device meant something different than the comment next to it
 * claimed, including the two that matter most operationally: the
 * low-battery off-duty baseline was pushing literal value 2 (which
 * OwnTracks reads as continuous-GPS "Move" — the opposite of low-battery),
 * and the incident-active "Move" escalation was pushing 3, a value outside
 * OwnTracks's own range.
 *
 * Covers:
 *   1. The corrected hardcoded values in _ot_build_layered_config()
 *      (both layers) and inc/unit_owntracks.php's own unit-device config.
 *   2. The corrected UI options map in _ot_tunable_keys().
 *   3. The one-time data migration that remaps any ALREADY-STORED
 *      old-scale value (global default or per-member override) — driven
 *      against the real script via a throwaway fixture, not a
 *      reimplementation of its logic.
 *
 * @requires-db
 * Usage: php tests/test_gh152_owntracks_monitoring_scale.php
 */
require_once __DIR__ . '/../config.php';
define('OT_CONFIG_LIBRARY_ONLY', true);
// CLI has no REQUEST_METHOD; owntracks-config.php reads it unconditionally
// at its own top level (a pre-existing, unrelated gap — not part of this
// fix) before the library-only guard skips the rest of the file.
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
require_once __DIR__ . '/../api/owntracks-config.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#152 -- OwnTracks monitoring scale ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Corrected UI options map ──
$keys = _ot_tunable_keys();
$monOptions = $keys['monitoring']['options'] ?? [];
t('options map has Quiet at -1 (OwnTracks real value, not 0)', ($monOptions[-1] ?? null) === 'Quiet (-1)');
t('options map has Manual at 0', ($monOptions[0] ?? null) === 'Manual (0)');
t('options map has Significant at 1', ($monOptions[1] ?? null) === 'Significant (1)');
t('options map has Move at 2', ($monOptions[2] ?? null) === 'Move (2)');
t('no stray value 3 (OwnTracks has never had one) remains in the options map',
    !array_key_exists(3, $monOptions));

// ── 2. Corrected hardcoded baseline + Layer D (no DB side effects needed —
//      build a config for a member with no DB row at all; Layer A always
//      applies regardless) ──
$cfg = _ot_build_layered_config(0, 'gh152-test-user', 'secret', 'https://cad.example.invalid');
t('Layer A baseline uses the real Significant value (1), not the old wrong 2',
    ($cfg['monitoring'] ?? null) === 1, 'got ' . var_export($cfg['monitoring'] ?? null, true));

// ── 3. inc/unit_owntracks.php's own vehicle-device config ──
$unitSrc = (string) @file_get_contents(__DIR__ . '/../inc/unit_owntracks.php');
t('unit device config pushes the real Move value (2), not the old wrong 1',
    (bool) preg_match("/'monitoring'\s*=>\s*2,/", $unitSrc));

// ── 4. The Layer D incident-active override, structurally (member-level
//      DB fixture for a real escalation path is covered elsewhere —
//      test_comm_identifiers_phase46.php already drives this exact line;
//      re-confirming the VALUE here is what GH#152 actually changed) ──
$otCfgSrc = (string) @file_get_contents(__DIR__ . '/../api/owntracks-config.php');
t('Layer D escalates to the real Move value (2), not the old wrong 3',
    (bool) preg_match("/_ot_member_has_active_incident\\(\\\$memberId\\)\\)\\s*\\{\\s*\n\\s*\\\$cfg\\['monitoring'\\]\\s*=\\s*2;/", $otCfgSrc));

// ── 5. The one-time remap migration, driven for real ──
$migPath = __DIR__ . '/../sql/run_gh152_owntracks_monitoring_remap.php';
t('the remap migration file exists', is_file($migPath));

$php = PHP_BINARY ?: 'php';
function gh152_run_migration(string $php, string $migPath): array {
    $cmd = [$php, $migPath];
    $outFile = tempnam(sys_get_temp_dir(), 'gh152o');
    $errFile = tempnam(sys_get_temp_dir(), 'gh152e');
    $proc = proc_open($cmd, [1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    $code = proc_close($proc);
    $out = (string) file_get_contents($outFile);
    @unlink($outFile); @unlink($errFile);
    return ['out' => $out, 'code' => $code];
}

// Fixture: a real member row (created fresh, not reusing an existing
// account) carrying an old-scale override, plus an old-scale global
// default — both inside the migration's valid 0-3 range.
//
// GH#152 CI fix (2026-10-01): the cleanup here used to unconditionally
// DELETE owntracks_monitoring_scale_fixed -- the real migration's own
// one-time completion marker, not fixture data this test created. On a
// fresh install the real migration (run during the earlier "Fresh
// install" step) had already set that marker to '1'; this test then
// deleted it as "cleanup", and because this file sorts alphabetically
// before test_migration_upgrade.php, THAT test's own snapshot-then-
// re-run-everything check saw the marker missing, re-ran the real
// migration a second time (correctly, since the marker really was
// gone), and flagged the resulting +1 settings row as drift -- a false
// "migrations are not idempotent" failure caused entirely by this test
// deleting state it does not own. Save and restore the REAL prior
// value of both keys instead of deleting either unconditionally.
$memberId = null;
$priorDefaultVal = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'owntracks_default_monitoring'");
$priorMarkerVal = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'owntracks_monitoring_scale_fixed'");
register_shutdown_function(function () use (&$memberId, $prefix, $priorDefaultVal, $priorMarkerVal) {
    if ($memberId) { try { db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$memberId]); } catch (Exception $e) {} }
    try {
        if ($priorDefaultVal === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE name = 'owntracks_default_monitoring'");
        } else {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('owntracks_default_monitoring', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$priorDefaultVal]);
        }
        if ($priorMarkerVal === false) {
            db_query("DELETE FROM `{$prefix}settings` WHERE name = 'owntracks_monitoring_scale_fixed'");
        } else {
            db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('owntracks_monitoring_scale_fixed', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$priorMarkerVal]);
        }
    } catch (Exception $e) {}
});

try {
    // Clear BOTH the default value and the completion marker so the
    // migration subprocess below genuinely attempts its work rather than
    // skipping -- the real prior values of both are already captured
    // above and will be put back exactly as found, whatever they were.
    db_query("DELETE FROM `{$prefix}settings` WHERE name IN ('owntracks_default_monitoring', 'owntracks_monitoring_scale_fixed')");
    db_query(
        "INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES ('owntracks_default_monitoring', '2')"
    );
    db_query("INSERT INTO `{$prefix}member` (`user_id`) VALUES (0)");
    $memberId = (int) db_insert_id();
    db_query(
        "UPDATE `{$prefix}member` SET `owntracks_overrides` = ? WHERE `id` = ?",
        [json_encode(['monitoring' => 3, 'locator_interval' => 45]), $memberId]
    );
    t('fixture member created with an old-scale override', $memberId > 0);
} catch (Exception $e) {
    t('fixture setup', false, $e->getMessage());
}

if ($memberId) {
    $res = gh152_run_migration($php, $migPath);
    t('migration subprocess exits 0', $res['code'] === 0, 'output: ' . $res['out']);
    t('migration reports the global default remap 2 -> 1', strpos($res['out'], '2 -> 1') !== false, $res['out']);
    t('migration reports the member override remap 3 -> 2', strpos($res['out'], "{$memberId}: monitoring override 3 -> 2") !== false, $res['out']);

    $defaultAfter = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'owntracks_default_monitoring'");
    t('global default is actually 1 in the database after the migration', $defaultAfter === '1', "got '{$defaultAfter}'");

    $overrideAfter = db_fetch_value("SELECT owntracks_overrides FROM `{$prefix}member` WHERE id = ?", [$memberId]);
    $decoded = $overrideAfter ? json_decode($overrideAfter, true) : null;
    t('member override.monitoring is actually 2 after the migration', is_array($decoded) && ($decoded['monitoring'] ?? null) === 2, "got {$overrideAfter}");
    t('the UNRELATED locator_interval key in the same override blob is untouched', is_array($decoded) && ($decoded['locator_interval'] ?? null) === 45);

    // Idempotency — a second run must NOT double-remap.
    $res2 = gh152_run_migration($php, $migPath);
    t('second run is a clean no-op (SKIP, already applied)', strpos($res2['out'], 'already applied') !== false, $res2['out']);
    $defaultAfter2 = db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = 'owntracks_default_monitoring'");
    t('global default is STILL 1 after a second run (not double-remapped to 0)', $defaultAfter2 === '1', "got '{$defaultAfter2}'");
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
