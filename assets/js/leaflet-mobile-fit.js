/**
 * Leaflet mobile-fit shim — Phase 70
 *
 * Whenever a Leaflet map's container changes size — initial layout
 * settling, address-bar collapse on iOS/Android, orientation change,
 * GridStack reflow on the dashboard — call invalidateSize() so the
 * tile canvas redraws into the new dimensions.
 *
 * Without this, maps initialised against a 0-height container (common
 * on mobile while the layout is still calculating viewport units)
 * render blank and stay blank.
 *
 * Loaded globally via navbar.php so every Leaflet map in the app
 * benefits. No-ops if Leaflet isn't on the page.
 */
(function () {
    'use strict';
    if (typeof L === 'undefined' || !L.Map) return;
    if (window._leafletMobileFitInstalled) return;
    window._leafletMobileFitInstalled = true;

    var instances = [];

    // Intercept map construction — every L.map(...) call is added to
    // the instance list so we can drive their invalidateSize() later.
    var origInit = L.Map.prototype.initialize;
    L.Map.prototype.initialize = function () {
        origInit.apply(this, arguments);
        instances.push(this);

        var map = this;
        var container = this.getContainer();
        if (!container) return;

        // Staggered invalidateSize for the initial render. Covers
        // viewport-unit calculations that don't finalise until after
        // the JS runs, plus slow phones.
        var fired = false;
        function poke() {
            if (!map._loaded) return;
            try { map.invalidateSize(true); fired = true; } catch (e) {}
        }
        setTimeout(poke, 100);
        setTimeout(poke, 400);
        setTimeout(poke, 1200);
        setTimeout(function () { if (!fired) poke(); }, 2500);

        // ResizeObserver — fires whenever the container's box changes.
        // This is the catch-all for GridStack reflow, orientation
        // changes, address-bar collapse, parent-flex resize, etc.
        if (typeof ResizeObserver !== 'undefined') {
            var ro = new ResizeObserver(function () {
                if (!map._loaded) return;
                try { map.invalidateSize(false); } catch (e) {}
            });
            try { ro.observe(container); } catch (e) {}
            // Stop observing when the map is removed.
            map.on('unload', function () { try { ro.disconnect(); } catch (e) {} });
        }
    };

    // Fallback: also invalidate every tracked map on window resize
    // and orientationchange — old browsers without ResizeObserver,
    // and as a belt-and-braces for the cases the observer misses.
    function pokeAll() {
        for (var i = 0; i < instances.length; i++) {
            var m = instances[i];
            if (m && m._loaded) {
                try { m.invalidateSize(false); } catch (e) {}
            }
        }
    }
    window.addEventListener('resize', pokeAll);
    window.addEventListener('orientationchange', pokeAll);
    // Address-bar collapse on mobile Chrome/Safari fires visualViewport
    // resize events that don't always trigger window resize.
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', pokeAll);
    }
})();
