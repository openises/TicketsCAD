<?php
/**
 * UI-consistency gate (Eric, 2026-07-31).
 *
 * ORIGIN. A newly shipped widget was reviewed on a live system and found
 * inconsistent with every other widget in the product — hotkeys as a run of
 * text along the bottom instead of labelled keycap buttons in the header, a
 * dismiss control with no way to reopen, per-user state in a newly invented
 * store while dashboard_layouts and user_screen_prefs already existed. Eric:
 * "I do not want the software to appear as if we have multiple developers who
 * have never seen this software before or ever talked to each other, working
 * on the same codebase."
 *
 * WHY A GATE. Documentation did not stop it; the conventions were all written
 * down somewhere. What has actually caught things in this codebase is gates —
 * schema_audit, api_contract_audit, legacy_level_audit, timezone_audit. This
 * is the fifth, and it fails the suite ONLY on findings that are not already
 * in tools/ui_consistency_baseline.txt: existing debt does not block work,
 * new drift does.
 *
 * These tests drive the REAL tool (tools/ui_consistency_audit.php) against
 * fixture trees via --path, rather than re-implementing its matching here. A
 * gate that only ever runs on a clean tree proves nothing, so every detector
 * is shown to FIRE on a known-bad input and stay SILENT on the known-good
 * form of the same construct — the second half matters as much as the first,
 * because a rule that flags the correct code too is a rule that gets muted.
 *
 * Usage: php tests/test_ui_consistency_audit.php
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
$tool = $base . '/tools/ui_consistency_audit.php';

echo "=== UI-consistency audit gate ===\n\n";
$pass = 0;
$fail = 0;
function ok(string $n): void { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad(string $n, string $why = ''): void {
    global $fail; echo "[FAIL] $n" . ($why !== '' ? " — $why" : '') . "\n"; $fail++;
}
function is_true($c, string $n, string $why = ''): void { $c ? ok($n) : bad($n, $why); }

/** Run the audit against a directory; return [exitCode, output]. */
function uia_run(string $tool, string $path = ''): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool);
    if ($path !== '') { $cmd .= ' ' . escapeshellarg('--path=' . $path); }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return [$code, implode("\n", $out)];
}

// ── Fixtures live outside the repo, so a crash cannot leave drifted markup
//    behind for the next run of the audit to find in the app tree ───────────
$tmp = sys_get_temp_dir() . '/uia_fixtures_' . getmypid();
$bad = $tmp . '/bad';
$good = $tmp . '/good';
foreach ([$bad, $good] as $d) {
    @mkdir($d . '/assets/js', 0777, true);
    @mkdir($d . '/assets/css', 0777, true);
    @mkdir($d . '/inc', 0777, true);
    @mkdir($d . '/tools', 0777, true);
}
register_shutdown_function(static function () use ($tmp) {
    $rii = @scandir($tmp);
    if ($rii === false) { return; }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

// ═══════════════════════════════════════════════════════════════════════════
// KNOWN-BAD tree: one construct per detector.
// ═══════════════════════════════════════════════════════════════════════════

// The widget engine. `responders` is absent from WIDGET_ICONS, and (below)
// from the toolbar — the shape where a widget renders but cannot be titled or
// re-opened.
file_put_contents($bad . '/assets/js/widget-manager.js', <<<'JS'
(function () {
    'use strict';
    var DEFAULT_LAYOUT = [
        { id: 'incidents',  x: 0, y: 0, w: 6, h: 5 },
        { id: 'responders', x: 0, y: 5, w: 6, h: 5 }
    ];
    var WIDGET_ICONS = {
        incidents: 'bi-exclamation-triangle'
    };
    var WIDGET_LABELS_EN = {
        incidents: 'Incidents',
        responders: 'Responders'
    };
    var grid = GridStack.init({ column: 12 }, '#dashboard');
})();
JS);

// The dashboard. Note the toolbar has no `responders` button, and the form
// button carries no type=.
file_put_contents($bad . '/index.php', <<<'PHP'
<?php
$__allowedWidgets = array_values(array_filter(
    ['incidents', 'responders'],
    fn($w) => dash_can($w, $userPerms)
));
?>
<script>var DASH_WIDGET_TITLES = {incidents: 'Incidents', responders: 'Responders'};</script>
<div class="grid-stack" id="dashboard"></div>
<template id="tpl-incidents"></template>
<template id="tpl-responders"></template>
<button class="btn btn-sm btn-outline-secondary widget-toggle" data-widget="incidents"></button>
<form id="unitForm">
    <input class="form-control" name="callsign">
    <select class="form-select" name="kind"></select>
    <button class="btn btn-sm btn-outline-primary" id="btnAddSource">Add</button>
</form>
<i class="fas fa-truck"></i>
<span style="color:#334455">stale</span>
<?php include_once __DIR__ . '/inc/panel.php'; ?>
PHP);

// A hand-rolled grid tile: its own card-header, a dismiss control with no
// re-open path, a run of keycaps as footer text, and an action bar with no
// entry in the shared stylesheet.
file_put_contents($bad . '/inc/panel.php', <<<'PHP'
<div class="grid-stack-item" gs-id="stuff">
  <div class="grid-stack-item-content card">
    <div class="card-header d-flex">
      <span class="small fw-semibold">Check-Ins</span>
      <span class="net-checkin-action-bar ms-auto">
        <button class="btn btn-outline-primary" type="button">New</button>
      </span>
      <button class="btn btn-sm btn-link" type="button" id="netClose" title="Hide">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="card-body"></div>
    <div class="card-footer small">
      <kbd>&uarr;</kbd> select &middot; <kbd>a</kbd> append &middot; <kbd>e</kbd> edit
    </div>
  </div>
</div>
PHP);

// Client state that never reaches the server, plus ES5 violations. The arrow
// and the backtick inside the COMMENT and the STRING must not count — that is
// the whole point of stripping both before a syntax rule runs.
file_put_contents($bad . '/assets/js/panel.js', <<<'JS'
(function () {
    'use strict';
    // A comment mentioning => and a `backtick` must not register.
    var help = 'echo "==> installing"';
    var grow = (n) => n + 1;
    let counter = 0;
    var msg = `hello`;
    function save(cfg) {
        localStorage.setItem('panelColConfig', JSON.stringify(cfg));
    }
    return { save: save, grow: grow, counter: counter, msg: msg, help: help };
})();
JS);

// GH#130 (rjonesbsink): tools/ holds Node.js CLI scripts (require('fs'),
// process.argv) that are never served to a browser and were never meant
// to follow the ES5-no-build-step convention this audit enforces on
// assets/js/. A real arrow function + template literal here must NOT be
// flagged, the same way vendor/ and node_modules/ already aren't.
file_put_contents($bad . '/tools/gh130_probe.js', <<<'JS'
const fs = require('fs');
const grow = (n) => n + 1;
const msg = `count is ${grow(1)}`;
console.log(msg);
JS);

// A theme-blind stylesheet. The value inside the [data-bs-theme] block is the
// dark half of a correct pair and must stay silent.
file_put_contents($bad . '/assets/css/panel.css', <<<'CSS'
.panel-title {
    color: #34495e;
    background-color: #ecf0f1;
}
[data-bs-theme="dark"] .panel-ok {
    color: #ffffff;
}
CSS);

[$code, $out] = uia_run($tool, $bad);

is_true($code === 1, 'audit exits non-zero on a tree containing UI drift',
    "exit code was $code");

$expect = [
    'widget-registry: '       => 'a widget id present in some registries and not others',
    'widget-header: '         => 'a grid tile header built by hand',
    'widget-header-control: ' => 'a dismiss control in a panel header',
    'hotkey-affordance: '     => 'keycaps rendered outside the shared action bar',
    'action-bar-css: '        => 'an action bar missing from the shared selector groups',
    'theme-color: '           => 'a hardcoded colour that cannot follow the theme',
    'control-size: '          => 'a form control without its -sm variant',
    'icon-source: '           => 'an icon from a font other than Bootstrap Icons',
    'form-button-type: '      => 'a <button> in a <form> with no type=',
    'state-store: '           => 'per-user column state saved only in the browser',
    'es5: '                   => 'ES5 violations in browser JavaScript',
];
foreach ($expect as $key => $desc) {
    is_true(strpos($out, $key) !== false, "detector fires: $desc",
        "no '" . trim($key, ': ') . "' finding in output");
}

// Findings must be actionable: they have to name the thing that is wrong.
is_true(strpos($out, 'responders missing from') !== false,
    'the registry finding names the widget and the registry it is missing from');
is_true(strpos($out, 'btnAddSource') !== false,
    'the form-button finding quotes the offending button');
is_true(strpos($out, 'panelColConfig') !== false,
    'the state-store finding names the localStorage key');

// A rule that also flags the CORRECT form gets muted, so prove the exclusions.
is_true(strpos($out, '#ffffff') === false,
    'a hex inside a [data-bs-theme] block is NOT flagged',
    'the dark half of a theme pair was reported as theme-blind');
is_true(preg_match('/es5: assets\/js\/panel\.js :: \d+ x template literal/', $out) === 1,
    'the template-literal rule counts the real backtick pair only',
    'a backtick in a comment or a string was counted');
is_true(strpos($out, '2 x arrow function') === false && strpos($out, '1 x arrow function') !== false,
    'the arrow rule ignores "==>" inside a string literal',
    'a string literal was scanned as code');
is_true(strpos($out, 'gh130_probe') === false,
    'GH#130: tools/*.js is excluded from the es5 rule (CLI scripts, never browser-served)',
    'a tools/ Node.js script was scanned as browser JavaScript');

// ═══════════════════════════════════════════════════════════════════════════
// KNOWN-GOOD tree: the same constructs, done the way the product does them.
// ═══════════════════════════════════════════════════════════════════════════
file_put_contents($good . '/assets/js/widget-manager.js', <<<'JS'
(function () {
    'use strict';
    var DEFAULT_LAYOUT = [
        { id: 'incidents',  x: 0, y: 0, w: 6, h: 5 },
        { id: 'responders', x: 0, y: 5, w: 6, h: 5 }
    ];
    var WIDGET_ICONS = {
        incidents: 'bi-exclamation-triangle',
        responders: 'bi-people'
    };
    var WIDGET_LABELS_EN = {
        incidents: 'Incidents',
        responders: 'Responders'
    };
    var grid = GridStack.init({ column: 12 }, '#dashboard');
    function addWidget(item) {
        var html = '<div class="grid-stack-item-content card">'
            + '<div class="card-header py-1 px-2 d-flex align-items-center justify-content-between">'
            + '<span class="small fw-semibold">' + item.id + '</span>'
            + '<span class="responder-action-bar d-none">'
            + '<button class="btn btn-xs btn-outline-info responder-action-btn" data-resp-action="view">'
            + '<i class="bi bi-eye me-1"></i><span class="action-label">View</span><kbd>V</kbd></button>'
            + '</span>'
            + '<span class="widget-refresh text-body-secondary" data-widget="'
            + item.id + '" title="Refresh"><i class="bi bi-arrow-clockwise"></i></span>'
            + '</div></div>';
        grid.addWidget({ id: item.id, content: html });
    }
    return { addWidget: addWidget };
})();
JS);

file_put_contents($good . '/index.php', <<<'PHP'
<?php
$__allowedWidgets = array_values(array_filter(
    ['incidents', 'responders'],
    fn($w) => dash_can($w, $userPerms)
));
?>
<script>var DASH_WIDGET_TITLES = {incidents: 'Incidents', responders: 'Responders'};</script>
<div class="grid-stack" id="dashboard"></div>
<template id="tpl-incidents"></template>
<template id="tpl-responders"></template>
<button class="btn btn-sm btn-outline-secondary widget-toggle" data-widget="incidents"></button>
<button class="btn btn-sm btn-outline-secondary widget-toggle" data-widget="responders"></button>
<form id="unitForm">
    <input class="form-control form-control-sm" name="callsign">
    <select class="form-select form-select-sm" name="kind"></select>
    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddSource">Add</button>
    <button type="submit" class="btn btn-sm btn-primary">Save</button>
</form>
<i class="bi bi-truck"></i>
<!-- A comment quoting <button class="btn"> and style="color:#334455" is not markup. -->
PHP);

file_put_contents($good . '/assets/js/panel.js', <<<'JS'
(function () {
    'use strict';
    // Mentioning => and a `backtick` in a comment is fine.
    var help = 'echo "==> installing"';
    function save(cfg) {
        // localStorage is a render-speed cache ALONGSIDE the server write.
        localStorage.setItem('panelLayout', JSON.stringify(cfg));
        return fetch('api/layout.php', { method: 'POST', body: JSON.stringify(cfg) });
    }
    return { save: save, help: help };
})();
JS);

file_put_contents($good . '/assets/css/panel.css', <<<'CSS'
.responder-action-bar .btn-xs,
.incident-action-bar .btn-xs {
    padding: 0.1rem 0.35rem;
}
.responder-action-bar .btn-xs kbd,
.incident-action-bar .btn-xs kbd {
    background: rgba(var(--bs-emphasis-color-rgb), 0.08);
    color: var(--bs-body-secondary);
}
.responder-action-bar .action-label,
.incident-action-bar .action-label {
    font-size: 0.6rem;
}
.panel-title {
    color: var(--bs-body-color);
    background-color: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
}
CSS);

[$gcode, $gout] = uia_run($tool, $good);
is_true($gcode === 0, 'audit stays silent on the conventional forms',
    "exit code $gcode; output:\n" . implode("\n", array_slice(explode("\n", $gout), -25)));

// ═══════════════════════════════════════════════════════════════════════════
// The real tree: only findings the baseline already records are tolerated.
// ═══════════════════════════════════════════════════════════════════════════
[$rcode, $rout] = uia_run($tool);
$tail = implode("\n", array_slice(explode("\n", $rout), -30));
is_true($rcode === 0, 'no NEW UI-consistency drift in the app tree', $tail);

// The baseline is a record of reviewed debt, not a dumping ground: every entry
// must still correspond to a real finding, or it is silently protecting
// nothing and hiding the fact that the rule stopped matching.
$baselineFile = $base . '/tools/ui_consistency_baseline.txt';
is_true(is_file($baselineFile), 'the baseline file exists');
if (is_file($baselineFile)) {
    $entries = [];
    foreach (file($baselineFile) as $l) {
        $l = trim($l);
        if ($l !== '' && $l[0] !== '#') { $entries[] = $l; }
    }
    // --all prints baseline-listed findings too, which is the only way to see
    // whether a baseline entry still corresponds to something real.
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tool) . ' --all';
    $lines = [];
    exec($cmd . ' 2>&1', $lines);
    $live = [];
    foreach ($lines as $l) {
        if (preg_match('/^\[(?:baseline|NEW)\]\s+(.*)$/', $l, $m)) { $live[trim($m[1])] = true; }
    }
    $stale = array_values(array_filter($entries, static fn($e) => !isset($live[$e])));
    is_true($stale === [], 'every baseline entry still matches a real finding',
        count($stale) . ' stale entry(ies), e.g. ' . implode(' | ', array_slice($stale, 0, 3)));
    is_true(count($entries) > 0, 'the baseline records the drift that exists today',
        'an empty baseline with a non-empty tree means the rules stopped matching');
}

// The tool must refuse to run under a web SAPI — every script under tools/ is
// reachable over HTTP on a default install until the web server is hardened.
foreach (['tools/ui_consistency_audit.php', 'tools/ui_extract.php'] as $rel) {
    $src = (string) file_get_contents($base . '/' . $rel);
    is_true(
        strpos($src, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false,
        "$rel carries the canonical CLI-only guard"
    );
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
