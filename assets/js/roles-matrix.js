/**
 * NewUI v4.0 — Permissions Matrix (Phase 99u-2, 2026-06-29).
 *
 * Loads the full permission × role grid in two parallel fetches:
 *   GET /api/rbac.php?action=permission_audit  → permissions + grants + dismissals
 *   GET /api/rbac.php                          → role list (filter is_system out)
 *
 * Wires up per-cell click → POST set_role_permission and per-row
 * Dismiss/Re-open → POST dismiss_permission / undismiss_permission.
 *
 * All state lives in module-locals; re-render filters in-memory
 * after every mutation so the UI always reflects the latest server
 * truth without a full refetch.
 */
(function () {
    'use strict';

    var STATE = {
        permissions: [],   // [{id, code, name, category, description, roles_granted:[{id,name,is_system}], dismissed, unreviewed}]
        roles:       [],   // non-system roles only [{id, name}]
        rolesById:   {},
        csrf:        '',
        filter: {
            scope: 'unreviewed',
            category: '',
            text: ''
        }
    };

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.textContent = String(s);
        return d.innerHTML;
    }

    function alertSlot(level, msg) {
        var slot = document.getElementById('matrixAlertSlot');
        if (!slot) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + level + ' alert-dismissible fade show py-2 small';
        div.innerHTML = escHtml(msg) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        slot.appendChild(div);
        setTimeout(function () { div.remove(); }, 5000);
    }

    function csrfToken() {
        if (STATE.csrf) return STATE.csrf;
        var meta = document.querySelector('meta[name="csrf-token"]');
        STATE.csrf = meta ? meta.getAttribute('content') : '';
        return STATE.csrf;
    }

    function postRbac(action, body) {
        body.action = action;
        body.csrf_token = csrfToken();
        return fetch('api/rbac.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) {
            return r.json().then(function (json) {
                if (!r.ok || (json && json.error)) {
                    throw new Error((json && json.error) ? json.error : ('HTTP ' + r.status));
                }
                return json;
            });
        });
    }

    function load() {
        return Promise.all([
            fetch('api/rbac.php?action=permission_audit', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); }),
            fetch('api/rbac.php', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
        ]).then(function (results) {
            var audit = results[0];
            var rolesPayload = results[1];

            STATE.permissions = (audit && audit.permissions) ? audit.permissions : [];

            // Role list: api/rbac.php (no params) returns {roles:[...]} or similar.
            // Filter to non-system. Sort by name.
            var rawRoles = [];
            if (rolesPayload && Array.isArray(rolesPayload.roles)) rawRoles = rolesPayload.roles;
            else if (Array.isArray(rolesPayload))                  rawRoles = rolesPayload;

            // Phase 99u-2 followup (Eric beta 2026-06-30): show ALL
            // non-Super-Admin roles. System roles (Operator, Dispatcher,
            // Org Admin, Read-Only, Field Unit) are admin-configurable
            // defaults and need to be visible+editable on the matrix —
            // otherwise admins can't see which permissions are already
            // covered by the seed roles. Only Super Admin (is_super=1)
            // is hidden to prevent accidental lock-out.
            STATE.roles = rawRoles
                .filter(function (r) {
                    return !parseInt(r.is_super || 0, 10);
                })
                .sort(function (a, b) {
                    // System roles first (alphabetical), then custom roles.
                    var aSys = parseInt(a.is_system || 0, 10);
                    var bSys = parseInt(b.is_system || 0, 10);
                    if (aSys !== bSys) return bSys - aSys;
                    return String(a.name || '').localeCompare(String(b.name || ''));
                });
            STATE.rolesById = {};
            STATE.roles.forEach(function (r) { STATE.rolesById[r.id] = r; });

            populateCategoryFilter();
            render();
        }).catch(function (e) {
            alertSlot('danger', 'Failed to load matrix: ' + e.message);
        });
    }

    function populateCategoryFilter() {
        var sel = document.getElementById('filterCategory');
        if (!sel) return;
        var cats = {};
        STATE.permissions.forEach(function (p) {
            if (p.category) cats[p.category] = true;
        });
        var sorted = Object.keys(cats).sort();
        // wipe + repopulate
        while (sel.options.length > 1) sel.remove(1);
        sorted.forEach(function (c) {
            var opt = document.createElement('option');
            opt.value = c;
            opt.textContent = c;
            sel.appendChild(opt);
        });
    }

    function filteredPermissions() {
        var f = STATE.filter;
        var text = (f.text || '').toLowerCase();
        return STATE.permissions.filter(function (p) {
            if (p.deprecated_alias_of) return false;
            if (f.scope === 'unreviewed' && !p.unreviewed) return false;
            if (f.scope === 'dismissed'  && !p.dismissed)  return false;
            if (f.scope === 'granted') {
                // at least one non-system role grants it
                var has = (p.roles_granted || []).some(function (rg) {
                    return !rg.is_system;
                });
                if (!has) return false;
            }
            if (f.category && p.category !== f.category) return false;
            if (text) {
                var hay = (p.code + ' ' + (p.name || '') + ' ' + (p.description || '')).toLowerCase();
                if (hay.indexOf(text) < 0) return false;
            }
            return true;
        });
    }

    function permIsGrantedToRole(perm, roleId) {
        return (perm.roles_granted || []).some(function (rg) {
            return parseInt(rg.id, 10) === parseInt(roleId, 10);
        });
    }

    function categoryColor(cat) {
        switch (cat) {
            case 'screen': return 'primary';
            case 'widget': return 'info';
            case 'action': return 'success';
            case 'field':  return 'secondary';
            default:       return 'dark';
        }
    }

    function renderHead() {
        var head = document.getElementById('permMatrixHead');
        if (!head) return;
        var html = '<th class="perm-col">Permission</th>';
        STATE.roles.forEach(function (r) {
            var isSystem = parseInt(r.is_system || 0, 10);
            var tip = isSystem
                ? escHtml(r.name) + ' (system role — editable, but seeded by the migration)'
                : escHtml(r.name);
            var label = escHtml(r.name) + (isSystem ? ' *' : '');
            html += '<th class="role-col" data-role-id="' + r.id +
                    '" title="' + tip + '">' + label + '</th>';
        });
        head.innerHTML = html;
    }

    function renderBody() {
        var body = document.getElementById('permMatrixBody');
        if (!body) return;
        var perms = filteredPermissions();
        if (!perms.length) {
            body.innerHTML = '<tr><td class="perm-col text-body-secondary text-center" colspan="' +
                (STATE.roles.length + 1) + '">No permissions match the current filter.</td></tr>';
            updateSummary(perms);
            return;
        }
        var html = '';
        perms.forEach(function (p) {
            var rowCls = '';
            if (p.unreviewed) rowCls = 'unreviewed';
            else if (p.dismissed) rowCls = 'dismissed';

            var dismissAction = p.dismissed
                ? '<a href="#" class="text-info perm-undismiss" data-perm-id="' + p.id + '" title="Re-open review">' +
                  '<i class="bi bi-arrow-counterclockwise"></i> Re-open</a>'
                : '<a href="#" class="text-body-secondary perm-dismiss" data-perm-id="' + p.id + '" title="Dismiss from audit">' +
                  '<i class="bi bi-x-circle"></i> Dismiss</a>';

            html += '<tr class="' + rowCls + '" data-perm-id="' + p.id + '">';
            html += '<td class="perm-col">' +
                '<div><code>' + escHtml(p.code) + '</code> ' +
                '<span class="badge bg-' + categoryColor(p.category) + ' badge-cat">' +
                escHtml(p.category || 'misc') + '</span>' +
                (p.unreviewed ? ' <span class="badge bg-warning text-dark badge-cat">UN-REVIEWED</span>' : '') +
                (p.dismissed  ? ' <span class="badge bg-info text-dark badge-cat">DISMISSED</span>' : '') +
                '</div>' +
                '<div class="text-body-secondary small">' + escHtml(p.name || '') + '</div>' +
                (p.description ? '<div class="text-body-secondary small fst-italic">' +
                    escHtml(p.description) + '</div>' : '') +
                '<div class="perm-actions">' + dismissAction + '</div>' +
                '</td>';
            STATE.roles.forEach(function (r) {
                var granted = permIsGrantedToRole(p, r.id);
                html += '<td class="cell" data-role-id="' + r.id + '" data-perm-id="' + p.id + '" ' +
                    'title="' + escHtml(p.code) + ' × ' + escHtml(r.name) + '">' +
                    (granted ? '<i class="bi bi-check-lg"></i>' : '<i class="bi bi-dash"></i>') +
                    '</td>';
            });
            html += '</tr>';
        });
        body.innerHTML = html;
        updateSummary(perms);
    }

    function updateSummary(filteredList) {
        var totals = { unreviewed: 0, dismissed: 0, total: STATE.permissions.length };
        STATE.permissions.forEach(function (p) {
            if (p.deprecated_alias_of) return;
            if (p.unreviewed) totals.unreviewed++;
            if (p.dismissed)  totals.dismissed++;
        });
        var sum = document.getElementById('matrixSummary');
        if (!sum) return;
        sum.innerHTML =
            '<span class="me-3">Showing <strong>' + filteredList.length + '</strong> of ' +
            totals.total + '</span>' +
            '<span class="me-3 text-warning">Un-reviewed: <strong>' + totals.unreviewed + '</strong></span>' +
            '<span class="text-info">Dismissed: <strong>' + totals.dismissed + '</strong></span>';
    }

    function render() {
        renderHead();
        renderBody();
    }

    // ── Mutations ────────────────────────────────────────────────────

    function toggleCell(td) {
        var roleId = parseInt(td.getAttribute('data-role-id'), 10);
        var permId = parseInt(td.getAttribute('data-perm-id'), 10);
        var perm = STATE.permissions.find(function (p) { return parseInt(p.id, 10) === permId; });
        if (!perm) return;
        var currentlyGranted = permIsGrantedToRole(perm, roleId);
        var nextGrant = !currentlyGranted;
        td.classList.add('pending');
        postRbac('set_role_permission', {
            role_id: roleId,
            permission_id: permId,
            grant: nextGrant
        }).then(function () {
            // Mutate in-place: update perm.roles_granted, then re-derive flags.
            if (nextGrant) {
                if (!permIsGrantedToRole(perm, roleId)) {
                    var role = STATE.rolesById[roleId];
                    perm.roles_granted.push({
                        id: roleId,
                        name: role.name,
                        is_system: parseInt(role.is_system || 0, 10),
                        is_super:  parseInt(role.is_super  || 0, 10)
                    });
                }
            } else {
                perm.roles_granted = perm.roles_granted.filter(function (rg) {
                    return parseInt(rg.id, 10) !== roleId;
                });
            }
            // Recompute the flags for THIS permission only.
            // "Reviewed" = at least one non-Super-Admin role grants it.
            var nonSuperCount = perm.roles_granted.filter(function (rg) { return !rg.is_super; }).length;
            perm.ungranted_to_human_roles = (nonSuperCount === 0);
            perm.unreviewed = perm.ungranted_to_human_roles && !perm.dismissed;
            render();
        }).catch(function (e) {
            td.classList.remove('pending');
            alertSlot('danger', 'Toggle failed: ' + e.message);
        });
    }

    function dismissPermission(permId) {
        postRbac('dismiss_permission', { permission_id: permId }).then(function () {
            var perm = STATE.permissions.find(function (p) { return parseInt(p.id, 10) === permId; });
            if (perm) {
                perm.dismissed = true;
                perm.unreviewed = false;
            }
            render();
        }).catch(function (e) {
            alertSlot('danger', 'Dismiss failed: ' + e.message);
        });
    }

    function undismissPermission(permId) {
        postRbac('undismiss_permission', { permission_id: permId }).then(function () {
            var perm = STATE.permissions.find(function (p) { return parseInt(p.id, 10) === permId; });
            if (perm) {
                perm.dismissed = false;
                perm.unreviewed = perm.ungranted_to_human_roles;
            }
            render();
        }).catch(function (e) {
            alertSlot('danger', 'Re-open failed: ' + e.message);
        });
    }

    // ── Event wiring ─────────────────────────────────────────────────

    function bindEvents() {
        var body = document.getElementById('permMatrixBody');
        if (body) {
            body.addEventListener('click', function (e) {
                var t = e.target;
                while (t && t !== body) {
                    if (t.classList && t.classList.contains('cell')) {
                        toggleCell(t);
                        return;
                    }
                    if (t.classList && t.classList.contains('perm-dismiss')) {
                        e.preventDefault();
                        dismissPermission(parseInt(t.getAttribute('data-perm-id'), 10));
                        return;
                    }
                    if (t.classList && t.classList.contains('perm-undismiss')) {
                        e.preventDefault();
                        undismissPermission(parseInt(t.getAttribute('data-perm-id'), 10));
                        return;
                    }
                    t = t.parentNode;
                }
            });
        }

        var fScope = document.getElementById('filterScope');
        if (fScope) fScope.addEventListener('change', function () {
            STATE.filter.scope = fScope.value;
            render();
        });
        var fCat = document.getElementById('filterCategory');
        if (fCat) fCat.addEventListener('change', function () {
            STATE.filter.category = fCat.value;
            render();
        });
        var fText = document.getElementById('filterText');
        if (fText) {
            var deb;
            fText.addEventListener('input', function () {
                clearTimeout(deb);
                deb = setTimeout(function () {
                    STATE.filter.text = fText.value;
                    render();
                }, 200);
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        bindEvents();
        load();
    });
})();
