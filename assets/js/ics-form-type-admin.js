/**
 * NewUI v4.0 - Custom ICS Form Type Authoring (Phase 140, GH#69)
 *
 * List + create/edit/archive/restore for agency-authored ICS form type
 * definitions. Backend: api/ics-form-types.php.
 *
 * Field-list builder: add/remove/reorder via up/down buttons (NOT
 * drag-and-drop -- a deliberate scope cut so this ships without a drag
 * library). The whole editor re-renders from the in-memory `editorFields`
 * array on every mutation -- simpler and less error-prone than trying to
 * patch individual DOM nodes for every field-type's differing shape.
 */
(function () {
    'use strict';

    var csrfToken = '';
    var editorFields = [];   // in-memory field list while the editor is open
    var editingId = 0;       // 0 = creating a new type
    var editingSlug = null;  // the ORIGINAL slug, once loaded -- proves immutability client-side too
    var orgList = [];        // populated for a global author's Scope dropdown
    var slugManuallyEdited = false;  // once the author types directly into Slug, stop auto-deriving it from the title

    document.addEventListener('DOMContentLoaded', function () {
        csrfToken = document.getElementById('csrfToken') ? document.getElementById('csrfToken').value : '';
        initOrgScope();
        bindEvents();
        loadTypesList();
    });

    // ═══════════════════════════════════════════════════════════
    // Scope selector setup
    // ═══════════════════════════════════════════════════════════
    function initOrgScope() {
        var wrap = document.getElementById('ftOrgScopeWrap');
        var sel = document.getElementById('ftOrgScope');
        if (!sel) return;

        if (!window.ICSFT_CAN_AUTHOR_GLOBAL) {
            // Org-scoped-only author: server FORCES their own org regardless
            // of what this shows (ics_form_types_resolve_create_org()) -- this
            // is display only, locked to communicate that plainly.
            sel.innerHTML = '<option value="">(your organization)</option>';
            sel.disabled = true;
            return;
        }

        sel.innerHTML = '<option value="">Install-wide (every organization)</option>';
        fetch('api/organizations.php?action=list')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                orgList = resp.organizations || [];
                orgList.forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = String(o.id);
                    opt.textContent = o.name;
                    sel.appendChild(opt);
                });
            })
            .catch(function () { /* install-wide-only remains selectable */ });
    }

    // ═══════════════════════════════════════════════════════════
    // Events
    // ═══════════════════════════════════════════════════════════
    function bindEvents() {
        var btnNew = document.getElementById('ftBtnNew');
        if (btnNew) btnNew.addEventListener('click', function () { openEditor(null); });

        var btnCancel = document.getElementById('ftBtnCancel');
        if (btnCancel) btnCancel.addEventListener('click', closeEditor);
        var btnCancel2 = document.getElementById('ftBtnCancel2');
        if (btnCancel2) btnCancel2.addEventListener('click', closeEditor);

        var btnSave = document.getElementById('ftBtnSave');
        if (btnSave) btnSave.addEventListener('click', saveType);

        // Slug auto-derives from the Form Title while creating a NEW type,
        // exactly like every other "permanent identifier from a display
        // name" field in this app -- but only until the author types into
        // the slug box directly, at which point their choice wins for the
        // rest of this editing session. Irrelevant once editing an existing
        // type (the slug input is disabled there -- see openEditor()).
        var titleInput = document.getElementById('ftFormTitle');
        var slugInput = document.getElementById('ftSlug');
        if (titleInput && slugInput) {
            titleInput.addEventListener('input', function () {
                if (editingId > 0 || slugManuallyEdited) return;
                slugInput.value = slugifyTitle(titleInput.value);
            });
            slugInput.addEventListener('input', function () {
                slugManuallyEdited = true;
            });
        }

        // "Add Field" dropdown -- delegated, the menu items are static.
        document.querySelectorAll('[data-add-field]').forEach(function (item) {
            item.addEventListener('click', function (ev) {
                ev.preventDefault();
                addField(this.getAttribute('data-add-field'));
            });
        });

        // List actions (edit/archive/restore) -- delegated on the table body
        // since rows are re-rendered wholesale on every list refresh.
        var listRows = document.getElementById('ftListRows');
        if (listRows) {
            listRows.addEventListener('click', function (ev) {
                var editBtn = ev.target.closest('[data-edit-id]');
                if (editBtn) { openEditorById(parseInt(editBtn.getAttribute('data-edit-id'), 10)); return; }
                var archBtn = ev.target.closest('[data-archive-id]');
                if (archBtn) { setArchiveStatus(parseInt(archBtn.getAttribute('data-archive-id'), 10), 'archive'); return; }
                var restBtn = ev.target.closest('[data-restore-id]');
                if (restBtn) { setArchiveStatus(parseInt(restBtn.getAttribute('data-restore-id'), 10), 'restore'); return; }
            });
        }

        // Field-editor row actions (move up/down/remove, type change) --
        // delegated on the fields container for the same reason.
        var fieldsList = document.getElementById('ftFieldsList');
        if (fieldsList) {
            fieldsList.addEventListener('click', function (ev) {
                var up = ev.target.closest('[data-field-up]');
                if (up) { moveField(parseInt(up.getAttribute('data-field-up'), 10), -1); return; }
                var down = ev.target.closest('[data-field-down]');
                if (down) { moveField(parseInt(down.getAttribute('data-field-down'), 10), 1); return; }
                var rm = ev.target.closest('[data-field-remove]');
                if (rm) { removeField(parseInt(rm.getAttribute('data-field-remove'), 10)); return; }
                var colAdd = ev.target.closest('[data-col-add]');
                if (colAdd) { addTableColumn(parseInt(colAdd.getAttribute('data-col-add'), 10)); return; }
                var colRm = ev.target.closest('[data-col-remove]');
                if (colRm) {
                    removeTableColumn(
                        parseInt(colRm.getAttribute('data-col-remove'), 10),
                        parseInt(colRm.getAttribute('data-col-idx'), 10)
                    );
                }
            });
            fieldsList.addEventListener('change', handleFieldsListChange);
            fieldsList.addEventListener('input', handleFieldsListChange);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // List panel
    // ═══════════════════════════════════════════════════════════
    function loadTypesList() {
        var tbody = document.getElementById('ftListRows');
        if (!tbody) return;

        fetch('api/ics-form-types.php?list=1&manage=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-danger">' + escHtml(resp.error) + '</td></tr>';
                    return;
                }
                renderTypesList(resp.types || []);
            })
            .catch(function (err) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-danger">Failed to load: ' + escHtml(err.message) + '</td></tr>';
            });
    }

    function renderTypesList(types) {
        var tbody = document.getElementById('ftListRows');
        if (!tbody) return;

        if (types.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-body-secondary py-3 text-center">'
                + 'No custom form types yet. Use "New Type" above to create one.</td></tr>';
            return;
        }

        var allowedColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];
        var html = '';
        types.forEach(function (t) {
            var color = allowedColors.indexOf(t.badge_color) !== -1 ? t.badge_color : 'secondary';
            var icon = /^bi-[a-z0-9-]+$/.test(t.icon || '') ? t.icon : 'bi-file-earmark-text';
            var scope = t.org_id ? (t.org_name || ('Org #' + parseInt(t.org_id, 10))) : 'Install-wide';
            var statusBadge = t.status === 'archived'
                ? '<span class="badge bg-secondary">Archived</span>'
                : '<span class="badge bg-success">Active</span>';

            html += '<tr>';
            html += '<td><span class="badge bg-' + color + '"><i class="bi ' + icon + '"></i></span></td>';
            html += '<td><div class="fw-semibold">' + escHtml(t.form_number || '(no number)') + '</div>'
                + '<div class="small text-body-secondary">' + escHtml(t.form_title || '(untitled)') + '</div></td>';
            html += '<td>' + escHtml(scope) + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td class="text-end">' + parseInt(t.instance_count || 0, 10) + '</td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-outline-primary me-1" data-edit-id="' + t.id + '" title="Edit"><i class="bi bi-pencil"></i></button>';
            if (t.status === 'archived') {
                html += '<button class="btn btn-sm btn-outline-success" data-restore-id="' + t.id + '" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>';
            } else {
                html += '<button class="btn btn-sm btn-outline-secondary" data-archive-id="' + t.id + '" title="Archive"><i class="bi bi-archive"></i></button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    function setArchiveStatus(id, action) {
        if (!id) return;
        postJson({ action: action, id: id, csrf_token: csrfToken })
            .then(function (resp) {
                if (resp.error) { showToast('danger', resp.error); return; }
                showToast('success', action === 'archive' ? 'Type archived.' : 'Type restored.');
                loadTypesList();
            })
            .catch(function (err) { showToast('danger', 'Failed: ' + err.message); });
    }

    // ═══════════════════════════════════════════════════════════
    // Editor panel — metadata
    // ═══════════════════════════════════════════════════════════
    function openEditor(type) {
        // type comes from ics_form_custom_template() (GET ?id=X), whose
        // shape names this key custom_type_id, not id -- matching how
        // assets/js/ics-forms.js's openFormEditor() already reads the same
        // function's output for the SAVE path. Do not "fix" this to .id;
        // that was the actual bug (confirmed live: saving after Edit sent
        // action=create instead of update because editingId came out NaN).
        editingId = type ? parseInt(type.custom_type_id, 10) : 0;
        editingSlug = type ? type.slug : null;
        editorFields = type && Array.isArray(type.fields) ? JSON.parse(JSON.stringify(type.fields)) : [];
        slugManuallyEdited = false;  // fresh editor session -- a NEW type auto-derives again from scratch

        document.getElementById('ftId').value = editingId;
        setVal('ftSlug', type ? type.slug : '');
        document.getElementById('ftSlug').disabled = !!type; // immutable after creation
        setVal('ftFormNumber', type ? type.form_number : '');
        setVal('ftFormTitle', type ? type.form_title : '');
        setVal('ftDescription', type ? type.description : '');
        setVal('ftIcon', type ? type.icon : 'bi-file-earmark-text');
        setVal('ftBadgeColor', type ? type.badge_color : 'secondary');
        setVal('ftRestrictTo', type && type.restrict_to_permission ? type.restrict_to_permission : '');

        var scopeSel = document.getElementById('ftOrgScope');
        if (scopeSel && window.ICSFT_CAN_AUTHOR_GLOBAL) {
            scopeSel.value = type && type.org_id ? String(type.org_id) : '';
        }

        var titleEl = document.getElementById('ftEditorTitle');
        if (titleEl) {
            titleEl.innerHTML = type
                ? '<i class="bi bi-pencil-square me-2"></i>Edit: ' + escHtml(type.form_title || type.slug)
                : '<i class="bi bi-pencil-square me-2"></i>New Form Type';
        }

        hideSaveError();
        renderFieldsEditor();

        document.getElementById('ftListPanel').classList.add('d-none');
        document.getElementById('ftEditorPanel').classList.remove('d-none');
        document.getElementById('ftEditorPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function openEditorById(id) {
        fetch('api/ics-form-types.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error || !resp.type) { showToast('danger', resp.error || 'Type not found.'); return; }
                openEditor(resp.type);
            })
            .catch(function (err) { showToast('danger', 'Failed to load type: ' + err.message); });
    }

    function closeEditor() {
        document.getElementById('ftEditorPanel').classList.add('d-none');
        document.getElementById('ftListPanel').classList.remove('d-none');
        editorFields = [];
        editingId = 0;
        editingSlug = null;
        loadTypesList();
    }

    function saveType() {
        hideSaveError();

        var slug = (document.getElementById('ftSlug').value || '').trim();
        var formTitle = (document.getElementById('ftFormTitle').value || '').trim();
        if (!slug) { showSaveError('Slug is required.'); return; }
        if (!formTitle) { showSaveError('Form title is required.'); return; }
        if (editorFields.length === 0) { showSaveError('At least one field is required.'); return; }

        var payload = {
            action: editingId > 0 ? 'update' : 'create',
            csrf_token: csrfToken,
            slug: slug,
            form_number: document.getElementById('ftFormNumber').value || '',
            form_title: formTitle,
            description: document.getElementById('ftDescription').value || '',
            icon: document.getElementById('ftIcon').value || 'bi-file-earmark-text',
            badge_color: document.getElementById('ftBadgeColor').value || 'secondary',
            restrict_to_permission: (document.getElementById('ftRestrictTo').value || '').trim() || null,
            fields: cleanFieldsForSave(editorFields)
        };
        if (editingId > 0) payload.id = editingId;

        var scopeSel = document.getElementById('ftOrgScope');
        if (scopeSel && window.ICSFT_CAN_AUTHOR_GLOBAL && editingId === 0) {
            // Only meaningful on CREATE -- the server ignores org_id on update
            // (a type's org never changes after creation).
            payload.org_id = scopeSel.value ? parseInt(scopeSel.value, 10) : null;
        }

        postJson(payload)
            .then(function (resp) {
                if (resp.error) { showSaveError(resp.error); return; }
                showToast('success', editingId > 0 ? 'Type updated.' : 'Type created.');
                closeEditor();
            })
            .catch(function (err) { showSaveError('Save failed: ' + err.message); });
    }

    /** Strip empty option/column rows the builder tolerates mid-edit but the server should never see. */
    function cleanFieldsForSave(fields) {
        return fields.map(function (f) {
            var out = {};
            for (var k in f) if (Object.prototype.hasOwnProperty.call(f, k)) out[k] = f[k];
            if (out.options) out.options = out.options.filter(function (o) { return String(o).trim() !== ''; });
            if (out.columns) {
                out.columns = out.columns.map(function (c) {
                    var co = {};
                    for (var ck in c) if (Object.prototype.hasOwnProperty.call(c, ck)) co[ck] = c[ck];
                    if (co.options) co.options = co.options.filter(function (o) { return String(o).trim() !== ''; });
                    return co;
                });
            }
            return out;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Field-list builder
    // ═══════════════════════════════════════════════════════════
    var FIELD_TYPE_LABELS = {
        text: 'Text', textarea: 'Text area', number: 'Number', date: 'Date', time: 'Time',
        'datetime-local': 'Date & time', select: 'Select', checkbox: 'Checkbox',
        section_header: 'Section header', table: 'Table'
    };
    var TABLE_COLUMN_TYPES = ['text', 'number', 'date', 'time', 'select'];

    /**
     * Lowercase, hyphen-separated slug from a display title -- matching the
     * server's own acceptance pattern (inc/ics-form-types.php
     * ics_form_type_validate_metadata(): ^[a-z][a-z0-9_-]{2,59}$, 3-60
     * chars total). A title that starts with a digit or symbol after
     * stripping gets an 'f-' prefix so the first character is always a
     * letter, same as suggestKey() does for a field key.
     */
    function slugifyTitle(title) {
        var s = String(title || '').toLowerCase().trim()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
        if (!s) return '';
        if (!/^[a-z]/.test(s)) s = 'f-' + s;
        if (s.length > 60) s = s.substring(0, 60).replace(/-+$/, '');
        return s;
    }

    function suggestKey(label, existingKeys) {
        var base = String(label || 'field').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
        if (!base || !/^[a-z]/.test(base)) base = 'field_' + base;
        var key = base;
        var n = 2;
        while (existingKeys.indexOf(key) !== -1) { key = base + '_' + n; n++; }
        return key;
    }

    function addField(type) {
        var existingKeys = editorFields.map(function (f) { return f.key; });
        var field = {
            key: suggestKey(type === 'section_header' ? 'section' : type, existingKeys),
            label: type === 'section_header' ? 'Section' : (FIELD_TYPE_LABELS[type] || 'Field'),
            type: type,
            required: false
        };
        if (type === 'select') field.options = ['Option 1', 'Option 2'];
        if (type === 'textarea') field.rows = 4;
        if (type === 'table') {
            field.columns = [{ key: 'col1', label: 'Column 1', type: 'text' }];
            field.default_rows = 1;
            field.max_rows = 50;
        }
        editorFields.push(field);
        renderFieldsEditor();
    }

    function removeField(idx) {
        if (idx < 0 || idx >= editorFields.length) return;
        editorFields.splice(idx, 1);
        renderFieldsEditor();
    }

    function moveField(idx, delta) {
        var newIdx = idx + delta;
        if (idx < 0 || idx >= editorFields.length || newIdx < 0 || newIdx >= editorFields.length) return;
        var tmp = editorFields[idx];
        editorFields[idx] = editorFields[newIdx];
        editorFields[newIdx] = tmp;
        renderFieldsEditor();
    }

    function addTableColumn(fieldIdx) {
        var f = editorFields[fieldIdx];
        if (!f || f.type !== 'table') return;
        if (!Array.isArray(f.columns)) f.columns = [];
        var n = f.columns.length + 1;
        f.columns.push({ key: 'col' + n, label: 'Column ' + n, type: 'text' });
        renderFieldsEditor();
    }

    function removeTableColumn(fieldIdx, colIdx) {
        var f = editorFields[fieldIdx];
        if (!f || !Array.isArray(f.columns)) return;
        f.columns.splice(colIdx, 1);
        renderFieldsEditor();
    }

    /**
     * Reads every input currently in the DOM back into editorFields BEFORE
     * a structural re-render (add/remove/reorder) would otherwise discard
     * in-progress typing in unrelated fields. Bound to both 'input' and
     * 'change' so it also fires for pure value edits that don't trigger a
     * re-render at all (the common case while typing a label).
     */
    function handleFieldsListChange(ev) {
        var el = ev.target;
        var fIdx = el.getAttribute('data-field-idx');
        if (fIdx === null) return;
        fIdx = parseInt(fIdx, 10);
        var f = editorFields[fIdx];
        if (!f) return;

        var prop = el.getAttribute('data-field-prop');
        var colIdx = el.getAttribute('data-col-idx');
        var colProp = el.getAttribute('data-col-prop');

        if (colProp !== null && colIdx !== null) {
            colIdx = parseInt(colIdx, 10);
            if (!Array.isArray(f.columns) || !f.columns[colIdx]) return;
            f.columns[colIdx][colProp] = readInputValue(el);
            return;
        }
        if (prop === 'options') {
            f.options = (el.value || '').split('\n');
            return;
        }
        if (prop !== null) {
            f[prop] = readInputValue(el);
        }
    }

    function readInputValue(el) {
        if (el.type === 'checkbox') return el.checked;
        if (el.type === 'number') return el.value === '' ? '' : Number(el.value);
        return el.value;
    }

    function renderFieldsEditor() {
        var container = document.getElementById('ftFieldsList');
        var empty = document.getElementById('ftFieldsEmpty');
        if (!container) return;

        if (editorFields.length === 0) {
            container.innerHTML = '';
            if (empty) empty.classList.remove('d-none');
            return;
        }
        if (empty) empty.classList.add('d-none');

        var html = '';
        editorFields.forEach(function (f, idx) {
            html += renderFieldRow(f, idx);
        });
        container.innerHTML = html;
    }

    function renderFieldRow(f, idx) {
        var typeLabel = FIELD_TYPE_LABELS[f.type] || f.type;
        var h = '<div class="card mb-2"><div class="card-body py-2">';
        h += '<div class="d-flex align-items-start gap-2 flex-wrap">';

        h += '<div class="btn-group-vertical btn-group-sm">';
        h += '<button type="button" class="btn btn-outline-secondary" data-field-up="' + idx + '" title="Move up"><i class="bi bi-chevron-up"></i></button>';
        h += '<button type="button" class="btn btn-outline-secondary" data-field-down="' + idx + '" title="Move down"><i class="bi bi-chevron-down"></i></button>';
        h += '</div>';

        h += '<span class="badge bg-secondary mt-1">' + escHtml(typeLabel) + '</span>';

        if (f.type === 'section_header') {
            h += '<div class="flex-grow-1">';
            h += '<label class="form-label form-label-sm mb-0">Section label</label>';
            h += '<input type="text" class="form-control form-control-sm" value="' + escAttr(f.label) + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="label">';
            h += '</div>';
        } else {
            h += '<div class="flex-grow-1"><div class="row g-2">';
            h += '<div class="col-md-3"><label class="form-label form-label-sm mb-0">Key</label>'
                + '<input type="text" class="form-control form-control-sm" value="' + escAttr(f.key) + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="key" pattern="[a-z][a-z0-9_]{0,63}"></div>';
            h += '<div class="col-md-4"><label class="form-label form-label-sm mb-0">Label</label>'
                + '<input type="text" class="form-control form-control-sm" value="' + escAttr(f.label) + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="label"></div>';
            if (f.type !== 'checkbox') {
                h += '<div class="col-md-5 d-flex align-items-end">';
                h += '<div class="form-check">';
                h += '<input type="checkbox" class="form-check-input" id="ftReq' + idx + '"'
                    + (f.required ? ' checked' : '') + ' data-field-idx="' + idx + '" data-field-prop="required">';
                h += '<label class="form-check-label" for="ftReq' + idx + '">Required</label>';
                h += '</div></div>';
            } else {
                h += '<div class="col-md-5"></div>';
            }
            h += renderFieldTypeExtras(f, idx);
            h += '</div></div>';
        }

        h += '<button type="button" class="btn btn-sm btn-outline-danger mt-1" data-field-remove="' + idx + '" title="Remove field"><i class="bi bi-trash"></i></button>';
        h += '</div></div></div>';
        return h;
    }

    function renderFieldTypeExtras(f, idx) {
        if (f.type === 'select') {
            var opts = Array.isArray(f.options) ? f.options.join('\n') : '';
            return '<div class="col-12"><label class="form-label form-label-sm mb-0">Options (one per line)</label>'
                + '<textarea class="form-control form-control-sm" rows="3"'
                + ' data-field-idx="' + idx + '" data-field-prop="options">' + escHtml(opts) + '</textarea></div>';
        }
        if (f.type === 'number') {
            return '<div class="col-4"><label class="form-label form-label-sm mb-0">Min</label>'
                + '<input type="number" class="form-control form-control-sm" value="' + escAttr(f.min !== undefined ? f.min : '') + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="min"></div>'
                + '<div class="col-4"><label class="form-label form-label-sm mb-0">Max</label>'
                + '<input type="number" class="form-control form-control-sm" value="' + escAttr(f.max !== undefined ? f.max : '') + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="max"></div>'
                + '<div class="col-4"><label class="form-label form-label-sm mb-0">Step</label>'
                + '<input type="number" class="form-control form-control-sm" value="' + escAttr(f.step !== undefined ? f.step : '') + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="step"></div>';
        }
        if (f.type === 'textarea') {
            return '<div class="col-4"><label class="form-label form-label-sm mb-0">Rows</label>'
                + '<input type="number" min="1" max="30" class="form-control form-control-sm" value="' + escAttr(f.rows || 4) + '"'
                + ' data-field-idx="' + idx + '" data-field-prop="rows"></div>';
        }
        if (f.type === 'table') {
            return renderTableFieldExtras(f, idx);
        }
        return '';
    }

    function renderTableFieldExtras(f, idx) {
        var h = '<div class="col-12">';
        h += '<div class="row g-2 mb-2">';
        h += '<div class="col-4"><label class="form-label form-label-sm mb-0">Default rows</label>'
            + '<input type="number" min="1" max="5" class="form-control form-control-sm" value="' + escAttr(f.default_rows || 1) + '"'
            + ' data-field-idx="' + idx + '" data-field-prop="default_rows"></div>';
        h += '<div class="col-4"><label class="form-label form-label-sm mb-0">Max rows (DoS cap, &le;200)</label>'
            + '<input type="number" min="1" max="200" class="form-control form-control-sm" value="' + escAttr(f.max_rows || 50) + '"'
            + ' data-field-idx="' + idx + '" data-field-prop="max_rows"></div>';
        h += '</div>';

        h += '<label class="form-label form-label-sm mb-0">Columns</label>';
        var columns = Array.isArray(f.columns) ? f.columns : [];
        columns.forEach(function (col, colIdx) {
            h += '<div class="row g-2 align-items-end mb-1">';
            h += '<div class="col-3"><input type="text" class="form-control form-control-sm" placeholder="key"'
                + ' value="' + escAttr(col.key) + '" data-field-idx="' + idx + '" data-col-idx="' + colIdx + '" data-col-prop="key"></div>';
            h += '<div class="col-4"><input type="text" class="form-control form-control-sm" placeholder="Label"'
                + ' value="' + escAttr(col.label) + '" data-field-idx="' + idx + '" data-col-idx="' + colIdx + '" data-col-prop="label"></div>';
            h += '<div class="col-3"><select class="form-select form-select-sm"'
                + ' data-field-idx="' + idx + '" data-col-idx="' + colIdx + '" data-col-prop="type">';
            TABLE_COLUMN_TYPES.forEach(function (ct) {
                h += '<option value="' + ct + '"' + (col.type === ct ? ' selected' : '') + '>' + FIELD_TYPE_LABELS[ct] + '</option>';
            });
            h += '</select></div>';
            h += '<div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger" data-col-remove="' + idx + '" data-col-idx="' + colIdx + '"><i class="bi bi-trash"></i></button></div>';
            if (col.type === 'select') {
                var colOpts = Array.isArray(col.options) ? col.options.join('\n') : '';
                h += '<div class="col-12"><textarea class="form-control form-control-sm" rows="2" placeholder="Options, one per line"'
                    + ' data-field-idx="' + idx + '" data-col-idx="' + colIdx + '" data-col-prop="options">' + escHtml(colOpts) + '</textarea></div>';
            }
            h += '</div>';
        });
        h += '<button type="button" class="btn btn-sm btn-outline-primary mt-1" data-col-add="' + idx + '"><i class="bi bi-plus-lg me-1"></i>Add Column</button>';
        h += '</div>';
        return h;
    }

    // ═══════════════════════════════════════════════════════════
    // Utilities
    // ═══════════════════════════════════════════════════════════
    function postJson(payload) {
        return fetch('api/ics-form-types.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }

    function setVal(id, val) {
        var el = document.getElementById(id);
        if (el) el.value = val || '';
    }

    function showToast(type, message) {
        var area = document.getElementById('ftToast');
        if (!area) return;
        area.className = 'alert alert-' + type;
        area.textContent = message;
        area.classList.remove('d-none');
        setTimeout(function () { area.classList.add('d-none'); }, 5000);
    }

    function showSaveError(message) {
        var el = document.getElementById('ftSaveError');
        if (!el) return;
        el.textContent = message;
        el.classList.remove('d-none');
    }

    function hideSaveError() {
        var el = document.getElementById('ftSaveError');
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
