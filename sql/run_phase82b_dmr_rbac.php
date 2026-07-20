<?php
/**
 * Phase 82b — DMR / DVSwitch RBAC permissions
 *
 * Seeds three permissions so admins can split DMR access into discrete
 * capabilities without giving full is_admin() to every dispatcher who
 * needs to listen or transmit:
 *
 *   action.dmr_configure  — create/edit/delete/toggle channels, rotate
 *                           bridge tokens, edit TTS voice + STT model
 *   action.dmr_transmit   — press TX (TTS or future voice PTT), key the
 *                           1 kHz test tone
 *   action.dmr_receive    — view transcripts + recordings, replay calls
 *                           via the DVR player, listen to live audio
 *                           (when Phase 83 ships)
 *
 * Default grants on fresh install:
 *   Super Admin (id=1)       configure + transmit + receive
 *   Org Admin (id=2)         configure + transmit + receive
 *   Dispatcher (id=3)        transmit + receive
 *   Operator (id=4)          receive only
 *   Read-Only (id=5)         — (no DMR access at all)
 *   Field Unit (id=6)        — (no DMR access at all)
 *
 * Idempotent — safe to re-run. Permissions and grants check before insert.
 *
 * Usage:
 *   php tools/run_phase82b_dmr_rbac.php
 */

require __DIR__ . '/../config.php';

$pdo = db();
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 82b — DMR / DVSwitch RBAC permissions\n";
echo "============================================\n\n";

$permissions = [
    'action.dmr_configure' => [
        'name'        => 'Configure DMR / DVSwitch',
        'description' => 'Create, edit, delete, toggle DMR channels; rotate bridge tokens; edit TTS/STT settings.',
        'category'    => 'action',
        'roles'       => [1, 2],   // Super Admin, Org Admin
    ],
    'action.dmr_transmit'  => [
        'name'        => 'Transmit on DMR',
        'description' => 'Key the DMR bridge — send TTS text, fire test tone, future voice PTT.',
        'category'    => 'action',
        'roles'       => [1, 2, 3],   // + Dispatcher
    ],
    'action.dmr_receive'   => [
        'name'        => 'Receive DMR transcripts + audio',
        'description' => 'View DMR transcripts and last-heard, play DVR recordings, listen to live audio when Phase 83 ships.',
        'category'    => 'action',
        'roles'       => [1, 2, 3, 4],   // + Operator
    ],
];

$inserted_perms = 0;
$inserted_grants = 0;

foreach ($permissions as $code => $spec) {
    // Insert (or update name/description on) the permission row.
    try {
        $existing = $pdo->prepare(
            "SELECT id FROM `{$prefix}permissions` WHERE code = ? LIMIT 1"
        );
        $existing->execute([$code]);
        $permId = (int) $existing->fetchColumn();

        if ($permId === 0) {
            $pdo->prepare(
                "INSERT INTO `{$prefix}permissions` (code, name, description, category)
                 VALUES (?, ?, ?, ?)"
            )->execute([$code, $spec['name'], $spec['description'], $spec['category']]);
            $permId = (int) $pdo->lastInsertId();
            echo "  [+] permission inserted: {$code} (id={$permId})\n";
            $inserted_perms++;
        } else {
            echo "  [skip] permission exists: {$code} (id={$permId})\n";
        }
    } catch (Exception $e) {
        fwrite(STDERR, "  ERROR inserting permission {$code}: " . $e->getMessage() . "\n");
        continue;
    }

    // Seed default role grants. Check first so re-run doesn't duplicate.
    foreach ($spec['roles'] as $roleId) {
        try {
            $hasGrant = $pdo->prepare(
                "SELECT 1 FROM `{$prefix}role_permissions`
                 WHERE role_id = ? AND permission_id = ? LIMIT 1"
            );
            $hasGrant->execute([$roleId, $permId]);
            if ($hasGrant->fetchColumn()) {
                continue;
            }
            $pdo->prepare(
                "INSERT INTO `{$prefix}role_permissions` (role_id, permission_id)
                 VALUES (?, ?)"
            )->execute([$roleId, $permId]);
            echo "  [+] grant: role_id={$roleId} -> {$code}\n";
            $inserted_grants++;
        } catch (Exception $e) {
            fwrite(STDERR, "  ERROR granting {$code} to role {$roleId}: "
                . $e->getMessage() . "\n");
        }
    }
}

echo "\nDone. {$inserted_perms} permission(s) inserted, {$inserted_grants} grant(s) seeded.\n";
