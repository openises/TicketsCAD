<?php
/**
 * Phase 152 (Console rebuild) — backfills the always-present dispatcher
 * intercom channel (`intercom_dd:main`, inc/channel_registry.php's
 * `channel_registry_sources()`) onto installs that already ran the
 * one-time `sql/run_phase114a_channel_registry.php` migration.
 *
 * `channel_registry_sync()` is the ONLY thing that ever creates a managed
 * `comm_channels` row from `channel_registry_sources()`'s "want" list --
 * it is called once at initial install (run_phase114a_channel_registry.php)
 * and on demand from the admin "Sync Channels" button (api/channels.php's
 * `sync` action, console.design-gated). Adding `intercom_dd:main` to that
 * "want" list therefore does NOTHING on an install that already ran the
 * one-time migration, until an admin happens to click Sync -- which
 * contradicts this channel's own design intent (a fixed, always-present
 * party line every install gets by default, per the 5-persona review
 * recorded in channel_registry.php's own comment). This migration is the
 * one-time nudge that makes "by default" actually true on an
 * already-migrated install, the same way run_phase114a_channel_registry.php
 * itself made the FIRST batch of sources real.
 *
 * Idempotent -- safe to run repeatedly; picked up by run_migrations.php.
 * channel_registry_sync() itself is idempotent (skips a channel_key that
 * already exists), so a second run of this script is a clean no-op.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
require_once 'inc/channel_registry.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 152 -- backfill intercom_dd:main\n";
echo "=======================================\n\n";

$ok = true;
try {
    $r = channel_registry_sync();
    echo "sync: {$r['created']} created, {$r['updated']} updated, {$r['pruned']} pruned\n";
} catch (Exception $e) {
    fwrite(STDERR, "[FAIL] channel_registry_sync(): " . $e->getMessage() . "\n");
    $ok = false;
}

// Verify (Phase 128 A9b lesson: a migration that swallows its own failure
// and exits 0 is a migration that never ran).
$row = db_fetch_one(
    "SELECT id, adapter, regulatory_class, enabled FROM `{$prefix}comm_channels` WHERE channel_key = ?",
    ['intercom_dd:main']
);
if (!$row) {
    fwrite(STDERR, "[FAIL] verification: intercom_dd:main not found in comm_channels after sync\n");
    $ok = false;
} elseif ($row['adapter'] !== 'intercom_dd' || $row['regulatory_class'] !== 'internal' || (int) $row['enabled'] !== 1) {
    fwrite(STDERR, "[FAIL] verification: intercom_dd:main exists but has the wrong shape (adapter="
        . $row['adapter'] . ", regulatory_class=" . $row['regulatory_class'] . ", enabled=" . $row['enabled'] . ")\n");
    $ok = false;
} else {
    echo "[OK] intercom_dd:main verified (id={$row['id']}, regulatory_class=internal, enabled)\n";
}

if (!$ok) { exit(1); }
echo "\n[OK] verified.\n";
exit(0);
