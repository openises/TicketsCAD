<?php
/**
 * GH #70 — seed dashboard widget-header caption keys
 *
 * The dashboard widget CARD HEADERS (Statistics / Incidents / Responders /
 * Facilities / …) were hardcoded English in widget-manager.js and ignored
 * the translation table — so a per-install rename (a beta tester's Facility->Clinic)
 * never reached the widget header. widget-manager.js now reads the caption
 * dash.widget.<id> for each header (shared with the show/hide toggle button).
 *
 * Phase 8's i18n seed created most dash.widget.* rows, but the audit_log and
 * time_entries widgets were added later and their keys were never seeded, so
 * the Translations screen had no row to edit. This idempotent migration
 * INSERT IGNOREs all ten dash.widget.* 'en' rows so every install (fresh or
 * upgraded) has editable entries. Existing translated rows are untouched
 * (INSERT IGNORE on the (caption_key, lang) unique key).
 *
 * Idempotent — safe to run repeatedly; picked up by run_migrations.php.
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$dbInc = file_exists('inc/db.inc.php') ? 'inc/db.inc.php' : 'inc/db.php';
require_once $dbInc;
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #70 — dashboard widget-header captions\n";
echo "=========================================\n\n";

$seeds = [
    ['dash.widget.statistics',   'Statistics'],
    ['dash.widget.incidents',    'Incidents'],
    ['dash.widget.responders',   'Responders'],
    ['dash.widget.facilities',   'Facilities'],
    ['dash.widget.controls',     'Controls'],
    ['dash.widget.comms',        'Communications'],
    ['dash.widget.map',          'Map'],
    ['dash.widget.log',          'Recent Events'],
    ['dash.widget.audit_log',    'Recent activity'],
    ['dash.widget.time_entries', 'My time'],
];

$added = 0;
foreach ($seeds as $s) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n`
                (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, 'dash')",
            [$s[0], $s[1]]
        );
        // db_query on an INSERT IGNORE that skipped returns 0 affected; count
        // only genuinely-new rows for the summary.
        $exists = db_fetch_value(
            "SELECT COUNT(*) FROM `{$prefix}captions_i18n`
             WHERE `caption_key` = ? AND `lang` = 'en'", [$s[0]]);
        echo ($exists ? '[ok]  ' : '[??]  ') . $s[0] . "\n";
    } catch (Exception $e) {
        echo '[ERR] ' . $s[0] . ': ' . $e->getMessage() . "\n";
    }
}

echo "\nDone. Rename dash.widget.facilities under Settings -> Translations to\n";
echo "change the Facilities widget header (and its show/hide toggle) label.\n";
