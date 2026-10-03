<?php
/**
 * Phase 155, GH#108 -- the browser phone's dial / answer path and its
 * plain-English error states.
 *
 * Regression for GH#108's original complaint ("outgoing calls need a
 * separate SIP client"): the REAL assets/js/phone-widget.js, driven in jsdom
 * against a FAKE JsSIP, proves that a dial from the widget produces ua.call
 * with the typed number, audio-only, and that every failure the operator can
 * hit (PBX unreachable, certificate not yet trusted, wrong password, blocked
 * microphone, plain-http page, busy, no such number, a caller who hangs up)
 * is reported in words someone can act on.
 *
 * WHAT THIS DOES NOT PROVE: there is no PBX and no real audio here. The fake
 * JsSIP records what the widget asked it to do. Real registration, real
 * two-way audio and the browser's own permission prompt need a real browser
 * and a PBX and are the maintainer's live check.
 *
 * Part 1 pins the pure description functions against the REAL vendored
 * JsSIP's cause strings, so a JsSIP upgrade that renames a cause cannot
 * silently turn a helpful message back into "Call ended: <cause>".
 *
 * Usage: php tests/test_phone_widget_call_flow.php
 */
require_once __DIR__ . '/_phone_node_runner.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
echo "=== Phase 155 -- browser phone dial/answer path and error states ===\n\n--- Part 1: structure ---\n\n";

$widget = (string) file_get_contents($root . '/assets/js/phone-widget.js');
$logic  = (string) file_get_contents($root . '/assets/js/phone-dial-logic.js');
$tpl    = (string) file_get_contents($root . '/inc/phone-widget-template.php');
foreach (['phone-widget.js' => $widget, 'phone-dial-logic.js' => $logic] as $name => $src) {
    // Strip comments, then look for ES6 syntax the project forbids.
    $code = preg_replace('#/\*.*?\*/#s', '', $src);
    $code = preg_replace('#(^|[^:])//[^\n]*#', '$1', $code);
    t("$name is ES5 only (no arrow functions, template literals, let/const)",
        !preg_match('/=>|`|\blet\s|\bconst\s/', $code));
}
t('phone-widget.js has no literal closing-script sequence anywhere (it is a .js file; the rule is for inline comments)',
    stripos($widget, '</script') === false);
t('every <button> in the phone template declares a type (a button inside any form would otherwise submit it)',
    preg_match_all('/<button(?![^>]*\btype=)[^>]*>/', $tpl) === 0);
t('the footer is a polite aria-live status region', strpos($tpl, 'id="phoneFooter" role="status" aria-live="polite"') !== false);
t('the number box and keypad controls carry accessible names',
    strpos($tpl, 'aria-label="Number to dial"') !== false && strpos($tpl, 'aria-label="Delete the last digit"') !== false);

echo "\n--- Part 2: behaviour (real widget in jsdom, fake JsSIP) ---\n\n";
$r = phone_node_run('_phone_call_flow_node.js');
if ($r !== null) { foreach ($r as $row) { t($row[0], $row[1]); } }

echo "\n--- Part 3: description functions vs the REAL vendored JsSIP's cause strings ---\n\n";
$r = phone_node_run('_phone_logic_node.js', [], false);
if ($r !== null) { foreach ($r as $row) { t($row[0], $row[1]); } }

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
