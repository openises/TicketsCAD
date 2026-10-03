<?php
/**
 * Phase 152 unification plan step 3 (2026-09-08) — the navbar Simulselect
 * group-transmit widget.
 *
 * specs/phase-152-comms-console-v2/tasks.md's own record has the full
 * design history: Eric asked for "select which channel my transmission
 * goes to" from any page, a 3-persona design review (fire/EMS dispatcher,
 * ARES/RACES net-control, sysadmin/maintainer) unanimously rejected
 * redefining what the Zello/Radio widgets' own PTT buttons do, and this
 * widget is the resulting safe shape: a genuinely NEW, additive control
 * built entirely on the already-existing Simulselect mechanism (console-
 * audio.js), reachable from a small navbar dropdown on any page instead
 * of only the full console.php strip bank.
 *
 * Covers: the shared audio-pipeline files (console-audio-logic.js,
 * console-audio.js, console-mic.js) are globalized into inc/navbar.php
 * and no longer duplicated on console.php; the widget's own markup exists
 * with the correct RBAC gate and data-can-tx attribute; the widget's JS
 * never touches the Zello/Radio widgets' own PTT (the hard constraint the
 * persona review imposed); the Zello navbar toggle button added in the
 * same change reuses the existing zello:toggle EventBus mechanism rather
 * than adding a second, competing path.
 *
 * Usage: php tests/test_phase152_simulselect_widget.php
 */
chdir(__DIR__ . '/..');

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($hint !== '' && !$cond ? " -- $hint" : '') . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- Simulselect navbar widget (unification plan step 3) ===\n\n";

$navbar = (string) @file_get_contents('inc/navbar.php');
$widgetJs = (string) @file_get_contents('assets/js/console-simulselect-widget.js');
$consolePhp = (string) @file_get_contents('console.php');
$audioJs = (string) @file_get_contents('assets/js/console-audio.js');

// ── 1. The shared audio pipeline is globalized, not duplicated ─────────
// Real <script src="..."> TAGS specifically, not any earlier prose mention
// of the same filename in an explanatory comment (both navbar.php's and
// console.php's own docblocks name these files while explaining the move,
// which sits textually ABOVE/AROUND the real tags in each file).
$logicTagPos = strpos($navbar, '<script src="assets/js/console-audio-logic.js');
$audioTagPos = strpos($navbar, '<script src="assets/js/console-audio.js');
$micTagPos = strpos($navbar, '<script src="assets/js/console-mic.js');
$widgetTagPos = strpos($navbar, '<script src="assets/js/console-simulselect-widget.js');
t('inc/navbar.php loads console-audio-logic.js', $logicTagPos !== false);
t('inc/navbar.php loads console-audio.js', $audioTagPos !== false);
t('inc/navbar.php loads console-mic.js', $micTagPos !== false);
t('inc/navbar.php loads console-simulselect-widget.js', $widgetTagPos !== false);
t('load order: console-audio-logic.js before console-audio.js before console-mic.js before the widget itself',
    $logicTagPos !== false && $audioTagPos !== false && $micTagPos !== false && $widgetTagPos !== false
    && $logicTagPos < $audioTagPos && $audioTagPos < $micTagPos && $micTagPos < $widgetTagPos);

t('console.php no longer has a <script> tag for console-audio-logic.js',
    strpos($consolePhp, '<script src="assets/js/console-audio-logic.js') === false);
t('console.php no longer has a <script> tag for console-audio.js',
    strpos($consolePhp, '<script src="assets/js/console-audio.js') === false);
t('console.php no longer has a <script> tag for console-mic.js',
    strpos($consolePhp, '<script src="assets/js/console-mic.js') === false);
// Phase 155 (GH#108 S1) reversed the 2026-09-08 decision to keep this
// console-only: the Phone widget needs the workstation token on EVERY page
// (it showed an empty token everywhere else), so inc/navbar.php now loads it
// once for all pages and console.php no longer carries its own copy.
t('console.php no longer loads console-workstation.js itself (inc/navbar.php does, once, for every page)',
    strpos($consolePhp, '<script src="assets/js/console-workstation.js') === false
    && substr_count($navbar, '<script src="assets/js/console-workstation.js') === 1);
t('console.php keeps console-playback.js (page-specific — actually plays received matrix audio)',
    strpos($consolePhp, 'assets/js/console-playback.js') !== false);
t('console.php keeps console.js itself (the full strip bank)', strpos($consolePhp, 'src="assets/js/console.js') !== false);

// ── 2. The widget's own markup ──────────────────────────────────────────
t('navbar.php renders #simulselectWidget', strpos($navbar, 'id="simulselectWidget"') !== false);
t('gated on screen.console (the same permission api/console-audio-prefs.php itself requires)',
    (bool) preg_match('/is_admin\(\)\s*\|\|\s*\(function_exists\(\'rbac_can\'\)\s*&&\s*rbac_can\(\'screen\.console\'\)\)\)\s*:\s*\?>\s*<div class="dropdown" id="simulselectWidget"/s', $navbar));
t('carries data-can-tx computed from action.console_tx (console.php\'s own $can_tx permission)',
    strpos($navbar, "data-can-tx=\"<?php echo rbac_can('action.console_tx')") !== false);
t('has a PTT button, a channel list container, and a target readout',
    strpos($navbar, 'id="simulselectPttBtn"') !== false
    && strpos($navbar, 'id="simulselectChannelList"') !== false
    && strpos($navbar, 'id="simulselectTargetReadout"') !== false);

// ── 3. The widget's JS never touches the legacy widgets' own PTT ───────
// The hard constraint the 3-persona design review imposed (2026-09-08):
// this file may drive window.ConsoleAudio's simulselect API, but it must
// NEVER call window.ZelloConsoleAudio / window.RadioConsoleAudio directly
// -- that would be exactly the "redefine a trusted button" shape the
// review unanimously rejected. Those two globals belong to console-
// audio.js's OWN existing (already-safe, already-reviewed) simulselect
// implementation, not to this new file.
t('console-simulselect-widget.js exists', $widgetJs !== '');
t('never references window.ZelloConsoleAudio directly', strpos($widgetJs, 'ZelloConsoleAudio') === false);
t('never references window.RadioConsoleAudio directly', strpos($widgetJs, 'RadioConsoleAudio') === false);
t('drives transmission only through window.ConsoleAudio.simulselectPttStart/Stop (the existing, already-reviewed mechanism)',
    strpos($widgetJs, 'Audio.simulselectPttStart()') !== false && strpos($widgetJs, 'Audio.simulselectPttStop()') !== false);
t('shows the ARES persona\'s requested visible "TX target" readout via simulselectTargets()',
    strpos($widgetJs, 'Audio.simulselectTargets()') !== false);
t('no-ops entirely when RBAC hid the widget server-side (no #simulselectWidget in the DOM)',
    strpos($widgetJs, "if (!root) { return; }") !== false);
t('no-ops entirely when its dependencies are missing, never throws',
    strpos($widgetJs, 'if (!Logic || !Audio) { return; }') !== false);
t('ES5 only (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $widgetJs));

// ── 4. The new Zello navbar toggle reuses the existing mechanism ───────
t('navbar.php\'s new Zello toggle button exists', strpos($navbar, 'id="navZelloToggleBtn"') !== false);
t('gated on action.zello_receive (the real server-side permission api/zello-audio.php enforces, not an unrelated one)',
    (bool) preg_match('/rbac_can\(\'action\.zello_receive\'\)\)\)\s*:\s*\?>\s*<button[^>]*id="navZelloToggleBtn"/s', $navbar));
t('reuses the existing zello:toggle EventBus mechanism rather than a second, competing path',
    strpos($navbar, "window.EventBus.emit('zello:toggle')") !== false);
t('does not add a second data-action="zello" delegator (that would risk the exact double-toggle bug radio-widget.js\'s own docblock documents)',
    strpos($navbar, 'data-action="zello"') === false);

// ── 5. simulselectPttStart/Stop's matrix extension (console-audio.js) ──
// Covered in depth by tests/test_console_audio_state.php's own structural
// guards; pinned again here from the widget's own perspective so a future
// change to either file is caught by both.
t('console-audio.js exposes simulselectTargets() for the readout',
    strpos($audioJs, 'simulselectTargets: function ()') !== false);
t('console-audio.js\'s matrix branch never fires for a DMR channel unless matrixAudio is actually engaged',
    (bool) preg_match("/\\(meta\\.adapter === 'dmr_bm' \\|\\| meta\\.adapter === 'dmr_local'\\) && s && s\\.matrixAudio/", $audioJs));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
