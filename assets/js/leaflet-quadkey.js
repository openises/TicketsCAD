/**
 * leaflet-quadkey.js — Bing-style quadkey tile layer + a quadkey-aware
 * tile-layer factory for TicketsCAD NewUI.
 *
 * Leaflet core only understands {z}/{x}/{y} tile URLs. Bing Maps (and a
 * few other providers) pack zoom/x/y into a single base-4 "quadkey"
 * string and expose subdomains t0..t7. This file adds:
 *
 *   L.TileLayer.QuadKey  — a TileLayer subclass whose getTileUrl()
 *                          substitutes {q} with the computed quadkey.
 *   window.makeTileLayer(url, opts) — returns a QuadKey layer when the
 *                          URL template contains {q}, otherwise a plain
 *                          L.tileLayer. This is the single factory the
 *                          map registry (map-prefs.js) and the Settings
 *                          tile preview (config.js) both call so quadkey
 *                          support lives in exactly one place.
 *   window.tileXYToQuadKey(x, y, z) — the pure computation, exported so
 *                          it can be unit-tested against known Bing
 *                          vectors without a DOM or Leaflet.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 *
 * Spec: specs/configurable-tile-providers-2026-06/ (Phase B core).
 */
(function () {
    'use strict';

    /**
     * Convert tile x/y/z to a Bing quadkey string.
     *
     * For each level from z down to 1, OR the corresponding bit of x and
     * y into a base-4 digit (x bit -> +1, y bit -> +2) and append it.
     *
     * Known vectors:
     *   tileXYToQuadKey(0, 0, 1) === "0"
     *   tileXYToQuadKey(1, 1, 1) === "3"
     *   tileXYToQuadKey(3, 5, 3) === "213"
     *   tileXYToQuadKey(0, 0, 0) === ""   (whole world, no digits)
     *
     * @param {number} x tile column
     * @param {number} y tile row
     * @param {number} z zoom level
     * @returns {string} the quadkey
     */
    function tileXYToQuadKey(x, y, z) {
        var quadKey = '';
        var i;
        var digit;
        var mask;
        for (i = z; i > 0; i--) {
            digit = 0;
            mask = 1 << (i - 1);
            if ((x & mask) !== 0) {
                digit += 1;
            }
            if ((y & mask) !== 0) {
                digit += 2;
            }
            quadKey += String(digit);
        }
        return quadKey;
    }

    // Expose the pure computation for unit testing and any other caller.
    window.tileXYToQuadKey = tileXYToQuadKey;

    // Only define the Leaflet layer + factory when Leaflet is present.
    // (The computation above is still exported for tests that stub L.)
    if (typeof L === 'undefined' || !L.TileLayer) {
        // Provide a minimal factory fallback so callers don't throw if
        // they reference makeTileLayer before Leaflet loads. Returns null;
        // callers already null-check layers.
        if (typeof window.makeTileLayer !== 'function') {
            window.makeTileLayer = function () { return null; };
        }
        return;
    }

    /**
     * L.TileLayer.QuadKey — a tile layer that fills the {q} placeholder
     * with the Bing quadkey for the requested tile. Bing's default
     * subdomains are t0..t7; the {s} placeholder is handled by Leaflet's
     * own subdomain rotation (pass subdomains '01234567' so {s}
     * becomes 0..7 and the URL template uses t{s}).
     */
    L.TileLayer.QuadKey = L.TileLayer.extend({
        getTileUrl: function (coords) {
            var data = {
                r: (L.Browser && L.Browser.retina) ? '@2x' : '',
                s: this._getSubdomain(coords),
                q: tileXYToQuadKey(coords.x, coords.y, this._getZoomForUrl()),
                x: coords.x,
                y: coords.y,
                z: this._getZoomForUrl()
            };
            // Honor TMS y-flip if the layer was configured with it.
            if (this._map && !this.options.tms) {
                data.y = coords.y;
            }
            return L.Util.template(this._url, L.extend(data, this.options));
        }
    });

    // Expose the computation on the class too, so tests / callers can
    // reach it via L.TileLayer.QuadKey.tileXYToQuadKey.
    L.TileLayer.QuadKey.tileXYToQuadKey = tileXYToQuadKey;

    /**
     * Factory: quadkey-aware tile-layer constructor.
     *
     * @param {string} url  tile URL template. If it contains {q} a
     *                      QuadKey layer is returned; otherwise a plain
     *                      L.tileLayer.
     * @param {object} opts Leaflet TileLayer options. For Bing {q}+t{s}
     *                      URLs the caller should pass subdomains:'01234567'.
     * @returns {L.TileLayer}
     */
    window.makeTileLayer = function (url, opts) {
        opts = opts || {};
        if (typeof url === 'string' && url.indexOf('{q}') !== -1) {
            return new L.TileLayer.QuadKey(url, opts);
        }
        return L.tileLayer(url, opts);
    };
})();
