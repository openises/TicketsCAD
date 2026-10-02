<?php
/**
 * test_gh137_zello_proxy_port_meta.php — GH#137.
 *
 * assets/js/zello-widget.js has always read the proxy port from a
 * <meta name="zello-proxy-port"> tag, falling back to a hardcoded 8090
 * when it's absent -- but nothing ever rendered the tag, on EITHER page
 * that embedded the widget. Changing Settings -> Zello Network Radio ->
 * Proxy Port only ever took effect server-side (proxy/zello-proxy.php
 * reads it directly), while the browser silently kept using 8090.
 * Reported by rjonesbsink, found the hard way (a real Windows port-
 * exclusion-range conflict forced a live port change) -- and reported
 * TWICE, because the first fix (console.php only) missed that index.php
 * embedded its own independent copy of the widget markup rather than
 * sharing inc/zello-widget-template.php.
 *
 * UPDATED 2026-09-08 (Eric's live report on your-server found a
 * THIRD instance of this exact disease -- console.php had forgotten
 * zello-widget.css entirely). Rather than fix a third per-page copy, the
 * whole widget (CSS + proxy-port meta tag + template + JS) was moved into
 * inc/navbar.php, the SAME "survives page navigation" treatment
 * radio-widget.js/.css already got on 2026-06-16 -- see that file's own
 * docblock. There is now exactly ONE place this can drift: if it's ever
 * moved out of navbar.php and duplicated per-page again, the disease is
 * back. This test's assertions flipped accordingly: instead of scanning
 * every top-level page for its OWN copy (the old, now-obsolete shape),
 * it pins navbar.php as the SOLE owner and asserts no top-level page has
 * quietly grown its own independent copy again.
 */

$base = realpath(__DIR__ . '/..');

echo "=== GH#137 — Zello proxy port meta tag ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

// ─────────────────────────────────────────────────────────────────────────
echo "-- 1. inc/navbar.php is the sole owner of the widget + its meta tag --\n";
// ─────────────────────────────────────────────────────────────────────────

$navbarSrc = (string) file_get_contents($base . '/inc/navbar.php');
is_true(strpos($navbarSrc, 'zello-widget-template') !== false,
    'navbar.php includes the shared zello-widget-template.php');
is_true(strpos($navbarSrc, 'zello-widget.js') !== false,
    'navbar.php loads zello-widget.js');
is_true(strpos($navbarSrc, 'zello-widget.css') !== false,
    'navbar.php loads zello-widget.css');
is_true(strpos($navbarSrc, 'name="zello-proxy-port"') !== false,
    'navbar.php renders the proxy-port meta tag');

// No top-level page may independently embed the widget any more -- that
// per-page duplication is the exact disease this fix closes. A page is
// still allowed to LINK to the widget (e.g. console.js firing the
// zello:toggle EventBus event, or a "data-action=zello" toggle button),
// and to MENTION it in an explanatory comment (several pages now say
// "moved to inc/navbar.php") -- only a genuine re-embed (a real <script>/
// <meta>/include_once line) counts. Comments are stripped first so a
// prose mention can never trip this the way it would a naive strpos scan.
$stripComments = static function (string $src): string {
    $src = preg_replace('/<!--.*?-->/s', '', $src) ?? $src;
    $src = preg_replace('~^\s*//.*$~m', '', $src) ?? $src;
    return $src;
};
$reembedders = [];
foreach (glob($base . '/*.php') as $f) {
    $src = $stripComments((string) file_get_contents($f));
    if (strpos($src, 'zello-widget-template') !== false
        || strpos($src, 'zello-widget.js') !== false
        || strpos($src, 'name="zello-proxy-port"') !== false) {
        $reembedders[] = basename($f);
    }
}
is_true($reembedders === [],
    'no top-level page independently re-embeds the widget/template/meta-tag any more',
    implode(', ', $reembedders));

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. The rendered value traces to the real setting, with the real clamp --\n";
// ─────────────────────────────────────────────────────────────────────────

is_true(
    (bool) preg_match(
        "/get_variable\\(\\s*'zello_proxy_port'\\s*\\)\\s*\\?:\\s*8090/",
        $navbarSrc
    ),
    'navbar.php reads zello_proxy_port via get_variable() with an 8090 fallback'
);
is_true(
    (bool) preg_match('/\$__zelloProxyPort\s*<\s*1024\s*\|\|\s*\$__zelloProxyPort\s*>\s*65535/', $navbarSrc),
    'navbar.php clamps to the same 1024-65535 range proxy/zello-proxy.php enforces'
);
is_true(
    (bool) preg_match(
        '/<meta name="zello-proxy-port" content="<\?php echo e\(\(string\) \$__zelloProxyPort\); \?>">/',
        $navbarSrc
    ),
    "navbar.php's meta tag echoes the resolved (validated) value, not the raw setting"
);

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
    'zello-widget.js still reads meta[name="zello-proxy-port"] (the exact name navbar.php renders)');

echo "\n";
echo "==========================================================\n";
echo "GH#137 zello proxy port tests: {$pass} passed, {$fail} failed\n";
echo "==========================================================\n";

exit($fail > 0 ? 1 : 0);
