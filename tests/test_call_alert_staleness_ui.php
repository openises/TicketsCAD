<?php
/**
 * Phase 149 Milestone 7 — the client-side heartbeat, the stale-claim
 * "Reclaim" button (FR-16, low-friction, no reason), and the FR-17
 * supervisor-override reason prompt (both the keyboard `T` path and the
 * quick-reassign-fails-so-falls-back-to-override path).
 *
 * Drives the REAL assets/js/call-alert.js under node.
 *
 * @requires-node
 * Usage: php tests/test_call_alert_staleness_ui.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 7 — heartbeat + stale-claim UI ===\n\n";

$base = realpath(__DIR__ . '/..');
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}
if ($node === null) {
    echo "SKIP: node not available\n";
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
var promptCalls = [];
var promptReturn = '';

global.window = global;
global.window.CALL_ALERT_USER_ID = 42;
global.window.CALL_ALERT_USER_NAME = 'Test Dispatcher';
global.window.CALL_ALERT_CSRF = 'test-csrf-token';
global.window.open = function (url) { openedUrls.push(url); };
global.window.prompt = function (msg, def) { promptCalls.push(msg); return promptReturn; };

var fakeContainer = { className: '', innerHTML: '', querySelectorAll: function () { return []; } };
global.document = {
    readyState: 'complete',
    addEventListener: function () {},
    getElementById: function (id) { return id === 'callAlertBanner' ? fakeContainer : null; }
};
global.sessionStorage = { getItem: function () { return null; }, setItem: function () {} };
global.fetch = function (url, opts) {
    fetchCalls.push({ url: url, opts: opts });
    var body = opts && opts.body ? JSON.parse(opts.body) : {};
    // Simulate the server's own behaviour for the reassign-elapsed case.
    if (url.indexOf('action=reassign') !== -1 && body.id === 200) {
        return Promise.resolve({ json: function () { return Promise.resolve({ success: false, reason: 'grace_window_elapsed', claimed_by_name: 'Someone Else' }); } });
    }
    return Promise.resolve({ json: function () { return Promise.resolve({ success: true, call: { id: body.id } }); } });
};

eval(fs.readFileSync(process.argv[2], 'utf8'));

var CA = global.window.CallAlert;
check('CallAlert exposed on window', !!CA);

// ── STALE claim: keyboard T force-reclaims directly, no reason prompt ──
CA._upsertCall({ call_id: 100, caller_number: '+16125550100', state: 'claimed', claimed_by: 999, claimed_by_name: 'Someone Else', stale: true, ringing_at: '2026-08-22 10:00:00' });
CA._render();
CA._setHighlighted(100);
fetchCalls.length = 0; promptCalls.length = 0;
CA._actOnHighlighted('t');
check("'T' on a STALE claimed-by-another call posts action=force_reclaim", fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=force_reclaim') !== -1, JSON.stringify(fetchCalls));
check('the stale reclaim sends NO reason (FR-16, low friction)', fetchCalls.length === 1 && JSON.parse(fetchCalls[0].opts.body).reason === null);
check('no reason PROMPT was shown for a stale reclaim', promptCalls.length === 0);

// ── NON-stale claim within grace window: T still quick-reassigns ───────
CA._upsertCall({ call_id: 101, caller_number: '+16125550101', state: 'claimed', claimed_by: 999, claimed_by_name: 'Someone Else', stale: false, ringing_at: '2026-08-22 10:00:05' });
CA._render();
CA._setHighlighted(101);
fetchCalls.length = 0; promptCalls.length = 0;
CA._actOnHighlighted('t');
check("'T' on a NON-stale claimed-by-another call still posts action=reassign (FR-18a unaffected)",
    fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=reassign') !== -1, JSON.stringify(fetchCalls));

// ── Grace window elapsed mid-flight: reassign fails, falls back to the
//    FR-17 reason-prompt override automatically. ───────────────────────
promptReturn = 'Shift lead approved takeover';
fetchCalls.length = 0; promptCalls.length = 0;
CA._reassignCall(200);

setTimeout(function () {
    check('a failed quick-reassign (grace window elapsed) automatically offers the FR-17 reason prompt',
        promptCalls.length === 1, JSON.stringify(promptCalls));
    check('after a reason is supplied, force_reclaim is posted WITH that reason',
        fetchCalls.length === 2 && fetchCalls[1].url.indexOf('action=force_reclaim') !== -1
        && JSON.parse(fetchCalls[1].opts.body).reason === 'Shift lead approved takeover',
        JSON.stringify(fetchCalls));

    // Cancelling the prompt (null) must NOT fire any force_reclaim call.
    promptReturn = null;
    fetchCalls.length = 0; promptCalls.length = 0;
    CA._forceReclaimCall(300, false);
    check('cancelling the reason prompt (null) never posts force_reclaim', fetchCalls.length === 0);

    // An empty/whitespace-only reason is also refused client-side.
    promptReturn = '   ';
    fetchCalls.length = 0;
    CA._forceReclaimCall(300, false);
    check('a blank reason is refused client-side (never sent to the server)', fetchCalls.length === 0);

    // ── The 15s heartbeat only beats calls THIS user holds in state=claimed ──
    CA._upsertCall({ call_id: 400, caller_number: '+16125550400', state: 'claimed', claimed_by: 42, ringing_at: '2026-08-22 10:00:00' });
    CA._upsertCall({ call_id: 401, caller_number: '+16125550401', state: 'wrapup', claimed_by: 42, ringing_at: '2026-08-22 10:00:00' });
    CA._upsertCall({ call_id: 402, caller_number: '+16125550402', state: 'claimed', claimed_by: 999, ringing_at: '2026-08-22 10:00:00' });
    fetchCalls.length = 0;
    CA._sendHeartbeats();
    var hbUrls = fetchCalls.map(function (f) { return f.url + ':' + JSON.parse(f.opts.body).id; });
    check('heartbeat fires for the CURRENT user\'s own claimed (not wrapup) call only',
        fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=heartbeat') !== -1 && Number(JSON.parse(fetchCalls[0].opts.body).id) === 400,
        JSON.stringify(hbUrls));

    console.log(out.join('\n'));
}, 50);
JS;

$harness = sys_get_temp_dir() . '/tcad_staleness_harness_' . getmypid() . '.js';
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
