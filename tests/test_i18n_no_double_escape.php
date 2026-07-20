<?php
/**
 * test_i18n_no_double_escape.php
 *
 * Static guard against the double-escaped-ampersand bug:
 *   echo e(t('some.key', 'Foo &amp; Bar'));
 * Here the fallback already contains the HTML entity "&amp;", and e()
 * (htmlspecialchars) escapes the "&" again → "&amp;amp;" → the page
 * renders the literal text "Foo &amp; Bar".
 *
 * The fix is to write raw "&" in t() fallbacks (and stored caption
 * values), letting the single e() at output time produce "&amp;".
 *
 * This test scans the NewUI PHP source for any t() default argument
 * that contains a pre-escaped "&amp;" (or other pre-escaped entity),
 * which is always wrong because t() output is escaped at the call site.
 *
 * Origin: 2026-06-23 — roles.php rendered "Roles &amp; Permissions"
 * in its <title> and <h1> because the fallback was 'Roles &amp; Permissions'.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$failures = [];

function tnd_assert($cond, $label, &$pass, &$fail, &$failures, $detail = '') {
    if ($cond) { $pass++; }
    else { $fail++; $failures[] = $label . ($detail ? " — {$detail}" : ''); }
}

// Collect PHP files, skipping vendor/lib/tests/node_modules.
$skip = '#[\\\\/](vendor|lib|node_modules|tests|tools|sql)[\\\\/]#';
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $path = $f->getPathname();
    if (substr($path, -4) !== '.php') continue;
    if (preg_match($skip, $path)) continue;
    $files[] = $path;
}

tnd_assert(count($files) > 50, 'found a reasonable number of PHP files to scan', $pass, $fail, $failures, count($files) . ' files');

// Pre-escaped entities that must never appear inside a t() fallback string.
$entities = ['&amp;', '&lt;', '&gt;', '&quot;', '&#039;', '&#39;'];

// Match a t() call's default (2nd) argument, single- or double-quoted.
//   t('key', 'default')  /  t("key", "default")
$reT = '/\bt\(\s*[\'"][a-zA-Z0-9._\-]+[\'"]\s*,\s*([\'"])(.*?)\1\s*\)/s';

$offenders = [];
foreach ($files as $path) {
    $src = file_get_contents($path);
    if ($src === false) continue;
    if (preg_match_all($reT, $src, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            $default = $hit[2];
            foreach ($entities as $ent) {
                if (strpos($default, $ent) !== false) {
                    $offenders[] = str_replace($root . DIRECTORY_SEPARATOR, '', $path) . " :: t() fallback '" . $default . "' contains pre-escaped '" . $ent . "'";
                    break;
                }
            }
        }
    }
}

tnd_assert(
    count($offenders) === 0,
    'no t() fallback contains a pre-escaped HTML entity (double-escape bug)',
    $pass, $fail, $failures,
    count($offenders) ? implode(' | ', $offenders) : ''
);

// Regression spot-check: roles.php fallback is the raw form.
$roles = @file_get_contents($root . '/roles.php');
tnd_assert($roles !== false, 'roles.php is readable', $pass, $fail, $failures);
if ($roles !== false) {
    tnd_assert(strpos($roles, "'Roles &amp; Permissions'") === false, 'roles.php has no pre-escaped Roles &amp; Permissions fallback', $pass, $fail, $failures);
    tnd_assert(strpos($roles, "'Roles & Permissions'") !== false, 'roles.php uses the raw "Roles & Permissions" fallback', $pass, $fail, $failures);
}

echo "test_i18n_no_double_escape: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    foreach ($failures as $f) echo "  FAIL: {$f}\n";
    exit(1);
}
exit(0);
