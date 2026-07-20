<?php
/**
 * Hour-2 + Hour-3 deep-dive regression tests (security-audit-2026-04 handoff).
 *
 * Hour 2 — auth/session:
 *   * api/auth.php sets security headers on every request
 *   * api/auth.php enforces session expiry via sm_is_session_valid()
 *   * inc/security-headers.php sets a real CSP (default-src 'self')
 *   * HSTS includes `preload`
 *
 * Hour 3 — TFA + crypto:
 *   * TFA encryption key is in a dedicated file with mode 0600
 *   * Keys directory is mode 0700
 *   * AES uses random_bytes for IV (no static IV, no rand())
 *   * login.php rate-limits TFA verification through ls_record_attempt
 *   * Failed TFA attempts feed the lockout counter
 */

require __DIR__ . '/../config.php';

$base = realpath(__DIR__ . '/..');

echo "=== Hour-2/3 deep dive regression suite ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }
function code_only(string $src): string {
    $src = preg_replace('!//[^\n]*!', '', $src);
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    return $src;
}

// ── HOUR 2 ─────────────────────────────────────────────────────────

// auth.php fires set_security_headers
$auth = code_only(file_get_contents($base . '/api/auth.php'));
if (strpos($auth, 'security-headers.php') !== false
    && strpos($auth, 'set_security_headers()') !== false) {
    ok('Hour-2: api/auth.php calls set_security_headers() on every request');
} else {
    bad('Hour-2: api/auth.php fires security headers');
}

// auth.php enforces session expiry
if (strpos($auth, 'sm_is_session_valid()') !== false) {
    ok('Hour-2: api/auth.php enforces session expiry (sm_is_session_valid)');
} else {
    bad('Hour-2: api/auth.php enforces session expiry');
}

// auth.php updates session activity
if (strpos($auth, 'sm_update_activity()') !== false) {
    ok('Hour-2: api/auth.php touches sm_update_activity (rolling timeout)');
} else {
    bad('Hour-2: api/auth.php sm_update_activity');
}

// security-headers.php has a CSP
$sh = code_only(file_get_contents($base . '/inc/security-headers.php'));
if (strpos($sh, 'Content-Security-Policy') !== false
    && strpos($sh, "default-src 'self'") !== false) {
    ok('Hour-2: set_security_headers() includes CSP default-src \'self\'');
} else {
    bad('Hour-2: set_security_headers() includes CSP');
}

// #53 — RainViewer radar (situation.php) needs BOTH the frame-catalog fetch
// (connect-src) and the tile host (img-src) allow-listed, or the layer paints
// nothing. Verify both directives permit *.rainviewer.com. NB: use the RAW
// source here — code_only() strips everything after '//', which mangles the
// https:// URLs we're matching on.
$shRaw = file_get_contents($base . '/inc/security-headers.php');
if (preg_match('/img-src[^;]*rainviewer\.com/s', $shRaw)) {
    ok('#53: CSP img-src allows RainViewer radar tiles');
} else {
    bad('#53: CSP img-src missing rainviewer.com (radar tiles blocked)');
}
if (preg_match('/connect-src[^;]*rainviewer\.com/s', $shRaw)) {
    ok('#53: CSP connect-src allows the RainViewer frame catalog fetch');
} else {
    bad('#53: CSP connect-src missing rainviewer.com (radar catalog fetch blocked)');
}
// #53 — NOAA/NWS MRMS radar export tiles (situation.php US radar layer).
if (preg_match('/img-src[^;]*mapservices\.weather\.noaa\.gov/s', $shRaw)) {
    ok('#53: CSP img-src allows NOAA MRMS radar export tiles');
} else {
    bad('#53: CSP img-src missing mapservices.weather.noaa.gov (US radar blocked)');
}

// HSTS preload
if (preg_match('/Strict-Transport-Security:.*preload/', $sh)) {
    ok('Hour-2: HSTS includes `preload` flag');
} else {
    bad('Hour-2: HSTS includes preload');
}

// X-XSS-Protection: 1; mode=block intentionally removed (modern browsers
// ignore it, older ones had exploitable bugs). Document that decision.
if (!preg_match('/header\(.X-XSS-Protection: 1/', $sh)) {
    ok('Hour-2: deprecated X-XSS-Protection header removed');
} else {
    bad('Hour-2: deprecated X-XSS-Protection still present');
}

// ── HOUR 3 ─────────────────────────────────────────────────────────

// TFA key file: dedicated key, written with random_bytes + chmod 0600
$tfaSrc = code_only(file_get_contents($base . '/inc/tfa.php'));
if (strpos($tfaSrc, 'random_bytes(32)') !== false
    && strpos($tfaSrc, 'chmod($keyFile, 0600)') !== false) {
    ok('Hour-3: tfa_generate_key uses random_bytes(32) + chmod 0600');
} else {
    bad('Hour-3: tfa_generate_key permissions');
}

// AES IV: random_bytes(16), not static
if (preg_match_all('/random_bytes\(16\)/', $tfaSrc) >= 2
    && !preg_match('/openssl_encrypt[^;]*0,\s*[\'"]/m', $tfaSrc)) {
    ok('Hour-3: TFA AES uses random IV every call');
} else {
    bad('Hour-3: TFA AES uses random IV every call');
}

// hash_equals on TFA token comparisons (anti-timing)
if (substr_count($tfaSrc, 'hash_equals(') >= 2) {
    ok('Hour-3: TFA token comparisons use hash_equals (timing-safe)');
} else {
    bad('Hour-3: TFA token comparisons use hash_equals');
}

// field-encrypt.php uses AES-GCM (authenticated encryption)
$feSrc = code_only(file_get_contents($base . '/inc/field-encrypt.php'));
if (strpos($feSrc, 'aes-256-gcm') !== false
    || strpos($feSrc, 'AES-256-GCM') !== false) {
    ok('Hour-3: field-encrypt uses AES-256-GCM (AEAD)');
} else {
    bad('Hour-3: field-encrypt uses AES-GCM');
}

// Key file permissions baked in
if (strpos($feSrc, 'chmod(FE_PRIVATE_KEY, 0600)') !== false
    && strpos($feSrc, 'chmod(FE_KEYS_DIR, 0700)') !== false) {
    ok('Hour-3: field-encrypt sets 0600 on private key + 0700 on keys dir');
} else {
    bad('Hour-3: field-encrypt key permissions');
}

// login.php — TFA verification is rate-limited
$loginSrc = code_only(file_get_contents($base . '/login.php'));
if (strpos($loginSrc, "ls_record_attempt") !== false
    && strpos($loginSrc, "'wrong_tfa'") !== false
    && preg_match('/ls_is_locked\(\$tfaUser\)/', $loginSrc)) {
    ok('Hour-3: login.php rate-limits TFA verification via ls_record_attempt');
} else {
    bad('Hour-3: login.php rate-limits TFA verification');
}

// TFA failure clears its own counter on success
if (preg_match('/tfa_verify_login.{0,300}ls_clear_attempts/s', $loginSrc)) {
    ok('Hour-3: login.php clears TFA lockout counter on success');
} else {
    bad('Hour-3: login.php clears TFA lockout counter on success');
}

// audit_login fires on TFA failure
if (preg_match("/audit_login.{0,80}'tfa_failed'/", $loginSrc)) {
    ok('Hour-3: TFA failure is audited');
} else {
    bad('Hour-3: TFA failure is audited');
}

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
