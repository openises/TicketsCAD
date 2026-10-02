/**
 * 2026-09-08 — Public audio stream channel admin page.
 * Modeled on assets/js/sip-trunks-admin.js's own list+modal shape.
 *
 * ES5 only (project rule).
 */
(function () {
    'use strict';

    var csrfToken = document.getElementById('csrfToken').value;
    var channels = [];
    var modalEl = document.getElementById('hscModal');
    var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;

    function csrfHeaders() { return { 'Content-Type': 'application/json' }; }

    function apiGet(action, params) {
        var qs = 'action=' + encodeURIComponent(action);
        if (params) {
            for (var k in params) {
                if (params.hasOwnProperty(k)) qs += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
            }
        }
        return fetch('api/http-stream-channels.php?' + qs, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function apiPost(action, body) {
        body = body || {};
        body.csrf_token = csrfToken;
        return fetch('api/http-stream-channels.php?action=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: csrfHeaders(),
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function showToast(msg, isError) {
        var el = document.getElementById('hscToast');
        el.className = 'alert ' + (isError ? 'alert-danger' : 'alert-success');
        el.textContent = msg;
        setTimeout(function () { el.className = 'alert d-none'; }, 6000);
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var REG_CLASS_LABEL = { amateur: 'Amateur', commercial: 'Commercial', pstn: 'PSTN', internal: 'Internal' };

    function renderList() {
        var tbody = document.getElementById('hscListRows');
        if (!channels.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-body-secondary">No stream channels configured yet. Click "New Stream" to add one.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < channels.length; i++) {
            var c = channels[i];
            html += '<tr>'
                + '<td>' + escapeHtml(c.label) + '</td>'
                + '<td class="font-monospace small">' + escapeHtml(c.channel_key) + '</td>'
                + '<td>' + escapeHtml(REG_CLASS_LABEL[c.regulatory_class] || c.regulatory_class) + '</td>'
                + '<td class="small text-truncate" style="max-width:280px" title="' + escapeHtml(c.url) + '">' + escapeHtml(c.url) + '</td>'
                + '<td>' + (Number(c.enabled) === 1 ? '<span class="badge text-bg-success">Enabled</span>' : '<span class="badge text-bg-secondary">Disabled</span>') + '</td>'
                + '<td class="text-end">'
                + '<button type="button" class="btn btn-sm btn-outline-secondary hsc-edit" data-id="' + c.id + '"><i class="bi bi-pencil"></i></button>'
                + '</td></tr>';
        }
        tbody.innerHTML = html;

        var editBtns = tbody.querySelectorAll('.hsc-edit');
        for (var e = 0; e < editBtns.length; e++) {
            editBtns[e].addEventListener('click', function (ev) { openEdit(ev.currentTarget.getAttribute('data-id')); });
        }
    }

    function loadChannels() {
        apiGet('list').then(function (data) {
            if (data && data.channels) {
                channels = data.channels;
                renderList();
            } else if (data && data.error) {
                showToast(data.error, true);
            }
        }).catch(function () { showToast('Failed to load stream channels', true); });
    }

    function resetModal() {
        document.getElementById('hscId').value = '0';
        document.getElementById('hscChannelKey').value = '';
        document.getElementById('hscChannelKey').disabled = false;
        document.getElementById('hscLabel').value = '';
        document.getElementById('hscUrl').value = '';
        document.getElementById('hscRegClass').value = 'internal';
        document.getElementById('hscEnabled').checked = true;
        document.getElementById('hscModalError').className = 'alert alert-danger small mt-2 d-none';
        document.getElementById('hscBtnDelete').className = 'btn btn-outline-danger btn-sm d-none';
    }

    function openNew() {
        resetModal();
        document.getElementById('hscModalTitle').innerHTML = '<i class="bi bi-plus-lg me-2"></i>New Stream';
        if (modal) modal.show();
    }

    function openEdit(id) {
        var c = null;
        for (var i = 0; i < channels.length; i++) { if (String(channels[i].id) === String(id)) { c = channels[i]; break; } }
        if (!c) return;
        resetModal();
        document.getElementById('hscModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit Stream';
        document.getElementById('hscId').value = c.id;
        document.getElementById('hscChannelKey').value = c.channel_key;
        document.getElementById('hscChannelKey').disabled = true; // immutable after creation -- see inc/http-stream-channels.php
        document.getElementById('hscLabel').value = c.label;
        document.getElementById('hscUrl').value = c.url;
        document.getElementById('hscRegClass').value = c.regulatory_class;
        document.getElementById('hscEnabled').checked = Number(c.enabled) === 1;
        document.getElementById('hscBtnDelete').className = 'btn btn-outline-danger btn-sm';
        if (modal) modal.show();
    }

    function showModalError(msg) {
        var el = document.getElementById('hscModalError');
        el.textContent = msg;
        el.className = 'alert alert-danger small mt-2';
    }

    function saveChannel() {
        var id = parseInt(document.getElementById('hscId').value, 10) || 0;
        var url = document.getElementById('hscUrl').value;
        var body = {
            label: document.getElementById('hscLabel').value,
            regulatory_class: document.getElementById('hscRegClass').value,
            url: url,
            enabled: document.getElementById('hscEnabled').checked ? 1 : 0
        };
        if (id > 0) {
            body.id = id;
            apiPost('update', body).then(function (res) {
                if (res && res.ok) {
                    showToast('Stream channel saved and applied live.', false);
                    loadChannels();
                    if (modal) modal.hide();
                } else {
                    showModalError((res && res.error) || 'Save failed');
                }
            });
        } else {
            body.channel_key = document.getElementById('hscChannelKey').value;
            apiPost('create', body).then(function (res) {
                if (res && res.ok) {
                    showToast('Stream channel created and is now live.', false);
                    loadChannels();
                    if (modal) modal.hide();
                } else {
                    showModalError((res && res.error) || 'Create failed');
                }
            });
        }
    }

    function deleteChannel() {
        var id = parseInt(document.getElementById('hscId').value, 10) || 0;
        if (!id) return;
        if (!window.confirm('Delete this stream channel? This stops it immediately and removes any patches routed through it.')) return;
        apiPost('delete', { id: id }).then(function (res) {
            if (res && res.ok) {
                showToast('Stream channel deleted.', false);
                loadChannels();
                if (modal) modal.hide();
            } else {
                showModalError((res && res.error) || 'Delete failed');
            }
        });
    }

    function init() {
        loadChannels();
        document.getElementById('hscBtnNew').addEventListener('click', openNew);
        document.getElementById('hscBtnSave').addEventListener('click', saveChannel);
        document.getElementById('hscBtnDelete').addEventListener('click', deleteChannel);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
