<?php
/**
 * Phase 152 prerequisite #8 — physical PTT (assets/js/console-hid.js).
 *
 * Deliberately deferred until the Console rebuild shipped a real strip to
 * bind to (tasks.md's own note: "there is no console-hid.js that could
 * exist right now that would do anything but hold a dead reference to a
 * DOM element that doesn't exist yet"). Now that strips exist, this is
 * the home test for the feature.
 *
 * No DOM simulation here (this project has no jsdom-equivalent dependency
 * for a full browser-DOM Node harness) — coverage is source-level
 * structural guards, this project's own established style for DOM-heavy
 * files that a headless Node eval can't meaningfully exercise (matching
 * tests/test_console_audio_state.php's own treatment of zello-widget.js/
 * radio-widget.js for the identical reason). Live-verification against
 * real USB foot-switch hardware happens by hand on the validation
 * instance, per this prerequisite's own explicit instruction to flag
 * untested hardware rather than claim broad support.
 *
 * Usage: php tests/test_phase152_physical_ptt.php
 */

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 prerequisite #8 -- physical PTT ===\n\n";

$hidPath = __DIR__ . '/../assets/js/console-hid.js';
t('assets/js/console-hid.js exists', file_exists($hidPath));
$hid = (string) @file_get_contents($hidPath);
t('ES5 style (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $hid));
t('exports window.ConsoleHid', strpos($hid, 'window.ConsoleHid = {') !== false);

echo "\n--- 1. Eligibility: data-real-ptt, not the absence of data-launcher ---\n\n";
t('eligibleChannelIds() queries data-real-ptt="1" directly, not an inferred double-negative '
    . '(a text-only channel also lacks data-launcher but is never a real PTT target)',
    strpos($hid, "querySelectorAll('[data-real-ptt=\"1\"]')") !== false);
t('...and additionally requires the channel to be currently SELECTED '
    . '(a real-PTT-capable channel the operator has not selected must not fire)',
    strpos($hid, 'state.selected') !== false);

$cjs = (string) @file_get_contents(__DIR__ . '/../assets/js/console.js');
t('console.js sets data-real-ptt="1" ONLY when real matrix audio is engaged AND the operator '
    . 'holds TX permission -- never on a launcher, never without TX permission',
    strpos($cjs, "if (matrixAudioOn && canTx && !listenOnlyMatrix) { strip.setAttribute('data-real-ptt', '1'); }") !== false);

echo "\n--- 2. No-silent-no-op: a press with nothing eligible shows a visible notice ---\n\n";
t('an empty eligible set triggers a visible on-screen notice, not a silent return '
    . '("stomping a pedal on a launcher strip must never silently do nothing", tasks.md)',
    strpos($hid, 'function showNoTargetNotice()') !== false
    && strpos($hid, 'if (!ids.length) {') !== false
    && strpos($hid, 'showNoTargetNotice();') !== false);

$css = (string) @file_get_contents(__DIR__ . '/../assets/css/console.css');
t('the notice has real, visible CSS (not a class that renders as nothing)',
    strpos($css, '.console-hid-notice') !== false && strpos($css, '.console-hid-notice-visible') !== false);

echo "\n--- 3. Two input paths, both converging on the SAME real PTT call ---\n\n";
t('Gamepad API polling is wired (navigator.getGamepads), for USB foot switches that enumerate as a gamepad',
    strpos($hid, 'navigator.getGamepads') !== false);
t('a keyboard-hold fallback exists, for pedals that instead emulate a keystroke, or an operator with none '
    . '(the backtick key, written via fromCharCode(96) so the source never contains a literal backtick byte)',
    strpos($hid, 'FALLBACK_KEY = String.fromCharCode(96)') !== false);
t('the fallback key is deliberately NOT Space (already bound to PTT by zello-widget.js/radio-widget.js '
    . "for their own legacy singleton-widget audio) and not an F-key (console.js's own per-strip Select hotkeys)",
    strpos($hid, "FALLBACK_KEY = ' '") === false
    && !preg_match("/FALLBACK_KEY\s*=\s*'F\d/", $hid));
t('the keyboard handler skips typing contexts (input/textarea/select/contenteditable), matching '
    . "console.js's own per-strip hotkey listener's established guard",
    strpos($hid, "tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'") !== false
    && strpos($hid, 'isContentEditable') !== false);
t('the keyboard handler ignores OS key-repeat (e.repeat) -- a held key must not re-fire startPtt() on every repeat',
    strpos($hid, 'e.repeat') !== false);
t('both input paths call the SAME startPtt()/stopPtt() -- one shared implementation, not two',
    substr_count($hid, 'startPtt();') >= 2 && substr_count($hid, 'stopPtt();') >= 2);
t('startPtt()/stopPtt() call the REAL window.ConsoleMatrix.talkStart()/talkEnd() -- the SAME '
    . 'function every mouse/touch PTT button on the page already calls, never a reimplementation',
    strpos($hid, 'window.ConsoleMatrix.talkStart(ids[i])') !== false
    && strpos($hid, 'window.ConsoleMatrix.talkEnd(activeChannelIds[i])') !== false);
t('a channel already keyed by one input path is not double-started if the OTHER path also engages '
    . '(activeChannelIds.length guard) -- pressing the pedal while ALSO holding the fallback key must '
    . 'not call talkStart() twice for the same channel',
    strpos($hid, 'if (activeChannelIds.length) { return; }') !== false);
t('releasing one input path does not stop PTT while the OTHER is still held '
    . '(gamepadHeld / keyHeld cross-checks before stopPtt())',
    strpos($hid, 'if (!gamepadHeld) { stopPtt(); }') !== false
    && strpos($hid, 'if (!keyHeld) { stopPtt(); }') !== false);

echo "\n--- 4. Wiring ---\n\n";
$page = (string) @file_get_contents(__DIR__ . '/../console.php');
t('console.php loads console-hid.js', strpos($page, 'console-hid.js') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
