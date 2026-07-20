<?php
/**
 * Phase 10c — User Accounts CJIS gap + client IP behind proxy.
 *
 * Two issues caught 2026-06-11:
 *
 * 1. The dedicated Reset Password form in Login Settings (Phase 10)
 *    requires a reason + audit + force-change + session-kill. But the
 *    EXISTING admin password-change path via Settings → User Accounts
 *    → Edit user → Password field skipped all of that — admins could
 *    do "stealth" resets with no audit trail.
 *
 * 2. The audit log for login attempts was recording the IP of NPM
 *    (the reverse proxy, typically 127.0.0.1) rather than the real
 *    client IP from X-Forwarded-For / X-Real-IP.
 *
 * This suite source-greps the fixes and exercises the client_ip()
 * helper directly.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/client-ip.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "=== Phase 10c — User Accounts CJIS + client IP tests ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── Issue 1: User Accounts edit form requires reason ─────────────────────
$ca = file_get_contents($base . '/api/config-admin.php');
if (strpos($ca, 'isAdminResetOfOther') !== false) {
    ok('api/config-admin.php detects admin-reset-of-other-user');
} else {
    bad('api/config-admin.php missing admin-reset detection');
}
// The source file contains the string with a backslash-escaped apostrophe
// (PHP single-quoted string literal escapes), so the on-disk bytes are
// "another user\'s password" not "another user's password".
if (strpos($ca, "A reason is required when changing another user") !== false &&
    strpos($ca, "s password") !== false) {
    ok('api/config-admin.php rejects admin reset without reason');
} else {
    bad('api/config-admin.php does NOT require reason');
}
if (strpos($ca, "'source'         => 'user_accounts_edit_form'") !== false ||
    strpos($ca, "'source' => 'user_accounts_edit_form'") !== false) {
    ok('api/config-admin.php audit records source = user_accounts_edit_form');
} else {
    bad('api/config-admin.php audit does NOT record source field');
}
if (strpos($ca, 'sm_destroy_all_for_user((int)$id)') !== false) {
    ok('api/config-admin.php kills target user sessions on admin reset');
} else {
    bad('api/config-admin.php does NOT kill sessions');
}

// ── Issue 1 UI: reason row + JS wiring ───────────────────────────────────
$st = file_get_contents($base . '/settings.php');
if (strpos($st, 'id="adminResetReasonRow"') !== false &&
    strpos($st, 'id="userResetReason"') !== false) {
    ok('settings.php User Accounts form has reason row + input');
} else {
    bad('settings.php missing reason row');
}
if (strpos($st, 'window.__currentUserId') !== false) {
    ok('settings.php exposes current admin user_id to JS');
} else {
    bad('settings.php does NOT expose __currentUserId');
}

$cj = file_get_contents($base . '/assets/js/config.js');
if (strpos($cj, 'adminResetReasonRow') !== false &&
    strpos($cj, 'isAdminResetOfOther') !== false) {
    ok('config.js wires reason row visibility on userPass input');
} else {
    bad('config.js does NOT wire reason row');
}
if (strpos($cj, 'A reason is required when changing another user') !== false) {
    ok('config.js validates reason at submit time');
} else {
    bad('config.js does NOT validate reason at submit');
}

// ── Issue 2: client_ip() helper ──────────────────────────────────────────
if (function_exists('client_ip')) {
    ok('client_ip() helper loaded');
} else {
    bad('client_ip() helper not loaded');
}
if (function_exists('_client_ip_in_cidr')) {
    ok('CIDR matcher loaded');
} else {
    bad('CIDR matcher missing');
}

// Direct functional checks of the CIDR matcher (no static cache issue).
$cidrCases = [
    ['10.0.0.1',   '10.0.0.0/8',     true],
    ['10.255.255.1', '10.0.0.0/8',   true],
    ['11.0.0.1',   '10.0.0.0/8',     false],
    ['192.168.1.5','192.168.0.0/16', true],
    ['172.16.0.1', '192.168.0.0/16', false],
    ['127.0.0.1',  '127.0.0.0/24',   true],
    ['::1',        '::1/128',        true],
    ['2001:db8::1','2001:db8::/32',  true],
];
foreach ($cidrCases as $c) {
    [$ip, $cidr, $expected] = $c;
    $got = _client_ip_in_cidr($ip, $cidr);
    if ($got === $expected) {
        ok("CIDR {$ip} in {$cidr} = " . ($expected ? 'true' : 'false'));
    } else {
        bad("CIDR {$ip} in {$cidr}", "expected " . var_export($expected, true) . ", got " . var_export($got, true));
    }
}

// trusted-proxy lookup uses the settings row we just seeded
try {
    $tp = db_fetch_value(
        "SELECT `value` FROM `{$prefix}settings` WHERE `name` = 'trusted_proxies' LIMIT 1"
    );
    if ($tp !== null) {
        ok("trusted_proxies setting present (value: '{$tp}')");
    } else {
        bad('trusted_proxies setting missing — run sql/run_phase10c_trusted_proxies.php');
    }
} catch (Exception $e) {
    bad('trusted_proxies lookup', $e->getMessage());
}

// is_trusted_proxy direct check
if (_client_ip_is_trusted_proxy('127.0.0.1')) {
    ok('127.0.0.1 is recognized as trusted proxy (default setting)');
} else {
    bad('127.0.0.1 is NOT trusted by default');
}
if (!_client_ip_is_trusted_proxy('203.0.113.5')) {
    ok('203.0.113.5 (public) is NOT trusted by default');
} else {
    bad('Public IP incorrectly trusted as proxy');
}

// ── REMOTE_ADDR replaced in hot paths ────────────────────────────────────
$auditSrc = file_get_contents($base . '/inc/audit.php');
if (substr_count($auditSrc, 'client_ip()') >= 2) {
    ok('inc/audit.php uses client_ip() in both audit_log + audit_login_event');
} else {
    bad('inc/audit.php does NOT use client_ip() in all needed spots');
}

$smSrc = file_get_contents($base . '/inc/session-manager.php');
if (strpos($smSrc, 'client_ip()') !== false) {
    ok('inc/session-manager.php uses client_ip()');
} else {
    bad('inc/session-manager.php does NOT use client_ip()');
}

$lgSrc = file_get_contents($base . '/login.php');
if (substr_count($lgSrc, 'client_ip()') >= 2) {
    ok('login.php uses client_ip() in TFA + main login paths');
} else {
    bad('login.php does NOT use client_ip() in all needed spots');
}

// ── Migration runner ─────────────────────────────────────────────────────
$mig = $base . '/sql/run_phase10c_trusted_proxies.php';
if (file_exists($mig)) {
    ok('sql/run_phase10c_trusted_proxies.php exists');
} else {
    bad('Phase 10c migration missing');
}

echo "\n";
echo "===========================================\n";
echo "Phase 10c CJIS audit fixes: {$pass} passed, {$fail} failed\n";
echo "===========================================\n";

if ($fail > 0) exit(1);
