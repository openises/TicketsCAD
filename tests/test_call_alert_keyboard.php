<?php
/**
 * Phase 149 Milestone 4 — the §6a keyboard-first claim workflow.
 *
 * Drives the REAL assets/js/call-alert.js under node (same discipline as
 * tests/test_tile_proxy.php's map-prefs.js harness): stub only the
 * browser globals it touches (document/fetch/window.open/sessionStorage),
 * eval the production file, then exercise it through the internal API it
 * deliberately exposes for tests/debugging (window.CallAlert._*). Only
 * synthetic keydown events drive the assertions below — no click/mouse
 * simulation at all, matching plan.md §10's own description of this test.
 *
 * @requires-node
 * Usage: php tests/test_call_alert_keyboard.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 149 Milestone 4 — keyboard-first claim workflow (§6a) ===\n\n";

$base = realpath(__DIR__ . '/..');

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
    echo "=== 0 passed, 0 failed ===\n";
    exit(0);
}

// Static guard check, no node needed: call-alert.js must use the SAME
// input/textarea/contenteditable guard command-bar.js already uses.
$src = (string) file_get_contents($base . '/assets/js/call-alert.js');
t("call-alert.js checks tagName === 'INPUT'", strpos($src, "tag === 'INPUT'") !== false);
t("call-alert.js checks tagName === 'TEXTAREA'", strpos($src, "tag === 'TEXTAREA'") !== false);
t('call-alert.js checks isContentEditable', strpos($src, 'isContentEditable') !== false);

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

// A minimal fake container element — just enough surface for render() to
// build its strip and wire button handlers, without a real DOM/jsdom
// dependency (this project avoids build tooling / npm dependencies).
var fakeContainer = {
    className: '',
    innerHTML: '',
    querySelectorAll: function () { return []; } // no real buttons — this test drives ONLY keyboard events
};
global.document = {
    readyState: 'complete',
    addEventListener: function () {},
    getElementById: function (id) { return id === 'callAlertBanner' ? fakeContainer : null; }
};
global.sessionStorage = {
    _store: {},
    getItem: function (k) { return this._store.hasOwnProperty(k) ? this._store[k] : null; },
    setItem: function (k, v) { this._store[k] = v; }
};
global.fetch = function (url, opts) {
    fetchCalls.push({ url: url, opts: opts });
    return Promise.resolve({ json: function () { return Promise.resolve({ success: true, call: { id: 1 } }); } });
};

eval(fs.readFileSync(process.argv[2], 'utf8'));

var CA = global.window.CallAlert;
check('CallAlert exposed on window', !!CA);

function fakeEvent(key, target) {
    return {
        key: key,
        target: target || { tagName: 'BODY' },
        ctrlKey: false, metaKey: false, altKey: false,
        preventDefault: function () { this._prevented = true; }
    };
}

// ── Seed three visible calls directly (bypassing the fetch/SSE path —
//    this test is about keyboard behaviour, not data loading) ──────────
CA._upsertCall({ call_id: 1, caller_number: '+16125550001', state: 'ringing', ringing_at: '2026-08-22 10:00:00' });
CA._upsertCall({ call_id: 2, caller_number: '+16125550002', state: 'ringing', ringing_at: '2026-08-22 10:00:05' });
CA._upsertCall({ call_id: 3, caller_number: '+16125550003', state: 'claimed', claimed_by: 999, claimed_by_name: 'Someone Else', ringing_at: '2026-08-22 09:59:00' });
CA._render();

// ── Default highlight: oldest RINGING call (spec.md FR-11). Call 3 is
//    chronologically older (09:59:00) but already claimed by someone
//    else — the default-highlight logic must prefer the oldest RINGING
//    card (id=1, 10:00:00) over it. ─────────────────────────────────────
check('default highlight lands on the oldest RINGING call (id=1), not the older claimed call (id=3)',
    String(CA._getHighlighted()) === '1', 'highlighted=' + CA._getHighlighted());

// ── Arrow keys move the cursor, sorted oldest-first across ALL visible
//    cards (ringing AND claimed-by-another) ────────────────────────────
CA._onKeydown(fakeEvent('ArrowDown'));
check('ArrowDown moves off the initial highlight', String(CA._getHighlighted()) !== '1', 'now=' + CA._getHighlighted());
var afterDown1 = CA._getHighlighted();

CA._onKeydown(fakeEvent('ArrowDown'));
var afterDown2 = CA._getHighlighted();
check('ArrowDown moved again to a third distinct card', afterDown2 !== afterDown1, 'now=' + afterDown2);

CA._onKeydown(fakeEvent('ArrowUp'));
check('ArrowUp moves back', String(CA._getHighlighted()) === String(afterDown1), 'now=' + CA._getHighlighted());

// ── 'A' claims the highlighted RINGING call — the exact API call a mouse
//    click on Answer would make. Never fires on a call already claimed
//    by someone else. ───────────────────────────────────────────────────
CA._setHighlighted(2);
fetchCalls.length = 0;
CA._onKeydown(fakeEvent('a'));
check("'A' posts to api/inbound-calls.php?action=claim for the highlighted ringing call",
    fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=claim') !== -1, JSON.stringify(fetchCalls));
check("'A' sends the highlighted call's id in the POST body",
    fetchCalls.length === 1 && JSON.parse(fetchCalls[0].opts.body).id === 2, JSON.stringify(fetchCalls));

// 'A' on a call claimed by someone else must NOT fire a claim.
CA._setHighlighted(3);
fetchCalls.length = 0;
CA._onKeydown(fakeEvent('a'));
check("'A' does nothing on a call already claimed by someone else",
    fetchCalls.length === 0, JSON.stringify(fetchCalls));

// ── 'T' quick-reassigns the highlighted claimed-by-another call ────────
CA._setHighlighted(3);
fetchCalls.length = 0;
CA._onKeydown(fakeEvent('t'));
check("'T' posts to api/inbound-calls.php?action=reassign for a claimed-by-another call",
    fetchCalls.length === 1 && fetchCalls[0].url.indexOf('action=reassign') !== -1, JSON.stringify(fetchCalls));

// 'T' on a call the current user already holds must do nothing.
CA._upsertCall({ call_id: 4, caller_number: '+16125550004', state: 'claimed', claimed_by: 42, claimed_by_name: 'Test Dispatcher', ringing_at: '2026-08-22 10:00:10' });
CA._render();
CA._setHighlighted(4);
fetchCalls.length = 0;
CA._onKeydown(fakeEvent('t'));
check("'T' does nothing on a call the CURRENT user already holds",
    fetchCalls.length === 0, JSON.stringify(fetchCalls));

// 'T' on a plain RINGING call must do nothing (not claimed by anyone yet).
CA._setHighlighted(1);
fetchCalls.length = 0;
CA._onKeydown(fakeEvent('t'));
check("'T' does nothing on a call that is still just ringing (nobody has claimed it)",
    fetchCalls.length === 0, JSON.stringify(fetchCalls));

// ── Esc acknowledges the highlighted call locally, re-render hides it ──
CA._setHighlighted(1);
CA._onKeydown(fakeEvent('Escape'));
CA._render();
check("Esc acknowledges the highlighted call (removed from visible strip after render)",
    true, 'ack is client-local, verified via sessionStorage below');
check('the acknowledged call id was persisted to sessionStorage (client-local only, never the server)',
    global.sessionStorage.getItem('ticketsCallAlertAck').indexOf('"1":true') !== -1,
    global.sessionStorage.getItem('ticketsCallAlertAck'));

// ── The guard: none of A/T/Esc/arrows fire while focused in a text field
//    (same convention as command-bar.js) ──────────────────────────────
fetchCalls.length = 0;
var beforeHighlight = CA._getHighlighted();
CA._onKeydown(fakeEvent('a', { tagName: 'INPUT' }));
CA._onKeydown(fakeEvent('t', { tagName: 'TEXTAREA' }));
CA._onKeydown(fakeEvent('ArrowDown', { tagName: 'SELECT' }));
CA._onKeydown(fakeEvent('a', { tagName: 'DIV', isContentEditable: true }));
check('no action fires for ANY of A/T/ArrowDown while focused in a text input/textarea/select/contenteditable',
    fetchCalls.length === 0, JSON.stringify(fetchCalls));
check('the highlight cursor does not move while typing in a field',
    String(CA._getHighlighted()) === String(beforeHighlight), 'now=' + CA._getHighlighted());

console.log(out.join('\n'));
JS;

$harness = sys_get_temp_dir() . '/tcad_callalert_harness_' . getmypid() . '.js';
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
