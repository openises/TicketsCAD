<?php
/**
 * Phase 84-followup: seed the states_translator table.
 *
 * Symptom (Phase 84): on training the State dropdown on new-incident.php
 * showed only "—" because states_translator had 0 rows. Eric tried entering
 * "Cedar Rapids IA" and couldn't pick a state.
 *
 * GH#153 (2026-10-19): the list is now an administrator's to change
 * (Settings → States / Provinces), so this seed must never undo that. It now
 * seeds ONLY when BOTH are true:
 *   - the table is empty, and
 *   - nobody has ever changed the list through the Settings page
 *     (settings.states_list_customized, written by inc/states.php).
 * The old version INSERT IGNOREd the 52 US rows by id on every run, so after
 * an administrator replaced the list with Canadian provinces (new ids), the
 * next run of this script would have put all fifty states back beside them.
 *
 * The rows come from inc/state-presets.php (the one copy of the preset data):
 * the 50 states plus the District of Columbia. The stray legacy row
 * ('England', 'UK') the old seed carried is no longer seeded; installs that
 * already have it keep it until an administrator removes it.
 *
 * sql/base_schema.sql no longer seeds this table either, so this script is
 * the single place a fresh install gets its list (tools/install_fresh.php
 * runs every sql/run_*.php).
 *
 * Idempotent. Exits non-zero only when the table is missing or the seed fails.
 *
 * Usage:  php sql/run_phase84_states_seed.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/states.php';

$prefix = $GLOBALS['db_prefix'] ?? '';

echo "Phase 84 follow-up — states_translator seed\n";
echo "==========================================\n\n";

try {
    $before = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}states_translator`");
} catch (Exception $e) {
    echo "[ERR] states_translator table missing — run the base schema first\n";
    exit(1);
}
echo "Rows before: {$before}\n";

if ($before > 0) {
    echo "[SKIP] the list already has {$before} entries — never seeded over an existing list\n";
    exit(0);
}
if (states_is_customized()) {
    echo "[SKIP] the list is empty because an administrator emptied it in Settings → States / Provinces — left empty\n";
    exit(0);
}

$built = states_build_selection(['us50' => ['dc']]);
$inserted = 0;
try {
    foreach ($built['rows'] as [$code, $name]) {
        db_query(
            "INSERT IGNORE INTO `{$prefix}states_translator` (`code`, `name`) VALUES (?, ?)",
            [$code, $name]
        );
        $inserted++;
    }
} catch (Exception $e) {
    fwrite(STDERR, "[ERR] seeding failed: " . $e->getMessage() . "\n");
    exit(1);
}

$after = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}states_translator`");
if ($after !== count($built['rows'])) {
    fwrite(STDERR, "[ERR] expected " . count($built['rows']) . " rows after seeding, found {$after}\n");
    exit(1);
}
echo "Rows after: {$after} (the 50 US states + DC)\n";
echo "\nDone.\n";
