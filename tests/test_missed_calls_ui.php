<?php
/**
 * Phase 149 Milestone 6 — the "Missed Calls" section of assets/js/
 * call-alert.js (spec.md FR-14/FR-23): an abandoned call moves into a
 * separate, persistent list instead of vanishing, offers a one-click
 * callback (reusing the same New Incident prefill Answer uses), and a
 * reviewed action that removes it from the live panel via the real
 * server action.
 *
 * Drives the REAL call-alert.js under node (same discipline as
 * test_call_alert_keyboard.php / test_call_prefill_new_tab.php).
 *
 * @requires-node
 * Usage: php tests/test_missed_calls_ui.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 6 — Missed Calls UI ===\n\n";

$base = realpath(__DIR__ . '/..');

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

var fetchCalls = [];
var openedUrls = [];

global.window = global;
global.window.CALL_ALERT_USER_ID = 42;
global.window.CALL_ALERT_USER_NAME = 'Test Dispatcher';
global.window.CALL_ALERT_CSRF = 'test-csrf-token';
global.window.open = function (url) { openedUrls.push(url); };

var fakeContainer = { className: '', innerHTML: '', querySelectorAll: function () { return []; } };
global.document = {
    readyState: 'complete',
    addEventListener: function () {},
    getElementById: function (id) { return id === 'callAlertBanner' ? fakeContainer : null; }
};
global.sessionStorage = { getItem: function () { return null; }, setItem: function () {} };
global.fetch = function (url, opts) {
    fetchCalls.push({ url: url, opts: opts });
    return Promise.resolve({ json: function () { return Promise.resolve({ success: true }); }, ok: true });
};

eval(fs.readFileSync(process.argv[2], 'utf8'));

var CA = global.window.CallAlert;
check('CallAlert exposed on window', !!CA);

// A ring that goes unanswered and abandons -- the SSE handler must move
// it into the missed-calls map, never simply drop it (FR-14).
CA._upsertCall({ call_id: 50, caller_number: '+16125550050', state: 'ringing', ringing_at: '2026-08-22 09:00:00' });
CA._render();
check('the call is visible while ringing', !!CA._calls[50]);

CA._handleTerminalEvent({ call_id: 50, state: 'abandoned', caller_number: '+16125550050', trunk_label: 'Test Trunk', ringing_at: '2026-08-22 09:00:00' });
check('an abandoned call is removed from the active calls map', !CA._calls[50]);
check('an abandoned call is added to the missed-calls map (FR-14 -- never simply vanishes)', !!CA._missedCalls[50]);
check('the missed-call entry retains the caller number', CA._missedCalls[50].caller_number === '+16125550050');

// A call that simply ENDS (not abandoned) must NOT land in missed calls.
CA._upsertCall({ call_id: 51, caller_number: '+16125550051', state: 'claimed', claimed_by: 42, ringing_at: '2026-08-22 09:01:00' });
CA._handleTerminalEvent({ call_id: 51, state: 'ended', caller_number: '+16125550051' });
check('a normally-ended (not abandoned) call does NOT land in missed calls', !CA._missedCalls[51]);

// ── One-click callback (FR-23) reuses the SAME New Incident prefill
//    mechanism Answer uses -- opens a new tab, no claim/reassign call. ──
openedUrls.length = 0;
fetchCalls.length = 0;
CA._callbackCall(50);
check('callback opens a NEW tab at new-incident.php?call_id=<id>', openedUrls.length === 1 && openedUrls[0].indexOf('new-incident.php?call_id=50') === 0, JSON.stringify(openedUrls));
check('callback does NOT call claim/reassign first -- the call is already terminal, nothing to claim', fetchCalls.length === 0);

// ── Reviewing a missed call posts to the real action=reviewed endpoint
//    and removes it from the live panel on success. ─────────────────────
fetchCalls.length = 0;
CA._reviewCall(50);
JS;

$harness = sys_get_temp_dir() . '/tcad_missedcalls_harness_' . getmypid() . '.js';
file_put_contents($harness, $js . "\nsetTimeout(function () {\n"
    . "check('reviewing posts to api/inbound-calls.php?action=reviewed', fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=reviewed') !== -1, JSON.stringify(fetchCalls));\n"
    . "check('reviewing sends the missed call\\'s id', fetchCalls.length === 1 && JSON.parse(fetchCalls[0].opts.body).id === 50, JSON.stringify(fetchCalls));\n"
    . "check('a successfully-reviewed call is removed from the missed-calls map', !CA._missedCalls[50]);\n"
    . "console.log(out.join('\\n'));\n"
    . "}, 50);\n");
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
