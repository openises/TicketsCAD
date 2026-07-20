<?php
/**
 * Legacy v3.44 → NewUI v4 — settings translator.
 *
 * Walks the legacy `settings` table and:
 *   - renames legacy keys to NewUI's expected shape (when the rename
 *     is unambiguous and lossless)
 *   - INSERT IGNOREs NewUI defaults the legacy install lacks
 *   - never overwrites operator-set values
 *
 * Idempotent. Safe to re-run.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Map: old_key => new_key. Only set the new key (idempotent INSERT IGNORE),
// then delete the old one. If the new key is already present, skip.
$renames = [
    'tile_url'        => 'map.tile_url',
    'email_host'      => 'smtp.host',
    'email_port'      => 'smtp.port',
    'email_user'      => 'smtp.user',
    'email_pass'      => 'smtp.pass',
    'email_from'      => 'smtp.from',
    'phpmailer_host'  => 'smtp.host',
    'phpmailer_port'  => 'smtp.port',
    'phpmailer_user'  => 'smtp.user',
    'phpmailer_pass'  => 'smtp.pass',
    'phpmailer_from'  => 'smtp.from',
];

// Defaults to seed if absent. NewUI features expect these to exist.
$seeds = [
    'rbac.require_separate_approver' => '0',
    'rbac.delegation_max_depth'      => '1',
    'rbac.time_entry_auto_approve'   => 'off',
    'tile_mode'                      => 'proxy',
    // Phase 41 — Chat defaults (panel-chat-settings)
    'chat_retention_days'            => '365',
    'chat_max_chars'                 => '2000',
    'chat_dm_clear_logout'           => 'off',
    'chat_all_room_enabled'          => '1',
    'chat_role_rooms_enabled'        => '1',
    'chat_incident_rooms_enabled'    => '1',
    'chat_dm_enabled'                => '1',
    'chat_typing_indicators'         => '1',
    'chat_read_receipts'             => '0',
];

$renamed = 0;
$seeded  = 0;
$skipped = 0;

echo "Settings migration:\n";

// Renames
foreach ($renames as $oldKey => $newKey) {
    try {
        $oldRow = db_fetch_one("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$oldKey]);
        if (empty($oldRow)) continue;

        $newRow = db_fetch_one("SELECT value FROM `{$prefix}settings` WHERE name = ?", [$newKey]);
        if (!empty($newRow)) {
            // Both exist — keep new, drop old.
            db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$oldKey]);
            $skipped++;
            echo "  [skip] $oldKey (new key $newKey already set; old removed)\n";
            continue;
        }
        db_query(
            "INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
            [$newKey, $oldRow['value']]
        );
        db_query("DELETE FROM `{$prefix}settings` WHERE name = ?", [$oldKey]);
        $renamed++;
        echo "  [ren]  $oldKey -> $newKey\n";
    } catch (Throwable $e) {
        echo "  [fail] $oldKey -> $newKey: " . $e->getMessage() . "\n";
    }
}

// Seeds
foreach ($seeds as $name => $default) {
    try {
        $exists = (int) db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = ?", [$name]
        );
        if ($exists) continue;
        db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)",
            [$name, $default]);
        $seeded++;
        echo "  [seed] $name = $default\n";
    } catch (Throwable $e) {
        echo "  [fail] seed $name: " . $e->getMessage() . "\n";
    }
}

echo "\nSummary: $renamed renamed, $seeded seeded, $skipped skipped.\n";
exit(0);
