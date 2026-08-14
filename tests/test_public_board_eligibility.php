<?php
/**
 * Phase 138 — Public incident board: pb_eligible_incidents() (tasks.md C1/C2).
 *
 * Drives the REAL query against real inserted rows — never a hand-simulated
 * filter — so a schema-mismatch typo fails loudly here instead of shipping
 * silently as "no open incidents" (spec.md's own quoted worry).
 *
 * Covers every eligibility gate from plan.md §2:
 *   - it.public_board_never_publish = 1               -> excluded
 *   - it.`group` in the excluded-groups setting        -> excluded
 *   - publish delay not yet elapsed                    -> excluded
 *   - Security Label routing_allow_broadcast = 0        -> excluded (PHP loop)
 *   - orphaned/unmatched in_types_id (INNER JOIN, not
 *     LEFT JOIN — security review finding #4)           -> excluded
 *   - a fully-eligible incident                         -> included
 *
 * `ticket.in_types_id` is `int(4) NOT NULL` in the base schema (no FK
 * constraint enforced) — there is no way to insert a literal NULL. The
 * "untyped incident" case is reproduced the way it can actually occur in
 * practice: a type row that existed at ticket-creation time and was later
 * deleted, leaving an orphaned reference the INNER JOIN can no longer
 * resolve. Functionally identical to NULL for this test's purpose (no
 * matching in_types row, so the query must exclude it).
 *
 * @requires-db
 * Usage: php tests/test_public_board_eligibility.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/public-board.php';
require_once __DIR__ . '/../inc/security-labels.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 138 — pb_eligible_incidents() ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── Preconditions: this phase's schema must exist ───────────────────────
try {
    $hasCol = db_fetch_value(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'public_board_never_publish'",
        [$prefix . 'in_types']
    );
} catch (Throwable $e) { $hasCol = false; }
if (!$hasCol) {
    echo "SKIP: Phase 138 schema not present (run sql/run_phase138_public_board.php first)\n";
    echo "\n=== $pass passed, $fail failed ===\n";
    exit(0);
}

$createdTypeIds  = [];
$createdTicketIds = [];
$createdLabelId  = null;
$origExcludedGroups = null;
$origDefaultDelay   = null;

function _pb_test_make_type(array $overrides = []): int {
    global $prefix, $createdTypeIds;
    // 2026-08-14: public_board_never_publish now defaults to 1 (never
    // publish) on the real column — every fixture here represents "an
    // admin has already opted this type in," which is the correct state
    // for exercising the OTHER eligibility gates (delay, excluded group,
    // Security Label) in isolation. Case 1 below overrides this back to 1
    // specifically to test the never-publish gate itself.
    $fields = array_merge([
        'type'                       => 'zz138-' . uniqid(),
        'description'                => 'Phase 138 eligibility test type',
        'group'                      => null,
        'public_board_never_publish' => 0,
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}in_types` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTypeIds[] = $id;
    return $id;
}

function _pb_test_make_ticket(int $typeId, array $overrides = []): int {
    global $prefix, $createdTicketIds;
    $fields = array_merge([
        'in_types_id' => $typeId,
        'contact'     => '',
        'street'      => '123 Test St',
        'city'        => 'Testville',
        'state'       => 'MN',
        'lat'         => 44.8,
        'lng'         => -93.3,
        'date'        => date('Y-m-d H:i:s', time() - 3600),
        'scope'       => 'Phase 138 eligibility test',
        'description' => 'Phase 138 eligibility test',
        'status'      => 2,
        'severity'    => 1,
        'updated'     => date('Y-m-d H:i:s'),
    ], $overrides);
    $cols = array_keys($fields);
    db_query(
        "INSERT INTO `{$prefix}ticket` (`" . implode('`,`', $cols) . "`) VALUES (" .
        implode(',', array_fill(0, count($cols), '?')) . ")",
        array_values($fields)
    );
    $id = (int) db_insert_id();
    $createdTicketIds[] = $id;
    return $id;
}

try {
    // ── Deterministic settings for this test (restored in finally) ──────
    $origExcludedGroups = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_excluded_groups'");
    $origDefaultDelay   = db_fetch_value("SELECT `value` FROM `{$prefix}settings` WHERE `name`='public_board_default_delay_secs'");
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_excluded_groups','ZZ138ExcludedGroup')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    db_query("INSERT INTO `{$prefix}settings` (`name`,`value`) VALUES ('public_board_default_delay_secs','60')
              ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");

    $oldEnough = date('Y-m-d H:i:s', time() - 3600); // well past any sane delay
    $justNow   = date('Y-m-d H:i:s', time());        // 0 seconds elapsed

    // ── Case 1: never-publish type ───────────────────────────────────────
    $neverPublishType = _pb_test_make_type(['public_board_never_publish' => 1]);
    $tNeverPublish = _pb_test_make_ticket($neverPublishType, ['date' => $oldEnough]);

    // ── Case 2: excluded-group type ──────────────────────────────────────
    $excludedGroupType = _pb_test_make_type(['group' => 'ZZ138ExcludedGroup']);
    $tExcludedGroup = _pb_test_make_ticket($excludedGroupType, ['date' => $oldEnough]);

    // ── Case 3: delay not yet elapsed (0s old, default delay is 60s) ────
    $delayType = _pb_test_make_type();
    $tDelayNotElapsed = _pb_test_make_ticket($delayType, ['date' => $justNow]);

    // ── Case 4: Security Label routing_allow_broadcast = 0 ──────────────
    db_query(
        "INSERT INTO `{$prefix}security_labels`
            (`code`,`name`,`sort_order`,`is_default`,`eoc_show_address`,`eoc_show_map_marker`,
             `routing_allow_broadcast`,`routing_allow_direct`,`audit_required_reason`)
         VALUES ('zz138_blocked','ZZ138 Blocked',999,0,1,'full',0,0,0)"
    );
    $createdLabelId = (int) db_insert_id();
    $broadcastType = _pb_test_make_type();
    $tBroadcastBlocked = _pb_test_make_ticket($broadcastType, [
        'date' => $oldEnough,
        'security_label_override_id' => $createdLabelId,
    ]);

    // ── Case 5: orphaned in_types_id (type existed, then deleted) ───────
    $orphanType = _pb_test_make_type();
    $tUntyped = _pb_test_make_ticket($orphanType, ['date' => $oldEnough]);
    db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$orphanType]);
    // Remove from the cleanup list — it's already gone.
    $createdTypeIds = array_values(array_filter($createdTypeIds, function ($id) use ($orphanType) {
        return $id !== $orphanType;
    }));

    // ── Case 6: fully eligible (older) ───────────────────────────────────
    $eligibleType = _pb_test_make_type();
    $tEligibleOlder = _pb_test_make_ticket($eligibleType, ['date' => date('Y-m-d H:i:s', time() - 7200)]);

    // ── Case 7: fully eligible (newer) — for ORDER BY t.date DESC check ──
    $tEligibleNewer = _pb_test_make_ticket($eligibleType, ['date' => $oldEnough]);

    // ═══════════════════════════════════════════════════════════════════
    $result = pb_eligible_incidents(null);
    $resultIds = array_map(function ($r) { return (int) $r['ticket']['id']; }, $result);

    t('never-publish type is EXCLUDED', !in_array($tNeverPublish, $resultIds, true));
    t('excluded-group type is EXCLUDED', !in_array($tExcludedGroup, $resultIds, true));
    t('delay-not-elapsed incident is EXCLUDED', !in_array($tDelayNotElapsed, $resultIds, true));
    t('broadcast-blocked (Security Label) incident is EXCLUDED', !in_array($tBroadcastBlocked, $resultIds, true));
    t('orphaned/untyped incident is EXCLUDED (INNER JOIN, not LEFT JOIN)', !in_array($tUntyped, $resultIds, true));
    t('fully-eligible incident (older) is INCLUDED', in_array($tEligibleOlder, $resultIds, true));
    t('fully-eligible incident (newer) is INCLUDED', in_array($tEligibleNewer, $resultIds, true));

    // NOTE: this runs against a real dev database that may already carry
    // other genuinely-open, genuinely-eligible incidents (demo data, real
    // dispatcher activity) — so the assertion is "our two eligible rows are
    // in there" (already covered above), never "the result set is exactly
    // our two rows." A strict-count assertion here would be a false
    // positive/negative machine on any install that isn't a pristine
    // throwaway DB.

    // ORDER BY t.date DESC — newer ticket must sort before the older one.
    $posNewer = array_search($tEligibleNewer, $resultIds, true);
    $posOlder = array_search($tEligibleOlder, $resultIds, true);
    t('ORDER BY t.date DESC (newer incident sorts first, matching feed.php)', $posNewer !== false && $posOlder !== false && $posNewer < $posOlder);

    // The B-section deviation: public_board_stub_label must be in the SELECT
    // list or every presence-only incident silently falls back to "Response"
    // even when an admin configured a specific stub.
    $eligibleRow = null;
    foreach ($result as $r) {
        if ((int) $r['ticket']['id'] === $tEligibleOlder) { $eligibleRow = $r['ticket']; break; }
    }
    t('row carries public_board_stub_label (required by pb_build_public_record)', $eligibleRow !== null && array_key_exists('public_board_stub_label', $eligibleRow));
    t('row carries a resolved Security Label (label sub-array present)', isset($result[0]['label']) && is_array($result[0]['label']));

    // ── org scope: a specific org id excludes an incident with a different/NULL org_id ──
    $orgScopedResult = pb_eligible_incidents(999999); // no ticket has this org_id
    $orgScopedIds = array_map(function ($r) { return (int) $r['ticket']['id']; }, $orgScopedResult);
    t('org-scoped query (?org=) excludes incidents not tagged to that org', !in_array($tEligibleOlder, $orgScopedIds, true));

} finally {
    // ── Cleanup: never leave throwaway rows or mutated settings behind ──
    foreach ($createdTicketIds as $id) {
        try { db_query("DELETE FROM `{$prefix}ticket` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    foreach ($createdTypeIds as $id) {
        try { db_query("DELETE FROM `{$prefix}in_types` WHERE id = ?", [$id]); } catch (Throwable $e) {}
    }
    if ($createdLabelId !== null) {
        try { db_query("DELETE FROM `{$prefix}security_labels` WHERE id = ?", [$createdLabelId]); } catch (Throwable $e) {}
    }
    if ($origExcludedGroups !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_excluded_groups'", [$origExcludedGroups]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_excluded_groups'");
    }
    if ($origDefaultDelay !== null) {
        db_query("UPDATE `{$prefix}settings` SET `value` = ? WHERE `name` = 'public_board_default_delay_secs'", [$origDefaultDelay]);
    } else {
        db_query("DELETE FROM `{$prefix}settings` WHERE `name` = 'public_board_default_delay_secs'");
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
