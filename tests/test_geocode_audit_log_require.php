<?php
/**
 * api/geocode.php must require inc/audit.php before calling audit_log().
 *
 * Reported by kmk1971 (openises/tickets#10, filed against the legacy repo
 * by mistake -- see specs/handoff.md): every address-lookup Test click and
 * every geocode-cache clear fataled with "Call to undefined function
 * audit_log()". api/geocode.php requires config.php, inc/functions.php,
 * api/auth.php, inc/rbac.php and inc/geocode.php -- none of which define or
 * pull in audit_log(), which only exists in inc/audit.php. Most api/*.php
 * files get inc/audit.php transitively through a different include chain;
 * this one never did, and nothing caught it because the endpoint's OTHER
 * actions (search/reverse/status) never reach the audit_log() call at all.
 *
 * Two checks: a structural one (api/geocode.php's own require list actually
 * names inc/audit.php), and a functional one (the real require chain --
 * everything api/geocode.php pulls in ahead of auth.php, which needs a
 * session and is unsuitable for a CLI unit test -- loads clean with
 * audit_log() defined by the end of it, proven by actually calling
 * function_exists() in a fresh PHP process, not by reading source).
 *
 * Usage: php tests/test_geocode_audit_log_require.php
 */

$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

echo "=== api/geocode.php requires inc/audit.php ===\n\n";

$base = realpath(__DIR__ . '/..');
$src = (string) file_get_contents($base . '/api/geocode.php');

is_true(strpos($src, "require_once __DIR__ . '/../inc/audit.php'") !== false,
    'api/geocode.php explicitly requires inc/audit.php');
is_true(strpos($src, 'audit_log(') !== false,
    'sanity check: the file still actually calls audit_log() (this test is protecting something real)');

$php = PHP_BINARY;
$harness = sys_get_temp_dir() . '/tcad_geocode_audit_require_' . getmypid() . '.php';
// Mirrors api/geocode.php's own require order, minus api/auth.php (which
// calls session_start() and is unsuitable for a bare CLI process) -- proves
// audit_log() is defined by the time auth.php would run, using the REAL
// files, not a re-implementation of what they contain.
file_put_contents($harness, <<<'PHP'
<?php
$base = dirname(__DIR__);
require_once $base . '/config.php';
require_once $base . '/inc/functions.php';
require_once $base . '/inc/rbac.php';
require_once $base . '/inc/geocode.php';
require_once $base . '/inc/audit.php';
echo function_exists('audit_log') ? 'DEFINED' : 'MISSING';
PHP
);
$testsDir = $base . '/tests';
@mkdir($testsDir, 0777, true);
$harnessInTree = $testsDir . '/__geocode_audit_require_harness.php';
rename($harness, $harnessInTree);

$out = @shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($harnessInTree) . ' 2>&1');
@unlink($harnessInTree);

is_true(is_string($out) && trim($out) === 'DEFINED',
    'audit_log() is actually defined after api/geocode.php\'s real require chain runs',
    'output: ' . var_export($out, true));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
