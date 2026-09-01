<?php
/**
 * Security review (2026-08-29, Eric's explicit request: "run a security
 * review... fix all known vulnerabilities"). Four CSRF gaps found by a
 * manual spot-check of api/*.php POST endpoints (the codebase's own
 * standing CLAUDE.md checklist) — continuing the F-NNN numbering from
 * specs/security-audit-2026-04/ (next free was F-015).
 *
 * All four are state-changing POST endpoints, session-authenticated via
 * api/auth.php, that never called csrf_verify() before this fix — a
 * classic cross-site request forgery gap: a page on another origin could
 * trigger the mutation via the victim's already-authenticated session
 * cookie. (session.cookie_samesite=Lax in config.php already blocks the
 * classic same-site-cookie-carried-cross-site-POST case in modern
 * browsers, but that is defense-in-depth, not a substitute for an actual
 * token check — the same reasoning this codebase already applies at
 * every OTHER POST endpoint.)
 *
 *   F-015  api/shift-assignments.php  — assign/signup/cancel/swap/
 *          update_status/delete a scheduling shift assignment. The
 *          client (assets/js/scheduling.js's shared apiPost() helper)
 *          already sent csrf_token in the JSON body for every call —
 *          the server just never checked it.
 *   F-016  api/router-test-send.php   — fires a REAL notification
 *          (push/Slack/etc.) to whoever a routing predicate resolves to.
 *          Admin-gated via RBAC, but had no CSRF check at all; the JS
 *          caller (assets/js/config.js) didn't send a token either.
 *   F-017  api/dmr-tx-audio.php       — forwards POSTed audio to a live
 *          amateur-radio transmitter. A comment claimed "csrf_verify
 *          lives in inc/functions.php, already loaded via config.php"
 *          but nothing on the file ever CALLED it — the comment
 *          described availability, not use. multipart/form-data carries
 *          the token as a $_POST field, the same shape api/upload.php
 *          already uses.
 *   F-018  api/dmr-tx-stream.php      — the streaming-TX sibling of
 *          F-017, same missing check. The request body is raw
 *          application/octet-stream PCM with no room for a form/JSON
 *          field, so the token travels in an X-CSRF-Token header
 *          instead — which a plain cross-site <form> submission cannot
 *          attach, and which a cross-origin fetch/XHR cannot attach
 *          without a CORS preflight this server never approves for a
 *          foreign origin. That makes the header itself a real,
 *          independently enforceable control here, not merely
 *          equivalent to a body field a same-site attacker could still
 *          forge without one.
 *
 * Source-grep tests are sufficient at this layer, matching
 * tests/test_security_csrf_bundle.php's own established convention:
 * csrf_verify() itself (hash_equals() against $_SESSION['csrf_token'])
 * is exercised by tests/test_security.php.
 */

require __DIR__ . '/../config.php';

// Start session before any output, matching tools/test_security.php's own
// established convention — session functions (needed by the csrf_verify()
// sanity check at the bottom) misbehave once output has begun.
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$base = realpath(__DIR__ . '/..');

echo "=== Security review 2026-08-29 — CSRF bundle (F-015..F-018) ===\n\n";
$pass = 0; $fail = 0;
function ok($name) { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad($name, $why = '') { global $fail; echo "[FAIL] $name" . ($why ? " — $why" : "") . "\n"; $fail++; }

// ── F-015 api/shift-assignments.php ──────────────────────────────────
$src = file_get_contents($base . '/api/shift-assignments.php');
if (strpos($src, 'csrf_verify') !== false
    && strpos($src, "\$input['csrf_token']") !== false) {
    ok('F-015 shift-assignments.php verifies CSRF on POST');
} else {
    bad('F-015 shift-assignments.php verifies CSRF on POST');
}
// The check must run before the action switch dispatches to a handler —
// a check placed after the mutation already ran would be decorative.
$csrfPos = strpos($src, 'csrf_verify');
$switchPos = strpos($src, "switch (\$action)");
if ($csrfPos !== false && $switchPos !== false && $csrfPos < $switchPos) {
    ok('F-015 CSRF check runs before the action switch dispatches');
} else {
    bad('F-015 CSRF check runs before the action switch dispatches');
}
// The client side was already correct — confirm it still is (this fix
// must not depend on a client-side change nobody made).
$js = file_get_contents($base . '/assets/js/scheduling.js');
if (strpos($js, 'body.csrf_token') !== false) {
    ok('F-015 scheduling.js apiPost() already sends csrf_token (client side was never the gap)');
} else {
    bad('F-015 scheduling.js apiPost() no longer sends csrf_token — client-side regression');
}

// ── F-016 api/router-test-send.php ───────────────────────────────────
$src = file_get_contents($base . '/api/router-test-send.php');
if (strpos($src, 'csrf_verify') !== false
    && strpos($src, "\$input['csrf_token']") !== false) {
    ok('F-016 router-test-send.php verifies CSRF on POST');
} else {
    bad('F-016 router-test-send.php verifies CSRF on POST');
}
$csrfPos = strpos($src, 'csrf_verify');
$forwardPos = strpos($src, 'router_forward(');
if ($csrfPos !== false && $forwardPos !== false && $csrfPos < $forwardPos) {
    ok('F-016 CSRF check runs before router_forward() actually sends anything');
} else {
    bad('F-016 CSRF check runs before router_forward() actually sends anything');
}
$js = file_get_contents($base . '/assets/js/config.js');
if (strpos($js, "fetch('api/router-test-send.php'") !== false) {
    $callSite = substr($js, strpos($js, "fetch('api/router-test-send.php'"), 700);
    if (strpos($callSite, 'csrf_token:') !== false && strpos($callSite, 'getCsrfToken()') !== false) {
        ok('F-016 config.js sends csrf_token on the test-send call');
    } else {
        bad('F-016 config.js does not send csrf_token on the test-send call');
    }
} else {
    bad('F-016 could not locate the router-test-send.php call site in config.js');
}

// ── F-017 api/dmr-tx-audio.php ───────────────────────────────────────
$src = file_get_contents($base . '/api/dmr-tx-audio.php');
if (strpos($src, "csrf_verify((string) \$csrfToken)") !== false
    && strpos($src, "\$_POST['csrf_token']") !== false) {
    ok('F-017 dmr-tx-audio.php verifies CSRF on POST (multipart $_POST field)');
} else {
    bad('F-017 dmr-tx-audio.php verifies CSRF on POST');
}
// The check must run before the RBAC-gated transmit path proceeds to
// read the uploaded file.
$csrfPos = strpos($src, 'csrf_verify(');
$filesPos = strpos($src, "\$_FILES['audio']");
if ($csrfPos !== false && $filesPos !== false && $csrfPos < $filesPos) {
    ok('F-017 CSRF check runs before the uploaded audio is touched');
} else {
    bad('F-017 CSRF check runs before the uploaded audio is touched');
}
// The stale standalone comment (which described availability, never
// use, and was the file's ONLY prior mention of CSRF) must be gone as a
// bare claim — it may still be quoted, verbatim, inside the new comment
// explaining WHY the fix was needed (that's the point: naming the false
// confidence that let this ship unchecked), but it must not stand alone
// as an unqualified statement of fact any more.
if (strpos($src, "// csrf_verify lives in inc/functions.php, already loaded via config.php.\n") === false) {
    ok('F-017 the misleading comment no longer stands alone as an unqualified claim');
} else {
    bad('F-017 the misleading comment is still present verbatim, unqualified');
}
$js = file_get_contents($base . '/assets/js/radio-widget.js');
if (strpos($js, "fd.append('csrf_token', fccCsrf())") !== false) {
    ok('F-017 radio-widget.js sendPtt() appends csrf_token to the FormData');
} else {
    bad('F-017 radio-widget.js sendPtt() does not send csrf_token');
}

// ── F-018 api/dmr-tx-stream.php ──────────────────────────────────────
$src = file_get_contents($base . '/api/dmr-tx-stream.php');
if (strpos($src, "HTTP_X_CSRF_TOKEN") !== false
    && strpos($src, 'csrf_verify(') !== false) {
    ok('F-018 dmr-tx-stream.php verifies CSRF via the X-CSRF-Token header');
} else {
    bad('F-018 dmr-tx-stream.php verifies CSRF on POST');
}
$csrfPos = strpos($src, 'csrf_verify(');
$rbacPos = strpos($src, "rbac_can('action.dmr_transmit')");
if ($csrfPos !== false && $rbacPos !== false && $csrfPos < $rbacPos) {
    ok('F-018 CSRF check runs before the RBAC/transmit path proceeds');
} else {
    bad('F-018 CSRF check does not run early enough');
}
if (strpos($js, "'X-CSRF-Token': fccCsrf()") !== false) {
    ok('F-018 radio-widget.js streaming TX sends the X-CSRF-Token header');
} else {
    bad('F-018 radio-widget.js streaming TX does not send X-CSRF-Token');
}

// ── Sanity: csrf_verify() itself still behaves correctly ─────────────
// (Full coverage lives in tools/test_security.php; this is a cheap
// direct sanity check that the function this whole bundle depends on
// has not regressed.)
$_SESSION['csrf_token'] = 'test-token-abc123';
if (csrf_verify('test-token-abc123') === true) {
    ok('csrf_verify() accepts the correct token');
} else {
    bad('csrf_verify() rejected the correct token');
}
if (csrf_verify('wrong-token') === false) {
    ok('csrf_verify() rejects an incorrect token');
} else {
    bad('csrf_verify() accepted an incorrect token');
}
if (csrf_verify('') === false) {
    ok('csrf_verify() rejects an empty token');
} else {
    bad('csrf_verify() accepted an empty token');
}
unset($_SESSION['csrf_token']);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
