/**
 * Phase 149 (2026-08-22) — Inbound SIP/PBX trunk admin page.
 * Modeled on assets/js/matrix-admin.js's own list+modal shape.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var csrfToken = document.getElementById('csrfToken').value;
    var trunks = [];
    var orgs = [];
    var modalEl = document.getElementById('stModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;

    function csrfHeaders() { return { 'Content-Type': 'application/json' }; }

    function apiGet(action, params) {
        var qs = 'action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }
        }
        return fetch('api/sip-trunks.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function apiPost(action, body) {
        body = body || {};
        body.csrf_token = csrfToken;
        return fetch('api/sip-trunks.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders(),
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function showToast(msg, isError) {
        var el = document.getElementById('stToast');
        el.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        el.textContent = msg;
        setTimeout(function () { el.className = 'alert d-none'; }, 6000);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function orgName(id) {
        if (id === null || id === undefined) return 'Install-wide';
        for (var i = 0; i < orgs.length; i++) {
            if (String(orgs[i].id) === String(id)) return orgs[i].name || ('Org #' + id);
        }
        return 'Org #' + id;
    }

    function renderList() {
        var tbody = document.getElementById('stListRows');
        if (!trunks.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-body-secondary">No trunks configured yet. Click "New Trunk" to add one.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < trunks.length; i++) {
            var t = trunks[i];
            html += '<tr>'
                + '<td>' + escapeHtml(t.label) + '</td>'
                + '<td>' + escapeHtml(orgName(t.org_id)) + '</td>'
                + '<td>' + (t.mute_bypass_enabled ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>') + '</td>'
                + '<td class="text-end">' + escapeHtml(t.wrapup_seconds) + '</td>'
                + '<td class="text-end">' + escapeHtml(t.reassign_grace_seconds) + '</td>'
                + '<td>' + (t.has_token ? '<span class="badge text-bg-success">Set</span>' : '<span class="badge text-bg-warning">None</span>') + '</td>'
                + '<td>' + (Number(t.enabled) === 1 ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>') + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary st-edit" data-id="' + t.id + '"><i class="bi bi-pencil"></i></button> '
                + '<button type="button" class="btn btn-sm btn-outline-' + (Number(t.enabled) === 1 ? 'warning' : 'success') + ' st-toggle" data-id="' + t.id + '">'
                + (Number(t.enabled) === 1 ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>') + '</button>'
                + '</td></tr>';
        }
        tbody.innerHTML = html;

        var editBtns = tbody.querySelectorAll('.st-edit');
        for (var e = 0; e < editBtns.length; e++) {
            editBtns[e].addEventListener('click', function (ev) { openEdit(ev.currentTarget.getAttribute('data-id')); });
        }
        var toggleBtns = tbody.querySelectorAll('.st-toggle');
        for (var g = 0; g < toggleBtns.length; g++) {
            toggleBtns[g].addEventListener('click', function (ev) { toggleTrunk(ev.currentTarget.getAttribute('data-id')); });
        }
    }

    function loadTrunks() {
        apiGet('trunks').then(function (data) {
            if (data && data.trunks) {
                trunks = data.trunks;
                renderList();
            } else if (data && data.error) {
                showToast(data.error, true);
            }
        }).catch(function () { showToast('Failed to load trunks', true); });
    }

    function loadOrgs() {
        // Best-effort — the org dropdown is a convenience; a failure here
        // just leaves it with only the "install-wide" option.
        fetch('api/organizations.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                orgs = data.organizations || data.orgs || [];
                var sel = document.getElementById('stOrgId');
                for (var i = 0; i < orgs.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = orgs[i].id;
                    opt.textContent = orgs[i].name || ('Org #' + orgs[i].id);
                    sel.appendChild(opt);
                }
                renderList(); // re-render now that org names are resolvable
            })
            .catch(function () { /* best effort */ });
    }

    function resetModal() {
        document.getElementById('stId').value = '0';
        document.getElementById('stLabel').value = '';
        document.getElementById('stOrgId').value = '';
        document.getElementById('stWrapup').value = '90';
        document.getElementById('stGrace').value = '20';
        document.getElementById('stMuteBypass').checked = true;
        document.getElementById('stEnabled').checked = true;
        document.getElementById('stModalError').className = 'alert alert-danger small mt-2 d-none';
        document.getElementById('stTokenWrap').className = 'd-none';
        document.getElementById('stBtnDelete').className = 'btn btn-outline-danger btn-sm d-none';
        document.getElementById('stBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto d-none';
    }

    function openNew() {
        resetModal();
        document.getElementById('stModalTitle').innerHTML = '<i class="bi bi-plus-lg me-2"></i>New Trunk';
        if (modal) modal.show();
    }

    function openEdit(id) {
        var t = null;
        for (var i = 0; i < trunks.length; i++) { if (String(trunks[i].id) === String(id)) { t = trunks[i]; break; } }
        if (!t) return;
        resetModal();
        document.getElementById('stModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Trunk';
        document.getElementById('stId').value = t.id;
        document.getElementById('stLabel').value = t.label;
        document.getElementById('stOrgId').value = t.org_id === null ? '' : t.org_id;
        document.getElementById('stWrapup').value = t.wrapup_seconds;
        document.getElementById('stGrace').value = t.reassign_grace_seconds;
        document.getElementById('stMuteBypass').checked = !!Number(t.mute_bypass_enabled);
        document.getElementById('stEnabled').checked = Number(t.enabled) === 1;
        document.getElementById('stBtnDelete').className = 'btn btn-outline-danger btn-sm';
        document.getElementById('stBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto';
        if (modal) modal.show();
    }

    function showModalError(msg) {
        var el = document.getElementById('stModalError');
        el.textContent = msg;
        el.className = 'alert alert-danger small mt-2';
    }

    function showToken(token, note) {
        document.getElementById('stTokenValue').value = token;
        document.getElementById('stTokenWrap').className = '';
        if (note) showToast(note, false);
    }

    function saveTrunk() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        var body = {
            label: document.getElementById('stLabel').value,
            org_id: document.getElementById('stOrgId').value,
            wrapup_seconds: document.getElementById('stWrapup').value,
            reassign_grace_seconds: document.getElementById('stGrace').value,
            mute_bypass_enabled: document.getElementById('stMuteBypass').checked ? 1 : 0
        };
        if (id > 0) {
            body.id = id;
            apiPost('trunk_update', body).then(function (res) {
                if (res && res.success) {
                    showToast('Trunk saved.', false);
                    loadTrunks();
                    if (modal) modal.hide();
                } else {
                    showModalError((res && res.error) || 'Save failed');
                }
            });
        } else {
            apiPost('trunk_create', body).then(function (res) {
                if (res && res.trunk_id) {
                    loadTrunks();
                    showToken(res.bearer_token, res.note);
                } else {
                    showModalError((res && res.error) || 'Create failed');
                }
            });
        }
    }

    function toggleTrunk(id) {
        apiPost('trunk_toggle', { id: id }).then(function (res) {
            if (res && res.success) { loadTrunks(); } else { showToast((res && res.error) || 'Toggle failed', true); }
        });
    }

    function deleteTrunk() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Delete this trunk? Historical call records are kept; the trunk config itself cannot be undone.')) return;
        apiPost('trunk_delete', { id: id }).then(function (res) {
            if (res && res.success) {
                showToast('Trunk deleted.', false);
                loadTrunks();
                if (modal) modal.hide();
            } else {
                showModalError((res && res.error) || 'Delete failed');
            }
        });
    }

    function rotateToken() {
        var id = parseInt(document.getElementById('stId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Rotate this trunk\'s bearer token? The old token stops working immediately.')) return;
        apiPost('trunk_rotate_token', { id: id }).then(function (res) {
            if (res && res.bearer_token) {
                loadTrunks();
                showToken(res.bearer_token, res.note);
            } else {
                showModalError((res && res.error) || 'Rotate failed');
            }
        });
    }

    function init() {
        loadTrunks();
        loadOrgs();
        document.getElementById('stBtnNew').addEventListener('click', openNew);
        document.getElementById('stBtnSave').addEventListener('click', saveTrunk);
        document.getElementById('stBtnDelete').addEventListener('click', deleteTrunk);
        document.getElementById('stBtnRotate').addEventListener('click', rotateToken);
        var copyBtn = document.getElementById('stBtnCopyToken');
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('stTokenValue');
            input.select();
            try { document.execCommand('copy'); } catch (e) {}
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
