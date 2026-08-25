<?php
/**
 * Eric (2026-08-12) — two live UI bugs found while investigating incident
 * Security Labels for a training video, both reproduced and confirmed
 * against your-server.example.com before being fixed:
 *
 * 1. #secLabelBadge lived INSIDE <h5 id="pageTitle">. incident-detail.js's
 *    renderHeader() does `pageTitle.innerHTML = ...` on every render, which
 *    silently destroyed the badge every single load — there was NO way to
 *    reach the security-label picker on an existing incident, ever, in the
 *    deployed product. Confirmed live: document.getElementById(
 *    'secLabelBadge') was null after page load despite the element being
 *    present in the server-rendered HTML fetched fresh.
 *
 * 2. Clicking the .edit-section-btn pencil inside a collapsible section
 *    header also collapsed the section. The button's own click handler
 *    already called e.stopPropagation() ("Don't toggle collapse") but that
 *    cannot work: Bootstrap 5's collapse data-api listens on `document` in
 *    the CAPTURE phase, which runs before the click ever reaches the
 *    button — stopPropagation() during the later bubble phase cannot undo
 *    a capture-phase listener on an ancestor that already fired on the way
 *    down. Confirmed live with instrumented capture/bubble listeners.
 *
 * No JS runtime in CI (docs/CI-ENVIRONMENT.md), so these are static-
 * contract checks against the shipped markup/JS — same convention as
 * test_gh44_command_bar_status_synonyms.php.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== incident-detail.php: security badge + pencil/collapse fixes ===\n\n";

$html = file_get_contents($root . '/incident-detail.php');
$js   = file_get_contents($root . '/assets/js/incident-detail.js');

// ── 1. #secLabelBadge must NOT be a descendant of #pageTitle ────────────
$h5Start = strpos($html, '<h5 class="mb-0 d-flex align-items-center" id="pageTitle">');
if ($h5Start === false) {
    bad('found #pageTitle to check its boundaries', 'element not found — did the markup change shape?');
} else {
    $h5Close = strpos($html, '</h5>', $h5Start);
    if ($h5Close === false) {
        bad('found a closing </h5> for #pageTitle');
    } else {
        $insideH5 = substr($html, $h5Start, $h5Close - $h5Start);
        if (strpos($insideH5, 'secLabelBadge') === false) {
            ok('secLabelBadge is NOT inside #pageTitle (renderHeader()\'s innerHTML replace cannot destroy it)');
        } else {
            bad('secLabelBadge is still inside #pageTitle', 'renderHeader() will wipe it on every render — regression of the live bug');
        }
    }
}
// It must still exist SOMEWHERE on the page (moved, not deleted).
if (strpos($html, 'id="secLabelBadge"') !== false) {
    ok('secLabelBadge still exists on the page (moved as a sibling, not removed)');
} else {
    bad('secLabelBadge is missing from incident-detail.php entirely');
}

// renderHeader() itself still does the innerHTML replace on pageTitle —
// this is expected and fine now that the badge isn't inside it; this
// assertion pins down WHY the fix works (the destructive call is still
// there, it just doesn't reach the badge anymore).
if (preg_match('/renderHeader[\s\S]{0,1500}pageTitle[\'"]\)\.innerHTML\s*=/', $js)) {
    ok('renderHeader() still replaces #pageTitle.innerHTML (confirms the fix works by relocation, not by changing this call)');
} else {
    bad('renderHeader()\'s pageTitle.innerHTML assignment was not found where expected — re-verify the badge-placement fix still applies');
}

// ── 2. No .form-section header may carry data-bs-toggle="collapse" ──────
// (Bootstrap's own data-api is what caused the capture-phase bypass;
// every section header must be driven by bindManualSectionCollapse()
// instead. A single stray data-bs-toggle="collapse" reintroduces the bug
// for just that section.)
if (preg_match_all('/class="[^"]*form-section[^"]*"[^>]*data-bs-toggle="collapse"/', $html, $m)) {
    bad('a .form-section header still carries data-bs-toggle="collapse"', count($m[0]) . ' instance(s) — these will bypass bindManualSectionCollapse() via Bootstrap\'s own capture-phase data-api');
} else {
    ok('no .form-section header carries data-bs-toggle="collapse" (all driven by bindManualSectionCollapse() instead)');
}

$formSectionCount = preg_match_all('/class="[^"]*form-section[^"]*"/', $html);
$dataTargetCount  = preg_match_all('/class="[^"]*form-section[^"]*"[^>]*data-bs-target="#collapse/', $html);
if ($formSectionCount > 0 && $formSectionCount === $dataTargetCount) {
    ok("every .form-section header ({$formSectionCount}) still carries data-bs-target (collapse behavior preserved, just not via Bootstrap's data-api)");
} else {
    bad('a .form-section header lost its data-bs-target', "found {$formSectionCount} .form-section headers but only {$dataTargetCount} with data-bs-target");
}

// ── 3. bindManualSectionCollapse() exists, excludes .edit-section-btn,
// and is actually invoked ───────────────────────────────────────────────
if (strpos($js, 'function bindManualSectionCollapse') !== false) {
    ok('bindManualSectionCollapse() is defined');
} else {
    bad('bindManualSectionCollapse() is not defined in incident-detail.js');
}
if (preg_match('/bindManualSectionCollapse\s*\(\s*\)\s*;/', $js) && substr_count($js, 'bindManualSectionCollapse') >= 2) {
    ok('bindManualSectionCollapse() is called (defined + invoked, not dead code)');
} else {
    bad('bindManualSectionCollapse() is defined but never called');
}
if (preg_match('/function bindManualSectionCollapse[\s\S]{0,800}edit-section-btn/', $js)) {
    ok('bindManualSectionCollapse() checks for .edit-section-btn before toggling');
} else {
    bad('bindManualSectionCollapse() does not appear to exclude .edit-section-btn clicks');
}

// ── 4. The pencil button's own stopPropagation must still be present
// (defense-in-depth — harmless now, and protects other listeners) ───────
if (preg_match('/edit-section-btn[\s\S]{0,300}stopPropagation/', $js)) {
    ok('the .edit-section-btn click handler still calls stopPropagation() (defense-in-depth, no regression)');
} else {
    bad('the .edit-section-btn click handler no longer calls stopPropagation()');
}

// ── 5. GH#106 (rjonesbsink, 2026-08-24) — the PAR-card poll interval's
// return value was discarded (`setInterval(function () { refreshPAR(...)
// }, 10000);`), so nothing could ever stop it. A role without
// action.manage_par gets a 403 on EVERY poll of api/par.php?action=
// for_ticket forever, for the life of the page, with no way to cancel.
// Fixed by assigning the handle and clearing it on the first 403. ──────
if (preg_match('/parPollInterval\s*=\s*setInterval\s*\(\s*function\s*\(\s*\)\s*\{\s*refreshPAR\(ticketId\)\s*;\s*\}\s*,\s*10000\s*\)\s*;/', $js)) {
    ok('the PAR poll setInterval() return value is assigned to a variable (parPollInterval), not discarded');
} else {
    bad('the PAR poll setInterval() call no longer assigns its handle — GH#106 regression (unstoppable 403 loop)');
}
if (preg_match('/setInterval\s*\(\s*function\s*\(\s*\)\s*\{\s*refreshPAR\(ticketId\)\s*;\s*\}\s*,\s*10000\s*\)\s*;(?!\s*\/\/)/', $js)
    && !preg_match('/parPollInterval\s*=\s*setInterval\s*\(\s*function\s*\(\s*\)\s*\{\s*refreshPAR\(ticketId\)\s*;\s*\}\s*,\s*10000\s*\)\s*;/', $js)) {
    bad('found a bare, unassigned refreshPAR setInterval() — the exact GH#106 shape');
} else {
    ok('no bare/unassigned refreshPAR setInterval() call remains');
}
if (preg_match('/r\.status\s*===\s*403[\s\S]{0,200}clearInterval\(parPollInterval\)/', $js)) {
    ok('refreshPAR() clears parPollInterval specifically on a 403 response — polling stops permanently rather than retrying forever');
} else {
    bad('refreshPAR() does not clear parPollInterval on a 403 — GH#106 not actually fixed');
}
if (preg_match('/function refreshPAR\(tid\)\s*\{[\s\S]{0,300}\.then\(function\s*\(\s*r\s*\)\s*\{[\s\S]{0,600}r\.status\s*===\s*403/', $js)) {
    ok('refreshPAR() inspects the raw Response (checks r.status) before parsing JSON, rather than blindly calling r.json()');
} else {
    bad('refreshPAR() does not appear to inspect the response status before parsing — cannot distinguish 403 from success');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
