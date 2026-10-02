<?php
/**
 * Zello widget: persist open/closed state across page navigation
 * (2026-09-08, Eric's bug report). See assets/js/zello-widget.js's own
 * docblock at the "wasOpen" declaration for the full reasoning, including
 * why radio-widget.js deliberately does NOT do the same thing.
 *
 * assets/js/zello-widget.js is a heavy DOM/window/BOM-dependent file with
 * no meaningful way to Node-eval its actual runtime behavior without an
 * extensive DOM mock -- this project's established pattern for files in
 * that category (see tests/test_phase152_window_detach.php's own
 * docblock) is structural source guards rather than a Node harness. The
 * underlying CSS mechanism this fix depends on (a cloned <link> losing its
 * media attribute) was verified separately, live, against the real
 * your-server.example.com print.css.
 *
 * Run: /c/xampp/8.2.4/php/php.exe tests/test_zello_widget_open_persistence.php
 */
chdir(__DIR__ . '/..');

$pass = 0; $fail = 0;
function tzp($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($hint !== '' && !$cond ? " -- $hint" : '') . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Zello widget -- open/closed state persists across page navigation ===\n\n";

$zelloJs = (string) @file_get_contents('assets/js/zello-widget.js');
$radioJs = (string) @file_get_contents('assets/js/radio-widget.js');

tzp('zello-widget.js exists', $zelloJs !== '');

// ── 1. Reads the persisted flag before init() runs ──────────────────────
tzp('reads zello_widget_open from localStorage into a wasOpen flag',
    (bool) preg_match('/wasOpen\s*=\s*\(localStorage\.getItem\(\'zello_widget_open\'\)\s*===\s*\'1\'\)/', $zelloJs));

// ── 2. show()/hide() persist the flag -- the SAME sites that already ────
//      persist mute/live-monitor, i.e. genuinely deliberate user actions
//      (Close button, Minimize button, Esc key, the Open Zello toggle),
//      never an incidental internal hide.
tzp('show() persists zello_widget_open = 1',
    (bool) preg_match(
        "/function show\\(\\) \\{[^}]*localStorage\\.setItem\\('zello_widget_open', '1'\\)/s",
        $zelloJs
    ));
tzp('hide() persists zello_widget_open = 0',
    (bool) preg_match(
        "/function hide\\(\\) \\{[^}]*localStorage\\.setItem\\('zello_widget_open', '0'\\)/s",
        $zelloJs
    ));

// ── 3. init() restores it by calling show(), AFTER the template/listeners ──
//      are wired -- restoring too early (before attachListeners()) would
//      call show() against a widget with no working buttons yet.
$initPos      = strpos($zelloJs, 'function init() {');
$restorePos   = strpos($zelloJs, 'if (wasOpen) {');
$attachPos    = strpos($zelloJs, 'attachListeners();');
tzp('init() restores the open state via show() when wasOpen is true',
    $restorePos !== false
    && strpos(substr($zelloJs, $restorePos, 60), 'show();') !== false);
tzp('the restore happens AFTER attachListeners() runs, not before',
    $initPos !== false && $attachPos !== false && $restorePos !== false
    && $initPos < $attachPos && $attachPos < $restorePos);

// ── 4. Never silently swallows a localStorage exception into a hard fail ──
tzp('reading the persisted flag is wrapped in try/catch (Safari private mode etc.)',
    (bool) preg_match('/try \{\s*wasOpen = \(localStorage\.getItem/', $zelloJs));

// ── 5. radio-widget.js deliberately does NOT carry the same auto-restore ──
//      -- its own init() explains why (SSE + history + N external
//      radioid.net lookups on every page nav). This test guards against
//      someone copy-pasting the Zello fix there without re-reading that
//      cost: it must still say the auto-open was intentionally removed.
tzp('radio-widget.js still documents its own deliberate NON-restore (do not silently regress this)',
    strpos($radioJs, 'we used to auto-open the widget on page load') !== false);
// Bound the check to init()'s own body (up to the "Public toggle" section
// that follows it in the file) rather than an unbounded non-greedy match,
// which would trivially "match" against show()/the localStorage key
// appearing anywhere LATER in the file regardless of which function it's
// really in.
$radioInitStart = strpos($radioJs, 'function init() {');
$radioInitEnd   = strpos($radioJs, '// ── Public toggle');
$radioInitBody  = ($radioInitStart !== false && $radioInitEnd !== false && $radioInitEnd > $radioInitStart)
    ? substr($radioJs, $radioInitStart, $radioInitEnd - $radioInitStart)
    : '';
tzp('found radio-widget.js\'s init() body to scope the next check against',
    $radioInitBody !== '');
tzp('radio-widget.js\'s init() does NOT call show() based on a persisted open flag',
    $radioInitBody !== '' && strpos($radioInitBody, 'show()') === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
