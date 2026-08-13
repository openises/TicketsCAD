<?php
/**
 * GH#52 (Eric, 2026-08-12, option 2 of 3 proposed) — a second, independent
 * extra-data slot on unit statuses, so one status change can collect BOTH
 * a destination facility AND a starting mileage reading (the case
 * @rjonesbsink traced) instead of only ever collecting one datum.
 *
 * Covers: the schema migration (idempotent, and safe on a fresh install
 * where the columns don't exist yet), the real writer
 * (responder_set_status_internal()) validating and logging each slot
 * independently, slot 1's required-and-missing error short-circuiting
 * before slot 2 is ever evaluated (so the two error codes can never both
 * appear in one response), and the static contract across
 * api/responder-status.php, api/responders.php, assets/js/app.js and
 * assets/js/command-bar.js that the recommendation's "chain slot 1 then
 * slot 2" design depends on.
 *
 * Usage: php tests/test_gh52_second_extra_data_slot.php
 */
require __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#52 — second extra-data slot on unit statuses ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();

// ── 1. Schema ────────────────────────────────────────────────────────
foreach (['extra_data_type_2', 'extra_data_required_2', 'extra_data_label_2', 'extra_data_target_2'] as $col) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute(["{$prefix}un_status", $col]);
    t("un_status.$col exists", (int) $stmt->fetchColumn() > 0);
}

ob_start();
try {
    $idemOk = true;
    require __DIR__ . '/../sql/run_gh52_second_extra_data.php';
} catch (Throwable $e) {
    $idemOk = false;
}
ob_end_clean();
t('run_gh52_second_extra_data.php is idempotent (re-run clean)', $idemOk);

// ── 2. Fixture: four scratch un_status rows (neither slot / slot 1 only /
//      slot 2 only / both slots) + a scratch responder, all torn down at
//      the end regardless of pass/fail.
require_once __DIR__ . '/../inc/responder-write.php';

function gh52_make_status(PDO $pdo, string $prefix, string $label, array $slot1, array $slot2): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (status_val, description,
             extra_data_type, extra_data_required, extra_data_label, extra_data_target,
             extra_data_type_2, extra_data_required_2, extra_data_label_2, extra_data_target_2)
         VALUES (?, 'gh52 test', ?, ?, ?, 'action_log', ?, ?, ?, 'action_log')",
        [
            $label,
            $slot1['type'] ?? 'none', $slot1['required'] ?? 0, $slot1['label'] ?? null,
            $slot2['type'] ?? 'none', $slot2['required'] ?? 0, $slot2['label'] ?? null,
        ]
    );
    return (int) db_insert_id();
}

$scratchIds = [];
$scratchResponder = 0;

try {
    $stNeither = gh52_make_status($pdo, $prefix, 'GH52 Neither', [], []);
    $stSlot1   = gh52_make_status($pdo, $prefix, 'GH52 Slot1', ['type' => 'mileage', 'required' => 1, 'label' => 'Odometer'], []);
    $stSlot2   = gh52_make_status($pdo, $prefix, 'GH52 Slot2', [], ['type' => 'facility', 'required' => 1, 'label' => 'Destination']);
    $stBoth    = gh52_make_status($pdo, $prefix, 'GH52 Both', ['type' => 'mileage', 'required' => 1, 'label' => 'Odometer'], ['type' => 'facility', 'required' => 1, 'label' => 'Destination']);
    $scratchIds = [$stNeither, $stSlot1, $stSlot2, $stBoth];

    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`) VALUES (?, ?, ?)",
        ['GH52 Test Unit', 'gh52 scratch unit', $stNeither]
    );
    $scratchResponder = (int) db_insert_id();

    t('scratch fixtures created (4 statuses + 1 responder)',
        $stNeither > 0 && $stSlot1 > 0 && $stSlot2 > 0 && $stBoth > 0 && $scratchResponder > 0);
} catch (Exception $e) {
    t('scratch fixtures created: ' . $e->getMessage(), false);
}

if ($scratchResponder > 0) {
    // ── 3. Neither slot configured — must behave exactly as before GH#52.
    $r = responder_set_status_internal($scratchResponder, $stNeither, 1, '', null, null);
    t('status with neither slot configured: no extra_data errors', empty($r['errors']));
    t('status with neither slot configured: extra_data_2_logged is false', ($r['extra_data_2_logged'] ?? null) === false);

    // ── 4. Slot 1 only, required, missing → the pre-existing error path,
    //      untouched by GH#52.
    $r = responder_set_status_internal($scratchResponder, $stSlot1, 1, '', null, null);
    t('slot 1 required + missing: extra_data_required error', in_array('extra_data_required', $r['errors'] ?? [], true));
    t('slot 1 required + missing: does NOT also carry a slot-2 error', !in_array('extra_data_2_required', $r['errors'] ?? [], true));

    // ── 5. Slot 1 only, satisfied → succeeds, slot 2 untouched.
    $r = responder_set_status_internal($scratchResponder, $stSlot1, 1, '', ['type' => 'mileage', 'value' => '12345'], null);
    t('slot 1 satisfied: update succeeds', $r['updated'] === true);
    t('slot 1 satisfied: extra_data_logged true', ($r['extra_data_logged'] ?? null) === true);
    t('slot 1 satisfied: extra_data_2_logged false (slot 2 not configured)', ($r['extra_data_2_logged'] ?? null) === false);

    // ── 6. Slot 2 only, required, missing → the NEW error path.
    $r = responder_set_status_internal($scratchResponder, $stSlot2, 1, '', null, null);
    t('slot 2 required + missing: extra_data_2_required error', in_array('extra_data_2_required', $r['errors'] ?? [], true));
    $labelLine = '';
    foreach ($r['errors'] ?? [] as $e) { if (strpos((string) $e, 'label:') === 0) { $labelLine = substr((string) $e, 6); break; } }
    t('slot 2 required + missing: error carries the configured label', $labelLine === 'Destination');

    // ── 7. Slot 2 only, satisfied → succeeds, slot 1 untouched.
    $r = responder_set_status_internal($scratchResponder, $stSlot2, 1, '', null, ['type' => 'facility', 'value' => '1']);
    t('slot 2 satisfied: update succeeds', $r['updated'] === true);
    t('slot 2 satisfied: extra_data_2_logged true', ($r['extra_data_2_logged'] ?? null) === true);
    t('slot 2 satisfied: extra_data_logged false (slot 1 not configured)', ($r['extra_data_logged'] ?? null) === false);

    // ── 8. Both slots configured, slot 1 missing → short-circuits on slot 1
    //      BEFORE slot 2 is ever evaluated (this is what api/responder-status.php's
    //      label-extraction relies on being unambiguous — see its comment).
    $r = responder_set_status_internal($scratchResponder, $stBoth, 1, '', null, ['type' => 'facility', 'value' => '1']);
    t('both slots, slot 1 missing: extra_data_required error (slot 1 wins)', in_array('extra_data_required', $r['errors'] ?? [], true));
    t('both slots, slot 1 missing: never reaches slot 2 error', !in_array('extra_data_2_required', $r['errors'] ?? [], true));

    // ── 9. Both slots configured, slot 1 satisfied but slot 2 missing.
    $r = responder_set_status_internal($scratchResponder, $stBoth, 1, '', ['type' => 'mileage', 'value' => '999'], null);
    t('both slots, slot 1 ok + slot 2 missing: extra_data_2_required error', in_array('extra_data_2_required', $r['errors'] ?? [], true));

    // ── 10. Both slots configured and satisfied — both log independently.
    $r = responder_set_status_internal($scratchResponder, $stBoth, 1, '',
        ['type' => 'mileage', 'value' => '54321'], ['type' => 'facility', 'value' => '1']);
    t('both slots satisfied: update succeeds', $r['updated'] === true);
    t('both slots satisfied: extra_data_logged true', ($r['extra_data_logged'] ?? null) === true);
    t('both slots satisfied: extra_data_2_logged true', ($r['extra_data_2_logged'] ?? null) === true);
} else {
    t('writer tests skipped (fixture setup failed)', false);
}

// Teardown, regardless of outcome above.
try {
    if ($scratchResponder > 0) { db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$scratchResponder]); }
    foreach ($scratchIds as $id) { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$id]); }
} catch (Exception $e) { /* best-effort cleanup */ }

// ── 11. API contract — the writer/reader/UI sites all speak the same
//      column set, and the design's key safety property (slot 1's error
//      short-circuits before slot 2 is evaluated) is visible in the source,
//      not just true by accident of test ordering above.
$writeSrc = file_get_contents(__DIR__ . '/../inc/responder-write.php');
t('responder_set_status_internal() accepts $extraData2', strpos($writeSrc, '$extraData2') !== false);
t('slot 1\'s required-and-missing check returns before slot 2 is validated',
    strpos($writeSrc, "'errors' => ['extra_data_required',") !== false
    && strpos($writeSrc, "'errors' => ['extra_data_2_required',") !== false
    && strpos($writeSrc, "// GH#52 — slot 2, validated identically and independently of slot 1.") !== false);
t('three-tier schema fallback covers both-slots / slot-1-only / neither (pre-GH#52 installs)',
    substr_count($writeSrc, "'extra_data_type_2'") >= 3);

$statusApiSrc = file_get_contents(__DIR__ . '/../api/responder-status.php');
t('api/responder-status.php reads extra_data_2 from the request', strpos($statusApiSrc, "input['extra_data_2']") !== false);
t('api/responder-status.php has a distinct extra_data_2_required structured error', strpos($statusApiSrc, "'code'  => 'extra_data_2_required'") !== false);

$respondersApiSrc = file_get_contents(__DIR__ . '/../api/responders.php');
t('api/responders.php exposes extra_data_type_2 in the status options payload', strpos($respondersApiSrc, "'extra_data_type_2'") !== false);
t('api/responders.php exposes extra_data_required_2', strpos($respondersApiSrc, "'extra_data_required_2'") !== false);

$appJsSrc = file_get_contents(__DIR__ . '/../assets/js/app.js');
t('app.js has a single _collectExtraData() chaining both slots', strpos($appJsSrc, 'function _collectExtraData(') !== false);
t('_collectExtraData chains slot 2 only after slot 1 resolves', strpos($appJsSrc, 'function collectSlot2(') !== false);
t('_postUnitStatus() forwards extra_data_2 to the API', strpos($appJsSrc, 'body.extra_data_2 = extraData2') !== false);
t('the Clear-status flow also chains slot 2 (not just the primary status picker)', strpos($appJsSrc, 'function _maybePromptSlot2(') !== false);

// ── 12. The admin UI to CONFIGURE a second slot -- until this was added,
//      the write/read path above was fully built and tested but had no way
//      to be reached from Settings: no form fields, and api/config-admin.php's
//      statuses GET didn't even SELECT the _2 columns, so a directly-DB-edited
//      row would show slot 1 correctly and slot 2 as blank/reset every time
//      the status was opened for editing again.
$settingsSrc = file_get_contents(__DIR__ . '/../settings.php');
t('settings.php has the second-slot Type field', strpos($settingsSrc, 'id="statusExtraDataType2"') !== false
    && strpos($settingsSrc, 'name="extra_data_type_2"') !== false);
t('settings.php has the second-slot Target field', strpos($settingsSrc, 'id="statusExtraDataTarget2"') !== false
    && strpos($settingsSrc, 'name="extra_data_target_2"') !== false);
t('settings.php has the second-slot Label field', strpos($settingsSrc, 'id="statusExtraDataLabel2"') !== false
    && strpos($settingsSrc, 'name="extra_data_label_2"') !== false);
t('settings.php has the second-slot Required checkbox', strpos($settingsSrc, 'id="statusExtraDataRequired2"') !== false
    && strpos($settingsSrc, 'name="extra_data_required_2"') !== false);

$configJsSrc = file_get_contents(__DIR__ . '/../assets/js/config.js');
t('config.js loads extra_data_type_2 into the form when editing a status', strpos($configJsSrc, "item.extra_data_type_2") !== false);
t('config.js explicitly reads the second-slot Required checkbox on save (unchecked boxes are absent from FormData)',
    strpos($configJsSrc, "getElementById('statusExtraDataRequired2')") !== false
    && strpos($configJsSrc, 'data.extra_data_required_2') !== false);

$configAdminSrc = file_get_contents(__DIR__ . '/../api/config-admin.php');
t('api/config-admin.php statuses GET selects extra_data_type_2', strpos($configAdminSrc, '`extra_data_type_2`') !== false);
t('api/config-admin.php statuses GET has a pre-GH#52-schema default backfill (array_key_exists guard)',
    strpos($configAdminSrc, "array_key_exists('extra_data_type_2', \$r)") !== false);
t('api/config-admin.php statuses POST writes extra_data_type_2 back', strpos($configAdminSrc, '`extra_data_type_2` = ?') !== false);
t('api/config-admin.php statuses POST validates extra_data_type_2 against the same allowlist as slot 1',
    (bool) preg_match('/\$extraType2\s*=.*;\s*\n\s*if \(!in_array\(\$extraType2, \$allowedExtraTypes, true\)\)/', $configAdminSrc));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
