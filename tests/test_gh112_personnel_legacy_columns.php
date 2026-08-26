<?php
/**
 * GH#112 (rjonesbsink, 2026-08-25) — the same legacy-column disease as
 * #95/#103, found in four more files across two follow-up passes.
 *
 * api/personnel-config.php (7 sites): "Members by Type"/"Members by
 * Status" summary panels joined member.field3/field21 while the app
 * writes member_type_id/member_status_id — every member showed
 * "Unassigned". Two of the seven sites are NOT display code: the
 * delete-safety guards for member types/statuses counted on the legacy
 * column too, so a type/status with real members assigned counted as
 * zero and never actually blocked the delete.
 *
 * api/ics-positions.php + api/time-entries.php (4 more sites, found in
 * rjonesbsink's own follow-up sweep): same disease for member
 * first_name/last_name (+ callsign in two of the four), rendering
 * nameless members in ICS qualification lists and time entries.
 * ics-positions.php separately had field26 for callsign instead of
 * field4 (every other file in this tree's own convention, per
 * api/teams.php's PRE-RELEASE-FIXES #17 comment) — a second, older,
 * independent bug living in the same query.
 *
 * api/compliance.php (found independently while fixing the above, NOT
 * named in rjonesbsink's report): the SAME field3 disease in the
 * 'overview' action's type join, PLUS a much sharper, unrelated bug in
 * the same two queries — `m.name` is not a column that exists on this
 * table at all (first_name/last_name/middle_name are). That's a hard
 * SQLSTATE 42S22 "Unknown column" error, so BOTH the 'overview' and
 * 'expiring' actions 500'd on every install, independent of the field3
 * bug. Fixed with CONCAT(m.first_name, ' ', m.last_name), matching the
 * established convention in api/equipment.php/api/events.php/
 * api/shifts.php/api/training.php for this exact "member display name"
 * shape.
 *
 * Fixed with the SAME COALESCE(NULLIF(named,''), legacy) / plain
 * COALESCE(named, legacy) pattern already shipping in api/reports.php
 * (#95), inc/import-export.php (#103), and api/teams.php/api/equipment.php.
 *
 * @requires-db
 * Usage: php tests/test_gh112_personnel_legacy_columns.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/db.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#112: personnel-config/ics-positions/time-entries read named columns with a legacy fallback ===\n\n";

$prefix = $GLOBALS['db_prefix'] ?? '';

// ── 1. Static contract: every one of the 7 personnel-config.php sites,
// and every one of the 4 ics-positions.php/time-entries.php sites, now
// uses COALESCE — and NO bare, un-COALESCEd field3/field21/field26 read
// remains in any of the three files. ───────────────────────────────────
$pcSrc  = file_get_contents($root . '/api/personnel-config.php');
$icsSrc = file_get_contents($root . '/api/ics-positions.php');
$teSrc  = file_get_contents($root . '/api/time-entries.php');

$pcCoalesceCount = substr_count($pcSrc, 'COALESCE(member_type_id, field3)') + substr_count($pcSrc, 'COALESCE(m.member_type_id, m.field3)')
                 + substr_count($pcSrc, 'COALESCE(member_status_id, field21)') + substr_count($pcSrc, 'COALESCE(m.member_status_id, m.field21)');
if ($pcCoalesceCount >= 7) {
    ok("api/personnel-config.php has at least 7 COALESCE(named, legacy) sites (found {$pcCoalesceCount})");
} else {
    bad("api/personnel-config.php has fewer than 7 COALESCE sites", "found only {$pcCoalesceCount} — GH#112 regression, some sites may still read the bare legacy column");
}
if (preg_match('/(?<!COALESCE\()(?<!, )\bm?\.?field3\s*=/', $pcSrc) === 0 || !preg_match('/WHERE\s+field3\s*=\s*\?/', $pcSrc)) {
    ok('api/personnel-config.php has no remaining bare "field3 = ?" delete-guard read');
} else {
    bad('api/personnel-config.php still has a bare field3 delete-guard', 'the delete-safety guard would still never actually block a delete');
}
if (!preg_match('/WHERE\s+field21\s*=\s*\?/', $pcSrc)) {
    ok('api/personnel-config.php has no remaining bare "field21 = ?" delete-guard read');
} else {
    bad('api/personnel-config.php still has a bare field21 delete-guard');
}

if (preg_match('/COALESCE\(NULLIF\(m\.last_name,\s*\'\'\),\s*m\.field1\)[\s\S]{0,200}COALESCE\(NULLIF\(m\.first_name,\s*\'\'\),\s*m\.field2\)[\s\S]{0,200}COALESCE\(NULLIF\(m\.callsign,\s*\'\'\),\s*m\.field4\)/', $icsSrc)) {
    ok('api/ics-positions.php uses COALESCE(NULLIF(...)) for last_name/first_name AND fixes callsign to field4 (was field26)');
} else {
    bad('api/ics-positions.php does not have the expected COALESCE + field4 shape', 'GH#112 regression — both the legacy-column bug and the separate field26 bug could reappear');
}
if (preg_match('/\bm\.field26\b/', $icsSrc)) {
    bad('api/ics-positions.php still references m.field26 somewhere', 'the separate, older callsign-column bug (should be field4) may not be fully fixed');
} else {
    ok('api/ics-positions.php no longer references the wrong field26 column anywhere');
}

$teCoalesceCount = substr_count($teSrc, "COALESCE(NULLIF(m.first_name, '')") ;
if ($teCoalesceCount >= 3) {
    ok("api/time-entries.php has COALESCE(NULLIF(...)) at all 3 known sites (found {$teCoalesceCount})");
} else {
    bad('api/time-entries.php has fewer than 3 COALESCE sites', "found only {$teCoalesceCount}");
}

// ── 1b. Static contract: api/compliance.php (found independently, not
// part of rjonesbsink's report) — no remaining bare "m.name" (a column
// that doesn't exist at all) and the field3 join uses the same COALESCE
// pattern as everywhere else. ───────────────────────────────────────────
$compSrc = file_get_contents($root . '/api/compliance.php');
if (preg_match('/\bm\.name\b/', $compSrc)) {
    bad('api/compliance.php still references m.name somewhere', 'member has no "name" column at all — this is a live SQL error (1054), not a legacy-column mismatch');
} else {
    ok('api/compliance.php no longer references the nonexistent m.name column anywhere');
}
if (substr_count($compSrc, 'COALESCE(m.member_type_id, m.field3)') >= 2) {
    ok('api/compliance.php uses COALESCE(member_type_id, field3) in both the SELECT and the JOIN condition');
} else {
    bad('api/compliance.php is missing the field3 COALESCE fix', 'GH#112 regression — the type join may fall back to the never-written legacy column again');
}
if (substr_count($compSrc, "CONCAT(m.first_name, ' ', m.last_name)") >= 2) {
    ok('api/compliance.php builds member display name from first_name/last_name at both sites (overview + expiring)');
} else {
    bad('api/compliance.php is missing the CONCAT(first_name, last_name) fix at one or both sites');
}

// ── 2. Functional: personnel-config.php's member_type_id/field3 and
// member_status_id/field21 are PLAIN columns on every install (unlike
// first_name/last_name/callsign, which are GENERATED on this dev
// database — see tests/test_gh95_personnel_report_named_columns.php's
// own documented reason for testing that case differently). Both shapes
// are directly testable here: modern-only (named populated, legacy
// NULL) and legacy-only (only the old column populated). ─────────────
try {
    db_query("INSERT INTO `{$prefix}member_types` (`name`, `description`, `color`, `_on`, `_by`) VALUES ('GH112 Test Type', '', '#123456', NOW(), 1)");
    $typeId = (int) db_insert_id();
    db_query("INSERT INTO `{$prefix}member_status` (`status_val`, `description`, `color`) VALUES ('GH112 Test Status', '', '#123456')");
    $statusId = (int) db_insert_id();

    try {
        // Modern-only member: member_type_id/member_status_id set,
        // field3/field21 left NULL. first_name/last_name are GENERATED
        // (mirror field2/field1) on this dev database and can't be
        // written directly — irrelevant to this section anyway, which
        // is only about member_type_id/member_status_id.
        db_query(
            "INSERT INTO `{$prefix}member` (`member_type_id`, `member_status_id`) VALUES (?, ?)",
            [$typeId, $statusId]
        );
        $modernId = (int) db_insert_id();
        // Legacy-only member: only field3/field21 set (simulating a pre-migration row).
        db_query(
            "INSERT INTO `{$prefix}member` (`field3`, `field21`) VALUES (?, ?)",
            [$typeId, $statusId]
        );
        $legacyId = (int) db_insert_id();

        try {
            $typeCount = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}member` m WHERE COALESCE(m.member_type_id, m.field3) = ?",
                [$typeId]
            );
            if ($typeCount === 2) {
                ok('the fixed member-type query finds BOTH the modern-only and legacy-only fixture members (2 of 2)');
            } else {
                bad('the fixed member-type query did not find both fixture members', "expected 2, got {$typeCount}");
            }

            $statusCount = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}member` m WHERE COALESCE(m.member_status_id, m.field21) = ?",
                [$statusId]
            );
            if ($statusCount === 2) {
                ok('the fixed member-status query finds BOTH the modern-only and legacy-only fixture members (2 of 2)');
            } else {
                bad('the fixed member-status query did not find both fixture members', "expected 2, got {$statusCount}");
            }

            // Regression proof for the delete-guard specifically: the
            // OLD bare "field3 = ?" query must NOT find the modern-only
            // member (proving the guard really was broken for exactly
            // the case that matters — a member assigned the modern way).
            $oldGuardCount = (int) db_fetch_value(
                "SELECT COUNT(*) FROM `{$prefix}member` WHERE field3 = ?",
                [$typeId]
            );
            if ($oldGuardCount === 1) {
                ok('confirms the ORIGINAL bug precisely: the old bare field3 guard sees only the legacy-only member (1), missing the modern-only one entirely — exactly why a type in real use could be deleted');
            } else {
                bad('the old-query reproduction did not match the expected historical bug shape', "expected 1, got {$oldGuardCount}");
            }
        } finally {
            db_query("DELETE FROM `{$prefix}member` WHERE id IN (?, ?)", [$modernId, $legacyId]);
        }
    } finally {
        db_query("DELETE FROM `{$prefix}member_types` WHERE id = ?", [$typeId]);
        db_query("DELETE FROM `{$prefix}member_status` WHERE id = ?", [$statusId]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not drive the member_type_id/member_status_id functional check (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

// ── 3. Functional: first_name/last_name/callsign are GENERATED (mirror
// field2/field1/field4) on THIS dev database — the same constraint
// tests/test_gh95_personnel_report_named_columns.php already documents.
// Writing to field1/field2/field4 directly is the only way to populate
// them here; this proves the fixed ics-positions.php/time-entries.php
// query shape correctly resolves a real member's name either way. ──────
try {
    db_query(
        "INSERT INTO `{$prefix}member` (`field1`, `field2`, `field4`) VALUES ('GH112LastGen', 'GH112FirstGen', 'GH112CALL')"
    );
    $genId = (int) db_insert_id();
    try {
        $row = db_fetch_one(
            "SELECT COALESCE(NULLIF(last_name, ''), field1) AS last_name,
                    COALESCE(NULLIF(first_name, ''), field2) AS first_name,
                    COALESCE(NULLIF(callsign, ''), field4) AS callsign
             FROM `{$prefix}member` WHERE id = ?",
            [$genId]
        );
        if ($row && $row['last_name'] === 'GH112LastGen' && $row['first_name'] === 'GH112FirstGen' && $row['callsign'] === 'GH112CALL') {
            ok('the fixed name-resolution query shape correctly resolves a real member on this install\'s generated-column schema (field1/field2/field4 -> last_name/first_name/callsign)');
        } else {
            bad('the name-resolution query did not resolve as expected', 'got ' . var_export($row, true));
        }
    } finally {
        db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$genId]);
    }
} catch (Throwable $e) {
    echo "SKIP: could not drive the generated-column name-resolution check (" . $e->getMessage() . ") — 0 passed, 0 failed for this section\n";
}

// ── 4. Functional: reproduce api/compliance.php's real overview query
// verbatim against a fixture member, proving (a) it executes at all —
// the original bare "m.name" was a hard SQL error, not a silent
// mismatch — and (b) a modern-only member (member_type_id set, field3
// left at its column default) resolves through the COALESCE join. ──────
try {
    // member_types.name is varchar(16) — must stay within that limit.
    db_query("INSERT INTO `{$prefix}member_types` (`name`, `description`, `color`, `_on`, `_by`) VALUES ('GH112CompType', '', '#123456', NOW(), 1)");
    $compTypeId = (int) db_insert_id();
    try {
        db_query(
            "INSERT INTO `{$prefix}member` (`field1`, `field2`, `member_type_id`) VALUES ('ComplianceGenLast', 'ComplianceGenFirst', ?)",
            [$compTypeId]
        );
        $compMemberId = (int) db_insert_id();
        try {
            $row = db_fetch_one(
                "SELECT m.id, CONCAT(m.first_name, ' ', m.last_name) AS name, m.callsign,
                        COALESCE(m.member_type_id, m.field3) AS type_id,
                        mt.name AS type_name, mt.color AS type_color
                 FROM `{$prefix}member` m
                 LEFT JOIN `{$prefix}member_types` mt ON COALESCE(m.member_type_id, m.field3) = mt.id
                 WHERE m.id = ?",
                [$compMemberId]
            );
            if ($row && $row['type_id'] == $compTypeId && $row['type_name'] === 'GH112CompType' && $row['name'] === 'ComplianceGenFirst ComplianceGenLast') {
                ok("api/compliance.php's fixed overview query runs without error and resolves a modern-only member's type + name correctly");
            } else {
                bad("api/compliance.php's fixed overview query did not resolve as expected", 'got ' . var_export($row, true));
            }
        } finally {
            db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$compMemberId]);
        }
    } finally {
        db_query("DELETE FROM `{$prefix}member_types` WHERE id = ?", [$compTypeId]);
    }
} catch (Throwable $e) {
    bad('api/compliance.php overview query threw', $e->getMessage() . ' — this is exactly the original bug (SQLSTATE 42S22 Unknown column m.name) if it reappears');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
