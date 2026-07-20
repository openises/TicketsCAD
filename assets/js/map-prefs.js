/**
 * MapPrefs — Shared basemap preference helper for all map pages.
 *
 * Reads per-theme basemap preference from localStorage ('ticketsMapPrefs')
 * and provides a tile layer URL + options for the current theme.
 *
 * Usage:
 *   var pref = MapPrefs.getBasemap();          // 'street' | 'dark' | 'terrain' | 'satellite'
 *   var tile = MapPrefs.createTileLayer();      // L.tileLayer ready to addTo(map)
 *   MapPrefs.addDefaultBasemap(map);            // convenience: create + addTo
 *   MapPrefs.setBasemap('dark', 'street');      // save preference
 *   MapPrefs.addLayerControl(map);              // attach basemap+weather switcher
 *
 * Detail pages (unit-detail, facility-detail, incident-detail) used to
 * inherit only the single preferred basemap from addDefaultBasemap()
 * with no way to switch. Calling addLayerControl(map) on top of that
 * gives the user the same Street/Dark/Terrain/Satellite picker and
 * weather toggles the units listing page has.
 */
(function () {
    'use strict';

    var PREFS_KEY = 'ticketsMapPrefs';

    var TILE_DEFS = {
        street: {
            label: 'Street',
            url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            opts: { attribution: '&copy; OSM', maxZoom: 19 }
        },
        dark: {
            label: 'Dark',
            url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
            opts: { attribution: '&copy; CartoDB', maxZoom: 19 }
        },
        terrain: {
            label: 'Terrain',
            url: 'https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png',
            opts: { attribution: '&copy; OpenTopoMap', maxZoom: 17 }
        },
        satellite: {
            label: 'Satellite',
            // Esri's World Imagery — free for non-commercial use, no key,
            // global coverage. Matches what most operators expect from
            // a "satellite" basemap option.
            url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            opts: { attribution: 'Tiles &copy; Esri', maxZoom: 19 }
        },
        light: {
            label: 'Light',
            url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png',
            opts: { attribution: '&copy; CartoDB', maxZoom: 19 }
        }
    };

    function readPrefs() {
        try { return JSON.parse(localStorage.getItem(PREFS_KEY)) || {}; } catch (e) { return {}; }
    }

    function writePrefs(prefs) {
        try { localStorage.setItem(PREFS_KEY, JSON.stringify(prefs)); } catch (e) { /* ignore */ }
    }

    // ── Configurable tile provider (specs/configurable-tile-providers-2026-06) ──
    //
    // On first map-touch we fetch api/map-config.php once and, if an admin has
    // configured a tile provider, merge it into TILE_DEFS under the 'custom'
    // key so every map that uses MapPrefs gains it as a selectable base layer.
    // The fetch is best-effort: if it fails, TILE_DEFS keeps only the built-in
    // basemaps (graceful degradation — constitution §defensive).
    var _configFetched = false;
    var _configPromise = null;

    /**
     * Substitute the API-key placeholders in a tile URL template.
     * Bing/Mapbox tile keys are designed to be client-visible in the tile
     * URL, so doing this on the client is fine (and matches the preview).
     */
    function applyApiKey(url, key) {
        if (!url || !key) return url;
        return url.replace('{key}', key).replace('{access_token}', key);
    }

    /**
     * Build the Leaflet options for the configured custom provider,
     * including Bing t0..t7 subdomains when the template uses t{s}.
     */
    function customOpts(cfg) {
        var opts = { attribution: 'Configured provider', maxZoom: 19 };
        // Bing-style t{s} subdomains run 0..7; google mt{s} run 0..3.
        if (cfg.tile_url && cfg.tile_url.indexOf('t{s}') !== -1) {
            opts.subdomains = '01234567';
        } else if (cfg.tile_url && cfg.tile_url.indexOf('mt{s}') !== -1) {
            opts.subdomains = '0123';
        }
        return opts;
    }

    /**
     * Merge the configured provider into TILE_DEFS as the 'custom' entry.
     * Idempotent. Resolves the {key}/{access_token} placeholder so the
     * stored template + key become a ready-to-use URL.
     */
    function mergeCustomProvider(cfg) {
        if (!cfg || !cfg.has_custom_tile || !cfg.tile_url) return;
        var url = applyApiKey(cfg.tile_url, cfg.tile_api_key || '');
        var label = 'Configured';
        // Use a friendlier label for known providers.
        if (cfg.tile_provider && cfg.tile_provider !== 'custom') {
            label = cfg.tile_provider
                .replace(/_/g, ' ')
                .replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        }
        TILE_DEFS.custom = {
            label: label,
            url: url,
            opts: customOpts(cfg)
        };
    }

    /**
     * Fetch map-config once and merge the configured provider. Returns a
     * promise that always resolves (never rejects) so callers can await it
     * without try/catch. Subsequent calls reuse the same promise.
     */
    function loadConfig() {
        if (_configPromise) return _configPromise;
        _configPromise = new Promise(function (resolve) {
            if (typeof fetch !== 'function') { _configFetched = true; resolve(); return; }
            fetch('api/map-config.php', { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (cfg) {
                    if (cfg) { mergeCustomProvider(cfg); }
                    _configFetched = true;
                    resolve();
                })
                .catch(function () {
                    // Network/parse failure — keep built-in basemaps only.
                    _configFetched = true;
                    resolve();
                });
        });
        return _configPromise;
    }

    /**
     * Quadkey-aware layer constructor. Delegates to window.makeTileLayer
     * (from leaflet-quadkey.js) when present so {q} Bing URLs render; falls
     * back to plain L.tileLayer if that script didn't load.
     */
    function buildLayer(url, opts) {
        if (typeof window.makeTileLayer === 'function') {
            return window.makeTileLayer(url, opts);
        }
        return L.tileLayer(url, opts);
    }

    // Shared RainViewer radar layer (Eric 2026-07-04). Returns an empty
    // tile layer and asynchronously points it at the newest radar frame,
    // re-checking every 5 minutes so a long-lived map stays current
    // through a storm. Free, no API key. Mirrors situation.php's radar.
    // We keep a single frame-refresh timer per page (module-level) so N
    // maps sharing the page don't each spin their own poll.
    var _radarTimer = null;
    var _radarLayers = [];
    function makeRadarLayer() {
        var layer = L.tileLayer('', { opacity: 0.7, maxZoom: 19, errorTileUrl: '' });
        _radarLayers.push(layer);
        function refreshRadar() {
            fetch('https://api.rainviewer.com/public/weather-maps.json')
                .then(function (r) { return r.json(); })
                .then(function (cat) {
                    var frames = (cat && cat.radar && cat.radar.past) || [];
                    if (!frames.length) return;
                    var latest = frames[frames.length - 1];
                    var host = cat.host || 'https://tilecache.rainviewer.com';
                    var url = host + latest.path + '/256/{z}/{x}/{y}/4/1_1.png';
                    for (var i = 0; i < _radarLayers.length; i++) {
                        _radarLayers[i].setUrl(url);
                    }
                })
                .catch(function () { /* offline / blocked — layer stays empty */ });
        }
        if (!_radarTimer) {
            refreshRadar();
            _radarTimer = setInterval(refreshRadar, 5 * 60 * 1000);
        } else {
            refreshRadar();
        }
        return layer;
    }

    // ── Map-overlay category loading (shared with the dashboard) ──
    //
    // Fetches the org's map-overlay categories + all markups, groups
    // markup shapes into per-category L.LayerGroups, and adds each
    // category as a toggleable overlay to a Leaflet layer control.
    //
    // Used by addLayerControl(...) when { includeMarkupOverlays:true }.
    // The shape-building mirrors what the dashboard's app.js does so
    // toggling a category on either page shows the same rendering.
    // Idempotent per map: won't re-fetch if _markupOverlaysAttached is
    // already set on the map instance.
    //
    // Layer preferences persist to the shared 'newui_map_layers'
    // localStorage key so a category the operator turned on at the
    // situation view is also on when they open unit-detail.
    var MARKUP_PREFS_KEY = 'newui_map_layers';

    function safeEsc(s) {
        // Local escape — MapPrefs can't depend on app.js's esc().
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function shapeFromMarkup(mk) {
        // Returns a Leaflet layer for a mmarkup row, or null if malformed.
        try {
            var coords = JSON.parse(mk.line_data);
            if (!coords || !coords.length) return null;
            var type   = (mk.line_type || '').toUpperCase();
            var color  = mk.line_color || '#9b59b6';
            var fill   = mk.fill_color || color;
            var op     = parseFloat(mk.line_opacity) || 0.7;
            var fop    = parseFloat(mk.fill_opacity) || 0.15;
            var weight = parseInt(mk.line_width, 10) || 2;
            var shape  = null;
            if (type === 'P' && coords.length >= 3) {
                shape = L.polygon(coords, {
                    color: color, weight: weight, opacity: op,
                    fillColor: fill, fillOpacity: fop
                });
            } else if (type === 'C' && coords.length >= 1) {
                var radius = parseFloat(mk.line_ident) || 500;
                shape = L.circle(coords[0], {
                    radius: radius, color: color, weight: weight, opacity: op,
                    fillColor: fill, fillOpacity: fop
                });
            } else if (type === 'L' && coords.length >= 2) {
                shape = L.polyline(coords, {
                    color: color, weight: weight, opacity: op
                });
            } else if (type === 'M' && coords.length >= 1) {
                shape = L.marker(coords[0]);
            }
            if (shape && mk.line_name) {
                var isZone = (type === 'P' || type === 'C');
                shape.bindTooltip(safeEsc(mk.line_name), {
                    permanent: isZone,
                    direction: 'center',
                    className: isZone ? 'map-zone-label' : ''
                });
            }
            return shape;
        } catch (e) { return null; }
    }

    function attachMarkupOverlays(mapInstance, ctl) {
        if (!mapInstance || !ctl) return;
        if (mapInstance._markupOverlaysAttached) return;
        mapInstance._markupOverlaysAttached = true;

        // Read the saved active-overlay names so we can auto-enable
        // categories the operator had on elsewhere.
        var savedActive = {};
        try {
            var s = JSON.parse(localStorage.getItem(MARKUP_PREFS_KEY) || 'null');
            if (s && Array.isArray(s.overlays)) {
                s.overlays.forEach(function (n) { savedActive[n] = true; });
            }
        } catch (e) { /* ignore */ }

        // Track the per-category label -> layer so save/restore matches
        // exactly the display name the layer control shows.
        var catLayers = {};   // catId -> { label, group }
        var uncatLayer = null;

        function catLabel(cat) {
            return '<span style="color:' +
                   (cat.color || '#9b59b6') +
                   '">&#9679;</span> ' +
                   safeEsc(cat.name || ('Category ' + cat.id));
        }

        fetch('api/map-overlay-categories.php?action=list', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { categories: [] }; })
            .then(function (cd) {
                var cats = (cd && cd.categories) || [];
                cats.forEach(function (c) {
                    var grp = L.layerGroup();
                    var lbl = catLabel(c);
                    catLayers[c.id] = { label: lbl, group: grp };
                    ctl.addOverlay(grp, lbl);
                    // Default-visible categories go on; saved-active
                    // trumps default when we restore below.
                    if (parseInt(c.default_visible, 10) === 1) grp.addTo(mapInstance);
                });
                // Catch-all for uncategorised markups
                uncatLayer = L.layerGroup();
                var uncatLbl = '<span style="color:#9b59b6">&#9679;</span> Uncategorised markups';
                ctl.addOverlay(uncatLayer, uncatLbl);
                catLayers['_uncat'] = { label: uncatLbl, group: uncatLayer };
                return fetch('api/map-markups.php', { credentials: 'same-origin' });
            })
            .then(function (r) { return r ? r.json() : { markups: [] }; })
            .then(function (md) {
                var mkups = (md && md.markups) || [];
                mkups.forEach(function (mk) {
                    if (!mk.line_data || parseInt(mk.line_status, 10) === 0) return;
                    var shape = shapeFromMarkup(mk);
                    if (!shape) return;
                    var effCat = parseInt(mk.category_id || mk.line_cat_id || 0, 10);
                    var target = (effCat && catLayers[effCat])
                        ? catLayers[effCat].group
                        : uncatLayer;
                    if (target) target.addLayer(shape);
                });
                // Restore per-category visibility from saved prefs.
                Object.keys(catLayers).forEach(function (k) {
                    var entry = catLayers[k];
                    if (savedActive[entry.label] && !mapInstance.hasLayer(entry.group)) {
                        entry.group.addTo(mapInstance);
                    }
                });
            })
            .catch(function () { /* graceful */ });

        // Persist overlay preferences whenever a category toggles.
        function saveActive() {
            var active = [];
            Object.keys(catLayers).forEach(function (k) {
                var e = catLayers[k];
                if (mapInstance.hasLayer(e.group)) active.push(e.label);
            });
            try {
                var prev = JSON.parse(localStorage.getItem(MARKUP_PREFS_KEY) || '{}');
                // Merge: preserve any non-markup overlays (Weather, etc.)
                // the dashboard's app.js might have persisted.
                var prevOverlays = Array.isArray(prev.overlays) ? prev.overlays : [];
                var isCatLabel = {};
                Object.keys(catLayers).forEach(function (k) {
                    isCatLabel[catLayers[k].label] = true;
                });
                var merged = prevOverlays.filter(function (n) { return !isCatLabel[n]; }).concat(active);
                prev.overlays = merged;
                localStorage.setItem(MARKUP_PREFS_KEY, JSON.stringify(prev));
            } catch (e) { /* ignore */ }
        }
        mapInstance.on('overlayadd', saveActive);
        mapInstance.on('overlayremove', saveActive);
    }

    window.MapPrefs = {
        /**
         * Fetch + merge the configured tile provider into TILE_DEFS. Safe to
         * call repeatedly (fetches once). Returns a promise that always
         * resolves. Map pages that want the configured provider to appear in
         * the layer control should `MapPrefs.init().then(...)` before building
         * the control; pages that don't await it still work (built-ins only).
         * @returns {Promise}
         */
        init: function () {
            return loadConfig();
        },

        /**
         * Whether the map-config fetch has completed (success or failure).
         * @returns {boolean}
         */
        isConfigLoaded: function () {
            return _configFetched;
        },

        /**
         * Build a Leaflet tile layer for a TILE_DEFS key using the
         * quadkey-aware factory. Unknown keys fall back to 'street'.
         * @param {string} key
         * @returns {L.TileLayer}
         */
        makeLayer: function (key) {
            var def = TILE_DEFS[key] || TILE_DEFS.street;
            return buildLayer(def.url, def.opts);
        },

        /**
         * The label of the configured custom provider, or '' if none.
         * @returns {string}
         */
        getCustomLabel: function () {
            return TILE_DEFS.custom ? TILE_DEFS.custom.label : '';
        },

        /**
         * Get the preferred basemap key for the current theme.
         * @returns {string} 'street' | 'dark' | 'terrain'
         */
        getBasemap: function () {
            var prefs = readPrefs();
            var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            return isDark ? (prefs.basemapDark || 'dark') : (prefs.basemapLight || 'street');
        },

        /**
         * Save basemap preference for a theme.
         * @param {string} theme  'dark' or 'light'
         * @param {string} value  'street' | 'dark' | 'terrain'
         */
        setBasemap: function (theme, value) {
            var prefs = readPrefs();
            if (theme === 'dark') {
                prefs.basemapDark = value;
            } else {
                prefs.basemapLight = value;
            }
            writePrefs(prefs);
        },

        /**
         * Create a Leaflet tile layer for the preferred basemap.
         * @returns {L.TileLayer}
         */
        createTileLayer: function () {
            var key = this.getBasemap();
            var def = TILE_DEFS[key] || TILE_DEFS.street;
            return buildLayer(def.url, def.opts);
        },

        /**
         * Add the preferred basemap tile layer to a map instance.
         * @param {L.Map} mapInstance
         * @returns {L.TileLayer} the layer that was added
         */
        addDefaultBasemap: function (mapInstance) {
            var layer = this.createTileLayer();
            layer.addTo(mapInstance);
            return layer;
        },

        /**
         * Attach the basemap + weather-overlay layer control to a map.
         * Idempotent: skips if a control is already attached (we tag
         * the map instance with _ticketsLayerCtl).
         *
         * @param {L.Map} mapInstance
         * @param {object} [options]
         * @param {boolean} [options.includeWeather=true] include the
         *        weather overlays (temp / precipitation / wind /
         *        clouds). Set to false on small detail-page maps
         *        where the weather noise outweighs the value.
         * @param {boolean} [options.includeMarkupOverlays=false] load
         *        map-overlay categories + markup shapes and add each
         *        category as a toggleable overlay. Shares the
         *        newui_map_layers localStorage key with the
         *        dashboard, so a category the operator enabled at
         *        situation-view is auto-enabled here (issue #46).
         * @param {string} [options.position='topright']
         * @param {L.TileLayer} [options.currentBase] if the caller
         *        already added a basemap (typically via
         *        addDefaultBasemap), pass it here so the control's
         *        radio reflects the active selection instead of
         *        starting unchecked.
         * @returns {L.Control.Layers} the control that was added
         */
        addLayerControl: function (mapInstance, options) {
            if (!mapInstance || typeof L === 'undefined') return null;
            if (mapInstance._ticketsLayerCtl) return mapInstance._ticketsLayerCtl;
            options = options || {};
            var includeWeather = options.includeWeather !== false;
            var position = options.position || 'topright';

            // Build a fresh basemap layer per key. Reusing the layer
            // the caller already added would mean the control's radio
            // shows it as selected, but switching to another base and
            // back would re-create — easier to just build all of them
            // here and let Leaflet handle add/remove from the map.
            var baseMaps = {};
            for (var key in TILE_DEFS) {
                if (TILE_DEFS.hasOwnProperty(key)) {
                    var def = TILE_DEFS[key];
                    baseMaps[def.label] = buildLayer(def.url, def.opts);
                }
            }

            // If a current base was supplied, swap our duplicate of
            // that key for the supplied instance so toggling away and
            // back doesn't briefly remove the map background.
            if (options.currentBase) {
                var prefKey = this.getBasemap();
                var prefDef = TILE_DEFS[prefKey] || TILE_DEFS.street;
                baseMaps[prefDef.label] = options.currentBase;
            }

            var overlays = {};
            if (includeWeather) {
                // Same caching weather-proxy the units listing uses.
                // Errors are silent (errorTileUrl: '') so a missing
                // OWM key just shows a blank overlay instead of red Xs.
                var weatherOpts = { opacity: 0.5, maxZoom: 19, errorTileUrl: '' };
                // Eric 2026-07-04 — Radar first so it sits at the top of
                // the overlay list. RainViewer live precipitation radar
                // (free, no key), the same layer situation.php uses. This
                // is what makes radar available on the unit-detail /
                // unit-edit / incident-detail / facility maps, so a
                // dispatcher placing a report ("north end of the storm")
                // can see the cells over the very map they're editing.
                overlays['Radar'] = makeRadarLayer();
                overlays['Temperature']   = L.tileLayer('api/weather-proxy.php?type=tile&layer=temp&z={z}&x={x}&y={y}',              weatherOpts);
                overlays['Precipitation'] = L.tileLayer('api/weather-proxy.php?type=tile&layer=precipitation_cls&z={z}&x={x}&y={y}', weatherOpts);
                overlays['Wind']          = L.tileLayer('api/weather-proxy.php?type=tile&layer=wind&z={z}&x={x}&y={y}',              weatherOpts);
                overlays['Clouds']        = L.tileLayer('api/weather-proxy.php?type=tile&layer=clouds_cls&z={z}&x={x}&y={y}',        weatherOpts);
            }

            var ctl = L.control.layers(baseMaps, overlays, {
                collapsed: true,
                position: position
            }).addTo(mapInstance);
            mapInstance._ticketsLayerCtl = ctl;

            // Eric 2026-07-04 — event map image overlays (#43) on every
            // map that uses this control, so the traced event map is
            // visible while viewing/editing a unit's location. No-op if
            // map-image-overlays.js isn't loaded on this page.
            if (window.MapImageOverlays && typeof window.MapImageOverlays.attach === 'function') {
                window.MapImageOverlays.attach(mapInstance, ctl);
            }

            // Persist the operator's choice back into prefs so the
            // next page they open already shows the basemap they last
            // picked here. We only key on theme, not on page.
            mapInstance.on('baselayerchange', (function (self) {
                return function (e) {
                    for (var k in TILE_DEFS) {
                        if (TILE_DEFS.hasOwnProperty(k) && TILE_DEFS[k].label === e.name) {
                            var theme = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
                            self.setBasemap(theme, k);
                            return;
                        }
                    }
                };
            })(this));

            // The configured provider arrives asynchronously (map-config
            // fetch). Callers here don't await init(), so kick the fetch
            // and, once it resolves, fold the merged 'custom' entry into
            // THIS already-built control. This is what makes the configured
            // provider appear on the MapPrefs-native detail/edit maps without
            // editing each of those call sites. Idempotent: we tag the
            // control once we've added the custom layer.
            loadConfig().then((function (ctlRef) {
                return function () {
                    if (!TILE_DEFS.custom || ctlRef._ticketsCustomAdded) return;
                    var def = TILE_DEFS.custom;
                    ctlRef.addBaseLayer(buildLayer(def.url, def.opts), def.label);
                    ctlRef._ticketsCustomAdded = true;
                };
            })(ctl));

            // Issue #46 — attach map-overlay categories + markups.
            if (options.includeMarkupOverlays) {
                attachMarkupOverlays(mapInstance, ctl);
            }

            return ctl;
        },

        /**
         * Convenience: attach markup overlays to an already-built
         * layer control. Useful when the caller built the control
         * themselves and just wants MapPrefs' shared overlay logic.
         */
        addMarkupOverlays: function (mapInstance, ctl) {
            attachMarkupOverlays(mapInstance, ctl);
        }
    };
})();
