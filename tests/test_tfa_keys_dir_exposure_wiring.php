<?php
/**
 * TFA keys-directory exposure warning wiring (2026-08-20).
 *
 * Found by tools/dead_control_audit.php's new check (d) ("dead API
 * response key"): api/tfa-key.php's GET action has computed
 * `keys_dir_exposed` / `keys_dir_note` / `keys_dir` via
 * served_dir_exposure(FE_KEYS_DIR) since the endpoint shipped — the same
 * web-exposure check this project's GHSA advisory history
 * (docs/security/advisory-2026-08-*.md) is built around — but nothing in
 * settings.php's TFA Key Management panel ever rendered it. An
 * administrator whose keys directory had drifted onto a served path had
 * no way to see it from this panel.
 *
 * This is a source-grep verification, the same technique
 * tests/test_interval_report_wiring.php and
 * tests/test_incident_summary_breakdown_wiring.php already use for this
 * class of bug — this codebase has no session-login HTTP harness to
 * drive settings.php end-to-end. Live confirmation happened separately
 * via the Browser pane against a local dev instance.
 *
 * Usage: php tests/test_tfa_keys_dir_exposure_wiring.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== TFA keys-dir exposure warning wiring verification ===\n\n";

$base = realpath(__DIR__ . '/..');
function _src(string $rel): string {
    global $base;
    $p = $base . '/' . $rel;
    if (!is_file($p)) { echo "MISSING FILE: $rel\n"; return ''; }
    return file_get_contents($p);
}

echo "--- api/tfa-key.php (unchanged by this fix — sanity check the shape) ---\n";
$api = _src('api/tfa-key.php');
t('computes exposure via served_dir_exposure(FE_KEYS_DIR)', strpos($api, 'served_dir_exposure($keysDir)') !== false);
t(
    'GET response carries keys_dir / keys_dir_exposed / keys_dir_note',
    strpos($api, "'keys_dir'       => \$keysDir,") !== false
    && strpos($api, "'keys_dir_exposed' =>") !== false
    && strpos($api, "'keys_dir_note'  =>") !== false
);

echo "\n--- settings.php ---\n";
$page = _src('settings.php');
t('has the keys-dir exposure warning container', strpos($page, 'id="tfaKeysDirExposureBox"') !== false);
t('the container sits inside the TFA Key Management group', (bool) preg_match(
    '/tfaKeyWarningBox.*?tfaKeysDirExposureBox/s',
    $page
));
t('JS reads data.keys_dir_exposed', strpos($page, 'data.keys_dir_exposed') !== false);
t('JS reads data.keys_dir_note', strpos($page, 'data.keys_dir_note') !== false);
t('JS reads data.keys_dir', strpos($page, 'data.keys_dir ') !== false || strpos($page, "data.keys_dir)") !== false || strpos($page, "data.keys_dir '") !== false);
t(
    'the warning is built with textContent/createTextNode, not raw innerHTML interpolation of server data',
    strpos($page, 'alertDiv.appendChild(document.createTextNode(noteText))') !== false
);
t(
    'exposureBox is reset (innerHTML = \'\') before conditionally appending — no stale warning survives a clean re-check',
    (bool) preg_match('/exposureBox\.innerHTML\s*=\s*\'\';\s*\n\s*if\s*\(data\.keys_dir_exposed\)/', $page)
);

echo "\n--- tools/dead_control_audit.php check (d) ---\n";
$dcaOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/tools/dead_control_audit.php') . ' --api-only 2>&1');
$dcaOut = (string) $dcaOut;
foreach (['keys_dir_exposed', 'keys_dir_note', 'keys_dir'] as $k) {
    t("$k is no longer reported as a NEW dead API key", strpos($dcaOut, "[NEW]      apikey:$k") === false);
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
