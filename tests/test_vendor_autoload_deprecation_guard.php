<?php
/**
 * A third-party deprecation raised while Composer's autoloader loads must not reach the page.
 *
 * Found by the Phase 155 live smoke on training (PHP 8.4, debug on): Notification Rules' JSON was
 * preceded by "<br /><b>Deprecated</b>: Ratchet\Client\connect(): Implicitly marking parameter
 * $loop as nullable is deprecated" once per Apache reload, because loading the broker loads the
 * push stack, which requires vendor/autoload.php, which compiles vendor/ratchet/pawl. The fix is
 * inc/vendor-autoload.php: error_reporting() drops E_DEPRECATED for the duration of the require
 * only. This test drives the REAL helper against a fixture autoloader that raises a REAL
 * E_DEPRECATED (strftime() is deprecated on 8.1-8.4, so it works on whichever PHP runs the suite),
 * proves nothing leaks while it loads, proves error_reporting is put back afterwards, and proves
 * the three web-request load sites use the helper instead of a bare require.
 *
 * Usage: php tests/test_vendor_autoload_deprecation_guard.php
 */
$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}
$root = dirname(__DIR__);
echo "=== vendor autoload deprecation guard ===\n\n";

// ── A scratch project: <tmp>/inc/vendor-autoload.php (a copy of the real helper) + <tmp>/vendor/autoload.php
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'newui-vag-' . getmypid() . '-' . mt_rand();
mkdir($tmp . '/inc', 0777, true);
mkdir($tmp . '/vendor', 0777, true);
copy($root . '/inc/vendor-autoload.php', $tmp . '/inc/vendor-autoload.php');

$raisesDeprecation = function_exists('strftime');
t('this PHP still has a function that raises a real E_DEPRECATED (the fixture needs one)', $raisesDeprecation);

file_put_contents($tmp . '/vendor/autoload.php',
    "<?php\n\$GLOBALS['VAG_LOADED'] = (\$GLOBALS['VAG_LOADED'] ?? 0) + 1;\n"
    . ($raisesDeprecation ? "strftime('%Y');\n" : ""));

$seen = [];
// Deterministic whatever the runner's ini says: CI's PHP excludes E_DEPRECATED by default, which
// made the CONTROL below find nothing to report.
error_reporting(E_ALL);
// A user error handler is called even for errors the current error_reporting() excludes, so a handler
// that wants to mimic what PHP would DISPLAY has to honour the mask itself, exactly as PHP's own does.
set_error_handler(function ($no, $str) use (&$seen) {
    if (!(error_reporting() & $no)) { return true; }
    $seen[] = [$no, $str];
    return true;
});
$before = error_reporting();

require_once $tmp . '/inc/vendor-autoload.php';
t('the helper function is defined', function_exists('newui_require_vendor_autoload'));
$ok = newui_require_vendor_autoload();
t('it reports the autoloader as loaded', $ok === true);
t('the fixture autoloader really ran', ($GLOBALS['VAG_LOADED'] ?? 0) === 1);
t('...and the E_DEPRECATED it raised while loading did NOT reach the error handler (nothing is printed)',
    count(array_filter($seen, function ($e) { return $e[0] === E_DEPRECATED; })) === 0);
t('error_reporting() is exactly what it was before the call', error_reporting() === $before);

// Control: with the mask restored, the same deprecation IS reported, so the silence above was the guard.
if ($raisesDeprecation) {
    $seen = [];
    strftime('%Y');
    t('CONTROL: outside the helper the same deprecation is reported again (the guard is scoped to the require)',
        count(array_filter($seen, function ($e) { return $e[0] === E_DEPRECATED; })) === 1);
}

// A second call is a no-op (require_once) and still leaves the mask alone.
$ok2 = newui_require_vendor_autoload();
t('a second call is harmless', $ok2 === true && ($GLOBALS['VAG_LOADED'] ?? 0) === 1 && error_reporting() === $before);

// An exception thrown by the autoloader must not leave the mask changed.
file_put_contents($tmp . '/vendor/autoload.php', "<?php\nthrow new RuntimeException('boom');\n");
// require_once of a path already loaded is skipped, so use a second scratch project for this one.
$tmp2 = $tmp . '-throw';
mkdir($tmp2 . '/inc', 0777, true);
mkdir($tmp2 . '/vendor', 0777, true);
$helperSrc = file_get_contents($root . '/inc/vendor-autoload.php');
$helperSrc = str_replace('newui_require_vendor_autoload', 'newui_require_vendor_autoload_throwing', $helperSrc);
file_put_contents($tmp2 . '/inc/vendor-autoload.php', $helperSrc);
file_put_contents($tmp2 . '/vendor/autoload.php', "<?php\nthrow new RuntimeException('boom');\n");
require_once $tmp2 . '/inc/vendor-autoload.php';
$threw = false;
try { newui_require_vendor_autoload_throwing(); } catch (RuntimeException $e) { $threw = true; }
t('an autoloader that throws still propagates the exception', $threw);
t('...and error_reporting() is restored even then (try/finally)', error_reporting() === $before);

// A project with no vendor/ at all.
$tmp3 = $tmp . '-none';
mkdir($tmp3 . '/inc', 0777, true);
$helperSrc3 = str_replace('newui_require_vendor_autoload', 'newui_require_vendor_autoload_none', file_get_contents($root . '/inc/vendor-autoload.php'));
file_put_contents($tmp3 . '/inc/vendor-autoload.php', $helperSrc3);
require_once $tmp3 . '/inc/vendor-autoload.php';
t('no vendor/autoload.php: returns false (callers keep their graceful path) and changes nothing',
    newui_require_vendor_autoload_none() === false && error_reporting() === $before);

restore_error_handler();

// ── The three web-request load sites use the helper, not a bare require ──────────────────────────
foreach (['inc/push.php', 'api/diagnostics.php', 'api/push-admin.php'] as $rel) {
    $src = file_get_contents($root . '/' . $rel);
    // Strip comments so a sentence that mentions the old line cannot satisfy or defeat the check.
    $code = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $code .= is_array($tok) ? $tok[1] : $tok;
    }
    t("$rel calls newui_require_vendor_autoload()", strpos($code, 'newui_require_vendor_autoload()') !== false);
    t("$rel has no bare require of vendor/autoload.php left",
        !preg_match('#require(?:_once)?\s*\(?\s*(?:__DIR__\s*\.\s*[\'"]/\.\./vendor/autoload\.php[\'"]|\$autoload)\s*\)?\s*;#', $code));
}

// cleanup
foreach ([$tmp, $tmp2, $tmp3] as $d) {
    foreach (['/inc/vendor-autoload.php', '/vendor/autoload.php'] as $f) { @unlink($d . $f); }
    @rmdir($d . '/inc'); @rmdir($d . '/vendor'); @rmdir($d);
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
