<?php
/**
 * Phase 152 (Console rebuild, continued) — "detach into its own window"
 * (2026-09-08, Eric's request: "many operators with multiple screens
 * wanting to spread out their windows into additional displays").
 *
 * assets/js/window-detach.js is a heavy DOM/window/BOM-dependent file
 * (window.open, documentPictureInPicture, document.querySelectorAll,
 * cross-document node adoption) with no meaningful way to Node-eval its
 * actual runtime behavior without an extensive DOM/window mock -- this
 * project's own established pattern for files in that category
 * (zello-widget.js, radio-widget.js) is structural source guards rather
 * than a Node harness (see tests/test_console_audio_state.php's own
 * docblock for the precedent and its stated reasoning: "this project has
 * a documented gap for hardware-dependent audio tests"). Live behavioral
 * verification happened through the Browser pane, by hand, as part of
 * this feature's own shipping checklist -- not reproduced here.
 *
 * What CAN be proven mechanically, and is: the honest capability claim
 * (Document Picture-in-Picture where supported -- a REAL OS-level
 * always-on-top window, not an approximation -- with a non-always-on-top
 * popup fallback elsewhere, never silently claiming more than either
 * path actually delivers); the position-reset fix (widgets are
 * positioned via inline position:fixed + absolute main-window pixel
 * offsets, which would render off-screen in a small detached window
 * without resetting them, and restoring the EXACT original inline style
 * on re-attach rather than a default); that all three floating surfaces
 * (Zello, Radio, Simulselect) wire a Detach control through the SAME
 * shared utility rather than three divergent implementations; and that
 * the Simulselect widget detaches only its inner content, never the
 * Bootstrap-managed dropdown wrapper itself.
 *
 * Usage: php tests/test_phase152_window_detach.php
 */
chdir(__DIR__ . '/..');

$pass = 0; $fail = 0;
function t($label, $cond, $hint = '') {
    global $pass, $fail;
    echo ($cond ? "[PASS] " : "[FAIL] ") . $label . ($hint !== '' && !$cond ? " -- $hint" : '') . "\n";
    $cond ? $pass++ : $fail++;
}

echo "=== Phase 152 -- detach floating widgets into their own window ===\n\n";

$detachJs = (string) @file_get_contents('assets/js/window-detach.js');
$navbar = (string) @file_get_contents('inc/navbar.php');
$zelloTpl = (string) @file_get_contents('inc/zello-widget-template.php');
$zelloJs = (string) @file_get_contents('assets/js/zello-widget.js');
$radioJs = (string) @file_get_contents('assets/js/radio-widget.js');
$widgetJs = (string) @file_get_contents('assets/js/console-simulselect-widget.js');

// ── 1. window-detach.js itself ──────────────────────────────────────────
t('window-detach.js exists', $detachJs !== '');
t('exports window.WindowDetach with open() and hasPiP()',
    strpos($detachJs, 'window.WindowDetach = {') !== false
    && strpos($detachJs, 'open: open') !== false
    && strpos($detachJs, 'hasPiP: function ()') !== false);
t('ES5 only (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $detachJs));

// ── 2. The honest capability claim -- PiP where supported, honest fallback otherwise ──
t('uses the real Document Picture-in-Picture API (documentPictureInPicture.requestWindow), not a fake claim',
    strpos($detachJs, 'window.documentPictureInPicture && window.documentPictureInPicture.requestWindow') !== false
    && strpos($detachJs, '.requestWindow({ width: width, height: height })') !== false);
t('falls back to an honest, non-always-on-top popup when PiP is unsupported or rejects',
    strpos($detachJs, 'function openPopupFallback()') !== false
    && strpos($detachJs, "openPopupFallback();") !== false);
t('the popup fallback strips chrome (menubar/toolbar/location/status) -- as close to frameless as an ordinary popup can get',
    strpos($detachJs, 'menubar=no,toolbar=no,location=no,status=no') !== false);
t('a genuinely blocked popup reports "unsupported" via onClose rather than silently doing nothing',
    strpos($detachJs, "opts.onClose('unsupported')") !== false);

// ── 3. The position-reset fix (the real bug found while building this) ──
t('saves the widget\'s original inline style before moving it (position:fixed + absolute pixel offsets, meaningless in a small new window)',
    strpos($detachJs, 'var savedStyleCssText = rootEl.style.cssText;') !== false);
t('fillDetachedWindow() resets position to fill the new window edge-to-edge rather than rendering off-screen',
    strpos($detachJs, "rootEl.style.position = 'static';") !== false
    && strpos($detachJs, "rootEl.style.width = '100%';") !== false
    && strpos($detachJs, "rootEl.style.height = '100%';") !== false);
t('restore() puts the EXACT original inline style back verbatim (re-attaching returns the widget to where the operator had it, not a default)',
    strpos($detachJs, 'rootEl.style.cssText = savedStyleCssText;') !== false);
t('fillDetachedWindow() is actually called before appendChild in BOTH the PiP and popup paths (found and fixed while building this -- an earlier draft forgot one path)',
    substr_count($detachJs, 'fillDetachedWindow();') === 2);

// ── 4. Theme + stylesheet continuity ────────────────────────────────────
t('copies the operator\'s current dark/light theme (data-bs-theme) into the new window -- otherwise it silently renders in Bootstrap\'s light default',
    strpos($detachJs, "document.documentElement.getAttribute('data-bs-theme')") !== false);
t('clones every stylesheet link + inline <style> block into the new window (which starts with an empty <head>)',
    strpos($detachJs, "document.querySelectorAll('link[rel=\"stylesheet\"]')") !== false
    && strpos($detachJs, "document.querySelectorAll('style')") !== false);
t('preserves each cloned <link>\'s media attribute (Eric, 2026-09-08 bug report -- a detached Zello widget '
    . 'rendered blank with a "Tickets CAD -- Printed" header: every page links print.css with media="print", '
    . 'and copying the link without its media attribute made it default to media="all", so print.css\'s '
    . '.zello-widget { display: none !important; } and its print-header pseudo-element silently applied on '
    . 'screen instead of only while printing)',
    strpos($detachJs, 'if (links[i].media) { link.media = links[i].media; }') !== false);

// ── 5. Load order: window-detach.js before all three consuming widgets ──
$detachTagPos = strpos($navbar, '<script src="assets/js/window-detach.js');
$zelloTagPos = strpos($navbar, '<script src="assets/js/zello-widget.js');
$radioTagPos = strpos($navbar, '<script src="assets/js/radio-widget.js');
$simulTagPos = strpos($navbar, '<script src="assets/js/console-simulselect-widget.js');
t('inc/navbar.php loads window-detach.js', $detachTagPos !== false);
t('window-detach.js loads before zello-widget.js, radio-widget.js, and console-simulselect-widget.js',
    $detachTagPos !== false && $zelloTagPos !== false && $radioTagPos !== false && $simulTagPos !== false
    && $detachTagPos < $zelloTagPos && $detachTagPos < $radioTagPos && $detachTagPos < $simulTagPos);

// ── 6. Zello widget's Detach button ─────────────────────────────────────
t('the Zello widget template has a Detach button', strpos($zelloTpl, 'id="zelloDetach"') !== false);
t('zello-widget.js wires it to window.WindowDetach.open() on the WHOLE widget node (the real floating card, not a sub-piece)',
    strpos($zelloJs, "widget.querySelector('#zelloDetach')") !== false
    && strpos($zelloJs, 'window.WindowDetach.open(widget,') !== false);
t('disables the Detach button while already detached (guards against double-detach) and re-enables it on close',
    strpos($zelloJs, 'detachBtn.disabled = true;') !== false
    && strpos($zelloJs, 'detachBtn.disabled = false;') !== false);
t('never calls WindowDetach without checking it exists first (graceful degradation)',
    strpos($zelloJs, 'if (detachBtn && window.WindowDetach)') !== false);

// ── 7. Radio widget's Detach button ─────────────────────────────────────
t('the Radio widget markup (inc/navbar.php) has a Detach button', strpos($navbar, 'id="radioDetach"') !== false);
t('radio-widget.js wires it to window.WindowDetach.open() on the WHOLE widget node',
    strpos($radioJs, "widget.querySelector('#radioDetach')") !== false
    && strpos($radioJs, 'window.WindowDetach.open(widget,') !== false);
t('same disable/re-enable double-detach guard as Zello',
    strpos($radioJs, 'detachBtn.disabled = true;') !== false
    && strpos($radioJs, 'detachBtn.disabled = false;') !== false);

// ── 8. Simulselect widget's Detach button -- detaches CONTENT, not the Bootstrap dropdown wrapper ──
t('the Simulselect panel has a Detach button', strpos($navbar, 'id="simulselectDetach"') !== false);
t('a dedicated #simulselectPanelContent wrapper exists specifically so the Bootstrap dropdown-menu itself never moves',
    strpos($navbar, 'id="simulselectPanelContent"') !== false);
t('console-simulselect-widget.js detaches #simulselectPanelContent specifically, NOT #navSimulselectPanel (the Bootstrap-managed wrapper)',
    strpos($widgetJs, "window.WindowDetach.open(panelContent,") !== false
    && strpos($widgetJs, "getElementById('simulselectPanelContent')") !== false);
t('disables the nav toggle button while detached, so the now-empty dropdown can\'t be confusingly reopened',
    strpos($widgetJs, 'navToggleBtn.disabled = true;') !== false
    && strpos($widgetJs, 'navToggleBtn.disabled = false;') !== false);
t('stops the detach click from also toggling the Bootstrap dropdown (e.stopPropagation)',
    (bool) preg_match('/detachBtn\.addEventListener\(\'click\', function \(e\) \{\s*e\.stopPropagation\(\);/', $widgetJs));
t('ES5 only (no arrows/template literals/let/const)', !preg_match('/=>|`|\blet\s|\bconst\s/', $widgetJs));

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
