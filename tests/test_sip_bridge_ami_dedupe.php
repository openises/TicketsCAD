<?php
/**
 * Phase 155, GH#108 slice S4 -- the Asterisk AMI bridge reports one ringing
 * event per CALL (keyed by Linkedid), not one per channel.
 *
 * Runs services/sip-bridge/tests/test_ami_tracker.py (the replay of
 * multi-leg AMI event sequences and a wire-format run against a fake AMI
 * socket) under the system Python, so the PHP suite and the local pre-commit
 * path cover it too; CI also runs the Python file directly in its bridge step.
 * SKIPs cleanly without a working Python 3 or the bridge's `requests`
 * dependency.
 *
 * The event sequences are SYNTHETIC (written from the Asterisk AMI
 * documentation); see the docstring of the Python file for what that does and
 * does not prove.
 *
 * Usage: php tests/test_sip_bridge_ami_dedupe.php
 */
require_once __DIR__ . '/_test_node_probe.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 155 / S4 -- AMI bridge: one ringing per call (Linkedid) ===\n\n";

$root = dirname(__DIR__);
$bridge = (string) file_get_contents($root . '/services/sip-bridge/bridge.py');
t('bridge.py defines AmiCallTracker and run_ami_bridge uses it',
    strpos($bridge, 'class AmiCallTracker') !== false && strpos($bridge, 'tracker = AmiCallTracker(') !== false);
t('the old per-Uniqueid "seen_ringing" forwarding is gone', strpos($bridge, 'seen_ringing') === false);
t('the old global AMI_FILTER_CONTEXT (set only by a CLI flag, ignored in bridge.ini) is gone',
    strpos($bridge, 'AMI_FILTER_CONTEXT') === false);
t('ami_context is a real configuration key', strpos($bridge, '"ami_context"') !== false);
$ci = (string) file_get_contents($root . '/.github/workflows/qa.yml');
t('CI runs test_ami_tracker in the bridge step', strpos($ci, 'test_ami_tracker') !== false);

$python = test_probe_cli(['python3', 'python', 'py'], '/^Python 3\.\d+/');
if ($python === null) {
    echo "SKIP: no Python 3 interpreter found -- the replay checks were not run (CI runs them)\n";
} else {
    $dep = test_run_cli([$python, '-c', 'import requests; print("deps-ok")']);
    if (!is_string($dep) || strpos($dep, 'deps-ok') === false) {
        echo "SKIP: the bridge's `requests` dependency is not installed for $python -- the replay checks were not run (CI runs them)\n";
    } else {
        $out = (string) test_run_cli([$python, $root . '/services/sip-bridge/tests/test_ami_tracker.py']);
        $ran = preg_match('/Ran (\d+) tests?/', $out, $m) ? (int) $m[1] : 0;
        t('the Python suite ran a realistic number of tests (' . $ran . ')', $ran >= 16);
        t('...and every one passed', (bool) preg_match('/^OK\s*$/m', $out) && strpos($out, 'FAILED') === false, substr($out, -400));
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
