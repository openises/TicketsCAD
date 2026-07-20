<?php
/**
 * F-002 regression — feed.php must not fail open when feed_api_key is unset.
 *
 * Validates the hardening applied 2026-05-04: the previous "no key configured
 * → open feed" branch is removed; the feed now requires either a key or a
 * logged-in session.
 */

require __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

echo "=== F-002 Feed Fail-Open Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

$src = file_get_contents($base . '/api/feed.php');

// ── 1. The fail-open branch must be gone ──
if (preg_match('/No API key configured\s*[—-].*open/i', $src)) {
    bad('feed.php removed "feed is open" branch', 'comment still present, code may still fail open');
} else {
    ok('feed.php removed "feed is open" comment');
}

// The pre-fix code unconditionally set $authenticated = true in the else branch
if (preg_match('/}\s*else\s*{\s*\/\/[^\n]*open[^\n]*\n\s*\$authenticated\s*=\s*true/i', $src)) {
    bad('feed.php removed unconditional $authenticated=true on missing key', 'pre-fix branch still present');
} else {
    ok('feed.php removed unconditional $authenticated=true on missing key');
}

// ── 2. The "fail closed" comment is present ──
if (stripos($src, 'fail-closed') !== false || stripos($src, 'fail closed') !== false) {
    ok('feed.php documents the fail-closed posture');
} else {
    bad('feed.php documents the fail-closed posture');
}

// ── 3. hash_equals is still used to compare keys (timing-safe) ──
if (strpos($src, 'hash_equals') !== false) {
    ok('feed.php uses hash_equals for key comparison');
} else {
    bad('feed.php uses hash_equals for key comparison');
}

// ── 4. Header-based key delivery is supported (X-Feed-Key) ──
if (strpos($src, 'HTTP_X_FEED_KEY') !== false) {
    ok('feed.php accepts X-Feed-Key header in addition to ?key=');
} else {
    bad('feed.php accepts X-Feed-Key header');
}

// ── 5. Session fallback is preserved (so logged-in admin can test) ──
if (strpos($src, "session_start") !== false
    && strpos($src, "\$_SESSION['user_id']") !== false) {
    ok('feed.php still allows session fallback for logged-in users');
} else {
    bad('feed.php still allows session fallback for logged-in users');
}

// ── 6. The 401 message tells admins how to enable the feed when no key set ──
if (strpos($src, 'feed_api_key') !== false
    && (stripos($src, 'disabled until') !== false || stripos($src, 'Settings') !== false)) {
    ok('feed.php 401 message guides admin to set feed_api_key');
} else {
    bad('feed.php 401 message guides admin to set feed_api_key');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
