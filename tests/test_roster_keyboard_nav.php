<?php
/**
 * Roster page keyboard navigation (Eric, 2026-08-14): land with the search
 * field focused -> Tab into the results list (roving tabindex, one tab
 * stop, not one per row) -> ArrowUp/ArrowDown walk rows and load each one's
 * detail live -> Tab again jumps into the detail panel -> Escape returns to
 * the previous page.
 *
 * Static structural guards over assets/js/roster.js + assets/css/roster.css
 * (behavioral JS/CSS; no headless DOM in this suite). Usage:
 *   php tests/test_roster_keyboard_nav.php
 */

$base = realpath(__DIR__ . '/..');
$passed = 0; $failed = 0;
function t($label, $cond) { global $passed, $failed; echo ($cond ? "[PASS] " : "[FAIL] ") . $label . "\n"; $cond ? $passed++ : $failed++; }

echo "=== Roster keyboard navigation ===\n\n";

$js = @file_get_contents($base . '/assets/js/roster.js');
if ($js === false) { t('assets/js/roster.js readable', false); echo "\n=== $passed passed, $failed failed ===\n"; exit(1); }

// ── Roving tabindex on rows ──────────────────────────────────────────
t('renderTable() assigns a roving tabindex per row (selected or first row = "0", rest "-1")',
    (bool) preg_match('/var rowTabindex = \(isSelected \|\| \(!selectedId && i === 0\)\) \? .0. : .-1.;/', $js) &&
    strpos($js, "tabindex=\"' + rowTabindex + '\"") !== false);

// ── The stale-node bug: select MUST happen before re-querying the row ──
// selectMember() re-renders $tbody synchronously; focusing a row reference
// obtained BEFORE calling it silently no-ops on a detached node. This was
// caught and fixed during development (reverted once here to confirm the
// assertion below actually distinguishes the two orderings) — see the
// helper's own docblock for the full explanation.
t('_rosterSelectAndFocus() calls selectMember() BEFORE re-querying the row (not after)',
    (bool) preg_match('/function _rosterSelectAndFocus\(id\)\s*\{\s*selectMember\(id\);\s*var row = \$tbody\.querySelector/', $js));

// ── Search field: Tab enters the list ──────────────────────────────────
t('search field Tab handler exists and prevents default browser tab order',
    (bool) preg_match('/\$searchInput\.addEventListener\(.keydown., function \(e\) \{\s*if \(e\.key !== .Tab. \|\| e\.shiftKey\) return;/', $js));
t('search-Tab handler falls back to _rosterActiveRow() (roving focus row, or first row)',
    strpos($js, 'var row = _rosterActiveRow();') !== false);

// ── Arrow keys walk the list, clamped (no wrap) ─────────────────────────
t('ArrowDown/ArrowUp handler exists on $tbody',
    (bool) preg_match('/\$tbody\.addEventListener\(.keydown., function \(e\) \{/', $js));
t('arrow-key navigation clamps at the ends instead of wrapping',
    (bool) preg_match('/if \(next < 0 \|\| next >= rows\.length\) return; \/\/ clamp, no wrap/', $js));
t('ArrowDown/ArrowUp re-selects the newly-focused row (live preview, mail-client style)',
    (bool) preg_match('/var next = e\.key === .ArrowDown. \? idx \+ 1 : idx - 1;[\s\S]{0,200}_rosterSelectAndFocus\(parseInt\(nextId, 10\)\)/', $js));

// ── Tab from a row jumps into the detail panel ──────────────────────────
t('Tab from a focused row targets the detail panel (whichever of detailView/detailEmpty is showing)',
    (bool) preg_match('/var target = \$detailView\.classList\.contains\(.d-none.\) \? \$detailEmpty : \$detailView;/', $js));
t('the detail-panel jump target gets tabindex="-1" (programmatically focusable, not in normal tab order)',
    strpos($js, "target.setAttribute('tabindex', '-1')") !== false);
t('focusing the detail panel also explicitly scrolls it into view',
    strpos($js, "target.scrollIntoView({behavior: 'smooth', block: 'start'})") !== false);

// ── Escape returns to the previous page, but defers to more specific owners ──
t('Escape handler defers to the edit view\'s own Escape-to-cancel when editing',
    (bool) preg_match('/if \(e\.key !== .Escape.\) return;\s*if \(\$editView && !\$editView\.classList\.contains\(.d-none.\)\) return;/', $js));
t('Escape handler defers to an open Bootstrap modal (which closes itself on Escape)',
    strpos($js, "if (document.querySelector('.modal.show')) return;") !== false);
t('Escape otherwise navigates back', strpos($js, 'window.history.back();') !== false);

// ── Auto-focus the search field on landing, except when deep-linked ────
// The guard references deepLinkMemberId BY NAME in the same statement that
// calls focus() -- proving both that the call is conditional on it AND
// that it's a real (not forward-referenced) use, since normal top-to-bottom
// execution inside init() means the var must already exist by this line.
t('init() focuses the search field on load, but only when NOT deep-linked to a specific member',
    (bool) preg_match('/if \(!deepLinkMemberId && \$searchInput\) \{ \$searchInput\.focus\(\); \}/', $js));

// ── Mouse clicks stay in sync with the roving tabindex ──────────────────
t('row click handler routes through the same select-then-requery helper as the keyboard handlers',
    (bool) preg_match('/\$tbody\.addEventListener\(.click., function \(e\) \{[\s\S]{0,600}_rosterSelectAndFocus\(parseInt\(id, 10\)\)/', $js));

// ── CSS: the roving-focus row must be visually distinguishable ─────────
$css = @file_get_contents($base . '/assets/css/roster.css');
t('assets/css/roster.css readable', $css !== false);
if ($css !== false) {
    t('roster.css defines an explicit :focus-visible ring for .roster-row',
        (bool) preg_match('/\.roster-row:focus-visible\s*\{[^}]*outline:/s', $css));
}

echo "\n=== $passed passed, $failed failed ===\n";
exit($failed === 0 ? 0 : 1);
