/**
 * MapImageOverlays — Special-event map image overlays (Phase 110 / GH #43).
 *
 * Clean-room rotated image overlay for Leaflet (no vendored plugin): an
 * <img> is positioned in a dedicated map pane with a CSS 2D matrix
 * transform computed from three geo-anchored corner points (top-left,
 * top-right, bottom-left). The fourth corner follows for free — the
 * transform is affine, so the image can be translated, scaled, rotated
 * and sheared by dragging the three anchors.
 *
 * Z-order guarantee (the heart of GH #43): the custom 'eventImagePane'
 * sits at zIndex 350 — above the base tile pane (200), below the
 * overlay pane (400) that holds markups, and far below markers (600).
 * Base tiles < event image < markups < unit + incident icons.
 *
 * Usage:
 *   MapImageOverlays.attach(map, layersControl);       // list pages
 *   var lyr = MapImageOverlays.makeLayer(url, anchors, // editor
 *                 { opacity: 0.7 });
 *   lyr.setAnchors({tl:{lat,lng}, tr:{...}, bl:{...}});
 *   lyr.setOpacity(0.5);
 *
 * Overlay visibility persists to the shared 'newui_map_layers'
 * localStorage key — the same key the markup-category overlays in
 * map-prefs.js use — so toggling an event map at the situation view
 * keeps it on at unit-detail too.
 */
(function () {
    'use strict';

    var PANE_NAME = 'eventImagePane';
    var PREFS_KEY = 'newui_map_layers';

    function safeEsc(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Create the shared event-image pane on a map (once per map).
     * zIndex 350: above tilePane (200), below overlayPane (400).
     */
    function ensurePane(map) {
        if (!map.getPane(PANE_NAME)) {
            var pane = map.createPane(PANE_NAME);
            pane.style.zIndex = 350;
            pane.style.pointerEvents = 'none';
        }
        return map.getPane(PANE_NAME);
    }

    /**
     * RotatedImageLayer — L.Layer subclass that renders an <img> whose
     * corners track three latlng anchors via a CSS matrix() transform.
     *
     * Math: project the anchors to layer points p0 (tl), p1 (tr), p2 (bl).
     * With natural image size w x h and transform-origin 0 0,
     * matrix(a,b,c,d,e,f) maps image pixel (x,y) → (a*x + c*y + e,
     * b*x + d*y + f). We want (0,0)→p0, (w,0)→p1, (0,h)→p2, so:
     *   a = (p1.x - p0.x) / w      b = (p1.y - p0.y) / w
     *   c = (p2.x - p0.x) / h      d = (p2.y - p0.y) / h
     *   e = p0.x                   f = p0.y
     */
    var RotatedImageLayer = L.Layer.extend({

        initialize: function (url, anchors, opts) {
            opts = opts || {};
            this._url = url;
            this._anchors = anchors || null;
            this._opacity = (typeof opts.opacity === 'number') ? opts.opacity : 0.7;
            this._imgLoaded = false;
        },

        onAdd: function (map) {
            this._map = map;
            ensurePane(map);
            if (!this._img) {
                var img = L.DomUtil.create('img', 'event-image-overlay');
                img.style.position = 'absolute';
                img.style.transformOrigin = '0 0';
                img.style.webkitTransformOrigin = '0 0';
                img.style.pointerEvents = 'none';
                img.style.maxWidth = 'none';   // defeat any global img { max-width } rules
                img.style.maxHeight = 'none';
                img.style.opacity = String(this._opacity);
                img.alt = '';
                var self = this;
                img.onload = function () {
                    self._imgLoaded = true;
                    self._reset();
                };
                img.src = this._url;
                this._img = img;
            }
            map.getPane(PANE_NAME).appendChild(this._img);
            map.on('zoom viewreset move zoomend', this._reset, this);
            this._reset();
            return this;
        },

        onRemove: function (map) {
            map.off('zoom viewreset move zoomend', this._reset, this);
            if (this._img && this._img.parentNode) {
                this._img.parentNode.removeChild(this._img);
            }
            this._map = null;
            return this;
        },

        /** Live opacity update (editor slider). */
        setOpacity: function (v) {
            this._opacity = v;
            if (this._img) {
                this._img.style.opacity = String(v);
            }
            return this;
        },

        /** Live anchor update (editor drag handles). */
        setAnchors: function (anchors) {
            this._anchors = anchors;
            this._reset();
            return this;
        },

        getAnchors: function () {
            return this._anchors;
        },

        /** The <img> element (editor reads naturalWidth/Height). */
        getElement: function () {
            return this._img;
        },

        _reset: function () {
            if (!this._map || !this._img || !this._imgLoaded || !this._anchors) return;
            var a = this._anchors;
            if (!a.tl || !a.tr || !a.bl) return;

            var p0 = this._map.latLngToLayerPoint(L.latLng(a.tl.lat, a.tl.lng));
            var p1 = this._map.latLngToLayerPoint(L.latLng(a.tr.lat, a.tr.lng));
            var p2 = this._map.latLngToLayerPoint(L.latLng(a.bl.lat, a.bl.lng));

            var w = this._img.naturalWidth || 1;
            var h = this._img.naturalHeight || 1;

            // Pin the element at its natural size so the matrix scale
            // factors (which divide by w/h) land the corners exactly.
            this._img.style.width = w + 'px';
            this._img.style.height = h + 'px';

            var mA = (p1.x - p0.x) / w;
            var mB = (p1.y - p0.y) / w;
            var mC = (p2.x - p0.x) / h;
            var mD = (p2.y - p0.y) / h;
            var t = 'matrix(' + mA + ',' + mB + ',' + mC + ',' + mD + ',' + p0.x + ',' + p0.y + ')';
            this._img.style.transform = t;
            this._img.style.webkitTransform = t;
        }
    });

    /**
     * Factory: build a rotated image layer.
     * @param {string} url      image URL (uploads/overlays/...)
     * @param {object} anchors  {tl:{lat,lng}, tr:{lat,lng}, bl:{lat,lng}} or null
     * @param {object} [opts]   { opacity: 0..1 }
     * @returns {L.Layer}
     */
    function makeLayer(url, anchors, opts) {
        return new RotatedImageLayer(url, anchors, opts);
    }

    /**
     * Attach all enabled + positioned event image overlays to a map's
     * layer control. Mirrors map-prefs.js attachMarkupOverlays: reads the
     * saved active-overlay names from the shared 'newui_map_layers'
     * localStorage key, auto-enables overlays the operator had on
     * elsewhere, and persists toggles back merged with whatever other
     * overlay names (markup categories, Weather, ...) are already saved.
     *
     * @param {L.Map} mapInstance
     * @param {L.Control.Layers} layersControl
     */
    function attach(mapInstance, layersControl) {
        if (!mapInstance || !layersControl) return;
        if (mapInstance._imageOverlaysAttached) return;
        mapInstance._imageOverlaysAttached = true;

        // Saved active-overlay names (shared with markup overlays / weather).
        var savedActive = {};
        try {
            var s = JSON.parse(localStorage.getItem(PREFS_KEY) || 'null');
            if (s && Object.prototype.toString.call(s.overlays) === '[object Array]') {
                s.overlays.forEach(function (n) { savedActive[n] = true; });
            }
        } catch (e) { /* ignore */ }

        // label -> layer, so save/restore matches the display name exactly.
        var imgLayers = {};

        fetch('api/map-image-overlays.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { overlays: [] }; })
            .then(function (data) {
                var list = (data && data.overlays) || [];
                list.forEach(function (ov) {
                    if (!ov || parseInt(ov.enabled, 10) !== 1) return;
                    var a = ov.anchors;
                    if (!a || !a.tl || !a.tr || !a.bl) return; // not positioned yet
                    var layer = makeLayer(ov.url, a, { opacity: parseFloat(ov.opacity) || 0.7 });
                    var label = '🖼 ' + safeEsc(ov.name);
                    imgLayers[label] = layer;
                    layersControl.addOverlay(layer, label);
                    if (savedActive[label]) {
                        layer.addTo(mapInstance);
                    }
                });
            })
            .catch(function () { /* graceful — map still works without overlays */ });

        // Persist toggles, merged with non-image overlay names already saved.
        function saveActive() {
            var active = [];
            Object.keys(imgLayers).forEach(function (label) {
                if (mapInstance.hasLayer(imgLayers[label])) active.push(label);
            });
            try {
                var prev = JSON.parse(localStorage.getItem(PREFS_KEY) || '{}');
                var prevOverlays = Object.prototype.toString.call(prev.overlays) === '[object Array]'
                    ? prev.overlays : [];
                var merged = prevOverlays.filter(function (n) {
                    return !imgLayers.hasOwnProperty(n);
                }).concat(active);
                prev.overlays = merged;
                localStorage.setItem(PREFS_KEY, JSON.stringify(prev));
            } catch (e) { /* ignore */ }
        }
        mapInstance.on('overlayadd', saveActive);
        mapInstance.on('overlayremove', saveActive);
    }

    window.MapImageOverlays = {
        makeLayer: makeLayer,
        attach: attach,
        ensurePane: ensurePane
    };
})();
