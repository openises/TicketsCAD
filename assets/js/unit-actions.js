/*
 * unit-actions.js — 2026-07-03
 *
 * Eric asked for the situation-view's per-unit action affordances
 * (View / Edit / Dispatch / Status / Note) on the units.php title
 * bar and the unit-detail.php title bar. Rather than duplicate the
 * dashboard app.js modal plumbing (which is tied to its
 * _lastRespondersData cache), this file exposes a page-agnostic
 * window.UnitActions API that takes just the unit id + handle and
 * runs its own fetches.
 *
 * Endpoints reused:
 *   GET  api/unit-statuses.php            → available un_status rows
 *   POST api/responder-status.php         → change status_id
 *   POST api/responder-note.php           → record unit-level note
 *   GET  api/incidents.php?func=0         → open incident list
 *   POST api/incident-assign.php          → assign unit to incident
 *
 * The Bootstrap modal container lives in inc/unit-actions-modal.php
 * — pages that use this JS must include that PHP snippet inside
 * their <body>.
 */
(function () {
    'use strict';

    function _csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function _esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function _modal() {
        return document.getElementById('unitActionModal');
    }
    function _title(t) {
        var el = document.getElementById('unitActionModalTitle');
        if (el) el.textContent = t;
    }
    function _body(html) {
        var el = document.getElementById('unitActionModalBody');
        if (el) el.innerHTML = html;
    }
    function _show() {
        var m = _modal();
        if (!m || !window.bootstrap) return;
        bootstrap.Modal.getOrCreateInstance(m).show();
    }
    function _hide() {
        var m = _modal();
        if (!m || !window.bootstrap) return;
        bootstrap.Modal.getOrCreateInstance(m).hide();
    }
    function _toast(msg) {
        if (typeof window.showBriefToast === 'function') {
            window.showBriefToast(msg);
        } else {
            // Fallback for pages without the toast helper.
            var el = document.getElementById('alertArea');
            if (el) {
                el.innerHTML = '<div class="alert alert-success alert-dismissible fade show py-1 small">'
                    + _esc(msg)
                    + '<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>'
                    + '</div>';
                setTimeout(function () {
                    var a = el.querySelector('.alert');
                    if (a) a.remove();
                }, 3000);
            }
        }
    }

    // ── View / Edit ── simple navigation, but centralized so callers
    // can wire a single "openAction" dispatcher.
    function view(id) {
        if (!id) return;
        window.location.href = 'unit-detail.php?id=' + id;
    }
    function edit(id) {
        if (!id) return;
        window.location.href = 'unit-edit.php?id=' + id;
    }

    // ── Status ──
    function status(id, handle) {
        if (!id) return;
        var label = handle || ('unit #' + id);
        _title('Status — ' + label);
        _body('<div class="text-body-secondary small py-2">'
            + '<i class="bi bi-hourglass-split me-1"></i>Loading statuses&hellip;</div>');
        _show();

        fetch('api/unit-statuses.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var options = (data && data.statuses) || [];
                _renderStatusBody(id, label, options);
            })
            .catch(function (err) {
                _body('<div class="alert alert-danger small mb-0">'
                    + 'Failed to load statuses: ' + _esc(err.message || String(err))
                    + '</div>');
            });
    }
    function _renderStatusBody(id, label, options) {
        // Filter out hidden statuses (matches unit-detail.js gate).
        var visible = [];
        for (var i = 0; i < options.length; i++) {
            var s = options[i];
            // QA #15 — un_status.hide is enum('n','y'), so parseInt('y')===1 was
            // always false and hidden statuses showed as selectable. Match the
            // string form (tolerate a legacy numeric form too).
            if (!(s.hide === 'y' || s.hide === 1 || s.hide === '1')) visible.push(s);
        }
        if (!visible.length) {
            _body('<div class="alert alert-info small mb-0">'
                + 'No unit statuses are configured. Add one in Settings → Statuses.</div>');
            return;
        }
        var html = '<label class="form-label form-label-sm mb-1">Optional note</label>'
                 + '<input type="text" id="uaStatusNote" class="form-control form-control-sm mb-2"'
                 + ' placeholder="Reason / detail (optional)…" maxlength="500">'
                 + '<div class="small text-body-secondary mb-1">Change status:</div>'
                 + '<div class="d-flex flex-wrap gap-1">';
        for (var j = 0; j < visible.length; j++) {
            var s2 = visible[j];
            var style = '';
            if (s2.bg_color) {
                style = 'background:' + _esc(s2.bg_color) + ';'
                      + 'color:' + _esc(s2.text_color || '#fff') + ';'
                      + 'border-color:' + _esc(s2.bg_color) + ';';
            }
            html += '<button type="button" class="btn btn-sm ua-status-btn"'
                 + (style ? ' style="' + style + '"' : ' class="btn btn-sm btn-outline-secondary"')
                 + ' data-status-id="' + s2.id + '"'
                 + ' data-status-val="' + _esc(s2.status_val) + '"'
                 + ' data-extra-type="' + _esc(s2.extra_data_type || '') + '"'
                 + ' data-extra-required="' + _esc(s2.extra_data_required || '') + '">'
                 + _esc(s2.status_val)
                 + '</button>';
        }
        html += '</div>';
        _body(html);

        var btns = document.querySelectorAll('#unitActionModalBody .ua-status-btn');
        for (var k = 0; k < btns.length; k++) {
            btns[k].addEventListener('click', function () {
                var sid = parseInt(this.getAttribute('data-status-id'), 10);
                var sval = this.getAttribute('data-status-val');
                var extraType = this.getAttribute('data-extra-type');
                if (extraType && extraType !== 'none' && extraType !== '') {
                    // Fall back to unit-detail.php for extra-data prompts.
                    // Building a generic extra_data prompter here would
                    // duplicate 200 lines from unit-detail.js — keep the
                    // simple statuses inline, punt the fancy ones.
                    _hide();
                    window.location.href = 'unit-detail.php?id=' + id + '#status';
                    return;
                }
                _postStatus(id, label, sid, sval);
            });
        }
    }
    function _postStatus(id, label, statusId, statusVal) {
        var noteEl = document.getElementById('uaStatusNote');
        var body = {
            responder_id: id,
            status_id: statusId,
            status_about: noteEl ? noteEl.value.trim() : '',
            csrf_token: _csrf()
        };
        fetch('api/responder-status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) { alert(data.error); return; }
            _hide();
            _toast(label + ' → ' + statusVal);
            _afterMutate(id);
        })
        .catch(function (err) {
            alert('Status update failed: ' + (err.message || String(err)));
        });
    }

    // ── Note ── record a unit-level note via audit_log.
    function note(id, handle) {
        if (!id) return;
        var label = handle || ('unit #' + id);
        _title('Note — ' + label);
        _body(
            '<label class="form-label form-label-sm mb-1">Note text</label>'
            + '<textarea id="uaNoteText" class="form-control form-control-sm mb-2" rows="3"'
            + ' maxlength="2000" placeholder="Note about ' + _esc(label) + '&hellip;"></textarea>'
            + '<div class="text-body-secondary small mb-2">'
            + '<i class="bi bi-info-circle me-1"></i>'
            + 'Note lands on the unit\'s audit trail. To attach to an incident instead,'
            + ' add the note from that incident\'s detail page.'
            + '</div>'
            + '<button type="button" class="btn btn-sm btn-success" id="uaNoteSave">'
            + '<i class="bi bi-check2 me-1"></i>Save note</button>'
        );
        _show();
        setTimeout(function () {
            var ta = document.getElementById('uaNoteText');
            if (ta) ta.focus();
        }, 200);

        var btn = document.getElementById('uaNoteSave');
        if (btn) {
            btn.addEventListener('click', function () {
                var ta = document.getElementById('uaNoteText');
                var txt = ta ? ta.value.trim() : '';
                if (!txt) { alert('Note text is required.'); return; }
                fetch('api/responder-note.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        responder_id: id, note: txt, csrf_token: _csrf()
                    })
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.error) { alert(data.error); return; }
                    _hide();
                    _toast('Note recorded for ' + label);
                    _afterMutate(id);
                })
                .catch(function (err) {
                    alert('Failed to record note: ' + (err.message || String(err)));
                });
            });
        }
    }

    // ── Dispatch ── assign to an open incident.
    function dispatch(id, handle) {
        if (!id) return;
        var label = handle || ('unit #' + id);
        _title('Dispatch — ' + label);
        _body('<div class="text-body-secondary small py-2">'
            + '<i class="bi bi-hourglass-split me-1"></i>Loading open incidents&hellip;</div>');
        _show();

        fetch('api/incidents.php?func=0', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var incidents = (data && data.incidents) || [];
                // Open + Scheduled only (matches app.js filter).
                incidents = incidents.filter(function (i) {
                    return i.status === 2 || i.status === 3;
                });
                _renderDispatchBody(id, label, incidents);
            })
            .catch(function (err) {
                _body('<div class="alert alert-danger small mb-0">'
                    + 'Failed to load incidents: ' + _esc(err.message || String(err))
                    + '</div>');
            });
    }
    function _renderDispatchBody(id, label, incidents) {
        if (!incidents.length) {
            _body('<div class="alert alert-info small mb-0">'
                + 'No open incidents to dispatch ' + _esc(label) + ' to.<br>'
                + 'Create a new incident, then dispatch from here or from the incident detail page.'
                + '</div>');
            return;
        }
        var html = '<p class="text-body-secondary small mb-2">'
                 + 'Select an incident to dispatch <strong>' + _esc(label) + '</strong> to:'
                 + '</p><div class="list-group">';
        for (var i = 0; i < incidents.length; i++) {
            var inc = incidents[i];
            var caseNum = inc.incident_number || ('#' + inc.id);
            var scope = inc.scope || inc.incident_type || '(no description)';
            var loc = (inc.street || '') + (inc.city ? ' · ' + inc.city : '');
            var sevDot = inc.severity_color
                ? '<span class="d-inline-block rounded-circle me-2" '
                  + 'style="width:8px;height:8px;background:' + _esc(inc.severity_color) + ';"></span>'
                : '';
            html += '<button type="button" class="list-group-item list-group-item-action py-2 ua-dispatch-btn"'
                 + ' data-ticket-id="' + inc.id + '"'
                 + ' data-case-num="' + _esc(caseNum) + '">'
                 + sevDot
                 + '<span class="font-monospace small text-primary me-2">' + _esc(caseNum) + '</span>'
                 + '<span class="fw-semibold">' + _esc(scope) + '</span>'
                 + (loc ? '<br><span class="text-body-secondary small ms-3">' + _esc(loc) + '</span>' : '')
                 + '</button>';
        }
        html += '</div>';
        _body(html);

        var btns = document.querySelectorAll('#unitActionModalBody .ua-dispatch-btn');
        for (var k = 0; k < btns.length; k++) {
            btns[k].addEventListener('click', function () {
                var tid = parseInt(this.getAttribute('data-ticket-id'), 10);
                var caseNum = this.getAttribute('data-case-num');
                _postDispatch(id, label, tid, caseNum);
            });
        }
    }
    function _postDispatch(id, label, ticketId, caseNum) {
        fetch('api/incident-assign.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'assign',
                ticket_id: ticketId,
                responder_id: id,
                csrf_token: _csrf()
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                alert('Failed to dispatch ' + label + ' to ' + caseNum + ': ' + data.error);
                return;
            }
            _hide();
            _toast(label + ' dispatched to ' + caseNum);
            _afterMutate(id);
        })
        .catch(function (err) {
            alert('Dispatch failed: ' + (err.message || String(err)));
        });
    }

    // Refresh callback — pages can override with UnitActions.onMutate =
    // fn to reload their own list / detail view after a mutation.
    function _afterMutate(id) {
        if (typeof window.UnitActions.onMutate === 'function') {
            window.UnitActions.onMutate(id);
        } else if (typeof window.EventBus !== 'undefined' && typeof window.EventBus.emit === 'function') {
            window.EventBus.emit('widget:refresh', { widget: 'responders' });
        }
    }

    window.UnitActions = {
        view: view,
        edit: edit,
        status: status,
        note: note,
        dispatch: dispatch,
        onMutate: null
    };
})();
