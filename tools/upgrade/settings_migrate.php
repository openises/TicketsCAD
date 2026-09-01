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
 *
 * FIX (found while writing the missing upgrade-path test suite this
 * script's own spec calls for): the previous version's SMTP handling was
 * built against email_host/email_port/email_user/email_pass/phpmailer_*
 * as the assumed legacy key names -- none of those exist ANYWHERE in the
 * legacy v3.44 tree (confirmed by grep). v3.44 stores SMTP config as a
 * SINGLE combined key, smtp_acct, in the exact field order documented in
 * its own do_smtp_mail() (tickets/incs/smtp.inc.php:37): "0 = server,
 * 1 = port, 2 = security, 3 = user, 4 = password". Every real v3.44
 * upgrade therefore had its SMTP config silently DROPPED (the rename
 * loop below just no-ops on a key that was never present) -- not merely
 * misnamed, which is what this fix originally (and still incorrectly, in
 * the version before this comment) assumed. The destination keys were
 * ALSO wrong regardless: inc/channels/smtp.php's _smtp_get_config()
 * reads smtp_host/smtp_port/smtp_encryption/smtp_user/smtp_pass
 * (underscore-separated, no dot), not smtp.host/smtp.port/etc -- a
 * second, independent bug that would have persisted even if the source
 * key had been right. email_from was already both the legacy AND the
 * current NewUI key name (_smtp_get_config() reads it literally) --
 * removed from the rename map entirely rather than "fixed" into
 * something that would have broken a key already correct.
 *
 * tile_url -> map.tile_url had the same second bug in isolation: tile_url
 * genuinely is legacy v3.44's key (confirmed in tickets/incs/config.tiles.
 * inc.php et al.), but api/map-config.php reads the setting as
 * tile_server_url, not map.tile_url -- nothing has ever read the old
 * destination key.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../../config.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

// Map: old_key => new_key. Only set the new key (idempotent INSERT IGNORE),
// then delete the old one. If the new key is already present, skip.
// email_from is deliberately absent: it's already the correct NewUI key
// name (_smtp_get_config() reads it literally), so touching it here could
// only ever destroy an already-correct value, never fix one.
$renames = [
    'tile_url'        => 'tile_server_url',
];

// smtp_acct: v3.44's single combined SMTP config field, slash-delimited
// in a FIXED order (tickets/incs/smtp.inc.php's do_smtp_mail(), the only
// legacy function that actually sends SMTP mail, is the authoritative
// source for this order -- not the descriptive comment in
// tickets/incs/config.inc.php, which uses a stale/inconsistent example).
//   0 = server, 1 = port, 2 = security, 3 = user, 4 = password
$smtpAcctRow = null;
try {
    $smtpAcctRow = db_fetch_one("SELECT value FROM `{$prefix}settings` WHERE name = ?", ['smtp_acct']);
} catch (Throwable $e) { /* legacy `settings` table may not exist on a non-v3 source; nothing to migrate */ }

$smtpMigrated = false;
if (!empty($smtpAcctRow) && trim((string) $smtpAcctRow['value']) !== '') {
    $parts = explode('/', trim((string) $smtpAcctRow['value']));
    // Matches the same "is this actually populated" gate v3.44's own
    // functions.inc.php uses before treating smtp_acct as usable, rather
    // than assuming any non-empty string is well-formed.
    if (count($parts) > 1 && count($parts) < 6) {
        $smtpFields = [
            'smtp_host'       => $parts[0] ?? '',
            'smtp_port'       => $parts[1] ?? '',
            'smtp_encryption' => $parts[2] ?? '',
            'smtp_user'       => $parts[3] ?? '',
            'smtp_pass'       => $parts[4] ?? '',
        ];
        foreach ($smtpFields as $newKey => $val) {
            if ($val === '') continue; // don't write empty/absent fields over a real default
            try {
                $exists = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = ?", [$newKey]);
                if ($exists) continue; // never overwrite an operator-set value
                db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", [$newKey, $val]);
            } catch (Throwable $e) { /* per-field non-fatal */ }
        }
        // A populated smtp_acct means the legacy install was actively
        // relaying via SMTP -- email_mode has no v3.44 equivalent (v3 had
        // no separate sendmail/smtp mode concept), so without this the
        // migrated host/port/user/pass would sit unused behind the
        // 'sendmail' default _smtp_send() falls back to.
        try {
            $exists = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}settings` WHERE name = ?", ['email_mode']);
            if (!$exists) {
                db_query("INSERT INTO `{$prefix}settings` (name, value) VALUES (?, ?)", ['email_mode', 'smtp']);
            }
        } catch (Throwable $e) { /* non-fatal */ }
        $smtpMigrated = true;
    }
    // smtp_acct itself is left in place (not deleted) regardless of
    // whether it parsed -- it is not read by any NewUI code path, so an
    // unparsed or malformed value does no harm sitting there, and
    // deleting it would destroy the only record of what the legacy
    // install had configured if this migration needs to be re-examined.
}

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

if ($smtpMigrated) {
    echo "  [smtp] smtp_acct parsed -> smtp_host/smtp_port/smtp_encryption/smtp_user/smtp_pass, email_mode=smtp\n";
} elseif (!empty($smtpAcctRow) && trim((string) $smtpAcctRow['value']) !== '') {
    echo "  [warn] smtp_acct present but not in the expected 2-5 field format -- left untouched, review manually\n";
}

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
