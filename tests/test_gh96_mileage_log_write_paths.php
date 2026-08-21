<?php
/**
 * GH#96 Step 0 (2026-08-20) — mileage_log write-path bug fix regression.
 *
 * Root cause: inc/responder-write.php's status-extra-data mechanism only
 * ever wrote a STRUCTURED mileage_log row when the status's
 * extra_data_target was 'incident' (via _phase95_route_to_incident()'s
 * mileage branch, inside the per-open-assignment loop). The other two
 * UI-offered targets -- action_log (labeled "default -- shown in incident
 * log" in settings.php) and unit (whose own routing helper's docblock
 * admitted "action-log only for now") -- captured the value in the
 * action-log NOTE TEXT only, never as a structured row. Since action_log
 * is the Settings UI's own labeled default, most real mileage entries on
 * most installs were invisible to mileage_log entirely -- and therefore
 * to the Mileage Log report this fix ships alongside.
 *
 * This test drives the REAL writer (responder_set_status_internal(), the
 * exact function every caller -- the status modal, mobile, the /s command
 * bar, the external API -- funnels through) for all three targets, plus
 * the mobile start_mileage writer, and asserts a structured mileage_log
 * row actually lands each time. Proving this catches the original bug is
 * a build-time step, not something this file re-derives at runtime: with
 * the fix reverted (the action_log/unit branches restored to "action-log
 * only, no structured write"), sections 3 and 4 below fail; restoring the
 * fix makes them pass again. See CLAUDE.md's GH#96 entry for the
 * before/after assertion counts from that verification pass.
 *
 * Usage: php tests/test_gh96_mileage_log_write_paths.php
 */
require __DIR__ . '/../config.php';
require_once __DIR__ . '/_test_admin.php';

$pass = 0; $fail = 0;
function t($l, $c) { global $pass, $fail; echo ($c ? "[PASS] " : "[FAIL] ") . $l . "\n"; $c ? $pass++ : $fail++; }

echo "=== GH#96 Step 0 — mileage_log write-path fix ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';
$pdo = db();
$testUserId = test_admin_user_id();

// ── 1. Schema — mileage_log.org_id exists (run the Step 1 migration if
//      this test runs before it, matching the established
//      require-the-migration-inline pattern, e.g. test_gh52_second_extra_data_slot.php). ──
ob_start();
try {
    $idemOk = true;
    require __DIR__ . '/../sql/run_gh96_mileage_log_org_id.php';
} catch (Throwable $e) {
    $idemOk = false;
}
ob_end_clean();
t('run_gh96_mileage_log_org_id.php is idempotent (re-run clean)', $idemOk);

$hasOrgIdCol = (bool) db_fetch_value(
    "SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'org_id'",
    [$prefix . 'mileage_log']
);
t('mileage_log.org_id column exists', $hasOrgIdCol);

require_once __DIR__ . '/../inc/responder-write.php';
require_once __DIR__ . '/../inc/org-scope.php';

// ── 2. Fixture: three scratch un_status rows (one per target), a scratch
//      responder, and a scratch ticket + open assignment (needed to
//      exercise the 'incident' target's per-open-assignment loop at all --
//      with zero open assignments that loop body never runs for ANY
//      target, which would make this test unable to distinguish "target
//      routing is broken" from "there was nothing to route to"). All torn
//      down at the end regardless of pass/fail.
function gh96_make_status(string $prefix, string $label, string $target): int {
    db_query(
        "INSERT INTO `{$prefix}un_status`
            (status_val, description,
             extra_data_type, extra_data_required, extra_data_label, extra_data_target)
         VALUES (?, 'gh96 test', 'mileage', 0, 'Odometer', ?)",
        [$label, $target]
    );
    return (int) db_insert_id();
}

$scratchStatusIds = [];
$scratchResponder = 0;
$scratchTicket = 0;
$scratchAssign = 0;

try {
    $stActionLog = gh96_make_status($prefix, 'GH96 ActionLog', 'action_log');
    $stIncident  = gh96_make_status($prefix, 'GH96 Incident', 'incident');
    $stUnit      = gh96_make_status($prefix, 'GH96 Unit', 'unit');
    $scratchStatusIds = [$stActionLog, $stIncident, $stUnit];

    db_query(
        "INSERT INTO `{$prefix}responder` (`name`, `description`, `un_status_id`) VALUES (?, ?, ?)",
        ['GH96 Test Unit', 'gh96 scratch unit', $stActionLog]
    );
    $scratchResponder = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}ticket` (`in_types_id`, `scope`, `description`, `date`, `status`, `severity`)
         VALUES (0, 'GH96 scratch incident', 'gh96 scratch', NOW(), 2, 0)"
    );
    $scratchTicket = (int) db_insert_id();

    db_query(
        "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `dispatched`)
         VALUES (?, ?, ?, NOW())",
        [$scratchTicket, $scratchResponder, $testUserId]
    );
    $scratchAssign = (int) db_insert_id();

    t('scratch fixtures created (3 statuses + 1 responder + 1 ticket + 1 open assign)',
        $stActionLog > 0 && $stIncident > 0 && $stUnit > 0
        && $scratchResponder > 0 && $scratchTicket > 0 && $scratchAssign > 0);
} catch (Exception $e) {
    t('scratch fixtures created: ' . $e->getMessage(), false);
}

function gh96_mileage_rows_for(string $prefix, int $responderId): array {
    return db_fetch_all(
        "SELECT * FROM `{$prefix}mileage_log` WHERE responder_id = ? ORDER BY id",
        [$responderId]
    );
}

if ($scratchResponder > 0 && $scratchTicket > 0) {

    // ── 3. action_log target (the Settings UI's own labeled "default") --
    //      must now ALSO produce a structured mileage_log row, not just an
    //      action-log note. This is the exact target the bug hid the most
    //      real-world mileage under.
    $before = count(gh96_mileage_rows_for($prefix, $scratchResponder));
    $r = responder_set_status_internal($scratchResponder, $stActionLog, $testUserId, '',
        ['type' => 'mileage', 'value' => '111'], null);
    $after = gh96_mileage_rows_for($prefix, $scratchResponder);
    t('action_log target: status update succeeds', $r['updated'] === true);
    t('action_log target: a mileage_log row was written', count($after) === $before + 1);
    $lastRow = end($after);
    t('action_log target: row carries org_id (or NULL is at least a real column, not silently dropped)',
        $lastRow !== false && array_key_exists('org_id', $lastRow));
    t('action_log target: row miles resolves to the entered value',
        $lastRow !== false && (float) $lastRow['miles'] === 111.0);
    t('action_log target: row is linked to the open ticket',
        $lastRow !== false && (int) $lastRow['ticket_id'] === $scratchTicket);

    // ── 4. unit target -- same requirement, same reason.
    $before = count(gh96_mileage_rows_for($prefix, $scratchResponder));
    $r = responder_set_status_internal($scratchResponder, $stUnit, $testUserId, '',
        ['type' => 'mileage', 'value' => '222'], null);
    $after = gh96_mileage_rows_for($prefix, $scratchResponder);
    t('unit target: status update succeeds', $r['updated'] === true);
    t('unit target: a mileage_log row was written', count($after) === $before + 1);
    $lastRow = end($after);
    t('unit target: row miles resolves to the entered value',
        $lastRow !== false && (float) $lastRow['miles'] === 222.0);

    // ── 5. incident target -- pre-existing behavior, must remain intact
    //      (regression guard, not new coverage) -- one row per open
    //      assignment, ticket_id populated.
    $before = count(gh96_mileage_rows_for($prefix, $scratchResponder));
    $r = responder_set_status_internal($scratchResponder, $stIncident, $testUserId, '',
        ['type' => 'mileage', 'value' => '333'], null);
    $after = gh96_mileage_rows_for($prefix, $scratchResponder);
    t('incident target: status update succeeds', $r['updated'] === true);
    t('incident target: a mileage_log row was written', count($after) === $before + 1);
    $lastRow = end($after);
    t('incident target: row miles resolves to the entered value',
        $lastRow !== false && (float) $lastRow['miles'] === 333.0);
    t('incident target: row is linked to the open ticket',
        $lastRow !== false && (int) $lastRow['ticket_id'] === $scratchTicket);

    // ── 6. org_id resolution -- the session-derived value actually landed
    //      (not just present-as-a-column). Compares against what
    //      org_user_home_id() resolves for the same test user, since no
    //      $_SESSION['active_org_id'] is set in a CLI test run (the same
    //      fallback path a real background/API-token caller would take).
    $expectedOrgId = null;
    try { $expectedOrgId = org_user_home_id($testUserId); } catch (Exception $e) { $expectedOrgId = null; }
    $rows = gh96_mileage_rows_for($prefix, $scratchResponder);
    $lastRow = end($rows);
    if ($hasOrgIdCol && $expectedOrgId !== null) {
        t('org_id resolved via org_user_home_id() fallback lands on the row',
            $lastRow !== false && (int) $lastRow['org_id'] === (int) $expectedOrgId);
    } else {
        t('org_id resolution check skipped (no org_id column or no resolvable home org)', true);
    }

    // ── 7. Exactly ONE row per action_log/unit call, not per open
    //      assignment -- these two targets are documented (see
    //      _phase95_record_mileage_log()'s docblock) to write a SINGLE row
    //      using the first open assignment's ticket_id, unlike 'incident'
    //      which writes once per open assignment. With only one open
    //      assignment in this fixture the row COUNT can't distinguish the
    //      two shapes, so assert the documented behavior directly against
    //      the source instead (mirrors this codebase's established
    //      "assert the safety property in source, not just by accident of
    //      fixture shape" convention -- see test_gh52_second_extra_data_slot.php
    //      section 11).
    $writeSrc = file_get_contents(__DIR__ . '/../inc/responder-write.php');
    t('action_log/unit targets call the shared writer ONCE, before the per-assignment loop',
        strpos($writeSrc, '_phase95_record_mileage_log(') !== false
        && strpos($writeSrc, "\$extraTarget !== 'incident'") !== false
        && strpos($writeSrc, 'foreach ($openAssigns as $oa) {') > strpos($writeSrc, "\$extraTarget !== 'incident'"));

} else {
    t('writer tests skipped (fixture setup failed)', false);
}

// Teardown, regardless of outcome above.
try {
    if ($scratchAssign > 0) { db_query("DELETE FROM `{$prefix}assigns` WHERE id = ?", [$scratchAssign]); }
    if ($scratchTicket > 0) { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$scratchTicket]); }
    if ($scratchResponder > 0) {
        db_query("DELETE FROM `{$prefix}mileage_log` WHERE responder_id = ?", [$scratchResponder]);
        db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$scratchResponder]);
    }
    foreach ($scratchStatusIds as $id) { db_query("DELETE FROM `{$prefix}un_status` WHERE id = ?", [$id]); }
} catch (Exception $e) { /* best-effort cleanup */ }

// ── 8. Mobile writer (api/mobile-data.php's start_mileage action) -- the
//      HTTP action-dispatch code isn't extracted into a directly-callable
//      function (unlike responder_set_status_internal()), so -- matching
//      this codebase's established convention for that shape (see
//      tests/test_facility_responder_org_id_write.php's equipment.php/
//      vehicles.php checks) -- assert the source wiring directly: org_id
//      is resolved and conditionally included in the INSERT, guarded by
//      an information_schema check so a pre-migration install (org_id
//      column not yet added) still succeeds.
$mobileSrc = file_get_contents(__DIR__ . '/../api/mobile-data.php');
t('start_mileage resolves org_id from the session (active_org_id, falling back to org_user_home_id())',
    strpos($mobileSrc, "\$mobileMileageOrgId = \$_SESSION['active_org_id'] ?? null;") !== false
    && strpos($mobileSrc, 'org_user_home_id((int) $current_user_id)') !== false);
t('start_mileage guards the org_id column with an information_schema check before writing it',
    strpos($mobileSrc, "AND column_name = 'org_id'") !== false
    && strpos($mobileSrc, '$hasMileageOrgIdCol') !== false);
t('start_mileage INSERT includes org_id when the column exists',
    strpos($mobileSrc, '`start_odo`, `started_at`, `org_id`)') !== false);
t('start_mileage still has a plain fallback INSERT (no org_id) for a pre-migration install',
    strpos($mobileSrc, '`start_odo`, `started_at`)') !== false);

// ── 9. Settings UI text no longer implies only ONE target captures data
//      (the exact framing that made action_log's blind spot invisible to
//      admins -- it was labeled "default").
$settingsSrc = file_get_contents(__DIR__ . '/../settings.php');
t('settings.php no longer claims ticket.mileage/responder.mileage_last exist (neither column does)',
    strpos($settingsSrc, 'ticket.mileage') === false
    && strpos($settingsSrc, 'responder.mileage_last') === false);
t('settings.php now says all three targets record a structured Mileage Log trip',
    strpos($settingsSrc, 'structured trip in the Mileage Log') !== false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
