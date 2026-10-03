<?php
/**
 * GH#148 (Phase 155) -- seed the vendor.* caption keys (towing / roadside dispatch).
 *
 * The dispatch dialog, the incident-page card, the admin page heading and the Settings sidebar link all call
 * t('vendor.*', fallback). A key that is not seeded into captions_i18n gives the Translations UI no row to edit, so an
 * agency that calls these "Wrecker" or "Roadside Service" could never rename them (the same gap
 * sql/run_facmodal_captions.php closed for the facility modal). tests/test_vendor_wiring.php fails if a t('vendor.*')
 * key used in the source is missing from this list.
 *
 * Idempotent: INSERT IGNORE on (caption_key, lang). Never overwrites an administrator's own wording.
 *
 * Usage: php sql/run_gh148_vendor_captions.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

chdir(__DIR__ . '/..');
require_once 'config.php';
$prefix = $GLOBALS['db_prefix'] ?? '';

echo "GH#148 -- seed vendor.* caption keys\n";
echo "====================================\n\n";

$captions = [
    'sidebar.tab.service_providers' => 'Service Providers (Towing)',
    'vendor.accept.eta' => 'ETA in minutes',
    'vendor.accept.ok' => 'Accepted',
    'vendor.admin.subtitle' => 'towing and roadside companies, rotation lists',
    'vendor.admin.title' => 'Service Providers',
    'vendor.btn.add' => 'Add',
    'vendor.btn.cancel_dispatch' => 'Cancel this dispatch',
    'vendor.btn.completed' => 'Completed',
    'vendor.btn.dispatch' => 'Tow / Roadside',
    'vendor.btn.dispatch_title' => 'Dispatch a towing or roadside-assistance company for this incident',
    'vendor.btn.goa' => 'Gone on arrival',
    'vendor.btn.new' => 'New',
    'vendor.btn.on_scene' => 'On scene',
    'vendor.btn.update' => 'Update',
    'vendor.btn.withdrew' => 'Company withdrew',
    'vendor.call' => 'Call',
    'vendor.calling' => 'calling…',
    'vendor.cancel' => 'Cancel',
    'vendor.card.empty' => 'No towing or roadside calls on this incident yet.',
    'vendor.card.title' => 'Towing / Roadside',
    'vendor.close' => 'Close',
    'vendor.col.action' => 'Action',
    'vendor.col.company' => 'Company',
    'vendor.col.number' => 'Number',
    'vendor.col.status' => 'Status',
    'vendor.copy' => 'Copy',
    'vendor.dest.address' => 'Destination address',
    'vendor.dest.address_ph' => 'Street address, city',
    'vendor.dest.title' => 'Destination',
    'vendor.details.title' => 'Vehicle and details',
    'vendor.eta.update' => 'Update ETA (minutes)',
    'vendor.field.list' => 'Rotation list',
    'vendor.field.location' => 'Pickup location',
    'vendor.field.notes' => 'Notes',
    'vendor.field.plate' => 'Plate',
    'vendor.field.plate_state' => 'Plate state',
    'vendor.field.reason' => 'Reason for the call',
    'vendor.field.reason_ph' => 'e.g. disabled vehicle, impound after crash',
    'vendor.field.service' => 'Service needed',
    'vendor.field.vehicle' => 'Vehicle (year, make, model, color)',
    'vendor.loading' => 'Loading...',
    'vendor.log_call' => 'Log call',
    'vendor.manage' => 'Manage',
    'vendor.modal.title' => 'Dispatch Towing / Roadside',
    'vendor.next_up' => 'NEXT UP',
    'vendor.no_company' => 'No company yet',
    'vendor.no_list' => 'No rotation list for this service. You can still log a call to any company below.',
    'vendor.note.add' => 'Add a note to this dispatch',
    'vendor.other.name' => 'Or type a company name',
    'vendor.other.owner' => 'The driver or owner requested this company',
    'vendor.other.phone' => 'Phone number',
    'vendor.other.pick' => 'A company we already have on file',
    'vendor.other.title' => 'Another company',
    'vendor.pending.title' => 'Calls awaiting an outcome',
    'vendor.queue.title' => 'Who to call',
    'vendor.readaloud.callback' => 'Callback number',
    'vendor.readaloud.callback_ph' => 'the number the driver should call',
    'vendor.readaloud.title' => 'Read to the driver',
    'vendor.reason.ok' => 'Log call with this reason',
    'vendor.reason.other' => 'Other reason',
    'vendor.reason.p1' => 'The earlier company did not answer',
    'vendor.reason.p2' => 'Owner or driver requested this company',
    'vendor.reason.p3' => 'Closest truck',
    'vendor.reason.p4' => 'Special equipment needed',
    'vendor.reason.p5' => 'Other (explain)',
    'vendor.reason.title' => 'Why skip the company that is next?',
    'vendor.save_details' => 'Save details',
    'vendor.status.assigned' => 'ASSIGNED',
    'vendor.status.cancelled' => 'CANCELLED',
    'vendor.status.completed' => 'COMPLETED',
    'vendor.status.goa' => 'GONE ON ARRIVAL',
    'vendor.status.on_scene' => 'ON SCENE',
    'vendor.status.open' => 'OPEN',
    'vendor.status.title' => 'Dispatch status',
    'vendor.timeline.title' => 'Timeline',
    'vendor.void.ok' => 'Void entry',
    'vendor.void.reason' => 'Why is this entry being voided?',
];

$added = 0;
foreach ($captions as $key => $value) {
    try {
        $category = (strpos($key, 'sidebar.') === 0) ? 'sidebar' : 'vendor';
        db_query(
            "INSERT IGNORE INTO `{$prefix}captions_i18n` (`caption_key`, `lang`, `value`, `category`)
             VALUES (?, 'en', ?, ?)",
            [$key, $value, $category]
        );
        $added += (int) db_fetch_value("SELECT ROW_COUNT()");
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR seeding $key: " . $e->getMessage() . "\n");
        exit(1);
    }
}

// Verify the OUTCOME: every key must exist now (a swallowed failure above would otherwise read as success).
$missing = [];
foreach (array_keys($captions) as $key) {
    $have = (int) db_fetch_value(
        "SELECT COUNT(*) FROM `{$prefix}captions_i18n` WHERE `caption_key` = ? AND `lang` = 'en'", [$key]);
    if ($have === 0) $missing[] = $key;
}
if ($missing) {
    fwrite(STDERR, "FAILED: caption keys missing after seeding: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "done: $added new caption row(s) seeded (" . count($captions) . " keys checked)\n";
echo "These now appear in Settings -> Translations for per-install renaming.\n";
exit(0);
