<?php
/**
 * Command bar — help text must not be pre-encoded (Eric, 2026-08-14,
 * screenshot report: /net's suggestion showed the literal text
 * "&lt;id&gt; &lt;note&gt;" instead of "<id> <note>").
 *
 * Root cause: renderSuggestions() builds each row as an HTML string and
 * sets it via innerHTML, correctly calling escapeHtml(cmd.description) once
 * at render time. Three command descriptions (/s, /z, /net) had their angle
 * brackets pre-encoded as literal "&lt;"/"&gt;" text in the JS source.
 * escapeHtml() encodes '&' first, so calling it on an already-encoded
 * string turns "&lt;" into "&amp;lt;" -- which the browser then renders as
 * the literal text "&lt;" (one decode, not two), exactly matching the
 * screenshot. The fix is to keep raw '<'/'>' in the source description
 * strings and let escapeHtml() encode them exactly once at render time.
 *
 * Usage: php tests/test_command_bar_help_text_encoding.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== Command bar help text must not be pre-encoded ===\n\n";

$src = @file_get_contents($base . '/assets/js/command-bar.js');
if ($src === false) { t('assets/js/command-bar.js readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// Isolate just the description: '...' string literals so this can't
// false-positive on unrelated &lt;/&gt; use elsewhere in the file (e.g. a
// genuine escapeHtml() call site, or HTML markup that legitimately needs
// entities).
preg_match_all("/description:\s*'((?:[^'\\\\]|\\\\.)*)'/", $src, $m);
$descriptions = $m[1] ?? [];
t('found at least one command description to check', count($descriptions) > 0);

foreach ($descriptions as $i => $desc) {
    t("description #{$i} has no pre-encoded HTML entities: " . substr($desc, 0, 60),
        strpos($desc, '&lt;') === false && strpos($desc, '&gt;') === false && strpos($desc, '&amp;') === false);
}

// The three commands from the report, specifically: their raw source
// strings must contain real angle brackets, matching what a user should
// see on screen after escapeHtml() renders them once.
$expectations = [
    'status' => "description: 'Change unit status — /s <handle> <status>'",
    'zone'   => "description: 'Set a unit\\'s event zone — /z <team> <zone>'",
    'net'    => "description: 'Capture net check-ins — /net <id> <note> / <id> <note>'",
];
foreach ($expectations as $cmd => $needle) {
    t("/{$cmd}'s description literal is present with raw angle brackets", strpos($src, $needle) !== false);
}

// The render path itself: renderSuggestions() must call escapeHtml() on
// cmd.description exactly once (not pre-escaped upstream, not double
// -escaped downstream) before it reaches innerHTML.
t('renderSuggestions() escapes cmd.description exactly once via escapeHtml()',
    (bool) preg_match('/escapeHtml\(cmd\.description\)/', $src)
    && substr_count($src, 'escapeHtml(cmd.description)') === 1);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
