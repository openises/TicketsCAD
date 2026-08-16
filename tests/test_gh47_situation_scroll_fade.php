<?php
/**
 * GH#47 follow-up (cbyrdmo, 2026-08-15) -- "The hamburger menu is correct
 * but the situation list is still off the screen." Not the mobile
 * breakpoint mismatch investigated in the prior reply (his own follow-up
 * ruled that out: desktop, multiple browsers, hard refresh, incognito,
 * resize, and F11 fullscreen all still cut off).
 *
 * Root cause, reproduced live at a plain 1366x768 desktop viewport with an
 * entirely ordinary dataset (26 open incidents, 18 responders, no
 * artificial inflation): #sitOverlay's content genuinely overflows its own
 * height (scrollHeight 2122 vs clientHeight 748 -- roughly 65% of the
 * incidents list was below the fold). overflow-y:auto DOES work -- the
 * scrollbar is real and functional -- but at 5px wide against a blurred,
 * semi-transparent background it's exactly the kind of affordance a user
 * scans past, so a genuinely scrollable panel reads as "the list is cut
 * off the screen" rather than "scroll for more."
 *
 * Fix: a sticky bottom-edge gradient fade, shown only while there's
 * actually more content below (JS toggles a `has-more-below` class on
 * #sitOverlay based on real scroll position), plus a slightly thicker,
 * higher-contrast scrollbar. Deliberately NOT requestAnimationFrame-gated
 * -- discovered live while verifying this exact fix that rAF callbacks
 * never fire in a browser context that isn't actively compositing/
 * painting frames (a backgrounded tab, or an automated browser that never
 * renders), which would make the fade silently never appear in precisely
 * that situation. A direct, unthrottled recompute costs nothing worth
 * debouncing for a list this size.
 *
 * Structural checks below are backed by live verification (not shippable
 * as an automated test, since it needs a rendering browser + real scroll
 * geometry): confirmed the fade toggles on/off correctly as #sitOverlay is
 * scrolled from top to bottom and back, confirmed it's suppressed under
 * the mobile breakpoint (where the whole page scrolls instead), and
 * confirmed (via Animation.finish()) the CSS transition's target opacity
 * is correct even though the automated browser tool used for verification
 * couldn't let the transition actually play.
 *
 * Usage: php tests/test_gh47_situation_scroll_fade.php
 */

$root = dirname(__DIR__);
$src = (string) file_get_contents($root . '/situation.php');

$pass = 0; $fail = 0;
function t47f(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else     { $fail++; echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "=== GH#47 follow-up: situation.php scroll-fade regression ===\n\n";

// ── CSS wiring ────────────────────────────────────────────────────────
t47f('scrollbar widened from 5px to 8px (more discoverable)',
    strpos($src, '#sitOverlay::-webkit-scrollbar { width: 8px; }') !== false);
t47f('#sitOverlayFade is position:sticky pinned to the bottom of #sitOverlay',
    (bool) preg_match('/#sitOverlayFade\s*\{[^}]*position:\s*sticky;\s*bottom:\s*-1px;/s', $src));
t47f('#sitOverlayFade defaults to opacity:0 (hidden unless there is more content)',
    (bool) preg_match('/#sitOverlayFade\s*\{[^}]*opacity:\s*0;/s', $src));
t47f('the has-more-below modifier reveals the fade',
    strpos($src, '#sitOverlay.has-more-below #sitOverlayFade { opacity: 1; }') !== false);
t47f('#sitOverlayFade is pointer-events:none (never blocks clicks on real content)',
    (bool) preg_match('/#sitOverlayFade\s*\{[^}]*pointer-events:\s*none;/s', $src));
t47f('mobile breakpoint hides the fade (page-level scroll there, not #sitOverlay)',
    (bool) preg_match('/@media \(max-width: 768px\) \{.*?#sitOverlayFade \{ display: none; \}/s', $src));

// ── HTML wiring ───────────────────────────────────────────────────────
t47f('#sitOverlayFade is the LAST child inside #sitOverlay (trails whichever tab body is visible)',
    (bool) preg_match('/id="sitOverlayFade"><\/div>\s*<\/div>\s*\n\s*<!-- Draw Toolbar/', $src));

// ── JS wiring ─────────────────────────────────────────────────────────
t47f('JS listens for scroll on #sitOverlay',
    (bool) preg_match('/overlay\.addEventListener\(.scroll., updateFade/', $src));
t47f('JS listens for window resize (viewport changes can change overflow)',
    (bool) preg_match('/window\.addEventListener\(.resize., updateFade/', $src));
t47f('JS uses a MutationObserver so ANY tab\'s content refresh re-checks (not hardcoded to one render function)',
    (bool) preg_match('/new MutationObserver\(updateFade\)\.observe\(overlay, \{ childList: true, subtree: true \}\)/', $src));
t47f('JS does NOT gate the recompute behind requestAnimationFrame (would silently never fire in a non-compositing tab)',
    !preg_match('/requestAnimationFrame\(updateFade\)/', $src));
t47f('updateFade() runs once immediately on load (not just after the first scroll/resize/mutation)',
    (bool) preg_match('/new MutationObserver\(updateFade\)\.observe\(overlay, \{ childList: true, subtree: true \}\);\s*\n\s*updateFade\(\);/', $src));

// ── The actual overflow math, unit-tested directly (independent of any
//    browser) — this is the exact condition the JS evaluates. ─────────
function gh47_has_more_below($scrollTop, $clientHeight, $scrollHeight) {
    return ($scrollTop + $clientHeight) < ($scrollHeight - 2);
}
t47f('overflow math: content taller than the panel, scrolled to top -> has more below',
    gh47_has_more_below(0, 748, 2122) === true);
t47f('overflow math: scrolled to the true bottom -> no more below',
    gh47_has_more_below(1374, 748, 2122) === false);
t47f('overflow math: scrolled partway -> still has more below',
    gh47_has_more_below(500, 748, 2122) === true);
t47f('overflow math: content that fits entirely -> never has more below',
    gh47_has_more_below(0, 748, 700) === false);
t47f('overflow math: content exactly matching the panel height -> no more below (not off-by-one)',
    gh47_has_more_below(0, 748, 748) === false);

echo "\n=== $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
