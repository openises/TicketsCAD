<?php
/**
 * F-001 regression — RCE via upload.php must remain closed.
 *
 * Validates the hardening applied 2026-05-04:
 *   1. uploads/.htaccess exists and denies php/phar/phtml/svg/html.
 *   2. api/upload.php derives MIME from finfo_file (not $_FILES['type']).
 *   3. api/upload.php rejects extensions not on the allowlist.
 *   4. api/upload.php uses a canonical extension keyed off the verified MIME.
 *   5. api/upload.php verifies CSRF on POST.
 *   6. api/upload.php calls user_can_access_entity() on download/list/upload/delete.
 *   7. inc/access.php helper exists and behaves correctly for admin / non-admin / cross-group.
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../inc/access.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$base   = realpath(__DIR__ . '/..');

echo "=== F-001 Upload RCE Regression Tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── 1. uploads/.htaccess present and complete ──
$htaccessPath = $base . '/uploads/.htaccess';
if (!file_exists($htaccessPath)) {
    bad('uploads/.htaccess exists', 'missing file');
} else {
    ok('uploads/.htaccess exists');
    $h = file_get_contents($htaccessPath);
    foreach (['php', 'phar', 'phtml', 'svg', 'html'] as $ext) {
        if (preg_match('/FilesMatch[^>]*' . preg_quote($ext, '/') . '/', $h)) {
            ok(".htaccess denies .$ext");
        } else {
            bad(".htaccess denies .$ext", 'pattern not present');
        }
    }
    if (strpos($h, 'php_flag engine off') !== false) {
        ok('.htaccess sets php_flag engine off');
    } else {
        bad('.htaccess sets php_flag engine off');
    }
}

// ── 2. api/upload.php source must contain hardening primitives ──
$src = file_get_contents($base . '/api/upload.php');

// finfo-based MIME detection
if (strpos($src, 'finfo_open(FILEINFO_MIME_TYPE)') !== false
    && strpos($src, 'finfo_file(') !== false) {
    ok('upload.php uses finfo_file for MIME detection');
} else {
    bad('upload.php uses finfo_file for MIME detection');
}

// User-supplied $_FILES['type'] is not trusted as the storage MIME
if (preg_match('/\$mime\s*=\s*\$file\[.type.\]\s*\?:/', $src)) {
    bad('upload.php no longer trusts $_FILES[type]', 'still uses fall-through ?: pattern');
} else {
    ok('upload.php no longer trusts $_FILES[type] for storage MIME');
}

// Canonical extension derived from MIME
if (strpos($src, '$canonicalExt') !== false
    && strpos($src, 'MIME_TO_EXT') !== false) {
    ok('upload.php derives canonical extension from MIME');
} else {
    bad('upload.php derives canonical extension from MIME');
}

// Allowlist for dangerous extensions — the user-supplied .php must be rejected
if (strpos($src, "File extension not allowed") !== false
    || strpos($src, 'ALLOWED_EXT_MIME') !== false) {
    ok('upload.php enforces extension allowlist');
} else {
    bad('upload.php enforces extension allowlist');
}

// CSRF check on POST
if (strpos($src, 'csrf_verify') !== false
    && preg_match('/if\s*\(\s*!csrf_verify\b/', $src)) {
    ok('upload.php verifies CSRF on POST');
} else {
    bad('upload.php verifies CSRF on POST');
}

// Per-entity access check on download / list / upload / delete
$accessChecks = preg_match_all('/user_can_access_entity\s*\(/', $src);
if ($accessChecks >= 4) {
    ok("upload.php calls user_can_access_entity ($accessChecks sites)");
} else {
    bad('upload.php calls user_can_access_entity', "found $accessChecks, expected ≥4");
}

// Download forces a safe MIME (no echo of stored mime)
if (strpos($src, "header('Content-Type: '") !== false
    && strpos($src, "X-Content-Type-Options: nosniff") !== false) {
    ok('upload.php sets X-Content-Type-Options: nosniff on download');
} else {
    bad('upload.php sets X-Content-Type-Options: nosniff on download');
}

// ── 3. inc/access.php helper behavior ──
if (!function_exists('user_can_access_entity')) {
    bad('user_can_access_entity() loaded', 'function missing');
} else {
    ok('user_can_access_entity() loaded');
}

// 3a. Admin always allowed (level <= 1)
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin', 'user_groups' => []];
if (user_can_access_entity('incident', 999999) === true) {
    ok('admin (level 0) bypasses allocates check');
} else {
    bad('admin (level 0) bypasses allocates check');
}

// 3b. Non-admin with no groups is denied for group-scoped types.
// Use a synthetic user_id that won't exist in the user table so the
// RBAC bypass in user_can_access_entity() (Phase 10b) doesn't find
// any grants and falls through to the allocates check. Also force
// the RBAC grants cache to reload — the prior 3a sub-test populated
// it for user_id=1 (admin) and that cache persists in the same PHP
// process otherwise.
$_SESSION = ['user_id' => 999998, 'level' => 4, 'user' => 'observer', 'user_groups' => []];
if (function_exists('rbac_reset_cache')) { rbac_reset_cache(); }
if (user_can_access_entity('incident', 1) === false) {
    ok('non-admin without groups denied incident access');
} else {
    bad('non-admin without groups denied incident access');
}
if (user_can_access_entity('responder', 1) === false) {
    ok('non-admin without groups denied responder access');
} else {
    bad('non-admin without groups denied responder access');
}

// 3c. Invalid entity_id is denied
$_SESSION = ['user_id' => 1, 'level' => 0, 'user' => 'admin'];
if (user_can_access_entity('incident', 0) === false) {
    ok('user_can_access_entity rejects entity_id=0');
} else {
    bad('user_can_access_entity rejects entity_id=0');
}

// 3d. Non-admin with matching group is allowed
// Seed a synthetic allocates row, attempt access, then clean up.
// Use a synthetic user_id with no RBAC grants so the Phase 10b RBAC
// bypass doesn't short-circuit the allocates check we're testing.
$_SESSION = ['user_id' => 999997, 'level' => 4, 'user' => 'g7user', 'user_groups' => [7]];
if (function_exists('rbac_reset_cache')) { rbac_reset_cache(); }
$testIncidentId = 0;
$insertedAlloc  = false;
try {
    db_query(
        "INSERT INTO `{$prefix}allocates` (`resource_id`, `type`, `group`)
         VALUES (?, ?, ?)",
        [987654, 1, 7]
    );
    $insertedAlloc = true;
    $testIncidentId = 987654;
} catch (Exception $e) {
    // allocates schema may differ on this install — skip the positive-path test
}
if ($insertedAlloc) {
    if (user_can_access_entity('incident', $testIncidentId) === true) {
        ok('non-admin with matching group allowed via allocates');
    } else {
        bad('non-admin with matching group allowed via allocates');
    }
    // Cross-group denial: same user, different incident
    if (user_can_access_entity('incident', 987655) === false) {
        ok('non-admin denied for non-allocated incident_id');
    } else {
        bad('non-admin denied for non-allocated incident_id');
    }
    db_query(
        "DELETE FROM `{$prefix}allocates` WHERE `resource_id` = ? AND `type` = ? AND `group` = ?",
        [987654, 1, 7]
    );
} else {
    echo "[SKIP] non-admin allocates positive path (test seed failed — schema variance)\n";
}

// 3e. Org-wide entity types fall back to authenticated allow
$_SESSION = ['user_id' => 4, 'level' => 4, 'user' => 'sopuser', 'user_groups' => []];
if (user_can_access_entity('sop', 1) === true
    && user_can_access_entity('general', 1) === true) {
    ok('sop/general entity types allow any authenticated user');
} else {
    bad('sop/general entity types allow any authenticated user');
}

// Logged-out: deny everything (also reset the rbac cache so any
// prior sub-test's grants don't carry over).
$_SESSION = [];
if (function_exists('rbac_reset_cache')) { rbac_reset_cache(); }
if (user_can_access_entity('sop', 1) === false
    && user_can_access_entity('incident', 1) === false) {
    ok('logged-out user denied for all entity types');
} else {
    bad('logged-out user denied for all entity types');
}

// ── 4. PHP must support finfo (the hardened code requires it) ──
if (function_exists('finfo_open')) {
    ok('finfo extension available (required for hardened MIME detection)');
} else {
    bad('finfo extension available', 'fileinfo extension not loaded');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
