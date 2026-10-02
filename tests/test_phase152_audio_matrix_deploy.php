<?php
/**
 * Phase 152 (Console rebuild) — deployment plumbing for the audio-matrix
 * service: services/audio-matrix/configure-php-settings.php, the ONE piece
 * of this phase's infrastructure closeout that is real PHP application
 * logic (the .service unit and install.sh are host-provisioning shell/
 * systemd artifacts with nothing this suite's PHP harness can meaningfully
 * exercise; they're covered instead by tests/test_sbom_installer_coverage.php
 * -- which auto-discovers every *.sh file and checks its apt/pip installs
 * against the SBOM -- and by live verification on the two deployed hosts,
 * recorded in specs/phase-152-comms-console-v2/tasks.md).
 *
 * Drives the REAL script via a CLI subprocess against a throwaway JSON
 * conf file and the real `settings` table (never a hand-seeded row
 * standing in for what the script produces) -- proves: a fresh sync
 * writes both settings rows correctly derived from the conf file's
 * control_port/control_token; a re-run with a CHANGED token updates
 * rather than duplicating (ON DUPLICATE KEY UPDATE, matching sql/run_
 * phase24_settings_unique_name.php's own established pattern); the
 * verification step genuinely reads back what it just wrote, not merely
 * assumes success; and a malformed/missing conf file is refused (exit
 * non-zero) rather than silently doing nothing -- the same "a script that
 * swallows its own failure and exits 0 is a script that never ran" lesson
 * this project applies to every migration, applied here to a one-off
 * host-configuration tool.
 *
 * Usage: php tests/test_phase152_audio_matrix_deploy.php
 */

require_once __DIR__ . '/../config.php';
require_once (file_exists(__DIR__ . '/../inc/db.inc.php') ? __DIR__ . '/../inc/db.inc.php' : __DIR__ . '/../inc/db.php');

$passed = 0;
$failed = 0;

function test($label, $condition, $hint = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $label\n";
        $passed++;
    } else {
        echo "[FAIL] $label" . ($hint !== '' ? " — $hint" : '') . "\n";
        $failed++;
    }
}

echo "=== Phase 152 -- audio-matrix deployment plumbing ===\n\n";

$root      = defined('NEWUI_ROOT') ? NEWUI_ROOT : dirname(__DIR__);
$script    = $root . '/services/audio-matrix/configure-php-settings.php';
$php       = PHP_BINARY;
$prefix    = $GLOBALS['db_prefix'] ?? '';

test('configure-php-settings.php exists', is_file($script));
test('the .service unit ships (not just an .example)',
    is_file($root . '/services/audio-matrix/ticketscad-audio-matrix.service'));
test('install.sh exists and is a real installer, not a stub',
    is_file($root . '/services/audio-matrix/install.sh')
    && strlen((string) @file_get_contents($root . '/services/audio-matrix/install.sh')) > 500);

function wehacm_run(string $php, string $script, string $confPath): array {
    $cmd = [$php, $script, $confPath];
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descriptors, $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out, $err];
}

function wehacm_setting(string $prefix, string $name) {
    return db_fetch_value("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$name]);
}

// Clean slate so a re-run of this test is meaningful, not coasting on a
// previous run's leftover rows.
db_query("DELETE FROM `{$prefix}settings` WHERE name IN ('matrix_control_url', 'matrix_control_token')");

$tmpConf = tempnam(sys_get_temp_dir(), 'phase152_amx_conf_');
$tokenA = bin2hex(random_bytes(16));
file_put_contents($tmpConf, json_encode([
    'db_host' => 'localhost', 'db_user' => 'x', 'db_pass' => 'x', 'db_name' => 'x',
    'control_port' => 19092, 'control_token' => $tokenA,
]));

echo "-- Fresh sync --\n";
[$code, $out, $err] = wehacm_run($php, $script, $tmpConf);
test('exits 0 on a valid conf file', $code === 0, "exit=$code stderr=$err");
test('reports both settings synced', strpos($out, 'matrix_control_url') !== false
    && strpos($out, 'matrix_control_token') !== false);
test('matrix_control_url derived from control_port (loopback, that exact port)',
    wehacm_setting($prefix, 'matrix_control_url') === 'http://127.0.0.1:19092');
test('matrix_control_token matches the conf file verbatim',
    wehacm_setting($prefix, 'matrix_control_token') === $tokenA);

echo "\n-- Re-run with a CHANGED token updates rather than duplicating --\n";
$tokenB = bin2hex(random_bytes(16));
file_put_contents($tmpConf, json_encode([
    'db_host' => 'localhost', 'db_user' => 'x', 'db_pass' => 'x', 'db_name' => 'x',
    'control_port' => 19093, 'control_token' => $tokenB,
]));
[$code2] = wehacm_run($php, $script, $tmpConf);
test('second run exits 0', $code2 === 0);
test('matrix_control_url reflects the NEW port (updated in place)',
    wehacm_setting($prefix, 'matrix_control_url') === 'http://127.0.0.1:19093');
test('matrix_control_token reflects the NEW token (updated in place, not appended)',
    wehacm_setting($prefix, 'matrix_control_token') === $tokenB);
$rowCount = db_fetch_value(
    "SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = 'matrix_control_url'"
);
test('exactly one row exists for matrix_control_url -- no duplicate from the re-run',
    (int) $rowCount === 1);

echo "\n-- A malformed conf file is refused, not silently no-op'd --\n";
$badConf = tempnam(sys_get_temp_dir(), 'phase152_amx_bad_');
file_put_contents($badConf, 'not json');
[$code3] = wehacm_run($php, $script, $badConf);
test('a non-JSON conf file exits non-zero', $code3 !== 0);

$missingTokenConf = tempnam(sys_get_temp_dir(), 'phase152_amx_missing_');
file_put_contents($missingTokenConf, json_encode(['control_port' => 18092]));
[$code4] = wehacm_run($php, $script, $missingTokenConf);
test('a conf file with no control_token exits non-zero', $code4 !== 0);

$missingFileConf = sys_get_temp_dir() . '/phase152_amx_does_not_exist_' . bin2hex(random_bytes(4)) . '.conf';
[$code5] = wehacm_run($php, $script, $missingFileConf);
test('a missing conf file exits non-zero', $code5 !== 0);

// Cleanup
db_query("DELETE FROM `{$prefix}settings` WHERE name IN ('matrix_control_url', 'matrix_control_token')");
@unlink($tmpConf);
@unlink($badConf);
@unlink($missingTokenConf);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
