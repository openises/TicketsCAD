<?php
/**
 * Security audit follow-up — IDOR sweep on the 10 endpoints flagged in
 * `endpoint-inventory.md`. Verifies each endpoint either:
 *   (a) Calls user_can_access_entity() before any DB read/write on a
 *       resource referenced by an ID parameter, OR
 *   (b) Is documented as requiring only auth (reference data) or admin
 *       (aggregate / mutating endpoints).
 */

$base = realpath(__DIR__ . '/..');

echo "=== IDOR Sweep — security audit follow-up ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// Strip PHP comments from a source string so substring checks match the
// active code path (post-fix comments often reference old patterns for
// historical context).
function code_only(string $src): string {
    $src = preg_replace('!//[^\n]*!', '', $src);
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    return $src;
}

// ── facility-detail.php ──
$src = code_only(file_get_contents($base . '/api/facility-detail.php'));
if (strpos($src, "user_can_access_entity('facility'") !== false
    && strpos($src, '/inc/access.php') !== false) {
    ok('facility-detail.php applies user_can_access_entity(facility, id)');
} else {
    bad('facility-detail.php IDOR check');
}
if (preg_match('/json_error\([^,]+,\s*404\)/', $src)) {
    ok('facility-detail.php returns 404 (not 403) on access denial');
} else {
    bad('facility-detail.php returns 404 (rule #27)');
}

// ── facility-save.php (POST update + DELETE) ──
$src = code_only(file_get_contents($base . '/api/facility-save.php'));
if (preg_match_all("/user_can_access_entity\('facility'/", $src) >= 2) {
    ok('facility-save.php IDOR check on UPDATE and DELETE paths');
} else {
    bad('facility-save.php IDOR check on UPDATE and DELETE paths');
}
// CSRF on DELETE (was missing pre-audit) — find the DELETE block and
// confirm a csrf_verify call appears within it.
if (preg_match('/REQUEST_METHOD.{0,10}DELETE.{0,400}csrf_verify/s', $src)) {
    ok('facility-save.php DELETE verifies CSRF');
} else {
    bad('facility-save.php DELETE verifies CSRF');
}

// ── facility-capacity.php (GET + POST) ──
$src = code_only(file_get_contents($base . '/api/facility-capacity.php'));
if (preg_match_all("/user_can_access_entity\('facility'/", $src) >= 2) {
    ok('facility-capacity.php IDOR check on GET single + POST update');
} else {
    bad('facility-capacity.php IDOR check on GET single + POST update');
}
// F-007 long-tail: scope-aware SSE publish
if (strpos($src, '_sse_groups_for_resource') !== false
    || strpos($src, "sse_publish_for_admin('facility:capacity'") !== false
    || preg_match("/sse_publish\('facility:capacity'.*'group'/", $src)) {
    ok('facility-capacity.php SSE publish is scope-aware (F-007)');
} else {
    bad('facility-capacity.php SSE publish is scope-aware');
}

// ── winlink-export.php (incident-scoped export) ──
$src = code_only(file_get_contents($base . '/api/winlink-export.php'));
if (strpos($src, "user_can_access_entity('incident'") !== false) {
    ok('winlink-export.php IDOR check on ticket_id');
} else {
    bad('winlink-export.php IDOR check on ticket_id');
}

// ── reports.php (per-resource + admin-on-aggregate) ──
$src = code_only(file_get_contents($base . '/api/reports.php'));
if (strpos($src, "user_can_access_entity('incident'") !== false
    && strpos($src, "user_can_access_entity('responder'") !== false) {
    ok('reports.php IDOR checks on incident + responder filters');
} else {
    bad('reports.php IDOR checks on filters');
}
if (strpos($src, '$isFiltered') !== false
    && strpos($src, '_currentLevel > 1') !== false) {
    ok('reports.php aggregate (no filter) requires admin');
} else {
    bad('reports.php aggregate requires admin');
}

// ── training.php (member-scoped) ──
$src = code_only(file_get_contents($base . '/api/training.php'));
if (strpos($src, "user_can_access_entity('member'") !== false) {
    ok('training.php IDOR check on member_id');
} else {
    bad('training.php IDOR check on member_id');
}

// ── routing.php (admin-only gate fires before any handler) ──
// Phase 12 (2026-06-11): accept either the legacy current_level>1 form
// (pre-sunset) or the modern !is_admin() form. Both express the same
// "admin required" semantics; the legacy form is removed everywhere
// outside the migration tooling.
$src = code_only(file_get_contents($base . '/api/routing.php'));
if (strpos($src, "rbac_can('action.manage_routing')") !== false
    && (preg_match('/current_level.*>\s*1/', $src)
        || strpos($src, '!is_admin()') !== false)) {
    ok('routing.php admin permission check fires before all GET/POST handlers');
} else {
    bad('routing.php admin permission check');
}

// ── F-007 long tail in geofence.php ──
$src = code_only(file_get_contents($base . '/inc/geofence.php'));
if (strpos($src, 'sse_publish_for_responder') !== false
    || strpos($src, 'sse_publish_for_admin') !== false) {
    ok('inc/geofence.php uses scope-aware SSE publish (F-007 long tail)');
} else {
    bad('inc/geofence.php uses scope-aware SSE publish');
}

// ── F-007 long tail in local_chat.php ──
$src = code_only(file_get_contents($base . '/inc/channels/local_chat.php'));
if (strpos($src, 'sse_publish_for_user') !== false
    && strpos($src, 'sse_publish_for_incident') !== false) {
    ok('inc/channels/local_chat.php scopes DMs (user) and incident chat (incident)');
} else {
    bad('inc/channels/local_chat.php SSE scope');
}

// ── reference-data endpoints documented as auth-only ──
foreach (['sop-pages', 'ics-positions', 'road-conditions'] as $f) {
    $src = code_only(file_get_contents($base . "/api/{$f}.php"));
    if (strpos($src, "require_once __DIR__ . '/auth.php'") !== false) {
        ok("api/{$f}.php enforces auth");
    } else {
        bad("api/{$f}.php enforces auth");
    }
}

// road-conditions.php enforces admin on writes
// Phase 12 (2026-06-11): accept legacy current_level>1 OR !is_admin().
$src = code_only(file_get_contents($base . '/api/road-conditions.php'));
if (preg_match('/method\s*===?\s*.POST.*current_level\s*>\s*1/s', $src) ||
    preg_match('/method\s*===?\s*.POST.*!is_admin\(\)/s', $src)) {
    ok('road-conditions.php requires admin for POST writes');
} else {
    bad('road-conditions.php requires admin for POST writes');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
