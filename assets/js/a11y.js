/*
 * NewUI v4.0 — Accessibility shim (Phase 44)
 *
 * One global handler that fixes the Sonar Web:MouseEventWithoutKeyboardEquivalentCheck
 * findings sitewide without editing 62 individual HTML elements.
 *
 * Pattern: every Bootstrap collapse / dropdown / tab / offcanvas trigger written
 * as <div data-bs-toggle="collapse" role="button"> needs two things to be
 * fully keyboard-accessible:
 *   1. A tabindex so a keyboard user can Tab to it.
 *   2. A keydown handler that triggers .click() on Enter or Space.
 *
 * This shim sets both at runtime. Zero per-element HTML changes required —
 * any future <div data-bs-toggle="..."> automatically inherits the fix.
 *
 * Loaded from inc/navbar.php so it runs on every authenticated page. Also
 * loaded directly on login.php for the theme-picker buttons.
 *
 * Wrapped in an IIFE for the project's ES5-only convention.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-bs-toggle]';

    function makeFocusable() {
        var nodes = document.querySelectorAll(SELECTOR);
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            // Don't override an explicit tabindex; don't touch elements that
            // are already natively focusable (button, a[href], input, etc.).
            if (el.hasAttribute('tabindex')) continue;
            var tag = el.tagName.toLowerCase();
            if (tag === 'button' || tag === 'input' || tag === 'select' || tag === 'textarea') continue;
            if (tag === 'a' && el.hasAttribute('href')) continue;
            el.setAttribute('tabindex', '0');
            // Add an aria-role hint if none present so screen readers
            // announce the element as interactive.
            if (!el.hasAttribute('role')) {
                el.setAttribute('role', 'button');
            }
        }
    }

    // Document-level delegated keydown: if a focused element has data-bs-toggle
    // and the key is Enter or Space, trigger the click.
    function onKeydown(e) {
        if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
        var t = e.target;
        if (!t || !t.hasAttribute || !t.hasAttribute('data-bs-toggle')) return;
        // Don't double-fire on elements that already handle Enter natively
        // (buttons, links). Bootstrap dispatchers Tag for those.
        var tag = t.tagName.toLowerCase();
        if (tag === 'button' || (tag === 'a' && t.hasAttribute('href'))) return;
        e.preventDefault();
        t.click();
    }

    // Observe the DOM for elements injected after page load (modals, dynamic
    // panels, etc.) and re-run makeFocusable on them too.
    function observe() {
        if (typeof MutationObserver === 'undefined') return;
        var obs = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (!m.addedNodes || !m.addedNodes.length) continue;
                // Cheap re-scan rather than per-node walk; the selector is fast.
                makeFocusable();
                return;
            }
        });
        obs.observe(document.body, { childList: true, subtree: true });
    }

    // Phase 44 — auto-id the first <main> as main-content so the skip-link in
    // navbar.php has something to land on, without editing every page's HTML.
    // If the page already has #main-content (custom id), leave it. If there's
    // no <main>, fall back to the first .container after the header.
    function ensureSkipTarget() {
        if (document.getElementById('main-content')) return;
        var target = document.querySelector('main')
            || document.querySelector('header ~ .container, header ~ .container-fluid, header ~ div');
        if (target) {
            target.id = 'main-content';
            // tabindex="-1" lets it receive focus programmatically without
            // entering the natural Tab order.
            if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
        }
    }

    function init() {
        makeFocusable();
        ensureSkipTarget();
        document.addEventListener('keydown', onKeydown);
        observe();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
