<?php
/**
 * Phase 155, GH#108 slice S1, finding F1 -- the Phone widget only identified
 * its workstation on console.php.
 *
 * phone-widget.js reads the browser's workstation token through
 * window.ConsoleWorkstation.getToken(). That object was defined only by
 * assets/js/console-workstation.js, loaded only from console.php. On every
 * other page the widget sent an EMPTY token, my_extension answered
 * "bound: false", and the widget showed "not bound" with an empty token box
 * -- while the guide said the widget was "reachable from every page". The
 * old test only asserted the widget FILE mentioned ConsoleWorkstation.getToken,
 * never that anything defined it on the same page.
 *
 * Part 1 (static): inc/navbar.php loads the definer exactly once and before
 *   phone-widget.js; console.php no longer loads its own copy; phone.php (the
 *   standalone window) loads it too.
 * Part 2 (behaviour, jsdom, FAKE JsSIP -- no PBX): the real widget on a
 *   non-console page resolves a real token; a control proves the same checks
 *   FAIL without the definer.
 *
 * Usage: php tests/test_phone_widget_token_global.php
 */
require_once __DIR__ . '/_phone_node_runner.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

$root = dirname(__DIR__);
echo "=== Phase 155 / S1 -- the workstation token on every page (GH#108 F1) ===\n\n--- Part 1: static ---\n\n";

$navbar  = (string) file_get_contents($root . '/inc/navbar.php');
$console = (string) file_get_contents($root . '/console.php');
$phonePg = (string) file_get_contents($root . '/phone.php');

$posDef    = strpos($navbar, 'assets/js/console-workstation.js');
$posLogic  = strpos($navbar, 'assets/js/phone-dial-logic.js');
$posWidget = strpos($navbar, 'assets/js/phone-widget.js');
t('inc/navbar.php loads console-workstation.js', $posDef !== false);
t('...exactly once', substr_count($navbar, '<script src="assets/js/console-workstation.js') === 1);
t('...before phone-widget.js (the widget reads the token at init)', $posDef !== false && $posWidget !== false && $posDef < $posWidget);
t('inc/navbar.php loads phone-dial-logic.js before phone-widget.js',
    $posLogic !== false && $posWidget !== false && $posLogic < $posWidget);
t('console.php no longer loads its own copy (one definition, not two)',
    strpos($console, '<script src="assets/js/console-workstation.js') === false);
t('console.php includes inc/navbar.php (so it still gets the definer)', strpos($console, 'inc/navbar.php') !== false);
t('phone.php (the standalone window) loads the definer, the logic and the widget, in that order',
    (bool) preg_match('#console-workstation\.js.*phone-dial-logic\.js.*phone-widget\.js#s', $phonePg));
t('phone.php does NOT include the navbar (minimal chrome, no SSE, no banner)',
    strpos($phonePg, 'inc/navbar.php') === false);

echo "\n--- Part 2: behaviour (real widget in jsdom, fake JsSIP) ---\n\n";
$r = phone_node_run('_phone_token_global_node.js');
if ($r !== null) {
    foreach ($r as $row) { t($row[0], $row[1]); }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
