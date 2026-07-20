<?php
/**
 * Phase 73u regression tests — chat DM scoping, msg_type=system
 * impersonation, router loop-prevention metadata trust.
 *
 * Static-text asserts that prove each patch landed. Where possible
 * the test also drives the function with synthetic state to confirm
 * the behaviour, but the harness doesn't have a writable test DB so
 * end-to-end coverage is light.
 */

$tests = 0; $fails = 0;
function tcheck(bool $cond, string $label): void
{
    global $tests, $fails;
    $tests++;
    if (!$cond) { $fails++; echo "FAIL: $label\n"; }
}

// ── 1. chat.php REST history scopes DMs ───────────────────────────
$chatSrc = file_get_contents(__DIR__ . '/../api/chat.php');
tcheck(strpos($chatSrc, "recipient = 'all'") !== false
    && strpos($chatSrc, "OR user_id = ?") !== false,
    'chat.php REST history filters by recipient + sender for non-admin');
tcheck(strpos($chatSrc, 'is_admin()') !== false
    && preg_match('/if \(is_admin\(\)\) \{/', $chatSrc) === 1,
    'chat.php REST history grants admin bypass');

// ── 2. local_chat.php msg_type whitelist ──────────────────────────
$chanSrc = file_get_contents(__DIR__ . '/../inc/channels/local_chat.php');
tcheck(strpos($chanSrc, "userAllowed = ['text', 'signal', 'alert']") !== false,
    'local_chat.php whitelists user msg_type values');
tcheck(strpos($chanSrc, "_system") !== false
    && strpos($chanSrc, "systemFlag = !empty") !== false,
    'local_chat.php uses _system flag for genuine server events');
tcheck(strpos($chanSrc, '$msgType = \'text\';') !== false,
    'local_chat.php downgrades unknown msg_type to text');

// ── 3. router.php trusts _routed/_route_depth only when forwarded ─
$routerSrc = file_get_contents(__DIR__ . '/../inc/router.php');
tcheck(strpos($routerSrc, "_is_routed_forward") !== false,
    'router.php uses _is_routed_forward flag');
$trustOccurrences = substr_count($routerSrc, '$trusted = !empty($message[\'_is_routed_forward\']);');
tcheck($trustOccurrences >= 2,
    'router.php applies the trust guard in both router_evaluate and router_forward (got '
    . $trustOccurrences . ' occurrences)');
tcheck(strpos($routerSrc, '$depth   = $trusted ? (int) ($message[\'_route_depth\'] ?? 0) : 0;') !== false,
    'router.php depth read is gated by trust flag');
tcheck(strpos($routerSrc, '$routed  = $trusted ? ($message[\'_routed\'] ?? []) : [];') !== false,
    'router.php _routed read is gated by trust flag');
tcheck(strpos($routerSrc, '$forwarded[\'_routed\'] = $trusted ? ($message[\'_routed\'] ?? []) : [];') !== false,
    'router_forward _routed carry-forward also gated by trust flag');

// ── 4. Live-behaviour test: a forged-depth message gets evaluated fresh ─
// Inline-extract the router_evaluate signature to confirm the
// pattern would actually evaluate routes for a depth=99 input
// without the forward flag. Pure static-text proof here; the
// function depends on the DB so we can't run it without state.
tcheck(preg_match('/function router_evaluate\([^)]*\)\s*\{.*?\$trusted = !empty\(\$message\[\'_is_routed_forward\'\]\);/s', $routerSrc) === 1,
    'router_evaluate body begins with the trust check');

echo "Phase 73u chat + router hardening regression: " . ($tests - $fails) . " passed, " . $fails . " failed\n";
exit($fails > 0 ? 1 : 0);
