<?php
/**
 * GH#141 -- the incident page's "Reserved units" card and the endpoints behind it.
 *
 * Structural checks on assets/js/incident-detail.js (it cannot be loaded into
 * node whole), plus the API contract driven for real: every property the JS
 * reads from a reservation row is emitted by api/incident-detail.php (the
 * project's recurring "JS reads a key nothing emits" disease), the real
 * `assign` / `release_reservation` / `dispatch_reservation_now` actions of
 * api/incident-assign.php behave as the UI expects, and the External API's
 * reserved answer has the documented shape.
 *
 * Also pins:
 *   - assignResponder() handles BOTH `needs_confirmation` (an earlier bug: the
 *     warning showed as a green success and nothing was assigned) and `reserved`
 *   - the new buttons are real, type="button", labelled <button>s (keyboard)
 *   - the incident id used by release/dispatch is the RESERVATION's own: a wrong
 *     client-supplied ticket_id changes nothing (the security side of this is in
 *     tests/test_gh141_reservation_security.php)
 *
 * @requires-db
 * Usage: php tests/test_gh141_incident_detail_ui_contract.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/assignment-write.php';
require_once __DIR__ . '/../inc/assign-reservations.php';
require_once __DIR__ . '/../inc/external-auth.php';
require_once __DIR__ . '/_p155_helpers.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c, $hint = '') { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . ($hint !== '' && !$c ? " -- $hint" : '') . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#141 -- incident page UI contract ===\n\n";

$prefix  = $GLOBALS['db_prefix'] ?? '';
$adminId = test_admin_user_id();
p155_remember_settings(['scheduled_assign_mode', 'scheduled_assign_lead_minutes']);
p155_set_setting('scheduled_assign_mode', 'reserve');
p155_set_setting('scheduled_assign_lead_minutes', '0');

$js   = (string) file_get_contents(__DIR__ . '/../assets/js/incident-detail.js');
$page = (string) file_get_contents(__DIR__ . '/../incident-detail.php');

// ── 1. assignResponder() ─────────────────────────────────────────────────
echo "--- assignResponder() ---\n";
$fnStart = strpos($js, 'function assignResponder(');
$fnEnd = strpos($js, '// ── API: Update assignment status', $fnStart);
$fn = ($fnStart !== false && $fnEnd !== false) ? substr($js, $fnStart, $fnEnd - $fnStart) : '';
t('assignResponder() was located', $fn !== '');
t('it handles needs_confirmation (the double-booking warning is a question, not a success)', strpos($fn, 'data.needs_confirmation') !== false && strpos($fn, 'window.confirm(') !== false);
t('it resubmits with force on confirmation', strpos($fn, 'assignResponder(responderId, true)') !== false);
t('it handles reserved: says so plainly instead of "assigned"', strpos($fn, 'data.reserved') !== false);
t('...and the reserved branch comes BEFORE the success toast (so a reservation is never shown as a green "assigned")',
    strpos($fn, 'data.reserved') !== false && strpos($fn, "showAlert(escHtml(data.message), 'success')") !== false
    && strpos($fn, 'data.reserved') < strpos($fn, "showAlert(escHtml(data.message), 'success')"));
t('it surfaces the near-miss conflicts the server reports', strpos($fn, 'data.conflicts') !== false);

// ── 2. The card ──────────────────────────────────────────────────────────
echo "\n--- the card ---\n";
foreach (['reservedUnitsPanel', 'reservedUnitsList', 'reservedCount'] as $id) {
    t("incident-detail.php has #$id", strpos($page, 'id="' . $id . '"') !== false);
}
t('the panel is hidden by default (d-none) -- nothing changes on an install that does not use reservations', (bool) preg_match('/id="reservedUnitsPanel"[^>]*d-none|d-none[^>]*id="reservedUnitsPanel"/', $page));
t('the page is wired to render it from BOTH the initial load and every refresh',
    substr_count($js, "renderReservations(data.reservations || [], data.reservation_settings || {});") === 2);
$rrStart = strpos($js, 'function renderReservations(');
$rrEnd = strpos($js, 'function reservationAction(', $rrStart);
$rr = substr($js, $rrStart, $rrEnd - $rrStart);
$raStart = $rrEnd;
$raEnd = strpos($js, 'Phase 151 (GH#138) — the "Primary:', $raStart);
$ra = substr($js, $raStart, $raEnd - $raStart);
t('renderReservations() and reservationAction() were located', strlen($rr) > 500 && strlen($ra) > 300);
t('the generated buttons are type="button" (never a form-submit)', substr_count($rr, '<button type="button"') >= 2 && !preg_match('/<button(?![^>]*type=)/', $rr));
t('each button has an aria-label naming the unit', substr_count($rr, 'aria-label="Dispatch ') >= 1 && substr_count($rr, 'aria-label="Release reservation for ') >= 1);
t('the three live states are all rendered: reserved, DUE, HELD',
    strpos($rr, 'Reserved &mdash; dispatches') !== false && strpos($rr, 'DUE &mdash; not yet dispatched') !== false && strpos($rr, 'HELD for a dispatcher') !== false);
t('all dynamic text is escaped through escHtml()', substr_count($rr, 'escHtml(') >= 8);
t('release asks for confirmation (an accidental click would lose a booking)', strpos($ra, "window.confirm('Release this reservation?") !== false);
t('Dispatch now answers the needs_confirmation question and resubmits with force', strpos($ra, 'data.needs_confirmation') !== false && strpos($ra, 'reservationAction(act, reservationId, btn, true)') !== false);
t('release/dispatch POST the reservation id (the server derives the incident from it)', strpos($ra, 'reservation_id: reservationId') !== false);
t('the card refreshes after an action', strpos($ra, 'refreshIncident()') !== false);

// ── 3. The API contract ──────────────────────────────────────────────────
echo "\n--- the API contract (real incident-detail.php) ---\n";
$stAvail = p155_make_status('p155_ui_avail', 0);
$unit = p155_make_unit('UI-A');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $unit]);
$tid = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', 'GH141 ui contract');

// Reserve through the REAL endpoint, as the page's Assign button does.
$ra1 = p155_probe('session', 'api/incident-assign.php', $adminId,
    ['action' => 'assign', 'ticket_id' => $tid, 'responder_id' => $unit, 'role' => 'Medic']);
t('POST assign in reserve mode answers success + reserved + reservation_id + promotes_at + a message that does not claim a dispatch',
    $ra1['http'] === 200 && !empty($ra1['json']['reserved']) && !empty($ra1['json']['reservation_id']) && !empty($ra1['json']['promotes_at'])
    && stripos((string) $ra1['json']['message'], 'reserved') !== false && stripos((string) $ra1['json']['message'], ' assigned to ') === false, $ra1['raw']);
t('and it did NOT announce assign.created (no `assign` audit row, no assign_id in the answer)',
    !isset($ra1['json']['assign_id']) && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tid]) === 0);
$resId = (int) ($ra1['json']['reservation_id'] ?? 0);

$detail = p155_probe('session', 'api/incident-detail.php', $adminId, [], 'GET', 'id=' . $tid);
t('incident-detail.php returns `reservations` and `reservation_settings`', $detail['http'] === 200 && isset($detail['json']['reservations'], $detail['json']['reservation_settings']), $detail['raw']);
t('the reservation is NOT in `assignments` (a reservation is deliberately not an assignment)', ($detail['json']['assignments'] ?? null) === []);
t('reservation_settings reports the mode and lead', ($detail['json']['reservation_settings']['mode'] ?? '') === 'reserve' && ($detail['json']['reservation_settings']['lead_minutes'] ?? -1) === 0);
$row = $detail['json']['reservations'][0] ?? [];
// Every property the JS reads off a reservation row must be emitted.
preg_match_all('/\b(?:r|d|list\[i\]|list\[j\])\.([a-z_]+)\b/', $rr, $m);
$read = array_values(array_unique(array_filter($m[1], function ($k) { return !in_array($k, ['length', 'push', 'join', 'substring'], true); })));
$missing = array_values(array_diff($read, array_keys($row)));
t('EVERY property renderReservations() reads off a reservation row is emitted by the API (none is a key nothing sends)',
    !empty($read) && $missing === [], 'JS reads ' . implode(',', $read) . '; API lacks ' . implode(',', $missing));
t('the row has the documented fields', isset($row['id'], $row['responder_id'], $row['responder_handle'], $row['state'], $row['live'], $row['due'], $row['promotes_at'], $row['booked_date']) && $row['role'] === 'Medic' && $row['state'] === 'pending' && $row['live'] === true);

// ── 4. The three actions through the real endpoint ───────────────────────
echo "\n--- the real actions ---\n";
$wrongTicket = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 8 HOUR)', 'GH141 a DIFFERENT incident');
$rel = p155_probe('session', 'api/incident-assign.php', $adminId,
    ['action' => 'release_reservation', 'ticket_id' => $wrongTicket, 'reservation_id' => $resId]);
t('release_reservation succeeds even when the client names a DIFFERENT incident (the reservation\'s own incident is used)',
    $rel['http'] === 200 && !empty($rel['json']['success']), $rel['raw']);
t('...the reservation is released', db_fetch_value("SELECT state FROM `{$prefix}assign_reservations` WHERE id = ?", [$resId]) === 'cancelled');
t('...and the incident the client NAMED was not touched', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assign_reservations` WHERE ticket_id = ?", [$wrongTicket]) === 0);
$rel2 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'release_reservation', 'ticket_id' => $tid, 'reservation_id' => $resId]);
t('releasing it again is a clean 409', $rel2['http'] === 409, $rel2['raw']);
$rel3 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'release_reservation', 'ticket_id' => $tid, 'reservation_id' => 2147480000]);
t('an unknown reservation id is a 404', $rel3['http'] === 404, $rel3['raw']);

// Dispatch now
$ra2 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'assign', 'ticket_id' => $tid, 'responder_id' => $unit]);
$res2 = (int) ($ra2['json']['reservation_id'] ?? 0);
t('setup: reserved again (a finished row does not block a new one)', $res2 > 0, $ra2['raw']);
$dn = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tid, 'reservation_id' => $res2]);
t('dispatch_reservation_now dispatches the unit: success + a real assign_id', $dn['http'] === 200 && !empty($dn['json']['success']) && (int) ($dn['json']['assign_id'] ?? 0) > 0, $dn['raw']);
t('...the unit is Dispatched and the reservation is promoted',
    (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ? AND responder_id = ?", [$tid, $unit]) === 1
    && db_fetch_value("SELECT state FROM `{$prefix}assign_reservations` WHERE id = ?", [$res2]) === 'promoted');
t('...and the announcement (assign.created audit row) was written exactly once', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tid]) === 1);
$dn2 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tid, 'reservation_id' => $res2]);
t('dispatching an already-promoted reservation is a clean 409 (no second dispatch)', $dn2['http'] === 409, $dn2['raw']);

// The busy-unit confirm-and-resubmit flow the card's "Dispatch now" button drives.
$uBusy = p155_make_unit('UI-BUSY');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $uBusy]);
$tLive = p155_make_ticket(2, null, 'GH141 ui live');
$aLive = assign_create_internal($tLive, $uBusy, '', $adminId);
assign_update_status_internal((int) $aLive['id'], 'on_scene', $adminId);
$tB = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', 'GH141 ui busy');
$rb = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'assign', 'ticket_id' => $tB, 'responder_id' => $uBusy]);
$resB = (int) ($rb['json']['reservation_id'] ?? 0);
$d1 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tB, 'reservation_id' => $resB]);
t('Dispatch now on a BUSY unit answers needs_confirmation (a question, not an error)', $d1['http'] === 200 && !empty($d1['json']['needs_confirmation']) && !empty($d1['json']['message']), $d1['raw']);
t('...and nothing was dispatched yet', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tB]) === 0);
$d2 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tB, 'reservation_id' => $resB, 'force' => true]);
t('confirmed (force) it dispatches', $d2['http'] === 200 && !empty($d2['json']['success']) && (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}assigns` WHERE ticket_id = ?", [$tB]) === 1, $d2['raw']);

// A hard block: force cannot bypass it.
$stBlock = p155_make_status('p155_ui_block', 2);
$uBlk = p155_make_unit('UI-BLK');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stBlock, $uBlk]);
$tK = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 4 HOUR)', 'GH141 ui hard block');
$rk = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'assign', 'ticket_id' => $tK, 'responder_id' => $uBlk]);
$resK = (int) ($rk['json']['reservation_id'] ?? 0);
$k1 = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'dispatch_reservation_now', 'ticket_id' => $tK, 'reservation_id' => $resK, 'force' => true]);
t('a hard block is a 409 even with force', $k1['http'] === 409, $k1['raw']);

// The reserved answer when the dispatcher says "dispatch_now"
$uNow = p155_make_unit('UI-NOW');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $uNow]);
$rn = p155_probe('session', 'api/incident-assign.php', $adminId, ['action' => 'assign', 'ticket_id' => $tK, 'responder_id' => $uNow, 'dispatch_now' => true]);
t('`dispatch_now` on the assign action dispatches at once (no reservation)', $rn['http'] === 200 && !empty($rn['json']['assign_id']) && empty($rn['json']['reserved']), $rn['raw']);

// ── 5. The External API's reserved answer ────────────────────────────────
echo "\n--- External API answer ---\n";
$tok = ext_api_mint_token($adminId, ['incidents:write', 'incidents:read'], $adminId, ['name' => 'gh141-ui-token']);
test_fixture_guard_track('external_api_tokens', (int) $tok['id']);
$uExt = p155_make_unit('UI-EXT');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $uExt]);
$tExt = p155_make_ticket(3, 'DATE_ADD(NOW(), INTERVAL 6 HOUR)', 'GH141 ext');
$e1 = p155_probe('bearer', 'api/external/v1/assignments.php', $tok['raw_token'], ['ticket_id' => $tExt, 'responder_id' => $uExt, 'role' => 'Medic'], 'POST');
$d = $e1['json']['data'] ?? [];
t('POST /assignments in reserve mode answers 201 with reserved:true, reservation_id, ticket_id, responder_id, promotes_at (and NO `id`)',
    $e1['http'] === 201 && ($d['reserved'] ?? null) === true && !empty($d['reservation_id']) && (int) $d['ticket_id'] === $tExt
    && (int) $d['responder_id'] === $uExt && !empty($d['promotes_at']) && !array_key_exists('id', $d), $e1['raw']);
t('...and announced no assign.created', (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tExt]) === 0);
$uExt2 = p155_make_unit('UI-EXT2');
db_query("UPDATE `{$prefix}responder` SET un_status_id = ? WHERE id = ?", [$stAvail, $uExt2]);
$e2 = p155_probe('bearer', 'api/external/v1/assignments.php', $tok['raw_token'], ['ticket_id' => $tExt, 'responder_id' => $uExt2, 'dispatch_now' => true], 'POST');
t('"dispatch_now": true dispatches at once and answers with the normal {id, ticket_id, responder_id}',
    $e2['http'] === 201 && !empty($e2['json']['data']['id']) && !array_key_exists('reserved', $e2['json']['data']), $e2['raw']);
$assignAudit = db_fetch_all("SELECT details FROM `{$prefix}newui_audit_log` WHERE category = 'incident' AND activity = 'assign' AND JSON_EXTRACT(details, '$.ticket_id') = ?", [$tExt]);
t('...and the audit row keeps the External API shape (token_id, via_external_api) the webhook payload always had',
    count($assignAudit) === 1 && strpos((string) $assignAudit[0]['details'], '"via_external_api":true') !== false && strpos((string) $assignAudit[0]['details'], '"token_id"') !== false, json_encode($assignAudit));
$mid = p155_probe('bearer', 'api/external/v1/assignments.php', $tok['raw_token'], ['ticket_id' => $tExt, 'responder_id' => $uExt], 'POST');
t('reserving the same unit twice is a 422 (validation_failed) with the reason', $mid['http'] === 422 && stripos($mid['raw'], 'already reserved') !== false, $mid['raw']);

p155_set_setting('scheduled_assign_mode', 'immediate');
p155_cleanup();
echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
