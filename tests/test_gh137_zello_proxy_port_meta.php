<?php
/**
 * test_gh137_zello_proxy_port_meta.php — GH#137.
 *
 * assets/js/zello-widget.js has always read the proxy port from a
 * <meta name="zello-proxy-port"> tag, falling back to a hardcoded 8090
 * when it's absent -- but nothing ever rendered the tag, on EITHER page
 * that embeds the widget. Changing Settings -> Zello Network Radio ->
 * Proxy Port only ever took effect server-side (proxy/zello-proxy.php
 * reads it directly), while the browser silently kept using 8090.
 * Reported by rjonesbsink, found the hard way (a real Windows port-
 * exclusion-range conflict forced a live port change) -- and reported
 * TWICE, because the first fix (console.php only) missed that index.php
 * embeds its own independent copy of the widget markup rather than
 * sharing inc/zello-widget-template.php.
 *
 * That "found a second copy after the first fix shipped" history is
 * exactly why this test's most durable assertion is generic: ANY page
 * that embeds the widget (by template include OR its own copy of the
 * markup) must ALSO render this meta tag -- not just console.php and
 * index.php by name. A third page added later without the fix should
 * fail this test the same way the second one would have.
 */

$base = realpath(__DIR__ . '/..');

echo "=== GH#137 — Zello proxy port meta tag ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. Every page that embeds the Zello widget also renders the meta tag --\n";
// ─────────────────────────────────────────────────────────────────────────

// A page embeds the widget if it includes the shared template OR loads
// zello-widget.js directly (index.php's own independent copy). Scan the
// whole app tree generically -- this is the check that would have caught
// index.php being missed the first time, and would catch a third page.
$embedders = [];
foreach (glob($base . '/*.php') as $f) {
    $src = (string) file_get_contents($f);
    if (strpos($src, 'zello-widget-template') !== false
        || strpos($src, 'zello-widget.js') !== false) {
        $embedders[] = $f;
    }
}
is_true(count($embedders) >= 2, 'at least the two known embedders are found by the generic scan',
    (string) count($embedders));

$missing = [];
foreach ($embedders as $f) {
    $src = (string) file_get_contents($f);
    if (strpos($src, 'name="zello-proxy-port"') === false) {
        $missing[] = basename($f);
    }
}
is_true($missing === [], 'every page embedding the Zello widget renders the proxy-port meta tag',
    implode(', ', $missing));

// The two known embedders, named explicitly so a passing generic scan
// above can't quietly stop covering them (e.g. if the widget's include
// mechanism changes shape and the glob-based scan starts missing files).
foreach (['console.php', 'index.php'] as $known) {
    is_true(in_array($base . '/' . $known, $embedders, true),
        "$known is still recognized as a Zello-widget embedder by the scan");
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The rendered value traces to the real setting, with the real clamp --\n";
// ─────────────────────────────────────────────────────────────────────────

foreach (['console.php' => $base . '/console.php', 'index.php' => $base . '/index.php'] as $name => $path) {
    $src = (string) file_get_contents($path);
    is_true(
        (bool) preg_match(
            "/get_variable\\(\\s*'zello_proxy_port'\\s*\\)\\s*\\?:\\s*8090/",
            $src
        ),
        "$name reads zello_proxy_port via get_variable() with an 8090 fallback"
    );
    is_true(
        (bool) preg_match('/\$zelloProxyPort\s*<\s*1024\s*\|\|\s*\$zelloProxyPort\s*>\s*65535/', $src),
        "$name clamps to the same 1024-65535 range proxy/zello-proxy.php enforces"
    );
    is_true(
        (bool) preg_match(
            '/<meta name="zello-proxy-port" content="<\?php echo e\(\(string\) \$zelloProxyPort\); \?>">/',
            $src
        ),
        "$name's meta tag echoes the resolved (validated) value, not the raw setting"
    );
}

// The clamp constant must match proxy/zello-proxy.php's own, or a client
// could be told to connect to a port the server itself would refuse.
$proxySrc = (string) file_get_contents($base . '/proxy/zello-proxy.php');
is_true(strpos($proxySrc, '$port < 1024 || $port > 65535') !== false,
    'proxy/zello-proxy.php still enforces the same 1024-65535 range this fix mirrors');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. The clamp/fallback logic itself, exercised against real values --\n";
// ─────────────────────────────────────────────────────────────────────────

require_once $base . '/config.php';

$resolve = function (?string $rawSetting): int {
    $v = (int) ($rawSetting ?: 8090);
    if ($v < 1024 || $v > 65535) { $v = 8090; }
    return $v;
};

$cases = [
    [null,    8090, 'unset setting falls back to 8090'],
    ['8091',  8091, 'a valid custom port is used verbatim'],
    ['80',    8090, 'a port below 1024 is clamped back to the fallback'],
    ['99999', 8090, 'a port above 65535 is clamped back to the fallback'],
    ['0',     8090, 'a zero setting falls back (falsy, same as unset)'],
    ['abc',   8090, 'a non-numeric setting casts to 0 and falls back'],
];
foreach ($cases as [$raw, $expected, $desc]) {
    is_true($resolve($raw) === $expected, $desc, "got " . $resolve($raw));
}

// And through the REAL get_variable() against a real (temporarily
// overridden) settings row, so the wiring — not just the arithmetic — is
// proven end to end. get_variable() caches every setting on its FIRST
// call for the life of the request (inc/functions.php's own documented
// behavior), so the original value is read via raw SQL here, never via
// get_variable() itself — calling get_variable() before the INSERT would
// populate its cache with the pre-test value and make every later call
// in THIS PROCESS return stale data, which is a property of reusing one
// PHP process across two states, not a bug in the fix under test. A real
// page request only ever calls get_variable() once, after the setting
// already has its current value, which is exactly what this reproduces
// by calling it here for the first and only time in this test run.
$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "SKIP: no database available — the live get_variable() round-trip was not exercised\n";
} else {
    $original = db_fetch_value("SELECT `value` FROM `settings` WHERE `name` = ?", ['zello_proxy_port']);

    try {
        db_query("DELETE FROM `settings` WHERE `name` = ?", ['zello_proxy_port']);
        db_query("INSERT INTO `settings` (`name`, `value`) VALUES (?, ?)", ['zello_proxy_port', '8091']);
        $live = (int) (get_variable('zello_proxy_port') ?: 8090);
        if ($live < 1024 || $live > 65535) { $live = 8090; }
        is_true($live === 8091, 'a real settings row round-trips through get_variable() to the resolved port',
            (string) $live);
    } finally {
        db_query("DELETE FROM `settings` WHERE `name` = ?", ['zello_proxy_port']);
        if ($original !== null && $original !== false && $original !== '') {
            db_query("INSERT INTO `settings` (`name`, `value`) VALUES (?, ?)", ['zello_proxy_port', $original]);
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. The browser side still reads the same meta tag name --\n";
// ─────────────────────────────────────────────────────────────────────────

$jsSrc = (string) file_get_contents($base . '/assets/js/zello-widget.js');
is_true(strpos($jsSrc, "meta[name=\"zello-proxy-port\"]") !== false,
    'zello-widget.js still reads meta[name="zello-proxy-port"] (the exact name both pages now render)');

echo "\n";
echo "==========================================================\n";
echo "GH#137 zello proxy port tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
