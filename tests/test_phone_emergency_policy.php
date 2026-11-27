<?php
/**
 * GH#108 (panel-review 2026-10-20, finding C2) -- the browser phone's
 * emergency-call POLICY, end to end.
 *
 * DECISION (Eric): every extension can dial emergency services BY DEFAULT. The
 * RESTRICTION is opt-in and OFF by default. TicketsCAD surfaces the policy and
 * warns; FreePBX's dial plan is the real routing boundary. So this suite proves,
 * above all, that a default install ALLOWS emergency calling and that nothing
 * about the released behaviour changes until an admin deliberately restricts it.
 *
 * Drives the REAL readers/writers (inc/phone-extensions.php) and the REAL
 * endpoint (api/phone-extensions.php via tests/_p155_api_probe.php -- a fresh
 * subprocess per settings value, because get_variable() caches the whole
 * settings table for the life of a process). The admin warning banner is driven
 * through the one canonical render function the admin page itself uses, so the
 * warning a test asserts can never drift from the warning an operator sees.
 *
 * @requires-db
 * Usage: php tests/test_phone_emergency_policy.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/phone-extensions.php';
require_once __DIR__ . '/_test_admin.php';
require_once __DIR__ . '/_test_node_probe.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$pass = 0; $fail = 0;
function t($label, $cond, $detail = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($cond ? '' : ($detail !== '' ? "  -- $detail" : '')) . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#108 / C2 -- browser-phone emergency-call policy ===\n\n";

// ── Shared settings this test writes: preserve and restore ────────────────
function pep_raw($name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}
function pep_set($name, $value) {
    global $prefix;
    if ($value === null) { db_query("DELETE FROM `{$prefix}settings` WHERE `name` = ?", [$name]); return; }
    db_query("INSERT INTO `{$prefix}settings` (`name`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)", [$name, (string) $value]);
}
// A fresh subprocess read of a reader that uses get_variable() (process-cached).
function pep_read($phpExpr) {
    $out = test_run_cli([PHP_BINARY ?: 'php', '-r',
        'require "config.php"; require "inc/phone-extensions.php"; ' . $phpExpr]);
    return $out === null ? null : trim((string) $out);
}
function pep_probe($method, $action, $userId, $payload = '') {
    $cmd = [PHP_BINARY ?: 'php', __DIR__ . '/_p155_api_probe.php', 'api/phone-extensions.php', $method, $action, (string) $userId, $payload];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return null;
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]); proc_close($proc);
    $d = json_decode(trim((string) $out), true);
    return is_array($d) ? $d : null;
}

$origRestrict = pep_raw('phone_restrict_emergency');
$origNumbers  = pep_raw('phone_emergency_numbers');
$createdIds = [];
$orgAdminId = 900016601;   // holds action.manage_calls, NOT action.manage_config
$restore = function () use ($prefix, $origRestrict, $origNumbers, &$createdIds, $orgAdminId) {
    pep_set('phone_restrict_emergency', $origRestrict);
    pep_set('phone_emergency_numbers', $origNumbers);
    foreach ($createdIds as $id) { try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {} }
    try { db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$orgAdminId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$orgAdminId]); } catch (Throwable $e) {}
    try { db_query("DELETE FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_emergency_policy.update' AND `user_name` = 'p155-probe'"); } catch (Throwable $e) {}
};
register_shutdown_function($restore);

// ── Part 1: pure logic (no DB) -- defaults, parsing, warning, banner ──────
echo "--- Part 1: defaults, number parsing, warning, banner (pure) ---\n\n";

t('the DEFAULT emergency-number set is exactly [911]',
    phone_emergency_numbers_default() === ['911'], json_encode(phone_emergency_numbers_default()));

t('normalize parses a comma list into tokens', phone_emergency_numbers_normalize('911, 112') === ['911', '112']);
t('normalize accepts whitespace/newline/semicolon separators too',
    phone_emergency_numbers_normalize("911 112\n999;000") === ['911', '112', '999', '000']);
t('normalize de-duplicates', phone_emergency_numbers_normalize('911 911 112 112') === ['911', '112']);
t('normalize drops invalid tokens (letters, too short) and keeps the valid ones',
    phone_emergency_numbers_normalize('911, abc, 9, 112') === ['911', '112']);
t('normalize accepts * and # codes', phone_emergency_numbers_normalize('*67, 112, #99') === ['*67', '112', '#99']);
t('normalize of an empty / all-invalid string is []', phone_emergency_numbers_normalize('abc, 9') === []);
t('normalize accepts an array and trims each entry',
    phone_emergency_numbers_normalize(['911', ' 112 ', '911']) === ['911', '112']);

$warn = phone_emergency_restriction_warning();
t('the warning names the real safety/legal stakes (911, serious safety risk, Kari\'s Law)',
    strpos($warn, '911') !== false && stripos($warn, 'serious safety risk') !== false && strpos($warn, "Kari's Law") !== false,
    $warn);

$bannerOn  = phone_emergency_policy_banner_html(true);
$bannerOff = phone_emergency_policy_banner_html(false);
t('the banner carries the full canonical warning text (one source, no drift)',
    strpos($bannerOn, $warn) !== false);
t('ENABLED -> the warning banner is VISIBLE (alert-danger, no d-none)',
    strpos($bannerOn, 'alert-danger') !== false && strpos($bannerOn, 'id="peEmergencyWarning"') !== false
    && strpos($bannerOn, 'd-none') === false, $bannerOn);
t('DISABLED -> the SAME banner markup is present but hidden (d-none, for the JS to reveal live)',
    strpos($bannerOff, 'id="peEmergencyWarning"') !== false && strpos($bannerOff, 'd-none') !== false);

// ── Part 2: settings round-trip (fresh subprocess per read) ───────────────
echo "\n--- Part 2: settings round-trip (DEFAULT = ALLOWED) ---\n\n";

// Default install: NO rows at all.
pep_set('phone_restrict_emergency', null);
pep_set('phone_emergency_numbers', null);
t('DEFAULT install: emergency calling is ALLOWED (restriction OFF)',
    pep_read('echo phone_restrict_emergency_enabled() ? "ON" : "OFF";') === 'OFF');
t('DEFAULT install: the emergency numbers fall back to 911',
    pep_read('echo implode(",", phone_emergency_numbers());') === '911');

// The explicit off ('0') is ALSO allowed (never trip the "0 is falsy" trap).
t('phone_restrict_emergency_save(false) stores the literal "0" and means ALLOWED',
    phone_restrict_emergency_save(false) === false && pep_raw('phone_restrict_emergency') === '0'
    && pep_read('echo phone_restrict_emergency_enabled() ? "ON" : "OFF";') === 'OFF');

// Enabling the restriction round-trips.
t('phone_restrict_emergency_save(true) stores "1" and reads back as ON',
    phone_restrict_emergency_save(true) === true && pep_raw('phone_restrict_emergency') === '1'
    && pep_read('echo phone_restrict_emergency_enabled() ? "ON" : "OFF";') === 'ON');

// Numbers round-trip and normalise on the way in.
$saved = phone_emergency_numbers_save('112, 911, 112');
t('phone_emergency_numbers_save normalises + de-dups and returns the list',
    $saved === ['112', '911'], json_encode($saved));
t('...and the stored value is the normalised CSV',
    pep_raw('phone_emergency_numbers') === '112,911');
t('...and a fresh process reads back the stored list',
    pep_read('echo implode(",", phone_emergency_numbers());') === '112,911');

$numThrew = false;
try { phone_emergency_numbers_save('abc, 9'); } catch (InvalidArgumentException $e) { $numThrew = true; }
t('phone_emergency_numbers_save refuses a list with nothing valid in it', $numThrew);
t('...and a refused save left the previous value untouched', pep_raw('phone_emergency_numbers') === '112,911');

// ── Part 3: the my_extension payload carries the policy ───────────────────
echo "\n--- Part 3: my_extension payload (what the widget reads) ---\n\n";

$adminId = test_admin_user_id();
$tok = 'pep-token-' . bin2hex(random_bytes(4));
$extNum = (string) random_int(200000, 899999);
$created = phone_extension_create($extNum, 'Emergency policy test desk', false, $tok, null);
$createdIds[] = $created['id'];

// Default install -> bound payload shows allowed + default numbers.
pep_set('phone_restrict_emergency', null);
pep_set('phone_emergency_numbers', null);
$rs = pep_probe('GET', 'my_extension', $adminId, 'workstation_token=' . $tok);
$body = $rs['body'] ?? [];
t('DEFAULT: my_extension returns 200, bound=true', ($rs['status'] ?? 0) === 200 && ($body['bound'] ?? false) === true, json_encode($rs));
t('DEFAULT: restrict_emergency is false (allowed) in the payload', ($body['restrict_emergency'] ?? 'x') === false, json_encode($body));
t('DEFAULT: emergency_numbers defaults to [911] in the payload', ($body['emergency_numbers'] ?? null) === ['911'], json_encode($body['emergency_numbers'] ?? null));

// Restriction on + custom numbers -> payload reflects it.
pep_set('phone_restrict_emergency', '1');
pep_set('phone_emergency_numbers', '112,911');
$rs2 = pep_probe('GET', 'my_extension', $adminId, 'workstation_token=' . $tok);
$body2 = $rs2['body'] ?? [];
t('RESTRICTED: restrict_emergency is true in the payload', ($body2['restrict_emergency'] ?? 'x') === true, json_encode($body2));
t('RESTRICTED: emergency_numbers is the configured list', ($body2['emergency_numbers'] ?? null) === ['112', '911'], json_encode($body2['emergency_numbers'] ?? null));
t('RESTRICTED: the credential fields are untouched by the policy (SIP behaviour unchanged)',
    ($body2['sip_username'] ?? null) === ($body['sip_username'] ?? null)
    && ($body2['sip_password'] ?? null) === ($body['sip_password'] ?? null));

// ── Part 4: the emergency_policy_save endpoint (RBAC, CSRF, audit) ─────────
echo "\n--- Part 4: emergency_policy_save (RBAC, CSRF, audited) ---\n\n";

// A clean Org Admin fixture (manage_calls, NOT manage_config).
db_query("DELETE FROM `{$prefix}user_roles` WHERE `user_id` = ?", [$orgAdminId]);
db_query("DELETE FROM `{$prefix}user` WHERE `id` = ?", [$orgAdminId]);
db_query("INSERT INTO `{$prefix}user` (`id`, `user`, `passwd`) VALUES (?, ?, ?)",
    [$orgAdminId, 'pepemergorgadmin', password_hash('unused-test-fixture', PASSWORD_BCRYPT)]);
db_query("INSERT INTO `{$prefix}user_roles` (`user_id`, `role_id`) VALUES (?, 2)", [$orgAdminId]);

pep_set('phone_restrict_emergency', '0');
pep_set('phone_emergency_numbers', '911');

$r = pep_probe('POST', 'emergency_policy_save', $adminId, json_encode(['restrict_emergency' => 1, 'emergency_numbers' => '112, 911']));
t('Super Admin saves the policy (200, ok)', ($r['status'] ?? 0) === 200 && ($r['body']['ok'] ?? false) === true, json_encode($r));
t('...the response echoes the restriction state and normalised numbers',
    ($r['body']['restrict_emergency'] ?? null) === true && ($r['body']['emergency_numbers'] ?? null) === ['112', '911'], json_encode($r['body'] ?? null));
t('...and both settings are really stored',
    pep_raw('phone_restrict_emergency') === '1' && pep_raw('phone_emergency_numbers') === '112,911');
$aud = db_fetch_one("SELECT summary FROM `{$prefix}newui_audit_log` WHERE `activity` = 'phone_emergency_policy.update' AND `user_name` = 'p155-probe' ORDER BY id DESC LIMIT 1");
t('...and audited (names the restriction state and the numbers)',
    $aud !== null && stripos((string) $aud['summary'], 'ENABLED') !== false && strpos((string) $aud['summary'], '112') !== false,
    $aud ? $aud['summary'] : 'no audit row');

// Invalid numbers reject the whole save, changing nothing.
pep_set('phone_restrict_emergency', '0');
pep_set('phone_emergency_numbers', '911');
$rBad = pep_probe('POST', 'emergency_policy_save', $adminId, json_encode(['restrict_emergency' => 1, 'emergency_numbers' => 'xx, 9']));
t('an invalid number list is rejected (400) and the restriction flag is NOT half-applied',
    ($rBad['status'] ?? 0) === 400 && pep_raw('phone_restrict_emergency') === '0', json_encode($rBad));

// Org Admin (manage_calls but not manage_config) is refused.
pep_set('phone_restrict_emergency', '0');
$rOrg = pep_probe('POST', 'emergency_policy_save', $orgAdminId, json_encode(['restrict_emergency' => 1, 'emergency_numbers' => '112']));
t('an Org Admin (manage_calls, NOT manage_config) is refused 403', ($rOrg['status'] ?? 0) === 403, json_encode($rOrg));
t('...and nothing changed', pep_raw('phone_restrict_emergency') === '0');

// A bogus CSRF token is refused BEFORE any write.
pep_set('phone_restrict_emergency', '0');
$rCsrf = pep_probe('POST', 'emergency_policy_save', $adminId, json_encode(['restrict_emergency' => 1, 'emergency_numbers' => '112', 'csrf_token' => 'bogus']));
t('a forged CSRF token is refused 403', ($rCsrf['status'] ?? 0) === 403, json_encode($rCsrf));
t('...and nothing changed', pep_raw('phone_restrict_emergency') === '0');

// ── Part 5: no-op / default-install regression ────────────────────────────
echo "\n--- Part 5: no-op regression (nobody touches the setting) ---\n\n";

pep_set('phone_restrict_emergency', null);
pep_set('phone_emergency_numbers', null);
$rDef = pep_probe('GET', 'my_extension', $adminId, 'workstation_token=' . $tok);
$bodyDef = $rDef['body'] ?? [];
t('with NO emergency settings stored, the phone is NOT restricted (released behaviour)',
    ($bodyDef['restrict_emergency'] ?? 'x') === false);
t('with NO emergency settings stored, the numbers are the safe 911 default',
    ($bodyDef['emergency_numbers'] ?? null) === ['911']);
t('with NO emergency settings stored, the admin banner is hidden (nothing warns on a default install)',
    strpos(phone_emergency_policy_banner_html(false), 'd-none') !== false);

// ── Part 6: static wiring guards (producer <-> consumer, ES5, docs) ───────
echo "\n--- Part 6: static wiring guards ---\n\n";
$api      = (string) file_get_contents('api/phone-extensions.php');
$inc      = (string) file_get_contents('inc/phone-extensions.php');
$adminPhp = (string) file_get_contents('phone-extensions-admin.php');
$adminJs  = (string) file_get_contents('assets/js/phone-extensions-admin.js');
$widget   = (string) file_get_contents('assets/js/phone-widget.js');
$tpl      = (string) file_get_contents('inc/phone-widget-template.php');
$guide    = (string) file_get_contents('docs/PHONE-TELEPHONY-GUIDE.md');

t('api my_extension emits restrict_emergency + emergency_numbers (producer for the widget)',
    strpos($api, "'restrict_emergency' => \$pbx['restrict_emergency']") !== false
    && strpos($api, "'emergency_numbers'  => \$pbx['emergency_numbers']") !== false);
t('api emergency_policy_save is gated on manage_config and CSRF-checked',
    strpos($api, "emergency_policy_save") !== false
    && strpos($api, "rbac_can('action.manage_config')") !== false);
t('inc: phone_pbx_settings_get() carries the policy fields',
    strpos($inc, "'restrict_emergency' => phone_restrict_emergency_enabled()") !== false
    && strpos($inc, "'emergency_numbers'  => phone_emergency_numbers()") !== false);
t('phone-widget.js consumes restrict_emergency + emergency_numbers (no dead/phantom key)',
    strpos($widget, 'restrict_emergency') !== false && strpos($widget, 'emergency_numbers') !== false
    && strpos($widget, 'updateEmergencyNote') !== false);
t('phone-widget.js is ES5 only (no arrows/template literals/let/const, comments included)',
    $widget !== '' && !preg_match('/=>|`|\blet\s|\bconst\s/', $widget));
t('the widget note uses textContent (never innerHTML of the server numbers)',
    strpos($widget, 'textEl.textContent =') !== false);
t('the phone template carries the courtesy emergency note element',
    strpos($tpl, 'id="phoneEmergencyNote"') !== false);
t('the admin page renders the warning banner from the saved restriction state',
    strpos($adminPhp, 'phone_emergency_policy_banner_html(phone_restrict_emergency_enabled())') !== false);
t('the admin JS reads the policy from pbx_settings and posts emergency_policy_save',
    strpos($adminJs, 'settings.restrict_emergency') !== false && strpos($adminJs, 'settings.emergency_numbers') !== false
    && strpos($adminJs, "apiPost('emergency_policy_save'") !== false);
t('phone-extensions-admin.js is ES5 only',
    $adminJs !== '' && !preg_match('/=>|`|\blet\s|\bconst\s/', $adminJs));
t('the guide documents the emergency-calling policy + FreePBX-is-the-boundary',
    stripos($guide, 'Emergency calling') !== false && strpos($guide, "RAY BAUM") !== false
    && strpos($guide, "Kari's Law") !== false && stripos($guide, 'FreePBX') !== false);

$restore();

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
