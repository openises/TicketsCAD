<?php
/**
 * Phase 82b regression — three-permission DMR RBAC slice
 *
 * Verifies:
 *   - The three permissions exist after run_phase82b_dmr_rbac.php ran
 *   - Default role grants match the spec table
 *   - api/dvswitch.php has migrated from is_admin to dvs_require_perm
 *   - api/dmr-audio.php uses dmr_receive
 *
 * Does NOT exercise the HTTP layer (that would require session+CSRF
 * fixtures); the function-level contract is what we lock in here.
 */
require __DIR__ . '/../config.php';

$pass = 0;
$fail = 0;
$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

function _t($pass, $fail, $label, $ok) {
    if ($ok) { echo "[PASS] {$label}\n"; return [$pass+1, $fail]; }
    echo "[FAIL] {$label}\n"; return [$pass, $fail+1];
}

echo "=== Phase 82b DMR RBAC permissions ===\n";

// Permissions exist
$codes = ['action.dmr_configure', 'action.dmr_transmit', 'action.dmr_receive'];
$permIds = [];
foreach ($codes as $code) {
    $row = $pdo->prepare("SELECT id FROM `{$prefix}permissions` WHERE code = ?");
    $row->execute([$code]);
    $id = (int) $row->fetchColumn();
    [$pass, $fail] = _t($pass, $fail, "permission exists: {$code}", $id > 0);
    if ($id > 0) $permIds[$code] = $id;
}

// Default grants from the migration spec.
$expectedGrants = [
    'action.dmr_configure' => [1, 2],
    'action.dmr_transmit'  => [1, 2, 3],
    'action.dmr_receive'   => [1, 2, 3, 4],
];
foreach ($expectedGrants as $code => $expectedRoles) {
    if (!isset($permIds[$code])) continue;
    $rows = $pdo->prepare(
        "SELECT role_id FROM `{$prefix}role_permissions` WHERE permission_id = ?"
    );
    $rows->execute([$permIds[$code]]);
    $actualRoles = array_map('intval', $rows->fetchAll(PDO::FETCH_COLUMN));
    sort($actualRoles);
    sort($expectedRoles);
    $hasAll = empty(array_diff($expectedRoles, $actualRoles));
    [$pass, $fail] = _t($pass, $fail,
        "{$code} granted to default roles: " . implode(',', $expectedRoles),
        $hasAll);
}

// File-level contract checks
$dvsSrc = file_get_contents(__DIR__ . '/../api/dvswitch.php');
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php defines dvs_require_perm helper",
    strpos($dvsSrc, 'function dvs_require_perm') !== false);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: channels GET uses dmr_receive",
    preg_match("/'channels'.*?dvs_require_perm\\('action\\.dmr_receive'/s", $dvsSrc) === 1);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: channel_create POST uses dmr_configure",
    preg_match("/'channel_create'.*?dvs_require_perm\\('action\\.dmr_configure'/s", $dvsSrc) === 1);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: channel_test_tx POST uses dmr_transmit",
    preg_match("/'channel_test_tx'.*?dvs_require_perm\\('action\\.dmr_transmit'/s", $dvsSrc) === 1);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: channel_tx_text POST uses dmr_transmit",
    preg_match("/'channel_tx_text'.*?dvs_require_perm\\('action\\.dmr_transmit'/s", $dvsSrc) === 1);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: channel_recent_messages GET uses dmr_receive",
    preg_match("/'channel_recent_messages'.*?dvs_require_perm\\('action\\.dmr_receive'/s", $dvsSrc) === 1);

// No leftover is_admin gates on public actions (besides the backwards-compat
// path inside dvs_require_perm itself).
$callSitesRemaining = preg_match_all(
    '/\$action === \'[a-z_]+\'\\)\s*\\{\s*dvs_admin_check\\(\\)/',
    $dvsSrc
);
[$pass, $fail] = _t($pass, $fail,
    "dvswitch.php: zero remaining dvs_admin_check sites under \$action handlers",
    $callSitesRemaining === 0);

$audioSrc = file_get_contents(__DIR__ . '/../api/dmr-audio.php');
[$pass, $fail] = _t($pass, $fail,
    "dmr-audio.php gates on dmr_receive",
    strpos($audioSrc, "rbac_can('action.dmr_receive')") !== false);
[$pass, $fail] = _t($pass, $fail,
    "dmr-audio.php keeps backwards-compat with action.play_dmr_audio",
    strpos($audioSrc, "action.play_dmr_audio") !== false);

echo "\n=== TOTAL: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
