<?php
/**
 * Unit Edit — return-to-list + visible save success (Eric, 2026-07-05).
 *
 * The edit page had no back-to-Units affordance (the view page has a "← Units"
 * button) and the save-success message scrolled behind the fixed navbar out of
 * view. This adds a "← Units" title-bar button (consistent with unit-detail.php)
 * and makes an existing-unit save show a persistent success WITH a "Back to
 * Units" action, scrolled to the top so it's visible.
 *
 * Static guards. Usage: php tests/test_unit_edit_nav.php
 */
$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Unit Edit — return-to-list + visible save success ===\n\n";

$edit   = rd($base . '/unit-edit.php');
$detail = rd($base . '/unit-detail.php');
$js     = rd($base . '/assets/js/unit-edit.js');

// ── Consistency: the edit page has a "← Units" back button like the view page ──
t('unit-edit.php has a "← Units" back button (arrow + href=units.php)',
    $edit !== false &&
    (bool) preg_match('/id="btnBackUnits"[\s\S]{0,120}bi-arrow-left/', $edit) &&
    (bool) preg_match('/<a href="units\.php"[^>]*id="btnBackUnits"/', $edit));
t('unit-detail.php (view) has the matching "← Units" back button',
    $detail !== false &&
    (bool) preg_match('/<a href="units\.php"[\s\S]{0,120}bi-arrow-left/', $detail));

// ── Save success on an EXISTING unit stays visible + offers a way back ──
t('save success shows a persistent "Back to Units" action',
    $js !== false && (bool) preg_match('/Back to Units[\s\S]{0,40}<\/a>/', $js) &&
    strpos($js, "href=\"units.php\"") !== false);
t('save success jumps to the top so the message is visible (not hidden by navbar)',
    $js !== false && (bool) preg_match('/window\.scrollTo\(\{\s*top:\s*0/', $js));
t('new-unit save still redirects to the detail page (unchanged)',
    $js !== false && strpos($js, "window.location.href = 'unit-detail.php?id=' + result.id") !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
