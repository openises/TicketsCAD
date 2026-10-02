/**
 * NewUI v4.0 — Console Positions Admin (Phase 152, thin position layer)
 *
 * List + create/edit/delete for console_positions. Backend: api/console-
 * positions.php. Channel choices come from api/channels.php?probe=1, the
 * same channel-listing endpoint console-recall.js already reuses, so this
 * page always reflects the live registry rather than a second copy of it.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals
 * (project convention, CLAUDE.md).
 */
(function () {
    'use strict';

    var API = 'api/console-positions.php';
    var CHANNELS_API = 'api/channels.php?probe=1';
    var SEARCH_API = 'api/console-workstation-search.php';

    var csrfToken = '';
    var channels = [];
    var positions = [];

    var cpaModalEl = null;
    var cpaModal = null;

    document.addEventListener('DOMContentLoaded', function () {
        var tokenEl = document.getElementById('csrfToken');
        csrfToken = tokenEl ? tokenEl.value : '';
        cpaModalEl = document.getElementById('cpaModal');
        cpaModal = (cpaModalEl && window.bootstrap) ? new window.bootstrap.Modal(cpaModalEl) : null;

        bindEvents();
        loadAll();
    });

    function loadAll() {
        Promise.all([
            fetch(CHANNELS_API, { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
            fetch(API, { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
        ]).then(function (results) {
            var chResp = results[0], posResp = results[1];
            channels = (chResp && chResp.channels) || [];
            if (posResp && posResp.error) { showToast('danger', posResp.error); return; }
            positions = (posResp && posResp.positions) || [];
            populateChannelSelect();
            renderList();
        }).catch(function (err) { showToast('danger', 'Failed to load: ' + err.message); });
    }

    function channelLabel(id) {
        for (var i = 0; i < channels.length; i++) {
            if (parseInt(channels[i].id, 10) === parseInt(id, 10)) {
                return channels[i].short_label || channels[i].label || ('#' + id);
            }
        }
        return '#' + id;
    }

    function populateChannelSelect() {
        var sel = document.getElementById('cpaChannels');
        if (!sel) { return; }
        sel.innerHTML = '';
        channels.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = String(c.id);
            opt.textContent = (c.short_label || c.label || ('#' + c.id)) + ' (' + c.adapter + ')';
            sel.appendChild(opt);
        });
    }

    function renderList() {
        var tbody = document.getElementById('cpaRows');
        if (!tbody) { return; }
        tbody.innerHTML = '';
        if (!positions.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="text-body-secondary">No positions configured yet — the console works fine with none.</td></tr>';
            return;
        }
        positions.forEach(function (p) {
            var tr = document.createElement('tr');

            var tdLabel = document.createElement('td');
            tdLabel.textContent = p.label;
            tr.appendChild(tdLabel);

            var tdChans = document.createElement('td');
            var ids = p.default_channel_ids || [];
            if (!ids.length) {
                tdChans.appendChild(makeSpan('text-body-secondary small', 'none'));
            } else {
                ids.forEach(function (id) {
                    var badge = document.createElement('span');
                    badge.className = 'badge text-bg-secondary me-1';
                    badge.textContent = channelLabel(id);
                    tdChans.appendChild(badge);
                });
            }
            tr.appendChild(tdChans);

            var tdSort = document.createElement('td');
            tdSort.className = 'text-end';
            tdSort.textContent = String(p.sort_order || 0);
            tr.appendChild(tdSort);

            var tdActions = document.createElement('td');
            tdActions.className = 'text-end';
            var editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'btn btn-sm btn-outline-secondary';
            editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
            editBtn.addEventListener('click', function () { openEdit(p); });
            tdActions.appendChild(editBtn);
            tr.appendChild(tdActions);

            tbody.appendChild(tr);
        });
    }

    function makeSpan(cls, text) {
        var s = document.createElement('span');
        s.className = cls;
        s.textContent = text;
        return s;
    }

    function openNew() {
        document.getElementById('cpaModalTitle').innerHTML = '<i class="bi bi-plus-lg me-2"></i>New Position';
        document.getElementById('cpaId').value = '0';
        document.getElementById('cpaLabel').value = '';
        document.getElementById('cpaSort').value = '0';
        selectChannels([]);
        document.getElementById('cpaBtnDelete').classList.add('d-none');
        hideModalError();
        if (cpaModal) { cpaModal.show(); }
    }

    function openEdit(p) {
        document.getElementById('cpaModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Position';
        document.getElementById('cpaId').value = String(p.id);
        document.getElementById('cpaLabel').value = p.label;
        document.getElementById('cpaSort').value = String(p.sort_order || 0);
        selectChannels(p.default_channel_ids || []);
        document.getElementById('cpaBtnDelete').classList.remove('d-none');
        hideModalError();
        if (cpaModal) { cpaModal.show(); }
    }

    function selectChannels(ids) {
        var sel = document.getElementById('cpaChannels');
        if (!sel) { return; }
        var idSet = {};
        ids.forEach(function (id) { idSet[String(id)] = true; });
        for (var i = 0; i < sel.options.length; i++) {
            sel.options[i].selected = !!idSet[sel.options[i].value];
        }
    }

    function selectedChannelIds() {
        var sel = document.getElementById('cpaChannels');
        var out = [];
        if (!sel) { return out; }
        for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].selected) { out.push(parseInt(sel.options[i].value, 10)); }
        }
        return out;
    }

    function showModalError(msg) {
        var el = document.getElementById('cpaModalError');
        el.textContent = msg;
        el.classList.remove('d-none');
    }
    function hideModalError() {
        document.getElementById('cpaModalError').classList.add('d-none');
    }

    function showToast(level, msg) {
        var el = document.getElementById('cpaToast');
        if (!el) { return; }
        el.className = 'alert alert-' + level;
        el.textContent = msg;
        el.classList.remove('d-none');
        window.setTimeout(function () { el.classList.add('d-none'); }, 5000);
    }

    function postAction(body) {
        body.csrf_token = csrfToken;
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }

    function bindEvents() {
        // Acoustic proximity auto-discovery's own install-wide toggle --
        // a DIFFERENT feature living on this page for lack of a better
        // home; note it goes to api/console-workstation-search.php, not
        // api/console-positions.php, and needs no workstation_token (the
        // server resolves nothing workstation-specific for this action).
        var discoveryToggle = document.getElementById('cpaDiscoveryToggle');
        if (discoveryToggle) {
            discoveryToggle.addEventListener('change', function () {
                var want = discoveryToggle.checked;
                discoveryToggle.disabled = true;
                fetch(SEARCH_API, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'set_discovery_enabled', enabled: want, csrf_token: csrfToken }),
                }).then(function (r) { return r.json(); }).then(function (resp) {
                    discoveryToggle.disabled = false;
                    if (resp && resp.error) { showToast('danger', resp.error); discoveryToggle.checked = !want; return; }
                    showToast('success', want ? 'Acoustic discovery enabled.' : 'Acoustic discovery disabled.');
                }).catch(function (err) {
                    discoveryToggle.disabled = false;
                    discoveryToggle.checked = !want;
                    showToast('danger', 'Save failed: ' + err.message);
                });
            });
        }

        var btnNew = document.getElementById('cpaBtnNew');
        if (btnNew) { btnNew.addEventListener('click', openNew); }

        var btnSave = document.getElementById('cpaBtnSave');
        if (btnSave) {
            btnSave.addEventListener('click', function () {
                var id = parseInt(document.getElementById('cpaId').value, 10) || 0;
                var label = document.getElementById('cpaLabel').value.trim();
                if (!label) { showModalError('Label is required.'); return; }
                var payload = {
                    action: id > 0 ? 'update' : 'create',
                    label: label,
                    default_channel_ids: selectedChannelIds(),
                    sort_order: parseInt(document.getElementById('cpaSort').value, 10) || 0,
                };
                if (id > 0) { payload.id = id; }
                postAction(payload).then(function (resp) {
                    if (resp.error) { showModalError(resp.error); return; }
                    if (cpaModal) { cpaModal.hide(); }
                    showToast('success', id > 0 ? 'Position updated.' : 'Position created.');
                    loadAll();
                }).catch(function (err) { showModalError('Save failed: ' + err.message); });
            });
        }

        var btnDelete = document.getElementById('cpaBtnDelete');
        if (btnDelete) {
            btnDelete.addEventListener('click', function () {
                var id = parseInt(document.getElementById('cpaId').value, 10) || 0;
                if (id <= 0) { return; }
                if (!window.confirm('Delete this position? Anyone currently logged into it will be logged out.')) { return; }
                postAction({ action: 'delete', id: id }).then(function (resp) {
                    if (resp.error) { showModalError(resp.error); return; }
                    if (cpaModal) { cpaModal.hide(); }
                    showToast('success', 'Position deleted.');
                    loadAll();
                }).catch(function (err) { showModalError('Delete failed: ' + err.message); });
            });
        }
    }
})();
