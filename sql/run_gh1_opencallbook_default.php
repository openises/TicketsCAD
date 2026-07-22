<?php
/**
 * GH openises/TicketsCAD#1 — default the call-sign lookup provider to OpenCallbook.
 *
 * OpenCallbook (opencallbook.com) resolves amateur AND GMRS by call sign in one
 * call, so it fixes "GMRS lookup returns No Record Found" — the old default
 * (callook.info) is amateur-only and can never return a GMRS record.
 *
 * This migrates existing installs that are still on the callook default over to
 * opencallbook. Deliberate offline / self-hosted choices (`local`, `fcc_uls_api`)
 * and `disabled` are LEFT UNTOUCHED — forcing an internet provider on an offline
 * (e.g. AREDN mesh) deployment would break it. Installs that never saved a lookup
 * provider pick up the new code default automatically. Idempotent.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
echo "GH TicketsCAD#1 — default call-sign lookup provider -> opencallbook\n";

try {
    // Lookup config lives in the tiny `config` table (key/value). Create it if
    // this install has never saved a lookup setting.
    db_query("CREATE TABLE IF NOT EXISTS `{$prefix}config` (
        `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
        `value` TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    db_query(
        "UPDATE `{$prefix}config`
            SET `value` = 'opencallbook'
          WHERE `key` = 'lookup_callsign_provider' AND `value` = 'callook'"
    );
    echo "  OK — callook installs moved to opencallbook; local / fcc_uls_api / disabled left as-is.\n";
} catch (Exception $e) {
    echo "  ERROR: " . $e->getMessage() . "\n";
    exit(1);   // non-zero so the migration runner records the failure (project rule)
}
