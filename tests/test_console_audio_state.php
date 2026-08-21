<?php
/**
 * Phase 114b3 — Communications Console select/monitor/mute/volume/
 * simulselect PURE STATE-MACHINE tests.
 *
 * This project has a documented gap for hardware-dependent audio tests
 * ("mock services for hardware-dependent integration tests" in the
 * backlog) — so console-audio-logic.js is deliberately factored to have
 * NO DOM, no fetch, no widget references (see its own docblock), and this
 * file drives the REAL file under Node exactly the way tests/test_tile_
 * proxy.php drives the real assets/js/map-prefs.js: eval the production
 * source with minimal stubbed globals, then call its exported functions
 * and assert on the real output. Nothing here is a re-implementation of
 * the logic under test.
 *
 * Covers: default-state gain is always 1.0 (an untouched console must
 * behave exactly as it did before this feature existed); select promotes
 * to full volume; mon=false silences an unselected channel even with
 * nothing else selected; mon=true (default) only attenuates once
 * something ELSE is selected; mute always wins; volume scales both the
 * selected and monitored cases; simulselect membership filters to only
 * TX-capable ids; textProminence's three states.
 *
 * Usage: php tests/test_console_audio_state.php
 */
chdir(__DIR__ . '/..');

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 114b3 — console audio state machine (pure logic) ===\n\n";

$logicPath = __DIR__ . '/../assets/js/console-audio-logic.js';
t('console-audio-logic.js exists', file_exists($logicPath));

$src = (string) @file_get_contents($logicPath);
t('no DOM/fetch/window-widget references in the pure logic file (stays testable without a browser)',
    strpos($src, 'document.') === false && strpos($src, 'fetch(') === false
    && strpos($src, 'ZelloConsoleAudio') === false && strpos($src, 'RadioConsoleAudio') === false);
t('exports window.ConsoleAudioLogic', strpos($src, 'window.ConsoleAudioLogic') !== false);

// ── Widget hook wiring guards ────────────────────────────────────────
// zello-widget.js / radio-widget.js are 2000+ line WebAudio/AudioWorklet/
// MediaSource pipelines this project's own backlog names as a documented
// gap for hardware-dependent automated testing (no mock audio-device
// harness exists here) — so these are STRUCTURAL guards, not behavioral
// proof; live_verification of actual audio output happens through the
// Browser pane, by hand, as part of this phase's own shipping checklist,
// not in this file. What CAN be proven mechanically: the console hooks
// call the SAME functions the widgets' own PTT button / mute button call
// (not a parallel, easily-drifting reimplementation), and the pure gain
// math lives in console-audio-logic.js (already proven above), not
// duplicated inline in either widget.
$zelloSrc = (string) @file_get_contents(__DIR__ . '/../assets/js/zello-widget.js');
$radioSrc = (string) @file_get_contents(__DIR__ . '/../assets/js/radio-widget.js');

t('zello-widget.js exports window.ZelloConsoleAudio', strpos($zelloSrc, 'window.ZelloConsoleAudio') !== false);
t('ZelloConsoleAudio.ptt.start() calls the REAL startTransmit() — the same function the '
    . "widget's own PTT button/Spacebar handler calls, not a reimplementation",
    preg_match('/ptt:\s*\{\s*start:\s*function\s*\(\)\s*\{\s*startTransmit\(\);\s*\}/', $zelloSrc) === 1);
t('ZelloConsoleAudio.ptt.stop() calls the REAL stopTransmit()',
    preg_match('/stop:\s*function\s*\(\)\s*\{\s*stopTransmit\(\);\s*\}/', $zelloSrc) === 1);
t('zello console volume is applied via the REAL <audio>.volume on every active stream '
    . '(applyConsoleAudioLevel walks activeAudioStreams — the same object the widget\'s own playback uses)',
    strpos($zelloSrc, 'activeAudioStreams[sid].audio.volume = vol') !== false);

t('radio-widget.js exports window.RadioConsoleAudio', strpos($radioSrc, 'window.RadioConsoleAudio') !== false);
t('RadioConsoleAudio.ptt.start() calls the REAL pttStart() — including its FCC 97.119 gate',
    preg_match('/ptt:\s*\{\s*start:\s*function\s*\(\)\s*\{\s*pttStart\(\);\s*\}/', $radioSrc) === 1);
t('RadioConsoleAudio.ptt.stop() calls the REAL pttEnd()',
    preg_match('/stop:\s*function\s*\(\)\s*\{\s*pttEnd\(\);\s*\}/', $radioSrc) === 1);
t('radio console gain multiplies the REAL ring-buffer samples inside pullSamples() '
    . '(the same function every other playback path already reads through)',
    strpos($radioSrc, 'out[i++] = ring[playIndex] * consoleGain;') !== false);
t('radio console mute composes with (never replaces) the widget\'s own muted/audioMuted silence gate',
    strpos($radioSrc, 'if (muted || audioMuted || consoleMuted || !audioAllowed()) return out;') !== false);

$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$harness = sys_get_temp_dir() . '/tcad_console_audio_harness_' . getmypid() . '.js';
$logicJsPath = str_replace('\\', '/', $logicPath);
$js = <<<'JS'
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

global.window = global;
eval(fs.readFileSync(process.argv[2], 'utf8'));

var L = global.window.ConsoleAudioLogic;
check('ConsoleAudioLogic loaded', !!L);

// ── defaultState() / normalizeState() ──────────────────────────────
var d = L.defaultState();
check('defaultState: selected false', d.selected === false);
check('defaultState: mon true', d.mon === true);
check('defaultState: muted false', d.muted === false);
check('defaultState: volume 100', d.volume === 100);
check('defaultState: simulselect false', d.simulselect === false);

var n1 = L.normalizeState({ volume: 250 });
check('normalizeState clamps volume above 100', n1.volume === 100);
var n2 = L.normalizeState({ volume: -5 });
check('normalizeState clamps volume below 0', n2.volume === 0);
var n3 = L.normalizeState(null);
check('normalizeState(null) never throws, returns sane defaults', n3.volume === 100 && n3.mon === true);
var n4 = L.normalizeState({ mon: false });
check('normalizeState respects an explicit mon:false', n4.mon === false);
var n5 = L.normalizeState({});
check('normalizeState({}) defaults mon to true (undefined -> true)', n5.mon === true);

// ── anySelected() ───────────────────────────────────────────────────
check('anySelected: empty map -> false', L.anySelected({}) === false);
check('anySelected: no channel selected -> false',
    L.anySelected({ '1': { selected: false }, '2': { selected: false } }) === false);
check('anySelected: one selected -> true',
    L.anySelected({ '1': { selected: false }, '2': { selected: true } }) === true);

// ── effectiveGain() — THE core rule ─────────────────────────────────
// An UNTOUCHED console (every channel at its default state, nothing
// selected anywhere) must produce gain 1.0 for every channel, always —
// this is the deliberate "shipping this feature never silently changes
// existing audio levels" guarantee (see the module's own docblock).
check('untouched channel, nothing selected anywhere -> gain 1.0 (unchanged default behavior)',
    L.effectiveGain(L.defaultState(), false) === 1);
check('untouched channel, board has an unrelated selection -> still full (mon=true, no selection HERE, default) '
    + 'attenuates: base(1) * MONITOR_ATTEN',
    Math.abs(L.effectiveGain(L.defaultState(), true) - L.MONITOR_ATTEN) < 1e-9);

check('selected -> full volume regardless of board selection state',
    L.effectiveGain({ selected: true, mon: true, muted: false, volume: 100 }, true) === 1
    && L.effectiveGain({ selected: true, mon: true, muted: false, volume: 100 }, false) === 1);

check('muted ALWAYS wins -> 0, even when selected',
    L.effectiveGain({ selected: true, mon: true, muted: true, volume: 100 }, true) === 0);

check('unselected + mon:false -> 0 (silent) even with NOTHING selected anywhere',
    L.effectiveGain({ selected: false, mon: false, muted: false, volume: 100 }, false) === 0);
check('unselected + mon:false -> 0 (silent) when something ELSE is selected too',
    L.effectiveGain({ selected: false, mon: false, muted: false, volume: 100 }, true) === 0);

check('unselected + mon:true + something else selected -> attenuated monitor level',
    Math.abs(L.effectiveGain({ selected: false, mon: true, muted: false, volume: 100 }, true) - L.MONITOR_ATTEN) < 1e-9);
check('unselected + mon:true + NOTHING selected anywhere -> full (the untouched-default case)',
    L.effectiveGain({ selected: false, mon: true, muted: false, volume: 100 }, false) === 1);

check('volume scales the selected (full) case',
    L.effectiveGain({ selected: true, mon: true, muted: false, volume: 50 }, true) === 0.5);
check('volume scales the monitored (attenuated) case',
    Math.abs(L.effectiveGain({ selected: false, mon: true, muted: false, volume: 50 }, true) - (0.5 * L.MONITOR_ATTEN)) < 1e-9);
check('volume 0 -> 0 regardless of select/mon', L.effectiveGain({ selected: true, mon: true, muted: false, volume: 0 }, true) === 0);

// ── textProminence() ─────────────────────────────────────────────────
check("textProminence: default state -> 'normal'", L.textProminence(L.defaultState()) === 'normal');
check("textProminence: selected -> 'prominent'",
    L.textProminence({ selected: true, mon: true, muted: false, volume: 100 }) === 'prominent');
check("textProminence: muted -> 'suppressed' (even if also selected — mute wins)",
    L.textProminence({ selected: true, mon: true, muted: true, volume: 100 }) === 'suppressed');
check("textProminence: unselected, board has a selection elsewhere -> still 'normal' "
    + '(a missed dispatch message is worse than a quiet radio channel — text never fades)',
    L.textProminence({ selected: false, mon: true, muted: false, volume: 100 }) === 'normal');

// ── simulselectMembers() ─────────────────────────────────────────────
var chans = {
    '1': { simulselect: true },   // TX-capable, member
    '2': { simulselect: true },   // NOT TX-capable -> excluded
    '3': { simulselect: false },  // TX-capable, not a member
    '4': { simulselect: true }    // TX-capable, member
};
var members = L.simulselectMembers(chans, ['1', '3', '4']);
check('simulselectMembers: only TX-capable + simulselect:true ids are members',
    members.length === 2 && members.indexOf('1') !== -1 && members.indexOf('4') !== -1);
check('simulselectMembers: a simulselect:true id NOT in txCapableIds is excluded (channel lost TX capability)',
    members.indexOf('2') === -1);
check('simulselectMembers: empty inputs never throw',
    L.simulselectMembers({}, []).length === 0 && L.simulselectMembers(null, null).length === 0);

console.log(out.join('\n'));
JS;
file_put_contents($harness, $js);
$raw = @shell_exec($node . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($logicJsPath) . ' 2>&1');
@unlink($harness);

if (!is_string($raw) || strpos($raw, '|') === false) {
    t('node harness ran console-audio-logic.js', false);
    echo "  raw output: " . trim((string) $raw) . "\n";
} else {
    foreach (explode("\n", trim($raw)) as $line) {
        $parts = explode('|', $line, 3);
        if (count($parts) < 2) { continue; }
        t('[js] ' . $parts[1], $parts[0] === 'PASS');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
