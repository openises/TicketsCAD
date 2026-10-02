/**
 * Phase 153 (2026-09-08) — SIP extension admin page.
 * Modeled on assets/js/sip-trunks-admin.js's own list+modal shape.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var csrfToken = document.getElementById('csrfToken').value;
    var extensions = [];
    var modalEl = document.getElementById('peModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;

    function apiGet(action, params) {
        var qs = 'action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }
        }
        return fetch('api/phone-extensions.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function apiPost(action, body) {
        body = body || {};
        body.csrf_token = csrfToken;
        return fetch('api/phone-extensions.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function showToast(msg, isError) {
        var el = document.getElementById('peToast');
        el.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        el.textContent = msg;
        setTimeout(function () { el.className = 'alert d-none'; }, 6000);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function renderList() {
        var tbody = document.getElementById('peListRows');
        if (!extensions.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-body-secondary">No extensions configured yet. Click "New Extension" to add one.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < extensions.length; i++) {
            var x = extensions[i];
            html += '<tr>'
                + '<td class="font-monospace">' + escapeHtml(x.extension) + '</td>'
                + '<td>' + escapeHtml(x.label) + '</td>'
                + '<td>' + (Number(x.is_general) === 1
                    ? '<span class="badge text-bg-info">General</span>'
                    : '<span class="badge text-bg-secondary">Direct</span>') + '</td>'
                + '<td class="small font-monospace text-truncate" style="max-width:220px">' + (x.workstation_token ? escapeHtml(x.workstation_token) : '<span class="text-body-secondary">(unbound)</span>') + '</td>'
                + '<td>' + (x.has_password ? '<span class="badge text-bg-success">Set</span>' : '<span class="badge text-bg-warning">None</span>') + '</td>'
                + '<td>' + (Number(x.enabled) === 1 ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>') + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary pe-edit" data-id="' + x.id + '"><i class="bi bi-pencil"></i></button>'
                + '</td></tr>';
        }
        tbody.innerHTML = html;

        var editBtns = tbody.querySelectorAll('.pe-edit');
        for (var e = 0; e < editBtns.length; e++) {
            editBtns[e].addEventListener('click', function (ev) { openEdit(ev.currentTarget.getAttribute('data-id')); });
        }
    }

    function loadExtensions() {
        apiGet('extensions').then(function (data) {
            if (data && data.extensions) {
                extensions = data.extensions;
                renderList();
            } else if (data && data.error) {
                showToast(data.error, true);
            }
        }).catch(function () { showToast('Failed to load extensions', true); });
    }

    function resetModal() {
        document.getElementById('peId').value = '0';
        document.getElementById('peExtension').value = '';
        document.getElementById('peExtension').disabled = false;
        document.getElementById('peLabel').value = '';
        document.getElementById('peIsGeneral').checked = false;
        document.getElementById('peWorkstationToken').value = '';
        document.getElementById('peEnabled').checked = true;
        document.getElementById('peModalError').className = 'alert alert-danger small mt-2 d-none';
        document.getElementById('peCredentialWrap').className = 'd-none';
        document.getElementById('peBtnDelete').className = 'btn btn-outline-danger btn-sm d-none';
        document.getElementById('peBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto d-none';
    }

    function openNew() {
        resetModal();
        document.getElementById('peModalTitle').innerHTML = '<i class="bi bi-plus-lg me-2"></i>New Extension';
        if (modal) modal.show();
    }

    function openEdit(id) {
        var x = null;
        for (var i = 0; i < extensions.length; i++) { if (String(extensions[i].id) === String(id)) { x = extensions[i]; break; } }
        if (!x) return;
        resetModal();
        document.getElementById('peModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Extension';
        document.getElementById('peId').value = x.id;
        document.getElementById('peExtension').value = x.extension;
        document.getElementById('peExtension').disabled = true; // immutable after creation
        document.getElementById('peLabel').value = x.label;
        document.getElementById('peIsGeneral').checked = Number(x.is_general) === 1;
        document.getElementById('peWorkstationToken').value = x.workstation_token || '';
        document.getElementById('peEnabled').checked = Number(x.enabled) === 1;
        document.getElementById('peBtnDelete').className = 'btn btn-outline-danger btn-sm';
        document.getElementById('peBtnRotate').className = 'btn btn-outline-warning btn-sm me-auto';
        if (modal) modal.show();
    }

    function showModalError(msg) {
        var el = document.getElementById('peModalError');
        el.textContent = msg;
        el.className = 'alert alert-danger small mt-2';
    }

    function showCredentials(username, password, note) {
        document.getElementById('peCredUsername').value = username;
        document.getElementById('peCredPassword').value = password;
        document.getElementById('peCredentialWrap').className = '';
        if (note) showToast(note, false);
    }

    function saveExtension() {
        var id = parseInt(document.getElementById('peId').value, 10) || 0;
        var body = {
            label: document.getElementById('peLabel').value,
            is_general: document.getElementById('peIsGeneral').checked ? 1 : 0,
            workstation_token: document.getElementById('peWorkstationToken').value,
            enabled: document.getElementById('peEnabled').checked ? 1 : 0
        };
        if (id > 0) {
            body.id = id;
            apiPost('extension_update', body).then(function (res) {
                if (res && res.ok) {
                    showToast('Extension saved.', false);
                    loadExtensions();
                    if (modal) modal.hide();
                } else {
                    showModalError((res && res.error) || 'Save failed');
                }
            });
        } else {
            body.extension = document.getElementById('peExtension').value;
            apiPost('extension_create', body).then(function (res) {
                if (res && res.extension_id) {
                    loadExtensions();
                    showCredentials(res.sip_username, res.sip_password, res.note);
                } else {
                    showModalError((res && res.error) || 'Create failed');
                }
            });
        }
    }

    function deleteExtension() {
        var id = parseInt(document.getElementById('peId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Delete this extension? Any browser session using it will stop being able to register.')) return;
        apiPost('extension_delete', { id: id }).then(function (res) {
            if (res && res.ok) {
                showToast('Extension deleted.', false);
                loadExtensions();
                if (modal) modal.hide();
            } else {
                showModalError((res && res.error) || 'Delete failed');
            }
        });
    }

    function rotatePassword() {
        var id = parseInt(document.getElementById('peId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Rotate this extension\'s password? The old one stops working immediately.')) return;
        apiPost('extension_rotate_password', { id: id }).then(function (res) {
            if (res && res.sip_password) {
                var ext = null;
                for (var i = 0; i < extensions.length; i++) { if (String(extensions[i].id) === String(id)) { ext = extensions[i]; break; } }
                loadExtensions();
                showCredentials(ext ? ext.sip_username : '(username unchanged)', res.sip_password, res.note);
            } else {
                showModalError((res && res.error) || 'Rotate failed');
            }
        });
    }

    function loadPbxSettings() {
        apiGet('pbx_settings').then(function (data) {
            if (data && data.settings) {
                document.getElementById('pePbxWssUrl').value = data.settings.wss_url || '';
                document.getElementById('pePbxGeneral').value = data.settings.general_number || '';
            }
        }).catch(function () {});
    }

    function savePbxSettings() {
        apiPost('pbx_settings_save', {
            wss_url: document.getElementById('pePbxWssUrl').value,
            general_number: document.getElementById('pePbxGeneral').value
        }).then(function (res) {
            if (res && res.ok) {
                showToast('PBX connection settings saved.', false);
            } else {
                showToast((res && res.error) || 'Save failed', true);
            }
        }).catch(function () { showToast('Save failed', true); });
    }

    function init() {
        loadExtensions();
        loadPbxSettings();
        document.getElementById('peBtnSavePbx').addEventListener('click', savePbxSettings);
        document.getElementById('peBtnNew').addEventListener('click', openNew);
        document.getElementById('peBtnSave').addEventListener('click', saveExtension);
        document.getElementById('peBtnDelete').addEventListener('click', deleteExtension);
        document.getElementById('peBtnRotate').addEventListener('click', rotatePassword);
        var copyBtn = document.getElementById('peBtnCopyPassword');
        copyBtn.addEventListener('click', function () {
            var input = document.getElementById('peCredPassword');
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
