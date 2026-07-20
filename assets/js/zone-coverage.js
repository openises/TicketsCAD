/**
 * Zone Coverage board (Phase 115, GH #64).
 *
 * Renders one card per event zone with a big unit count + the units in it, an
 * "I'm in: [zone]" self-report strip for the viewer's own unit, and a "no zone
 * yet" bucket. Real-time via the shared EventBus SSE (responder:status /
 * zone_update); 15 s poll fallback. ES5 only, no build step.
 */
(function () {
    'use strict';

    var CFG = window.ZC_CONFIG || { csrf: '', canSetOwnZone: false };
    var LS_KEY = 'zc_ticket_id';
    var currentTicketId = 0;
    var reloadTimer = null;
    var pollTimer = null;

    function el(id) { return document.getElementById(id); }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return (m && m.content) || CFG.csrf || '';
    }

    /** Readable-contrast text color for a given hex background. */
    function textOn(bg) {
        if (!bg || bg.charAt(0) !== '#' || bg.length < 7) return '';
        var r = parseInt(bg.substr(1, 2), 16),
            g = parseInt(bg.substr(3, 2), 16),
            b = parseInt(bg.substr(5, 2), 16);
        // Perceived luminance — dark text on light zones, white on dark.
        return (0.299 * r + 0.587 * g + 0.114 * b) > 150 ? '#111' : '#fff';
    }

    /** Build a unit chip (callsign + lead name) safely — no innerHTML. */
    function unitChip(u) {
        var chip = document.createElement('span');
        chip.className = 'zc-unit-chip';
        var cs = document.createElement('span');
        cs.className = 'zc-unit-cs';
        cs.textContent = u.callsign || u.name || 'Unit';
        chip.appendChild(cs);
        if (u.lead) {
            var lead = document.createElement('span');
            lead.className = 'zc-unit-lead';
            lead.textContent = ' ' + u.lead;
            chip.appendChild(lead);
        }
        return chip;
    }

    function render(data) {
        // Event name + picker
        var evName = el('zcEventName');
        if (data.event) {
            var label = (data.event.incident_number ? data.event.incident_number + ' — ' : '') +
                        (data.event.scope || ('Incident #' + data.event.id));
            evName.textContent = label;
            currentTicketId = data.event.id;
        } else {
            evName.textContent = '';
        }

        var picker = el('zcEventPicker');
        if (data.events && data.events.length > 1) {
            picker.innerHTML = '';
            for (var i = 0; i < data.events.length; i++) {
                var opt = document.createElement('option');
                opt.value = data.events[i].id;
                opt.textContent = data.events[i].label + ' (' + data.events[i].zone_count + ')';
                if (data.events[i].id === currentTicketId) opt.selected = true;
                picker.appendChild(opt);
            }
            picker.classList.remove('d-none');
        } else {
            picker.classList.add('d-none');
        }

        // Empty state
        var loading = el('zcLoading');
        if (loading) loading.remove();
        var zonesWrap = el('zcZones');
        var empty = el('zcEmpty');
        if (!data.event || !data.zones || data.zones.length === 0) {
            zonesWrap.innerHTML = '';
            empty.classList.remove('d-none');
            renderSelfReport(data);
            renderUnassigned(data);
            return;
        }
        empty.classList.add('d-none');

        // Zone cards
        zonesWrap.innerHTML = '';
        for (var z = 0; z < data.zones.length; z++) {
            zonesWrap.appendChild(zoneCard(data.zones[z]));
        }

        renderSelfReport(data);
        renderUnassigned(data);
    }

    function zoneCard(zone) {
        var col = document.createElement('div');
        col.className = 'col-6 col-md-4 col-lg-3';

        var card = document.createElement('div');
        card.className = 'card zc-zone-card h-100';
        var accent = zone.color || '#6c757d';
        card.style.borderTopColor = accent;

        var body = document.createElement('div');
        body.className = 'card-body p-2';

        // Header: zone name + code badge
        var head = document.createElement('div');
        head.className = 'd-flex align-items-center gap-1 mb-1';
        var badge = document.createElement('span');
        badge.className = 'zc-zone-code';
        badge.style.background = accent;
        badge.style.color = textOn(accent) || '#fff';
        badge.textContent = zone.code || zone.name;
        head.appendChild(badge);
        var nm = document.createElement('span');
        nm.className = 'zc-zone-name text-truncate';
        nm.textContent = zone.name;
        head.appendChild(nm);
        body.appendChild(head);

        // Big count
        var count = document.createElement('div');
        count.className = 'zc-zone-count';
        count.textContent = zone.unit_count;
        var unitWord = document.createElement('span');
        unitWord.className = 'zc-zone-unit-word';
        unitWord.textContent = zone.unit_count === 1 ? ' unit' : ' units';
        count.appendChild(unitWord);
        body.appendChild(count);

        // Unit chips
        var chips = document.createElement('div');
        chips.className = 'zc-zone-units';
        if (zone.units && zone.units.length) {
            for (var i = 0; i < zone.units.length; i++) {
                chips.appendChild(unitChip(zone.units[i]));
            }
        } else {
            var none = document.createElement('span');
            none.className = 'text-body-secondary fst-italic small';
            none.textContent = 'empty';
            chips.appendChild(none);
        }
        body.appendChild(chips);

        card.appendChild(body);
        col.appendChild(card);
        return col;
    }

    function renderUnassigned(data) {
        var wrap = el('zcUnassignedWrap');
        var box = el('zcUnassigned');
        var cnt = el('zcUnassignedCount');
        var u = data.unassigned || { unit_count: 0, units: [] };
        if (!u.units || u.units.length === 0) {
            wrap.classList.add('d-none');
            return;
        }
        box.innerHTML = '';
        for (var i = 0; i < u.units.length; i++) box.appendChild(unitChip(u.units[i]));
        cnt.textContent = u.unit_count;
        wrap.classList.remove('d-none');
    }

    function renderSelfReport(data) {
        var strip = el('zcSelfReport');
        var buttons = el('zcSelfButtons');
        var me = data.me || {};
        // Only when the viewer is on a unit for this event AND may self-report.
        if (!CFG.canSetOwnZone || !me.assign_id || !data.zones || !data.zones.length) {
            strip.classList.add('d-none');
            return;
        }
        buttons.innerHTML = '';
        for (var i = 0; i < data.zones.length; i++) {
            (function (zone) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'btn btn-sm zc-self-btn' +
                    (me.current_zone_id === zone.id ? ' active' : '');
                if (me.current_zone_id === zone.id) {
                    var c = zone.color || '#0d6efd';
                    btn.style.background = c;
                    btn.style.borderColor = c;
                    btn.style.color = textOn(c) || '#fff';
                } else {
                    btn.className += ' btn-outline-secondary';
                }
                btn.textContent = zone.code || zone.name;
                btn.title = zone.name;
                btn.addEventListener('click', function () { selfReport(zone.id); });
                buttons.appendChild(btn);
            })(data.zones[i]);
        }
        // A "clear" chip
        var clr = document.createElement('button');
        clr.type = 'button';
        clr.className = 'btn btn-sm btn-outline-secondary zc-self-btn';
        clr.textContent = '—';
        clr.title = 'Clear my zone';
        clr.addEventListener('click', function () { selfReport(0); });
        buttons.appendChild(clr);

        strip.classList.remove('d-none');
    }

    function selfReport(zoneId) {
        var status = el('zcSelfStatus');
        status.textContent = 'Saving…';
        status.className = 'small ms-1 text-body-secondary';
        fetch('api/zone-self-report.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ ticket_id: currentTicketId, zone_id: zoneId, csrf_token: csrf() })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (res && res.ok) {
                status.textContent = res.zone ? ('You are in ' + res.zone.name) : 'Zone cleared';
                status.className = 'small ms-1 text-success';
                load(); // reflect immediately (SSE will also fire for others)
            } else {
                status.textContent = (res && res.error) ? res.error : 'Could not save';
                status.className = 'small ms-1 text-danger';
            }
            setTimeout(function () { status.textContent = ''; }, 4000);
        })
        .catch(function () {
            status.textContent = 'Network error';
            status.className = 'small ms-1 text-danger';
        });
    }

    function load() {
        var url = 'api/zone-coverage.php';
        if (currentTicketId > 0) url += '?ticket_id=' + currentTicketId;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) { return; }
                render(data);
            })
            .catch(function () { /* keep last-rendered board */ });
    }

    // Debounce reloads so a burst of zone moves triggers one refetch.
    function scheduleReload() {
        if (reloadTimer) return;
        reloadTimer = setTimeout(function () { reloadTimer = null; load(); }, 400);
    }

    function setLive(on) {
        var lbl = el('zcLiveLabel');
        var wrap = el('zcLive');
        if (!lbl || !wrap) return;
        lbl.textContent = on ? 'live' : 'reconnecting…';
        wrap.classList.toggle('zc-live-on', !!on);
        wrap.classList.toggle('zc-live-off', !on);
    }

    function init() {
        // Restore last chosen event.
        var saved = parseInt(window.localStorage.getItem(LS_KEY) || '0', 10);
        if (saved > 0) currentTicketId = saved;

        el('zcRefresh').addEventListener('click', load);
        el('zcEventPicker').addEventListener('change', function () {
            currentTicketId = parseInt(this.value, 10) || 0;
            window.localStorage.setItem(LS_KEY, String(currentTicketId));
            load();
        });

        // Real-time: any zone move on the current event refreshes the board.
        if (window.EventBus && typeof window.EventBus.on === 'function') {
            window.EventBus.on('responder:status', function (d) {
                if (!d || d.action === 'zone_update') scheduleReload();
            });
            window.EventBus.on('sse:connected', function () { setLive(true); });
            window.EventBus.on('sse:disconnected', function () { setLive(false); });
            if (typeof window.EventBus.isSSEConnected === 'function') {
                setLive(window.EventBus.isSSEConnected());
            }
        }

        // Poll fallback (covers SSE gaps + the between-events picker).
        pollTimer = setInterval(load, 15000);

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
