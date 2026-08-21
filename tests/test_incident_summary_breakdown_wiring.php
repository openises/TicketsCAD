<?php
/**
 * severity_breakdown / disposition_breakdown wiring verification
 * (2026-08-20 — the confirmed real-world instance tools/dead_control_audit.php's
 * new check (d), "dead API response key", exists to catch).
 *
 * api/reports.php's 'incident_summary' case has computed both
 * severity_breakdown (GH#87/GH#88, replacing the old fixed
 * high_severity/medium_severity/low_severity summary keys) and
 * disposition_breakdown (Phase 132 Step 5, GH#16) as top-level JSON
 * response keys since those phases shipped — but neither key was ever
 * read anywhere in assets/js/reports.js. `grep -rn
 * "disposition_breakdown|severity_breakdown" assets/` returned zero
 * matches before this fix; specs/handoff.md carried it forward across
 * three separate session entries (v14, v15, v16) as a known,
 * not-yet-fixed instance.
 *
 * Mirrors tests/test_interval_report_wiring.php's own technique and its
 * own stated rationale: this codebase has no session-login HTTP test
 * harness to drive reports.php/reports.js end-to-end, so this greps the
 * actual shipped source — the same technique
 * tools/api_contract_audit.php, tools/schema_audit.php, and
 * tools/dead_control_audit.php itself already use. Live end-to-end
 * confirmation happens separately via the Browser pane against a local
 * dev instance as part of this change's self-verification step.
 *
 * Usage: php tests/test_incident_summary_breakdown_wiring.php
 */

require_once __DIR__ . '/../config.php';

$pass = 0; $fail = 0;
function t($label, $cond) {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== severity_breakdown / disposition_breakdown wiring verification ===\n\n";

$base = realpath(__DIR__ . '/..');
function _src(string $rel): string {
    global $base;
    $p = $base . '/' . $rel;
    if (!is_file($p)) { echo "MISSING FILE: $rel\n"; return ''; }
    return file_get_contents($p);
}

// ── api/reports.php — the server side was already correct; just confirm
//    the shape this fix's JS relies on hasn't drifted. ────────────────────
echo "--- api/reports.php (unchanged by this fix — sanity check the shape) ---\n";
$api = _src('api/reports.php');
t('declares $severity_breakdown = [] for every report (default empty)', strpos($api, '$severity_breakdown = [];') !== false);
t('declares $disposition_breakdown = [] for every report (default empty)', strpos($api, '$disposition_breakdown = [];') !== false);
t(
    'severity_breakdown entries carry value/label/color/count',
    strpos($api, "'value' => \$v,") !== false
    && strpos($api, "'label' => \$lvl['label'],") !== false
    && strpos($api, "'color' => \$lvl['color'],") !== false
    && strpos($api, "'count' => \$grand_sev[\$v] ?? 0,") !== false
);
t(
    'disposition_breakdown entries carry disposition/total',
    strpos($api, "'disposition' => \$drow['disposition_label'],") !== false
    && strpos($api, "'total'       => (int) \$drow['total'],") !== false
);
t(
    'both keys ride in the final json_response() payload',
    strpos($api, "'disposition_breakdown' => \$disposition_breakdown,") !== false
    && strpos($api, "'severity_breakdown' => \$severity_breakdown,") !== false
);

// ── reports.php (page) ───────────────────────────────────────────────────
echo "\n--- reports.php ---\n";
$page = _src('reports.php');
t('has the severity/disposition breakdown panel', strpos($page, 'id="incidentSummaryBreakdownPanel"') !== false);
t('breakdown panel has a By Severity table body', strpos($page, 'id="severityBreakdownBody"') !== false);
t('breakdown panel has a By Disposition table body', strpos($page, 'id="dispositionBreakdownBody"') !== false);
t('panel starts hidden (d-none)', (bool) preg_match('/class="row g-3 mt-1 d-none" id="incidentSummaryBreakdownPanel"/', $page));

// ── assets/js/reports.js ─────────────────────────────────────────────────
echo "\n--- assets/js/reports.js ---\n";
$js = _src('assets/js/reports.js');
t('defines renderIncidentSummaryBreakdown()', strpos($js, 'function renderIncidentSummaryBreakdown()') !== false);
t(
    'renderReport() actually CALLS renderIncidentSummaryBreakdown() (not just defines it — the exact "plumbing exists, nobody wired the last mile" failure class this fix closes)',
    strpos($js, 'renderIncidentSummaryBreakdown();') !== false
);
t(
    "the breakdown panel is hidden by showLoading() at the start of every run",
    strpos($js, "incidentSummaryBreakdownPanel.classList.add('d-none');") !== false
);
t('reads reportData.severity_breakdown', strpos($js, 'reportData.severity_breakdown') !== false);
t('reads reportData.disposition_breakdown', strpos($js, 'reportData.disposition_breakdown') !== false);
t(
    "renderReport() gates on currentReport === 'incident_summary', mirroring the interval_report gate immediately above it",
    (bool) preg_match('/currentReport === \'incident_summary\'\)\s*\{\s*\n\s*renderIncidentSummaryBreakdown\(\);/', $js)
);
t('severity label cell uses textContent, not innerHTML (no untrusted-HTML injection path)', strpos($js, "sevLabelCell.appendChild(document.createTextNode(sev.label") !== false);
t('disposition label cell uses textContent', strpos($js, 'dispLabelCell.textContent = disp.disposition;') !== false);
t(
    'neither breakdown body is ever assigned via innerHTML with untrusted content (only innerHTML = \'\' resets)',
    !preg_match('/(severityBreakdownBody|dispositionBreakdownBody)\.innerHTML\s*=(?!\s*\'\')/', $js)
);

// ── dead_control_audit.php check (d) itself no longer flags either key ────
echo "\n--- tools/dead_control_audit.php check (d) ---\n";
$dcaOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($base . '/tools/dead_control_audit.php') . ' --api-only 2>&1');
$dcaOut = (string) $dcaOut;
t(
    'severity_breakdown is no longer reported as a NEW dead API key',
    strpos($dcaOut, '[NEW]      apikey:severity_breakdown') === false
);
t(
    'disposition_breakdown is no longer reported as a NEW dead API key',
    strpos($dcaOut, '[NEW]      apikey:disposition_breakdown') === false
);

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
