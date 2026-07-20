/**
 * GH #62 — canonical unit/facility TYPE icon mapping.
 *
 * unit_types.icon / fac_types.icon are LEGACY NUMERIC indexes (int(3),
 * inherited from v3.44's marker sprites). NewUI never rendered them — the
 * map/popup code expects a Bootstrap-icon class string, so a numeric value
 * produced a junk class and silently fell back to the default pin. This
 * module is the single source of truth turning that index into a real glyph,
 * and it powers the visual picker on settings.php (no more "what does this
 * number mean?"). The DB stays int(3) so legacy-migrated data keeps working.
 *
 * ES5, no modules — attaches window.TypeIcons and auto-populates any
 * <select data-type-icon-picker> with icon-labelled options + live preview
 * (<i data-type-icon-preview-for="selectId">).
 */
(function () {
    'use strict';

    // index → { c: bootstrap-icons class, l: label }. 0 is the default pin.
    // Append new entries at the END — indexes are stored in the DB.
    var MAP = [
        { c: 'bi-geo-alt-fill',           l: 'Default pin' },
        { c: 'bi-truck',                  l: 'Engine / Truck' },
        { c: 'bi-fire',                   l: 'Fire' },
        { c: 'bi-heart-pulse',            l: 'Medical / EMS' },
        { c: 'bi-shield-fill',            l: 'Law / Security' },
        { c: 'bi-person-fill',            l: 'Person / Foot' },
        { c: 'bi-car-front-fill',         l: 'Car / Patrol' },
        { c: 'bi-truck-front',            l: 'Utility / Squad' },
        { c: 'bi-bicycle',                l: 'Bike team' },
        { c: 'bi-life-preserver',         l: 'Water rescue' },
        { c: 'bi-airplane',               l: 'Aviation / Drone' },
        { c: 'bi-broadcast-pin',          l: 'Comms / Radio' },
        { c: 'bi-tools',                  l: 'Support / Maintenance' },
        { c: 'bi-house-fill',             l: 'Station / Post' },
        { c: 'bi-hospital',               l: 'Hospital' },
        { c: 'bi-people-fill',            l: 'Team / Crew' },
        { c: 'bi-binoculars-fill',        l: 'Spotter / Watch' },
        { c: 'bi-cone-striped',           l: 'Traffic control' },
        { c: 'bi-lightning-charge-fill',  l: 'Utility / Power' },
        { c: 'bi-box-seam',               l: 'Logistics / Supply' }
    ];

    /**
     * Resolve a stored icon value to a Bootstrap-icon class.
     * Accepts the numeric index (int or numeric string). A value that is
     * already a 'bi-…' class string passes through (forward compatibility).
     */
    function classFor(v) {
        if (v === null || v === undefined || v === '') return MAP[0].c;
        if (typeof v === 'string' && v.indexOf('bi-') === 0) return v;
        var i = parseInt(v, 10);
        if (isNaN(i) || i < 0 || i >= MAP.length) return MAP[0].c;
        return MAP[i].c;
    }

    function list() { return MAP.slice(); }

    // ── Map markers (GH #82 icons + #76 labels) ──────────────────────────
    // Shared builder so every Leaflet map (dashboard, Situation/EOC, units,
    // facilities) draws a unit/facility the SAME way: the configured type
    // glyph in a status-coloured badge, with an optional always-on name label.
    function _esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // Readable glyph colour (dark on a light badge, white on a dark one).
    function _textOn(bg) {
        if (typeof bg !== 'string' || bg.charAt(0) !== '#' || bg.length < 7) return '#fff';
        var r = parseInt(bg.substr(1, 2), 16),
            g = parseInt(bg.substr(3, 2), 16),
            b = parseInt(bg.substr(5, 2), 16);
        return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111' : '#fff';
    }

    /**
     * Build an L.divIcon for a unit/facility marker.
     *   iconVal  — stored type-icon index (u.icon / f.type_icon)
     *   color    — status colour (hex) for the badge
     *   opts     — { label, square, stale, size }
     * Requires Leaflet (L) to be loaded on the page. Returns null if not.
     */
    function markerDivIcon(iconVal, color, opts) {
        if (typeof L === 'undefined' || !L.divIcon) return null;
        opts = opts || {};
        var c = (typeof color === 'string' && color && color !== 'transparent') ? color : '#6c757d';
        var size = opts.size || 24;
        var cls = classFor(iconVal);
        var square = opts.square ? ' ti-marker-square' : '';
        var stale = opts.stale ? ' ti-marker-stale' : '';
        var label = opts.label
            ? '<span class="ti-marker-label">' + _esc(opts.label) + '</span>' : '';
        var html =
            '<div class="ti-marker' + square + stale + '">' +
              '<span class="ti-marker-glyph" style="background:' + _esc(c) +
                    ';color:' + _textOn(c) + '"><i class="bi ' + cls + '"></i></span>' +
              label +
            '</div>';
        return L.divIcon({
            className: 'ti-marker-divicon',
            html: html,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2]
        });
    }

    // ── Map name-label visibility (GH #76) ──
    // Always-on marker labels overlap in dense, zoomed-out views. One call per
    // map gives it both: (1) auto-hide labels below a zoom threshold (the
    // declutter), and (2) a small toggle control to turn names off entirely,
    // remembered across pages. All bound maps share the preference.
    var _labelMaps = [];
    function _labelsPref() {
        try { return localStorage.getItem('tcad_map_labels') || 'auto'; } catch (e) { return 'auto'; }
    }
    function _applyLabels(entry) {
        var pref = _labelsPref(), hide;
        if (pref === 'off') { hide = true; }
        else if (pref === 'on') { hide = false; }
        else { hide = entry.map.getZoom() < entry.minZoom; }   // 'auto'
        var el = entry.map.getContainer && entry.map.getContainer();
        if (el) { el.classList.toggle('ti-labels-hidden', !!hide); }
    }
    function _applyAllLabels() {
        for (var i = 0; i < _labelMaps.length; i++) { _applyLabels(_labelMaps[i]); }
    }
    function setLabels(mode) {
        try { localStorage.setItem('tcad_map_labels', mode); } catch (e) {}
        _applyAllLabels();
    }
    function bindLabelZoom(map, opts) {
        if (!map || typeof map.getZoom !== 'function') { return; }
        opts = opts || {};
        var entry = { map: map, minZoom: opts.minZoom || 12 };
        _labelMaps.push(entry);
        map.on('zoomend', function () { _applyLabels(entry); });
        _applyLabels(entry);
        if (opts.control !== false && typeof L !== 'undefined' && L.Control) {
            var Ctl = L.Control.extend({
                options: { position: opts.position || 'topright' },
                onAdd: function () {
                    var b = L.DomUtil.create('a', 'leaflet-bar ti-label-toggle');
                    b.href = '#';
                    b.title = 'Show / hide unit & facility names';
                    b.innerHTML = '<i class="bi bi-tag"></i>';
                    if (_labelsPref() === 'off') { b.classList.add('ti-label-off'); }
                    L.DomEvent.on(b, 'click', function (e) {
                        L.DomEvent.preventDefault(e);
                        L.DomEvent.stopPropagation(e);
                        setLabels(_labelsPref() === 'off' ? 'auto' : 'off');
                        b.classList.toggle('ti-label-off', _labelsPref() === 'off');
                    });
                    return b;
                }
            });
            map.addControl(new Ctl());
        }
    }

    // Populate pickers + wire live previews.
    function initPickers() {
        var pickers = document.querySelectorAll('select[data-type-icon-picker]');
        for (var p = 0; p < pickers.length; p++) {
            (function (sel) {
                if (sel._tiInit) return;
                sel._tiInit = true;
                var current = sel.getAttribute('data-value') || sel.value || '0';
                sel.innerHTML = '';
                for (var i = 0; i < MAP.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = String(i);
                    opt.textContent = i + ' — ' + MAP[i].l;
                    sel.appendChild(opt);
                }
                sel.value = String(parseInt(current, 10) || 0);
                function preview() {
                    var el = document.querySelector('[data-type-icon-preview-for="' + sel.id + '"]');
                    if (el) el.className = 'bi ' + classFor(sel.value) + ' fs-4 align-middle';
                }
                sel.addEventListener('change', preview);
                // The forms set .value programmatically when editing a row;
                // poll-free hook: expose a refresh so config.js can call it,
                // and also refresh on focus (covers modal-open edits).
                sel.addEventListener('focus', preview);
                preview();
            })(pickers[p]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPickers);
    } else {
        initPickers();
    }

    window.TypeIcons = {
        classFor: classFor, list: list, initPickers: initPickers,
        markerDivIcon: markerDivIcon,
        bindLabelZoom: bindLabelZoom, setLabels: setLabels
    };
})();
