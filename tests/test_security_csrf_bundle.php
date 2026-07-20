<?php
/**
 * Bundled regression for the smaller F-008 / F-009 / F-011 / F-012 / F-013 fixes
 * plus F-014 (org strict isolation toggle).
 *
 * Each section validates that the relevant endpoint now calls csrf_verify()
 * before any mutating action. Source-grep tests are sufficient at this layer
 * because csrf_verify() itself is exercised by tests/test_security.php.
 */

require __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Bundled CSRF + F-014 Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── F-008 import-export.php ──
$src = file_get_contents($base . '/api/import-export.php');
if (strpos($src, "\$method === 'POST'") !== false
    && strpos($src, 'csrf_verify') !== false
    && strpos($src, 'handleImExPost()') !== false) {
    ok('F-008 import-export.php verifies CSRF on POST');
} else {
    bad('F-008 import-export.php verifies CSRF on POST');
}

// CSRF check fires before handleImExPost() runs the action
$csrfPos    = strpos($src, 'csrf_verify');
$dispatchPos = strpos($src, 'handleImExPost()');
if ($csrfPos !== false && $dispatchPos !== false && $csrfPos < $dispatchPos) {
    ok('F-008 CSRF check runs before handleImExPost()');
} else {
    bad('F-008 CSRF check runs before handleImExPost()');
}

// ── F-009 callsign-lookup.php ──
$src = file_get_contents($base . '/api/callsign-lookup.php');
if (strpos($src, 'csrf_verify') !== false
    && strpos($src, "callsign_provider") !== false
    && strpos($src, "fcc_uls_api_url") !== false) {
    ok('F-009 callsign-lookup.php verifies CSRF on config save');
} else {
    bad('F-009 callsign-lookup.php verifies CSRF on config save');
}
// SSRF blocklist for known metadata endpoints
if (strpos($src, '169.254.169.254') !== false
    && strpos($src, 'metadata.google.internal') !== false) {
    ok('F-009 callsign-lookup.php blocks cloud-metadata SSRF targets');
} else {
    bad('F-009 callsign-lookup.php blocks cloud-metadata SSRF targets');
}

// ── F-011 layout.php ──
$src = file_get_contents($base . '/api/layout.php');
if (strpos($src, 'csrf_verify') !== false
    && strpos($src, "REQUEST_METHOD'], ['POST', 'DELETE']") !== false) {
    ok('F-011 layout.php verifies CSRF on POST and DELETE');
} else {
    bad('F-011 layout.php verifies CSRF on POST and DELETE');
}

// JS caller updated
$js = file_get_contents($base . '/assets/js/widget-manager.js');
if (strpos($js, 'getCsrfToken') !== false
    && strpos($js, 'csrf_token: csrf') !== false) {
    ok('F-011 widget-manager.js sends csrf_token in layout requests');
} else {
    bad('F-011 widget-manager.js sends csrf_token in layout requests');
}

// ── F-012 theme.php ──
$src = file_get_contents($base . '/api/theme.php');
if (strpos($src, 'csrf_verify') !== false) {
    ok('F-012 theme.php verifies CSRF on POST');
} else {
    bad('F-012 theme.php verifies CSRF on POST');
}
foreach (['theme-manager.js', 'roster.js', 'toolbar.js'] as $f) {
    $js = file_get_contents($base . '/assets/js/' . $f);
    if (strpos($js, 'api/theme.php') !== false
        && strpos($js, "'X-CSRF-Token'") !== false) {
        ok("F-012 $f sends X-CSRF-Token on theme POST");
    } else {
        bad("F-012 $f sends X-CSRF-Token on theme POST");
    }
}

// ── F-013 compliance.php ──
$src = file_get_contents($base . '/api/compliance.php');
if (strpos($src, 'csrf_verify') !== false
    && strpos($src, "action === 'snooze'") !== false) {
    ok('F-013 compliance.php verifies CSRF on snooze POST');
} else {
    bad('F-013 compliance.php verifies CSRF on snooze POST');
}
$js = file_get_contents($base . '/assets/js/app.js');
if (strpos($js, 'api/compliance.php?action=snooze') !== false
    && strpos($js, 'csrf_token:') !== false) {
    ok('F-013 app.js _snoozeAlert sends csrf_token');
} else {
    bad('F-013 app.js _snoozeAlert sends csrf_token');
}

// ── F-014 org strict isolation toggle ──
// Phase 99j (2026-06-29) centralized org scoping into inc/org-scope.php's
// org_query_filter(); api/incident-list.php consumes it. The
// org_strict_isolation setting is honoured inside the helper now
// (restored 2026-07-07 — the refactor had briefly orphaned the setting).
$src   = file_get_contents($base . '/api/incident-list.php');
$scope = file_get_contents($base . '/inc/org-scope.php');
if (strpos($src, "org_query_filter('t.org_id')") !== false
    && strpos($scope, 'function org_strict_isolation_enabled') !== false
    && strpos($scope, "get_variable('org_strict_isolation')") !== false) {
    ok('F-014 incident-list.php supports strict org isolation via setting');
} else {
    bad('F-014 incident-list.php supports strict org isolation via setting');
}

// Default behavior (setting absent / 0) keeps the legacy NULL fall-through
if (strpos($scope, '((int) ($val ?? 0)) === 1') !== false
    && strpos($scope, 'OR $column IS NULL') !== false) {
    ok('F-014 default (no setting) preserves legacy behavior');
} else {
    bad('F-014 default (no setting) preserves legacy behavior');
}

// Strict-mode branch does NOT include the "OR org_id IS NULL" fall-through
if (preg_match('/if\s*\(org_strict_isolation_enabled\(\)\)\s*\{([^{}]|\{[^{}]*\})*IN\s*\(\$placeholders\)/s', $scope)
    && !preg_match('/if\s*\(org_strict_isolation_enabled\(\)\)\s*\{([^{}]|\{[^{}]*\})*IS NULL/s', $scope)) {
    ok('F-014 strict-mode WHERE clause omits the legacy NULL fall-through');
} else {
    bad('F-014 strict-mode WHERE clause omits the legacy NULL fall-through');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
