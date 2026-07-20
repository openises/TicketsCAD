<?php
/**
 * GH #70 follow-up (a beta tester 2026-07-07) — seed the facmodal.* caption keys.
 *
 * The dashboard facility quick-action modal was internationalized with
 * t('facmodal.*', fallback) calls, but the keys were never seeded into
 * captions_i18n — so the Translations UI had no row to edit and every
 * string permanently showed its hardcoded English fallback. a beta tester
 * renamed Facility→Clinic in his captions and this modal's header was
 * the one spot that ignored it: there was nothing for him to rename.
 *
 * Idempotent — INSERT IGNORE on (caption_key, lang).
 */

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH #70 — seed facmodal.* caption keys\n";
echo "=====================================\n\n";

$captions = [
    'facmodal.title'          => 'Facility Quick Action',
    'facmodal.loading'        => 'Loading…',
    'facmodal.set_status'     => 'Set Status',
    'facmodal.bed_counts'     => 'Bed Counts',
    'facmodal.add_note'       => 'Add Note',
    'facmodal.status'         => 'Status',
    'facmodal.pick_status'    => 'Pick a status',
    'facmodal.no_statuses'    => 'No facility statuses are configured yet. Add them under Settings → Facility Statuses.',
    'facmodal.beds_available' => 'Beds Available',
    'facmodal.beds_occupied'  => 'Beds Occupied',
    'facmodal.note'           => 'Note',
    'facmodal.optional'       => '(optional)',
    'facmodal.ph_beds'        => 'Bed/capacity detail…',
    'facmodal.ph_note'        => 'Facility note…',
    'facmodal.ph_reason'      => 'Reason / detail…',
    'facmodal.apply'          => 'Apply',
    'facmodal.close'          => 'Close',
];

$added = 0;
foreach ($captions as $key => $value) {
    try {
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, 'facmodal')",
            [$key, $value]
        );
        $added += (int) db_fetch_value("SELECT ROW_COUNT()");
    } catch (Exception $e) {
        fwrite(STDERR, "ERROR seeding $key: " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "done: $added new caption row(s) seeded (" . count($captions) . " keys checked)\n";
echo "These now appear in Settings → Translations for per-install renaming.\n";
