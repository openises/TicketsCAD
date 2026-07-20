/**
 * NewUI v4.0 — Roles & Permissions page (Phase 80b)
 *
 * Standalone 3-column editor backed by the existing RBAC API. Mirrors
 * the surface offered by settings.php's RBAC tab but without dragging in
 * the rest of config.js — this file owns its own state, renders, and
 * fetch wiring.
 *
 * Endpoints reused:
 *   GET  api/rbac.php                       — list roles
 *   GET  api/rbac.php?role_id=N             — role + permissions
 *   GET  api/rbac.php?permissions=1         — full permission registry
 *   GET  api/config-admin.php?section=users — user list (for member col)
 *   POST api/rbac.php action=save_role        — name/description edit
 *   POST api/rbac.php action=delete_role      — remove role
 *   POST api/rbac.php action=set_permissions  — replace permission set
 *   POST api/rbac.php action=remove_role      — strip user grant
 *
 * Conventions:
 *   - ES5 only (var, function, no arrow, no template literals)
 *   - IIFE-wrapped with strict mode
 *   - No jQuery, no build step
 *   - Bootstrap 5 utility classes + bi-* icons
 *   - Debounce permission toggles (300ms) to coalesce rapid clicks
 */
(function () {
    'use strict';

    var CSRF_TOKEN = (document.getElementById('csrfToken') || {}).value || '';

    // Per-page state. selectedRoleId is the role currently loaded into
    // columns B + C; pendingPermIds collects in-flight permission
    // toggles for the debounced save.
    var state = {
        roles: [],
        users: [],
        selectedRoleId: null,
        currentRole: null,         // {id, name, description, ...}
        currentPerms: [],          // full permission list for the selected role
        pendingSave: null,         // setTimeout handle for debounced save
        saveInFlight: false,
        permCategoryOrder: ['screen', 'widget', 'action', 'field'],
        permCategoryLabels: {
            screen: 'Screens',
            widget: 'Widgets',
            action: 'Actions',
            field: 'Data Visibility'
        }
    };

    // ── Utilities ───────────────────────────────────────────────────

    function $(id) { return document.getElementById(id); }

    function esc(s) {
        if (s === null || s === undefined) return '';
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(s)));
        return d.innerHTML;
    }

    function showAlert(message, kind) {
        var slot = $('rolesAlertSlot');
        if (!slot) return;
        var cls = 'alert alert-' + (kind || 'info') + ' alert-dismissible fade show py-2 mb-2 small';
        var node = document.createElement('div');
        node.className = cls;
        node.setAttribute('role', 'alert');
        node.innerHTML = esc(message) +
            ' <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button>';
        slot.appendChild(node);
        // Auto-dismiss success messages so the slot doesn't pile up.
        if (kind === 'success') {
            setTimeout(function () {
                if (node.parentNode) node.parentNode.removeChild(node);
            }, 3500);
        }
    }

    function setPermsStatus(text, kind) {
        var el = $('permsSaveStatus');
        if (!el) return;
        el.textContent = text || '';
        el.className = 'text-' + (kind || 'body-secondary') + ' small ms-auto';
    }

    function apiFetch(url, opts) {
        opts = opts || {};
        opts.credentials = 'same-origin';
        if (opts.method === 'POST' || opts.method === 'PUT' || opts.method === 'DELETE') {
            opts.headers = opts.headers || {};
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
        }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (body) {
                if (!r.ok || (body && body.error)) {
                    var msg = (body && body.error) || ('HTTP ' + r.status);
                    var err = new Error(msg);
                    err.status = r.status;
                    err.body = body;
                    throw err;
                }
                return body;
            });
        });
    }

    // ── Column A: Role list ─────────────────────────────────────────

    function loadRoles() {
        var listEl = $('rolesList');
        if (!listEl) return;
        listEl.innerHTML = '<div class="text-center py-4 text-body-secondary">' +
            '<div class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></div>' +
            '<div class="small mt-2">Loading roles...</div></div>';
        apiFetch('api/rbac.php')
            .then(function (data) {
                state.roles = (data && data.roles) || [];
                renderRoleList();
            })
            .catch(function (err) {
                listEl.innerHTML = '<div class="alert alert-danger small m-2">' +
                    esc(err.message) + '</div>';
            });
    }

    function renderRoleList() {
        var listEl = $('rolesList');
        if (!listEl) return;
        if (!state.roles.length) {
            listEl.innerHTML = '<div class="text-center text-body-secondary py-4 small">' +
                'No roles defined. Click <strong>New Role</strong> to create one.</div>';
            return;
        }
        var html = '';
        var i;
        for (i = 0; i < state.roles.length; i++) {
            var r = state.roles[i];
            var rid = parseInt(r.id, 10);
            var isActive = state.selectedRoleId === rid;
            var activeCls = isActive ? ' active' : '';
            var isSuper = parseInt(r.is_super || 0, 10) === 1;
            html += '<button type="button" ';
            html += 'class="list-group-item list-group-item-action role-card' + activeCls + '" ';
            html += 'data-role-id="' + rid + '" ';
            html += 'aria-pressed="' + (isActive ? 'true' : 'false') + '">';
            html += '  <div class="d-flex align-items-start">';
            html += '    <div class="flex-grow-1 me-2">';
            html += '      <div class="fw-semibold small">';
            if (isSuper) {
                html += '<i class="bi bi-star-fill text-warning me-1" title="Super admin role"></i>';
            } else {
                html += '<i class="bi bi-shield me-1"></i>';
            }
            html += esc(r.name);
            html += '      </div>';
            if (r.description) {
                html += '      <div class="text-body-secondary" style="font-size:0.72rem;line-height:1.25;">' +
                    esc(r.description) + '</div>';
            }
            html += '    </div>';
            html += '    <div class="text-end" style="min-width:60px;">';
            html += '      <span class="badge bg-secondary" title="Permissions">' +
                (parseInt(r.perm_count || 0, 10)) + '</span><br>';
            html += '      <span class="badge bg-primary mt-1" title="Users assigned">' +
                (parseInt(r.user_count || 0, 10)) + ' user' +
                (parseInt(r.user_count || 0, 10) === 1 ? '' : 's') + '</span>';
            html += '    </div>';
            html += '  </div>';
            html += '</button>';
        }
        listEl.innerHTML = html;

        var cards = listEl.querySelectorAll('.role-card');
        for (i = 0; i < cards.length; i++) {
            cards[i].addEventListener('click', function () {
                var rid = parseInt(this.getAttribute('data-role-id'), 10);
                selectRole(rid);
            });
        }
    }

    // ── Column B: Selected role detail ──────────────────────────────

    function selectRole(roleId) {
        state.selectedRoleId = roleId;
        // Refresh active marker without a full re-render of column A.
        var cards = document.querySelectorAll('#rolesList .role-card');
        var i;
        for (i = 0; i < cards.length; i++) {
            var rid = parseInt(cards[i].getAttribute('data-role-id'), 10);
            if (rid === roleId) {
                cards[i].classList.add('active');
                cards[i].setAttribute('aria-pressed', 'true');
            } else {
                cards[i].classList.remove('active');
                cards[i].setAttribute('aria-pressed', 'false');
            }
        }
        loadRoleDetail(roleId);
    }

    function loadRoleDetail(roleId) {
        var panel = $('roleDetailPanel');
        var matrix = $('permissionMatrix');
        if (!panel || !matrix) return;
        panel.innerHTML = '<div class="text-center py-4">' +
            '<div class="spinner-border spinner-border-sm"></div></div>';
        matrix.innerHTML = '<div class="text-center py-4">' +
            '<div class="spinner-border spinner-border-sm"></div></div>';
        setPermsStatus('');

        Promise.all([
            apiFetch('api/rbac.php?role_id=' + encodeURIComponent(roleId)),
            apiFetch('api/config-admin.php?section=users')
        ]).then(function (results) {
            var roleData  = results[0];
            var usersData = results[1];
            state.currentRole  = roleData.role || null;
            state.currentPerms = roleData.permissions || [];
            state.users        = (usersData && usersData.rows) || [];
            renderRoleDetail(roleData);
            renderPermissionMatrix();
        }).catch(function (err) {
            panel.innerHTML = '<div class="alert alert-danger small">' + esc(err.message) + '</div>';
            matrix.innerHTML = '';
        });
    }

    function renderRoleDetail(data) {
        var panel = $('roleDetailPanel');
        if (!panel) return;
        var role = data.role;
        if (!role) {
            panel.innerHTML = '<div class="alert alert-warning small">Role not found.</div>';
            return;
        }
        var userCount = parseInt(data.user_count || 0, 10);
        var isSuper = parseInt(role.is_super || 0, 10) === 1;

        // Determine if delete is safe — the API does the real check, but
        // we also pre-block the only-remaining super-admin case so the
        // button reads correctly. lastRemainingSuper is true iff this
        // role grants is_super AND no other is_super role has users.
        var lastRemainingSuper = false;
        if (isSuper) {
            var otherSuperHasUsers = false;
            var i;
            for (i = 0; i < state.roles.length; i++) {
                var r = state.roles[i];
                if (parseInt(r.id, 10) === parseInt(role.id, 10)) continue;
                if (parseInt(r.is_super || 0, 10) === 1 &&
                    parseInt(r.user_count || 0, 10) > 0) {
                    otherSuperHasUsers = true;
                    break;
                }
            }
            lastRemainingSuper = !otherSuperHasUsers;
        }

        var html = '';
        html += '<form id="roleMetaForm" class="mb-3">';
        html += '  <div class="mb-2">';
        html += '    <label class="form-label form-label-sm mb-1" for="roleName">Name <span class="text-danger">*</span></label>';
        html += '    <input type="text" class="form-control form-control-sm" id="roleName" ' +
                'maxlength="64" value="' + esc(role.name) + '" required>';
        html += '  </div>';
        html += '  <div class="mb-2">';
        html += '    <label class="form-label form-label-sm mb-1" for="roleDescription">Description</label>';
        html += '    <textarea class="form-control form-control-sm" id="roleDescription" ' +
                'rows="3" maxlength="255">' + esc(role.description || '') + '</textarea>';
        html += '  </div>';
        html += '  <div class="d-flex align-items-center gap-2">';
        html += '    <button type="submit" class="btn btn-sm btn-success">' +
                '<i class="bi bi-check-lg me-1"></i>Save</button>';
        html += '    <button type="button" class="btn btn-sm btn-outline-danger ms-auto" id="btnDeleteRole"' +
                (lastRemainingSuper ? ' disabled title="Cannot delete the only super-admin role"' : '') + '>';
        html += '      <i class="bi bi-trash me-1"></i>Delete';
        html += '    </button>';
        html += '  </div>';
        html += '</form>';

        // Assigned users section
        html += '<div class="border-top pt-2">';
        html += '  <div class="fw-semibold small mb-2">';
        html += '    <i class="bi bi-people-fill me-1"></i>Users assigned ';
        html += '    <span class="badge bg-secondary">' + userCount + '</span>';
        html += '  </div>';
        html += '  <div id="assignedUserList" class="small" style="max-height: 30vh; overflow-y: auto;">';
        // 2026-06-30 (Eric beta) — use the canonical assigned_users list
        // returned by the API. Previously we filtered state.users locally
        // for role_id === this role, but that list comes from
        // api/config-admin.php and reports each user's MOST RECENT grant
        // only. A user with multiple grants (e.g. Dispatcher + Super
        // Admin) would appear in Dispatcher's badge count but NOT in
        // Dispatcher's list — they only showed under Super Admin.
        // data.assigned_users includes everyone with ANY active grant of
        // this role, matching the badge count exactly.
        var assigned = data.assigned_users || [];
        var j;
        if (!assigned.length) {
            html += '<div class="text-body-secondary py-2">No users have this role.</div>';
        } else {
            html += '<ul class="list-group list-group-flush">';
            for (j = 0; j < assigned.length; j++) {
                var u = assigned[j];
                html += '<li class="list-group-item d-flex align-items-center py-1 px-2">';
                html += '  <i class="bi bi-person me-2"></i>';
                html += '  <span class="flex-grow-1">' + esc(u.user) + '</span>';
                html += '  <button type="button" class="btn btn-sm btn-link text-danger p-0 remove-user-grant" ' +
                        'data-user-id="' + parseInt(u.id, 10) + '" ' +
                        'data-username="' + esc(u.user) + '" ' +
                        'title="Remove this user from the role">';
                html += '    <i class="bi bi-x-circle"></i>';
                html += '  </button>';
                html += '</li>';
            }
            html += '</ul>';
        }
        html += '  </div>';
        html += '</div>';

        panel.innerHTML = html;

        // Bind meta save
        var metaForm = $('roleMetaForm');
        if (metaForm) {
            metaForm.addEventListener('submit', function (e) {
                e.preventDefault();
                saveRoleMeta();
            });
        }
        // Bind delete
        var delBtn = $('btnDeleteRole');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                deleteRole();
            });
        }
        // Bind remove-user actions
        var removeBtns = panel.querySelectorAll('.remove-user-grant');
        var k;
        for (k = 0; k < removeBtns.length; k++) {
            removeBtns[k].addEventListener('click', function () {
                var uid = parseInt(this.getAttribute('data-user-id'), 10);
                var uname = this.getAttribute('data-username') || '';
                removeUserFromRole(uid, uname);
            });
        }
    }

    function saveRoleMeta() {
        if (!state.selectedRoleId) return;
        var nameEl = $('roleName');
        var descEl = $('roleDescription');
        var name = (nameEl && nameEl.value || '').trim();
        var desc = (descEl && descEl.value || '').trim();
        if (!name) {
            showAlert('Name is required', 'warning');
            return;
        }
        apiFetch('api/rbac.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'save_role',
                id: state.selectedRoleId,
                name: name,
                description: desc,
                sort_order: parseInt((state.currentRole && state.currentRole.sort_order) || 0, 10)
            })
        }).then(function () {
            showAlert('Role "' + name + '" saved.', 'success');
            // Refresh the role list to reflect the new name/desc + counts.
            loadRoles();
            loadRoleDetail(state.selectedRoleId);
        }).catch(function (err) {
            showAlert('Save failed: ' + err.message, 'danger');
        });
    }

    function deleteRole() {
        if (!state.selectedRoleId || !state.currentRole) return;
        var name = state.currentRole.name || ('role #' + state.selectedRoleId);
        if (!window.confirm('Delete role "' + name + '"? This removes the role and all grants of it. ' +
            'Users will lose access until reassigned to another role.')) {
            return;
        }
        apiFetch('api/rbac.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'delete_role',
                id: state.selectedRoleId
            })
        }).then(function () {
            showAlert('Role "' + name + '" deleted.', 'success');
            state.selectedRoleId = null;
            state.currentRole = null;
            state.currentPerms = [];
            $('roleDetailPanel').innerHTML =
                '<div class="text-center text-body-secondary py-5">' +
                '<i class="bi bi-arrow-left-circle display-6 d-block mb-2 opacity-25"></i>' +
                '<span class="small">Select a role on the left to edit.</span></div>';
            $('permissionMatrix').innerHTML =
                '<div class="text-center text-body-secondary py-5">' +
                '<i class="bi bi-shield-check display-6 d-block mb-2 opacity-25"></i>' +
                '<span class="small">Select a role to see its permissions.</span></div>';
            loadRoles();
        }).catch(function (err) {
            // The API surfaces the "only super-admin" lock-out as a 400,
            // which is what we want — show the server's wording.
            showAlert('Delete failed: ' + err.message, 'danger');
        });
    }

    function removeUserFromRole(userId, username) {
        if (!state.selectedRoleId || !userId) return;
        if (!window.confirm('Remove ' + username + ' from this role?')) return;
        apiFetch('api/rbac.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'remove_role',
                user_id: userId,
                role_id: state.selectedRoleId
            })
        }).then(function () {
            showAlert(username + ' removed from role.', 'success');
            loadRoles();
            loadRoleDetail(state.selectedRoleId);
        }).catch(function (err) {
            showAlert('Remove failed: ' + err.message, 'danger');
        });
    }

    // ── Column C: Permission matrix ─────────────────────────────────

    function renderPermissionMatrix() {
        var matrix = $('permissionMatrix');
        if (!matrix) return;
        var perms = state.currentPerms || [];
        if (!perms.length) {
            matrix.innerHTML = '<div class="text-body-secondary text-center py-4 small">' +
                'No permissions defined.</div>';
            return;
        }

        // Group by category
        var grouped = {};
        var i;
        for (i = 0; i < perms.length; i++) {
            var p = perms[i];
            var cat = p.category || 'other';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(p);
        }
        // Render in stable order; categories not in the canonical order
        // appear at the bottom.
        var order = state.permCategoryOrder.slice();
        for (var c in grouped) {
            if (grouped.hasOwnProperty(c) && order.indexOf(c) === -1) order.push(c);
        }

        var html = '';
        for (i = 0; i < order.length; i++) {
            var cat2 = order[i];
            var bucket = grouped[cat2];
            if (!bucket || !bucket.length) continue;
            var label = state.permCategoryLabels[cat2] || cat2;
            html += '<div class="mb-3">';
            html += '  <div class="fw-semibold small text-uppercase text-body-secondary border-bottom mb-1 pb-1">';
            html += esc(label) + ' <span class="text-body-tertiary">(' + bucket.length + ')</span>';
            html += '  </div>';
            for (var b = 0; b < bucket.length; b++) {
                var perm = bucket[b];
                var pid = parseInt(perm.id, 10);
                var granted = parseInt(perm.granted || 0, 10) === 1;
                var permCode = perm.code || perm.name || ('perm_' + pid);
                html += '<div class="form-check form-check-sm py-0 mb-1">';
                html += '  <input class="form-check-input perm-cb" type="checkbox" ' +
                        'id="perm_cb_' + pid + '" data-perm-id="' + pid + '"' +
                        (granted ? ' checked' : '') + '>';
                html += '  <label class="form-check-label small" for="perm_cb_' + pid + '">';
                html += '    <span class="fw-semibold">' + esc(perm.name || permCode) + '</span>';
                html += '    <code class="text-body-tertiary ms-1" style="font-size:0.7rem;">' +
                        esc(permCode) + '</code>';
                if (perm.description) {
                    html += '    <div class="text-body-secondary" style="font-size:0.7rem;line-height:1.2;">' +
                        esc(perm.description) + '</div>';
                }
                html += '  </label>';
                html += '</div>';
            }
            html += '</div>';
        }
        matrix.innerHTML = html;

        // Bind toggle handlers
        var cbs = matrix.querySelectorAll('.perm-cb');
        for (i = 0; i < cbs.length; i++) {
            cbs[i].addEventListener('change', function () {
                var pid = parseInt(this.getAttribute('data-perm-id'), 10);
                togglePermission(pid, this.checked);
            });
        }
    }

    /**
     * Apply the toggle to local state immediately, then schedule a
     * debounced save. The set_permissions endpoint is a full
     * replacement, so we always push the complete set of granted IDs.
     */
    function togglePermission(permId, granted) {
        if (!state.selectedRoleId) return;
        var i;
        for (i = 0; i < state.currentPerms.length; i++) {
            if (parseInt(state.currentPerms[i].id, 10) === permId) {
                state.currentPerms[i].granted = granted ? 1 : 0;
                break;
            }
        }
        setPermsStatus('Pending...', 'body-secondary');
        if (state.pendingSave) {
            clearTimeout(state.pendingSave);
        }
        state.pendingSave = setTimeout(savePermissions, 300);
    }

    function savePermissions() {
        if (!state.selectedRoleId) return;
        if (state.saveInFlight) {
            // Re-arm; another toggle came in mid-flight.
            state.pendingSave = setTimeout(savePermissions, 300);
            return;
        }
        var ids = [];
        var i;
        for (i = 0; i < state.currentPerms.length; i++) {
            if (parseInt(state.currentPerms[i].granted || 0, 10) === 1) {
                ids.push(parseInt(state.currentPerms[i].id, 10));
            }
        }
        state.saveInFlight = true;
        setPermsStatus('Saving...', 'body-secondary');
        apiFetch('api/rbac.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'set_permissions',
                role_id: state.selectedRoleId,
                permission_ids: ids
            })
        }).then(function (data) {
            state.saveInFlight = false;
            setPermsStatus('Saved (' + (data.count || ids.length) + ')', 'success');
            // Refresh the role list so the perm-count badge updates.
            loadRoles();
            // Auto-clear the status after a couple of seconds.
            setTimeout(function () { setPermsStatus(''); }, 2500);
        }).catch(function (err) {
            state.saveInFlight = false;
            setPermsStatus('Save failed', 'danger');
            showAlert('Permission save failed: ' + err.message, 'danger');
        });
    }

    // ── New role modal ──────────────────────────────────────────────

    function bindNewRoleButton() {
        var btn = $('btnNewRole');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var nameInput = $('newRoleName');
            var descInput = $('newRoleDesc');
            if (nameInput) nameInput.value = '';
            if (descInput) descInput.value = '';
            // Bootstrap 5 modal
            if (window.bootstrap && window.bootstrap.Modal) {
                var modal = window.bootstrap.Modal.getOrCreateInstance($('newRoleModal'));
                modal.show();
            }
        });

        var form = $('newRoleForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                createRole();
            });
        }
    }

    function createRole() {
        var name = ($('newRoleName').value || '').trim();
        var desc = ($('newRoleDesc').value || '').trim();
        if (!name) {
            showAlert('Name is required', 'warning');
            return;
        }
        apiFetch('api/rbac.php', {
            method: 'POST',
            body: JSON.stringify({
                action: 'save_role',
                name: name,
                description: desc,
                sort_order: 10
            })
        }).then(function (data) {
            showAlert('Role "' + name + '" created.', 'success');
            if (window.bootstrap && window.bootstrap.Modal) {
                var modal = window.bootstrap.Modal.getInstance($('newRoleModal'));
                if (modal) modal.hide();
            }
            var newId = parseInt(data.id, 10);
            loadRoles();
            if (newId) {
                // After list refresh, jump the user straight into editing
                // the new role's permissions.
                setTimeout(function () {
                    state.selectedRoleId = newId;
                    selectRole(newId);
                }, 250);
            }
        }).catch(function (err) {
            showAlert('Create failed: ' + err.message, 'danger');
        });
    }

    // ── Legacy-accounts migration (Phase 11 carry-over) ─────────────
    //
    // Surfaces the one-time "Migrate Legacy Accounts to Roles" button
    // when api/rbac.php?action=migration_status reports user accounts
    // without active grants. Once the install is fully on RBAC the
    // confirmation block replaces the button.

    function refreshMigrationStatus() {
        var wrap = $('migrateLegacyWrap');
        var done = $('migrateLegacyDone');
        if (!wrap || !done) return;
        apiFetch('api/rbac.php?action=migration_status')
            .then(function (data) {
                if (data && data.needs_migration) {
                    wrap.style.display = '';
                    done.style.display = 'none';
                    var hint = $('migrateLegacyHint');
                    if (hint && typeof data.legacy_only_users === 'number' &&
                        data.legacy_only_users > 0) {
                        hint.textContent = data.legacy_only_users +
                            ' user account' + (data.legacy_only_users !== 1 ? 's' : '') +
                            ' from the legacy install still need a role.';
                    }
                } else {
                    wrap.style.display = 'none';
                    done.style.display = '';
                }
            })
            .catch(function () {
                // Endpoint missing — leave both blocks hidden; this
                // page is no place to nag.
                wrap.style.display = 'none';
                done.style.display = 'none';
            });
    }

    function bindMigrateLegacyButton() {
        var btn = $('btnMigrateLevels');
        if (!btn) return;
        var defaultHtml = btn.innerHTML;
        btn.addEventListener('click', function () {
            if (!window.confirm('Assign roles to user accounts carried over from a legacy installation? ' +
                'Existing roles will not be changed.')) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Working...';
            apiFetch('api/rbac.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'migrate_levels' })
            }).then(function (data) {
                btn.disabled = false;
                btn.innerHTML = defaultHtml;
                showAlert('Assigned roles to ' + data.migrated +
                    ' of ' + data.total_users + ' user account(s).', 'success');
                loadRoles();
                refreshMigrationStatus();
            }).catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = defaultHtml;
                showAlert('Migration failed: ' + err.message, 'danger');
            });
        });
    }

    // ── Boot ────────────────────────────────────────────────────────

    function init() {
        bindNewRoleButton();
        bindMigrateLegacyButton();
        refreshMigrationStatus();
        loadRoles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose a small surface for tests / future cross-page hooks.
    window.RolesPage = {
        loadRoles: loadRoles,
        selectRole: selectRole,
        saveRoleMeta: saveRoleMeta,
        deleteRole: deleteRole,
        togglePermission: togglePermission,
        removeUserFromRole: removeUserFromRole,
        _state: state
    };
})();
