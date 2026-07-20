<?php
/**
 * Phase 73bb regression tests — 4 HIGH auth findings.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── 3. SSE admin scope uses is_admin() not legacy level ──────────
$stream = file_get_contents(__DIR__ . '/../api/stream.php');
tcheck(strpos($stream, '$userIsAdmin = is_admin();') !== false,
    'api/stream.php computes admin scope via is_admin()');
tcheck(strpos($stream, '($userLevel <= 1)') === false
    || preg_match('/userIsAdmin\s*=\s*\(\s*\$userLevel\s*<=\s*1\s*\)/', $stream) === 0,
    'api/stream.php no longer keys admin scope off legacy $userLevel');

// ── 4. TOTP replay protection ────────────────────────────────────
$totp = file_get_contents(__DIR__ . '/../inc/totp.php');
tcheck(strpos($totp, "function totp_verify(\$secret, \$code, \$window = 1, \$period = 30, \$userId = null)") !== false,
    'totp_verify accepts userId parameter for replay tracking');
tcheck(strpos($totp, 'last_used_counter') !== false,
    'totp_verify references last_used_counter column');
tcheck(strpos($totp, '$checkCounter <= $lastUsed') !== false,
    'totp_verify rejects already-redeemed counters');
$tfa = file_get_contents(__DIR__ . '/../inc/tfa.php');
tcheck(substr_count($tfa, 'totp_verify($secret, $code, 1, 30, $userId)') >= 2,
    'tfa.php passes userId in both enrollment + login totp_verify calls');

// ── 5. Device fingerprint binds to IP /24 ────────────────────────
tcheck(strpos($tfa, 'FILTER_FLAG_IPV4') !== false
    && strpos($tfa, '$oct[0] . \'.\' . $oct[1] . \'.\' . $oct[2] . \'.0/24\'') !== false,
    'tfa_device_fingerprint binds to /24 IPv4 prefix');
tcheck(strpos($tfa, "FILTER_FLAG_IPV6") !== false
    && strpos($tfa, "::/48") !== false,
    'tfa_device_fingerprint binds to /48 IPv6 prefix');

// ── 6. tfa_is_required_for_user resolves against RBAC ───────────
tcheck(strpos($tfa, "rbac_user_roles((int) \$userId)") !== false,
    'tfa_is_required_for_user resolves caller roles via rbac_user_roles');
// 2026-07-03 Sonar php:S930 refactor: the string-code matcher branch was
// removed as dead code (the `roles` table has no `code` column, so codes
// were never populated). The current contract: numeric role IDs match,
// non-numeric values are skipped explicitly.
tcheck(strpos($tfa, '$userRoleIds') !== false
    && strpos($tfa, "if (!is_numeric(\$rs)) continue;") !== false,
    'tfa_is_required_for_user matches numeric role IDs and skips non-numeric codes (dead branch removed)');
tcheck(strpos($tfa, "// legacy level") !== false,
    'tfa_is_required_for_user still honours legacy level for backwards compatibility');

echo "Phase 73bb HIGH auth regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
