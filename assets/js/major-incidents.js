/**
 * NewUI v4.0 — Major Incidents page controller.
 *
 * Drives major-incidents.php in two modes:
 *   LIST   (no ?id) — table of all majors, "New Major Incident" modal.
 *   DETAIL (?id=X)  — overview + edit, linked incidents, link/unlink/close.
 *
 * All writes go to api/major-incidents.php via fetch POST with a JSON body
 * carrying csrf_token. The API enforces CSRF + rbac_can('action.link_major');
 * the page hides the action controls when the user lacks the permission, and
 * this script reads <body data-can-manage-major> to stay consistent.
 *
 * ES5 only — var, function expressions, addEventListener, fetch. No arrow
 * functions, let/const, or template literals. All dynamic text is escaped
 * with esc() before being placed in innerHTML.
 */
(function () {
    'use strict';

    var SEV_LABELS = ['Minor', 'Major', 'Critical'];
    var SEV_CLASSES = ['bg-secondary', 'bg-warning text-dark', 'bg-danger'];
    var STATUS_TEXT = { 1: 'Closed', 2: 'Open', 3: 'Scheduled' };

    var canManage = false;

    function init() {
        canManage = document.body.getAttribute('data-can-manage-major') === '1';

        var id = getQueryId();
        if (id) {
            initDetailMode(id);
        } else {
            initListMode();
        }
    }

    // ── URL / token helpers ──
    function getQueryId() {
        var params = new URLSearchParams(window.location.search);
        var id = parseInt(params.get('id'), 10);
        return id > 0 ? id : null;
    }

    function getCsrfToken() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : '';
    }

    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }

    function showAlert(areaId, message, type) {
        var area = document.getElementById(areaId);
        if (!area) return;
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            esc(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' +
            '</div>';
        area.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function formatDateTime(dt) {
        if (!dt) return '—';
        var d = new Date(String(dt).replace(' ', 'T'));
        if (isNaN(d.getTime())) return String(dt);
        var month = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        var hours = ('0' + d.getHours()).slice(-2);
        var mins = ('0' + d.getMinutes()).slice(-2);
        return month + '/' + day + '/' + d.getFullYear() + ' ' + hours + ':' + mins;
    }

    function sevBadgeHtml(sev) {
        var s = parseInt(sev, 10) || 0;
        if (s < 0) s = 0; if (s > 2) s = 2;
        return '<span class="badge ' + SEV_CLASSES[s] + '">' + esc(SEV_LABELS[s]) + '</span>';
    }

    function statusBadgeHtml(status) {
        var open = String(status) === 'open';
        return '<span class="badge ' + (open ? 'bg-success' : 'bg-secondary') + '">' +
            (open ? 'Open' : 'Closed') + '</span>';
    }

    // ── POST helper (all writes share this) ──
    function postAction(payload, onSuccess, alertArea) {
        payload.csrf_token = getCsrfToken();
        fetch('api/major-incidents.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) {
                    showAlert(alertArea, data.error, 'danger');
                    return;
                }
                onSuccess(data);
            })
            .catch(function (err) {
                showAlert(alertArea, 'Request failed: ' + err.message, 'danger');
            });
    }

    // ── Commander dropdown population (shared by create + edit) ──
    function loadUsersInto(selectId, selectedId) {
        var sel = document.getElementById(selectId);
        if (!sel) return;
        fetch('api/messaging.php?users=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var users = (data && data.users) || [];
                // Keep the existing first "— None —" option, append users.
                for (var i = 0; i < users.length; i++) {
                    var opt = document.createElement('option');
                    opt.value = users[i].id;
                    opt.textContent = users[i].name;
                    if (selectedId !== null && selectedId !== undefined &&
                        String(users[i].id) === String(selectedId)) {
                        opt.selected = true;
                    }
                    sel.appendChild(opt);
                }
            })
            .catch(function () { /* dropdown stays "None"-only */ });
    }

    // ═══════════════════════════════════════════════════════════
    //  LIST MODE
    // ═══════════════════════════════════════════════════════════
    function initListMode() {
        document.getElementById('listMode').classList.remove('d-none');
        loadList();

        if (canManage) {
            loadUsersInto('newMajorCommander', null);
            var createBtn = document.getElementById('btnCreateMajorSubmit');
            if (createBtn) createBtn.addEventListener('click', submitCreate);
        }
    }

    function loadList() {
        fetch('api/major-incidents.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) {
                    showAlert('listAlertArea', data.error, 'danger');
                    renderList([]);
                    return;
                }
                renderList(data || []);
            })
            .catch(function (err) {
                showAlert('listAlertArea', 'Failed to load major incidents: ' + err.message, 'danger');
            });
    }

    function renderList(rows) {
        var body = document.getElementById('majorListBody');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="8" class="text-center text-body-secondary py-4 small">' +
                '<i class="bi bi-diagram-3 me-1"></i>No major incidents.</td></tr>';
            return;
        }
        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var m = rows[i];
            var href = 'major-incidents.php?id=' + encodeURIComponent(m.id);
            html += '<tr style="cursor:pointer" data-href="' + esc(href) + '" class="major-row">' +
                '<td class="ps-3">' + esc(m.id) + '</td>' +
                '<td class="fw-semibold">' + esc(m.name) + '</td>' +
                '<td>' + sevBadgeHtml(m.severity) + '</td>' +
                '<td>' + statusBadgeHtml(m.status) + '</td>' +
                '<td class="text-center">' + esc(m.linked_count || 0) + '</td>' +
                '<td>' + esc(m.commander_name || '—') + '</td>' +
                '<td>' + esc(formatDateTime(m.created_at)) + '</td>' +
                '<td class="text-end pe-3"><a href="' + esc(href) + '" class="btn btn-sm btn-outline-primary py-0">' +
                '<i class="bi bi-box-arrow-in-right"></i></a></td>' +
                '</tr>';
        }
        body.innerHTML = html;

        var clickRows = body.querySelectorAll('.major-row');
        for (var k = 0; k < clickRows.length; k++) {
            clickRows[k].addEventListener('click', function (e) {
                // Let the explicit link/button handle its own click.
                if (e.target.closest('a')) return;
                window.location.href = this.getAttribute('data-href');
            });
        }
    }

    function submitCreate() {
        var name = document.getElementById('newMajorName').value.trim();
        if (name === '') {
            showAlert('listAlertArea', 'Name is required.', 'warning');
            return;
        }
        var btn = document.getElementById('btnCreateMajorSubmit');
        btn.disabled = true;
        var payload = {
            action: 'create',
            name: name,
            description: document.getElementById('newMajorDescription').value.trim(),
            severity: parseInt(document.getElementById('newMajorSeverity').value, 10) || 0,
            commander: document.getElementById('newMajorCommander').value
        };
        postAction(payload, function (data) {
            // Redirect straight to the new major's detail view.
            window.location.href = 'major-incidents.php?id=' + encodeURIComponent(data.major_id);
        }, 'listAlertArea');
        // If postAction errored, re-enable so the dispatcher can retry.
        setTimeout(function () { btn.disabled = false; }, 1500);
    }

    // ═══════════════════════════════════════════════════════════
    //  DETAIL MODE
    // ═══════════════════════════════════════════════════════════
    var currentMajor = null;

    function initDetailMode(id) {
        document.getElementById('detailMode').classList.remove('d-none');
        loadDetail(id);

        if (canManage) {
            var editBtn = document.getElementById('btnEditMajor');
            if (editBtn) editBtn.addEventListener('click', openEditModal);
            var updateBtn = document.getElementById('btnUpdateMajorSubmit');
            if (updateBtn) updateBtn.addEventListener('click', submitUpdate);
            var closeBtn = document.getElementById('btnCloseMajor');
            if (closeBtn) closeBtn.addEventListener('click', confirmClose);
            initLinkControl(id);
        }
    }

    function loadDetail(id) {
        fetch('api/major-incidents.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.error) {
                    showAlert('detailAlertArea', data.error, 'danger');
                    return;
                }
                currentMajor = data;
                renderDetail(data);
            })
            .catch(function (err) {
                showAlert('detailAlertArea', 'Failed to load major incident: ' + err.message, 'danger');
            });
    }

    function renderDetail(m) {
        document.title = m.name + ' — Major Incident';
        document.getElementById('detailHeading').textContent = m.name;
        document.getElementById('majorName').textContent = m.name;

        var sev = parseInt(m.severity, 10) || 0;
        if (sev < 0) sev = 0; if (sev > 2) sev = 2;
        var sevBadge = document.getElementById('majorSeverityBadge');
        sevBadge.className = 'badge ' + SEV_CLASSES[sev];
        sevBadge.textContent = SEV_LABELS[sev];

        var isOpenStatus = String(m.status) === 'open';
        var statBadge = document.getElementById('majorStatusBadge');
        statBadge.className = 'badge ' + (isOpenStatus ? 'bg-success' : 'bg-secondary');
        statBadge.textContent = isOpenStatus ? 'Open' : 'Closed';

        document.getElementById('majorCommander').textContent = m.commander_name || '—';
        document.getElementById('majorStarted').textContent = formatDateTime(m.created_at);
        document.getElementById('majorLinkedCount').textContent =
            (m.linked_incidents ? m.linked_incidents.length : 0);

        var desc = document.getElementById('majorDescription');
        desc.textContent = (m.description && m.description.trim()) ? m.description : '—';

        var isOpen = isOpenStatus;

        if (!isOpen && m.closed_at) {
            document.getElementById('majorClosedLabel').classList.remove('d-none');
            var closedRow = document.getElementById('majorClosedRow');
            closedRow.classList.remove('d-none');
            closedRow.textContent = formatDateTime(m.closed_at);
        }

        if (canManage) {
            var editBtn = document.getElementById('btnEditMajor');
            if (editBtn) editBtn.classList.remove('d-none');
            var closeBtn = document.getElementById('btnCloseMajor');
            // Close only makes sense (and is API-allowed) while open.
            if (closeBtn) {
                if (isOpen) closeBtn.classList.remove('d-none');
                else closeBtn.classList.add('d-none');
            }
            // Hide the link control once closed (API rejects linking to closed).
            var linkControl = document.getElementById('linkControl');
            if (linkControl) {
                if (isOpen) linkControl.classList.remove('d-none');
                else linkControl.classList.add('d-none');
            }
        }

        renderLinkedIncidents(m.linked_incidents || [], isOpen);
    }

    function renderLinkedIncidents(links, isOpen) {
        var container = document.getElementById('linkedIncidentsList');
        document.getElementById('linkedBadge').textContent = links.length;

        if (!links.length) {
            container.innerHTML = '<div class="text-center text-body-secondary py-3 small">' +
                '<i class="bi bi-link-45deg me-1"></i>No incidents linked yet.</div>';
            return;
        }

        var statusBadge = function (st) {
            var s = parseInt(st, 10);
            var cls = s === 2 ? 'bg-success' : (s === 3 ? 'bg-info' : 'bg-secondary');
            return '<span class="badge ' + cls + '">' + esc(STATUS_TEXT[s] || s) + '</span>';
        };

        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0 small">' +
            '<thead><tr><th class="ps-2">#</th><th>Scope / Title</th><th>Address</th>' +
            '<th>Status</th><th>Sev</th><th>Linked</th>' +
            (canManage && isOpen ? '<th class="text-end pe-2"></th>' : '') +
            '</tr></thead><tbody>';

        for (var i = 0; i < links.length; i++) {
            var L = links[i];
            var addr = [L.street, L.city].filter(function (s) { return s; }).join(', ') || '—';
            html += '<tr>' +
                '<td class="ps-2"><a href="incident-detail.php?id=' + encodeURIComponent(L.ticket_id) + '">' +
                    esc(L.ticket_id) + '</a></td>' +
                '<td>' + esc(L.scope || '') + '</td>' +
                '<td>' + esc(addr) + '</td>' +
                '<td>' + statusBadge(L.status) + '</td>' +
                '<td>' + sevBadgeHtml(L.severity) + '</td>' +
                '<td>' + esc(formatDateTime(L.linked_at)) + '</td>' +
                (canManage && isOpen
                    ? '<td class="text-end pe-2"><button type="button" class="btn btn-sm btn-outline-danger py-0 unlink-btn" ' +
                      'data-ticket-id="' + esc(L.ticket_id) + '" data-scope="' + esc(L.scope || ('#' + L.ticket_id)) + '" ' +
                      'title="Unlink this incident from the major"><i class="bi bi-x-lg"></i></button></td>'
                    : '') +
                '</tr>';
        }
        html += '</tbody></table></div>';
        container.innerHTML = html;

        if (canManage && isOpen) {
            var btns = container.querySelectorAll('.unlink-btn');
            for (var k = 0; k < btns.length; k++) {
                btns[k].addEventListener('click', function () {
                    var ticketId = parseInt(this.getAttribute('data-ticket-id'), 10);
                    // Phase 99p — prefer case number from data-incident-number.
                    var scope = this.getAttribute('data-scope')
                             || this.getAttribute('data-incident-number')
                             || ('#' + ticketId);
                    if (!confirm('Unlink incident "' + scope + '" from this major incident?')) return;
                    unlinkIncident(ticketId);
                });
            }
        }
    }

    function unlinkIncident(ticketId) {
        var id = getQueryId();
        postAction({ action: 'unlink', major_id: id, ticket_id: ticketId }, function (data) {
            showAlert('detailAlertArea', data.message || 'Incident unlinked.', 'info');
            loadDetail(id);
        }, 'detailAlertArea');
    }

    // ── Edit modal ──
    function openEditModal() {
        if (!currentMajor) return;
        document.getElementById('editMajorName').value = currentMajor.name || '';
        document.getElementById('editMajorDescription').value = currentMajor.description || '';
        document.getElementById('editMajorSeverity').value = String(parseInt(currentMajor.severity, 10) || 0);

        // Reset commander dropdown to just the placeholder, then repopulate
        // with the current commander preselected.
        var sel = document.getElementById('editMajorCommander');
        sel.options.length = 1;
        loadUsersInto('editMajorCommander', currentMajor.commander);

        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('editMajorModal'));
        modal.show();
    }

    function submitUpdate() {
        var id = getQueryId();
        var name = document.getElementById('editMajorName').value.trim();
        if (name === '') {
            showAlert('detailAlertArea', 'Name is required.', 'warning');
            return;
        }
        var payload = {
            action: 'update',
            major_id: id,
            name: name,
            description: document.getElementById('editMajorDescription').value.trim(),
            severity: parseInt(document.getElementById('editMajorSeverity').value, 10) || 0,
            commander: document.getElementById('editMajorCommander').value
        };
        postAction(payload, function (data) {
            bootstrap.Modal.getInstance(document.getElementById('editMajorModal')).hide();
            showAlert('detailAlertArea', data.message || 'Updated.', 'success');
            loadDetail(id);
        }, 'detailAlertArea');
    }

    // ── Close (cascade) ──
    function confirmClose() {
        var id = getQueryId();
        var linkedOpen = 0;
        if (currentMajor && currentMajor.linked_incidents) {
            for (var i = 0; i < currentMajor.linked_incidents.length; i++) {
                if (parseInt(currentMajor.linked_incidents[i].status, 10) === 2) linkedOpen++;
            }
        }
        var msg = 'Close this major incident?';
        if (linkedOpen > 0) {
            msg += '\n\nThis will also CLOSE ' + linkedOpen + ' linked open incident' +
                (linkedOpen === 1 ? '' : 's') + ' (status Open → Closed).';
        }
        msg += '\n\nThis cannot be undone from this screen.';
        if (!confirm(msg)) return;

        postAction({ action: 'close', major_id: id }, function (data) {
            var n = (data && typeof data.closed_tickets !== 'undefined') ? data.closed_tickets : 0;
            showAlert('detailAlertArea',
                (data && data.message) || ('Major incident closed. ' + n + ' linked ticket(s) also closed.'),
                'success');
            loadDetail(id);
        }, 'detailAlertArea');
    }

    // ── Link-an-incident search control ──
    var linkSearchTimeout = null;

    function initLinkControl(majorId) {
        var input = document.getElementById('ticketSearch');
        var dropdown = document.getElementById('ticketDropdown');
        if (!input || !dropdown) return;

        input.addEventListener('input', function () {
            var q = input.value.trim();
            if (linkSearchTimeout) clearTimeout(linkSearchTimeout);
            if (q.length < 1) {
                dropdown.classList.add('d-none');
                dropdown.innerHTML = '';
                return;
            }
            linkSearchTimeout = setTimeout(function () { searchOpenTickets(q, majorId); }, 200);
        });

        document.addEventListener('click', function (e) {
            if (!input.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('d-none');
            }
        });
    }

    function searchOpenTickets(query, majorId) {
        var dropdown = document.getElementById('ticketDropdown');
        // status=2 → only OPEN tickets are candidates for linking.
        fetch('api/incident-search.php?status=2&limit=20&q=' + encodeURIComponent(query),
            { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var results = (data && data.results) || [];

                // Exclude tickets already linked to this major.
                var linkedIds = {};
                if (currentMajor && currentMajor.linked_incidents) {
                    for (var i = 0; i < currentMajor.linked_incidents.length; i++) {
                        linkedIds[currentMajor.linked_incidents[i].ticket_id] = true;
                    }
                }

                var html = '';
                var shown = 0;
                for (var j = 0; j < results.length && shown < 12; j++) {
                    var t = results[j];
                    if (linkedIds[t.id]) continue;
                    var addr = [t.street, t.city].filter(function (s) { return s; }).join(', ');
                    html += '<a href="#" class="list-group-item list-group-item-action py-1 px-2 ticket-option" ' +
                        'data-ticket-id="' + esc(t.id) + '">' +
                        '<div class="d-flex justify-content-between align-items-center">' +
                        '<span class="fw-semibold">#' + esc(t.id) + ' ' + esc(t.scope || '') + '</span>' +
                        sevBadgeHtml(t.severity) +
                        '</div>' +
                        '<small class="text-body-secondary">' +
                        esc(t.type_name || '') + (addr ? ' · ' + esc(addr) : '') +
                        '</small></a>';
                    shown++;
                }
                if (shown === 0) {
                    html = '<div class="list-group-item text-body-secondary py-2 small">No matching open incidents.</div>';
                }
                dropdown.innerHTML = html;
                dropdown.classList.remove('d-none');

                var opts = dropdown.querySelectorAll('.ticket-option');
                for (var k = 0; k < opts.length; k++) {
                    opts[k].addEventListener('click', function (e) {
                        e.preventDefault();
                        var tid = parseInt(this.getAttribute('data-ticket-id'), 10);
                        linkIncident(majorId, tid);
                    });
                }
            })
            .catch(function (err) {
                dropdown.innerHTML = '<div class="list-group-item text-danger py-2 small">Search failed: ' +
                    esc(err.message) + '</div>';
                dropdown.classList.remove('d-none');
            });
    }

    function linkIncident(majorId, ticketId) {
        var input = document.getElementById('ticketSearch');
        var dropdown = document.getElementById('ticketDropdown');
        postAction({ action: 'link', major_id: majorId, ticket_id: ticketId }, function (data) {
            if (input) input.value = '';
            if (dropdown) { dropdown.classList.add('d-none'); dropdown.innerHTML = ''; }
            showAlert('detailAlertArea', data.message || 'Incident linked.', 'success');
            loadDetail(majorId);
        }, 'detailAlertArea');
    }

    // ── Boot ──
    document.addEventListener('DOMContentLoaded', init);

})();
