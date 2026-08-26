<?php
/**
 * GH#105 (2026-08-25/26) — Print button on incident-detail.php took 2-3
 * minutes to open the print dialog in Safari when clicked (Cmd+P on the
 * same page was instant).
 *
 * ROUND 1 (b956de0) shipped a setTimeout(fn, 0) deferral based on the
 * reporter's own tested-safe suggestion, explicitly flagged as NOT a
 * confirmed fix — nobody had reproduced the underlying WebKit behavior.
 *
 * ROUND 2 (this fix) — the reporter came back with a genuine root cause
 * and a 4-line minimal reproduction: an OPEN EventSource anywhere on the
 * page (connected OR stuck retrying) is enough to stall Safari's
 * PROGRAMMATIC window.print() for minutes; Cmd+P is unaffected because it
 * doesn't go through the same code path. The setTimeout(fn, 0) deferral
 * does NOT help (confirmed live — it was never a timing issue, since the
 * reporter's own console evidence showed the click handler returning in
 * 0.010ms with no user-gesture ambiguity at all). A 'beforeprint' listener
 * doesn't work either — Safari stalls BEFORE dispatching that event, so
 * the stream has to already be closed by the time print() is called.
 *
 * event-bus.js and assets/js/radio-widget.js are both loaded globally via
 * inc/navbar.php (confirmed by the reporter's own bisection), which is
 * why this hit every page in the app, not just incident-detail.php.
 *
 * Fix: a shared window.appPrint() (assets/js/event-bus.js) that closes
 * both EventSources before calling window.print(), and reopens them on
 * 'afterprint'. All 9 call sites (5 inline onclick, 4 JS click handlers)
 * now call appPrint() instead of window.print()/the old setTimeout wrapper.
 *
 * No JS runtime in CI (docs/CI-ENVIRONMENT.md), so this stays a
 * static-contract check against the shipped markup/JS — it cannot prove
 * the Safari stall is actually gone, only that the fix the reporter's own
 * reproduction points at is wired everywhere it needs to be.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#105: every print call site uses appPrint(), which closes SSE streams before printing ===\n\n";

// ── 1. Inline onclick print buttons — every one calls appPrint(), none
// call window.print() or the old (disproven) setTimeout deferral. ────────
$phpFiles = [
    'incident-detail.php',
    'facility-detail.php',
    'help.php',
    'roster.php',
    'unit-detail.php',
];
foreach ($phpFiles as $f) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { bad("$f exists to check", 'file not found'); continue; }
    $html = file_get_contents($path);

    if (preg_match('/onclick="[^"]*window\.print\(\)/', $html)) {
        bad("$f has no direct window.print() left in an onclick", 'GH#105 round 2 regression — this bypasses the SSE-close-before-print fix entirely');
        continue;
    }
    if (preg_match('/onclick="appPrint\(\)"/', $html)) {
        ok("$f's print button calls appPrint()");
    } else {
        bad("$f's print button does not call appPrint()", 'expected onclick="appPrint()"');
    }
}

// ── 2. JS-driven click handlers — same rule. ──────────────────────────────
$jsChecks = [
    'assets/js/app.js'       => 'the command-bar "print" action',
    'assets/js/ics-forms.js' => 'printForm()\'s client-side print path',
    'assets/js/reports.js'   => 'printReport()',
    'assets/js/sop.js'       => 'the SOP page\'s #btnPrint click listener',
];
foreach ($jsChecks as $f => $label) {
    $path = $root . '/' . $f;
    if (!is_file($path)) { bad("$f exists to check", 'file not found'); continue; }
    $js = file_get_contents($path);

    if (preg_match('/\bwindow\.print\(\)/', $js)) {
        bad("$f ($label) has a direct window.print() call", 'GH#105 round 2 regression — bypasses the SSE-close-before-print fix');
        continue;
    }
    if (preg_match('/\bappPrint\(\)\s*;/', $js)) {
        ok("$f ($label) calls appPrint()");
    } else {
        bad("$f ($label) does not call appPrint()", 'expected a bare appPrint(); statement');
    }
}

// ── 3. The fix itself: appPrint() closes streams before print(), and
// reopens them on afterprint. ─────────────────────────────────────────────
$ebSrc = file_get_contents($root . '/assets/js/event-bus.js');

if (preg_match('/window\.appPrint\s*=\s*function\s*\(\s*\)\s*\{([\s\S]{0,600}?)\};/', $ebSrc, $m)) {
    $body = $m[1];
    $disconnectsBeforePrint = strpos($body, 'EventBus.disconnectSSE()') !== false
        && strpos($body, 'window.RadioWidget') !== false
        && strpos($body, 'disconnectSSE()') !== false
        && strpos($body, 'window.print()') !== false
        && strpos($body, 'EventBus.disconnectSSE()') < strpos($body, 'window.print()');
    if ($disconnectsBeforePrint) {
        ok('window.appPrint() closes EventBus\'s SSE stream (and guards for RadioWidget\'s) BEFORE calling window.print()');
    } else {
        bad('window.appPrint() does not close SSE streams before window.print()', 'this is the exact fix the reporter\'s reproduction requires — order matters, since beforeprint fires too late');
    }
} else {
    bad('window.appPrint() is not defined in assets/js/event-bus.js');
}

if (preg_match("/addEventListener\\('afterprint',\\s*function\\s*\\(\\s*\\)\\s*\\{([\\s\\S]{0,400}?)\\}\\)/", $ebSrc, $m2)) {
    $afterBody = $m2[1];
    if (strpos($afterBody, 'EventBus.connectSSE()') !== false && strpos($afterBody, 'window.RadioWidget') !== false) {
        ok("an 'afterprint' listener reconnects both EventBus's and RadioWidget's SSE streams once the print dialog closes");
    } else {
        bad("the 'afterprint' listener does not reconnect both streams", 'a page would lose real-time updates permanently after printing once, not just during the dialog');
    }
} else {
    bad("no 'afterprint' listener found in assets/js/event-bus.js");
}

// ── 4. radio-widget.js exposes the disconnect/connect pair appPrint()
// depends on, matching EventBus's own naming convention. ─────────────────
$rwSrc = file_get_contents($root . '/assets/js/radio-widget.js');
if (preg_match('/window\.RadioWidget\s*=\s*\{[\s\S]{0,300}?disconnectSSE[\s\S]{0,300}?connectSSE[\s\S]{0,50}?\};/', $rwSrc)) {
    ok('assets/js/radio-widget.js exposes window.RadioWidget.disconnectSSE()/connectSSE()');
} else {
    bad('assets/js/radio-widget.js does not expose window.RadioWidget.disconnectSSE()/connectSSE()', 'appPrint() depends on this to close radio-widget.js\'s own EventSource, which the reporter confirmed is loaded on every page via navbar.php');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
