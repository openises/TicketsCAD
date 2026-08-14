<?php
/**
 * test_a11y_skip_target.php — assets/js/a11y.js's ensureSkipTarget().
 *
 * Found 2026-08-09, fixed 2026-08-14 (Eric: "why not fix it now?"): on any
 * page without a real <main> element, ensureSkipTarget()'s fallback walked
 * `header ~ .container, header ~ .container-fluid, header ~ div` and only
 * excluded `.alert`/`role=alert` bars — not the command bar
 * (`inc/navbar.php`'s `<div class="command-bar" id="commandBar">`), a
 * `header ~ div` sibling that sits ahead of real page content in document
 * order. The fallback stamped `id="main-content"` onto it, renaming the
 * command bar's own id out from under any code that looks it up by id.
 *
 * Drives the REAL assets/js/a11y.js via node — stubbing only the DOM
 * primitives it touches, not re-implementing ensureSkipTarget()'s logic —
 * so a future regression in the actual exclusion list fails here, not just
 * a grep for the string ".command-bar".
 *
 * Usage: php tests/test_a11y_skip_target.php
 */

$pass = 0; $fail = 0;
function ok(string $name): void { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void { global $fail; echo "[FAIL] $name" . ($why !== '' ? " — $why" : '') . "\n"; $fail++; }
function is_true(bool $cond, string $name, string $why = ''): void { $cond ? ok($name) : bad($name, $why); }

echo "=== a11y.js — ensureSkipTarget() ===\n\n";

$base = realpath(__DIR__ . '/..');

// ── Structural: the fix is actually present in source ───────────────────
$src = (string) file_get_contents($base . '/assets/js/a11y.js');
is_true(strpos($src, "sibs[i].classList.contains('command-bar')") !== false,
    'source excludes .command-bar from the skip-target fallback');
is_true(strpos($src, "sibs[i].classList.contains('alert')") !== false,
    'source still excludes .alert too (the original Phase 118 fix)');

// This fix is worthless to anyone whose browser already cached the
// pre-fix a11y.js under the same bare ?v=<version> URL -- and
// your-server.example.com sits behind a 4-hour Cloudflare edge cache
// keyed on the URL alone (tests/test_map_prefs_cache_busting.php hit the
// identical class of bug for map-prefs.js). a11y.js must load via
// asset_v() (mtime-based), not a bare newui_version() call, so a
// content-only change actually changes the URL.
$navbarSrc = (string) file_get_contents($base . '/inc/navbar.php');
is_true(
    (bool) preg_match("/loadGlobal\\('assets\\/js\\/a11y\\.js\\?v=<\\?php echo asset_v\\('assets\\/js\\/a11y\\.js'\\); \\?>'/", $navbarSrc),
    'inc/navbar.php loads a11y.js through asset_v(), not a bare version string (cache-busts on content change)'
);

// ── Functional: drive the real file via node ─────────────────────────────
$node = null;
foreach (['node', 'node.exe'] as $cand) {
    $probe = @shell_exec($cand . ' --version 2>&1');
    if (is_string($probe) && preg_match('/^v\d+/', trim($probe))) { $node = $cand; break; }
}

if ($node === null) {
    echo "SKIP: node not available — the JS execution checks were not run\n";
} else {
    $harness = sys_get_temp_dir() . '/tcad_a11y_harness_' . getmypid() . '.js';
    $js = <<<'JS'
// Drive the REAL assets/js/a11y.js. Stub only the DOM primitives it
// touches so the logic under test is production code, not a
// re-implementation of ensureSkipTarget()'s exclusion rules.
var fs = require('fs');
var out = [];
function check(name, cond, detail) { out.push((cond ? 'PASS|' : 'FAIL|') + name + '|' + (detail || '')); }

function makeEl(cls, attrs) {
    attrs = attrs || {};
    var classes = (cls || '').split(' ').filter(Boolean);
    var el = {
        id: '',
        tagName: 'DIV',
        _attrs: attrs,
        classList: { contains: function (c) { return classes.indexOf(c) !== -1; } },
        getAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
        setAttribute: function (k, v) { attrs[k] = v; },
        hasAttribute: function (k) { return Object.prototype.hasOwnProperty.call(attrs, k); }
    };
    return el;
}

// Realistic document order for a page with no <main>: the pending-
// migrations alert renders first (inc/navbar.php), then the command bar
// (also inc/navbar.php, right after it), then the page's own content div —
// exactly the shape that let the command bar win the old, unfenced fallback.
var alertBar   = makeEl('alert', { role: 'alert' });
var commandBar = makeEl('command-bar d-none', {});
var contentDiv = makeEl('container', {});
var fallbackSibs = [alertBar, commandBar, contentDiv];

global.document = {
    readyState: 'complete',
    body: {},
    getElementById: function (id) { return null; }, // no pre-existing #main-content
    querySelector: function (sel) {
        if (sel === 'main') return null; // force the fallback path
        return null;
    },
    querySelectorAll: function (sel) {
        if (sel === '[data-bs-toggle]') return [];
        if (sel === 'header ~ .container, header ~ .container-fluid, header ~ div') return fallbackSibs;
        return [];
    },
    addEventListener: function () {}
};
global.window = global;
// MutationObserver left undefined -- observe() guards on this and returns,
// matching a real browser only in that it must not throw either way.

eval(fs.readFileSync(process.argv[2], 'utf8'));

check('command bar was NOT chosen as the skip target', commandBar.id !== 'main-content', 'commandBar.id=' + commandBar.id);
check('alert bar was NOT chosen as the skip target', alertBar.id !== 'main-content', 'alertBar.id=' + alertBar.id);
check('the real content div WAS chosen instead', contentDiv.id === 'main-content', 'contentDiv.id=' + contentDiv.id);
check('command bar keeps its own id untouched', commandBar.id === '', 'commandBar.id=' + commandBar.id);
check('chosen target got tabindex=-1 for programmatic focus', contentDiv._attrs.tabindex === '-1');

console.log(out.join('\n'));
JS;
    file_put_contents($harness, $js);
    $a11yPath = str_replace('\\', '/', $base . '/assets/js/a11y.js');
    $raw = @shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($a11yPath) . ' 2>&1');
    @unlink($harness);

    if (!is_string($raw) || strpos($raw, '|') === false) {
        bad('node harness ran a11y.js', trim((string) $raw));
    } else {
        foreach (explode("\n", trim($raw)) as $line) {
            $parts = explode('|', $line, 3);
            if (count($parts) < 2) continue;
            list($status, $name) = $parts;
            $detail = $parts[2] ?? '';
            is_true($status === 'PASS', $name, $detail);
        }
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
