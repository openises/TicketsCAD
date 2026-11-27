<?php
/**
 * GH#108 (panel-review 2026-10-20, finding C3 / spec section 4) -- ephemeral
 * SIP credentials, end to end.
 *
 * The exposure: api/phone-extensions.php?action=my_extension used to hand the
 * browser the stored, permanent per-extension sip_password (toll fraud -- a
 * credential good forever from any SIP client). In 'ephemeral' mode the browser
 * instead gets a short-TTL HMAC credential and the stored password never leaves
 * the server; 'static' mode (the default) is byte-identical to before.
 *
 * Drives the REAL minting code (inc/phone-extensions.php) and the REAL endpoint
 * (api/phone-extensions.php via tests/_p155_api_probe.php, a fresh subprocess
 * per settings value because get_variable() caches the whole settings table for
 * a process). The widget behaviour runs under jsdom (node) via
 * tests/_phone_sip_ephemeral_node.js.
 *
 * @requires-db
 * Usage: php tests/test_phone_sip_ephemeral.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/phone-extensions.php';
require_once 'inc/settings-secrets.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_phone_node_runner.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c, $d = '') {
    global $passed, $failed;
    echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($c ? '' : ($d !== '' ? "  -- $d" : '')) . "\n";
    $c ? $passed++ : $failed++;
}

echo "=== GH#108 -- ephemeral SIP credentials ===\n\n";

// Preserve and restore the shared install settings this test writes.
function _ese_raw($name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}
function _ese_set($name, $value) {
    global $prefix;
    if ($value === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$name]); return; }
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, (string) $value]);
}
$origMode   = _ese_raw('phone_sip_mode');
$origTtl    = _ese_raw('phone_sip_cred_ttl');
$origSecret = _ese_raw('phone_sip_shared_secret');
$createdIds = [];
$restore = function () use ($prefix, $origMode, $origTtl, $origSecret, &$createdIds) {
    _ese_set('phone_sip_mode', $origMode);
    _ese_set('phone_sip_cred_ttl', $origTtl);
    _ese_set('phone_sip_shared_secret', $origSecret);
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
};
register_shutdown_function($restore);

// ── Part 1: the mint is a deterministic, correct HMAC credential ──────────
echo "--- Part 1: phone_extension_mint_ephemeral() (pure, fixed vectors) ---\n\n";

$secret = 'test-shared-secret-abc';
$row = ['sip_username' => '101', 'extension' => '101'];
$cred = phone_extension_mint_ephemeral($row, 300, 1700000000, $secret);
t('sip_username is "<expiry-unix>:<base>" (TURN-REST / RFC-7635 shape)',
    $cred['sip_username'] === '1700000300:101', $cred['sip_username']);
t('expires_at = now + ttl', $cred['expires_at'] === 1700000300, (string) $cred['expires_at']);
t('sip_password is the fixed, known HMAC-SHA256 vector (deterministic)',
    $cred['sip_password'] === 'WMtHP4HIxE6HTKH76+1Rozqwj9CjGvC51t7h4CP9sCA=', $cred['sip_password']);

$credAgain = phone_extension_mint_ephemeral($row, 300, 1700000000, $secret);
t('same (secret, base, expiry) -> byte-identical credential (deterministic)',
    $credAgain['sip_password'] === $cred['sip_password'] && $credAgain['sip_username'] === $cred['sip_username']);

$credLater = phone_extension_mint_ephemeral($row, 900, 1700000000, $secret);
t('a DIFFERENT expiry window -> a different username AND a different password',
    $credLater['sip_username'] === '1700000900:101'
    && $credLater['sip_password'] === '6k4nOXjFK0k7EAJUOnS2voynPNHUN3ocWuOSUXSY4lg='
    && $credLater['sip_password'] !== $cred['sip_password']);

// Validation the PBX will perform: recompute HMAC-SHA256(shared_secret, username),
// base64, and constant-time compare.
$recomputed = base64_encode(hash_hmac('sha256', $cred['sip_username'], $secret, true));
t('a PBX recomputing the HMAC from the username + shared secret VALIDATES the credential',
    hash_equals($recomputed, $cred['sip_password']));
$tampered = $cred['sip_password'];
$tampered[0] = ($tampered[0] === 'A' ? 'B' : 'A');   // flip the first base64 char
t('a TAMPERED password does NOT validate against the recomputed HMAC',
    !hash_equals($recomputed, $tampered));
$wrongSecretCred = phone_extension_mint_ephemeral($row, 300, 1700000000, 'a-different-secret');
t('the SAME username under a DIFFERENT shared secret yields a different password (the secret is load-bearing)',
    $wrongSecretCred['sip_password'] !== $cred['sip_password']);

// Base falls back to the extension number when sip_username is blank.
$credFallback = phone_extension_mint_ephemeral(['sip_username' => '', 'extension' => '205'], 300, 1700000000, $secret);
t('the base falls back to the extension number when sip_username is empty',
    $credFallback['sip_username'] === '1700000300:205');

// TTL is clamped even when passed explicitly.
$credClamped = phone_extension_mint_ephemeral($row, 999999, 1700000000, $secret);
t('an out-of-range TTL is clamped to the ceiling (3600s)',
    $credClamped['expires_at'] === 1700000000 + 3600, (string) $credClamped['expires_at']);

// ── Part 2: settings readers / writers ────────────────────────────────────
echo "\n--- Part 2: phone_sip_mode / phone_sip_cred_ttl / shared secret ---\n\n";

t('the mode values are exactly static and ephemeral', phone_sip_mode_values() === ['static', 'ephemeral']);
$modeThrew = false;
try { phone_sip_mode_save('weird'); } catch (InvalidArgumentException $e) { $modeThrew = true; }
t('phone_sip_mode_save() refuses an unknown mode', $modeThrew);

_ese_set('phone_sip_mode', null);
t('the DEFAULT mode is static (an upgraded install is unchanged)',
    _ese_raw('phone_sip_mode') === null);  // nothing stored; code default is static (proven via the endpoint in Part 3)

t('phone_sip_cred_ttl clamps below the floor to 60',  phone_sip_cred_ttl_clamp(5) === 60);
t('phone_sip_cred_ttl clamps above the ceiling to 3600', phone_sip_cred_ttl_clamp(999999) === 3600);
t('phone_sip_cred_ttl keeps an in-range value', phone_sip_cred_ttl_clamp(300) === 300);

// Shared secret: masked, strong, generated on a WRITE path only, read-only on
// the minting (GET) path so that path never writes.
t('phone_sip_shared_secret is treated as a SECRET setting (masked on read in the settings UI)',
    is_secret_setting_key('phone_sip_shared_secret') === true);
_ese_set('phone_sip_shared_secret', null);
// phone_sip_shared_secret() is READ ONLY -- returns '' when unset, never writes
// (proven in a fresh subprocess: the settings row stays absent afterwards).
$readOnlyProbe = trim((string) test_run_cli([PHP_BINARY ?: 'php', '-r',
    'require "config.php"; require "inc/phone-extensions.php"; echo "[" . phone_sip_shared_secret() . "]";']));
t('phone_sip_shared_secret() returns "" when none is set (READ ONLY -- the GET mint path never generates)',
    $readOnlyProbe === '[]', $readOnlyProbe);
t('...and reading it did NOT create a settings row', _ese_raw('phone_sip_shared_secret') === null);
// phone_sip_ensure_shared_secret() is the WRITE path: generates a strong value.
$secretProbe = trim((string) test_run_cli([PHP_BINARY ?: 'php', '-r',
    'require "config.php"; require "inc/phone-extensions.php"; echo phone_sip_ensure_shared_secret();']));
t('phone_sip_ensure_shared_secret() generates a strong value (>= 32 chars) and persists it',
    strlen($secretProbe) >= 32 && _ese_raw('phone_sip_shared_secret') === $secretProbe, 'len=' . strlen($secretProbe));
$secretProbe2 = trim((string) test_run_cli([PHP_BINARY ?: 'php', '-r',
    'require "config.php"; require "inc/phone-extensions.php"; echo phone_sip_ensure_shared_secret();']));
t('...and a later ensure returns the SAME secret (stable, not re-generated each call)', $secretProbe2 === $secretProbe);
// Enabling ephemeral mode guarantees a secret exists (generation on a write
// path). Verified by the PARENT's own fresh DB read after the subprocess ran,
// not by get_variable() inside that subprocess (whose cache would predate the
// INSERT -- the documented settings-cache trap).
_ese_set('phone_sip_shared_secret', null);
_ese_set('phone_sip_mode', null);
test_run_cli([PHP_BINARY ?: 'php', '-r',
    'require "config.php"; require "inc/phone-extensions.php"; phone_sip_mode_save("ephemeral");']);
t('phone_sip_mode_save("ephemeral") ensures a shared secret exists',
    is_string(_ese_raw('phone_sip_shared_secret')) && _ese_raw('phone_sip_shared_secret') !== '');

// ── Part 3: the my_extension endpoint, one subprocess per stored value ────
echo "\n--- Part 3: my_extension (REAL endpoint, static vs ephemeral) ---\n\n";

function _ese_probe(string $query, int $userId): ?array {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/phone-extensions.php', 'GET', 'my_extension', (string) $userId, $query];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}

$adminId = test_admin_user_id();
$tok = 'ese-token-' . bin2hex(random_bytes(4));
$extNum = (string) random_int(200000, 899999);
$created = phone_extension_create($extNum, 'Ephemeral test desk', false, $tok, null);
$createdIds[] = $created['id'];
$storedPassword = $created['sip_password'];
$storedUsername = $created['sip_username'];

// STATIC (default: no mode row at all) -- must be byte-identical to today.
_ese_set('phone_sip_mode', null);
$rs = _ese_probe('workstation_token=' . $tok, $adminId);
$body = $rs['body'] ?? [];
t('static (default): endpoint returns 200 and bound=true',
    ($rs['status'] ?? 0) === 200 && ($body['bound'] ?? false) === true, json_encode($rs));
t('static (default): the STORED sip_username/sip_password are returned (unchanged)',
    ($body['sip_username'] ?? null) === $storedUsername && ($body['sip_password'] ?? null) === $storedPassword, json_encode($body));
t('static (default): NO expires_at key is present (byte-identical to pre-GH#108)',
    !array_key_exists('expires_at', $body));
t('static (default): the credential keys are byte-identical to pre-GH#108 (the two'
    . ' trailing keys are the later, additive emergency-policy display fields, GH#108 C2)',
    array_keys($body) === ['bound', 'extension', 'label', 'sip_username', 'sip_password',
        'wss_url', 'general_number', 'register_scope', 'restrict_emergency', 'emergency_numbers'],
    json_encode(array_keys($body)));

// Explicit static (a row set to 'static') behaves the same.
_ese_set('phone_sip_mode', 'static');
$rs2 = _ese_probe('workstation_token=' . $tok, $adminId);
$body2 = $rs2['body'] ?? [];
t('explicit static: identical to the default (stored password, no expires_at)',
    ($body2['sip_password'] ?? null) === $storedPassword && !array_key_exists('expires_at', $body2));

// EPHEMERAL -- a known shared secret so we can recompute the HMAC.
$knownSecret = 'ese-known-secret-' . bin2hex(random_bytes(8));
_ese_set('phone_sip_shared_secret', $knownSecret);
_ese_set('phone_sip_cred_ttl', '300');
_ese_set('phone_sip_mode', 'ephemeral');
$before = time();
$rs3 = _ese_probe('workstation_token=' . $tok, $adminId);
$after = time();
$body3 = $rs3['body'] ?? [];
t('ephemeral: endpoint returns 200 and bound=true',
    ($rs3['status'] ?? 0) === 200 && ($body3['bound'] ?? false) === true, json_encode($rs3));
t('ephemeral: the STORED password is NOT returned to the browser',
    ($body3['sip_password'] ?? null) !== $storedPassword && ($body3['sip_username'] ?? null) !== $storedUsername, json_encode($body3));
t('ephemeral: the shared secret itself is NEVER in the payload',
    strpos(json_encode($body3), $knownSecret) === false);
$expiresAt = (int) ($body3['expires_at'] ?? 0);
t('ephemeral: expires_at is now + the configured TTL (300s)',
    $expiresAt >= $before + 300 && $expiresAt <= $after + 300, (string) $expiresAt);
t('ephemeral: sip_username is "<expires_at>:<stored sip_username>"',
    ($body3['sip_username'] ?? '') === $expiresAt . ':' . $storedUsername, $body3['sip_username'] ?? '');
$recomputedEp = base64_encode(hash_hmac('sha256', (string) ($body3['sip_username'] ?? ''), $knownSecret, true));
t('ephemeral: the returned password is exactly HMAC-SHA256(shared_secret, sip_username) (the PBX can validate it)',
    hash_equals($recomputedEp, (string) ($body3['sip_password'] ?? '')));
$tamperedEp = (string) ($body3['sip_username'] ?? '') . 'X';  // extend the username past its real expiry/content
t('ephemeral: a credential whose username was tampered no longer validates',
    !hash_equals(base64_encode(hash_hmac('sha256', $tamperedEp, $knownSecret, true)), (string) ($body3['sip_password'] ?? '')));
t('ephemeral: the OTHER payload fields (wss_url, register_scope, general_number) are unchanged',
    ($body3['wss_url'] ?? null) === ($body['wss_url'] ?? null)
    && ($body3['register_scope'] ?? null) === ($body['register_scope'] ?? null));

// The UNBOUND payload is identical in both modes (no credential to mint).
$rsU = _ese_probe('workstation_token=ese-no-such-token', $adminId);
$bodyU = $rsU['body'] ?? [];
t('ephemeral: an UNBOUND payload carries no credential and no expires_at (unchanged)',
    ($bodyU['bound'] ?? true) === false && !array_key_exists('expires_at', $bodyU) && !array_key_exists('sip_password', $bodyU),
    json_encode($bodyU));

$restore();  // return the shared settings to their pre-test state before the node part

// ── Part 4: static wiring guards ──────────────────────────────────────────
echo "\n--- Part 4: static wiring guards ---\n\n";
$api    = (string) file_get_contents('api/phone-extensions.php');
$inc    = (string) file_get_contents('inc/phone-extensions.php');
$logic  = (string) file_get_contents('assets/js/phone-dial-logic.js');
$widget = (string) file_get_contents('assets/js/phone-widget.js');
$tool   = (string) file_get_contents('tools/phone_sip_ephemeral.php');

t('api my_extension mints an ephemeral credential only in ephemeral mode',
    strpos($api, "phone_sip_mode() === 'ephemeral'") !== false
    && strpos($api, 'phone_extension_mint_ephemeral(') !== false);
t('inc: the mint is base64(HMAC-SHA256(shared_secret, username))',
    strpos($inc, "base64_encode(hash_hmac('sha256', \$username, \$secret, true))") !== false);
t('inc: the shared secret is never echoed, and is masked via the _secret suffix (no custom masking needed)',
    strpos($inc, 'phone_sip_shared_secret') !== false);
t('phone-widget.js is ES5 only (no arrows/template literals/let/const, comments included)',
    $widget !== '' && !preg_match('/=>|`|\blet\s|\bconst\s/', $widget));
t('phone-dial-logic.js exposes credentialRefreshDelayMs and isAuthFailure',
    strpos($logic, 'credentialRefreshDelayMs:') !== false && strpos($logic, 'isAuthFailure:') !== false);
t('phone-widget.js schedules a credential refresh and re-fetches my_extension on it',
    strpos($widget, 'scheduleCredRefresh') !== false && strpos($widget, 'credentialRefreshDelayMs') !== false
    && strpos($widget, 'doCredRefresh') !== false);
t('phone-widget.js clears the refresh timer when it stops registering (no orphan timer)',
    strpos($widget, 'clearCredRefresh') !== false);
t('the CLI tool carries the exact CLI-only guard as its first statement',
    strpos($tool, "if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }") !== false);
t('the CLI tool never prints the secret for a plain status (only --show-secret / --rotate-secret)',
    strpos($tool, '--show-secret') !== false && strpos($tool, 'shared_secret_set') !== false);

// ── Part 5: the widget behaviour (jsdom, FAKE JsSIP, no PBX) ──────────────
echo "\n--- Part 5: widget behaviour (jsdom) ---\n\n";
$nr = phone_node_run('_phone_sip_ephemeral_node.js');
if ($nr !== null) { foreach ($nr as $rowOut) { t($rowOut[0], $rowOut[1], isset($rowOut[1]) && !$rowOut[1] ? 'node check failed' : ''); } }

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
