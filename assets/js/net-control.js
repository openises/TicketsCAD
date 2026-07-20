/**
 * Net Control Board — Phase 109 Slice A
 *
 * ES5 IIFE, var only. No arrow functions / template literals / let /
 * const / destructuring. Uses fetch + addEventListener per NewUI
 * conventions. Escapes every rendered string.
 *
 * Responsibilities:
 *   - Load board payload (api/net-control.php) for the selected event
 *   - Render units × [roster / zone / last check-in / global status]
 *   - Fast zone entry: click a Zone cell (picker) OR select a row and
 *     press digit 1-9 to set the Nth zone (api/event-zone-update.php)
 *   - Edit zones modal (api/event-zones.php) for admins
 *   - Auto-refresh every 15s + on SSE responder:status / responder:assign
 */
(function () {
    'use strict';

    var CFG = window.NC_CONFIG || { csrf: '', canManageZones: false, canUpdateZone: false, canIssueEquipment: false };
    var STORAGE_KEY = 'nc_selected_event';
    var REFRESH_MS = 15000;

    var state = {
        ticketId: 0,
        zones: [],
        units: [],
        selectedAssignId: null
    };

    var els = {};
    var refreshTimer = null;

    // ── Utilities ───────────────────────────────────────────────────
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function byId(id) { return document.getElementById(id); }

    function showAlert(msg, type) {
        if (!els.alert) return;
        var cls = type === 'error' ? 'danger' : (type || 'info');
        els.alert.innerHTML =
            '<div class="alert alert-' + cls + ' alert-dismissible fade show py-2" role="alert">' +
            esc(msg) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }

    function clearAlert() { if (els.alert) els.alert.innerHTML = ''; }

    // Pick a readable text color for a given background hex.
    function textOn(bg) {
        if (!bg) return '';
        var hex = String(bg).replace('#', '');
        if (hex.length === 3) {
            hex = hex.charAt(0) + hex.charAt(0) + hex.charAt(1) + hex.charAt(1) + hex.charAt(2) + hex.charAt(2);
        }
        if (hex.length !== 6) return '';
        var r = parseInt(hex.substring(0, 2), 16);
        var g = parseInt(hex.substring(2, 4), 16);
        var b = parseInt(hex.substring(4, 6), 16);
        if (isNaN(r) || isNaN(g) || isNaN(b)) return '';
        var lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return lum > 0.6 ? '#000' : '#fff';
    }

    function zoneStyle(color) {
        if (!color) return '';
        var fg = textOn(color);
        var s = 'background:' + esc(color) + ';';
        if (fg) s += 'color:' + fg + ';';
        return s;
    }

    // ── API calls ───────────────────────────────────────────────────
    function loadBoard() {
        if (!state.ticketId) {
            renderEmpty('Select an open event to begin.');
            return;
        }
        fetch('api/net-control.php?ticket_id=' + encodeURIComponent(state.ticketId), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data && data.error) {
                showAlert(data.error, 'error');
                return;
            }
            state.zones = (data && data.zones) || [];
            state.units = (data && data.units) || [];
            state.signedOut = (data && data.signed_out) || [];
            state.parCadenceSecs = (data && data.par && data.par.cadence_secs) || 0;
            renderZoneChips();
            renderBoard();
            renderSignedOutTray();
        })
        .catch(function (e) {
            showAlert('Failed to load the board. ' + (e && e.message ? e.message : ''), 'error');
        });
    }

    function postJson(url, body) {
        body = body || {};
        body.csrf_token = CFG.csrf;
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    // ── Zone move (the fast path) ───────────────────────────────────
    function moveUnit(assignId, zoneId) {
        if (!CFG.canUpdateZone) {
            showAlert('You do not have permission to move units.', 'error');
            return;
        }
        postJson('api/event-zone-update.php', {
            ticket_id: state.ticketId,
            assign_id: assignId,
            zone_id: zoneId
        }).then(function (res) {
            if (res && res.error) {
                showAlert(res.error, 'error');
                return;
            }
            clearAlert();
            // Optimistic local update, then a quiet reload to pick up the
            // fresh check-in timestamp.
            loadBoard();
        }).catch(function (e) {
            showAlert('Zone update failed. ' + (e && e.message ? e.message : ''), 'error');
        });
    }

    // ── Render: zone chips toolbar ──────────────────────────────────
    function renderZoneChips() {
        if (!els.zoneChips) return;
        if (!state.zones.length) {
            els.zoneChips.innerHTML = '<span class="text-body-secondary small fst-italic">No zones defined for this event.</span>';
            return;
        }
        var html = '';
        var i;
        for (i = 0; i < state.zones.length; i++) {
            var z = state.zones[i];
            html += '<span class="nc-zone-chip" style="' + zoneStyle(z.color) + '">' +
                    '<span class="nc-idx-key">' + (i + 1) + '</span>' +
                    esc(z.name) + '</span>';
        }
        els.zoneChips.innerHTML = html;
    }

    // ── Render: board ───────────────────────────────────────────────
    function renderEmpty(msg) {
        if (els.body) {
            els.body.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">' +
                esc(msg) + '</td></tr>';
        }
        if (els.count) els.count.textContent = '0';
    }

    function checkinClass(secs) {
        if (secs === null || secs === undefined) return '';
        // Ramp off the event's configured PAR cadence when PAR is enabled
        // (per-incident / incident-type / agency default). Amber at the
        // cadence, red at 1.5x. Falls back to fixed 10/20-minute thresholds
        // when PAR is disabled for this event.
        var cad = state.parCadenceSecs || 0;
        var warnAt = cad > 0 ? cad : 600;
        var dangerAt = cad > 0 ? Math.round(cad * 1.5) : 1200;
        if (secs > dangerAt) return 'nc-checkin-danger';
        if (secs > warnAt) return 'nc-checkin-warn';
        return '';
    }

    function rosterHtml(roster) {
        if (!roster || !roster.length) {
            return '<span class="text-body-secondary small fst-italic">—</span>';
        }
        var parts = [];
        var i;
        for (i = 0; i < roster.length; i++) {
            var m = roster[i];
            var name = esc(m.name);
            var handle = m.handle ? ' <span class="text-body-secondary">(' + esc(m.handle) + ')</span>' : '';
            var star = m.is_lead ? '<i class="bi bi-star-fill me-1"></i>' : '';
            var cls = m.is_lead ? 'nc-roster-lead' : '';
            parts.push('<span class="' + cls + '">' + star + name + handle + gearHtml(m) + '</span>');
        }
        return parts.join('<span class="text-body-secondary">, </span>');
    }

    // Slice C — the gear a member carries (📻 chips), plus an "issue" button.
    // A chip is click-to-return when the operator can issue equipment.
    function gearHtml(m) {
        var out = '';
        var eq = m.equipment || [];
        var j;
        for (j = 0; j < eq.length; j++) {
            var g = eq[j];
            var attr = CFG.canIssueEquipment
                ? ' data-return="' + g.equipment_id + '" role="button" title="Return: ' + esc(g.label) + '" style="cursor:pointer;"'
                : ' title="' + esc(g.label) + '"';
            out += ' <span class="badge rounded-pill bg-info-subtle text-info-emphasis nc-gear"' + attr +
                   '><i class="bi bi-broadcast me-1"></i>' + esc(g.label) + '</span>';
        }
        if (CFG.canIssueEquipment) {
            out += ' <button type="button" class="btn btn-outline-info nc-issue-gear py-0 px-1" ' +
                   'data-member="' + m.member_id + '" data-member-name="' + esc(m.name) +
                   '" title="Issue equipment to ' + esc(m.name) + '" style="font-size:.6rem;line-height:1.2;">' +
                   '<i class="bi bi-plus-lg"></i><i class="bi bi-broadcast"></i></button>';
        }
        return out;
    }

    function zoneCellHtml(unit) {
        if (unit.current_zone_id && unit.zone_name) {
            return '<span class="nc-zone-badge" style="' + zoneStyle(unit.zone_color) + '" ' +
                   'data-assign="' + unit.assign_id + '" role="button" tabindex="0">' +
                   esc(unit.zone_name) + '</span>';
        }
        return '<span class="nc-zone-badge nc-zone-empty" data-assign="' + unit.assign_id +
               '" role="button" tabindex="0">set zone</span>';
    }

    function globalHtml(unit) {
        if (!unit.global_status) {
            return '<span class="text-body-secondary small">—</span>';
        }
        return '<span class="nc-zone-chip" style="' + zoneStyle(unit.global_status_color) + '">' +
               esc(unit.global_status) + '</span>';
    }

    function renderBoard() {
        if (!els.body) return;
        if (!state.units.length) {
            renderEmpty('No units assigned to this event yet.');
            return;
        }
        var html = '';
        var i;
        for (i = 0; i < state.units.length; i++) {
            var u = state.units[i];
            var teamName = u.callsign ? u.callsign : u.name;
            var teamSub = (u.callsign && u.name && u.callsign !== u.name)
                ? '<div class="small text-body-secondary">' + esc(u.name) + '</div>' : '';
            var ccls = checkinClass(u.last_checkin_secs);
            var selCls = (state.selectedAssignId === u.assign_id) ? ' nc-selected' : '';

            html += '<tr class="nc-board-row' + selCls + '" data-assign="' + u.assign_id +
                    '" tabindex="0">' +
                    '<td><span class="fw-semibold">' + esc(teamName) + '</span>' + teamSub + '</td>' +
                    '<td>' + rosterHtml(u.roster) + '</td>' +
                    '<td>' + zoneCellHtml(u) + '</td>' +
                    '<td><span class="' + ccls + '">' + esc(u.last_checkin_ago || '—') + '</span></td>' +
                    '<td>' + globalHtml(u) + signoutBtnHtml(u) + '</td>' +
                    '</tr>';
        }
        els.body.innerHTML = html;
        if (els.count) els.count.textContent = String(state.units.length);
        wireRowEvents();
        wireSignoutButtons();
        wireEquipmentButtons();
    }

    // ── Equipment cache checkout (Phase 109 Slice C) ────────────────────
    // Wire click-to-return on every gear chip inside `container`. Reused by the
    // active board AND the signed-out tray (so gear can be returned at tear-down).
    function wireGearReturns(container) {
        if (!container || !CFG.canIssueEquipment) return;
        var chips = container.querySelectorAll('.nc-gear[data-return]');
        for (var i = 0; i < chips.length; i++) {
            chips[i].addEventListener('click', function (e) {
                e.stopPropagation();
                var eid = parseInt(this.getAttribute('data-return'), 10);
                if (!eid || !window.confirm('Return this equipment to the cache?')) return;
                postJson('api/equipment-assign.php', { action: 'return', equipment_id: eid })
                    .then(function (r) {
                        if (r && r.error) { showAlert(r.error, 'error'); return; }
                        clearAlert(); loadBoard();
                    });
            });
        }
    }

    // Collect the still-issued gear across a unit's whole roster.
    function unitOutstandingGear(u) {
        var out = [], roster = u.roster || [], i, j;
        for (i = 0; i < roster.length; i++) {
            var eq = roster[i].equipment || [];
            for (j = 0; j < eq.length; j++) out.push(eq[j]);
        }
        return out;
    }

    function wireEquipmentButtons() {
        if (!els.body || !CFG.canIssueEquipment) return;
        wireGearReturns(els.body);
        var btns = els.body.querySelectorAll('.nc-issue-gear');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', function (e) {
                e.stopPropagation();
                openIssueModal(this.getAttribute('data-member'), this.getAttribute('data-member-name'));
            });
        }
    }

    function openIssueModal(memberId, memberName) {
        var idEl = byId('ncIssueMemberId'), nameEl = byId('ncIssueMemberName'), sel = byId('ncIssueItem');
        if (!idEl || !sel) return;
        idEl.value = memberId;
        if (nameEl) nameEl.textContent = memberName || ('Member #' + memberId);
        sel.innerHTML = '<option value="">Loading…</option>';
        fetch('api/equipment-assign.php?action=cache', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var list = (data && data.cache) || [];
                if (!list.length) { sel.innerHTML = '<option value="">No available cache items</option>'; return; }
                var html = '', k;
                for (k = 0; k < list.length; k++) {
                    html += '<option value="' + list[k].id + '">' + esc(list[k].label) +
                            (list[k].type_name ? ' — ' + esc(list[k].type_name) : '') + '</option>';
                }
                sel.innerHTML = html;
            })
            .catch(function () { sel.innerHTML = '<option value="">Failed to load cache</option>'; });
        var modalEl = byId('ncIssueModal');
        if (modalEl && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmIssue() {
        var memberId = parseInt(byId('ncIssueMemberId').value, 10);
        var equipmentId = parseInt(byId('ncIssueItem').value, 10);
        if (!memberId || !equipmentId) { showAlert('Pick a cache item.', 'error'); return; }
        postJson('api/equipment-assign.php', { action: 'issue', member_id: memberId, equipment_id: equipmentId })
            .then(function (r) {
                if (r && r.error) { showAlert(r.error, 'error'); return; }
                var modalEl = byId('ncIssueModal');
                if (modalEl && window.bootstrap) {
                    var inst = window.bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                clearAlert(); loadBoard();
            });
    }

    // ── Sign-out / sign-in (Phase 109 Slice B) ──────────────────────
    function signoutBtnHtml(u) {
        if (!CFG.canUpdateZone) return '';
        return ' <button type="button" class="btn btn-sm btn-outline-secondary nc-signout-btn ms-1" ' +
               'data-assign="' + u.assign_id + '" title="Sign out" style="padding:0 .35rem;">' +
               '<i class="bi bi-box-arrow-right"></i></button>';
    }

    function postSignout(assignId, action) {
        if (!CFG.canUpdateZone) {
            showAlert('You do not have permission to sign units in or out.', 'error');
            return;
        }
        postJson('api/net-signout.php', {
            ticket_id: state.ticketId,
            assign_id: assignId,
            action: action
        }).then(function (res) {
            if (res && res.error) { showAlert(res.error, 'error'); return; }
            clearAlert();
            loadBoard();
        }).catch(function (e) {
            showAlert('Sign-' + (action === 'signout' ? 'out' : 'in') + ' failed. ' +
                (e && e.message ? e.message : ''), 'error');
        });
    }

    function wireSignoutButtons() {
        var btns = els.body ? els.body.querySelectorAll('.nc-signout-btn') : [];
        var i;
        for (i = 0; i < btns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function (ev) {
                    ev.stopPropagation();   // don't select the row
                    postSignout(parseInt(btn.getAttribute('data-assign'), 10), 'signout');
                });
            })(btns[i]);
        }
    }

    function renderSignedOutTray() {
        if (!els.tray || !els.trayList) return;
        var list = state.signedOut || [];
        if (!list.length) {
            els.tray.style.display = 'none';
            els.trayList.innerHTML = '';
            if (els.trayCount) els.trayCount.textContent = '0';
            return;
        }
        els.tray.style.display = '';
        if (els.trayCount) els.trayCount.textContent = String(list.length);
        var html = '';
        var i;
        for (i = 0; i < list.length; i++) {
            var u = list[i];
            var team = u.callsign ? u.callsign : u.name;
            // Slice C — flag equipment still out on a signed-out unit so it gets
            // chased/returned at tear-down. Chips are click-to-return for issuers.
            var gear = unitOutstandingGear(u), gearHtmlStr = '', gk;
            for (gk = 0; gk < gear.length; gk++) {
                var g = gear[gk];
                gearHtmlStr += CFG.canIssueEquipment
                    ? ' <span class="badge bg-danger-subtle text-danger-emphasis nc-gear" data-return="' + g.equipment_id +
                      '" role="button" title="Return: ' + esc(g.label) + '" style="cursor:pointer;">' +
                      '<i class="bi bi-exclamation-triangle me-1"></i>' + esc(g.label) + '</span>'
                    : ' <span class="badge bg-danger-subtle text-danger-emphasis" title="Equipment still out">' +
                      '<i class="bi bi-exclamation-triangle me-1"></i>' + esc(g.label) + '</span>';
            }
            html += '<span class="nc-signedout-chip d-inline-flex align-items-center gap-1 border rounded px-2 py-1 small">' +
                    '<span class="fw-semibold">' + esc(team) + '</span>' + gearHtmlStr +
                    (CFG.canUpdateZone
                        ? '<button type="button" class="btn btn-sm btn-outline-primary nc-signin-btn" ' +
                          'data-assign="' + u.assign_id + '" title="Sign back in" style="padding:0 .35rem;">' +
                          '<i class="bi bi-box-arrow-in-left"></i> Sign in</button>'
                        : '') +
                    '</span>';
        }
        els.trayList.innerHTML = html;
        wireGearReturns(els.trayList);
        var btns = els.trayList.querySelectorAll('.nc-signin-btn');
        var j;
        for (j = 0; j < btns.length; j++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    postSignout(parseInt(btn.getAttribute('data-assign'), 10), 'signin');
                });
            })(btns[j]);
        }
    }

    // ── Row + zone-cell interaction ─────────────────────────────────
    function selectRow(assignId) {
        state.selectedAssignId = assignId;
        var rows = els.body.querySelectorAll('.nc-board-row');
        var i;
        for (i = 0; i < rows.length; i++) {
            var rid = parseInt(rows[i].getAttribute('data-assign'), 10);
            if (rid === assignId) rows[i].classList.add('nc-selected');
            else rows[i].classList.remove('nc-selected');
        }
    }

    function wireRowEvents() {
        var rows = els.body.querySelectorAll('.nc-board-row');
        var i;
        for (i = 0; i < rows.length; i++) {
            (function (row) {
                row.addEventListener('click', function (ev) {
                    // If the click landed on the zone badge, open the picker.
                    var badge = ev.target.closest ? ev.target.closest('.nc-zone-badge') : null;
                    var assignId = parseInt(row.getAttribute('data-assign'), 10);
                    selectRow(assignId);
                    if (badge) {
                        openZonePicker(badge, assignId);
                    }
                });
                row.addEventListener('focus', function () {
                    selectRow(parseInt(row.getAttribute('data-assign'), 10));
                });
            })(rows[i]);
        }
    }

    // ── Zone picker popover ─────────────────────────────────────────
    function openZonePicker(anchorEl, assignId) {
        if (!els.picker) return;
        if (!CFG.canUpdateZone) {
            showAlert('You do not have permission to move units.', 'error');
            return;
        }
        var btns = '';
        var i;
        for (i = 0; i < state.zones.length; i++) {
            var z = state.zones[i];
            btns += '<button type="button" class="btn btn-sm nc-zone-picker-btn" ' +
                    'style="' + zoneStyle(z.color) + '" data-zone="' + z.id + '">' +
                    '<span class="nc-idx-key">' + (i + 1) + '</span>' + esc(z.name) + '</button>';
        }
        // A "clear" option.
        btns += '<button type="button" class="btn btn-sm btn-outline-secondary" data-zone="0">' +
                '<i class="bi bi-x-lg"></i> Clear</button>';

        byId('ncZonePickerButtons').innerHTML = btns;

        var rect = anchorEl.getBoundingClientRect();
        els.picker.style.display = 'block';
        els.picker.style.left = (window.scrollX + rect.left) + 'px';
        els.picker.style.top = (window.scrollY + rect.bottom + 4) + 'px';

        var pickerBtns = byId('ncZonePickerButtons').querySelectorAll('button');
        var j;
        for (j = 0; j < pickerBtns.length; j++) {
            (function (b) {
                b.addEventListener('click', function () {
                    var zid = parseInt(b.getAttribute('data-zone'), 10);
                    closeZonePicker();
                    moveUnit(assignId, zid);
                });
            })(pickerBtns[j]);
        }
    }

    function closeZonePicker() {
        if (els.picker) els.picker.style.display = 'none';
    }

    // ── Keyboard: digit 1-9 sets the Nth zone on the selected row ────
    function onKeyDown(ev) {
        // Ignore when typing in a field.
        var tag = (ev.target && ev.target.tagName) ? ev.target.tagName.toUpperCase() : '';
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') return;
        if (ev.key === 'Escape') { closeZonePicker(); return; }
        if (!state.selectedAssignId) return;
        var n = parseInt(ev.key, 10);
        if (isNaN(n) || n < 1 || n > 9) return;
        if (n > state.zones.length) return;
        ev.preventDefault();
        var zone = state.zones[n - 1];
        if (zone) moveUnit(state.selectedAssignId, zone.id);
    }

    // ── Edit zones modal ────────────────────────────────────────────
    function renderZonesModalList() {
        var list = byId('ncZonesModalList');
        if (!list) return;
        if (!state.zones.length) {
            list.innerHTML = '<div class="text-body-secondary small fst-italic">No zones yet. Add one below.</div>';
            return;
        }
        var html = '<div class="list-group list-group-flush">';
        var i;
        for (i = 0; i < state.zones.length; i++) {
            var z = state.zones[i];
            html += '<div class="list-group-item d-flex align-items-center gap-2 px-0 py-1">' +
                    '<span class="nc-zone-chip" style="' + zoneStyle(z.color) + '">' + esc(z.name) + '</span>' +
                    '<span class="text-body-secondary small">code: ' + esc(z.code) + '</span>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary ms-auto" ' +
                    'data-map-zone="' + z.id + '" title="Draw this zone on the map (shows on the Situation screen)">' +
                    '<i class="bi bi-geo-alt"></i></button>' +
                    '<button type="button" class="btn btn-sm btn-outline-danger" ' +
                    'data-del-zone="' + z.id + '"><i class="bi bi-trash"></i></button>' +
                    '</div>';
        }
        html += '</div>';
        list.innerHTML = html;

        var delBtns = list.querySelectorAll('[data-del-zone]');
        var j;
        for (j = 0; j < delBtns.length; j++) {
            (function (b) {
                b.addEventListener('click', function () {
                    var zid = parseInt(b.getAttribute('data-del-zone'), 10);
                    deleteZone(zid);
                });
            })(delBtns[j]);
        }
        var mapBtns = list.querySelectorAll('[data-map-zone]');
        var k;
        for (k = 0; k < mapBtns.length; k++) {
            (function (b) {
                b.addEventListener('click', function () {
                    openZoneMapEditor(parseInt(b.getAttribute('data-map-zone'), 10));
                });
            })(mapBtns[k]);
        }
    }

    function addZone() {
        var name = (byId('ncNewZoneName').value || '').trim();
        var code = (byId('ncNewZoneCode').value || '').trim();
        var color = byId('ncNewZoneColor').value || '';
        if (!name || !code) {
            showAlert('Zone name and code are required.', 'error');
            return;
        }
        postJson('api/event-zones.php', {
            action: 'create',
            ticket_id: state.ticketId,
            name: name,
            code: code,
            color: color,
            sort_order: state.zones.length
        }).then(function (res) {
            if (res && res.error) { showAlert(res.error, 'error'); return; }
            byId('ncNewZoneName').value = '';
            byId('ncNewZoneCode').value = '';
            loadBoard();
            // Re-render the modal list after the reload settles.
            setTimeout(function () { renderZonesModalList(); }, 250);
        }).catch(function (e) {
            showAlert('Could not add zone. ' + (e && e.message ? e.message : ''), 'error');
        });
    }

    function deleteZone(zoneId) {
        postJson('api/event-zones.php', {
            action: 'delete',
            ticket_id: state.ticketId,
            id: zoneId
        }).then(function (res) {
            if (res && res.error) { showAlert(res.error, 'error'); return; }
            loadBoard();
            setTimeout(function () { renderZonesModalList(); }, 250);
        }).catch(function (e) {
            showAlert('Could not delete zone. ' + (e && e.message ? e.message : ''), 'error');
        });
    }

    // ── Slice D: zone templates (reuse a zone set year to year) ─────────
    function loadTemplateList() {
        var sel = byId('ncTemplateSelect');
        if (!sel) return;
        postJson('api/event-zones.php', { action: 'list_templates', ticket_id: state.ticketId || 0 })
            .then(function (res) {
                var list = (res && res.templates) || [], i;
                var html = '<option value="">— pick a template —</option>';
                for (i = 0; i < list.length; i++) {
                    html += '<option value="' + list[i].id + '">' + esc(list[i].name) +
                            ' (' + list[i].zone_count + ' zones)</option>';
                }
                sel.innerHTML = html;
            }).catch(function () {});
    }

    function applyTemplate() {
        var tid = parseInt(byId('ncTemplateSelect').value, 10);
        if (!tid) { showAlert('Pick a template to apply.', 'error'); return; }
        postJson('api/event-zones.php', { action: 'apply_template', ticket_id: state.ticketId, template_id: tid })
            .then(function (res) {
                if (res && res.error) { showAlert(res.error, 'error'); return; }
                showAlert((res.added || 0) + ' zone(s) added from template.', 'info');
                loadBoard();
                setTimeout(function () { renderZonesModalList(); }, 250);
            });
    }

    function saveTemplate() {
        var name = (byId('ncTemplateName').value || '').trim();
        if (!name) { showAlert('Enter a template name.', 'error'); return; }
        postJson('api/event-zones.php', { action: 'save_template', ticket_id: state.ticketId, name: name })
            .then(function (res) {
                if (res && res.error) { showAlert(res.error, 'error'); return; }
                byId('ncTemplateName').value = '';
                showAlert('Template saved (' + (res.zone_count || 0) + ' zones).', 'info');
                loadTemplateList();
            });
    }

    // ── Slice D: zone map editor — draw a zone's point/polygon ──────────
    // Click adds vertices: 1 click saves a Point, 3+ a Polygon. The saved
    // geo_json renders on the Situation big screen (Event Zones overlay).
    var zoneMap = null;          // Leaflet map, created once
    var zoneMapDraw = null;      // working layer (marker/polygon preview)
    var zoneMapVerts = [];       // [ [lat,lng], ... ]
    var zoneMapZone = null;      // the full zone row being edited

    function _zoneMapRedraw() {
        if (zoneMapDraw) { zoneMap.removeLayer(zoneMapDraw); zoneMapDraw = null; }
        var color = (zoneMapZone && zoneMapZone.color) || '#6f42c1';
        if (zoneMapVerts.length === 1) {
            zoneMapDraw = L.circleMarker(zoneMapVerts[0], {
                radius: 9, color: color, weight: 2, fillColor: color, fillOpacity: 0.5
            }).addTo(zoneMap);
        } else if (zoneMapVerts.length >= 2) {
            zoneMapDraw = L.polygon(zoneMapVerts, {
                color: color, weight: 2, fillColor: color, fillOpacity: 0.2
            }).addTo(zoneMap);
        }
    }

    function _zoneMapLoadExisting(geoJsonStr) {
        zoneMapVerts = [];
        if (!geoJsonStr) { _zoneMapRedraw(); return; }
        var g;
        try { g = JSON.parse(geoJsonStr); } catch (e) { _zoneMapRedraw(); return; }
        if (!g) { _zoneMapRedraw(); return; }
        if (g.type === 'Point' && g.coordinates) {
            zoneMapVerts = [[g.coordinates[1], g.coordinates[0]]];
        } else if (g.type === 'Polygon' && g.coordinates && g.coordinates[0]) {
            var ring = g.coordinates[0], i;
            // Drop the closing vertex (GeoJSON rings repeat the first point).
            for (i = 0; i < ring.length - 1; i++) zoneMapVerts.push([ring[i][1], ring[i][0]]);
        }
        _zoneMapRedraw();
        if (zoneMapVerts.length) {
            var b = L.latLngBounds(zoneMapVerts);
            zoneMap.fitBounds(b.pad(0.5), { maxZoom: 16 });
        }
    }

    function openZoneMapEditor(zoneId) {
        // Fetch the zone fresh (the board payload omits geo_json).
        fetch('api/event-zones.php?ticket_id=' + state.ticketId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var zones = (data && data.zones) || [], i, z = null;
                for (i = 0; i < zones.length; i++) { if (zones[i].id === zoneId) { z = zones[i]; break; } }
                if (!z) { showAlert('Zone not found.', 'error'); return; }
                zoneMapZone = z;
                byId('ncZoneMapZoneId').value = z.id;
                var nameEl = byId('ncZoneMapName');
                if (nameEl) nameEl.textContent = z.name;

                var modalEl = byId('ncZoneMapModal');
                if (!modalEl || !window.bootstrap || typeof L === 'undefined') return;
                var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                m.show();

                // Create the map ONCE the modal is visible (Leaflet needs real
                // dimensions); reuse it on later opens.
                setTimeout(function () {
                    if (!zoneMap) {
                        zoneMap = L.map('ncZoneMap').setView([39.8283, -98.5795], 4);
                        var base = (window.MapPrefs && window.MapPrefs.makeLayer)
                            ? window.MapPrefs.makeLayer('street')
                            : L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',
                                { attribution: '&copy; OpenStreetMap' });
                        base.addTo(zoneMap);
                        zoneMap.on('click', function (ev) {
                            zoneMapVerts.push([ev.latlng.lat, ev.latlng.lng]);
                            _zoneMapRedraw();
                        });
                    }
                    zoneMap.invalidateSize();
                    _zoneMapLoadExisting(z.geo_json);
                }, 300);
            });
    }

    function zoneMapClear() {
        zoneMapVerts = [];
        _zoneMapRedraw();
    }

    function zoneMapSave() {
        if (!zoneMapZone) return;
        var geo = '';
        if (zoneMapVerts.length === 1) {
            geo = JSON.stringify({ type: 'Point',
                coordinates: [zoneMapVerts[0][1], zoneMapVerts[0][0]] });
        } else if (zoneMapVerts.length === 2) {
            showAlert('A polygon needs at least 3 corners (or use a single click for a point).', 'error');
            return;
        } else if (zoneMapVerts.length >= 3) {
            var ring = [], i;
            for (i = 0; i < zoneMapVerts.length; i++) ring.push([zoneMapVerts[i][1], zoneMapVerts[i][0]]);
            ring.push([zoneMapVerts[0][1], zoneMapVerts[0][0]]); // close the ring
            geo = JSON.stringify({ type: 'Polygon', coordinates: [ring] });
        }
        // Empty verts + Save = clear the stored geometry.
        postJson('api/event-zones.php', {
            action: 'update',
            ticket_id: state.ticketId,
            id: zoneMapZone.id,
            name: zoneMapZone.name,
            code: zoneMapZone.code,
            color: zoneMapZone.color || '',
            sort_order: zoneMapZone.sort_order,
            hide: zoneMapZone.hide,
            geo_json: geo
        }).then(function (res) {
            if (res && res.error) { showAlert(res.error, 'error'); return; }
            var modalEl = byId('ncZoneMapModal');
            if (modalEl && window.bootstrap) {
                var inst = window.bootstrap.Modal.getInstance(modalEl);
                if (inst) inst.hide();
            }
            showAlert(geo ? 'Zone shape saved — it now renders on the Situation screen.'
                          : 'Zone shape cleared.', 'info');
        });
    }

    // ── Event selection + persistence ───────────────────────────────
    function setEvent(ticketId) {
        state.ticketId = ticketId || 0;
        state.selectedAssignId = null;
        if (state.ticketId) {
            try { window.localStorage.setItem(STORAGE_KEY, String(state.ticketId)); } catch (e) {}
        }
        loadBoard();
    }

    function initEventSelector() {
        if (!els.eventSelect) return;
        var stored = null;
        try { stored = window.localStorage.getItem(STORAGE_KEY); } catch (e) {}
        // If the stored event is still in the list, honor it; else default
        // to the first (most recent open) option.
        var options = els.eventSelect.options;
        var chosen = 0;
        var i;
        if (stored) {
            for (i = 0; i < options.length; i++) {
                if (options[i].value === stored) { chosen = parseInt(stored, 10); break; }
            }
        }
        if (!chosen && options.length) {
            chosen = parseInt(options[0].value, 10) || 0;
        }
        if (chosen) els.eventSelect.value = String(chosen);
        setEvent(chosen);

        els.eventSelect.addEventListener('change', function () {
            setEvent(parseInt(els.eventSelect.value, 10) || 0);
        });
    }

    // ── SSE wiring (EventBus loaded globally via navbar) ────────────
    function initSSE() {
        if (typeof EventBus === 'undefined') return;
        EventBus.on('responder:status', function () { loadBoard(); });
        EventBus.on('responder:assign', function () { loadBoard(); });
        EventBus.on('system:refresh', function () { loadBoard(); });
    }

    // ── Boot ────────────────────────────────────────────────────────
    function init() {
        els.body = byId('ncBoardBody');
        els.count = byId('ncUnitCount');
        els.zoneChips = byId('ncZoneChips');
        els.alert = byId('ncAlert');
        els.tray = byId('ncSignedOutTray');
        els.trayList = byId('ncSignedOutList');
        els.trayCount = byId('ncSignedOutCount');
        els.eventSelect = byId('ncEventSelect');
        els.picker = byId('ncZonePicker');

        if (els.eventSelect && els.eventSelect.value === '0') {
            // No open events at all.
            renderEmpty('No open events. Open an incident to run net control.');
        }

        initEventSelector();
        initSSE();

        var refreshBtn = byId('ncRefreshBtn');
        if (refreshBtn) refreshBtn.addEventListener('click', function () { loadBoard(); });

        var editBtn = byId('ncEditZonesBtn');
        if (editBtn) {
            editBtn.addEventListener('click', function () {
                renderZonesModalList();
                loadTemplateList();
                var applyTpl = byId('ncApplyTemplateBtn');
                if (applyTpl && !applyTpl._wired) { applyTpl._wired = 1; applyTpl.addEventListener('click', applyTemplate); }
                var saveTpl = byId('ncSaveTemplateBtn');
                if (saveTpl && !saveTpl._wired) { saveTpl._wired = 1; saveTpl.addEventListener('click', saveTemplate); }
                var modalEl = byId('ncZonesModal');
                if (modalEl && window.bootstrap) {
                    var m = window.bootstrap.Modal.getOrCreateInstance(modalEl);
                    m.show();
                }
            });
        }
        var addBtn = byId('ncAddZoneBtn');
        if (addBtn) addBtn.addEventListener('click', addZone);

        // Slice C — the issue-equipment modal's Confirm button (static; wire once).
        var issueBtn = byId('ncIssueConfirm');
        if (issueBtn) issueBtn.addEventListener('click', confirmIssue);

        // Slice D — the zone map editor's Clear/Save buttons (static; wire once).
        var zmClear = byId('ncZoneMapClear');
        if (zmClear) zmClear.addEventListener('click', zoneMapClear);
        var zmSave = byId('ncZoneMapSave');
        if (zmSave) zmSave.addEventListener('click', zoneMapSave);

        document.addEventListener('keydown', onKeyDown);
        document.addEventListener('click', function (ev) {
            // Close the picker when clicking outside it.
            if (els.picker && els.picker.style.display === 'block') {
                if (!els.picker.contains(ev.target) &&
                    !(ev.target.closest && ev.target.closest('.nc-zone-badge'))) {
                    closeZonePicker();
                }
            }
        });

        // Auto-refresh every 15s.
        refreshTimer = window.setInterval(function () { loadBoard(); }, REFRESH_MS);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
