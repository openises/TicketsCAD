<?php
/**
 * GH#49 — no way to rotate feed_api_key once one is configured
 *
 * The "Generate" button only exists inside #feedKeyMissingBanner, which
 * updateFeedKeyBanner() hides the moment a key is present, so there was no
 * path back to it once configured. Adds a standing "Regenerate" button next
 * to the key field, always visible, confirming before it touches the field
 * since regenerating invalidates whatever's currently consuming the feed.
 *
 * Static guards (behavioral JS + a PHP-rendered form; no headless DOM here).
 * Usage: php tests/test_gh49_feed_key_regenerate.php
 */
$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== GH#49 — feed_api_key Regenerate control ===\n\n";

$settings = rd($base . '/settings.php');
t('settings.php has a #btnRegenerateFeedKey control',
    strpos($settings, 'id="btnRegenerateFeedKey"') !== false);

// Position-based rather than one brittle nested-<div> regex: the banner's
// OWN generate button (btnGenerateFeedKey) must come before Regenerate, and
// the gap between them must be small enough that Regenerate is clearly a
// sibling control near the input, not still inside the banner markup.
$posBannerBtn = strpos($settings, 'id="btnGenerateFeedKey"');
$posInput     = strpos($settings, 'id="setFeedApiKey"');
$posRegenBtn  = strpos($settings, 'id="btnRegenerateFeedKey"');
t('the Regenerate button comes after the banner\'s own Generate button and the key input (not nested inside the banner)',
    $posBannerBtn !== false && $posInput !== false && $posRegenBtn !== false
    && $posBannerBtn < $posInput && $posInput < $posRegenBtn);
t('the Regenerate button sits in the key input-group, next to reveal/copy',
    $posInput !== false && $posRegenBtn !== false && ($posRegenBtn - $posInput) < 1600);

$config = rd($base . '/assets/js/config.js');
t('config.js wires btnRegenerateFeedKey',
    strpos($config, "getElementById('btnRegenerateFeedKey')") !== false);
t('Regenerate confirms before touching the field (regenerating invalidates the current key)',
    (bool) preg_match(
        "/btnRegenerateFeedKey'\\)[\\s\\S]{0,700}?if \\(!confirm\\([\\s\\S]{0,300}?\\)\\) return;[\\s\\S]{0,200}?randomHex\\(48\\)/",
        $config
    ));
t('Regenerate fills the field but does not auto-submit (still requires Save)',
    (bool) preg_match("/btnRegenerateFeedKey'\\)[\\s\\S]{0,700}?input\\.value = randomHex\\(48\\);/", $config)
    && !(bool) preg_match("/btnRegenerateFeedKey'\\)[\\s\\S]{0,900}?form\\.submit\\(\\)/", $config));
t('the confirm message differs when a key is already stored vs. not (honest about the blast radius)',
    strpos($config, '_feedKeyStored') !== false
    && (bool) preg_match('/btnRegenerateFeedKey[\s\S]{0,600}_feedKeyStored\s*\?/', $config));

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
