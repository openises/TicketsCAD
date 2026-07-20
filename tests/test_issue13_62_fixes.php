<?php
/**
 * GH #13 (CAD→mobile real-time) + GH #62 (type icon picker) — wiring guards.
 * The SSE 'entitled' scope's DATA behaviour is covered in
 * tests/test_security_f007_sse_visibility.php; this pins the wiring on both
 * sides plus the icon-picker surfaces.
 *
 * Usage: php tests/test_issue13_62_fixes.php
 */
$base   = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return (string) @file_get_contents($p); }

echo "=== GH #13 mobile SSE + GH #62 icon picker ===\n\n";

// ── #13: publisher — no-allocates fallback is 'entitled', not 'admin' ────────
$sse = rd($base . '/inc/sse.php');
t("sse.php allows the 'entitled' scope",
    strpos($sse, "'entitled'") !== false && strpos($sse, "'public', 'admin', 'group', 'user', 'entitled'") !== false);
t("no-allocates fallbacks publish 'entitled' (incident + responder)",
    substr_count($sse, "sse_publish(\$eventType, \$payload, \$userId, 'entitled')") === 2);

// ── #13: subscriber — stream grants entity events to RBAC view holders ───────
$stream = rd($base . '/api/stream.php');
t('stream.php computes RBAC entitlement BEFORE session_write_close',
    strpos($stream, '$entitledPrefixes') !== false &&
    strpos($stream, 'session_write_close') > strpos($stream, '$entitledPrefixes = ['));
t('stream.php entitlement map mirrors inc/access.php permission lists',
    strpos($stream, "'incident:%'") !== false && strpos($stream, "'responder:%'") !== false &&
    strpos($stream, "'chat:%'") !== false &&
    strpos($stream, "'screen.incidents'") !== false && strpos($stream, "'screen.units'") !== false);
t('admins receive entitled events; non-admin RBAC holders via prefix clause',
    strpos($stream, "IN ('admin','group','entitled')") !== false &&
    strpos($stream, "IN ('group','entitled') AND `event_type` LIKE ?") !== false);
// mobile.js must still subscribe to the real event names (regression from the
// earlier #13 rounds — these are the events the entitled scope now delivers).
$mob = rd($base . '/assets/js/mobile.js');
t('mobile.js subscribes to incident:note + responder:status',
    strpos($mob, "'incident:note'") !== false && strpos($mob, "'responder:status'") !== false);

// ── #62: type icon picker ────────────────────────────────────────────────────
$ti = rd($base . '/assets/js/type-icons.js');
t('type-icons.js exists with the index→glyph map + classFor + picker init',
    strpos($ti, 'window.TypeIcons') !== false && strpos($ti, 'function classFor') !== false &&
    strpos($ti, 'data-type-icon-picker') !== false && strpos($ti, 'bi-geo-alt-fill') !== false);
$set = rd($base . '/settings.php');
t('settings.php: both icon fields are visual pickers with live preview',
    substr_count($set, 'data-type-icon-picker') === 2 &&
    strpos($set, 'data-type-icon-preview-for="unitTypeIcon"') !== false &&
    strpos($set, 'data-type-icon-preview-for="facTypeIcon"') !== false &&
    strpos($set, 'assets/js/type-icons.js') !== false);
t('settings.php: the bare "Icon Index" number inputs are gone',
    strpos($set, 'Numeric icon index used for map markers') === false);
$cfg = rd($base . '/assets/js/config.js');
t('type list tables render the glyph (not a bare number) + previews refresh',
    substr_count($cfg, 'window.TypeIcons.classFor') >= 2 &&
    substr_count($cfg, "dispatchEvent(new Event('change', { bubbles: true }))") >= 2);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
