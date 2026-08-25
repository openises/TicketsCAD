<?php
/**
 * GH#110 (rjonesbsink, 2026-08-25) — the "Save current layout as a
 * snapshot" dropdown (#snapshotMenu's .dropdown-menu, class
 * .snapshot-dropdown, index.php) rendered BEHIND the GridStack widget
 * content underneath it — no z-index was set on it at all, so whatever
 * stacking context the widget grid establishes (GridStack positions
 * widgets with CSS transforms, which create a new stacking context) won.
 *
 * Fixed by giving .snapshot-dropdown the same z-index (1055) already
 * used by two other elements in this codebase for exactly this same
 * "must clear GridStack widget content" problem: searchable-select.css
 * and radio-widget.css. This test pins that convention down — a value
 * that drifts below what those two other elements use is a regression
 * even if it's still numerically "high".
 *
 * No JS runtime / live rendering in CI, so this is a static-contract
 * check on the shipped CSS.
 */

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
function ok(string $what) { global $pass; echo "  PASS  $what\n"; $pass++; }
function bad(string $what, string $why = '') {
    global $fail; echo "  FAIL  $what" . ($why ? " — $why" : '') . "\n"; $fail++;
}

echo "=== GH#110: .snapshot-dropdown clears GridStack widget content ===\n\n";

$widgetsCss = file_get_contents($root . '/assets/css/widgets.css');

// ── 1. .snapshot-dropdown has an explicit z-index set. ────────────────────
if (preg_match('/\.snapshot-dropdown\s*\{([^}]*)\}/s', $widgetsCss, $m)) {
    if (preg_match('/z-index\s*:\s*(\d+)/', $m[1], $zm)) {
        ok('.snapshot-dropdown declares an explicit z-index (' . $zm[1] . ')');

        // ── 2. It matches this codebase's own established convention
        // (1055) for "must clear GridStack widget content" — not just any
        // positive number, which could still lose to a widget's own
        // stacking context depending on what that context's z-index is. ──
        $established = null;
        foreach (['assets/css/searchable-select.css', 'assets/css/radio-widget.css'] as $ref) {
            $c = file_get_contents($root . '/' . $ref);
            if (preg_match('/z-index\s*:\s*(\d+)/', $c, $rm)) {
                $established = (int) $rm[1];
                break;
            }
        }
        if ($established === null) {
            bad('found a reference z-index value from searchable-select.css/radio-widget.css to compare against', 'those files may have changed shape — re-derive the expected convention');
        } elseif ((int) $zm[1] === $established) {
            ok(".snapshot-dropdown's z-index ({$zm[1]}) matches this codebase's established GridStack-clearing convention ({$established}, from searchable-select.css/radio-widget.css)");
        } else {
            bad(".snapshot-dropdown's z-index ({$zm[1]}) does not match the established convention ({$established})", 'drifting from the shared convention risks the exact GH#110 symptom recurring under a different stacking-context arrangement');
        }
    } else {
        bad('.snapshot-dropdown has no z-index declared', 'GH#110 regression — the dropdown can render behind GridStack widget content again');
    }
} else {
    bad('found a .snapshot-dropdown rule block in assets/css/widgets.css', 'selector not found — did the rule move or get renamed?');
}

// ── 3. The class is actually applied to the real dropdown-menu element
// in the markup, not just declared in CSS with nothing to attach to. ──────
$indexPhp = file_get_contents($root . '/index.php');
if (preg_match('/class="dropdown-menu\s+snapshot-dropdown"/', $indexPhp)) {
    ok('index.php applies class="dropdown-menu snapshot-dropdown" to the real #snapshotMenu dropdown panel');
} else {
    bad('index.php does not apply snapshot-dropdown to a dropdown-menu element as expected', 're-verify the CSS fix actually reaches the live element');
}

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
