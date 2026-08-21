/**
 * NewUI v4.0 — Audio Matrix Patch Admin (Phase 114c, closes SPEC-STATUS.md §B1)
 *
 * List + grid + create/edit/delete for comm_routes (the audio patch
 * matrix). Backend: api/matrix.php. ES5 IIFE — no arrow functions, no
 * let/const, no template literals (project convention, CLAUDE.md).
 *
 * The client-side cross-class check below is a UX HINT ONLY — it mirrors
 * inc/matrix-routes.php's matrix_blocked_class_pairs(), which itself
 * mirrors services/audio-matrix/matrix_core.py's _BLOCKED_PAIRS. The
 * server independently re-validates every save; this only decides whether
 * to show the acknowledgment checkbox before the user submits.
 */
(function () {
    'use strict';

    var API = 'api/matrix.php';
    var BLOCKED_PAIRS = [['amateur', 'commercial'], ['amateur', 'pstn']];

    var csrfToken = '';
    var channels = [];
    var channelsById = {};
    var routes = [];
    var routesByPair = {}; // "srcId:dstId" -> route

    var mxModalEl = null;
    var mxModal = null;

    document.addEventListener('DOMContentLoaded', function () {
        var tokenEl = document.getElementById('csrfToken');
        csrfToken = tokenEl ? tokenEl.value : '';
        mxModalEl = document.getElementById('mxModal');
        mxModal = (mxModalEl && window.bootstrap) ? new window.bootstrap.Modal(mxModalEl) : null;

        bindEvents();
        loadAll();
    });

    // ═══════════════════════════════════════════════════════════
    // Load
    // ═══════════════════════════════════════════════════════════
    function loadAll() {
        fetch(API, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                channels = (resp.channels || []).slice().sort(function (a, b) {
                    var sa = a.sort_order || 0, sb = b.sort_order || 0;
                    if (sa !== sb) return sa - sb;
                    return (a.label || '').localeCompare(b.label || '');
                });
                channelsById = {};
                channels.forEach(function (c) { channelsById[String(c.id)] = c; });

                routes = resp.routes || [];
                routesByPair = {};
                routes.forEach(function (r) {
                    routesByPair[pairKey(r.src_channel_id, r.dst_channel_id)] = r;
                });

                populateChannelSelects();
                renderGrid();
                renderList();
            })
            .catch(function (err) { showToast('danger', 'Failed to load: ' + err.message); });
    }

    function pairKey(srcId, dstId) { return String(srcId) + ':' + String(dstId); }

    // ═══════════════════════════════════════════════════════════
    // Grid
    // ═══════════════════════════════════════════════════════════
    function renderGrid() {
        var table = document.getElementById('mxGridTable');
        if (!table) return;
        var thead = table.querySelector('thead');
        var tbody = table.querySelector('tbody');
        thead.innerHTML = '';
        tbody.innerHTML = '';

        if (!channels.length) {
            thead.innerHTML = '<tr><th>No channels registered yet — configure at least two channels ' +
                'in the Communications Console before patching.</th></tr>';
            return;
        }

        var headRow = document.createElement('tr');
        var corner = document.createElement('th');
        corner.textContent = 'src \\ dst';
        headRow.appendChild(corner);
        channels.forEach(function (c) {
            var th = document.createElement('th');
            th.title = c.label + ' (' + c.channel_key + ')';
            th.textContent = shortLabel(c);
            headRow.appendChild(th);
        });
        thead.appendChild(headRow);

        channels.forEach(function (src) {
            var row = document.createElement('tr');
            var th = document.createElement('th');
            th.title = src.label + ' (' + src.channel_key + ')';
            th.textContent = shortLabel(src);
            row.appendChild(th);

            channels.forEach(function (dst) {
                var td = document.createElement('td');
                if (String(src.id) === String(dst.id)) {
                    var diag = document.createElement('span');
                    diag.className = 'mx-cell mx-cell-diag bg-secondary-subtle';
                    diag.textContent = '—';
                    td.appendChild(diag);
                    row.appendChild(td);
                    return;
                }
                var route = routesByPair[pairKey(src.id, dst.id)];
                var cell = document.createElement('span');
                cell.setAttribute('role', 'button');
                cell.setAttribute('tabindex', '0');
                if (route) {
                    // Colour comes from Bootstrap's own text-bg-* utility
                    // classes (background + readable text together, themed
                    // via Bootstrap's CSS variables) rather than any custom
                    // colour rule in matrix-admin.css.
                    var cls = 'mx-cell mx-cell-';
                    if (Number(route.allow_cross_class) === 1) {
                        cls += 'cross text-bg-warning';
                    } else if (Number(route.enabled) === 1) {
                        cls += 'on text-bg-success';
                    } else {
                        cls += 'off text-bg-secondary';
                    }
                    cell.className = cls;
                    cell.textContent = Number(route.enabled) === 1 ? 'P' : 'off';
                    cell.title = src.label + ' → ' + dst.label + ' | gain ' + route.gain_db +
                        'dB, priority ' + route.priority + (route.note ? ', "' + route.note + '"' : '');
                    (function (r) {
                        cell.addEventListener('click', function () { openModal(r); });
                        cell.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openModal(r); }
                        });
                    })(route);
                } else {
                    cell.className = 'mx-cell mx-cell-empty';
                    cell.textContent = '+';
                    cell.title = 'Create a patch: ' + src.label + ' → ' + dst.label;
                    (function (s, d) {
                        cell.addEventListener('click', function () { openModal(null, s.id, d.id); });
                        cell.addEventListener('keydown', function (ev) {
                            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); openModal(null, s.id, d.id); }
                        });
                    })(src, dst);
                }
                td.appendChild(cell);
                row.appendChild(td);
            });
            tbody.appendChild(row);
        });
    }

    function shortLabel(c) {
        var s = c.short_label || c.label || c.channel_key || ('#' + c.id);
        return s.length > 14 ? s.substring(0, 13) + '…' : s;
    }

    // ═══════════════════════════════════════════════════════════
    // List
    // ═══════════════════════════════════════════════════════════
    function renderList() {
        var body = document.getElementById('mxListRows');
        if (!body) return;
        body.innerHTML = '';
        if (!routes.length) {
            body.innerHTML = '<tr><td colspan="9" class="text-body-secondary">No patches configured yet.</td></tr>';
            return;
        }
        routes.forEach(function (r) {
            var tr = document.createElement('tr');

            var srcLabel = r.src_label || ('#' + r.src_channel_id);
            var dstLabel = r.dst_label || ('#' + r.dst_channel_id);

            var statusBadge = Number(r.enabled) === 1
                ? '<span class="badge bg-success">enabled</span>'
                : '<span class="badge bg-secondary">disabled</span>';
            if (Number(r.allow_cross_class) === 1) {
                statusBadge += ' <span class="badge bg-warning text-dark">cross-class</span>';
            }
            if (!r.src_key || !r.dst_key) {
                statusBadge += ' <span class="badge bg-danger" title="Referenced channel no longer exists">orphaned</span>';
            }

            tr.innerHTML =
                '<td>' + escHtml(srcLabel) + '</td>' +
                '<td class="text-center text-body-secondary"><i class="bi bi-arrow-right"></i></td>' +
                '<td>' + escHtml(dstLabel) + '</td>' +
                '<td class="text-end">' + escHtml(String(r.gain_db)) + ' dB</td>' +
                '<td class="text-end">' + escHtml(String(r.priority)) + '</td>' +
                '<td>' + (Number(r.ducking) === 1 ? 'yes' : 'no') + '</td>' +
                '<td>' + statusBadge + '</td>' +
                '<td class="small text-body-secondary">' + escHtml(r.note || '') + '</td>' +
                '<td class="text-end"></td>';

            var actionsTd = tr.lastElementChild;
            var editBtn = document.createElement('button');
            editBtn.className = 'btn btn-sm btn-outline-secondary me-1';
            editBtn.innerHTML = '<i class="bi bi-pencil"></i>';
            editBtn.title = 'Edit';
            (function (route) {
                editBtn.addEventListener('click', function () { openModal(route); });
            })(r);
            actionsTd.appendChild(editBtn);

            var delBtn = document.createElement('button');
            delBtn.className = 'btn btn-sm btn-outline-danger';
            delBtn.innerHTML = '<i class="bi bi-trash"></i>';
            delBtn.title = 'Delete';
            (function (route) {
                delBtn.addEventListener('click', function () { deletePatch(route); });
            })(r);
            actionsTd.appendChild(delBtn);

            body.appendChild(tr);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Modal
    // ═══════════════════════════════════════════════════════════
    function populateChannelSelects() {
        ['mxSrc', 'mxDst'].forEach(function (id) {
            var sel = document.getElementById(id);
            if (!sel) return;
            sel.innerHTML = '';
            channels.forEach(function (c) {
                var opt = document.createElement('option');
                opt.value = String(c.id);
                opt.textContent = c.label + ' (' + c.regulatory_class + (Number(c.enabled) === 1 ? '' : ', disabled') + ')';
                sel.appendChild(opt);
            });
        });
    }

    function openModal(route, presetSrcId, presetDstId) {
        hideModalError();
        var title = document.getElementById('mxModalTitle');
        var delBtn = document.getElementById('mxBtnDelete');

        document.getElementById('mxId').value = route ? route.id : 0;
        document.getElementById('mxSrc').value = route ? String(route.src_channel_id) : String(presetSrcId || '');
        document.getElementById('mxDst').value = route ? String(route.dst_channel_id) : String(presetDstId || '');
        document.getElementById('mxGain').value = route ? route.gain_db : '0.0';
        document.getElementById('mxPriority').value = route ? route.priority : '0';
        document.getElementById('mxDucking').checked = route ? Number(route.ducking) === 1 : true;
        document.getElementById('mxEnabled').checked = route ? Number(route.enabled) === 1 : true;
        document.getElementById('mxNote').value = route ? (route.note || '') : '';
        document.getElementById('mxAllowCrossClass').checked = route ? Number(route.allow_cross_class) === 1 : false;

        if (title) {
            title.innerHTML = route
                ? '<i class="bi bi-pencil-square me-2"></i>Edit Patch'
                : '<i class="bi bi-plus-lg me-2"></i>New Patch';
        }
        if (delBtn) { delBtn.classList.toggle('d-none', !route); }

        updateCrossClassWarning();
        if (mxModal) { mxModal.show(); }
    }

    function classOf(channelId) {
        var c = channelsById[String(channelId)];
        return c ? (c.regulatory_class || 'internal') : 'internal';
    }

    function classesBlocked(a, b) {
        for (var i = 0; i < BLOCKED_PAIRS.length; i++) {
            var p = BLOCKED_PAIRS[i];
            if ((p[0] === a && p[1] === b) || (p[0] === b && p[1] === a)) { return true; }
        }
        return false;
    }

    function updateCrossClassWarning() {
        var srcSel = document.getElementById('mxSrc');
        var dstSel = document.getElementById('mxDst');
        var wrap = document.getElementById('mxCrossClassWrap');
        var text = document.getElementById('mxCrossClassText');
        if (!srcSel || !dstSel || !wrap) return;
        var srcClass = classOf(srcSel.value);
        var dstClass = classOf(dstSel.value);
        var blocked = srcSel.value && dstSel.value && classesBlocked(srcClass, dstClass);
        wrap.classList.toggle('d-none', !blocked);
        if (blocked && text) {
            text.textContent = 'This patches a ' + srcClass + ' channel to a ' + dstClass +
                ' channel — blocked by FCC Part 97.113 unless explicitly authorized below.';
        }
        if (!blocked) { document.getElementById('mxAllowCrossClass').checked = false; }
    }

    function bindEvents() {
        var btnNew = document.getElementById('mxBtnNew');
        if (btnNew) btnNew.addEventListener('click', function () { openModal(null); });

        var srcSel = document.getElementById('mxSrc');
        var dstSel = document.getElementById('mxDst');
        if (srcSel) srcSel.addEventListener('change', updateCrossClassWarning);
        if (dstSel) dstSel.addEventListener('change', updateCrossClassWarning);

        var saveBtn = document.getElementById('mxBtnSave');
        if (saveBtn) saveBtn.addEventListener('click', savePatch);

        var delBtn = document.getElementById('mxBtnDelete');
        if (delBtn) delBtn.addEventListener('click', function () {
            var id = Number(document.getElementById('mxId').value || 0);
            var route = routes.filter(function (r) { return Number(r.id) === id; })[0];
            if (route) { deletePatch(route, true); }
        });
    }

    function savePatch() {
        hideModalError();
        var id = Number(document.getElementById('mxId').value || 0);
        var srcId = Number(document.getElementById('mxSrc').value || 0);
        var dstId = Number(document.getElementById('mxDst').value || 0);

        if (!srcId || !dstId) { showModalError('Select a source and destination channel.'); return; }
        if (srcId === dstId) { showModalError('A channel cannot be patched to itself.'); return; }

        var payload = {
            csrf_token: csrfToken,
            action: id ? 'update' : 'create',
            src_channel_id: srcId,
            dst_channel_id: dstId,
            gain_db: Number(document.getElementById('mxGain').value || 0),
            priority: Number(document.getElementById('mxPriority').value || 0),
            ducking: document.getElementById('mxDucking').checked ? 1 : 0,
            enabled: document.getElementById('mxEnabled').checked ? 1 : 0,
            allow_cross_class: document.getElementById('mxAllowCrossClass').checked ? 1 : 0,
            note: document.getElementById('mxNote').value || ''
        };
        if (id) { payload.id = id; }

        postJson(payload).then(function (resp) {
            if (resp.error) { showModalError(resp.error); return; }
            showToast('success', id ? 'Patch updated.' : 'Patch created.');
            if (mxModal) { mxModal.hide(); }
            loadAll();
        }).catch(function (err) { showModalError('Failed: ' + err.message); });
    }

    function deletePatch(route, fromModal) {
        var srcLabel = route.src_label || ('#' + route.src_channel_id);
        var dstLabel = route.dst_label || ('#' + route.dst_channel_id);
        if (!window.confirm('Delete the patch ' + srcLabel + ' → ' + dstLabel + '?')) { return; }
        postJson({ csrf_token: csrfToken, action: 'delete', id: route.id }).then(function (resp) {
            if (resp.error) { showToast('danger', resp.error); return; }
            showToast('success', 'Patch deleted.');
            if (fromModal && mxModal) { mxModal.hide(); }
            loadAll();
        }).catch(function (err) { showToast('danger', 'Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Utilities
    // ═══════════════════════════════════════════════════════════
    function postJson(payload) {
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function escHtml(s) {
        var div = document.createElement('div');
        div.textContent = s == null ? '' : String(s);
        return div.innerHTML;
    }

    function showToast(type, message) {
        var area = document.getElementById('mxToast');
        if (!area) return;
        area.className = 'alert alert-' + type;
        area.textContent = message;
        area.classList.remove('d-none');
        setTimeout(function () { area.classList.add('d-none'); }, 5000);
    }

    function showModalError(message) {
        var el = document.getElementById('mxModalError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function hideModalError() {
        var el = document.getElementById('mxModalError');
        if (el) el.classList.add('d-none');
    }
})();
