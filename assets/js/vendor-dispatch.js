/**
 * NewUI v4.0 - Towing / Roadside dispatch dialog (GH#148, Phase 155)
 *
 * Loaded by incident-detail.php ONLY when the feature is turned on and the caller holds action.dispatch_vendor,
 * and AFTER incident-detail.js. Owns three things on that page: the "Towing / Roadside" card, the dialog
 * (#vendorDispatchModal, inc/vendor-dispatch-modal.php) and the live refresh. Backend: api/vendor-dispatch.php.
 *
 * Rules this file keeps:
 *   - Every server-authored string (company names, contacts, notes, reasons) is written with textContent /
 *     createTextNode, never innerHTML.
 *   - "Log call" / "Call" records the offer FIRST and dials second (when phone_click_to_call is tel_link/widget),
 *     and dials NOTHING when the server answers 409 queue_changed (the rotation moved while the screen was open).
 *   - The server classifies every offer (rotation / override / owner request / unlisted); this file only reports what
 *     the screen showed (client_head_provider_id) and what was picked.
 *   - Numbers carry data-dial / data-dial-ctx so the click-to-dial module (GH#108) can upgrade them without a change here.
 *
 * Sections: 1 helpers/state, 2 card, 3 dialog form, 4 queue + pending calls, 5 actions, 6 timeline/status/read-aloud, 7 boot.
 */
(function () {
    'use strict';

    // ═════════════════════════════════════════════════════════════
    // 1. Helpers and state
    // ═════════════════════════════════════════════════════════════
    var STR = window.VENDOR_STR || {};
    function S(key, fallback) {
        var v = STR[key];
        return (typeof v === 'string' && v !== '') ? v : fallback;
    }

    var ticketId = 0;
    var csrf = '';
    var cfg = null;            // the config response
    var current = null;        // the dispatch (view) being worked in the dialog, or null for a new one
    var queue = null;          // the latest queue for the selected list
    var facilities = null;     // cached api/facilities.php rows
    var pendingPick = null;    // a pick waiting for an override reason
    var acceptFor = null;      // the pending call whose ETA is being entered
    var voidFor = 0;           // the ledger row whose void reason is being entered
    var inflight = false;
    var modalOpen = false;
    var formFor = -1;          // which dispatch the detail fields were last filled from (0 = new)
    var refreshTimer = null;

    function $(id) { return document.getElementById(id); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }

    function clear(n) { while (n && n.firstChild) n.removeChild(n.firstChild); }

    function val(id) { var n = $(id); return n ? String(n.value).replace(/^\s+|\s+$/g, '') : ''; }

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function hhmm(dt) {
        var m = /^\d{4}-\d{2}-\d{2} (\d{2}:\d{2})/.exec(dt || '');
        return m ? m[1] : String(dt || '');
    }

    function sinceLabel(dt) {
        var m = /^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2})/.exec(dt || '');
        if (!m) return '';
        var d = new Date();
        var today = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        return m[1] === today ? m[2] : (m[1].slice(5) + ' ' + m[2]);
    }

    function getTicketId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : 0;
    }

    function readLocal(key) {
        try { return window.localStorage.getItem(key) || ''; } catch (e) { return ''; }
    }

    function writeLocal(key, value) {
        try { window.localStorage.setItem(key, value); } catch (e) { /* private window: the page works without it */ }
    }

    function parse(r) {
        return r.json().then(function (d) {
            if (d && typeof d === 'object') { d._http = r.status; return d; }
            return { error: 'Unexpected response', _http: r.status };
        }, function () { return { error: 'Unexpected response', _http: r.status }; });
    }

    function apiGet(query) {
        return fetch('api/vendor-dispatch.php?' + query, { credentials: 'same-origin' }).then(parse);
    }

    function apiPost(body) {
        body.csrf_token = csrf;
        return fetch('api/vendor-dispatch.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        }).then(parse);
    }

    function announce(msg) {
        var n = $('vdLive');
        if (n) { n.textContent = ''; n.textContent = msg || ''; }
    }

    function showError(msg) {
        var n = $('vdError');
        if (!n) return;
        n.textContent = msg || '';
        n.classList.toggle('d-none', !msg);
        if (msg) announce(msg);
    }

    function hideError() { showError(''); }

    function findDispatch(id) {
        var ds = (cfg && cfg.dispatches) || [];
        for (var i = 0; i < ds.length; i++) { if (ds[i].id === id) return ds[i]; }
        return null;
    }

    function upsertDispatch(d) {
        var ds = cfg.dispatches;
        for (var i = 0; i < ds.length; i++) {
            if (ds[i].id === d.id) { ds[i] = d; return; }
        }
        ds.push(d);
    }

    function serviceTypeById(id) {
        var ts = cfg.service_types || [];
        for (var i = 0; i < ts.length; i++) { if (ts[i].id === id) return ts[i]; }
        return null;
    }

    function selectedServiceTypeId() { return parseInt(($('vdServiceType') || {}).value, 10) || 0; }
    function selectedListId() { return parseInt(($('vdList') || {}).value, 10) || 0; }

    function needsDestination() {
        var t = serviceTypeById(selectedServiceTypeId());
        return !!(t && t.needs_destination === 1);
    }

    // ═════════════════════════════════════════════════════════════
    // 2. The card on the incident page
    // ═════════════════════════════════════════════════════════════
    var STATUS_META = {
        open:      { icon: 'bi-hourglass-split', cls: 'text-bg-secondary', key: 'status_open',      text: 'OPEN' },
        assigned:  { icon: 'bi-truck',           cls: 'text-bg-primary',   key: 'status_assigned',  text: 'ASSIGNED' },
        on_scene:  { icon: 'bi-geo-alt-fill',    cls: 'text-bg-info',      key: 'status_on_scene',  text: 'ON SCENE' },
        completed: { icon: 'bi-check-circle',    cls: 'text-bg-success',   key: 'status_completed', text: 'COMPLETED' },
        cancelled: { icon: 'bi-x-circle',        cls: 'text-bg-dark',      key: 'status_cancelled', text: 'CANCELLED' },
        goa:       { icon: 'bi-slash-circle',    cls: 'text-bg-warning',   key: 'status_goa',       text: 'GONE ON ARRIVAL' }
    };

    function statusLabel(status) {
        var m = STATUS_META[status];
        return m ? S(m.key, m.text) : String(status);
    }

    /** Status is always text PLUS an icon, never colour alone. */
    function statusChip(status) {
        var m = STATUS_META[status] || STATUS_META.open;
        var chip = el('span', 'badge ' + m.cls);
        var i = el('i', 'bi ' + m.icon + ' me-1');
        i.setAttribute('aria-hidden', 'true');
        chip.appendChild(i);
        chip.appendChild(document.createTextNode(statusLabel(status)));
        return chip;
    }

    function etaText(d) {
        if (d.eta_minutes === null || d.eta_minutes === undefined) return '';
        if (d.status !== 'assigned' && d.status !== 'on_scene') return '';
        return 'ETA ' + d.eta_minutes + ' min' + (d.eta_clock ? ' (about ' + d.eta_clock + ')' : '');
    }

    function renderCard() {
        var box = $('vendorDispatchList');
        if (!box || !cfg) return;
        clear(box);
        var ds = cfg.dispatches || [];
        var count = $('vendorDispatchCount');
        if (count) count.textContent = String(ds.length);
        if (!ds.length) {
            box.appendChild(el('div', 'text-center text-body-secondary py-2 small', S('card_empty', 'No towing or roadside calls on this incident yet.')));
            return;
        }
        ds.forEach(function (d) {
            var row = el('div', 'd-flex flex-wrap align-items-center gap-2 px-2 py-1 border-bottom small vd-card-row');
            row.appendChild(el('strong', '', d.ref));
            row.appendChild(el('span', '', d.service_label));
            row.appendChild(el('span', 'text-body-secondary', d.provider_name || S('no_company', 'No company yet')));
            row.appendChild(statusChip(d.status));
            var eta = etaText(d);
            if (eta) row.appendChild(el('span', '', eta));
            var dest = d.dest_facility_name || d.dest_text;
            if (dest) row.appendChild(el('span', 'text-body-secondary', '\u2192 ' + dest));
            if (d.pending && d.pending.length) {
                var p = el('span', 'badge text-bg-warning');
                var pi = el('i', 'bi bi-telephone me-1');
                pi.setAttribute('aria-hidden', 'true');
                p.appendChild(pi);
                p.appendChild(document.createTextNode(d.pending.length + (d.pending.length === 1 ? ' call awaiting an outcome' : ' calls awaiting an outcome')));
                row.appendChild(p);
            }
            var btn = el('button', 'btn btn-sm btn-outline-primary ms-auto py-0', S('manage', 'Manage'));
            btn.type = 'button';
            btn.setAttribute('data-vd-manage', String(d.id));
            btn.setAttribute('aria-label', 'Manage ' + d.ref);
            row.appendChild(btn);
            box.appendChild(row);
        });
    }

    // ═════════════════════════════════════════════════════════════
    // 3. The dialog form
    // ═════════════════════════════════════════════════════════════
    function populateServiceTypes() {
        var sel = $('vdServiceType');
        clear(sel);
        (cfg.service_types || []).forEach(function (t) {
            var o = el('option', '', t.label);
            o.value = String(t.id);
            sel.appendChild(o);
        });
        if (current) {
            if (!serviceTypeById(current.service_type_id)) {   // a retired type is still shown for its own dispatch
                var ro = el('option', '', current.service_label);
                ro.value = String(current.service_type_id);
                sel.appendChild(ro);
            }
            sel.value = String(current.service_type_id);
            sel.disabled = true;
        } else {
            sel.disabled = false;
            var last = readLocal('vd_service');
            if (last && serviceTypeById(parseInt(last, 10))) sel.value = last;
        }
    }

    function populateLists() {
        var typeId = selectedServiceTypeId();
        var sel = $('vdList');
        clear(sel);
        var matching = (cfg.lists || []).filter(function (l) { return l.service_type_id === typeId; });
        matching.forEach(function (l) {
            var o = el('option', '', l.name + (l.is_default ? '  (default)' : ''));
            o.value = String(l.id);
            if (l.description) o.title = l.description;
            sel.appendChild(o);
        });
        var chosen = null;
        if (current && current.list_id) {
            matching.forEach(function (l) { if (l.id === current.list_id) chosen = l; });
        }
        if (!chosen) matching.forEach(function (l) { if (!chosen && l.is_default) chosen = l; });
        if (!chosen && matching.length) chosen = matching[0];
        if (chosen) sel.value = String(chosen.id);
        $('vdListWrap').classList.toggle('d-none', matching.length < 2);
        $('vdNoList').classList.toggle('d-none', matching.length > 0);
    }

    function populateOtherProviders() {
        var sel = $('vdOtherProvider');
        clear(sel);
        var none = el('option', '', '— none, I will type one —');
        none.value = '';
        sel.appendChild(none);
        (cfg.other_providers || []).forEach(function (p) {
            var o = el('option', '', p.name + (p.contact_name ? ' (' + p.contact_name + ')' : '') + (p.phone ? ' — ' + p.phone : ''));
            o.value = String(p.id);
            sel.appendChild(o);
        });
    }

    function yardFor(providerId) {
        if (!providerId) return null;
        var found = null;
        if (queue) queue.candidates.forEach(function (c) { if (c.provider_id === providerId && c.yard_facility_id) found = c.yard_facility_id; });
        if (found) return found;
        (cfg.other_providers || []).forEach(function (p) { if (p.id === providerId && p.yard_facility_id) found = p.yard_facility_id; });
        return found;
    }

    function facilityName(id) {
        var name = '';
        (facilities || []).forEach(function (f) { if (f.id === id) name = f.name || ''; });
        return name;
    }

    /** Destination: the provider's yard first (when it has one), then every facility, then "enter an address". */
    function buildDestOptions() {
        var sel = $('vdDestSelect');
        var keep = sel.value;
        clear(sel);
        var none = el('option', '', '— no destination yet —');
        none.value = '';
        sel.appendChild(none);
        var yardId = yardFor(current && current.provider_id ? current.provider_id : 0);
        if (yardId) {
            var yo = el('option', '', 'Provider’s yard: ' + (facilityName(yardId) || ('facility #' + yardId)));
            yo.value = 'f:' + yardId;
            sel.appendChild(yo);
        }
        (facilities || []).forEach(function (f) {
            var label = (f.name || ('facility #' + f.id)) + (f.street ? ' — ' + f.street + (f.city ? ', ' + f.city : '') : '');
            var o = el('option', '', label);
            o.value = 'f:' + f.id;
            sel.appendChild(o);
        });
        var other = el('option', '', 'Enter an address…');
        other.value = 'other';
        sel.appendChild(other);
        if (keep) sel.value = keep;
        if (sel.value !== keep) sel.value = '';
        toggleDestText();
    }

    function toggleDestText() {
        var show = $('vdDestSelect').value === 'other';
        $('vdDestText').classList.toggle('d-none', !show);
    }

    function ensureFacilities(done) {
        if (facilities !== null) { done(); return; }
        fetch('api/facilities.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { facilities = (d && d.facilities) ? d.facilities : []; done(); })
            .catch(function () { facilities = []; done(); });
    }

    /** Fill the detail fields from a dispatch, or blank them for a new one. Only when the context CHANGES, so a refresh never clobbers typing. */
    function fillDetails() {
        var key = current ? current.id : 0;
        if (formFor === key) return;
        formFor = key;
        var c = current || {};
        $('vdVehicle').value = c.vehicle_desc || '';
        $('vdPlate').value = c.plate || '';
        $('vdPlateState').value = c.plate_state || '';
        $('vdTowReason').value = c.tow_reason || '';
        $('vdNotes').value = c.notes || '';
        $('vdDestText').value = c.dest_text || '';
        buildDestOptions();
        if (c.dest_facility_id) $('vdDestSelect').value = 'f:' + c.dest_facility_id;
        else if (c.dest_text) $('vdDestSelect').value = 'other';
        else $('vdDestSelect').value = '';
        toggleDestText();
    }

    function detailsPayload() {
        var o = {
            vehicle_desc: val('vdVehicle'), plate: val('vdPlate'), plate_state: val('vdPlateState'),
            tow_reason: val('vdTowReason'), notes: val('vdNotes'), dest_facility_id: 0, dest_text: ''
        };
        if (needsDestination()) {
            var d = $('vdDestSelect').value;
            if (d.indexOf('f:') === 0) o.dest_facility_id = parseInt(d.slice(2), 10) || 0;
            else if (d === 'other') o.dest_text = val('vdDestText');
        }
        return o;
    }

    function openModal(dispatchId) {
        current = dispatchId ? findDispatch(dispatchId) : null;
        queue = null; pendingPick = null; acceptFor = null; voidFor = 0; formFor = -1;
        hideError();
        $('vdOwnerRequest').checked = false;
        $('vdOtherName').value = '';
        $('vdOtherPhone').value = '';
        $('vdCallback').value = readLocal('vd_callback');
        populateServiceTypes();
        populateLists();
        populateOtherProviders();
        var lbl = $('vdLogOtherLabel');
        if (lbl) lbl.textContent = (cfg.dial_mode === 'off') ? S('log_call', 'Log call') : S('call', 'Call');
        if (!current && cfg.ticket.closed) showError('This incident is closed. Reopen it before dispatching a company.');
        var modalEl = $('vendorDispatchModal');
        modalOpen = true;
        if (window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
        ensureFacilities(function () { renderAll(); loadQueue(); });
        renderAll();
    }

    function renderAll() {
        if (!cfg) return;
        var ref = $('vdRef');
        ref.classList.remove('d-none');
        ref.textContent = current ? current.ref : ('Incident ' + cfg.ticket.ref);
        fillDetails();
        $('vdDestWrap').classList.toggle('d-none', !needsDestination());
        buildDestOptionsIfNeeded();
        $('vdSaveDetailsWrap').classList.toggle('d-none', !current);
        $('vdLocation').textContent = locationText();
        renderPending();
        renderQueue();
        renderStatus();
        renderTimeline();
        renderReadAloud();
    }

    var destBuiltFor = '';
    function buildDestOptionsIfNeeded() {
        var key = (facilities ? facilities.length : -1) + '|' + (current ? current.id + ':' + (current.provider_id || 0) : 0) + '|' + (queue ? queue.list_id : 0);
        if (key === destBuiltFor) return;
        destBuiltFor = key;
        buildDestOptions();
        if (current && current.dest_facility_id && formFor === current.id && !$('vdDestSelect').value) {
            $('vdDestSelect').value = 'f:' + current.dest_facility_id;
        }
    }

    function locationText() {
        var t = cfg.ticket;
        var parts = [];
        if (t.street) parts.push(t.street);
        var cs = [t.city, t.state].filter(function (x) { return x; }).join(' ');
        if (cs) parts.push(cs);
        var s = parts.join(', ');
        if (t.address_about) s += (s ? ' ' : '') + '(' + t.address_about + ')';
        return s || '(no address on the incident)';
    }

    // ═════════════════════════════════════════════════════════════
    // 4. The queue and the calls awaiting an outcome
    // ═════════════════════════════════════════════════════════════
    function loadQueue() {
        var lid = selectedListId();
        if (!lid) { queue = null; renderQueue(); return; }
        var q = 'action=queue&ticket_id=' + ticketId + '&list_id=' + lid + (current ? '&dispatch_id=' + current.id : '');
        apiGet(q).then(function (d) {
            if (selectedListId() !== lid) return;          // the list changed while this was loading
            if (d.error) { showError(d.error); queue = null; }
            else { queue = d.queue; }
            renderAll();
        }).catch(function () { showError('Could not load the rotation queue.'); });
    }

    function digitsOnly(n) { return String(n || '').replace(/[^0-9+*#]/g, ''); }

    /** A phone number, as plain text or (in tel_link mode) a tel: anchor that records the call first. */
    function numberNode(number, providerId, onDial) {
        var ctx = JSON.stringify({ target_type: 'vendor_provider', target_id: providerId || 0, ticket_id: ticketId });
        var node;
        if (cfg.dial_mode === 'tel_link') {
            node = el('a', '', number);
            node.href = 'tel:' + digitsOnly(number);
            if (onDial) node.addEventListener('click', function (e) { e.preventDefault(); onDial(); });
        } else {
            node = el('span', '', number);
        }
        node.setAttribute('data-dial', number);
        node.setAttribute('data-dial-ctx', ctx);
        return node;
    }

    function dialNumber(number) {
        var mode = cfg.dial_mode;
        var clean = digitsOnly(number);
        if (!clean) return;
        if (mode === 'tel_link') {
            var a = document.createElement('a');
            a.href = 'tel:' + clean;
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
        } else if (mode === 'widget' && window.PhoneWidget && typeof window.PhoneWidget.placeCall === 'function') {
            try {
                if (typeof window.PhoneWidget.show === 'function') window.PhoneWidget.show();
                window.PhoneWidget.placeCall(clean);
            } catch (e) { /* the call was already recorded; the dispatcher can dial by hand */ }
        }
    }

    function chipFor(c) {
        var chip = el('span', 'badge');
        var icon = '';
        var text = '';
        var cls = 'text-bg-light border';
        if (c.state) {
            var map = { calling: ['bi-telephone-outbound', 'text-bg-warning', S('calling', 'calling…')],
                        accepted: ['bi-check-circle', 'text-bg-success', 'accepted'],
                        declined: ['bi-x-circle', 'text-bg-secondary', 'declined'],
                        no_answer: ['bi-telephone-x', 'text-bg-secondary', 'no answer'],
                        unavailable: ['bi-slash-circle', 'text-bg-secondary', 'unavailable'],
                        withdrew: ['bi-arrow-counterclockwise', 'text-bg-secondary', 'withdrew'] };
            var m = map[c.state] || ['bi-dot', cls, c.state];
            icon = m[0]; cls = m[1]; text = m[2];
        } else if (!c.eligible) {
            icon = 'bi-pause-circle'; cls = 'text-bg-secondary';
            text = c.suspended_until ? ('suspended until ' + sinceLabel(c.suspended_until)) : 'not eligible';
        } else if (c.is_head && queue.mode !== 'manual') {
            icon = 'bi-arrow-right-circle-fill'; cls = 'text-bg-primary'; text = S('next_up', 'NEXT UP');
        } else if (c.last_turn_at) {
            icon = 'bi-clock'; cls = 'text-bg-light border'; text = 'waiting since ' + sinceLabel(c.last_turn_at);
        } else {
            icon = 'bi-clock'; cls = 'text-bg-light border'; text = 'no calls yet';
        }
        chip.className = 'badge ' + cls;
        var i = el('i', 'bi ' + icon + ' me-1');
        i.setAttribute('aria-hidden', 'true');
        chip.appendChild(i);
        chip.appendChild(document.createTextNode(text));
        return chip;
    }

    function renderQueue() {
        var wrap = $('vdQueueWrap');
        var body = $('vdQueueBody');
        clear(body);
        var visible = !!queue && (!current || current.status === 'open');
        wrap.classList.toggle('d-none', !visible);
        if (!visible) return;

        var modeText = { round_robin: 'Round robin: the company called longest ago is next',
                         strict_order: 'Strict order: the list order is fixed',
                         manual: 'Manual: the system shows the order, you choose' }[queue.mode] || '';
        $('vdQueueMode').textContent = modeText ? '(' + modeText + ')' : '';

        if (!queue.candidates.length) {
            var tr0 = el('tr');
            var td0 = el('td', 'text-body-secondary', 'This list has no companies yet. Use "Another company" below, or add companies under Settings > Service Providers.');
            td0.colSpan = 5;
            tr0.appendChild(td0);
            body.appendChild(tr0);
            return;
        }
        var callLabel = (cfg.dial_mode === 'off') ? S('log_call', 'Log call') : S('call', 'Call');
        queue.candidates.forEach(function (c) {
            var tr = el('tr');
            if (c.is_head && queue.mode !== 'manual' && !c.state) tr.className = 'table-primary';
            tr.appendChild(el('td', '', String(c.rank)));

            var tdName = el('td');
            tdName.appendChild(el('div', 'fw-semibold', c.name));
            var sub = [];
            if (c.contact_name) sub.push(c.contact_name);
            if (c.hours_note) sub.push(c.hours_note);
            if (c.service_area) sub.push(c.service_area);
            if (sub.length) tdName.appendChild(el('div', 'text-body-secondary', sub.join(' · ')));
            tr.appendChild(tdName);

            var pick = { provider_id: c.provider_id, name: c.name, phone: c.phone, eligible: c.eligible, ownerRequest: false };
            var tdNum = el('td');
            tdNum.appendChild(numberNode(c.phone, c.provider_id, c.eligible && !c.state ? function () { startCall(pick); } : null));
            if (c.phone_alt) {
                tdNum.appendChild(el('br'));
                tdNum.appendChild(numberNode(c.phone_alt, c.provider_id, null));
            }
            tr.appendChild(tdNum);

            var tdChip = el('td');
            tdChip.appendChild(chipFor(c));
            tr.appendChild(tdChip);

            var tdAct = el('td', 'text-end');
            if (c.eligible && !c.state) {
                var b = el('button', 'btn btn-sm ' + (c.is_head && queue.mode !== 'manual' ? 'btn-primary' : 'btn-outline-primary'));
                b.type = 'button';
                var bi = el('i', 'bi bi-telephone me-1');
                bi.setAttribute('aria-hidden', 'true');
                b.appendChild(bi);
                b.appendChild(document.createTextNode(callLabel));
                b.setAttribute('aria-label', callLabel + ' ' + c.name);
                b.addEventListener('click', function () { startCall(pick); });
                tdAct.appendChild(b);
            }
            tr.appendChild(tdAct);
            body.appendChild(tr);
        });
    }

    function renderPending() {
        var wrap = $('vdPendingWrap');
        var box = $('vdPendingList');
        clear(box);
        var pend = current && current.pending ? current.pending : [];
        wrap.classList.toggle('d-none', !pend.length);
        if (!pend.length) { acceptFor = null; $('vdAcceptBox').classList.add('d-none'); return; }
        pend.forEach(function (p) {
            var row = el('div', 'd-flex flex-wrap align-items-center gap-2 mb-1');
            row.appendChild(el('strong', '', p.provider_name));
            if (p.provider_phone) row.appendChild(numberNode(p.provider_phone, p.provider_id, null));
            row.appendChild(el('span', 'text-body-secondary', 'called ' + hhmm(p.event_at)));
            var grp = el('div', 'btn-group btn-group-sm ms-auto');
            grp.setAttribute('role', 'group');
            [['accepted', 'Accepted', 'btn-outline-success'], ['declined', 'Declined', 'btn-outline-secondary'],
             ['no_answer', 'No answer', 'btn-outline-secondary'], ['unavailable', 'Unavailable', 'btn-outline-secondary']].forEach(function (o) {
                var b = el('button', 'btn ' + o[2], o[1]);
                b.type = 'button';
                b.setAttribute('aria-label', o[1] + ': ' + p.provider_name);
                b.addEventListener('click', function () {
                    if (o[0] === 'accepted') { acceptFor = p; $('vdAcceptBox').classList.remove('d-none'); $('vdAcceptEta').focus(); }
                    else { postOutcome(p, o[0], null); }
                });
                grp.appendChild(b);
            });
            row.appendChild(grp);
            box.appendChild(row);
        });
        $('vdAcceptBox').classList.toggle('d-none', !acceptFor);
    }

    // ═════════════════════════════════════════════════════════════
    // 5. Actions: log a call, record an outcome, save details, status, note, void
    // ═════════════════════════════════════════════════════════════
    function setBusy(busy) {
        var m = $('vendorDispatchModal');
        if (!m) return;
        var btns = m.querySelectorAll('button');
        for (var i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-bs-dismiss')) continue;
            if (busy) { btns[i].setAttribute('data-vd-was-disabled', btns[i].disabled ? '1' : '0'); btns[i].disabled = true; }
            else if (btns[i].hasAttribute('data-vd-was-disabled')) {
                btns[i].disabled = btns[i].getAttribute('data-vd-was-disabled') === '1';
                btns[i].removeAttribute('data-vd-was-disabled');
            }
        }
    }

    function handleError(d) {
        showError(d.error || 'The request failed.');
        if (d.queue) { queue = d.queue; renderAll(); }
    }

    /** POST an action, then refresh the dialog and the card from the answer. */
    function runAction(body, okMessage, after) {
        if (inflight) return;
        inflight = true;
        setBusy(true);
        hideError();
        apiPost(body).then(function (d) {
            inflight = false;
            setBusy(false);
            if (d.error || d.success !== true) { handleError(d); return; }
            if (d.dispatch) { current = d.dispatch; upsertDispatch(d.dispatch); }
            renderCard();
            renderAll();
            loadQueue();
            if (okMessage) announce(okMessage);
            if (after) after(d);
        }).catch(function () {
            inflight = false;
            setBusy(false);
            showError('Could not reach the server. Nothing was recorded.');
        });
    }

    /** The dispatcher chose a company to call. Ask for a reason first when this pick skips an eligible next-up. */
    function startCall(pick) {
        if (inflight) return;
        hideError();
        var listId = selectedListId();
        var skipsNext = !!(listId && queue && queue.mode !== 'manual' && !pick.ownerRequest && pick.provider_id
                           && pick.eligible && queue.head_provider_id && pick.provider_id !== queue.head_provider_id);
        if (skipsNext) {
            if (!cfg.settings.allow_override) {
                showError('This agency requires calling the company that is next in the rotation.');
                return;
            }
            if (cfg.settings.override_requires_reason) { pendingPick = pick; showReasonBox(true); return; }
        }
        sendOffer(pick, '');
    }

    function showReasonBox(show) {
        $('vdReasonBox').classList.toggle('d-none', !show);
        if (show) {
            $('vdReasonOther').value = '';
            var radios = document.getElementsByName('vdReasonPreset');
            for (var i = 0; i < radios.length; i++) radios[i].checked = false;
            if (radios.length) radios[0].focus();
        }
    }

    function reasonFromBox() {
        var radios = document.getElementsByName('vdReasonPreset');
        var picked = null;
        for (var i = 0; i < radios.length; i++) { if (radios[i].checked) picked = radios[i]; }
        var other = val('vdReasonOther');
        if (picked && picked.value) return other ? (picked.value + ': ' + other) : picked.value;
        return other;
    }

    function sendOffer(pick, reason) {
        var body = {
            action: 'offer', service_type_id: selectedServiceTypeId(), list_id: selectedListId(),
            provider_id: pick.provider_id || 0, provider_name: pick.typedName || '', provider_phone: pick.typedPhone || '',
            client_head_provider_id: (queue && queue.head_provider_id) ? queue.head_provider_id : 0,
            owner_request: !!pick.ownerRequest, reason: reason || ''
        };
        if (current) body.dispatch_id = current.id;
        else { body.ticket_id = ticketId; var dp = detailsPayload(); for (var k in dp) { if (dp.hasOwnProperty(k)) body[k] = dp[k]; } }
        if (!current) writeLocal('vd_service', String(selectedServiceTypeId()));

        if (inflight) return;
        inflight = true;
        setBusy(true);
        hideError();
        apiPost(body).then(function (d) {
            inflight = false;
            setBusy(false);
            if (d.error || d.success !== true) {
                if (d.code === 'reason_required') { pendingPick = pick; showReasonBox(true); }
                handleError(d);       // queue_changed lands here: nothing was recorded, so NOTHING IS DIALLED
                return;
            }
            showReasonBox(false);
            pendingPick = null;
            current = d.dispatch;
            upsertDispatch(d.dispatch);
            if (d.queue) queue = d.queue;
            formFor = current.id;
            renderCard();
            renderAll();
            announce('Call to ' + (pick.name || pick.typedName || 'the company') + ' recorded (' + (METHOD_LABEL[d.selection_method] || 'logged') + ').');
            // Record first, dial second.
            var number = pick.phone || pick.typedPhone || '';
            if (number) dialNumber(number);
        }).catch(function () {
            inflight = false;
            setBusy(false);
            showError('Could not reach the server. Nothing was recorded.');
        });
    }

    function startOtherCall() {
        hideError();
        var providerId = parseInt($('vdOtherProvider').value, 10) || 0;
        var typedName = val('vdOtherName');
        var typedPhone = val('vdOtherPhone');
        var owner = $('vdOwnerRequest').checked;
        if (!providerId && (!typedName || !typedPhone)) {
            showError('Choose a company we have on file, or type a company name and a phone number.');
            return;
        }
        var pick = { provider_id: providerId, ownerRequest: owner, eligible: false, typedName: providerId ? '' : typedName, typedPhone: providerId ? '' : typedPhone };
        if (providerId) {
            (cfg.other_providers || []).forEach(function (p) { if (p.id === providerId) { pick.name = p.name; pick.phone = p.phone; } });
        } else {
            pick.name = typedName; pick.phone = typedPhone;
        }
        sendOffer(pick, '');
    }

    function postOutcome(pend, outcome, eta) {
        if (!current) return;
        var body = { action: 'outcome', dispatch_id: current.id, offer_event_id: pend.event_id, outcome: outcome };
        if (eta !== null) body.eta_minutes = eta;
        acceptFor = null;
        runAction(body, pend.provider_name + ' ' + outcome.replace('_', ' ') + '.');
    }

    function postStatus(event, eta, confirmText) {
        if (!current) return;
        if (confirmText && !window.confirm(confirmText)) return;
        var body = { action: 'status', dispatch_id: current.id, event: event };
        if (eta !== null && eta !== undefined) body.eta_minutes = eta;
        runAction(body, 'Dispatch ' + current.ref + ': ' + event.replace('_', ' ') + '.');
    }

    // ═════════════════════════════════════════════════════════════
    // 6. Status panel, timeline, read-aloud sheet
    // ═════════════════════════════════════════════════════════════
    function renderStatus() {
        var wrap = $('vdStatusWrap');
        wrap.classList.toggle('d-none', !current);
        if (!current) return;
        var sum = $('vdStatusSummary');
        clear(sum);
        sum.appendChild(statusChip(current.status));
        var txt = '';
        if (current.provider_name) txt += ' ' + current.provider_name + (current.provider_phone ? ' (' + current.provider_phone + ')' : '');
        var eta = etaText(current);
        if (eta) txt += ' · ' + eta;
        if (current.assigned_at) txt += ' · accepted ' + hhmm(current.assigned_at);
        if (current.closed_at) txt += ' · closed ' + hhmm(current.closed_at);
        txt += ' · started by ' + current.created_by_name + ' at ' + hhmm(current.created_at);
        sum.appendChild(document.createTextNode(txt));

        var st = current.status;
        var terminal = (st === 'completed' || st === 'cancelled' || st === 'goa');
        $('btnVdOnScene').disabled = (st !== 'assigned');
        $('btnVdComplete').disabled = !(st === 'assigned' || st === 'on_scene');
        $('btnVdWithdrew').disabled = !(st === 'assigned' || st === 'on_scene');
        $('btnVdGoa').disabled = terminal;
        $('btnVdCancel').disabled = terminal;
        $('btnVdEta').disabled = !(st === 'assigned' || st === 'on_scene');
        $('vdEtaInput').disabled = !(st === 'assigned' || st === 'on_scene');
    }

    var EVENT_LABEL = { offered: 'Called', accepted: 'Accepted', declined: 'Declined', no_answer: 'No answer', unavailable: 'Unavailable',
                        withdrew: 'Withdrew', eta_update: 'ETA update', on_scene: 'On scene', completed: 'Completed',
                        cancelled: 'Cancelled', goa: 'Gone on arrival', note: 'Note', voided: 'Voided' };
    var METHOD_LABEL = { rotation: 'next up', override: 'override', owner_request: 'driver/owner request', unlisted: 'not on a list' };

    function renderTimeline() {
        var wrap = $('vdTimelineWrap');
        var box = $('vdTimeline');
        clear(box);
        var events = current && current.events ? current.events : [];
        wrap.classList.toggle('d-none', !events.length);
        $('vdVoidBox').classList.toggle('d-none', !voidFor);
        events.forEach(function (e) {
            var row = el('div', 'd-flex flex-wrap align-items-baseline gap-2 border-bottom py-1' + (e.voided ? ' text-decoration-line-through text-body-secondary' : ''));
            row.appendChild(el('span', 'text-body-secondary', hhmm(e.event_at)));
            row.appendChild(el('strong', '', EVENT_LABEL[e.event_type] || e.event_type));
            if (e.provider_name) row.appendChild(el('span', '', e.provider_name));
            if (e.selection_method && e.event_type === 'offered') row.appendChild(el('span', 'badge text-bg-light border', (METHOD_LABEL[e.selection_method] || e.selection_method) + (e.consumed_turn ? ', used a turn' : '')));
            if (e.eta_minutes !== null && e.eta_minutes !== undefined) row.appendChild(el('span', '', 'ETA ' + e.eta_minutes + ' min'));
            var extra = e.reason || e.detail;
            if (extra) row.appendChild(el('span', 'text-body-secondary', extra));
            row.appendChild(el('span', 'text-body-secondary ms-auto', e.actor_name));
            if (e.voided) row.appendChild(el('span', 'badge text-bg-secondary', 'voided'));
            if (e.can_void) {
                var b = el('button', 'btn btn-sm btn-outline-warning py-0', 'Void');
                b.type = 'button';
                b.setAttribute('aria-label', 'Void the ' + (EVENT_LABEL[e.event_type] || e.event_type) + ' entry at ' + hhmm(e.event_at));
                b.addEventListener('click', function () {
                    voidFor = e.id;
                    $('vdVoidBox').classList.remove('d-none');
                    $('vdVoidReason').value = '';
                    $('vdVoidReason').focus();
                });
                row.appendChild(b);
            }
            box.appendChild(row);
        });
    }

    function destinationText() {
        if (!needsDestination()) return '';
        var d = $('vdDestSelect').value;
        if (d.indexOf('f:') === 0) return facilityName(parseInt(d.slice(2), 10)) || '';
        if (d === 'other') return val('vdDestText');
        return '';
    }

    function renderReadAloud() {
        if (!cfg) return;
        var t = cfg.ticket;
        var lines = [];
        var addr = [t.street, t.city, t.state].filter(function (x) { return x; }).join(', ');
        lines.push('Pickup: ' + (addr || '(no address on the incident)'));
        if (t.address_about) lines.push('Cross street / landmark: ' + t.address_about);
        var veh = val('vdVehicle');
        if (veh) lines.push('Vehicle: ' + veh);
        var plate = val('vdPlate');
        if (plate) lines.push('Plate: ' + plate + (val('vdPlateState') ? ' (' + val('vdPlateState') + ')' : ''));
        var dest = destinationText();
        if (dest) lines.push('Destination: ' + dest);
        var cb = val('vdCallback');
        if (cb) lines.push('Callback number: ' + cb);
        lines.push('Reference: ' + (current ? current.ref : '(assigned when the first call is logged)'));
        $('vdReadAloud').textContent = lines.join('\n');
    }

    function copyReadAloud() {
        var text = $('vdReadAloud').textContent;
        var done = function () { announce('Copied to the clipboard.'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
        } else {
            fallbackCopy(text, done);
        }
    }

    function fallbackCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { if (document.execCommand('copy')) done(); } catch (e) { /* the sheet is selectable by hand */ }
        document.body.removeChild(ta);
    }

    // ═════════════════════════════════════════════════════════════
    // 7. Boot
    // ═════════════════════════════════════════════════════════════
    function bind() {
        var open1 = $('btnVendorDispatch');
        if (open1) open1.addEventListener('click', function () { openModal(0); });
        var open2 = $('btnVendorDispatchCard');
        if (open2) open2.addEventListener('click', function () { openModal(0); });
        var list = $('vendorDispatchList');
        if (list) list.addEventListener('click', function (e) {
            var b = e.target.closest ? e.target.closest('[data-vd-manage]') : null;
            if (b) openModal(parseInt(b.getAttribute('data-vd-manage'), 10));
        });

        $('vdServiceType').addEventListener('change', function () {
            populateLists();
            destBuiltFor = '';
            renderAll();
            loadQueue();
        });
        $('vdList').addEventListener('change', function () { loadQueue(); });
        $('vdDestSelect').addEventListener('change', function () { toggleDestText(); renderReadAloud(); });
        ['vdVehicle', 'vdPlate', 'vdPlateState', 'vdDestText'].forEach(function (id) {
            $(id).addEventListener('input', renderReadAloud);
        });
        $('vdCallback').addEventListener('input', function () { writeLocal('vd_callback', val('vdCallback')); renderReadAloud(); });

        $('btnVdLogOther').addEventListener('click', startOtherCall);
        $('btnVdReasonOk').addEventListener('click', function () {
            var reason = reasonFromBox();
            if (!reason) { showError('Choose a reason or type one.'); return; }
            var pick = pendingPick;
            if (pick) sendOffer(pick, reason);
        });
        $('btnVdReasonCancel').addEventListener('click', function () { pendingPick = null; showReasonBox(false); });

        $('btnVdAcceptOk').addEventListener('click', function () {
            var eta = parseInt($('vdAcceptEta').value, 10);
            if (!(eta >= 0 && eta <= 1440)) { showError('Enter the ETA in minutes (0 to 1440).'); return; }
            if (acceptFor) postOutcome(acceptFor, 'accepted', eta);
        });
        $('btnVdAcceptCancel').addEventListener('click', function () { acceptFor = null; $('vdAcceptBox').classList.add('d-none'); });

        $('btnVdSaveDetails').addEventListener('click', function () {
            if (!current) return;
            var body = detailsPayload();
            body.action = 'update';
            body.dispatch_id = current.id;
            runAction(body, 'Details saved.');
        });

        $('btnVdOnScene').addEventListener('click', function () { postStatus('on_scene', null, ''); });
        $('btnVdComplete').addEventListener('click', function () { postStatus('completed', null, ''); });
        $('btnVdWithdrew').addEventListener('click', function () { postStatus('withdrew', null, 'Record that the company backed out? This dispatch goes back to open so you can call another company.'); });
        $('btnVdGoa').addEventListener('click', function () { postStatus('goa', null, 'Mark this dispatch gone on arrival?'); });
        $('btnVdCancel').addEventListener('click', function () { postStatus('cancelled', null, 'Cancel this dispatch? This cannot be undone (a supervisor can void the entry).'); });
        $('btnVdEta').addEventListener('click', function () {
            var eta = parseInt($('vdEtaInput').value, 10);
            if (!(eta >= 0 && eta <= 1440)) { showError('Enter the new ETA in minutes (0 to 1440).'); return; }
            postStatus('eta_update', eta, '');
        });
        $('btnVdNote').addEventListener('click', function () {
            var text = val('vdNoteInput');
            if (!current || !text) return;
            runAction({ action: 'note', dispatch_id: current.id, text: text }, 'Note added.', function () { $('vdNoteInput').value = ''; });
        });
        $('vdNoteInput').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); $('btnVdNote').click(); } });

        $('btnVdVoidOk').addEventListener('click', function () {
            var reason = val('vdVoidReason');
            if (!reason) { showError('A reason is required to void an entry.'); return; }
            var id = voidFor;
            voidFor = 0;
            runAction({ action: 'void', event_id: id, reason: reason }, 'Entry voided.');
        });
        $('btnVdVoidCancel').addEventListener('click', function () { voidFor = 0; $('vdVoidBox').classList.add('d-none'); });
        $('btnVdCopy').addEventListener('click', copyReadAloud);

        var modalEl = $('vendorDispatchModal');
        modalEl.addEventListener('shown.bs.modal', function () {
            var target = null;
            if (current && current.pending && current.pending.length) target = $('vdPendingList').querySelector('button');
            if (!target && !$('vdServiceType').disabled) target = $('vdServiceType');
            if (!target && current) target = $('btnVdOnScene').disabled ? $('btnVdNote') : $('btnVdOnScene');
            if (target && !target.disabled) target.focus();
        });
        modalEl.addEventListener('hidden.bs.modal', function () { modalOpen = false; pendingPick = null; acceptFor = null; voidFor = 0; });
    }

    function refreshDispatches() {
        apiGet('action=ticket&ticket_id=' + ticketId).then(function (d) {
            if (d.error) return;
            cfg.dispatches = d.dispatches || [];
            renderCard();
            if (modalOpen && current) {
                var f = findDispatch(current.id);
                if (f) { current = f; renderAll(); loadQueue(); }
            }
        }).catch(function () { /* the next event or reload catches up */ });
    }

    function wireLive(attempt) {
        if (typeof EventBus === 'undefined' || !EventBus.on) {
            if (attempt < 10) setTimeout(function () { wireLive(attempt + 1); }, 500);
            return;
        }
        EventBus.on('vendor:dispatch', function (p) {
            if (p && p.ticket_id !== undefined && parseInt(p.ticket_id, 10) !== ticketId) return;
            if (refreshTimer) clearTimeout(refreshTimer);
            refreshTimer = setTimeout(refreshDispatches, 300);
        });
    }

    function boot() {
        if (!$('vendorDispatchCard') || !$('vendorDispatchModal')) return;
        ticketId = getTicketId();
        if (!ticketId) return;
        var c = $('csrfToken');
        csrf = c ? c.value : '';
        apiGet('action=config&ticket_id=' + ticketId).then(function (d) {
            // enabled:false, 403 (can see it, cannot change it), 404, 409: leave the button and card hidden.
            if (!d || d.error || d.enabled !== true) return;
            cfg = d;
            bind();
            $('vendorDispatchCard').classList.remove('d-none');
            var b = $('btnVendorDispatch');
            if (b) b.classList.remove('d-none');
            renderCard();
            wireLive(0);
        }).catch(function () { /* the page works without the card */ });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
