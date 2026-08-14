<?php
/**
 * GH#52 follow-up (Chris Byrd, 2026-08-13) — after the second extra-data
 * slot shipped, the Incident page's own status-change path
 * (assign_update_status_internal(), used only by api/incident-assign.php)
 * turned out never to have been given ANY of it:
 *
 *   1. No facility picker -- window.TCADStatusExtraDataPrompt was
 *      referenced in incident-detail.js since Phase 104f but never
 *      defined anywhere, so every extra-data status fell back to a plain
 *      window.prompt() (fixed in assets/js/status-extra-data-prompt.js +
 *      incident-detail.js, not covered by this PHP test).
 *   2. No extra_data_type_2/required_2 handling at all in
 *      assign_update_status_internal() -- covered here.
 *   3. The activity log recorded only the status name, never what was
 *      actually collected (responder_set_status_internal()'s sibling path
 *      already did this via _phase95_summarize_extra()) -- covered here.
 *
 * @requires-db
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

$pass = 0; $fail = 0;
function test(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

try {
    db_fetch_value('SELECT 1');
} catch (Throwable $e) {
    echo "SKIP: no database connection (" . $e->getMessage() . ")\n";
    echo "\n0 passed, 0 failed\n";
    exit(0);
}

require_once $root . '/inc/assignment-write.php';

$prefix = $GLOBALS['db_prefix'] ?? '';
$_SESSION = ['user_id' => 1, 'user' => 'admin', 'level' => 0];

$tid = 0; $rid = 0; $fid = 0; $statId = 0; $assignId = 0;
try {
    $typeId = (int) db_fetch_value("SELECT id FROM `{$prefix}in_types` ORDER BY id LIMIT 1");
    db_query("INSERT INTO `{$prefix}ticket` (in_types_id, status, severity, scope, description, date, problemstart, _by)
              VALUES (?, 2, 0, 'gh52 fixture', 'GH#52 follow-up fixture', NOW(), NOW(), 1)", [$typeId]);
    $tid = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}responder` (name, handle, description, un_status_id, status_updated, updated)
              VALUES ('gh52_unit', 'GH52', 'test', 1, NOW(), NOW())");
    $rid = (int) db_insert_id();

    db_query("INSERT INTO `{$prefix}facilities` (name, description, type, status_id, updated, _by, _on)
              VALUES ('gh52_fac_dest', 'test', 0, 0, NOW(), 1, NOW())");
    $fid = (int) db_insert_id();

    // A status requiring BOTH a facility (slot 1) and a numeric value
    // (slot 2, e.g. mileage) -- exactly the "TRP" shape Chris reported:
    // needs a destination AND collects something else at the same time.
    db_query("INSERT INTO `{$prefix}un_status`
              (status_val, description, dispatch, watch, hide, excl_from_reset, `group`, sort,
               bg_color, text_color, incident_action, resets_par,
               extra_data_type, extra_data_required, extra_data_label,
               extra_data_type_2, extra_data_required_2, extra_data_label_2)
              VALUES ('gh52 TRP', 'test', 0, 0, 'n', 'n', 'busy', 99,
                      'transparent', '#000000', '', 0,
                      'facility', 1, 'Destination',
                      'numeric', 1, 'Starting mileage')");
    $statId = (int) db_insert_id();

    $created = assign_create_internal($tid, $rid, '', 1);
    $assignId = (int) ($created['id'] ?? 0);
    test('unit assigned via the real writer', $assignId > 0);

    // 1. Neither slot supplied -> rejected asking for slot 1 first.
    $GLOBALS['_assign_update_status_input'] = [];
    $r1 = assign_update_status_internal($assignId, $statId, 1);
    test('missing both slots -> rejected for slot 1',
        !empty($r1['errors']) && $r1['errors'][0] === 'extra_data_required',
        json_encode($r1));
    unset($GLOBALS['_assign_update_status_input']);

    // 2. Slot 1 supplied, slot 2 missing -> rejected specifically for slot 2.
    $GLOBALS['_assign_update_status_input'] = ['extra_data' => ['value' => $fid]];
    $r2 = assign_update_status_internal($assignId, $statId, 1);
    test('slot 1 supplied, slot 2 missing -> rejected for slot 2 specifically (not slot 1 again)',
        !empty($r2['errors']) && $r2['errors'][0] === 'extra_data_required_2',
        json_encode($r2));
    unset($GLOBALS['_assign_update_status_input']);

    // 3. Both slots supplied -> succeeds.
    $GLOBALS['_assign_update_status_input'] = [
        'extra_data'   => ['value' => $fid],
        'extra_data_2' => ['value' => 12345],
    ];
    $r3 = assign_update_status_internal($assignId, $statId, 1);
    test('both slots supplied -> succeeds', empty($r3['errors']), json_encode($r3));
    unset($GLOBALS['_assign_update_status_input']);

    $gotFac = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
    test('the slot-1 facility is written to the assignment (per-unit destination)',
        $gotFac === $fid, "expected {$fid}, got {$gotFac}");

    $gotUnStatus = (int) db_fetch_value("SELECT un_status_id FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    test('the responder status is set to the picked status', $gotUnStatus === $statId);

    $logDesc = (string) db_fetch_value(
        "SELECT description FROM `{$prefix}action` WHERE ticket_id = ? AND action_type = 21 ORDER BY id DESC LIMIT 1",
        [$tid]
    );
    test('the activity log names the facility by NAME, not just "something was set"',
        strpos($logDesc, 'gh52_fac_dest') !== false,
        "got: {$logDesc}");
    test('the activity log also names the numeric slot-2 value',
        strpos($logDesc, '12345') !== false,
        "got: {$logDesc}");
    test('the activity log uses each slot\'s configured label, not a generic word',
        strpos($logDesc, 'Destination') !== false && strpos($logDesc, 'Starting mileage') !== false,
        "got: {$logDesc}");

    // 4. Facility configured in slot 2 instead of slot 1 must still be
    // written -- an install may order the two slots either way.
    db_query("UPDATE `{$prefix}un_status`
              SET extra_data_type = 'numeric', extra_data_label = 'Odometer',
                  extra_data_type_2 = 'facility', extra_data_label_2 = 'Where to'
              WHERE id = ?", [$statId]);
    db_query("UPDATE `{$prefix}assigns` SET rec_facility_id = NULL WHERE id = ?", [$assignId]);
    $GLOBALS['_assign_update_status_input'] = [
        'extra_data'   => ['value' => 999],
        'extra_data_2' => ['value' => $fid],
    ];
    $r4 = assign_update_status_internal($assignId, $statId, 1);
    test('slot-2-configured-as-facility case succeeds', empty($r4['errors']), json_encode($r4));
    unset($GLOBALS['_assign_update_status_input']);
    $gotFac2 = (int) db_fetch_value("SELECT rec_facility_id FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
    test('the facility is written even when it is slot 2, not slot 1',
        $gotFac2 === $fid, "expected {$fid}, got {$gotFac2}");
} catch (Exception $e) {
    echo "[FAIL] fixture threw: " . $e->getMessage() . "\n"; $fail++;
}

// Teardown.
try {
    if ($assignId > 0) db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$assignId]);
    if ($tid > 0) db_query("DELETE FROM `{$prefix}action` WHERE ticket_id = ?", [$tid]);
    if ($tid > 0) db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$tid]);
    if ($rid > 0) db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$rid]);
    if ($fid > 0) db_query("DELETE FROM `{$prefix}facilities` WHERE id = ?", [$fid]);
    if ($statId > 0) db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$statId]);
} catch (Exception $e) {
    echo "Teardown warning: " . $e->getMessage() . "\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
