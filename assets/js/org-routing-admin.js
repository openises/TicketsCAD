/**
 * NewUI v4.0 - Cross-Org Ticket Routing Admin (Phase 141, GH#70)
 *
 * List + create/edit(tier-only)/deactivate for org_type_routing rules.
 * Backend: api/org-routing.php. Follows the same list-panel/editor-panel/
 * confirm-modal shape as assets/js/ics-form-type-admin.js (Phase 140), at a
 * smaller scale -- a routing rule has five meaningful inputs, no field-list
 * builder needed.
 *
 * Immutability (server-enforced by org_routing_rule_update(), inc/org-sharing.php):
 * once a rule is saved, its org pair and match target can never change --
 * only access_tier. Editing an EXISTING rule therefore disables every field
 * except the tier radios; those fields stay editable only while creating a
 * NEW rule.
 */
(function () {
    'use strict';

    var csrfToken = '';
    var editingId = 0;         // 0 = creating a new rule
    var orgList = [];
    var groupList = [];
    var typeList = [];

    document.addEventListener('DOMContentLoaded', function () {
        csrfToken = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';
        bindEvents();
        loadMeta();
        loadRulesList();
    });

    // ═══════════════════════════════════════════════════════════
    // Metadata (orgs + incident type groups/types)
    // ═══════════════════════════════════════════════════════════
    function loadMeta() {
        fetch('api/organizations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                orgList = resp.organizations || [];
                populateOwningOrgSelect();
                populateTargetOrgSelect();
            })
            .catch(function () { /* selects stay empty; save will fail server-side with a clear error */ });

        fetch('api/org-routing.php?meta=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) return;
                groupList = resp.groups || [];
                typeList = resp.types || [];
                populateMatchGroupSelect();
                populateMatchTypeSelect();
            })
            .catch(function () { /* selects stay empty */ });
    }

    function populateOwningOrgSelect() {
        var sel = document.getElementById('orOwningOrg');
        if (!sel) return;

        if (!window.ORTR_CAN_AUTHOR_GLOBAL) {
            // Org-scoped-only author: the server FORCES their own resolved
            // org regardless of what this shows (_ortr_resolve_create_owning_org()
            // in api/org-routing.php) -- this is display only, locked to
            // communicate that plainly. Same convention as
            // ics-form-type-admin.js's initOrgScope().
            sel.innerHTML = '<option value="">(your organization)</option>';
            sel.disabled = true;
            return;
        }

        sel.innerHTML = '<option value="">Select an organization…</option>';
        orgList.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = String(o.id);
            opt.textContent = o.name;
            sel.appendChild(opt);
        });
    }

    function populateTargetOrgSelect() {
        var sel = document.getElementById('orTargetOrg');
        if (!sel) return;
        var owningSel = document.getElementById('orOwningOrg');
        var excludeId = owningSel ? owningSel.value : '';

        var current = sel.value;
        sel.innerHTML = '<option value="">Select an organization…</option>';
        orgList.forEach(function (o) {
            if (excludeId && String(o.id) === excludeId) return; // a rule cannot route to itself
            var opt = document.createElement('option');
            opt.value = String(o.id);
            opt.textContent = o.name;
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    function populateMatchGroupSelect() {
        var sel = document.getElementById('orMatchGroup');
        if (!sel) return;
        var current = sel.value;
        sel.innerHTML = '';
        if (groupList.length === 0) {
            sel.innerHTML = '<option value="">(no incident type groups defined yet)</option>';
            return;
        }
        groupList.forEach(function (g) {
            var opt = document.createElement('option');
            opt.value = g;
            opt.textContent = g;
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    function populateMatchTypeSelect() {
        var sel = document.getElementById('orMatchType');
        if (!sel) return;
        var current = sel.value;
        sel.innerHTML = '';
        if (typeList.length === 0) {
            sel.innerHTML = '<option value="">(no incident types defined yet)</option>';
            return;
        }
        typeList.forEach(function (ty) {
            var opt = document.createElement('option');
            opt.value = String(ty.id);
            opt.textContent = ty.type + (ty.group ? ' (' + ty.group + ')' : '');
            sel.appendChild(opt);
        });
        if (current) sel.value = current;
    }

    function orgName(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < orgList.length; i++) {
            if (parseInt(orgList[i].id, 10) === id) return orgList[i].name;
        }
        return 'Org #' + id;
    }

    // ═══════════════════════════════════════════════════════════
    // Events
    // ═══════════════════════════════════════════════════════════
    function bindEvents() {
        var btnNew = document.getElementById('orBtnNew');
        if (btnNew) btnNew.addEventListener('click', function () { openEditor(null); });

        var btnCancel = document.getElementById('orBtnCancel');
        if (btnCancel) btnCancel.addEventListener('click', closeEditor);
        var btnCancel2 = document.getElementById('orBtnCancel2');
        if (btnCancel2) btnCancel2.addEventListener('click', closeEditor);

        var btnSave = document.getElementById('orBtnSave');
        if (btnSave) btnSave.addEventListener('click', saveRule);

        var owningSel = document.getElementById('orOwningOrg');
        if (owningSel) owningSel.addEventListener('change', populateTargetOrgSelect);

        var scopeGroup = document.getElementById('orMatchScopeGroup');
        var scopeType = document.getElementById('orMatchScopeType');
        if (scopeGroup) scopeGroup.addEventListener('change', toggleMatchScopeInputs);
        if (scopeType) scopeType.addEventListener('change', toggleMatchScopeInputs);

        var listRows = document.getElementById('orListRows');
        if (listRows) {
            listRows.addEventListener('click', function (ev) {
                var editBtn = ev.target.closest('[data-edit-id]');
                if (editBtn) { openEditorById(parseInt(editBtn.getAttribute('data-edit-id'), 10)); return; }
                var deactBtn = ev.target.closest('[data-deactivate-id]');
                if (deactBtn) { openDeactivateConfirm(deactBtn); return; }
            });
        }

        var btnConfirmDeactivate = document.getElementById('orBtnConfirmDeactivate');
        if (btnConfirmDeactivate) btnConfirmDeactivate.addEventListener('click', confirmDeactivate);
    }

    function toggleMatchScopeInputs() {
        var isType = document.getElementById('orMatchScopeType').checked;
        document.getElementById('orMatchGroupWrap').classList.toggle('d-none', isType);
        document.getElementById('orMatchTypeWrap').classList.toggle('d-none', !isType);
    }

    // ═══════════════════════════════════════════════════════════
    // List panel
    // ═══════════════════════════════════════════════════════════
    function loadRulesList() {
        var tbody = document.getElementById('orListRows');
        if (!tbody) return;

        fetch('api/org-routing.php?list=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) {
                    tbody.innerHTML = '<tr><td colspan="8" class="text-danger">' + escHtml(resp.error) + '</td></tr>';
                    return;
                }
                renderRulesList(resp.rules || []);
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="8" class="text-danger">Failed to load: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    function renderRulesList(rules) {
        var tbody = document.getElementById('orListRows');
        if (!tbody) return;

        if (rules.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-body-secondary py-3 text-center">'
                + 'No cross-org routing rules yet. Use "New Rule" above to create one.</td></tr>';
            return;
        }

        var html = '';
        rules.forEach(function (r) {
            var tierBadge = r.access_tier === 'assist'
                ? '<span class="badge bg-warning text-dark">Assist</span>'
                : '<span class="badge bg-info text-dark">View</span>';
            var statusBadge = r.active
                ? '<span class="badge bg-success">Active</span>'
                : '<span class="badge bg-secondary">Inactive</span>';

            html += '<tr' + (r.active ? '' : ' class="opacity-75"') + '>';
            html += '<td>' + escHtml(r.owning_org_name) + '</td>';
            html += '<td class="text-body-tertiary"><i class="bi bi-arrow-right"></i></td>';
            html += '<td>' + escHtml(r.shared_with_org_name) + '</td>';
            html += '<td>' + escHtml(r.match_description) + '</td>';
            html += '<td>' + tierBadge + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td class="small text-body-secondary">' + escHtml(r.created_by_name || '') + '</td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-outline-primary me-1" data-edit-id="' + r.id + '" title="Edit tier"><i class="bi bi-pencil"></i></button>';
            if (r.active) {
                html += '<button class="btn btn-sm btn-outline-danger" data-deactivate-id="' + r.id + '" '
                    + 'data-desc="' + escAttr(r.owning_org_name + ' → ' + r.shared_with_org_name + ' (' + r.match_description + ')') + '" '
                    + 'title="Deactivate"><i class="bi bi-slash-circle"></i></button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    // ═══════════════════════════════════════════════════════════
    // Editor panel
    // ═══════════════════════════════════════════════════════════
    function openEditor(rule) {
        editingId = rule ? parseInt(rule.id, 10) : 0;

        document.getElementById('orId').value = editingId;

        var owningSel = document.getElementById('orOwningOrg');
        var targetSel = document.getElementById('orTargetOrg');
        var scopeGroupRadio = document.getElementById('orMatchScopeGroup');
        var scopeTypeRadio = document.getElementById('orMatchScopeType');
        var groupSel = document.getElementById('orMatchGroup');
        var typeSel = document.getElementById('orMatchType');
        var tierViewRadio = document.getElementById('orTierView');
        var tierAssistRadio = document.getElementById('orTierAssist');

        if (rule) {
            if (window.ORTR_CAN_AUTHOR_GLOBAL && owningSel) owningSel.value = String(rule.owning_org_id);
            populateTargetOrgSelect();
            if (targetSel) targetSel.value = String(rule.shared_with_org_id);

            if (rule.match_scope === 'type') {
                scopeTypeRadio.checked = true;
                if (typeSel) typeSel.value = String(rule.match_in_type_id);
            } else {
                scopeGroupRadio.checked = true;
                if (groupSel) groupSel.value = rule.match_group || '';
            }
            toggleMatchScopeInputs();

            if (rule.access_tier === 'assist') tierAssistRadio.checked = true;
            else tierViewRadio.checked = true;

            // Immutable once saved: org pair, match scope/target. Only the
            // tier radios stay editable -- server-enforced by
            // org_routing_rule_update(); disabled here too so the UI itself
            // communicates the rule plainly.
            if (owningSel) owningSel.disabled = true;
            if (targetSel) targetSel.disabled = true;
            scopeGroupRadio.disabled = true;
            scopeTypeRadio.disabled = true;
            if (groupSel) groupSel.disabled = true;
            if (typeSel) typeSel.disabled = true;
        } else {
            if (owningSel && window.ORTR_CAN_AUTHOR_GLOBAL) { owningSel.value = ''; owningSel.disabled = false; }
            if (targetSel) { targetSel.value = ''; targetSel.disabled = false; }
            populateTargetOrgSelect();
            scopeGroupRadio.checked = true;
            scopeGroupRadio.disabled = false;
            scopeTypeRadio.disabled = false;
            if (groupSel) { groupSel.value = ''; groupSel.disabled = false; }
            if (typeSel) { typeSel.value = ''; typeSel.disabled = false; }
            tierViewRadio.checked = true;
            toggleMatchScopeInputs();
        }

        var titleEl = document.getElementById('orEditorTitle');
        if (titleEl) {
            titleEl.innerHTML = rule
                ? '<i class="bi bi-pencil-square me-2"></i>Edit Rule (tier only)'
                : '<i class="bi bi-pencil-square me-2"></i>New Routing Rule';
        }

        hideSaveError();
        document.getElementById('orListPanel').classList.add('d-none');
        document.getElementById('orEditorPanel').classList.remove('d-none');
        document.getElementById('orEditorPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openEditorById(id) {
        fetch('api/org-routing.php?list=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                var rule = (resp.rules || []).filter(function (r) { return r.id === id; })[0];
                if (!rule) { showToast('danger', 'Rule not found.'); return; }
                openEditor(rule);
            })
            .catch(function (err) { showToast('danger', 'Failed to load rule: ' + err.message); });
    }

    function closeEditor() {
        document.getElementById('orEditorPanel').classList.add('d-none');
        document.getElementById('orListPanel').classList.remove('d-none');
        editingId = 0;
        loadRulesList();
    }

    function saveRule() {
        hideSaveError();

        var payload = {
            action: editingId > 0 ? 'update' : 'create',
            csrf_token: csrfToken,
            id: editingId > 0 ? editingId : undefined,
            access_tier: document.getElementById('orTierAssist').checked ? 'assist' : 'view'
        };

        if (editingId === 0) {
            var owningSel = document.getElementById('orOwningOrg');
            var targetSel = document.getElementById('orTargetOrg');
            var owningVal = owningSel ? owningSel.value : '';
            var targetVal = targetSel ? targetSel.value : '';

            if (window.ORTR_CAN_AUTHOR_GLOBAL && !owningVal) { showSaveError('Owning organization is required.'); return; }
            if (!targetVal) { showSaveError('Target organization is required.'); return; }
            if (owningVal && owningVal === targetVal) { showSaveError('A rule cannot route from an organization to itself.'); return; }

            payload.owning_org_id = owningVal ? parseInt(owningVal, 10) : null;
            payload.shared_with_org_id = parseInt(targetVal, 10);

            var isType = document.getElementById('orMatchScopeType').checked;
            payload.match_scope = isType ? 'type' : 'group';
            if (isType) {
                var typeVal = document.getElementById('orMatchType').value;
                if (!typeVal) { showSaveError('Select a specific incident type.'); return; }
                payload.match_in_type_id = parseInt(typeVal, 10);
            } else {
                var groupVal = document.getElementById('orMatchGroup').value;
                if (!groupVal) { showSaveError('Select an incident type group.'); return; }
                payload.match_group = groupVal;
            }
        }

        postJson(payload)
            .then(function (resp) {
                if (resp.error) { showSaveError(resp.error); return; }
                showToast('success', editingId > 0 ? 'Rule updated.' : 'Rule created.');
                closeEditor();
            })
            .catch(function (err) { showSaveError('Save failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Deactivate confirmation
    // ═══════════════════════════════════════════════════════════
    var pendingDeactivateId = 0;

    function openDeactivateConfirm(btn) {
        pendingDeactivateId = parseInt(btn.getAttribute('data-deactivate-id'), 10);
        var desc = btn.getAttribute('data-desc') || '';
        var descEl = document.getElementById('orDeactivateDesc');
        if (descEl) descEl.textContent = desc;
        var modalEl = document.getElementById('orDeactivateModal');
        if (modalEl && window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function confirmDeactivate() {
        if (!pendingDeactivateId) return;
        postJson({ action: 'deactivate', id: pendingDeactivateId, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                showToast('success', 'Rule deactivated. Already-shared tickets stay shared.');
                var modalEl = document.getElementById('orDeactivateModal');
                if (modalEl && window.bootstrap) {
                    var inst = bootstrap.Modal.getInstance(modalEl);
                    if (inst) inst.hide();
                }
                loadRulesList();
            })
            .catch(function (err) { showToast('danger', 'Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Utilities
    // ═══════════════════════════════════════════════════════════
    function postJson(payload) {
        return fetch('api/org-routing.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function showToast(type, message) {
        var area = document.getElementById('orToast');
        if (!area) return;
        area.className = 'alert alert-' + type;
        area.textContent = message;
        area.classList.remove('d-none');
        setTimeout(function () { area.classList.add('d-none'); }, 5000);
    }

    function showSaveError(message) {
        var el = document.getElementById('orSaveError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function hideSaveError() {
        var el = document.getElementById('orSaveError');
        if (el) el.classList.add('d-none');
    }

    function escHtml(str) {
        if (!str && str !== 0) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str && str !== 0) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
})();
