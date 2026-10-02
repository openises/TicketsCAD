<?php
/**
 * Phase 153 (2026-09-08) — phone_extensions CRUD tests.
 *
 * Drives the REAL functions in inc/phone-extensions.php against throwaway
 * fixtures, cleaned up by reference. Covers validation, create/list/get/
 * update/rotate/delete, immutability of extension+credentials on edit,
 * duplicate-extension refusal, and static wiring guards on the API/page/
 * sidebar.
 *
 * Usage: php tests/test_phone_extensions.php
 */
chdir(__DIR__ . '/..');
require_once 'config.php';
require_once 'inc/db.php';
require_once 'inc/phone-extensions.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }

echo "=== Phase 153 -- phone_extensions CRUD ===\n\n";

$createdIds = [];
register_shutdown_function(function () use (&$createdIds, $prefix) {
    foreach ($createdIds as $id) {
        try { db_query("DELETE FROM `{$prefix}phone_extensions` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
});

function uniq_ext() {
    // 2-10 digits only (validate() requirement) -- a unique-enough test extension.
    return (string) rand(200000, 999999);
}

// ── Validation ───────────────────────────────────────────────────────
function expect_invalid($label, $fn) {
    try { $fn(); t($label, false); }
    catch (InvalidArgumentException $e) { t($label, true); }
    catch (Exception $e) { t($label . ' (wrong exception type: ' . get_class($e) . ')', false); }
}

expect_invalid('empty extension is rejected',
    function () { phone_extension_validate('', 'Label', false); });
expect_invalid('non-numeric extension is rejected',
    function () { phone_extension_validate('abc', 'Label', false); });
expect_invalid('a 1-digit extension is rejected (min 2 digits)',
    function () { phone_extension_validate('5', 'Label', false); });
expect_invalid('empty label is rejected',
    function () { phone_extension_validate('100', '', false); });

// ── Create ───────────────────────────────────────────────────────────
$ext1 = uniq_ext();
$created1 = phone_extension_create($ext1, 'Workstation 1 (test)', false, 'ws-token-test-1', null);
$createdIds[] = $created1['id'];
t('create() returns a positive integer id', $created1['id'] > 0);
t('create() mints a sip_username equal to the extension number', $created1['sip_username'] === $ext1);
t('create() mints a real, non-empty password', strlen($created1['sip_password']) >= 20);

$row1 = phone_extension_get($created1['id']);
t('the row is_general=0 (direct number)', (int) $row1['is_general'] === 0);
t('the row stores the bound workstation_token', $row1['workstation_token'] === 'ws-token-test-1');
t('the row is enabled=1 by default', (int) $row1['enabled'] === 1);

// General number, no workstation binding.
$extGen = uniq_ext();
$createdGen = phone_extension_create($extGen, 'General Number (test)', true, '', null);
$createdIds[] = $createdGen['id'];
$rowGen = phone_extension_get($createdGen['id']);
t('a general-number create has is_general=1', (int) $rowGen['is_general'] === 1);
t('an empty workstation_token is stored as NULL, not an empty string',
    $rowGen['workstation_token'] === null);

// ── Duplicate extension refusal ──────────────────────────────────────
$dupeThrew = false;
try {
    phone_extension_create($ext1, 'Different Label', false, '', null);
} catch (RuntimeException $e) {
    $dupeThrew = true;
}
t('creating a second extension with the SAME number throws (RuntimeException -> 409)', $dupeThrew);

// ── List ─────────────────────────────────────────────────────────────
$list = phone_extension_list();
$foundInList = false;
foreach ($list as $row) { if ((int) $row['id'] === $created1['id']) { $foundInList = true; break; } }
t('list() includes the freshly-created extension', $foundInList);

// ── Update ───────────────────────────────────────────────────────────
$updated = phone_extension_update($created1['id'], 'Renamed Workstation', false, 'ws-token-test-1-new', null, false);
t('update() returns the updated row', $updated !== null);
t('update() changes the label', $updated['label'] === 'Renamed Workstation');
t('update() changes the workstation_token', $updated['workstation_token'] === 'ws-token-test-1-new');
t('update() changes enabled to false', (int) $updated['enabled'] === 0);

$rawAfterUpdate = db_fetch_one("SELECT extension, sip_username, sip_password FROM `{$prefix}phone_extensions` WHERE id = ?", [$created1['id']]);
t('update() NEVER changes the extension number', $rawAfterUpdate['extension'] === $ext1);
t('update() NEVER changes sip_username/sip_password (credential rotation is a distinct action)',
    $rawAfterUpdate['sip_username'] === $created1['sip_username']
    && $rawAfterUpdate['sip_password'] === $created1['sip_password']);

$updateMissingThrew = false;
try { phone_extension_update(999999999, 'X', false, '', null, true); }
catch (RuntimeException $e) { $updateMissingThrew = true; }
t('update() on a nonexistent id throws (-> 404)', $updateMissingThrew);

// ── Rotate password ──────────────────────────────────────────────────
$oldPassword = $created1['sip_password'];
$newPassword = phone_extension_rotate_password($created1['id']);
t('rotate_password() returns a NEW password, different from the old one',
    $newPassword !== '' && $newPassword !== $oldPassword);
$rawAfterRotate = db_fetch_one("SELECT sip_password FROM `{$prefix}phone_extensions` WHERE id = ?", [$created1['id']]);
t('the new password is actually persisted', $rawAfterRotate['sip_password'] === $newPassword);

// ── Delete ───────────────────────────────────────────────────────────
$ext2 = uniq_ext();
$created2 = phone_extension_create($ext2, 'To Be Deleted', false, '', null);
// Intentionally NOT added to $createdIds -- delete() below is what removes it.
$deleted = phone_extension_delete($created2['id']);
t('delete() returns the deleted row\'s data', $deleted !== null && $deleted['extension'] === $ext2);
$goneRow = db_fetch_one("SELECT id FROM `{$prefix}phone_extensions` WHERE id = ?", [$created2['id']]);
t('delete() actually removed the row', $goneRow === null);
$deleteAgain = phone_extension_delete($created2['id']);
t('delete() on an already-deleted id returns null, not an error', $deleteAgain === null);

// ── Workstation-token lookup (browser calling UI) ────────────────────
$extWs = uniq_ext();
$createdWs = phone_extension_create($extWs, 'Workstation for token test', false, 'ws-lookup-token-xyz', null);
$createdIds[] = $createdWs['id'];
$foundByToken = phone_extension_find_by_workstation_token('ws-lookup-token-xyz');
t('find_by_workstation_token() finds the bound direct extension', $foundByToken !== null && (int) $foundByToken['id'] === $createdWs['id']);
t('find_by_workstation_token() never matches a general number',
    phone_extension_find_by_workstation_token('') === null);
$noMatch = phone_extension_find_by_workstation_token('no-such-token-exists-anywhere');
t('find_by_workstation_token() returns null for an unbound token', $noMatch === null);

// Disable it and confirm the lookup stops matching.
phone_extension_update($createdWs['id'], 'Workstation for token test', false, 'ws-lookup-token-xyz', null, false);
$foundAfterDisable = phone_extension_find_by_workstation_token('ws-lookup-token-xyz');
t('find_by_workstation_token() ignores a disabled extension', $foundAfterDisable === null);

$extGenForLookup = uniq_ext();
$createdGenForLookup = phone_extension_create($extGenForLookup, 'General for lookup test', true, '', null);
$createdIds[] = $createdGenForLookup['id'];
$foundGeneral = phone_extension_find_general();
t('find_general() finds an enabled general-number extension', $foundGeneral !== null);

// ── PBX connection settings ───────────────────────────────────────────
// get_variable() caches the whole settings table for the life of the PHP
// process with no invalidation hook (this project's own documented
// Phase 151 lesson) -- so verify the WRITE via a direct, uncached SQL
// read, not through phone_pbx_settings_get() in the same process (that
// would read back whatever get_variable() happened to cache on its
// FIRST call earlier in this same script, not the fresh value).
function _raw_setting($name) {
    global $prefix;
    $row = db_fetch_one("SELECT `value` FROM `{$prefix}settings` WHERE `name` = ?", [$name]);
    return $row ? $row['value'] : null;
}
$origWssUrl = _raw_setting('phone_pbx_wss_url');
$origGeneral = _raw_setting('phone_general_number');
phone_pbx_settings_save('wss://10.0.0.10:8089/ws', '100');
t('pbx_settings_save() persists the WSS URL', _raw_setting('phone_pbx_wss_url') === 'wss://10.0.0.10:8089/ws');
t('pbx_settings_save() persists the general number', _raw_setting('phone_general_number') === '100');

$rejectedScheme = false;
try { phone_pbx_settings_save('https://not-a-websocket-url', '100'); }
catch (InvalidArgumentException $e) { $rejectedScheme = true; }
t('pbx_settings_save() rejects a non-wss:// URL', $rejectedScheme);

$rejectedGeneral = false;
try { phone_pbx_settings_save('wss://x:8089/ws', 'not-digits'); }
catch (InvalidArgumentException $e) { $rejectedGeneral = true; }
t('pbx_settings_save() rejects a non-numeric general number', $rejectedGeneral);

// Restore whatever was there before this test ran (this is shared settings state).
phone_pbx_settings_save((string) $origWssUrl, (string) $origGeneral);

// ── Static wiring guards ─────────────────────────────────────────────
$api = (string) @file_get_contents('api/phone-extensions.php');
t('api/phone-extensions.php: RBAC-gated on action.manage_calls',
    strpos($api, "rbac_can('action.manage_calls')") !== false);
t('api/phone-extensions.php: my_extension is reachable WITHOUT action.manage_calls '
    . '(every operator needs their own bound extension, not just admins)',
    strpos($api, "action === 'my_extension'") !== false
    && strpos($api, "action === 'my_extension'") < strpos($api, 'pe_require_perm();'));
t('api/phone-extensions.php: deliberately NOT `|| is_admin()` on the RBAC gate',
    !(bool) preg_match('/rbac_can\(\'action\.manage_calls\'\)\s*\|\|\s*is_admin\(\)/', $api));
t('api/phone-extensions.php: CSRF-checked on every mutating action',
    substr_count($api, 'pe_csrf_check(') >= 4);
t('api/phone-extensions.php: masks sip_password on list/get (pe_mask)',
    substr_count($api, 'pe_mask(') >= 3);
t('api/phone-extensions.php: every mutation is audited', substr_count($api, 'audit_log(') >= 4);

$page = (string) @file_get_contents('phone-extensions-admin.php');
t('phone-extensions-admin.php: session gate, RBAC gate, CSRF meta, cache-busted assets',
    strpos($page, "empty(\$_SESSION['user_id'])") !== false
    && strpos($page, "rbac_can('action.manage_calls')") !== false
    && strpos($page, 'csrf-token') !== false
    && strpos($page, 'asset_v(') !== false);

$sidebar = (string) @file_get_contents('inc/config-sidebar.php');
t('config-sidebar.php links to phone-extensions-admin.php, gated on action.manage_calls',
    strpos($sidebar, 'phone-extensions-admin.php') !== false);

$jsFile = (string) @file_get_contents('assets/js/phone-extensions-admin.js');
t('phone-extensions-admin.js saves PBX connection settings', strpos($jsFile, "apiPost('pbx_settings_save'") !== false);

$widgetJs = (string) @file_get_contents('assets/js/phone-widget.js');
t('phone-widget.js is ES5 only (no arrows/template literals/let/const)',
    $widgetJs !== '' && !preg_match('/=>|`|\\blet\\s|\\bconst\\s/', $widgetJs));
t('phone-widget.js resolves its own extension via my_extension, not any admin-only action',
    strpos($widgetJs, "action=my_extension") !== false);
t('phone-widget.js reads the workstation token via window.ConsoleWorkstation (Phase 152\'s convention)',
    strpos($widgetJs, 'ConsoleWorkstation.getToken') !== false);
t('phone-widget.js persists its open/closed state across navigation (Zello\'s own 2026-09-08 fix, applied from day one here)',
    strpos($widgetJs, "localStorage.getItem('phone_widget_open')") !== false
    && strpos($widgetJs, "wasOpen") !== false);

$navbar = (string) @file_get_contents('inc/navbar.php');
t('navbar.php loads the JsSIP vendor bundle before phone-widget.js',
    strpos($navbar, 'assets/vendor/jssip/jssip-3.10.1.min.js') < strpos($navbar, 'assets/js/phone-widget.js'));
t('navbar.php includes the phone widget template', strpos($navbar, "phone-widget-template.php") !== false);

t('phone-extensions-admin.js is ES5 only (no arrows/template literals/let/const)',
    $jsFile !== '' && !preg_match('/=>|`|\\blet\\s|\\bconst\\s/', $jsFile));
t('phone-extensions-admin.js disables the extension-number field when editing (it\'s immutable)',
    strpos($jsFile, "getElementById('peExtension').disabled = true") !== false);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed > 0 ? 1 : 0);
