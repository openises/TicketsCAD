<?php
/**
 * test_gh119_sound_alerts_fixes.php — GH#119 (reported 2026-08-28).
 *
 * TWO independent bugs in Settings → Sound / Alerts, both confirmed and
 * fixed.
 *
 * BUG 1 — "Save Sound Settings" always failed with "No settings
 * provided". settings.php's Sound/Alerts panel is, by design, a
 * localStorage-backed panel (AudioAlerts.getPrefs()/setPrefs() — the
 * panel's own intro text says "Settings are stored locally in this
 * browser") with its own correct inline <script> submit handler. But
 * assets/js/config.js ALSO carried a stale bindSoundAlertsPanel() —
 * leftover from BEFORE this panel was rewritten to be localStorage-
 * based — which attached a SECOND submit listener to the same
 * #soundAlertsForm, calling the generic collectSettingsFromForm()
 * against a form with zero [data-key] attributes (by design, since
 * these prefs never went through the server-side settings API). That
 * always produced an empty payload, and api/config-admin.php correctly
 * rejected it with "No settings provided" — visible alongside the real
 * handler's own (correct) "Sound settings saved" toast. Its companion
 * loadSoundAlerts() was equally dead: it targeted element ids
 * (setSoundNewVolume, setSoundHighVolume, btnTestSound) that don't exist
 * in the current markup, so every branch of it silently no-op'd except
 * one wasted apiGet('settings') call.
 *
 * FIX: deleted both dead functions and their three call sites (an
 * init()-time bind, a tab-switch load, and a no-op comment marker) from
 * config.js entirely. settings.php's own inline handler (AudioAlerts.
 * setPrefs()-based) is the sole, correct save path, unchanged.
 *
 * BUG 2 — a per-event custom tone override (e.g. newIncident -> a
 * composed tone named "Change") saved correctly to the database (byte-
 * identical to what the composer showed) but the assigned tone did not
 * reliably play — sometimes falling back to the built-in default,
 * sometimes producing no sound with no console error, reproducible both
 * via direct AudioAlerts.playTone() console calls AND via a real
 * incident event.
 *
 * ROOT CAUSE: assets/js/audio-alerts.js is included via inc/navbar.php's
 * own loadGlobal(...) (the documented, single global loader for this
 * script — CLAUDE.md: "EventBus + audio-alerts are loaded via
 * navbar.php... don't include them per-page") AND, redundantly, via a
 * direct <script src="assets/js/audio-alerts.js"> tag on several pages
 * (index.php, callboard.php, messaging.php, settings.php). navbar.php's
 * dedup check is filename-based and usually catches this, but on a
 * large page the static tag can still be unparsed when navbar's own
 * delayed (300ms) dedup check runs, so both copies can end up executing.
 * Because the file declared `var AudioAlerts = (function(){...})()` — a
 * plain var, silently REASSIGNED on a second execution with no error —
 * a second load created a WHOLE SEPARATE module instance with its own
 * eventOverrides/TONES/prefs, and window.AudioAlerts ended up pointing
 * at whichever instance's script tag happened to finish executing last.
 * Whether a given playTone() call (or a composer-driven
 * reloadCustomTones()) landed on the instance whose fetch had actually
 * resolved was a genuine, load-order-dependent RACE — not a data bug at
 * all, which is why the reporter's own direct DB inspection showed
 * everything structurally correct.
 *
 * FIX: `var AudioAlerts = window.AudioAlerts || (function () {...})();`
 * — idempotent regardless of how many times the script tag loads on a
 * given page. A second (or third, etc.) execution short-circuits before
 * the IIFE body (and its own init()/loadCustomTones()/subscribeEvents())
 * ever runs again, so there is only ever ONE module instance no matter
 * how many <script> tags reference the file.
 *
 * This file proves:
 *   Section 1 (static) — config.js contains neither dead function nor
 *     any leftover call site; the real init()/tab-switch code paths are
 *     unaffected otherwise.
 *   Section 2 (static) — audio-alerts.js's declaration line is the
 *     idempotent form.
 *   Section 3 (Node, functional) — the REAL audio-alerts.js source,
 *     evaluated TWICE in the same mocked global scope (simulating two
 *     <script> tag executions on one page, exactly what the four
 *     affected pages do), produces the SAME object identity for
 *     window.AudioAlerts both times — i.e. genuinely one instance, not
 *     two racing ones.
 *   Section 4 (Node, functional) — NEGATIVE CONTROL: the literal pre-fix
 *     declaration line, evaluated twice through the identical harness,
 *     produces TWO DIFFERENT object identities — proving the harness
 *     actually detects the double-instantiation bug this fix closes,
 *     not just that the current file happens to look right.
 *   Section 5 (Node, functional) — with the fix applied, a SECOND
 *     execution's own loadCustomTones() fetch never runs at all (the
 *     mock fetch call counter proves it), confirming the guard prevents
 *     the underlying race outright rather than merely papering over its
 *     symptom.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$base = realpath(__DIR__ . '/..');

echo "=== GH#119 — Sound/Alerts: dead save handler + double-instantiation race ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. config.js: dead bindSoundAlertsPanel()/loadSoundAlerts() fully removed --\n";
// ─────────────────────────────────────────────────────────────────────────
$configSrc = (string) file_get_contents($base . '/assets/js/config.js');

is_true(strpos($configSrc, 'function bindSoundAlertsPanel') === false,
    'FIX: bindSoundAlertsPanel() function definition is gone');
is_true(strpos($configSrc, 'function loadSoundAlerts') === false,
    'FIX: loadSoundAlerts() function definition is gone');
is_true(strpos($configSrc, 'bindSoundAlertsPanel();') === false,
    'FIX: the init()-time call site is gone');
is_true(preg_match('/loadSoundAlerts\(\)\s*;/', $configSrc) === 0,
    'FIX: no remaining call to loadSoundAlerts()');
is_true(strpos($configSrc, "tab === 'sound-alerts')      loadCustomTonesPanel();") !== false,
    'the REAL sound-alerts tab load (loadCustomTonesPanel) is still wired, just without the dead loadSoundAlerts() alongside it');
is_true(strpos($configSrc, 'function loadCustomTonesPanel') !== false,
    'sanity: loadCustomTonesPanel() itself still exists (this fix must not have removed the real loader)');

// A same-shaped, still-alive sibling function to prove this is a targeted
// removal, not something that regressed config.js structurally.
is_true(strpos($configSrc, 'function bindChatSettingsPanel') !== false,
    'sanity: a same-shaped, unrelated panel binder (bindChatSettingsPanel) is untouched');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. audio-alerts.js: idempotent declaration guard is in place --\n";
// ─────────────────────────────────────────────────────────────────────────
$audioSrc = (string) file_get_contents($base . '/assets/js/audio-alerts.js');

is_true(strpos($audioSrc, 'var AudioAlerts = window.AudioAlerts || (function () {') !== false,
    'FIX: the module declaration short-circuits on an existing window.AudioAlerts');
is_true(strpos($audioSrc, 'var AudioAlerts = (function () {') === false,
    'the old, non-idempotent declaration form is gone (only the guarded form remains)');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3/4/5. The REAL audio-alerts.js source, evaluated twice in one mocked page --\n";
// ─────────────────────────────────────────────────────────────────────────
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    require_once __DIR__ . '/_test_node_probe.php';
    $node = test_probe_cli(['node', 'node.exe']);
    break;
}

$harness = <<<'JS'
// Evaluates a given audio-alerts.js SOURCE STRING (process.argv[2] names a
// file with the source to use) TWICE in the SAME mocked global scope --
// simulating two independent <script> tag executions of the file on one
// page load, which is exactly what index.php/callboard.php/messaging.php/
// settings.php do (navbar.php's own loadGlobal() injection, plus each
// page's own redundant static <script> tag).
var fs = require('fs');
var vm = require('vm');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

var srcPath = process.argv[2];
var src = fs.readFileSync(srcPath, 'utf8');

var fetchCallCount = 0;
var sandbox = {
    console: console,
    setTimeout: setTimeout,
    parseInt: parseInt, parseFloat: parseFloat, isNaN: isNaN,
    Math: Math, JSON: JSON, Date: Date,
    // Minimal DOM/browser mocks -- enough for init()/subscribeEvents()/
    // attachUnlockListeners() to run without throwing, since the point of
    // this test is the MODULE-INSTANCE identity, not full DOM behavior.
    document: {
        readyState: 'complete',
        addEventListener: function () {},
        removeEventListener: function () {},
        getElementById: function () { return null; },
        querySelector: function () { return null; },
        createElement: function () { return { addEventListener: function () {}, style: {} }; }
    },
    localStorage: {
        _data: {},
        getItem: function (k) { return Object.prototype.hasOwnProperty.call(this._data, k) ? this._data[k] : null; },
        setItem: function (k, v) { this._data[k] = String(v); }
    },
    EventBus: { on: function () {}, emit: function () {} },
    fetch: function (url) {
        fetchCallCount++;
        return Promise.resolve({
            ok: true,
            json: function () {
                return Promise.resolve({
                    custom_tones: { Change: { notes: [{ hz: 440, dur: 100 }], gap: 40, type: 'sawtooth' } },
                    event_overrides: { newIncident: 'Change' }
                });
            }
        });
    },
    AudioContext: function () { return { state: 'running', resume: function () {}, currentTime: 0, createOscillator: function () { return { connect: function () {}, frequency: { setValueAtTime: function () {} }, type: '', start: function () {}, stop: function () {} }; }, createGain: function () { return { connect: function () {}, gain: { setValueAtTime: function () {}, linearRampToValueAtTime: function () {} } }; } }; }
};
sandbox.window = sandbox; // window === global scope, as in a real page
var ctx = vm.createContext(sandbox);

try { vm.runInContext(src, ctx, { filename: 'audio-alerts-eval-1.js' }); }
catch (e) { check('first eval of the real source runs without throwing', false, String(e)); }

var firstInstance = ctx.window.AudioAlerts;
check('first eval defines window.AudioAlerts', !!firstInstance);

try { vm.runInContext(src, ctx, { filename: 'audio-alerts-eval-2.js' }); }
catch (e) { check('second eval of the real source runs without throwing', false, String(e)); }

var secondInstance = ctx.window.AudioAlerts;
check('FIX: window.AudioAlerts after a SECOND load is the SAME object as after the first (one instance, not two)',
      firstInstance === secondInstance);
check('FIX: the second load did NOT re-run loadCustomTones() (fetch was called exactly once, not twice)',
      fetchCallCount === 1, 'fetchCallCount=' + fetchCallCount);

console.log(out.join('\n'));
JS;

if ($node === null) {
    echo "SKIP: node not available — the double-instantiation checks were not run\n";
} else {
    // ── The REAL fix, run through the harness (Section 3 + 5) ──
    $h = sys_get_temp_dir() . '/tcad_gh119_harness_' . getmypid() . '.js';
    file_put_contents($h, $harness);
    $raw = test_run_cli([$node, $h, str_replace('\\', '/', $base . '/assets/js/audio-alerts.js')]);
    @unlink($h);

    $results = [];
    if (is_string($raw)) {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) < 2) continue;
            if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
            $results[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
        }
    }
    if (!$results) {
        bad('node harness ran the real audio-alerts.js', 'no parseable output: ' . substr((string) $raw, 0, 2000));
    } else {
        foreach ($results as $name => $r) {
            $r['ok'] ? ok('[js] ' . $name) : bad('[js] ' . $name, $r['detail']);
        }
    }

    // ── NEGATIVE CONTROL (Section 4): the literal PRE-FIX declaration
    // line, same harness, same real rest-of-file. Proves the harness
    // actually detects the bug it exists to catch. ──
    $preFixSrc = str_replace(
        'var AudioAlerts = window.AudioAlerts || (function () {',
        'var AudioAlerts = (function () {',
        (string) file_get_contents($base . '/assets/js/audio-alerts.js')
    );
    is_true($preFixSrc !== file_get_contents($base . '/assets/js/audio-alerts.js'),
        'negative-control fixture text substitution actually changed something (sanity)');

    $negPath = sys_get_temp_dir() . '/tcad_gh119_negctrl_' . getmypid() . '.js';
    file_put_contents($negPath, $preFixSrc);
    $h2 = sys_get_temp_dir() . '/tcad_gh119_harness2_' . getmypid() . '.js';
    file_put_contents($h2, $harness);
    $raw2 = test_run_cli([$node, $h2, str_replace('\\', '/', $negPath)]);
    @unlink($h2);
    @unlink($negPath);

    $results2 = [];
    if (is_string($raw2)) {
        foreach (explode("\n", trim($raw2)) as $line) {
            $parts = explode('|', trim($line), 3);
            if (count($parts) < 2) continue;
            if ($parts[0] !== 'PASS' && $parts[0] !== 'FAIL') continue;
            $results2[$parts[1]] = ['ok' => $parts[0] === 'PASS', 'detail' => $parts[2] ?? ''];
        }
    }
    $identityCheckName = 'FIX: window.AudioAlerts after a SECOND load is the SAME object as after the first (one instance, not two)';
    if (isset($results2[$identityCheckName])) {
        is_true($results2[$identityCheckName]['ok'] === false,
            'NEGATIVE CONTROL: the pre-fix declaration DOES produce two different object identities on double-load — proving this harness would have caught the original bug');
    } else {
        bad('NEGATIVE CONTROL: could not find the identity-check result in the negative-control run', substr((string) $raw2, 0, 1000));
    }
}

echo "\n=== {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
