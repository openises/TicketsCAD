<?php
/**
 * Phase 149 Milestone 5 — spec.md FR-20: Answer never navigates the
 * current tab away from unsaved work; the New Incident prefill only
 * ever opens in a NEW tab.
 *
 * Two complementary checks:
 *   1. assets/js/call-alert.js's claim/reassign success handlers use
 *      window.open(), never window.location — driven under node against
 *      the REAL file (same discipline as test_call_alert_keyboard.php).
 *   2. assets/js/call-prefill.js never mutates window.location either —
 *      its whole job is filling #phone + firing a synthetic blur, not
 *      navigating.
 *
 * @requires-node
 * Usage: php tests/test_call_prefill_new_tab.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 5 — Answer never navigates the current tab (FR-20) ===\n\n";

$base = realpath(__DIR__ . '/..');

// ── Static source checks (no node needed) ───────────────────────────
$alertSrc = (string) file_get_contents($base . '/assets/js/call-alert.js');
t('call-alert.js never assigns window.location (would navigate the CURRENT tab away)',
    !preg_match('/window\.location\s*=/', $alertSrc));
t('call-alert.js opens the New Incident prefill via window.open()', strpos($alertSrc, "window.open('new-incident.php?call_id=") !== false);

$prefillSrc = (string) file_get_contents($base . '/assets/js/call-prefill.js');
t('call-prefill.js never assigns window.location', !preg_match('/window\.location\s*=/', $prefillSrc));
t('call-prefill.js never calls window.open() itself (that is call-alert.js\'s job, only AFTER a successful claim)',
    strpos($prefillSrc, 'window.open') === false);

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$caPath = str_replace('\\', '/', $base . '/assets/js/call-alert.js');

$js = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var openedUrls = [];
var navigatedTo = null;

global.window = global;
global.window.CALL_ALERT_USER_ID = 42;
global.window.CALL_ALERT_USER_NAME = 'Test Dispatcher';
global.window.CALL_ALERT_CSRF = 'test-csrf-token';
global.window.open = function (url) { openedUrls.push(url); };

// A location object whose `href`/whole-object assignment we can detect —
// proves the real code never does `window.location = ...` or
// `window.location.href = ...` at runtime, not just by source-text scan.
var locationAssigned = false;
Object.defineProperty(global.window, 'location', {
    get: function () { return { href: 'http://example.invalid/index.php', assign: function () { locationAssigned = true; } }; },
    set: function () { locationAssigned = true; }
});

var fakeContainer = { className: '', innerHTML: '', querySelectorAll: function () { return []; } };
global.document = {
    readyState: 'complete',
    addEventListener: function () {},
    getElementById: function (id) { return id === 'callAlertBanner' ? fakeContainer : null; }
};
global.sessionStorage = { getItem: function () { return null; }, setItem: function () {} };
global.fetch = function (url) {
    return Promise.resolve({ json: function () { return Promise.resolve({ success: true, call: { id: 1 } }); } });
};

eval(fs.readFileSync(process.argv[2], 'utf8'));

var CA = global.window.CallAlert;
check('CallAlert exposed on window', !!CA);

CA._upsertCall({ call_id: 7, caller_number: '+16125550007', state: 'ringing', ringing_at: '2026-08-22 10:00:00' });
CA._render();
CA._setHighlighted(7);
CA._actOnHighlighted('a');

// postAction's fetch().then() resolves as a microtask -- drain it before
// asserting on the result of claimCall()'s own .then() handler.
setTimeout(function () {
    check('claiming a ringing call opens a NEW tab via window.open()', openedUrls.length === 1 && openedUrls[0].indexOf('new-incident.php?call_id=7') === 0, JSON.stringify(openedUrls));
    check('window.location was NEVER assigned/mutated by the claim flow', locationAssigned === false);
    console.log(out.join('\n'));
}, 50);
JS;

$harness = sys_get_temp_dir() . '/tcad_callprefill_harness_' . getmypid() . '.js';
file_put_contents($harness, $js);
$raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($caPath) . ' 2>&1');
@unlink($harness);

if (!is_string($raw) || trim($raw) === '') {
    t('node harness produced output', false);
} else {
    $lines = explode("\n", trim($raw));
    foreach ($lines as $line) {
        if (strpos($line, 'PASS|') === 0 || strpos($line, 'FAIL|') === 0) {
            $parts = explode('|', $line, 3);
            t($parts[1] . (isset($parts[2]) && $parts[2] !== '' ? ' (' . $parts[2] . ')' : ''), $parts[0] === 'PASS');
        } else {
            echo "  [node] " . $line . "\n";
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
