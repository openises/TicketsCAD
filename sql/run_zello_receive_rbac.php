<?php
/**
 * GHSA-x9x6-w4fg-pmcc — action.zello_receive RBAC permission
 *
 * Zello recordings and message history had no RBAC gate at all -- any
 * authenticated session, any role, could reach them (api/zello-messages.php
 * required only auth.php). This seeds a dedicated "receive" permission,
 * mirroring the DMR split (sql/run_phase82b_dmr_rbac.php's action.dmr_receive)
 * so listening to archived Zello traffic is a granted capability rather than
 * an implicit side effect of having any account.
 *
 * Default grants on fresh install, matching action.dmr_receive exactly:
 *   Super Admin (id=1)   Org Admin (id=2)   Dispatcher (id=3)   Operator (id=4)
 *   Read-Only (id=5) and Field Unit (id=6) do NOT get it by default.
 *
 * Idempotent -- safe to re-run. Permission and grants check before insert.
 *
 * Usage:
 *   php sql/run_zello_receive_rbac.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require __DIR__ . '/../config.php';

$pdo    = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Zello receive RBAC permission (GHSA-x9x6-w4fg-pmcc)\n";
echo "====================================================\n\n";

$code    = 'action.zello_receive';
$name    = 'Receive Zello audio + message history';
$desc    = 'Listen to archived Zello recordings and view Zello message/channel history.';
$roleIds = [1, 2, 3, 4]; // Super Admin, Org Admin, Dispatcher, Operator

$inserted_perms  = 0;
$inserted_grants = 0;

try {
    $existing = $pdo->prepare("SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1");
    $existing->execute([$code]);
    $permId = (int) $existing->fetchColumn();

    if ($permId === 0) {
        $pdo->prepare(
            "INSERT INTO `{$prefix}permissions` (code, name, description, category)
             VALUES (?, ?, ?, 'action')"
        )->execute([$code, $name, $desc]);
        $permId = (int) $pdo->lastInsertId();
        echo "  [+] permission inserted: {$code} (id={$permId})\n";
        $inserted_perms++;
    } else {
        echo "  [skip] permission exists: {$code} (id={$permId})\n";
    }
} catch (Exception $e) {
    fwrite(STDERR, "  ERROR inserting permission {$code}: " . $e->getMessage() . "\n");
    exit(1);
}

foreach ($roleIds as $roleId) {
    try {
        $hasGrant = $pdo->prepare(
            "SELECT 1 FROM `{$prefix}role_permissions` WHERE role_id = ? AND permission_id = ? LIMIT 1"
        );
        $hasGrant->execute([$roleId, $permId]);
        if ($hasGrant->fetchColumn()) {
            continue;
        }
        $pdo->prepare(
            "INSERT INTO `{$prefix}role_permissions` (role_id, permission_id) VALUES (?, ?)"
        )->execute([$roleId, $permId]);
        echo "  [+] grant: role_id={$roleId} -> {$code}\n";
        $inserted_grants++;
    } catch (Exception $e) {
        fwrite(STDERR, "  ERROR granting {$code} to role {$roleId}: " . $e->getMessage() . "\n");
    }
}

echo "\nDone. {$inserted_perms} permission(s) inserted, {$inserted_grants} grant(s) seeded.\n";
