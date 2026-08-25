<?php
/**
 * GH#105 (2026-08-25) — Print button on incident-detail.php took 2-3
 * minutes to open the print dialog in Safari when clicked (Cmd+P on the
 * same page was instant). The reporter did extensive isolation (Web
 * Inspector Timeline showed no activity during the wait, Chrome unaffected,
 * a bare minimal repro did NOT reproduce it, content volume ruled out) and
 * landed on: something about this page's structure + print.css combined
 * with a click-triggered (vs keyboard-triggered) window.print() call
 * specifically. Filed as an FYI with a low-risk, tested-safe suggested
 * workaround (defer the call with setTimeout(fn, 0), moving it off the
 * click handler's own call stack) rather than a full root-cause fix.
 *
 * Applied the reporter's own suggested workaround everywhere window.print()
 * is called from a click in this codebase — not just incident-detail.php
 * — since the same print.css and page-structure conventions are shared
 * across every page in the app, and the deferral is a no-downside change
 * for every browser where the button already worked fine.
 *
 * IMPORTANT — this is NOT a confirmed fix. Nobody on this project has
 * independently reproduced or verified the underlying WebKit behavior on
 * Safari; this test only proves the defensive change actually shipped
 * everywhere it should have, matching the reporter's own tested-safe
 * suggestion. No JS runtime in CI (docs/CI-ENVIRONMENT.md), so this is a
 * static-contract check against the shipped markup/JS.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#105: every click-triggered window.print() is deferred with setTimeout ===\n\n";

// ── Inline onclick="window.print()" buttons — every one of these must be
// deferred, and NONE may call window.print() synchronously anymore. ───────
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

    if (preg_match('/onclick="window\.print\(\)"/', $html)) {
        bad("$f has no un-deferred onclick=\"window.print()\" left", 'a synchronous click-triggered window.print() reintroduces GH#105\'s exact reported shape');
        continue;
    }
    if (preg_match('/onclick="setTimeout\(function\(\)\{\s*window\.print\(\);\s*\},\s*0\)"/', $html)) {
        ok("$f's print button defers window.print() via setTimeout(fn, 0)");
    } else {
        bad("$f's print button does not use the setTimeout(fn, 0) deferral", 'expected onclick="setTimeout(function(){ window.print(); }, 0)"');
    }
}

// ── JS-driven click handlers that call window.print() — same rule. ────────
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

    // No bare "window.print();" statement should remain anywhere in these
    // four files — every call must go through setTimeout.
    if (preg_match('/(?<!function \(\) \{ )window\.print\(\)\s*;(?!\s*\}\s*,\s*0\s*\))/', $js)) {
        // The negative lookarounds above try to exclude the deferred form
        // itself; fall back to a stricter manual scan for a bare call that
        // is NOT immediately preceded by "setTimeout(function () { ".
        $bareFound = false;
        foreach (explode("\n", $js) as $lineNo => $line) {
            if (preg_match('/\bwindow\.print\(\)\s*;/', $line) && strpos($line, 'setTimeout') === false) {
                $bareFound = true;
                break;
            }
        }
        if ($bareFound) {
            bad("$f ($label) has a bare, un-deferred window.print() call", 'GH#105 regression — reintroduces the exact reported shape');
            continue;
        }
    }
    if (preg_match('/setTimeout\(\s*function\s*\(\s*\)\s*\{\s*window\.print\(\)\s*;\s*\}\s*,\s*0\s*\)\s*;/', $js)) {
        ok("$f ($label) defers window.print() via setTimeout(function () { window.print(); }, 0)");
    } else {
        bad("$f ($label) does not defer window.print() via setTimeout", 'expected setTimeout(function () { window.print(); }, 0);');
    }
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
