<?php
/**
 * GH#95 (rjonesbsink, cbyrdmo, 2026-08-20) — all 6 Personnel reports in
 * api/reports.php read the LEGACY member.field1/field2/field3/field4/
 * field6/field7/field8 columns instead of the NAMED last_name/first_name/
 * member_type_id/callsign/email/phone_cell/available columns. On installs
 * where the named columns are GENERATED (virtual, derived from the legacy
 * field via getGeneratedColumnMap()/remapGeneratedColumns() in
 * api/members.php), this happened to work by coincidence -- a generated
 * column always mirrors its legacy source. On installs where the named
 * columns are PLAIN (independently writable, per sql/run_member_columns.php's
 * addCol()) -- confirmed real on two independent installs (GH#95) -- data
 * saved through the normal roster UI lands in the named columns and never
 * appears in these reports: Last/First/Callsign/Type/Phone/Email render
 * blank.
 *
 * Fix (api/reports.php): the six report cases -- license_expirations (two
 * queries), roster_snapshot, dmr_inventory, membership_due,
 * inactive_members, time_summary -- plus the shared member-name header
 * lookup now read COALESCE(NULLIF(named,''), legacy), preferring the named
 * column and falling back to legacy. Same pattern already shipped in
 * api/equipment.php:316-318 and api/external/v1/teams.php:56-58. A no-op on
 * generated-column installs (the named column there already resolves to
 * the legacy value); correct on plain-column installs either way (old rows
 * with only legacy data, or new rows with only named data).
 *
 * WHY THIS FILE REPRODUCES SQL TEXT INSTEAD OF DRIVING api/reports.php's
 * HTTP ENDPOINT DIRECTLY: this codebase already has an established,
 * documented answer for testing this exact file -- see
 * tests/test_phase132_reports.php's docblock ("WHY THIS FILE DUPLICATES SQL
 * TEXT") and tests/test_gh57_incident_report_responder_filter.php's
 * "Functional check against real rows" section: api/reports.php is a
 * monolithic, session/header-coupled endpoint whose report logic has never
 * been factored into a testable function, so a live-HTTP or full-file-
 * require harness would be a new testing pattern this codebase doesn't
 * otherwise use for this file. Every SQL string below is copied VERBATIM
 * from the real, fixed api/reports.php case block it tests (not
 * re-derived), run directly against real fixture rows this file creates
 * and tears down -- what's under test is the actual SQL shape the endpoint
 * runs, not a hand-waved re-derivation of "correct" output. Section 1
 * (structural) additionally greps the LIVE source file, so a future edit
 * that silently reverts the COALESCE shape is caught even if the copies
 * here fall out of sync. If a query changes in api/reports.php, update the
 * matching copy here (same maintenance discipline test_phase132_reports.php
 * already documents).
 *
 * TWO SCHEMA SHAPES (Eric asked this be covered if the harness can simulate
 * the distinction -- it can):
 *   - Section 2 uses a scratch table (`_test_gh95_plain`) with all seven
 *     relevant columns as PLAIN, independently writable columns -- the
 *     exact DDL shape sql/run_member_columns.php's addCol() produces
 *     (ALTER TABLE ADD COLUMN ... DEFAULT NULL, never GENERATED). This is
 *     the shape BOTH GH#95 reporters' installs actually have, and the one
 *     the bug report is about. A scratch table (not the live `member`
 *     table) is used because THIS dev database's own `member` table
 *     already has first_name/last_name/callsign/email/phone_cell defined
 *     as GENERATED VIRTUAL columns (confirmed via information_schema while
 *     building this fix -- see Section 3) -- MySQL refuses a direct write
 *     to a generated column, so there is no way to independently populate
 *     "named only, legacy empty" against the live table for those five
 *     columns on this install.
 *   - Section 3 uses the REAL, live `member` table, which already has
 *     first_name/last_name/callsign/email/phone_cell as GENERATED VIRTUAL
 *     columns on this dev database -- an authentic, already-in-production
 *     schema shape, not a synthetic stand-in (member_type_id and available
 *     are PLAIN even on this install, a genuine mixed shape). This proves
 *     the fix is a correct no-op for the generated columns: writing to
 *     field2/field1/field4/field6/field7 (the only way to populate a
 *     generated column) resolves identically through the new COALESCE as
 *     it did through the old bare read, and separately proves named-column
 *     preference still holds for member_type_id/available on this same
 *     table since those two are plain here too.
 *
 * available/field8 DOES NOT use the same COALESCE(NULLIF(named,''), legacy)
 * shape as every other field. A first attempt at this fix did, and CI's
 * genuinely-fresh install caught the real consequence within one push: the
 * PRE-EXISTING tests/test_reports_personnel_drilldown_links.php creates its
 * inactive_members fixture by writing field8='No' directly and never
 * touching `available` at all -- and `available` is a nullable ENUM with
 * DEFAULT 'Yes' (not DEFAULT NULL like every other named column), so a
 * plain INSERT that never mentions it takes that default. NULLIF cannot
 * distinguish "explicitly saved Yes" from "never touched, silently
 * defaulted to Yes" -- COALESCE(NULLIF(available,''), field8) therefore
 * ALWAYS resolved to 'Yes' for that fixture, masking its real, deliberately
 * written field8='No' and dropping it out of the inactive_members report
 * entirely. Not a narrow, hypothetical case -- ANY row where only one of
 * the two columns was ever explicitly written hits it, which turned out to
 * be the common case, not a rare one.
 *
 * The shipped fix instead treats 'No' as DOMINANT from either column:
 * `CASE WHEN available = 'No' OR field8 = 'No' THEN 'No' ELSE 'Yes' END`.
 * This works because BOTH columns share the identical NOT NULL DEFAULT
 * 'Yes' -- so an unwritten column and an explicit 'Yes' are the same
 * stored value on EITHER side, and a 'No' appearing anywhere can only be
 * the result of a deliberate write. Section 2's Row P2 (real legacy
 * field8='No', `available` never written) now correctly resolves 'No',
 * proving the corrected behavior rather than documenting a gap.
 *
 * Usage: php tests/test_gh95_personnel_report_named_columns.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/functions.php';

$pass = 0; $fail = 0; $skip = 0;
function g95ok(string $m): void { global $pass; $pass++; echo "  PASS: $m\n"; }
function g95bad(string $m, string $hint = ''): void {
    global $fail; $fail++; echo "  FAIL: $m" . ($hint !== '' ? " — $hint" : '') . "\n";
}
function g95chk($cond, string $m, string $hint = ''): void { $cond ? g95ok($m) : g95bad($m, $hint); }
function g95skip(string $m, string $why): void { global $skip; $skip++; echo "  SKIP: $m — $why\n"; }

echo "\n=== GH#95 — Personnel reports read named columns (with legacy fallback) ===\n";

$haveDb = false;
try { db_fetch_value('SELECT 1'); $haveDb = true; } catch (Throwable $e) {}
if (!$haveDb) {
    echo "\nSKIP: no database available — this test needs one\n";
    echo "\n=== {$pass} passed, {$fail} failed ===\n";
    exit($fail > 0 ? 1 : 0);
}

$prefix = $GLOBALS['db_prefix'] ?? '';
$src = (string) file_get_contents(__DIR__ . '/../api/reports.php');

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 1. Structural: the live source really carries the COALESCE fix --\n";
// ─────────────────────────────────────────────────────────────────────────

/** Isolate a case block's source text between two case labels (or a trailing marker). */
function g95_block(string $src, string $caseName, string $stopAt): string {
    $start = strpos($src, "case '{$caseName}':");
    if ($start === false) return '';
    $stopPos = strpos($src, $stopAt, $start);
    if ($stopPos === false) return substr($src, $start);
    return substr($src, $start, $stopPos - $start);
}

$blocks = [
    'license_expirations' => g95_block($src, 'license_expirations', "case 'roster_snapshot':"),
    'roster_snapshot'      => g95_block($src, 'roster_snapshot', "case 'dmr_inventory':"),
    'dmr_inventory'        => g95_block($src, 'dmr_inventory', "case 'membership_due':"),
    'membership_due'       => g95_block($src, 'membership_due', "case 'inactive_members':"),
    'inactive_members'     => g95_block($src, 'inactive_members', "case 'time_summary':"),
    'time_summary'         => g95_block($src, 'time_summary', "\nini_set('display_errors', \$prevDisplay);"),
];
foreach ($blocks as $name => $block) {
    g95chk($block !== '', "isolated the '{$name}' case block from api/reports.php", 'file structure may have changed');
}

// Every report reads first_name/last_name/callsign via named-then-legacy.
foreach (['license_expirations', 'roster_snapshot', 'dmr_inventory', 'membership_due', 'inactive_members', 'time_summary'] as $name) {
    $block = $blocks[$name];
    foreach ([
        'first_name' => 'field2',
        'last_name'  => 'field1',
        'callsign'   => 'field4',
    ] as $named => $legacy) {
        $pattern = '/COALESCE\(NULLIF\(m\.' . $named . ',\s*\'\'\),\s*m\.' . $legacy . '\)\s*AS\s*' . $named . '/';
        g95chk((bool) preg_match($pattern, $block),
            "{$name}: reads {$named} as COALESCE(NULLIF(m.{$named},''), m.{$legacy})");
    }
    // No bare, unwrapped legacy reference should remain outside a NULLIF
    // fallback -- every occurrence of `m.field1`/`m.field2`/`m.field4` in
    // the block must be the fallback argument of one of the three patterns
    // just asserted above (or, for inactive_members/roster_snapshot, the
    // extra field3/field6/field7/field8 patterns asserted below).
    foreach (['field1' => 'last_name', 'field2' => 'first_name', 'field4' => 'callsign'] as $legacy => $named) {
        $totalRefs = substr_count($block, "m.{$legacy}");
        $wrappedRefs = preg_match_all('/NULLIF\(m\.' . $named . ',\s*\'\'\),\s*m\.' . $legacy . '\)/', $block);
        g95chk($totalRefs === $wrappedRefs,
            "{$name}: every m.{$legacy} reference is inside the NULLIF fallback for {$named} (no bare leftover)",
            "found {$totalRefs} reference(s), {$wrappedRefs} wrapped");
    }
}

// roster_snapshot: the extra email/phone_cell/member_type_id fields use the
// named-then-legacy COALESCE shape; available/field8 deliberately does NOT
// (see the file docblock for why) -- checked separately below.
$rs = $blocks['roster_snapshot'];
foreach ([
    ['email',      'field6'],
    ['phone_cell', 'field7'],
] as [$named, $legacy]) {
    $pattern = '/COALESCE\(NULLIF\(m\.' . $named . ',\s*\'\'\),\s*m\.' . $legacy . '\)\s*AS\s*' . $named . '/';
    g95chk((bool) preg_match($pattern, $rs), "roster_snapshot: reads {$named} as COALESCE(NULLIF(m.{$named},''), m.{$legacy})");
}
g95chk((bool) preg_match('/mt\.id\s*=\s*COALESCE\(NULLIF\(m\.member_type_id,\s*0\),\s*m\.field3\)/', $rs),
    'roster_snapshot: member_types join resolves via COALESCE(NULLIF(m.member_type_id, 0), m.field3) (id column, 0 not \'\')');
g95chk(strpos($rs, 'ORDER BY last_name, first_name') !== false,
    'roster_snapshot: ORDER BY uses the resolved alias, not the bare legacy columns');
g95chk((bool) preg_match(
    '/CASE\s+WHEN\s+m\.available\s*=\s*\'No\'\s+OR\s+m\.field8\s*=\s*\'No\'\s+THEN\s+\'No\'\s+ELSE\s+\'Yes\'\s+END\s+AS\s+available/',
    $rs
), 'roster_snapshot: available uses the dominant-\'No\' shape (CASE WHEN available=\'No\' OR field8=\'No\'), not a COALESCE fallback');

// inactive_members: available/field8 in SELECT AND in the WHERE-clause filter.
$im = $blocks['inactive_members'];
g95chk((bool) preg_match(
    '/CASE\s+WHEN\s+m\.available\s*=\s*\'No\'\s+OR\s+m\.field8\s*=\s*\'No\'\s+THEN\s+\'No\'\s+ELSE\s+\'Yes\'\s+END\s+AS\s+available/',
    $im
), 'inactive_members: SELECT reads available via the dominant-\'No\' CASE WHEN shape');
g95chk((bool) preg_match('/m\.available\s*=\s*\'No\'\s+OR\s+m\.field8\s*=\s*\'No\'/', $im),
    'inactive_members: WHERE-clause filter matches on available=\'No\' OR field8=\'No\' (dominant-No), not a bare m.field8 = \'No\' alone',
    'the SELECT alias cannot be referenced in a WHERE clause, so this must be its own comparison, matching the SELECT');

// The shared header lookup (member-name label when filtering by member_id).
$headerPos = strpos($src, 'if ($isPersonnel && $member_id > 0)');
$headerBlock = $headerPos !== false ? substr($src, $headerPos, 900) : '';
g95chk(
    (bool) preg_match('/COALESCE\(NULLIF\(first_name,\s*\'\'\),\s*field2\)/', $headerBlock)
    && (bool) preg_match('/COALESCE\(NULLIF\(last_name,\s*\'\'\),\s*field1\)/', $headerBlock),
    'the shared personnel-report header (member name label) also reads named-then-legacy'
);

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 2. Functional: scratch table with PLAIN (non-generated) named columns --\n";
echo "      (the exact shape sql/run_member_columns.php produces, and both\n";
echo "       GH#95 reporters' installs actually have)\n";
// ─────────────────────────────────────────────────────────────────────────

$scratch = $prefix . '_test_gh95_plain';
$typeA = null; $typeB = null;
$idP1 = null; $idP2 = null; $idP3 = null;

function g95_cleanup(string $prefix, string $scratch, ?int $typeA, ?int $typeB): void {
    try { db_query("DROP TABLE IF EXISTS `{$scratch}`"); } catch (Throwable $e) {}
    if ($typeA) { try { db_query("DELETE FROM `{$prefix}member_types` WHERE id = ?", [$typeA]); } catch (Throwable $e) {} }
    if ($typeB) { try { db_query("DELETE FROM `{$prefix}member_types` WHERE id = ?", [$typeB]); } catch (Throwable $e) {} }
}

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
            `field8` ENUM('Yes','No') NOT NULL DEFAULT 'Yes',
            `first_name` VARCHAR(28) DEFAULT NULL,
            `last_name` VARCHAR(28) DEFAULT NULL,
            `callsign` VARCHAR(16) DEFAULT NULL,
            `email` VARCHAR(48) DEFAULT NULL,
            `phone_cell` VARCHAR(20) DEFAULT NULL,
            `member_type_id` INT DEFAULT NULL,
            `available` ENUM('Yes','No') DEFAULT 'Yes',
            `notes` TEXT DEFAULT NULL,
            `membership_due` DATE DEFAULT NULL,
            `deleted_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $scratchReady = true;
} catch (Throwable $e) {
    g95skip('scratch plain-column table', 'could not CREATE TABLE: ' . $e->getMessage());
}

register_shutdown_function(function () use (&$prefix, &$scratch, &$typeA, &$typeB, &$idP1) {
    if ($idP1) { try { db_query("DELETE FROM `{$prefix}member_callsigns` WHERE member_id = ?", [$idP1]); } catch (Throwable $e) {} }
    g95_cleanup($prefix, $scratch, $typeA, $typeB);
});

/**
 * Self-healing member_types insert (CLAUDE.md "Defensive Database Patterns"
 * §1) — this dev database's member_types table carries legacy _on/_from/_by
 * columns (NOT NULL, no default, an artifact of the original v3 import)
 * that sql/run_member_columns.php's own CREATE TABLE IF NOT EXISTS doesn't
 * define, so a fresh install's member_types table won't have them. Discover
 * whatever NOT-NULL/no-default columns actually exist beyond the ones we
 * supply and fill them with a type-appropriate value, rather than hardcode
 * a shape that only matches this one database.
 */
function g95_member_types_insert(string $prefix, string $name): int {
    $table = trim($prefix . 'member_types', '` ');
    $cols = ['name' => $name, 'description' => 'gh95 test fixture', 'sort_order' => 999];
    try {
        $extra = db_fetch_all(
            "SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE '%auto_increment%'
                AND COLUMN_NAME NOT IN ('name', 'description', 'sort_order')",
            [$table]
        );
        foreach ($extra as $col) {
            $dtype = strtolower($col['DATA_TYPE']);
            if (in_array($dtype, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint', 'decimal', 'float', 'double'], true)) {
                $cols[$col['COLUMN_NAME']] = 0;
            } elseif (in_array($dtype, ['datetime', 'timestamp', 'date'], true)) {
                $cols[$col['COLUMN_NAME']] = date('Y-m-d H:i:s');
            } else {
                $cols[$col['COLUMN_NAME']] = '';
            }
        }
    } catch (Throwable $e) { /* best effort — fall through with the base columns */ }

    $colNames = array_keys($cols);
    $placeholders = implode(',', array_fill(0, count($colNames), '?'));
    $colList = implode(', ', array_map(fn($c) => "`{$c}`", $colNames));
    db_query("INSERT INTO `{$prefix}member_types` ({$colList}) VALUES ({$placeholders})", array_values($cols));
    return (int) db_insert_id();
}

if ($scratchReady) {
    try {
        $typeA = g95_member_types_insert($prefix, 'GH95TypeA');
        $typeB = g95_member_types_insert($prefix, 'GH95TypeB');
        g95chk($typeA > 0 && $typeB > 0, 'fixture: two member_types rows created', "A={$typeA} B={$typeB}");
    } catch (Throwable $e) {
        g95bad('creating member_types fixtures', $e->getMessage());
    }

    try {
        // Row P1 "NamedOnly" -- the reported bug: a member created/edited
        // through the NewUI roster on a plain-column install. Legacy
        // field1/field2/field3/field4/field6/field7 left at their table
        // defaults (NULL/NULL/0/NULL/NULL/NULL); field8 stays at ITS OWN
        // default 'Yes' (meaningless here, never real data).
        db_query(
            "INSERT INTO `{$scratch}` (first_name, last_name, callsign, email, phone_cell, member_type_id, available, notes, membership_due)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ['Persona', 'NamedOnly95', 'N0GH95A', 'namedonly@example.test', '6125550001', $typeA, 'No',
             'DMR ID: 11111 (N0GH95A)', date('Y-m-d', strtotime('+10 days'))]
        );
        $idP1 = (int) db_insert_id();

        // Row P2 "LegacyOnly" -- old, never-migrated data: real legacy
        // field1-8 values, named columns left NULL (never touched by the
        // NewUI roster). available is deliberately left unset here (its
        // own DEFAULT 'Yes' applies) to reproduce the documented ENUM-
        // default edge case against a real field8='No' row.
        db_query(
            "INSERT INTO `{$scratch}` (field1, field2, field3, field4, field6, field7, field8, notes, membership_due)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ['LegacyLast95', 'LegacyFirst', $typeB, 'N0GH95B', 'legacyonly@example.test', '6125550002', 'No',
             'DMR ID: 22222 (N0GH95B)', date('Y-m-d', strtotime('+20 days'))]
        );
        $idP2 = (int) db_insert_id();

        // Row P3 "BothPopulated" -- the generated-column install's real
        // shape reproduced on the plain table: named and legacy carry the
        // SAME value (as a generated column mechanically would), proving
        // no behavior change for already-consistent rows.
        db_query(
            "INSERT INTO `{$scratch}` (field1, field2, field3, field4, field6, field7, field8,
                                        first_name, last_name, callsign, email, phone_cell, member_type_id, available)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            ['BothLast95', 'BothFirst', $typeA, 'N0GH95C', 'both@example.test', '6125550003', 'Yes',
             'BothFirst', 'BothLast95', 'N0GH95C', 'both@example.test', '6125550003', $typeA, 'Yes']
        );
        $idP3 = (int) db_insert_id();

        g95chk($idP1 > 0 && $idP2 > 0 && $idP3 > 0, 'fixture: three scratch-table rows created (NamedOnly, LegacyOnly, BothPopulated)',
            "P1={$idP1} P2={$idP2} P3={$idP3}");
    } catch (Throwable $e) {
        g95bad('creating scratch-table fixture rows', $e->getMessage());
    }

    // ---- roster_snapshot-shaped query, copied from the fixed case (member_status join omitted -- not part of the bug) ----
    // available/field8 uses the dominant-'No' CASE WHEN, not COALESCE -- see
    // the file docblock for why (both columns share the same non-NULL
    // DEFAULT 'Yes', so COALESCE-only can't tell "never written" from
    // "explicitly Yes").
    $rosterSql =
        "SELECT m.id,
                COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                COALESCE(NULLIF(m.email,      ''), m.field6) AS email,
                COALESCE(NULLIF(m.phone_cell, ''), m.field7) AS phone_cell,
                CASE WHEN m.available = 'No' OR m.field8 = 'No' THEN 'No' ELSE 'Yes' END AS available,
                mt.name AS type_name
           FROM `{$scratch}` m
           LEFT JOIN `{$prefix}member_types` mt ON mt.id = COALESCE(NULLIF(m.member_type_id, 0), m.field3)
          WHERE m.id = ?";

    if ($idP1) {
        $r = db_fetch_all($rosterSql, [$idP1]);
        $row = $r[0] ?? [];
        g95chk(($row['first_name'] ?? null) === 'Persona' && ($row['last_name'] ?? null) === 'NamedOnly95'
            && ($row['callsign'] ?? null) === 'N0GH95A' && ($row['email'] ?? null) === 'namedonly@example.test'
            && ($row['phone_cell'] ?? null) === '6125550001' && ($row['type_name'] ?? null) === 'GH95TypeA',
            'roster_snapshot [plain, NamedOnly]: all named-column fields resolve correctly (the reported bug, now fixed)',
            var_export($row, true));
        g95chk(($row['available'] ?? null) === 'No',
            'roster_snapshot [plain, NamedOnly]: available correctly resolves the deliberately-set named value',
            var_export($row['available'] ?? null, true));
    }
    if ($idP2) {
        $r = db_fetch_all($rosterSql, [$idP2]);
        $row = $r[0] ?? [];
        g95chk(($row['first_name'] ?? null) === 'LegacyFirst' && ($row['last_name'] ?? null) === 'LegacyLast95'
            && ($row['callsign'] ?? null) === 'N0GH95B' && ($row['email'] ?? null) === 'legacyonly@example.test'
            && ($row['phone_cell'] ?? null) === '6125550002' && ($row['type_name'] ?? null) === 'GH95TypeB',
            'roster_snapshot [plain, LegacyOnly]: old pre-fix data still resolves via legacy fallback (no regression)',
            var_export($row, true));
        // This is the exact shape CI's genuinely-fresh install caught a
        // COALESCE-only first attempt getting wrong: field8 is REALLY 'No'
        // (deliberately written); available was NEVER written and only
        // carries its own column default 'Yes'. The dominant-'No' CASE WHEN
        // correctly resolves 'No' here -- proving the corrected fix, not
        // documenting a remaining gap.
        g95chk(($row['available'] ?? null) === 'No',
            'roster_snapshot [plain, LegacyOnly]: available correctly resolves \'No\' from the real legacy '
            . 'field8, not masked by the unwritten named column\'s own default',
            var_export($row['available'] ?? null, true));
    }
    if ($idP3) {
        $r = db_fetch_all($rosterSql, [$idP3]);
        $row = $r[0] ?? [];
        g95chk(($row['first_name'] ?? null) === 'BothFirst' && ($row['last_name'] ?? null) === 'BothLast95'
            && ($row['callsign'] ?? null) === 'N0GH95C' && ($row['available'] ?? null) === 'Yes',
            'roster_snapshot [plain, BothPopulated]: already-consistent rows are unaffected',
            var_export($row, true));
    }

    // ---- inactive_members-shaped WHERE-clause filter, copied from the fixed case ----
    if ($idP1 && $idP2 && $idP3) {
        $filterSql = "SELECT m.id FROM `{$scratch}` m
                       WHERE m.id IN (?, ?, ?)
                         AND (m.available = 'No' OR m.field8 = 'No')";
        $matched = array_map(fn($r) => (int) $r['id'], db_fetch_all($filterSql, [$idP1, $idP2, $idP3]));
        g95chk(in_array($idP1, $matched, true),
            'inactive_members [plain, NamedOnly available=No]: WHERE-clause filter correctly catches it (the reported bug\'s filter half)',
            'matched: ' . implode(',', $matched));
        g95chk(!in_array($idP3, $matched, true),
            'inactive_members [plain, BothPopulated available=Yes]: WHERE-clause filter correctly excludes it',
            'matched: ' . implode(',', $matched));
        // P2 is the exact fixture shape that broke the PRE-EXISTING
        // tests/test_reports_personnel_drilldown_links.php on CI's fresh
        // install under a COALESCE-only first attempt (real field8='No',
        // available never written) -- the dominant-'No' filter now
        // correctly catches it too, closing that regression.
        g95chk(in_array($idP2, $matched, true),
            'inactive_members [plain, LegacyOnly field8=No]: WHERE-clause filter correctly catches it via '
            . 'the real legacy field8 signal, unmasked by available\'s own unwritten default',
            'matched: ' . implode(',', $matched));
    }

    // ---- dmr_inventory-shaped query (simple 3-field pattern), copied from the fixed case ----
    $dmrSql = "SELECT m.id,
                      COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                      COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                      COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                      m.notes
                 FROM `{$scratch}` m
                WHERE m.id = ? AND m.notes LIKE '%DMR ID%'";
    if ($idP1) {
        $row = db_fetch_all($dmrSql, [$idP1])[0] ?? [];
        g95chk(($row['last_name'] ?? null) === 'NamedOnly95' && ($row['callsign'] ?? null) === 'N0GH95A',
            'dmr_inventory [plain, NamedOnly]: name/callsign resolve correctly', var_export($row, true));
    }
    if ($idP2) {
        $row = db_fetch_all($dmrSql, [$idP2])[0] ?? [];
        g95chk(($row['last_name'] ?? null) === 'LegacyLast95' && ($row['callsign'] ?? null) === 'N0GH95B',
            'dmr_inventory [plain, LegacyOnly]: legacy fallback still resolves correctly', var_export($row, true));
    }

    // ---- membership_due-shaped query, copied from the fixed case ----
    $dueSql = "SELECT m.id,
                      COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                      COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                      COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                      m.membership_due
                 FROM `{$scratch}` m
                WHERE m.id = ? AND m.membership_due IS NOT NULL";
    if ($idP1) {
        $row = db_fetch_all($dueSql, [$idP1])[0] ?? [];
        g95chk(($row['first_name'] ?? null) === 'Persona', 'membership_due [plain, NamedOnly]: name resolves correctly', var_export($row, true));
    }
    if ($idP2) {
        $row = db_fetch_all($dueSql, [$idP2])[0] ?? [];
        g95chk(($row['first_name'] ?? null) === 'LegacyFirst', 'membership_due [plain, LegacyOnly]: legacy fallback resolves correctly', var_export($row, true));
    }

    // ---- Regression guard: prove the OLD (pre-fix) query text really was broken ----
    // Same discipline as tests/test_gh51_report_table_sort.php's "demonstrate
    // the OLD comparator actually was broken" section.
    if ($idP1) {
        $oldSql = "SELECT m.field2 AS first_name, m.field1 AS last_name, m.field4 AS callsign FROM `{$scratch}` m WHERE m.id = ?";
        $row = db_fetch_all($oldSql, [$idP1])[0] ?? [];
        g95chk(($row['first_name'] ?? null) === null && ($row['last_name'] ?? null) === null && ($row['callsign'] ?? null) === null,
            'REGRESSION GUARD: the OLD pre-fix query (bare m.field2/m.field1/m.field4) really did return NULL '
            . 'for the NamedOnly row -- proves the reported bug was real, not hypothetical',
            var_export($row, true));
    }
}

// ─────────────────────────────────────────────────────────────────────────
echo "\n-- 3. Functional: the REAL member table (generated-column shape on this install) --\n";
// ─────────────────────────────────────────────────────────────────────────

$genCols = [];
try {
    $genCols = array_column(db_fetch_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
            AND GENERATION_EXPRESSION IS NOT NULL AND GENERATION_EXPRESSION != ''",
        [trim($prefix . 'member', '` ')]
    ), 'COLUMN_NAME');
} catch (Throwable $e) {}
echo '  (this install\'s generated named columns: ' . (empty($genCols) ? '(none -- fully plain install)' : implode(', ', $genCols)) . ")\n";

$realId = null;
register_shutdown_function(function () use (&$prefix, &$realId) {
    if (!$realId) return;
    try {
        db_query("DELETE FROM `{$prefix}member_callsigns`    WHERE member_id = ?", [$realId]);
        db_query("DELETE FROM `{$prefix}member_time_entries` WHERE member_id = ?", [$realId]);
        db_query("DELETE FROM `{$prefix}member`              WHERE id = ?",        [$realId]);
    } catch (Throwable $e) {}
});

try {
    // Written via field1/field2/field3/field4/field6/field7/field8 -- the
    // ONLY way to populate a GENERATED column (a direct write to
    // first_name/last_name/callsign/email/phone_cell on THIS install would
    // throw "The value specified for generated column ... is not allowed").
    // member_type_id/available are written directly -- confirmed PLAIN on
    // this install even though the name/contact columns are generated.
    db_query(
        "INSERT INTO `{$prefix}member` (field1, field2, field3, field4, field6, field7, field8, member_type_id, available, notes, membership_due)
         VALUES (?, ?, 0, ?, ?, ?, 'Yes', NULL, ?, ?, ?)",
        ['TableFixture95', 'Real', 'N0GH95REAL', 'realtable@example.test', '6125559999', 'No',
         'DMR ID: 99999 (N0GH95REAL)', date('Y-m-d', strtotime('+30 days'))]
    );
    $realId = (int) db_insert_id();
    g95chk($realId > 0, 'fixture: a real member row created (generated-column shape)', "id={$realId}");
} catch (Throwable $e) {
    g95bad('creating real member fixture', $e->getMessage());
}

if ($realId) {
    try {
        db_query(
            "INSERT INTO `{$prefix}member_callsigns` (member_id, callsign, license_type, expiry_date)
             VALUES (?, ?, 'amateur', ?)",
            [$realId, 'N0GH95REAL', date('Y-m-d', strtotime('+15 days'))]
        );
        // hours is a GENERATED column on this install
        // (TIMESTAMPDIFF(MINUTE, started_at, ended_at) / 60.0) -- a direct
        // write is rejected. -2h to -30m is a 90-minute span = 1.5 hours,
        // computed automatically; matches the total_hours assertion below.
        db_query(
            "INSERT INTO `{$prefix}member_time_entries` (member_id, started_at, ended_at, activity_type, status)
             VALUES (?, ?, ?, 'Drill', 'self_reported')",
            [$realId, date('Y-m-d H:i:s', strtotime('-2 hours')), date('Y-m-d H:i:s', strtotime('-30 minutes'))]
        );
    } catch (Throwable $e) {
        g95bad('creating real member_callsigns/member_time_entries fixtures', $e->getMessage());
    }

    // roster_snapshot-shaped query against the REAL table.
    $rosterSqlReal = "SELECT m.id,
                              COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                              COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                              COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                              COALESCE(NULLIF(m.email,      ''), m.field6) AS email,
                              COALESCE(NULLIF(m.phone_cell, ''), m.field7) AS phone_cell,
                              CASE WHEN m.available = 'No' OR m.field8 = 'No' THEN 'No' ELSE 'Yes' END AS available
                         FROM `{$prefix}member` m
                        WHERE m.id = ?";
    $row = db_fetch_all($rosterSqlReal, [$realId])[0] ?? [];
    g95chk(($row['first_name'] ?? null) === 'Real' && ($row['last_name'] ?? null) === 'TableFixture95'
        && ($row['callsign'] ?? null) === 'N0GH95REAL' && ($row['email'] ?? null) === 'realtable@example.test'
        && ($row['phone_cell'] ?? null) === '6125559999',
        'roster_snapshot [real table, generated named columns]: resolves correctly (no-op path, no regression)',
        var_export($row, true));
    g95chk(($row['available'] ?? null) === 'No',
        'roster_snapshot [real table]: available (plain even on this install) prefers the named value',
        var_export($row['available'] ?? null, true));

    // dmr_inventory-shaped query against the REAL table.
    $dmrSqlReal = "SELECT m.id,
                          COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                          COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                          COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                          m.notes
                     FROM `{$prefix}member` m
                    WHERE m.id = ? AND m.notes LIKE '%DMR ID%'";
    $row = db_fetch_all($dmrSqlReal, [$realId])[0] ?? [];
    g95chk(($row['last_name'] ?? null) === 'TableFixture95' && ($row['callsign'] ?? null) === 'N0GH95REAL',
        'dmr_inventory [real table]: resolves correctly', var_export($row, true));

    // license_expirations-shaped query (FCC half) against the REAL table.
    $fccSqlReal = "SELECT m.id AS member_id,
                          COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                          COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                          COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                          mc.callsign AS identifier, mc.expiry_date
                     FROM `{$prefix}member_callsigns` mc
                     JOIN `{$prefix}member` m ON mc.member_id = m.id
                    WHERE m.id = ?";
    $row = db_fetch_all($fccSqlReal, [$realId])[0] ?? [];
    g95chk(($row['last_name'] ?? null) === 'TableFixture95' && ($row['identifier'] ?? null) === 'N0GH95REAL',
        'license_expirations [real table, FCC query]: resolves correctly', var_export($row, true));

    // time_summary-shaped query against the REAL table.
    $timeSqlReal = "SELECT m.id AS member_id,
                           COALESCE(NULLIF(m.first_name, ''), m.field2) AS first_name,
                           COALESCE(NULLIF(m.last_name,  ''), m.field1) AS last_name,
                           COALESCE(NULLIF(m.callsign,   ''), m.field4) AS callsign,
                           COUNT(te.id) AS entry_count,
                           COALESCE(SUM(te.hours), 0) AS total_hours
                      FROM `{$prefix}member` m
                      LEFT JOIN `{$prefix}member_time_entries` te ON te.member_id = m.id
                     WHERE m.id = ?
                     GROUP BY m.id
                    HAVING entry_count > 0";
    $row = db_fetch_all($timeSqlReal, [$realId])[0] ?? [];
    g95chk(($row['last_name'] ?? null) === 'TableFixture95' && (int) ($row['entry_count'] ?? 0) === 1
        && abs((float) ($row['total_hours'] ?? 0) - 1.5) < 0.001,
        'time_summary [real table]: resolves correctly', var_export($row, true));
}

echo "\n=== {$pass} passed, {$fail} failed" . ($skip > 0 ? ", {$skip} skipped" : '') . " ===\n";
exit($fail > 0 ? 1 : 0);
