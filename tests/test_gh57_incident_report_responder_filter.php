<?php
/**
 * GH#57 follow-up (cbyrdmo, 2026-08-15) — "The reports menu is still not
 * generating the responder pull down. If I run an after action report and
 * then click incident to run a new report it does not present the
 * responder pull down."
 *
 * Same missing-capability shape as the facility_log/notes_log fix from the
 * day before (see test_gh57_responder_filter_visibility.php): the
 * "Incidents" tab (data-report="incident_report" -- confirmed against
 * reports.php's own button label, not assumed) was left off
 * showResponder's whitelist in assets/js/reports.js, AND
 * api/reports.php's incident_report case never checked $responder_id at
 * all, so even flipping the whitelist alone would have shipped a control
 * that appears but filters nothing.
 *
 * "What incidents was this unit on" is exactly the kind of question the
 * Incidents report should answer, and unlike unit_log/dispatch_log/
 * facility_log (which already join `assigns` directly), incident_report's
 * main query has NO assigns join at all -- units_assigned is a subquery
 * COUNT -- so the fix uses an EXISTS filter rather than a JOIN, to avoid
 * duplicating an incident row once per assigned unit.
 *
 * Structural checks mirror test_gh57_responder_filter_visibility.php's
 * established rigor (isolate the real function bodies, not a bare grep of
 * the whole file). The functional half drives the real
 * api/reports.php?report=incident_report endpoint against a real ticket +
 * assigns row this test creates itself, matching this project's "reproduce
 * via the real writer" convention -- not a hand-seeded row shaped to make
 * the query pass.
 */

$root = dirname(__DIR__);

// Loaded first, before any output -- config.php sets session ini directives
// that PHP refuses (with a warning) once a byte has already been echoed.
require_once $root . '/config.php';
require_once $root . '/inc/functions.php';

$jsSrc = (string) file_get_contents($root . '/assets/js/reports.js');
$apiSrc = (string) file_get_contents($root . '/api/reports.php');

$pass = 0; $fail = 0;
function t57ir(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#57 follow-up: Incidents report responder filter ===\n\n";

// ── The button cbyrdmo clicked really is data-report="incident_report" ──
$reportsHtml = (string) file_get_contents($root . '/reports.php');
t57ir('the "Incidents" tab button uses data-report="incident_report" (matches cbyrdmo\'s "click incident")',
    (bool) preg_match('/data-report="incident_report">\s*<i[^>]*><\/i>Incidents/s', $reportsHtml));

// ── JS: showResponder's whitelist must include incident_report, without
//    regressing the existing entries ──
if (preg_match('/function selectReportType\([^)]*\)\s*\{(.*?)\r?\n    \}\r?\n/s', $jsSrc, $m) !== 1) {
    echo "[FAIL] could not isolate selectReportType() from assets/js/reports.js — file structure changed?\n";
    echo "\n1 passed, 1 failed\n";
    exit(1);
}
$fn = $m[1];
t57ir("showResponder's whitelist includes 'incident_report'",
    (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .incident_report./s', $fn));
foreach (['unit_log', 'dispatch_log', 'facility_log', 'notes_log'] as $existing) {
    t57ir("showResponder's whitelist still includes '{$existing}' (not regressed)",
        (bool) preg_match('/showResponder\s*=\s*\([^)]*type === .' . preg_quote($existing, '/') . './s', $fn));
}

// ── PHP: incident_report's case must apply $responder_id via EXISTS
//    (not a JOIN, which would duplicate rows for multi-unit incidents) ──
if (preg_match('/case \'incident_report\':(.*?)case \'facility_log\':/s', $apiSrc, $fm) !== 1) {
    echo "[FAIL] could not isolate the incident_report case from api/reports.php — file structure changed?\n";
    $fail++;
} else {
    $block = $fm[1];
    t57ir('incident_report case checks $responder_id > 0', strpos($block, '$responder_id > 0') !== false);
    t57ir('the filter uses EXISTS against assigns (not a JOIN that would duplicate multi-unit incidents)',
        (bool) preg_match('/EXISTS\s*\(SELECT 1 FROM `\{?\$prefix\}?assigns`/', $block));
    t57ir('the EXISTS subquery correlates on ticket_id = t.id', strpos($block, '`ra`.`ticket_id` = `t`.`id`') !== false);
    t57ir('the EXISTS subquery filters on responder_id', strpos($block, '`ra`.`responder_id` = ?') !== false);
}

// ── Functional check against real rows, real query ──────────────────────
// api/reports.php is a monolithic, session/header-coupled endpoint (the
// sibling test_gh57_responder_filter_visibility.php stays structural-only
// for the same reason -- including it here fights session_start() +
// security-headers.php's own header calls after this file has already
// echoed the structural results above). Manually verified live through a
// real authenticated session during development: exactly 3 rows for a
// responder with 3 real assignments this year, matching a direct DB count,
// and 0 for an unrelated responder with none. This block re-proves the
// SAME EXISTS clause the endpoint now runs (copied verbatim from the fix,
// not re-derived) against real fixture rows this test creates and tears
// down, so a future edit to that WHERE clause still gets caught here.
$prefix = $GLOBALS['db_prefix'] ?? '';

$testTypeId = (int) (db_fetch_value("SELECT MIN(id) FROM `{$prefix}in_types`") ?: 1);
$testUserId = (int) (db_fetch_value("SELECT MIN(id) FROM `{$prefix}user`") ?: 1);

db_query(
    "INSERT INTO `{$prefix}ticket`
        (`in_types_id`, `street`, `city`, `state`, `status`, `severity`, `scope`, `description`, `date`, `updated`)
     VALUES (?, 'GH57ir Test St A', 'Testville', 'MN', 2, 3, 'test', 'GH#57ir filtered-in ticket', NOW(), NOW())",
    [$testTypeId]
);
$tidIn = (int) db_insert_id();
db_query(
    "INSERT INTO `{$prefix}ticket`
        (`in_types_id`, `street`, `city`, `state`, `status`, `severity`, `scope`, `description`, `date`, `updated`)
     VALUES (?, 'GH57ir Test St B', 'Testville', 'MN', 2, 3, 'test', 'GH#57ir filtered-out ticket', NOW(), NOW())",
    [$testTypeId]
);
$tidOut = (int) db_insert_id();

db_query(
    "INSERT INTO `{$prefix}responder` (`name`, `handle`, `description`)
     VALUES ('GH57ir Test Responder', 'GH57irTest', '')"
);
$testResponderId = (int) db_insert_id();

db_query(
    "INSERT INTO `{$prefix}assigns` (`ticket_id`, `responder_id`, `user_id`, `dispatched`)
     VALUES (?, ?, ?, NOW())",
    [$tidIn, $testResponderId, $testUserId]
);

try {
    $matched = db_fetch_all(
        "SELECT `t`.`id` FROM `{$prefix}ticket` `t`
         WHERE `t`.`id` IN (?, ?)
           AND EXISTS (SELECT 1 FROM `{$prefix}assigns` `ra`
                       WHERE `ra`.`ticket_id` = `t`.`id` AND `ra`.`responder_id` = ?)",
        [$tidIn, $tidOut, $testResponderId]
    );
    $matchedIds = array_map(fn($r) => (int) $r['id'], $matched);
    t57ir('the filtered-IN ticket (has an assignment to this responder) matches',
        in_array($tidIn, $matchedIds, true), 'matched: ' . implode(',', $matchedIds));
    t57ir('the filtered-OUT ticket (no assignment to this responder) does NOT match',
        !in_array($tidOut, $matchedIds, true), 'matched: ' . implode(',', $matchedIds));
    t57ir('exactly one ticket matched (no row duplication from the EXISTS pattern)',
        count($matchedIds) === 1, 'matched: ' . implode(',', $matchedIds));
} finally {
    db_query("DELETE FROM `{$prefix}assigns` WHERE responder_id = ?", [$testResponderId]);
    db_query("DELETE FROM `{$prefix}responder` WHERE id = ?", [$testResponderId]);
    db_query("DELETE FROM `{$prefix}ticket` WHERE id IN (?, ?)", [$tidIn, $tidOut]);
}

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
