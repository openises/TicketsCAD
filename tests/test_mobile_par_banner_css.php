<?php
/**
 * Regression: the PAR (Personnel Accountability) floating-banner styles must
 * live in a stylesheet that mobile.php actually loads.
 *
 * Bug (Eric, 2026-06-23): mobile.php links only assets/css/mobile-unit.css, but
 * the .par-banner* rules lived only in assets/css/mobile.css (NOT loaded by
 * mobile.php). When a PAR cycle was pending, mobile.js removed d-none on
 * #parBanner and it un-hid as an unstyled static block (position:static,
 * transparent) that shifted the page and looked broken.
 *
 * This test parses mobile.php's <link> stylesheets, concatenates the local CSS
 * it actually loads, and asserts every .par-banner* rule (+ the keyframes, the
 * body.par-banner-active rule, and the dark-theme variant) is present. It is
 * file-location-agnostic: copy/move the rules wherever you like, as long as a
 * stylesheet mobile.php loads contains them.
 */

$base = realpath(__DIR__ . '/..');

echo "=== Mobile PAR banner CSS regression ===\n\n";
$pass = 0; $fail = 0;
function ok($n)         { global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n, $w='') { global $fail; echo "[FAIL] $n" . ($w ? " — $w" : "") . "\n"; $fail++; }

$mobile = @file_get_contents($base . '/mobile.php');
if ($mobile === false) { bad('read mobile.php'); echo "\n=== 0 passed, 1 failed ===\n"; exit(1); }

// Collect the local stylesheets mobile.php links (strip any ?v=… cache buster).
preg_match_all(
    '/<link[^>]+rel=["\']stylesheet["\'][^>]+href=["\']([^"\']+\.css)(?:\?[^"\']*)?["\']/i',
    $mobile, $m
);
$hrefs = array_unique($m[1]);
if (count($hrefs)) ok('mobile.php links ' . count($hrefs) . ' stylesheet(s): ' . implode(', ', $hrefs));
else bad('mobile.php links no stylesheets');

// Concatenate the CSS mobile.php actually loads (local files only).
$css = '';
foreach ($hrefs as $h) {
    $p = $base . '/' . ltrim($h, '/');
    if (is_file($p)) $css .= "\n" . file_get_contents($p);
}

// Every PAR-banner selector / keyframe that must be styled where mobile.php loads.
$need = [
    '.par-banner {'         => 'the floating banner container (position:fixed)',
    '.par-banner-row'       => 'the banner row',
    '.par-banner-icon'      => 'the banner icon',
    '.par-banner-title'     => 'the banner title',
    '.par-banner-sub'       => 'the banner subtitle',
    '.par-banner-ack'       => 'the Ack button',
    '.par-banner-form'      => 'the ack form',
    '@keyframes par-slide-down' => 'the slide-down keyframes',
    'body.par-banner-active'    => 'the .mobile-main padding offset',
];
foreach ($need as $needle => $desc) {
    if (strpos($css, $needle) !== false) ok("loaded CSS defines $needle ($desc)");
    else bad("loaded CSS MISSING $needle", 'PAR banner renders unstyled on mobile.php');
}

// The dark-theme variant must come along too.
if (preg_match('/\[data-bs-theme="dark"\]\s*\.par-banner\b/', $css)) ok('dark-theme .par-banner variant present');
else bad('dark-theme .par-banner variant missing');

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
