<?php
/**
 * GH#64 — Interval Report source-level wiring verification.
 *
 * Companion to tests/test_interval_report_math.php (pure math) and
 * tests/test_interval_report_integration.php (real-writer DB integration).
 * Neither of those touches api/reports.php's HTTP surface or the
 * reports.php/reports.js frontend at all, and this codebase has no
 * session-login HTTP test harness to drive that path end-to-end (see
 * tests/test_org_sharing_endpoint_wiring.php's own docblock — the same
 * conclusion applies here unchanged). This file instead greps the actual
 * shipped source, the same technique tools/api_contract_audit.php and
 * tools/schema_audit.php already use — because this project has been bitten
 * more than once by a computed value or a top-level response key that
 * existed on the server and nothing on the client ever consumed (see this
 * project's own severity_breakdown/disposition_breakdown precedent: both
 * are still, as of this writing, never referenced anywhere in
 * assets/js/reports.js — a live instance of exactly the class of bug this
 * file exists to catch for the NEW keys this phase adds).
 *
 * Live end-to-end confirmation of the full HTTP+RBAC+render path happens
 * via the Browser pane against a real host as part of this change's
 * self-verification step (see the GH#64 comment / commit for the result).
 *
 * @requires-db (reads inc/rbac.php's permission seed; no writes)
 * Usage: php tests/test_interval_report_wiring.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== GH#64 — Interval Report wiring verification ===\n\n";

$base = realpath(__DIR__ . '/..');
function _src(string $rel): string {
    global $base;
    $p = $base . '/' . $rel;
    if (!is_file($p)) { echo "MISSING FILE: $rel\n"; return ''; }
    return file_get_contents($p);
}

// ── inc/interval-report.php ──────────────────────────────────────────────
echo "--- inc/interval-report.php ---\n";
$math = _src('inc/interval-report.php');
foreach (['interval_report_ts', 'interval_report_diff', 'interval_report_fmt', 'interval_report_compute'] as $fn) {
    t("defines {$fn}()", strpos($math, "function {$fn}(") !== false);
}

// ── api/reports.php ──────────────────────────────────────────────────────
echo "\n--- api/reports.php ---\n";
$api = _src('api/reports.php');
t('requires inc/interval-report.php', strpos($api, "require_once __DIR__ . '/../inc/interval-report.php';") !== false);
t("'interval_report' is a recognized \$valid_reports entry", preg_match("/\\\$valid_reports\s*=\s*\[.*?'interval_report'.*?\];/s", $api) === 1);
t("has a case 'interval_report': block", strpos($api, "case 'interval_report':") !== false);
t('calls interval_report_compute() inside the case', strpos($api, 'interval_report_compute(') !== false);
t('calls interval_report_fmt() to render the six leg columns', strpos($api, 'interval_report_fmt(') !== false);
// The org-scope ticket filter must be applied to interval_report the same
// way it is to unit_log/dispatch_log/facility_log -- Phase 99j-7's own
// convention. Structural check: $rptTicketFrag is appended somewhere
// AFTER the 'interval_report' case begins and before its own break;.
$intervalCaseStart = strpos($api, "case 'interval_report':");
$intervalCaseEnd   = strpos($api, "break;", $intervalCaseStart);
$intervalCaseBody  = ($intervalCaseStart !== false && $intervalCaseEnd !== false)
    ? substr($api, $intervalCaseStart, $intervalCaseEnd - $intervalCaseStart) : '';
t('interval_report case applies the org-scope ticket filter ($rptTicketFrag)', strpos($intervalCaseBody, '$rptTicketFrag') !== false);
t('interval_report case excludes soft-deleted tickets', strpos($intervalCaseBody, 'deleted_at') !== false);
t('interval_report case supports the responder_id filter (by-unit scoping)', strpos($intervalCaseBody, '$responder_id > 0') !== false);
t('response is linked to the incident (drill-down)', strpos($intervalCaseBody, '$incident_link_cols[] = 0;') !== false);
t('unit column is linked (drill-down)', strpos($intervalCaseBody, '$unit_link_cols[] = 2;') !== false);
t('populates $interval_by_type', strpos($intervalCaseBody, '$interval_by_type[]') !== false);
t('populates $interval_by_unit', strpos($intervalCaseBody, '$interval_by_unit[]') !== false);
t(
    'the two new top-level keys are declared for EVERY report (default []), not only inside the case — same discipline as severity_breakdown',
    strpos($api, '$interval_by_type = [];') !== false && strpos($api, '$interval_by_unit = [];') !== false
);
t('the two new keys ride in the final json_response() payload', strpos($api, "'interval_by_type' => \$interval_by_type,") !== false && strpos($api, "'interval_by_unit' => \$interval_by_unit,") !== false);
// The RBAC gate already applied uniformly above the switch() (is_admin() ||
// rbac_can('action.view_reports'), enforced via the $isFiltered/$_canAggregate
// check before the switch runs) must NOT be bypassed by a report-specific
// carve-out for interval_report -- i.e. no second, narrower or looser gate
// introduced just for this case.
t('no per-report RBAC carve-out was added for interval_report (relies on the existing uniform gate)', strpos($intervalCaseBody, 'rbac_can(') === false && strpos($intervalCaseBody, 'is_admin(') === false);

// ── reports.php (page) ───────────────────────────────────────────────────
echo "\n--- reports.php ---\n";
$page = _src('reports.php');
t('has an Interval Report tab button', strpos($page, 'data-report="interval_report"') !== false);
t('has the by-type/by-unit breakdown panel', strpos($page, 'id="intervalBreakdownPanel"') !== false);
t('breakdown panel has a By Incident Type table body', strpos($page, 'id="intervalByTypeBody"') !== false);
t('breakdown panel has a By Unit table body', strpos($page, 'id="intervalByUnitBody"') !== false);

// ── assets/js/reports.js ─────────────────────────────────────────────────
echo "\n--- assets/js/reports.js ---\n";
$js = _src('assets/js/reports.js');
t("'interval_report' is included in the responder-filter whitelist (showResponder)", preg_match("/showResponder\s*=\s*\(.*?type === 'interval_report'.*?\);/s", $js) === 1);
t('defines renderIntervalBreakdown()', strpos($js, 'function renderIntervalBreakdown()') !== false);
t('renderReport() actually CALLS renderIntervalBreakdown() (not just defines it — the exact "plumbing exists, nobody wired the last mile" failure class)', strpos($js, 'renderIntervalBreakdown();') !== false);
t('the breakdown panel is hidden by showLoading() at the start of every run', strpos($js, "intervalBreakdownPanel.classList.add('d-none');") !== false);
t('reads reportData.interval_by_type', strpos($js, 'reportData.interval_by_type') !== false);
t('reads reportData.interval_by_unit', strpos($js, 'reportData.interval_by_unit') !== false);
t('renderSummaryCards() has an interval_report branch', strpos($js, "currentReport === 'interval_report'") !== false);
t('summary cards read avg_response_time', strpos($js, 'summary.avg_response_time') !== false);
t('summary cards read avg_scene_time', strpos($js, 'summary.avg_scene_time') !== false);
t('summary cards read avg_transport_time', strpos($js, 'summary.avg_transport_time') !== false);
t('breakdown label cell uses textContent, not innerHTML (no untrusted-HTML injection path)', strpos($js, 'tdLabel.textContent = item.label') !== false);
t('label/count/avg values are never assigned via innerHTML', strpos($js, 'innerHTML = item.') === false);

// ── RBAC parity with the existing Reports page ───────────────────────────
// "should probably match whatever permission gates the existing Reports
// page" (this phase's own instructions) — verify by construction rather
// than assertion: interval_report was added to $valid_reports and is
// governed by the SAME switch()/gate api/reports.php already applies
// uniformly to every non-personnel report (the $isFiltered / $_canAggregate
// check runs BEFORE the switch, so there is structurally no way for one
// case inside the switch to see a request the gate above it rejected).
// The screen-level gate is reports.php's own rbac_require_screen('screen.reports')
// call, unchanged by this phase.
echo "\n--- RBAC parity ---\n";
$pageGate = _src('reports.php');
t("reports.php still gates on screen.reports (unchanged — no new/looser page-level gate introduced)", strpos($pageGate, "rbac_require_screen('screen.reports')") !== false);
t(
    "the pre-switch aggregate gate in api/reports.php still runs for every report not filtered by incident_id/responder_id (structurally covers interval_report — no bypass was added)",
    strpos($api, '$isFiltered && !$_canAggregate') !== false
);

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
