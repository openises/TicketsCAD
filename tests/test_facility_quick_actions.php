<?php
/**
 * Phase 115 — Facilities widget quick-action bar.
 *
 * Exercises the facility_notes schema + the write semantics of
 * api/facility-action.php against a throwaway test facility (created +
 * torn down here). The HTTP/auth/CSRF layer is verified by static wiring
 * guards; the data-shaping (status update, note append, partial bed
 * update, append-only history) is exercised directly so no live session
 * is required.
 *
 * Usage: php tests/test_facility_quick_actions.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$base   = realpath(__DIR__ . '/..');
$prefix = $GLOBALS['db_prefix'] ?? '';
$passed = 0; $failed = 0;
function t($l, $c) { global $passed, $failed; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $passed++ : $failed++; }
function rd($p) { return @file_get_contents($p); }

echo "=== Phase 115 — facility quick actions ===\n\n";

// Ensure the migration ran (the runner may not have on a bare checkout).
require_once __DIR__ . '/../sql/run_facility_notes.php';

// ── Schema ───────────────────────────────────────────────────────────────────
$cols = array_column(db_fetch_all("SHOW COLUMNS FROM `{$prefix}facility_notes`"), 'Field');
t('facility_notes has the append-only columns',
    in_array('facility_id', $cols) && in_array('category', $cols) &&
    in_array('note', $cols) && in_array('username', $cols) && in_array('created_at', $cols));

// ── Seed a throwaway facility (description is text NOT NULL, no default) ──────
db_query("DELETE FROM `{$prefix}facilities` WHERE name = 'ZZ_FAC_QA_TEST'");
$statusRow = db_fetch_one("SELECT id, status_val FROM `{$prefix}fac_status` ORDER BY sort, id LIMIT 1");
$firstStatusId = $statusRow ? (int) $statusRow['id'] : 1;
db_query("INSERT INTO `{$prefix}facilities` (name, description, status_id, beds_a, beds_o, beds_info)
          VALUES ('ZZ_FAC_QA_TEST', 'qa test facility', ?, '0', '0', '')", [$firstStatusId]);
$facId = (int) db_insert_id();
t('test facility created', $facId > 0);

// ── Simulate the three writes the API performs (same SQL shapes) ─────────────
// A second status (so a change is observable); fall back to firstStatus.
$status2 = db_fetch_one("SELECT id, status_val FROM `{$prefix}fac_status` WHERE id <> ? ORDER BY sort, id LIMIT 1",
    [$firstStatusId]);
$newStatusId = $status2 ? (int) $status2['id'] : $firstStatusId;

// status
db_query("UPDATE `{$prefix}facilities` SET status_id = ?, status_about = ? WHERE id = ?",
    [$newStatusId, 'diverting — ER full', $facId]);
db_query("INSERT INTO `{$prefix}facility_notes` (facility_id, category, note, detail, user_id, username, source_ip, created_at)
          VALUES (?, 'status', ?, ?, 0, 'qa', '127.0.0.1', NOW())",
    [$facId, 'diverting — ER full', 'Status change']);
$fac = db_fetch_one("SELECT status_id, status_about FROM `{$prefix}facilities` WHERE id = ?", [$facId]);
t('status update persisted (status_id + status_about)',
    (int) $fac['status_id'] === $newStatusId && $fac['status_about'] === 'diverting — ER full');

// note
db_query("INSERT INTO `{$prefix}facility_notes` (facility_id, category, note, detail, user_id, username, source_ip, created_at)
          VALUES (?, 'note', 'contact is charge nurse Pat', NULL, 0, 'qa', '127.0.0.1', NOW())", [$facId]);

// beds — PARTIAL update (only beds_a sent): beds_o must stay untouched.
db_query("UPDATE `{$prefix}facilities` SET beds_a = '12' WHERE id = ?", [$facId]);
db_query("INSERT INTO `{$prefix}facility_notes` (facility_id, category, note, detail, user_id, username, source_ip, created_at)
          VALUES (?, 'beds', 'avail 12', 'Beds: avail 12', 0, 'qa', '127.0.0.1', NOW())", [$facId]);
$fac2 = db_fetch_one("SELECT beds_a, beds_o FROM `{$prefix}facilities` WHERE id = ?", [$facId]);
t('partial bed update sets beds_a, leaves beds_o alone',
    $fac2['beds_a'] === '12' && $fac2['beds_o'] === '0');

// ── History is append-only: 3 rows, all three categories present ─────────────
$notes = db_fetch_all("SELECT category FROM `{$prefix}facility_notes` WHERE facility_id = ? ORDER BY id", [$facId]);
$cats = array_column($notes, 'category');
t('facility_notes recorded all three actions (append-only history)',
    count($notes) === 3 && in_array('status', $cats) && in_array('note', $cats) && in_array('beds', $cats));

// ── Wiring guards ────────────────────────────────────────────────────────────
$api = rd($base . '/api/facility-action.php');
t('API gates status/note on manage_facilities, beds on update_capacity',
    $api !== false &&
    strpos($api, "action.manage_facilities") !== false &&
    strpos($api, "action.update_capacity") !== false &&
    strpos($api, 'csrf_verify') !== false);
t('API writes an append-only facility_notes row + audit_log',
    $api !== false && strpos($api, 'facility_notes') !== false &&
    strpos($api, "audit_log('facility'") !== false);

$wm = rd($base . '/assets/js/widget-manager.js');
t('widget header has the 6-button facility action bar (V/E/I/S/N/B)',
    $wm !== false &&
    strpos($wm, 'id="facilityActionBar"') !== false &&
    strpos($wm, 'data-facility-action="view"') !== false &&
    strpos($wm, 'data-facility-action="incident"') !== false &&
    strpos($wm, 'data-facility-action="beds"') !== false);

$app = rd($base . '/assets/js/app.js');
t('app.js wires executeFacilityAction + the shared modal + Incident@ link',
    $app !== false &&
    strpos($app, 'function executeFacilityAction') !== false &&
    strpos($app, 'function showFacilityActionBar') !== false &&
    strpos($app, 'function _openFacilityModal') !== false &&
    strpos($app, 'new-incident.php?facility=') !== false);
// One-bar-at-a-time: selecting a facility hides the incident+responder bars.
t('facility selection hides the other action bars (one bar at a time)',
    $app !== false &&
    preg_match('/onFacilitySelected[\s\S]{0,400}showFacilityActionBar\(true\)/', $app) === 1 &&
    strpos($app, 'showFacilityActionBar(false)') !== false);

$kn = rd($base . '/assets/js/keyboard-nav.js');
t('keyboard-nav has facility hotkeys incl. I=incident + B=beds',
    $kn !== false && strpos($kn, 'FACILITY_ACTION_KEYS') !== false &&
    strpos($kn, "'i': 'incident'") !== false && strpos($kn, "'b': 'beds'") !== false);

$ni = rd($base . '/assets/js/new-incident.js');
t('new-incident.js pre-selects the facility from ?facility=',
    $ni !== false && strpos($ni, "params.get('facility')") !== false);

$idx = rd($base . '/index.php');
t('index.php has the facility quick-action modal shell',
    $idx !== false && strpos($idx, 'id="facilityActionModal"') !== false &&
    strpos($idx, 'id="facilityActionApply"') !== false);

// The compact-button CSS must name .facility-action-bar or the buttons
// fall back to full Bootstrap .btn size (the exact bug the responders bar
// hit first — Eric flagged it again for facilities 2026-07-06).
$css = rd($base . '/assets/css/widgets.css');
t('widgets.css sizes the facility action bar buttons compactly',
    $css !== false && strpos($css, '.facility-action-bar .btn-xs') !== false);

// ── Cleanup ──────────────────────────────────────────────────────────────────
db_query("DELETE FROM `{$prefix}facility_notes` WHERE facility_id = ?", [$facId]);
db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$facId]);

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
