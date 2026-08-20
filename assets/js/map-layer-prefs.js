/**
 * MapLayerPrefs — per-user map LAYER VISIBILITY, shared by every map surface.
 *
 * The bug this fixes, precisely:
 *
 *   The dashboard (app.js) already saved active overlays to localStorage and
 *   already restored them on load. But the restore loop only ever called
 *   addTo() — it had no branch that could REMOVE a layer. Since the data layer
 *   groups are `.addTo(map)` at construction, a layer the user switched OFF
 *   was re-added on every single load and the restore could not undo it.
 *   Turning a layer on persisted; turning one off was impossible. The
 *   situation screen persisted nothing at all.
 *
 *   So the load path here is deliberately SYMMETRIC: apply() both adds layers
 *   that should be visible and removes layers that should not be. That
 *   symmetry is the entire fix; everything else is plumbing.
 *
 * Where the answer comes from: window.MAP_LAYER_PREFS, injected server-side and
 * SYNCHRONOUSLY by inc/navbar.php — so it is on the page before any map builds
 * a layer, and binding costs no network round trip and no render delay. An
 * async fetch would show the operator their facilities, then take them away a
 * moment later, on every single page load.
 *
 * Usage:
 *   MapLayerPrefs.bind(map, {
 *       facilities: facilityLayerGroup,
 *       units:      unitLayerGroup,
 *       radar:      radarLayer
 *   });
 *   MapLayerPrefs.register(map, 'markups', grp);   // for async-built layers
 *
 * Saving is fire-and-forget and debounced: the map has already changed locally,
 * so a failed write must degrade silently here and be logged server-side. A
 * dispatcher never gets a modal because a preference did not save.
 */
(function () {
    'use strict';

    var ENDPOINT = 'api/map-layer-prefs.php';
    var SAVE_DEBOUNCE_MS = 500;

    // Shipped fallbacks, used only if navbar's injection is missing entirely
    // (a page that somehow renders a map without the navbar). These MUST match
    // inc/map-layer-prefs.php's map_layer_catalog() defaults, so a page in that
    // state behaves exactly as it did before this feature existed.
    var SHIPPED_DEFAULTS = {
        incidents: true, units: true, units_live: true, facilities: true, event_zones: true,
        markups: false, road_conditions: false,
        weather_alerts: true, radar: false, radar_us: false,
        temperature: false, precipitation: false, rain: false, snow: false,
        wind: false, clouds: false, pressure: false, city_weather: false,
        grid: false
    };

    function cfg() {
        return window.MAP_LAYER_PREFS || null;
    }

    function visibleMap() {
        var c = cfg();
        return (c && c.layers) ? c.layers : SHIPPED_DEFAULTS;
    }

    function defaultsMap() {
        var c = cfg();
        return (c && c.defaults) ? c.defaults : SHIPPED_DEFAULTS;
    }

    /**
     * Effective visibility for a layer id. An id we have never heard of falls
     * back to visible — a new layer someone forgot to add to the catalog should
     * appear (and be noticed), not vanish silently.
     */
    function isVisible(id) {
        var m = visibleMap();
        if (Object.prototype.hasOwnProperty.call(m, id)) return !!m[id];
        if (Object.prototype.hasOwnProperty.call(SHIPPED_DEFAULTS, id)) return !!SHIPPED_DEFAULTS[id];
        return true;
    }

    function defaultVisible(id) {
        var d = defaultsMap();
        if (Object.prototype.hasOwnProperty.call(d, id)) return !!d[id];
        if (Object.prototype.hasOwnProperty.call(SHIPPED_DEFAULTS, id)) return !!SHIPPED_DEFAULTS[id];
        return true;
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ── Pending writes, coalesced ────────────────────────────────────────────
    var _pending = {};
    var _timer = null;

    function flush() {
        _timer = null;
        var payload = _pending;
        _pending = {};
        var any = false;
        for (var k in payload) { if (payload.hasOwnProperty(k)) { any = true; break; } }
        if (!any) return;
        if (typeof fetch !== 'function') return;

        fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ layers: payload, csrf_token: csrfToken() })
        }).catch(function () {
            // Deliberately silent. The map already shows what the operator
            // asked for; the server logs the failure. Never a modal here.
        });
    }

    function queueSave(id, visible) {
        // Keep the in-memory view current so a second map on the same page
        // (and a later bind) agrees with what the operator just did.
        var c = cfg();
        if (c && c.layers) c.layers[id] = !!visible;
        _pending[id] = !!visible;
        if (_timer) clearTimeout(_timer);
        _timer = setTimeout(flush, SAVE_DEBOUNCE_MS);
    }

    /**
     * Bring one layer into line with the stored preference. Returns nothing;
     * this is the add-AND-remove step the old restore code was missing.
     */
    function applyOne(map, id, layer) {
        if (!map || !layer) return;
        var want = isVisible(id);
        var has = map.hasLayer(layer);
        if (want && !has) {
            layer.addTo(map);
        } else if (!want && has) {
            map.removeLayer(layer);
        }
    }

    /**
     * Wire the map's overlay events once, so any toggle of a registered layer
     * persists. Leaflet fires overlayadd/overlayremove for layers a layer
     * control manages, including programmatic addTo/removeLayer — hence the
     * _applying guard, or our own reconciliation would echo straight back as a
     * save.
     */
    function ensureListener(map) {
        if (map._mlpListener) return;
        map._mlpListener = true;
        map._mlpRegistry = map._mlpRegistry || [];

        function onChange(visible) {
            return function (e) {
                if (map._mlpApplying) return;
                var reg = map._mlpRegistry || [];
                for (var i = 0; i < reg.length; i++) {
                    if (reg[i].layer === e.layer) {
                        queueSave(reg[i].id, visible);
                        return;
                    }
                }
            };
        }
        map.on('overlayadd', onChange(true));
        map.on('overlayremove', onChange(false));
    }

    /**
     * Register one layer under a stable id and immediately apply the stored
     * preference to it. Safe to call for layers built asynchronously (markup
     * categories, EOC unit/facility groups) after bind() has already run.
     */
    function register(map, id, layer) {
        if (!map || !layer || !id) return;
        ensureListener(map);
        map._mlpRegistry = map._mlpRegistry || [];
        for (var i = 0; i < map._mlpRegistry.length; i++) {
            if (map._mlpRegistry[i].id === id && map._mlpRegistry[i].layer === layer) {
                return;   // already registered
            }
        }
        map._mlpRegistry.push({ id: id, layer: layer });

        map._mlpApplying = true;
        try { applyOne(map, id, layer); }
        finally { map._mlpApplying = false; }
    }

    /**
     * Register a whole {id: layer} map at once and reconcile every one of them.
     * Layers whose id is not in the catalog are still registered (they simply
     * default to visible), so a surface can adopt this without a schema change.
     */
    function bind(map, layersById) {
        if (!map || !layersById) return;
        ensureListener(map);
        map._mlpApplying = true;
        try {
            for (var id in layersById) {
                if (!layersById.hasOwnProperty(id)) continue;
                var layer = layersById[id];
                if (!layer) continue;
                map._mlpRegistry = map._mlpRegistry || [];
                var known = false;
                for (var i = 0; i < map._mlpRegistry.length; i++) {
                    if (map._mlpRegistry[i].id === id && map._mlpRegistry[i].layer === layer) {
                        known = true; break;
                    }
                }
                if (!known) map._mlpRegistry.push({ id: id, layer: layer });
                applyOne(map, id, layer);
            }
        } finally {
            map._mlpApplying = false;
        }

        // The layer control's DOM is built by Leaflet; defer one tick so it
        // exists. Never blocks or delays the map itself.
        if (typeof setTimeout === 'function') {
            setTimeout(function () { try { attachReset(map); } catch (e) {} }, 0);
        }
    }

    /**
     * Append a "Reset to default" link to the map's Leaflet layer control, so
     * the way back to the administrator's default sits exactly where the
     * operator changed things — not buried in Settings, which most operators
     * cannot even open.
     *
     * Shown only when this user actually has overrides. Entirely defensive: if
     * the control DOM is not what we expect, this does nothing and the map is
     * unaffected.
     */
    function attachReset(map) {
        if (!map || map._mlpResetAttached) return;
        var container = map.getContainer ? map.getContainer() : null;
        if (!container) return;
        var list = container.querySelector('.leaflet-control-layers-overlays');
        if (!list) return;
        map._mlpResetAttached = true;

        var c = cfg();
        var overridden = false;
        if (c && c.layers && c.defaults) {
            for (var id in c.defaults) {
                if (!c.defaults.hasOwnProperty(id)) continue;
                if (!!c.layers[id] !== !!c.defaults[id]) { overridden = true; break; }
            }
        }
        if (!overridden) return;

        var row = document.createElement('div');
        row.className = 'mlp-reset-row mt-1 pt-1 border-top';
        var link = document.createElement('a');
        link.href = '#';
        link.className = 'small text-decoration-none';
        link.textContent = 'Reset to default';
        link.title = 'Restore the layers this installation shows by default';
        link.addEventListener('click', function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            link.textContent = 'Resetting…';
            reset().then(function () { window.location.reload(); })
                   .catch(function () { link.textContent = 'Reset to default'; });
        });
        row.appendChild(link);
        list.parentNode.appendChild(row);
    }

    /** Drop this user's overrides server-side; resolves to the fresh prefs. */
    function reset() {
        if (typeof fetch !== 'function') return Promise.resolve(null);
        return fetch(ENDPOINT, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reset: true, csrf_token: csrfToken() })
        }).then(function (r) { return r.json(); })
          .catch(function () { return null; });
    }

    window.MapLayerPrefs = {
        isVisible: isVisible,
        defaultVisible: defaultVisible,
        bind: bind,
        register: register,
        reset: reset,
        attachReset: attachReset,
        /** Force any queued write out now (used by tests). */
        flush: flush,
        /** True when the server injected a preference payload for this user. */
        isLoaded: function () { return !!cfg(); }
    };
})();
