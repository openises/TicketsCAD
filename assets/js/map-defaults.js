/**
 * Shared map-defaults loader. Every Leaflet map initializer in the app
 * should use this instead of hardcoding [lat, lng] / zoom.
 *
 * Single canonical source: api/map-config.php (which itself handles the
 * legacy/modern settings-key drift server-side). Result is cached per
 * page load so multiple maps on the same page only fetch once.
 *
 * Usage:
 *   MapDefaults.load().then(function (d) {
 *       var map = L.map('foo').setView([d.lat, d.lng], d.zoom);
 *   });
 *
 *   // Use of MapDefaults.HARDCODED is reserved for the genuine
 *   // failure-mode fallback inside a .catch() handler — don't
 *   // reach for it directly when you can wait for load().
 *
 * The hardcoded fallback ([44.9778, -93.2650] / zoom 12) used to be
 * scattered across 5+ files; this module is the only place it lives now.
 * Beta tester a beta tester 2026-06-26 reported that several maps in
 * the app ignored the configured Map Settings and showed Minneapolis
 * instead. This loader is the systemic fix.
 */
(function () {
    'use strict';

    var HARDCODED = { lat: 44.9778, lng: -93.2650, zoom: 12 };
    var _cachedPromise = null;

    function load() {
        if (_cachedPromise) return _cachedPromise;
        _cachedPromise = fetch('api/map-config.php', { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                // api/map-config.php returns def_lat / def_lng / def_zoom
                // (server-side normalizes between the legacy 'def_*' keys
                // and the modern 'default_*' keys).
                var lat = parseFloat(data && data.def_lat);
                var lng = parseFloat(data && data.def_lng);
                var zoom = parseInt(data && data.def_zoom, 10);
                return {
                    lat:  isFinite(lat)  ? lat  : HARDCODED.lat,
                    lng:  isFinite(lng)  ? lng  : HARDCODED.lng,
                    zoom: isFinite(zoom) ? zoom : HARDCODED.zoom
                };
            })
            .catch(function () {
                // Network / parse error — return the hardcoded fallback
                // so the calling map still initializes. Operator can
                // recenter manually.
                return HARDCODED;
            });
        return _cachedPromise;
    }

    // Clear the cache (rare — useful if Map Settings was just saved and
    // a panel re-opens without a full page reload).
    function clearCache() {
        _cachedPromise = null;
    }

    window.MapDefaults = {
        load: load,
        clearCache: clearCache,
        HARDCODED: HARDCODED
    };
})();
