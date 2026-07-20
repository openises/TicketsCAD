<?php
/**
 * Regression test for the User Accounts admin features (2026-06-23):
 * the Unlock control + last-login/2FA fields on the user edit panel.
 * Static presence checks (guard against the wiring being removed).
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
$base = realpath(__DIR__ . '/..');
echo "=== User Accounts admin features ===\n\n";
$pass = 0; $fail = 0;
function ok($n){ global $pass; echo "[PASS] $n\n"; $pass++; }
function bad($n,$w=''){ global $fail; echo "[FAIL] $n".($w?" — $w":'')."\n"; $fail++; }

$capi = file_get_contents("$base/api/config-admin.php");
strpos($capi, '`login` AS `last_login`') !== false ? ok('users API returns last_login') : bad('users API missing last_login');
strpos($capi, 'AS `tfa_enrolled`') !== false ? ok('users API returns tfa_enrolled') : bad('users API missing tfa_enrolled');

$lsec = file_get_contents("$base/api/login-security.php");
strpos($lsec, "=== 'unlock_account'") !== false ? ok('login-security has unlock_account action') : bad('unlock_account action missing');

$set = file_get_contents("$base/settings.php");
foreach (['btnUnlockUser','userLastLogin','userTfaStatus','userAdminInfo'] as $id) {
    strpos($set, "id=\"$id\"") !== false ? ok("settings.php has #$id") : bad("settings.php missing #$id");
}

$cjs = file_get_contents("$base/assets/js/config.js");
strpos($cjs, 'function unlockUserAccount') !== false ? ok('config.js has unlockUserAccount()') : bad('unlockUserAccount missing');
strpos($cjs, "action=unlock_account") !== false ? ok('config.js posts unlock_account') : bad('config.js unlock call missing');
strpos($cjs, 'userLastLogin') !== false && strpos($cjs, 'userTfaStatus') !== false ? ok('config.js populates last_login + 2FA') : bad('config.js field population missing');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
