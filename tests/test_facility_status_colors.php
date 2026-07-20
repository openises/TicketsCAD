<?php
/**
 * GH #49 — facility status colours must be honoured on EVERY surface that
 * shows facility status. The bug recurred four times because each surface
 * reimplemented the render (plain text / hardcoded colour map / wrong field
 * names). This locks in the single shared helper + its use everywhere, and
 * the two APIs' field-name contracts the helper must both satisfy.
 *
 * Usage: php tests/test_facility_status_colors.php
 */
$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== GH #49 — facility status colours everywhere ===\n\n";

// ── The shared helper exists + handles BOTH endpoints' field names ───────────
$h = rd($base . '/assets/js/facility-status.js');
t('shared helper exists (window.FacilityStatus)', $h !== '' && strpos($h, 'window.FacilityStatus') !== false);
t('helper accepts bg_color OR status_bg (endpoint-agnostic — the root cause)',
    strpos($h, 'f.bg_color   || f.status_bg') !== false &&
    strpos($h, 'f.text_color || f.status_text') !== false);
t('helper decides "has status" off the LABEL, never the colour',
    strpos($h, 'f.status_name || f.status_val') !== false &&
    preg_match('/if \(!label\)/', $h) === 1);
t('helper exposes bits() + badge() + cell()',
    strpos($h, 'bits: bits') !== false && strpos($h, 'badge: badge') !== false &&
    strpos($h, 'cell: cell') !== false);
// The dashboard must match the Responders widget's FILLED-CELL style
// (Eric 2026-07-06: not a pill). cell() paints the whole <td>.
$appJs = rd($base . '/assets/js/app.js');
t('dashboard facilities widget uses the filled-cell style (matches responders)',
    strpos($appJs, 'window.FacilityStatus.cell(f)') !== false &&
    strpos($h, 'font-weight:600;text-align:center;border-radius:3px') !== false);

// ── The two APIs' field-name contracts (both must keep working) ──────────────
$apiList = rd($base . '/api/facilities.php');
t('api/facilities.php sends bg_color + text_color per facility',
    strpos($apiList, "'bg_color'") !== false && strpos($apiList, "'text_color'") !== false);
$apiDetail = rd($base . '/api/facility-detail.php');
t('api/facility-detail.php sends status_bg + status_text (aliased)',
    strpos($apiDetail, "'status_bg'") !== false && strpos($apiDetail, "'status_text'") !== false);

// ── Every render surface routes through the helper ───────────────────────────
$surfaces = [
    'dashboard widget (app.js)'      => ['js' => 'assets/js/app.js',            'page' => 'index.php'],
    'Facilities page (facilities.js)'=> ['js' => 'assets/js/facilities.js',      'page' => 'facilities.php'],
    'facility board (facility-board.js)' => ['js' => 'assets/js/facility-board.js','page' => 'facility-board.php'],
    'facility detail (facility-detail.js)' => ['js' => 'assets/js/facility-detail.js','page' => 'facility-detail.php'],
];
foreach ($surfaces as $label => $paths) {
    $js   = rd($base . '/' . $paths['js']);
    $page = rd($base . '/' . $paths['page']);
    t($label . ' uses window.FacilityStatus', strpos($js, 'window.FacilityStatus') !== false);
    t($label . ' loads facility-status.js before its own JS',
        strpos($page, 'assets/js/facility-status.js') !== false);
}
// situation.php is inline JS — helper loaded + facStatusBits delegates.
$sit = rd($base . '/situation.php');
t('situation.php loads the helper + facStatusBits delegates to it',
    strpos($sit, 'assets/js/facility-status.js') !== false &&
    strpos($sit, 'window.FacilityStatus.bits(f)') !== false);

// ── The specific per-surface bugs must be gone ───────────────────────────────
$app = rd($base . '/assets/js/app.js');
t('dashboard no longer renders status as plain esc(status_name) text',
    strpos($app, "'<td>' + esc(f.status_name") === false);
$fb = rd($base . '/assets/js/facility-board.js');
t('facility board badge no longer keys off the hardcoded open/closed/full class only',
    strpos($fb, 'window.FacilityStatus.badge(fac') !== false);
$fd = rd($base . '/assets/js/facility-detail.js');
t('facility detail no longer silently greys out (reads via helper, both field names)',
    strpos($fd, 'window.FacilityStatus') !== false);

// ── widgets.css cache-buster (the reason earlier fixes appeared not to land) ──
$idx = rd($base . '/index.php');
t('index.php cache-busts widgets.css (stale-CSS was masking prior fixes)',
    strpos($idx, 'widgets.css?v=') !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
