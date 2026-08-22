<?php
/**
 * GH#103 (rjonesbsink, confirming cbyrdmo, 2026-08-22) — Import/Export's
 * member CSV export read ONLY the legacy field1/field2/field4/field6
 * columns for last_name/first_name/callsign/email, so on any install
 * where members are actually written through the NewUI roster (named
 * columns, not the legacy field* columns) every exported row had blank
 * names. The mirror bug on import: execute_import() wrote CSV data to
 * the legacy column unconditionally, while the roster (api/members.php)
 * reads the NAMED columns with no fallback — so an install where the
 * named columns are the real, independently-writable ones ended up with
 * imported members who are completely nameless in the roster, despite
 * the import reporting success with the correct row count. Same root
 * cause as GH#95 (api/reports.php), but in a different file, so that fix
 * never reached this one.
 *
 * Fix (inc/import-export.php + inc/functions.php):
 *   - get_table_config('member')'s last_name/first_name/callsign/email
 *     columns gained 'legacy_remap' => true.
 *   - export_csv() (both the SELECT list and the search-filter builder)
 *     reads a 'legacy_remap' column as
 *     COALESCE(NULLIF(named, ''), legacy) instead of the bare legacy
 *     column.
 *   - execute_import() (the row-insert remap, the `default`-fill loop,
 *     and the match_columns duplicate-detection lookup) resolves a
 *     'legacy_remap' column's real WRITE target via the new
 *     db_generated_column_map($table) (inc/functions.php, a table-
 *     parameterized generalization of api/members.php's existing
 *     member-only getGeneratedColumnMap()): if the named column is a
 *     GENERATED mirror of the legacy one, write legacy (unchanged
 *     behavior); otherwise write the named column directly (the fix).
 *
 * 'phone' deliberately does NOT get 'legacy_remap' — see the long
 * comment on it in get_table_config(): the roster reads `phone_cell`,
 * a THIRD column this pair doesn't reach either way, so remapping
 * phone/field7 would not have actually fixed roster visibility. Left
 * open, not silently "fixed" without closing it.
 *
 * facility.phone/contact, in_types.severity, and team.name/description/
 * team_type_id do NOT get 'legacy_remap' either — audited while building
 * this fix (see git history / CLAUDE.md for the full reasoning): none of
 * those named columns exist as real columns on any install checked (or,
 * for team.name/description, exist but are either a GENERATED mirror or
 * a column nothing internal ever reads — api/teams.php always reads
 * `mission AS description`). For those, `legacy` already IS the one
 * real column, and the pre-GH#103 bare-legacy read/write is correct.
 * Section 4 below proves those targets are unaffected by this change.
 *
 * TWO SCHEMA SHAPES, both exercised by driving the REAL execute_import()
 * and export_csv() (not a hand-derived re-implementation):
 *   - Section 3 uses the REAL, live `member` table on THIS dev database,
 *     which has first_name/last_name/callsign/email as GENERATED VIRTUAL
 *     mirrors of field2/field1/field4/field6 (confirmed via
 *     information_schema while building this fix) — the shape where the
 *     fix must be a no-op (the pre-fix code already worked here by
 *     coincidence, since a generated column always mirrors its source).
 *   - Section 4 uses a scratch table (`_test_gh103_plain`) with all
 *     relevant columns as PLAIN, independently writable columns — the
 *     exact shape BOTH GH#103 reporters' installs actually have, and
 *     the one the bug report is about (matching
 *     tests/test_gh95_personnel_report_named_columns.php's established
 *     pattern for testing this same distinction).
 *
 * Usage: php tests/test_gh103_legacy_column_import_export.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';
require_once __DIR__ . '/../inc/import-export.php';

$pass = 0; $fail = 0; $skip = 0;
function g103ok(string $m): void { global $pass; $pass++; echo "  PASS: $m\n"; }
function g103bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function g103chk($cond, string $m, string $hint = ''): void { $cond ? g103ok($m) : g103bad($m, $hint); }
function g103skip(string $m, string $why): void { global $skip; $skip++; echo "  SKIP: $m — $why\n"; }

echo "\n=== GH#103 — Import/Export member CSV: named-vs-legacy column fix ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Structural: get_table_config() carries the fix, scoped correctly --\n";
// ─────────────────────────────────────────────────────────────────────────

$memberCfg = get_table_config('member');
foreach (['last_name', 'first_name', 'callsign', 'email'] as $col) {
    g103chk(
        isset($memberCfg['columns'][$col]['legacy']) && !empty($memberCfg['columns'][$col]['legacy_remap']),
        "member.{$col} declares 'legacy' with 'legacy_remap' => true"
    );
}
g103chk(
    isset($memberCfg['columns']['phone']['legacy']) && empty($memberCfg['columns']['phone']['legacy_remap']),
    "member.phone keeps 'legacy' but deliberately has NO 'legacy_remap' (roster reads phone_cell, a third column this pair can't reach)"
);
foreach (['field3', 'field8', 'field9', 'field10', 'field11', 'field12', 'field13'] as $col) {
    g103chk(
        !isset($memberCfg['columns'][$col]['legacy']),
        "member.{$col} has no 'legacy' key at all (raw field, unaffected by this fix)"
    );
}

$facilityCfg = get_table_config('facility');
foreach (['phone', 'contact'] as $col) {
    g103chk(
        isset($facilityCfg['columns'][$col]['legacy']) && empty($facilityCfg['columns'][$col]['legacy_remap']),
        "facility.{$col} keeps its 'legacy' alias with NO 'legacy_remap' (contact_phone/contact_name are the only real columns)"
    );
}

$inTypesCfg = get_table_config('in_types');
g103chk(
    isset($inTypesCfg['columns']['severity']['legacy']) && empty($inTypesCfg['columns']['severity']['legacy_remap']),
    "in_types.severity keeps its 'legacy' alias with NO 'legacy_remap' (set_severity is the only real column)"
);

$teamCfg = get_table_config('team');
foreach (['name', 'description', 'team_type_id'] as $col) {
    g103chk(
        isset($teamCfg['columns'][$col]['legacy']) && empty($teamCfg['columns'][$col]['legacy_remap']),
        "team.{$col} keeps its 'legacy' alias with NO 'legacy_remap' (team/mission/ttypes_id are the columns api/teams.php actually reads)"
    );
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Structural: export_csv()'s source really emits the COALESCE fix --\n";
// ─────────────────────────────────────────────────────────────────────────

$src = (string) file_get_contents(__DIR__ . '/../inc/import-export.php');
g103chk(
    (bool) preg_match('/legacy_remap.*\n.*COALESCE\(NULLIF\(\{\$col\}`\{\$dbCol\}`, \'\'\), \{\$col\}`\{\$def\[.legacy.\]\}`\) AS `\{\$dbCol\}`/s', $src)
    || substr_count($src, "COALESCE(NULLIF({\$col}`{\$dbCol}`, ''), {\$col}`{\$def['legacy']}`)") >= 2,
    "export_csv() source contains the COALESCE(NULLIF(named,''), legacy) shape at least twice (SELECT list + search filter)"
);
g103chk(
    strpos($src, 'function _ie_resolve_write_column') !== false,
    "inc/import-export.php defines _ie_resolve_write_column()"
);
g103chk(
    strpos((string) file_get_contents(__DIR__ . '/../inc/functions.php'), 'function db_generated_column_map') !== false,
    "inc/functions.php defines the shared db_generated_column_map()"
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Functional: the REAL member table on this install (generated-column shape) --\n";
// ─────────────────────────────────────────────────────────────────────────

$genCols = db_generated_column_map(trim($prefix . 'member', '` '));
echo '  (this install\'s member generated columns: ' . (empty($genCols) ? '(none — fully plain install)' : implode(', ', array_keys($genCols))) . ")\n";

$realId = null;
register_shutdown_function(function () use (&$prefix, &$realId) {
    if (!$realId) return;
    try { db_query("DELETE FROM `{$prefix}member` WHERE id = ?", [$realId]); } catch (Throwable $e) {}
});

$uniqCallsign = 'N0GH103' . substr((string) mt_rand(1000, 9999), 0, 4);
$importRow = [
    'last_name'  => 'Gh103RealLast',
    'first_name' => 'Gh103RealFirst',
    'callsign'   => $uniqCallsign,
    'email'      => 'gh103real@example.test',
    'field3'     => 0,
    'field8'     => 'Yes',
];

$result = execute_import([$importRow], $memberCfg, 1, 'insert');
g103chk($result['inserted'] === 1 && empty($result['errors']),
    'execute_import() against the REAL member table inserts one row with no errors',
    'result=' . var_export($result, true));

$realId = (int) db_fetch_value(
    "SELECT id FROM `{$prefix}member` WHERE " . (isset($genCols['callsign']) ? 'field4' : 'callsign') . " = ? ORDER BY id DESC LIMIT 1",
    [$uniqCallsign]
);
g103chk($realId > 0, 'the imported real-table row can be found', "id={$realId}");

if ($realId) {
    // Roster-shaped read: bare named columns, no fallback — exactly
    // api/members.php's own SELECT shape. Must resolve correctly
    // whichever physical column the writer actually used.
    $roster = db_fetch_all(
        "SELECT first_name, last_name, callsign, email FROM `{$prefix}member` WHERE id = ?",
        [$realId]
    );
    $row = $roster[0] ?? [];
    g103chk(
        ($row['first_name'] ?? null) === 'Gh103RealFirst'
        && ($row['last_name'] ?? null) === 'Gh103RealLast'
        && ($row['callsign'] ?? null) === $uniqCallsign
        && ($row['email'] ?? null) === 'gh103real@example.test',
        'roster-shaped read (bare named columns, no fallback) resolves correctly on this install\'s real table',
        var_export($row, true)
    );

    // export_csv() must also resolve correctly for this row.
    $csv = export_csv($memberCfg, ['search' => $uniqCallsign]);
    g103chk($csv !== '', 'export_csv() returned non-empty CSV for the real-table row');
    g103chk(strpos($csv, 'Gh103RealLast') !== false && strpos($csv, 'Gh103RealFirst') !== false,
        'export_csv() output contains the real names (not blank) for the real-table row',
        $csv);
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 4. Functional: scratch table with PLAIN (non-generated) named columns --\n";
echo "      (the exact shape BOTH GH#103 reporters' installs actually have)\n";
// ─────────────────────────────────────────────────────────────────────────

$scratch = $prefix . '_test_gh103_plain';
$idNamed = null; $idLegacyOnly = null; $idOldBugDemo = null;

function g103_cleanup(string $scratch): void {
    try { db_query("DROP TABLE IF EXISTS `{$scratch}`"); } catch (Throwable $e) {}
}

register_shutdown_function(function () use ($scratch) { g103_cleanup($scratch); });

$scratchReady = false;
try {
    db_query("DROP TABLE IF EXISTS `{$scratch}`");
    db_query(
        "CREATE TABLE `{$scratch}` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `field1` VARCHAR(28) DEFAULT NULL,
            `field2` VARCHAR(28) DEFAULT NULL,
            `field3` INT DEFAULT 0,
            `field4` VARCHAR(16) DEFAULT NULL,
            `field6` VARCHAR(48) DEFAULT NULL,
            `field7` VARCHAR(20) DEFAULT NULL,
            `field8` VARCHAR(8) DEFAULT NULL,
            `field9` VARCHAR(64) DEFAULT NULL,
            `field10` VARCHAR(64) DEFAULT NULL,
            `field11` VARCHAR(12) DEFAULT NULL,
            `field12` DOUBLE DEFAULT NULL,
            `field13` DOUBLE DEFAULT NULL,
            `first_name` VARCHAR(28) DEFAULT NULL,
            `last_name` VARCHAR(28) DEFAULT NULL,
            `callsign` VARCHAR(16) DEFAULT NULL,
            `email` VARCHAR(48) DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $scratchReady = true;
} catch (Throwable $e) {
    g103skip('scratch plain-column table', 'could not CREATE TABLE: ' . $e->getMessage());
}

if ($scratchReady) {
    // Sanity: this scratch table really has NO generated columns —
    // confirms Section 4's assertions exercise the "not generated, write
    // the named column" branch of _ie_resolve_write_column(), not the
    // generated branch Section 3 already covers.
    $scratchGenCols = db_generated_column_map($scratch);
    g103chk(empty($scratchGenCols), 'scratch table has zero generated columns (confirms the plain-column code path is what runs below)',
        var_export($scratchGenCols, true));

    // Build a config pointing execute_import()/export_csv() at the
    // scratch table instead of the real member table. audit_cols cleared
    // — this scratch table has no _by/_on/_from columns, and GH#103 has
    // nothing to do with audit columns.
    $scratchCfg = $memberCfg;
    $scratchCfg['table'] = '_test_gh103_plain';
    $scratchCfg['audit_cols'] = [];

    $uniqCallsign2 = 'N0GH103P' . substr((string) mt_rand(1000, 9999), 0, 4);

    try {
        // ---- The reported bug's exact scenario: import a row, then read it the way the roster does ----
        $plainRow = [
            'last_name'  => 'Gh103PlainLast',
            'first_name' => 'Gh103PlainFirst',
            'callsign'   => $uniqCallsign2,
            'email'      => 'gh103plain@example.test',
            'field3'     => 0,
            'field8'     => 'Yes',
        ];
        $result2 = execute_import([$plainRow], $scratchCfg, 1, 'insert');
        g103chk($result2['inserted'] === 1 && empty($result2['errors']),
            'execute_import() against the plain-column scratch table inserts one row with no errors',
            'result=' . var_export($result2, true));

        $idNamed = (int) db_fetch_value("SELECT id FROM `{$scratch}` WHERE callsign = ? ORDER BY id DESC LIMIT 1", [$uniqCallsign2]);
        g103chk($idNamed > 0, 'the imported plain-column row can be found via its NAMED callsign column (proves the writer targeted the named column, not field4)', "id={$idNamed}");

        if ($idNamed) {
            $row = db_fetch_all("SELECT first_name, last_name, callsign, email, field1, field2, field4, field6 FROM `{$scratch}` WHERE id = ?", [$idNamed])[0] ?? [];
            g103chk(
                ($row['first_name'] ?? null) === 'Gh103PlainFirst' && ($row['last_name'] ?? null) === 'Gh103PlainLast'
                && ($row['callsign'] ?? null) === $uniqCallsign2 && ($row['email'] ?? null) === 'gh103plain@example.test',
                'THE FIX: roster-shaped read (bare named columns, no fallback) now resolves correctly for a plain-column install '
                . '— this is the reported bug ("nameless in the roster"), now fixed',
                var_export($row, true)
            );
            g103chk(
                empty($row['field1']) && empty($row['field2']) && empty($row['field4']) && empty($row['field6']),
                'the legacy field1/field2/field4/field6 columns were correctly left untouched (writer targeted the real, plain named columns instead)',
                var_export($row, true)
            );

            // export_csv() must resolve correctly for this row too.
            $csvNamed = export_csv($scratchCfg, ['search' => $uniqCallsign2]);
            g103chk($csvNamed !== '', 'export_csv() returned non-empty CSV for the plain-column NamedOnly row');
            g103chk(strpos($csvNamed, 'Gh103PlainLast') !== false && strpos($csvNamed, 'Gh103PlainFirst') !== false,
                'THE FIX: export_csv() output contains the real names (not blank) for the plain-column row '
                . '— this is the reported bug ("export returns rows with every name field blank"), now fixed',
                $csvNamed);

            // Duplicate-detection must also check the NAMED column now —
            // re-importing the identical row (mode=insert) must be
            // recognized as a match and skipped, not silently duplicated
            // by looking at the wrong (always-empty) legacy column.
            $result2b = execute_import([$plainRow], $scratchCfg, 1, 'insert');
            g103chk($result2b['skipped'] === 1 && $result2b['inserted'] === 0,
                'match_columns duplicate-detection checks the NAMED column too — re-importing the same row is skipped, not duplicated',
                'result=' . var_export($result2b, true));
        }

        // ---- Regression check: old, never-migrated data (legacy columns only) must still resolve ----
        db_query(
            "INSERT INTO `{$scratch}` (field1, field2, field4, field6, field3, field8) VALUES (?, ?, ?, ?, 0, 'Yes')",
            ['Gh103LegacyLast', 'Gh103LegacyFirst', 'N0GH103LEG', 'gh103legacy@example.test']
        );
        $idLegacyOnly = (int) db_insert_id();
        g103chk($idLegacyOnly > 0, 'fixture: a LegacyOnly row (real legacy field1/2/4/6, named columns NULL) created directly', "id={$idLegacyOnly}");

        $csvLegacy = export_csv($scratchCfg, ['search' => 'N0GH103LEG']);
        g103chk(strpos($csvLegacy, 'Gh103LegacyLast') !== false && strpos($csvLegacy, 'Gh103LegacyFirst') !== false,
            'export_csv() still resolves old, never-migrated legacy-only data correctly (no regression from this fix)',
            $csvLegacy);

        // ---- Regression guard: prove the OLD (pre-fix) code path really was broken on this exact schema shape ----
        // Reproduces the OLD execute_import() write logic verbatim
        // (unconditional redirect to 'legacy') against the SAME
        // plain-column scratch table, to prove the reported bug was
        // real on this schema shape, not hypothetical.
        $oldStyleRow = [
            'last_name'  => 'Gh103OldBugLast',
            'first_name' => 'Gh103OldBugFirst',
            'callsign'   => 'N0GH103OLD',
            'email'      => 'gh103oldbug@example.test',
        ];
        $oldInsertRow = [];
        foreach ($oldStyleRow as $dbCol => $val) {
            // This is the exact pre-GH#103 line:
            //   if (isset($columns[$dbCol]['legacy'])) { $insertRow[$columns[$dbCol]['legacy']] = $val; }
            if (isset($memberCfg['columns'][$dbCol]['legacy'])) {
                $oldInsertRow[$memberCfg['columns'][$dbCol]['legacy']] = $val;
            } else {
                $oldInsertRow[$dbCol] = $val;
            }
        }
        $cols = array_keys($oldInsertRow);
        $placeholders = array_fill(0, count($cols), '?');
        db_query(
            "INSERT INTO `{$scratch}` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")",
            array_values($oldInsertRow)
        );
        $idOldBugDemo = (int) db_insert_id();

        $oldBugRoster = db_fetch_all("SELECT first_name, last_name, callsign, email FROM `{$scratch}` WHERE id = ?", [$idOldBugDemo])[0] ?? [];
        g103chk(
            ($oldBugRoster['first_name'] ?? null) === null && ($oldBugRoster['last_name'] ?? null) === null
            && ($oldBugRoster['callsign'] ?? null) === null && ($oldBugRoster['email'] ?? null) === null,
            'REGRESSION GUARD: the OLD pre-fix write logic really did leave the roster-read named columns NULL '
            . 'on a plain-column install — proves the reported bug was real, not hypothetical',
            var_export($oldBugRoster, true)
        );
        $oldBugCsv = export_csv($scratchCfg, ['search' => 'N0GH103OLD']);
        g103chk(strpos($oldBugCsv, 'Gh103OldBugLast') !== false && strpos($oldBugCsv, 'Gh103OldBugFirst') !== false,
            'export_csv() (the NEW, fixed reader) still recovers the OLD-style row correctly via the legacy-column fallback '
            . '(a member imported before this fix is not permanently lost)',
            $oldBugCsv);

    } catch (Throwable $e) {
        g103bad('unexpected exception during Section 4', $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 5. Regression: facility/in_types/team import+export are unaffected by this fix --\n";
// ─────────────────────────────────────────────────────────────────────────

$facTable = db_table('facilities');
$facId = null;
register_shutdown_function(function () use (&$facTable, &$facId) {
    if ($facId) { try { db_query("DELETE FROM {$facTable} WHERE id = ?", [$facId]); } catch (Throwable $e) {} }
});
try {
    $uniqFacName = 'GH103 Facility ' . substr((string) mt_rand(10000, 99999), 0, 5);
    $facRow = ['name' => $uniqFacName, 'description' => 'GH#103 regression fixture', 'phone' => '555-0103', 'contact' => 'Gh103 Contact'];
    $facResult = execute_import([$facRow], $facilityCfg, 1, 'insert');
    g103chk($facResult['inserted'] === 1 && empty($facResult['errors']),
        'facility import (unaffected target) still inserts correctly', var_export($facResult, true));
    $facId = (int) db_fetch_value("SELECT id FROM {$facTable} WHERE name = ?", [$uniqFacName]);
    if ($facId) {
        $facRead = db_fetch_all("SELECT contact_phone, contact_name FROM {$facTable} WHERE id = ?", [$facId])[0] ?? [];
        g103chk(($facRead['contact_phone'] ?? null) === '555-0103' && ($facRead['contact_name'] ?? null) === 'Gh103 Contact',
            'facility import still writes to contact_phone/contact_name (legacy-only behavior, unchanged by this fix)',
            var_export($facRead, true));
        $facCsv = export_csv($facilityCfg, ['search' => $uniqFacName]);
        g103chk(strpos($facCsv, '555-0103') !== false && strpos($facCsv, 'Gh103 Contact') !== false,
            'facility export still reads contact_phone/contact_name correctly (unchanged by this fix)', $facCsv);
    }
} catch (Throwable $e) {
    g103bad('unexpected exception in the facility regression check', $e->getMessage());
}

$teamTable = db_table('teams');
$teamId = null;
register_shutdown_function(function () use (&$teamTable, &$teamId) {
    if ($teamId) { try { db_query("DELETE FROM {$teamTable} WHERE id = ?", [$teamId]); } catch (Throwable $e) {} }
});
try {
    $uniqTeamName = 'GH103 Team ' . substr((string) mt_rand(10000, 99999), 0, 5);
    $teamRow = ['name' => $uniqTeamName, 'description' => 'GH#103 regression fixture team'];
    $teamResult = execute_import([$teamRow], $teamCfg, 1, 'insert');
    g103chk($teamResult['inserted'] === 1 && empty($teamResult['errors']),
        'team import (unaffected target) still inserts correctly', var_export($teamResult, true));
    $teamId = (int) db_fetch_value("SELECT id FROM {$teamTable} WHERE `team` = ?", [$uniqTeamName]);
    if ($teamId) {
        // Exactly api/teams.php's own read shape: mission AS description.
        $teamRead = db_fetch_all("SELECT `team` AS name, mission AS description FROM {$teamTable} WHERE id = ?", [$teamId])[0] ?? [];
        g103chk(($teamRead['name'] ?? null) === $uniqTeamName && ($teamRead['description'] ?? null) === 'GH#103 regression fixture team',
            'team import still writes to team/mission (the columns api/teams.php reads), unchanged by this fix',
            var_export($teamRead, true));
        $teamCsv = export_csv($teamCfg, ['search' => $uniqTeamName]);
        g103chk(strpos($teamCsv, 'GH#103 regression fixture team') !== false,
            'team export still reads team/mission correctly (unchanged by this fix)', $teamCsv);
    }
} catch (Throwable $e) {
    g103bad('unexpected exception in the team regression check', $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 6. Follow-up found by CI: team import on a GENUINELY FRESH schema --\n";
echo "      (sql/base_schema.sql's `teams` table, no ad-hoc DEFAULTs this dev DB has accumulated)\n";
// ─────────────────────────────────────────────────────────────────────────
//
// CI's fresh-install job caught this on the first push: this dev
// database's live `teams` table has real DEFAULTs on `sub-group`/`by`/
// `from`/`on`/`ttypes_id`/`leader`/`leader_dpty` (some untracked fix set
// them directly, at some point, on this one long-lived box), but
// sql/base_schema.sql's own CREATE TABLE never gained one for any of
// them -- so team import worked here by accident and failed with MySQL
// 1364 on any genuinely fresh install, including CI's. This scratch
// table reproduces base_schema.sql's ACTUAL DDL byte-for-byte (verified
// against it while building this fix) rather than trusting this
// database's drifted-from-tracked-schema shape.

$freshScratch = $prefix . '_test_gh103_teams_fresh_schema';
register_shutdown_function(function () use ($freshScratch) {
    try { db_query("DROP TABLE IF EXISTS `{$freshScratch}`"); } catch (Throwable $e) {}
});

try {
    db_query("DROP TABLE IF EXISTS `{$freshScratch}`");
    // Copied verbatim from sql/base_schema.sql's `teams` CREATE TABLE —
    // every column that is NOT NULL here has NO DEFAULT clause, matching
    // the tracked schema file exactly (not this database's live, drifted
    // copy of the same table).
    db_query(
        "CREATE TABLE `{$freshScratch}` (
            `id` int(7) NOT NULL AUTO_INCREMENT,
            `team` varchar(48) NOT NULL,
            `sub-group` varchar(48) NOT NULL,
            `ttypes_id` int(7) NOT NULL,
            `mission` varchar(48) NOT NULL,
            `leader` int(4) NOT NULL,
            `leader_dpty` int(4) NOT NULL,
            `formed` date DEFAULT NULL,
            `by` int(7) NOT NULL,
            `from` varchar(16) NOT NULL,
            `on` datetime NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_teams_team_name` (`team`)
        ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci"
    );

    $freshCfg = $teamCfg;
    $freshCfg['table'] = '_test_gh103_teams_fresh_schema';

    $uniqFreshTeam = 'GH103 Fresh Schema Team ' . substr((string) mt_rand(10000, 99999), 0, 5);
    $freshResult = execute_import(
        [['name' => $uniqFreshTeam, 'description' => 'fresh schema fixture']],
        $freshCfg, 77, 'insert'
    );
    g103chk($freshResult['inserted'] === 1 && empty($freshResult['errors']),
        'THE FOLLOW-UP FIX: team import succeeds against base_schema.sql\'s real DDL (no ad-hoc defaults) '
        . '— this is what failed on CI\'s fresh install with MySQL 1364 on `sub-group`',
        'result=' . var_export($freshResult, true));

    if ($freshResult['inserted'] === 1) {
        $freshRow = db_fetch_all("SELECT * FROM `{$freshScratch}` WHERE `team` = ?", [$uniqFreshTeam])[0] ?? [];
        g103chk(
            ($freshRow['sub-group'] ?? null) === '' && (int) ($freshRow['leader'] ?? -1) === 0
            && (int) ($freshRow['leader_dpty'] ?? -1) === 0 && (int) ($freshRow['by'] ?? -1) === 77
            && ($freshRow['from'] ?? null) === '' && !empty($freshRow['on']),
            'the legacy NOT NULL columns (sub-group/leader/leader_dpty/by/from/on) all landed with the '
            . 'SAME placeholder values team_upsert_internal() (the real Teams UI writer) itself uses',
            var_export($freshRow, true)
        );

        // export_csv() and the roster-shaped read must still resolve
        // correctly against this schema shape too (name/description are
        // unaffected by this follow-up — this just proves no new gap was
        // introduced alongside the sub-group/leader/leader_dpty/by/from/on fix).
        $freshRoster = db_fetch_all("SELECT `team` AS name, mission AS description FROM `{$freshScratch}` WHERE `team` = ?", [$uniqFreshTeam])[0] ?? [];
        g103chk(($freshRoster['name'] ?? null) === $uniqFreshTeam && ($freshRoster['description'] ?? null) === 'fresh schema fixture',
            'roster-shaped read still resolves correctly on the fresh-schema scratch table', var_export($freshRoster, true));
    }

    // REGRESSION GUARD: prove the OLD config (no sub-group/leader/leader_dpty
    // defaults, empty audit_cols) really did fail against this exact DDL —
    // reproduces the OLD execute_import() call shape verbatim by using a
    // config with those three columns removed and audit_cols cleared, the
    // way get_table_config('team') looked before this follow-up.
    $oldStyleCfg = $freshCfg;
    unset($oldStyleCfg['columns']['sub-group'], $oldStyleCfg['columns']['leader'], $oldStyleCfg['columns']['leader_dpty']);
    $oldStyleCfg['audit_cols'] = [];
    $oldResult = execute_import(
        [['name' => $uniqFreshTeam . ' OldStyle', 'description' => 'old style fixture']],
        $oldStyleCfg, 77, 'insert'
    );
    g103chk(
        $oldResult['inserted'] === 0 && !empty($oldResult['errors']) && stripos($oldResult['errors'][0] ?? '', 'sub-group') !== false,
        'REGRESSION GUARD: the OLD config (pre-follow-up) really does fail with the exact MySQL 1364 '
        . 'CI reported, on this exact schema shape — proves the bug was real, not hypothetical',
        var_export($oldResult, true)
    );

} catch (Throwable $e) {
    g103bad('unexpected exception during Section 6', $e->getMessage());
}

echo "\n=== {$pass} passed, {$fail} failed" . ($skip > 0 ? ", {$skip} skipped" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
