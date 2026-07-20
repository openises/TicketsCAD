<?php
/**
 * Pre-release fix #21 — Time tracking + personnel reports.
 *
 * Static + DB checks for:
 *   • Schema (member_time_entries, time_activity_types, hours virtual col)
 *   • API (api/time-entries.php) — auth gate, CSRF on writes, ownership
 *     enforcement, IDOR guard on incident_id, validation, status transitions
 *   • Reports (api/reports.php) — six new personnel report types are in the
 *     allow-list, gated by admin level, and produce normalized output
 *   • UI (roster.php + reports.php + roster.js) — Time Log card, modal,
 *     personnel report buttons, JS event bindings
 */

declare(strict_types=1);

$base = realpath(__DIR__ . '/..');
require_once $base . '/config.php';

echo "=== Time Tracking + Personnel Reports (pre-release #21) ===\n\n";
$pass = 0; $fail = 0;
function ok(string $name): void  { global $pass; echo "[PASS] $name\n"; $pass++; }
function bad(string $name, string $why = ''): void {
    global $fail; echo "[FAIL] $name" . ($why ? " — $why" : '') . "\n"; $fail++;
}

function code_only(string $src): string {
    $src = preg_replace('!//[^\n]*!', '', $src);
    $src = preg_replace('!/\*.*?\*/!s', '', $src);
    return $src;
}

// ─────────────────────────────────────────────────────────────────────────
// Schema checks
// ─────────────────────────────────────────────────────────────────────────

$prefix = $GLOBALS['db_prefix'] ?? '';

function table_has_col(string $tbl, string $col): bool {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$prefix . $tbl, $col]
        );
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

function table_exists(string $tbl): bool {
    global $prefix;
    try {
        $row = db_fetch_one(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$prefix . $tbl]
        );
        return !empty($row);
    } catch (Throwable $e) {
        return false;
    }
}

if (table_exists('time_activity_types'))   { ok('time_activity_types table exists'); } else { bad('time_activity_types table exists'); }
if (table_exists('member_time_entries'))   { ok('member_time_entries table exists'); } else { bad('member_time_entries table exists'); }

$cols = ['member_id','started_at','ended_at','activity_type','incident_id','notes','status','submitted_by','approved_by','approved_at'];
foreach ($cols as $c) {
    if (table_has_col('member_time_entries', $c)) { ok("member_time_entries.$c"); } else { bad("member_time_entries.$c missing"); }
}

if (table_has_col('member_time_entries', 'hours')) {
    // Hours must be a virtual generated column derived from started_at/ended_at
    try {
        $row = db_fetch_one(
            "SELECT EXTRA, GENERATION_EXPRESSION FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'hours'",
            [$prefix . 'member_time_entries']
        );
        if ($row && stripos((string) ($row['EXTRA'] ?? ''), 'VIRTUAL GENERATED') !== false) {
            ok('member_time_entries.hours is VIRTUAL GENERATED');
        } else {
            bad('member_time_entries.hours generation', 'EXTRA=' . ($row['EXTRA'] ?? 'null'));
        }
    } catch (Throwable $e) {
        bad('hours column metadata read', $e->getMessage());
    }
} else {
    bad('member_time_entries.hours missing');
}

// Activity types seeded
try {
    $count = (int) db_fetch_value("SELECT COUNT(*) FROM `{$prefix}time_activity_types`");
    if ($count >= 9) { ok("time_activity_types seeded ($count rows)"); }
    else            { bad("time_activity_types seeded", "only $count rows"); }
} catch (Throwable $e) {
    bad('time_activity_types seeded', $e->getMessage());
}

// ─────────────────────────────────────────────────────────────────────────
// API: api/time-entries.php static checks
// ─────────────────────────────────────────────────────────────────────────

$apiSrc = code_only(file_get_contents($base . '/api/time-entries.php'));

if (strpos($apiSrc, "require_once __DIR__ . '/auth.php'") !== false) {
    ok('time-entries.php requires auth');
} else {
    bad('time-entries.php requires auth');
}

if (strpos($apiSrc, "csrf_verify") !== false) {
    ok('time-entries.php verifies CSRF on writes');
} else {
    bad('time-entries.php verifies CSRF on writes');
}

// Display errors must be suppressed at top of every API endpoint
if (preg_match("/ini_set\\(\\s*'display_errors'\\s*,\\s*'0'\\s*\\)/", $apiSrc)) {
    ok('time-entries.php suppresses display_errors');
} else {
    bad('time-entries.php suppresses display_errors');
}

// IDOR check: incident_id must be access-checked, not just trusted
if (strpos($apiSrc, "user_can_access_entity('incident'") !== false) {
    ok('time-entries.php IDOR-checks incident_id');
} else {
    bad('time-entries.php IDOR-checks incident_id');
}

// Modify gate (te_can_modify) — admin always; owner only while self_reported
if (preg_match('/function\s+te_can_modify[\s\S]{0,400}self_reported/', $apiSrc)) {
    ok('time-entries.php enforces ownership + self_reported lock');
} else {
    bad('time-entries.php enforces ownership + self_reported lock');
}

// Activity type validation against lookup
if (strpos($apiSrc, 'time_activity_types') !== false &&
    preg_match('/function\s+te_validate_activity/', $apiSrc)) {
    ok('time-entries.php validates activity against lookup table');
} else {
    bad('time-entries.php validates activity against lookup table');
}

// Approve / reject paths exist and require admin
if (strpos($apiSrc, "'approve'") !== false && strpos($apiSrc, "'reject'") !== false) {
    ok('time-entries.php exposes approve and reject actions');
} else {
    bad('time-entries.php exposes approve and reject actions');
}

// Summary endpoint
if (strpos($apiSrc, 'summary=1') !== false || strpos($apiSrc, "['summary']") !== false ||
    preg_match("/case\s+'summary'/", $apiSrc) ||
    strpos($apiSrc, "isset(\$_GET['summary'])") !== false) {
    ok('time-entries.php provides summary endpoint');
} else {
    bad('time-entries.php provides summary endpoint');
}

// 30-day creation window guard (prevents back-dated abuse)
if (preg_match('/30\s*\*\s*86400|30 days|2592000/', $apiSrc)) {
    ok('time-entries.php caps back-dated entries (30-day window)');
} else {
    bad('time-entries.php caps back-dated entries (30-day window)');
}

// ─────────────────────────────────────────────────────────────────────────
// Reports: api/reports.php
// ─────────────────────────────────────────────────────────────────────────

$reportsSrc = code_only(file_get_contents($base . '/api/reports.php'));

$personnel = ['license_expirations','roster_snapshot','dmr_inventory','membership_due','inactive_members','time_summary'];
foreach ($personnel as $r) {
    if (strpos($reportsSrc, "case '$r':") !== false) { ok("reports.php handles '$r'"); }
    else                                              { bad("reports.php handles '$r'"); }
}

// Allow-list contains all six new types
$inAllowlist = true;
foreach ($personnel as $r) {
    if (!preg_match("/'$r'/", $reportsSrc)) { $inAllowlist = false; break; }
}
if ($inAllowlist) { ok('reports.php $valid_reports allow-list updated'); }
else              { bad('reports.php $valid_reports allow-list updated'); }

// Admin gate for personnel reports
if (preg_match('/personnel\s+report.*admin/i', $reportsSrc) ||
    strpos($reportsSrc, 'isPersonnel') !== false) {
    ok('reports.php gates personnel reports behind admin');
} else {
    bad('reports.php gates personnel reports behind admin');
}

// Output normalization (struct columns + assoc rows → flat strings + arrays)
if (preg_match('/is_array\(\$columns\[0\]\s*\?\?\s*null\)/', $reportsSrc) &&
    strpos($reportsSrc, '$colKeys') !== false &&
    strpos($reportsSrc, '$colLabels') !== false) {
    ok('reports.php normalizes structured columns + rows for JSON contract');
} else {
    bad('reports.php normalizes structured columns + rows for JSON contract');
}

// ─────────────────────────────────────────────────────────────────────────
// UI: reports.php (page + JS) and roster.php (page + JS)
// ─────────────────────────────────────────────────────────────────────────

$reportsPage = file_get_contents($base . '/reports.php');
foreach ($personnel as $r) {
    if (strpos($reportsPage, "data-report=\"$r\"") !== false) {
        ok("reports.php UI exposes button for '$r'");
    } else {
        bad("reports.php UI exposes button for '$r'");
    }
}

$reportsJs = code_only(file_get_contents($base . '/assets/js/reports.js'));
if (strpos($reportsJs, "personnelReportBtns") !== false) {
    ok('reports.js binds personnelReportBtns group');
} else {
    bad('reports.js binds personnelReportBtns group');
}
if (strpos($reportsJs, 'isPersonnelReport') !== false) {
    ok('reports.js skips incident-stats fetch for personnel reports');
} else {
    bad('reports.js skips incident-stats fetch for personnel reports');
}
if (strpos($reportsJs, 'personnelNoPeriod') !== false) {
    ok('reports.js hides period selector for snapshot-style reports');
} else {
    bad('reports.js hides period selector for snapshot-style reports');
}

$rosterPage = file_get_contents($base . '/roster.php');
if (strpos($rosterPage, 'collapseDetailTimeLog') !== false &&
    strpos($rosterPage, 'btnLogTime') !== false) {
    ok('roster.php has Time Log card + Log button');
} else {
    bad('roster.php has Time Log card + Log button');
}
if (strpos($rosterPage, 'logTimeModal') !== false &&
    strpos($rosterPage, 'logTimeStart') !== false &&
    strpos($rosterPage, 'logTimeActivity') !== false) {
    ok('roster.php Log Time modal has required fields');
} else {
    bad('roster.php Log Time modal has required fields');
}

$rosterJs = code_only(file_get_contents($base . '/assets/js/roster.js'));
if (strpos($rosterJs, 'loadTimeLog') !== false &&
    strpos($rosterJs, 'saveTimeEntry') !== false &&
    strpos($rosterJs, 'deleteTimeEntry') !== false) {
    ok('roster.js wires loadTimeLog, save, delete');
} else {
    bad('roster.js wires loadTimeLog, save, delete');
}
if (strpos($rosterJs, "api/time-entries.php") !== false) {
    ok('roster.js calls api/time-entries.php');
} else {
    bad('roster.js calls api/time-entries.php');
}

// ─────────────────────────────────────────────────────────────────────────
// Live round-trip — only if a member exists. Skip cleanly otherwise.
// ─────────────────────────────────────────────────────────────────────────

try {
    $mid = (int) (db_fetch_value("SELECT id FROM `{$prefix}member` WHERE deleted_at IS NULL ORDER BY id LIMIT 1") ?: 0);
    if ($mid > 0) {
        $start = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $end   = date('Y-m-d H:i:s', strtotime('-1 hour'));
        db_query(
            "INSERT INTO `{$prefix}member_time_entries` (member_id, started_at, ended_at, activity_type, status, submitted_by)
             VALUES (?, ?, ?, ?, 'self_reported', ?)",
            [$mid, $start, $end, 'Drill', $mid]
        );
        $newId = (int) db_insert_id();
        $row = db_fetch_one(
            "SELECT hours FROM `{$prefix}member_time_entries` WHERE id = ?",
            [$newId]
        );
        $hours = (float) ($row['hours'] ?? 0);
        if (abs($hours - 1.0) < 0.05) {
            ok('hours virtual column computes (~1.0 for 1-hour entry)');
        } else {
            bad('hours virtual column', "got $hours");
        }
        db_query("DELETE FROM `{$prefix}member_time_entries` WHERE id = ?", [$newId]);
    } else {
        echo "[SKIP] live round-trip — no member rows present\n";
    }
} catch (Throwable $e) {
    bad('live round-trip', $e->getMessage());
}

echo "\n=== Result: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
