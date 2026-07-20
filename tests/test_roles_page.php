<?php
/**
 * Phase 80b — standalone Roles & Permissions page tests.
 *
 * Verifies:
 *   - roles.php exists and includes the shared navbar
 *   - roles.php has an admin-only access guard
 *   - roles.php links to assets/js/roles.js (the new dedicated script)
 *   - assets/js/roles.js exists, is ES5 IIFE-wrapped, and uses the
 *     existing RBAC + config-admin API endpoints
 *   - assets/js/roles.js exposes loadRoles / selectRole / saveRoleMeta /
 *     deleteRole / togglePermission / removeUserFromRole
 *   - navbar.php has a link to /roles.php (Personnel dropdown)
 *   - An admin session can include roles.php directly and the page
 *     renders (no header redirect, no fatal) — exercises the page
 *     bootstrap path via a simulated $_SESSION
 *
 * Pattern: borrowed from tests/test_security.php — direct PHP include
 * with a primed $_SESSION rather than HTTP curl. Faster, doesn't
 * require Apache to be running, and the bootstrap is identical to
 * what the browser sees.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tests/test_roles_page.php
 */

require_once __DIR__ . '/../config.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 80b — Roles page tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name)  { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++; }

// ─────────────────────────────────────────────────────────────────────
// 1. roles.php — file exists, navbar include, admin guard, JS link
// ─────────────────────────────────────────────────────────────────────

$rolesPath = $base . '/roles.php';
if (file_exists($rolesPath)) {
    ok('roles.php exists');
} else {
    bad('roles.php missing — fatal');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
$rolesSrc = file_get_contents($rolesPath);

// Navbar include
if (strpos($rolesSrc, "/inc/navbar.php'") !== false ||
    strpos($rolesSrc, '/inc/navbar.php"') !== false) {
    ok('roles.php includes inc/navbar.php');
} else {
    bad('roles.php does not include inc/navbar.php');
}

// Admin-only access guard. Either is_admin() OR rbac_can('action.manage_roles')
// must appear in the page.
if (strpos($rolesSrc, 'is_admin()') !== false ||
    strpos($rolesSrc, "rbac_can('action.manage_roles')") !== false ||
    strpos($rolesSrc, 'rbac_can("action.manage_roles")') !== false) {
    ok('roles.php has admin/manage_roles access guard');
} else {
    bad('roles.php missing admin access guard');
}

// On guard failure the page must bounce — header redirect OR exit with
// a 404. The new Phase 80b page redirects to index.php.
if (preg_match('#header\s*\(\s*[\'\"]Location:[^\'\"]+[\'\"]\s*\)\s*;\s*exit#', $rolesSrc)) {
    ok('roles.php bounces unauthorized users (header Location + exit)');
} else {
    bad('roles.php does not redirect on auth failure');
}

// Links to the new dedicated roles.js
if (strpos($rolesSrc, 'assets/js/roles.js') !== false) {
    ok('roles.php loads assets/js/roles.js');
} else {
    bad('roles.php does not reference assets/js/roles.js');
}

// CSRF token surfaced for JS consumption
if (strpos($rolesSrc, 'id="csrfToken"') !== false ||
    strpos($rolesSrc, 'csrf-token') !== false) {
    ok('roles.php exposes CSRF token for JS');
} else {
    bad('roles.php does not expose CSRF token');
}

// Three-column layout
$hasThreeCols =
    substr_count($rolesSrc, 'col-md-4') >= 3 ||
    (strpos($rolesSrc, 'col-md-4') !== false && strpos($rolesSrc, 'col-lg-4') !== false);
if ($hasThreeCols) {
    ok('roles.php uses a 3-column Bootstrap grid layout');
} else {
    bad('roles.php missing 3-column layout');
}

// Key UI anchors the JS will reach for. Names must stay stable because
// roles.js binds to them by id.
$expectedIds = ['rolesList', 'roleDetailPanel', 'permissionMatrix', 'btnNewRole'];
foreach ($expectedIds as $idAttr) {
    if (strpos($rolesSrc, 'id="' . $idAttr . '"') !== false) {
        ok("roles.php has #{$idAttr} anchor");
    } else {
        bad("roles.php missing #{$idAttr}");
    }
}

// ─────────────────────────────────────────────────────────────────────
// 2. assets/js/roles.js — file exists, IIFE, ES5, correct endpoints
// ─────────────────────────────────────────────────────────────────────

$jsPath = $base . '/assets/js/roles.js';
if (file_exists($jsPath)) {
    ok('assets/js/roles.js exists');
} else {
    bad('assets/js/roles.js missing — fatal');
    echo "\n=== $pass passed, $fail failed ===\n";
    exit($fail > 0 ? 1 : 0);
}
$jsSrc = file_get_contents($jsPath);

// IIFE wrapper + strict mode (project convention)
if (preg_match("#\\(function\\s*\\(\\s*\\)\\s*\\{[^}]*'use strict'#s", $jsSrc)) {
    ok('roles.js is IIFE-wrapped with "use strict"');
} else {
    bad('roles.js missing IIFE / strict mode');
}

// ES5: no arrow functions, no let/const, no template literals.
// Arrow detection: "=> {" or "=> (" — distinguish from ">= " (we want
// a single = before >).
if (preg_match('#[^=!<>]=>\\s*[\\{\\(]#', $jsSrc)) {
    bad('roles.js uses arrow functions (ES6 forbidden)');
} else {
    ok('roles.js has no arrow functions');
}
if (preg_match('#^\s*(let|const)\s+#m', $jsSrc)) {
    bad('roles.js uses let/const (ES6 forbidden)');
} else {
    ok('roles.js uses var only (no let/const)');
}
// Backticks anywhere (template literals)
if (strpos($jsSrc, '`') !== false) {
    bad('roles.js contains backticks (template literals — ES6)');
} else {
    ok('roles.js has no template literals');
}

// Endpoint usage — the page MUST hit the existing API (don't reinvent).
$expectedEndpoints = [
    'api/rbac.php'                       => 'roles.js calls api/rbac.php',
    'api/config-admin.php?section=users' => 'roles.js calls config-admin.php?section=users',
    "'save_role'"                        => 'roles.js sends save_role action',
    "'delete_role'"                      => 'roles.js sends delete_role action',
    "'set_permissions'"                  => 'roles.js sends set_permissions action',
    "'remove_role'"                      => 'roles.js sends remove_role action',
];
foreach ($expectedEndpoints as $needle => $label) {
    if (strpos($jsSrc, $needle) !== false) {
        ok($label);
    } else {
        bad($label, "needle: $needle");
    }
}

// Public function surface
$expectedFns = ['loadRoles', 'selectRole', 'saveRoleMeta', 'deleteRole',
                'togglePermission', 'removeUserFromRole'];
foreach ($expectedFns as $fn) {
    if (preg_match('#function\\s+' . preg_quote($fn, '#') . '\\s*\\(#', $jsSrc)) {
        ok("roles.js defines function $fn()");
    } else {
        bad("roles.js missing function $fn()");
    }
}

// Debounce for permission toggles
if (strpos($jsSrc, 'setTimeout') !== false &&
    strpos($jsSrc, 'pendingSave') !== false) {
    ok('roles.js debounces permission toggles (setTimeout + pendingSave)');
} else {
    bad('roles.js does not appear to debounce permission saves');
}

// ─────────────────────────────────────────────────────────────────────
// 3. inc/navbar.php — link to roles.php exists
// ─────────────────────────────────────────────────────────────────────

$navSrc = file_get_contents($base . '/inc/navbar.php');
if (preg_match('#href="roles\.php"#', $navSrc)) {
    ok('navbar.php contains href="roles.php"');
} else {
    bad('navbar.php missing href="roles.php"');
}

// The link is gated on rbac_can('action.manage_roles') so non-admins
// don't see a dead-end link.
if (preg_match("#rbac_can\\(\\s*'action\\.manage_roles'\\s*\\)#", $navSrc)) {
    ok('navbar.php gates the Roles link on action.manage_roles');
} else {
    bad('navbar.php does not gate the Roles link');
}

// ─────────────────────────────────────────────────────────────────────
// 4. Integration — admin session can render the page
//
// Direct include with a primed $_SESSION (no HTTP). Captures the rendered
// output, checks for a few expected markers, and confirms no fatal /
// header-Location redirect fired.
// ─────────────────────────────────────────────────────────────────────

// Find a real super-admin user so the rbac_can check inside roles.php
// resolves true at runtime. Falls back to user_id=1 (conventional admin).
$adminUid = null;
try {
    $row = db_fetch_one(
        "SELECT DISTINCT ur.user_id
           FROM `{$prefix}user_roles` ur
           JOIN `{$prefix}roles` r ON r.id = ur.role_id
          WHERE r.is_super = 1
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
          LIMIT 1"
    );
    if ($row) $adminUid = (int) $row['user_id'];
} catch (Exception $e) { /* fall through */ }
if (!$adminUid) {
    // Pre-Phase-11 install — use user.level=0 (Super) as the marker.
    try {
        $row = db_fetch_one(
            "SELECT id FROM `{$prefix}user` WHERE level = 0 LIMIT 1"
        );
        if ($row) $adminUid = (int) $row['id'];
    } catch (Exception $e) { /* nothing */ }
}
if (!$adminUid) $adminUid = 1; // last-ditch

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Prime an admin session
$_SESSION['user_id']   = $adminUid;
$_SESSION['user']      = 'phase80b-tester';
$_SESSION['level']     = 0; // legacy fallback path
$_SESSION['day_night'] = 'Day';
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
if (function_exists('rbac_clear_cache')) rbac_clear_cache();

// roles.php calls session_start() unconditionally; PHP warns when the
// session is already active. Suppress that one notice while still
// surfacing real errors.
$prevErrors = error_reporting();
error_reporting($prevErrors & ~E_NOTICE & ~E_WARNING);

ob_start();
$caughtFatal = null;
try {
    // Some pages call exit on guard failure. Use a wrapper that captures
    // exit() via output-buffer inspection rather than letting it kill us.
    // We can't intercept exit() in pure PHP, but if the guard fails the
    // page emits a Location header and stops; the output buffer stays
    // empty. Detect that case after the include.
    include $rolesPath;
} catch (Throwable $e) {
    $caughtFatal = $e;
}
$rendered = ob_get_clean();
error_reporting($prevErrors);

if ($caughtFatal !== null) {
    bad('admin include — fatal during render', $caughtFatal->getMessage());
} else {
    ok('admin include — no fatal errors');
}

if (strpos($rendered, 'rolesList') !== false &&
    strpos($rendered, 'roleDetailPanel') !== false &&
    strpos($rendered, 'permissionMatrix') !== false) {
    ok('admin include — page rendered with all 3 column anchors');
} else if ($rendered === '' && headers_list()) {
    // Page redirected via header() — that means the admin guard rejected
    // our session. Indicates the session wasn't fully wired for this run.
    bad('admin include — guard rejected admin session', 'rendered empty');
} else {
    bad('admin include — page rendered but missing anchors',
        'output length: ' . strlen($rendered));
}

// Final tally
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
