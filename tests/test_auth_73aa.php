<?php
/**
 * Phase 73aa regression tests — 2 CRITICAL auth findings.
 *
 *   1. api/auth.php enforces $_SESSION['tfa_enrollment_required']
 *      so curl callers can't bypass the page-level 2FA gate.
 *   2. inc/session-manager.php sm_is_session_valid treats "row
 *      missing" as INVALID once a session has been marked _sm_tracked,
 *      so force-logout actually invalidates live cookies.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── 1. api/auth.php has the 2FA enrollment gate ───────────────────
$auth = file_get_contents(__DIR__ . '/../api/auth.php');
tcheck(strpos($auth, "tfa_enrollment_required") !== false,
    'api/auth.php references the tfa_enrollment_required session flag');
tcheck(strpos($auth, "'force_tfa_enroll'") !== false,
    'api/auth.php returns the force_tfa_enroll code');
tcheck(strpos($auth, '$allowed = [\'profile.php\', \'tfa.php\', \'tfa-enroll.php\', \'logout.php\'];') !== false,
    'api/auth.php whitelists the enrollment endpoints');
tcheck(preg_match('/json_response\([^)]*\[\s*\'error\'\s*=>\s*\'2FA enrollment required\'/s', $auth) === 1,
    'api/auth.php returns a 423 with the explicit error message');

// ── 2. session-manager._sm_tracked marker discipline ──────────────
$sm = file_get_contents(__DIR__ . '/../inc/session-manager.php');
tcheck(strpos($sm, "_sm_tracked") !== false,
    'session-manager.php uses the _sm_tracked marker');
$markerCount = substr_count($sm, '$_SESSION[\'_sm_tracked\'] = 1;');
tcheck($markerCount >= 2,
    'sm_create_session sets the marker in both DB-create branches (got ' . $markerCount . ')');
tcheck(strpos($sm, "// row deleted = forcibly destroyed") !== false,
    'sm_is_session_valid treats absent row as forced-destroy when marker set');
tcheck(preg_match('/if \(!empty\(\$_SESSION\[\'_sm_tracked\'\]\)\) \{\s*return false;/', $sm) === 1,
    'sm_is_session_valid returns false when marker is set + row missing');

// ── 3. sm_is_session_valid still allows fresh untracked sessions ──
// Synthetic call: row absent, marker absent → should return true.
// We test by extracting and eval'ing the function in isolation.
if (!function_exists('sm_is_session_valid')) {
    // Stub db_fetch_one to return false (no row).
    $GLOBALS['_t_db_row'] = false;
    function db_fetch_one($sql, $params = []) {
        return $GLOBALS['_t_db_row'] ?? false;
    }
    preg_match('/function sm_is_session_valid\(.*?\n\}\n/s', $sm, $m);
    eval($m[0]);
}
session_id('test-sid-' . uniqid('', true));
unset($_SESSION['_sm_tracked']);
$GLOBALS['_t_db_row'] = false;
tcheck(sm_is_session_valid() === true,
    'untracked session passes when row missing (avoids locking out fresh creates)');

$_SESSION['_sm_tracked'] = 1;
$GLOBALS['_t_db_row'] = false;
tcheck(sm_is_session_valid() === false,
    'tracked session FAILS when row missing (force-logout works)');

$_SESSION['_sm_tracked'] = 1;
$GLOBALS['_t_db_row'] = ['expires_at' => date('Y-m-d H:i:s', time() + 3600)];
tcheck(sm_is_session_valid() === true,
    'tracked session with fresh row remains valid');

$GLOBALS['_t_db_row'] = ['expires_at' => date('Y-m-d H:i:s', time() - 60)];
tcheck(sm_is_session_valid() === false,
    'expired row is rejected even when marker is set');

echo "Phase 73aa CRITICAL auth regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
