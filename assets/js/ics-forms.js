/**
 * NewUI v4.0 - ICS Forms Page Logic
 *
 * Handles: form hub display, dynamic form rendering from field definitions,
 * save/load, print, XML export, and incident auto-population.
 */

(function () {
    'use strict';

    // ── State ──
    var currentTemplate = null;   // Current form template (from API)
    var currentFormId   = 0;      // ID of loaded form (0 = new)
    var currentForm     = null;   // Row of the loaded saved form (for can_delete)
    var csrfToken       = '';     // CSRF token from page
    var incidentData    = null;   // Linked incident data (if any)
    var currentCustomTypeId = 0;  // Phase 140: which ics_form_types row, when form_type === 'custom'

    // ── Init ──
    document.addEventListener('DOMContentLoaded', function () {
        csrfToken = document.getElementById('csrfToken')
            ? document.getElementById('csrfToken').value : '';
        initTheme();
        loadFormsList();
        bindHubEvents();
        loadCustomTypeCards();
    });

    // ═══════════════════════════════════════════════════════════
    // Theme toggle (matches other pages)
    // ═══════════════════════════════════════════════════════════
    function initTheme() {
        var btns = document.querySelectorAll('#themeToggle button');
        btns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var theme = this.dataset.theme;
                document.documentElement.setAttribute('data-bs-theme',
                    theme === 'Night' ? 'dark' : 'light');
                btns.forEach(function (b) {
                    b.className = 'btn ' + (b.dataset.theme === theme
                        ? (theme === 'Day' ? 'btn-warning' : 'btn-primary')
                        : 'btn-outline-secondary');
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Hub — list saved forms and show new-form cards
    // ═══════════════════════════════════════════════════════════
    function bindHubEvents() {
        // "New" buttons on form type cards -- a DELEGATED listener on
        // #hubSection (Phase 140), not per-button bindings, so the custom-
        // type cards loadCustomTypeCards() appends AFTER this runs work with
        // no separate re-binding step. A custom-type card additionally
        // carries data-custom-type-id, threaded through to the template
        // fetch and (via currentCustomTypeId) into saveForm()'s payload.
        var hub = document.getElementById('hubSection');
        if (hub) {
            hub.addEventListener('click', function (ev) {
                var btn = ev.target.closest('[data-new-form]');
                if (!btn || !hub.contains(btn)) return;
                var type = btn.getAttribute('data-new-form');
                var customTypeId = btn.getAttribute('data-custom-type-id');
                openFormEditor(type, 0, null, null, '', 'draft', customTypeId ? parseInt(customTypeId, 10) : 0);
            });
        }

        // Back button from editor to hub
        var backBtn = document.getElementById('btnBackToHub');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                showHub();
            });
        }

        // Save button
        var saveBtn = document.getElementById('btnSave');
        if (saveBtn) {
            saveBtn.addEventListener('click', function () {
                saveForm('draft');
            });
        }

        // Finalize button
        var finalBtn = document.getElementById('btnFinalize');
        if (finalBtn) {
            finalBtn.addEventListener('click', function () {
                saveForm('final');
            });
        }

        // Print button
        var printBtn = document.getElementById('btnPrint');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                printForm();
            });
        }

        // Export XML button (ICS-213 only)
        var xmlBtn = document.getElementById('btnExportXml');
        if (xmlBtn) {
            xmlBtn.addEventListener('click', function () {
                exportXml();
            });
        }

        // Delete button (saved forms this user may delete). On success we go
        // back to the hub — the form we were editing no longer exists there.
        var delBtn = document.getElementById('btnDeleteForm');
        if (delBtn) {
            delBtn.addEventListener('click', function () {
                if (!currentFormId || !currentForm) return;
                deleteSavedForm(currentFormId, describeForm(currentForm), showHub);
            });
        }

        // Incident search typeahead
        var searchInput = document.getElementById('linkIncidentSearch');
        var hiddenId = document.getElementById('linkIncidentId');
        var resultsDiv = document.getElementById('incidentSearchResults');
        var clearBtn = document.getElementById('btnClearIncidentLink');
        var searchTimer = null;
        var searchResults = [];
        var activeIdx = -1;

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var query = searchInput.value.trim();
                if (query.length < 1) {
                    resultsDiv.classList.add('d-none');
                    resultsDiv.innerHTML = '';
                    return;
                }
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    fetch('api/incidents.php?search=' + encodeURIComponent(query) + '&limit=10&sort=updated&dir=desc', { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            searchResults = data.incidents || data || [];
                            if (!Array.isArray(searchResults)) searchResults = [];
                            activeIdx = -1;
                            renderSearchResults();
                        })
                        .catch(function () {
                            resultsDiv.classList.add('d-none');
                        });
                }, 250);
            });

            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (activeIdx < searchResults.length - 1) activeIdx++;
                    highlightResult();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (activeIdx > 0) activeIdx--;
                    highlightResult();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (activeIdx >= 0 && activeIdx < searchResults.length) {
                        selectIncident(searchResults[activeIdx]);
                    }
                } else if (e.key === 'Escape') {
                    resultsDiv.classList.add('d-none');
                }
            });

            searchInput.addEventListener('blur', function () {
                setTimeout(function () { resultsDiv.classList.add('d-none'); }, 200);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                hiddenId.value = '';
                searchInput.value = '';
                searchInput.placeholder = 'Search by #, address, type, or description...';
                clearBtn.style.display = 'none';
                incidentData = null;
            });
        }

        function renderSearchResults() {
            if (searchResults.length === 0) {
                resultsDiv.innerHTML = '<div class="incident-search-item text-body-secondary"><em>No incidents found</em></div>';
                resultsDiv.classList.remove('d-none');
                return;
            }
            var html = '';
            for (var i = 0; i < searchResults.length; i++) {
                var inc = searchResults[i];
                var id = inc.id || inc.ticket_id || '?';
                var type = inc.type_name || inc.in_types_id || '';
                var addr = inc.street || inc.location || '';
                var scope = inc.scope || inc.description || '';
                var updated = inc.date || inc.updated_at || '';
                if (updated && updated.length > 16) updated = updated.substring(5, 16);
                html += '<div class="incident-search-item' + (i === activeIdx ? ' active' : '') + '" data-idx="' + i + '">';
                html += '<span class="item-id">#' + escHtml(String(id)) + '</span>';
                if (type) html += '<span class="item-type">' + escHtml(type) + '</span>';
                if (updated) html += '<span class="item-date">' + escHtml(updated) + '</span>';
                if (addr) html += '<span class="item-addr">' + escHtml(addr) + '</span>';
                if (scope && scope !== addr) html += ' <small class="text-body-secondary">' + escHtml(scope) + '</small>';
                html += '</div>';
            }
            resultsDiv.innerHTML = html;
            resultsDiv.classList.remove('d-none');

            var items = resultsDiv.querySelectorAll('.incident-search-item');
            for (var j = 0; j < items.length; j++) {
                items[j].addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    var idx = parseInt(this.getAttribute('data-idx'), 10);
                    if (idx >= 0 && idx < searchResults.length) {
                        selectIncident(searchResults[idx]);
                    }
                });
            }
        }

        function highlightResult() {
            var items = resultsDiv.querySelectorAll('.incident-search-item');
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('active', i === activeIdx);
            }
            if (activeIdx >= 0 && items[activeIdx]) {
                items[activeIdx].scrollIntoView({ block: 'nearest' });
            }
        }

        function selectIncident(inc) {
            var id = inc.id || inc.ticket_id;
            hiddenId.value = id;
            var label = '#' + id;
            if (inc.type_name) label += ' ' + inc.type_name;
            if (inc.street || inc.location) label += ' — ' + (inc.street || inc.location);
            searchInput.value = label;
            resultsDiv.classList.add('d-none');
            if (clearBtn) clearBtn.style.display = '';
            loadIncidentData(parseInt(id, 10));
        }

        // Filter form type on hub
        var filterSel = document.getElementById('hubFilterType');
        if (filterSel) {
            filterSel.addEventListener('change', function () {
                loadFormsList();
            });
        }
    }

    /**
     * Phase 140 — append one "New" card per active, in-scope custom ICS
     * form type after the 9 static built-in cards. Fails silently to an
     * empty row (not an error banner) on an unmigrated/feature-off install,
     * since api/ics-form-types.php answers 503 there -- this call is best-
     * effort décor, not something a dispatcher's day depends on.
     */
    function loadCustomTypeCards() {
        var row = document.getElementById('formTypeCardsRow');
        if (!row) return;

        fetch('api/ics-form-types.php?list=1')
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                var types = resp.types || [];
                var html = '';
                types.forEach(function (t) {
                    html += renderCustomTypeCard(t);
                });
                if (html) row.insertAdjacentHTML('beforeend', html);
            })
            .catch(function () {
                // Feature unavailable or request failed -- the 9 built-in
                // cards are still fully usable, so this is a quiet no-op.
            });
    }

    /**
     * One hub card for a custom type, matching the 9 built-in cards'
     * markup (col-md-6 col-lg-4 col-xl-3 > card.ics-type-card). Every
     * field here is agency-authored -- form_number/form_title/description
     * all go through escHtml(), and badge_color is checked against the
     * same 7-value whitelist getFormTypeBadge() uses, never trusted as a
     * bare class-attribute fragment.
     */
    function renderCustomTypeCard(t) {
        var allowedColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];
        var color = allowedColors.indexOf(t.badge_color) !== -1 ? t.badge_color : 'secondary';
        var icon = /^bi-[a-z0-9-]+$/.test(t.icon || '') ? t.icon : 'bi-file-earmark-text';
        var numberOrTitle = escHtml(t.form_number || t.form_title || 'Custom');

        var h = '<div class="col-md-6 col-lg-4 col-xl-3">';
        h += '<div class="card ics-type-card h-100">';
        h += '<div class="card-body">';
        h += '<div class="d-flex align-items-center justify-content-between mb-2">';
        h += '<span class="form-number text-' + color + '"><i class="bi ' + icon + ' me-1"></i>' + numberOrTitle + '</span>';
        h += '<span class="badge bg-' + color + '">Custom</span>';
        h += '</div>';
        h += '<h6 class="card-title mb-1">' + escHtml(t.form_title || 'Untitled Type') + '</h6>';
        h += '<p class="form-desc text-body-secondary mb-2">' + escHtml(t.description || '') + '</p>';
        h += '<button class="btn btn-sm btn-' + color + '" data-new-form="custom" data-custom-type-id="' + escAttr(t.id) + '">';
        h += '<i class="bi bi-plus-lg me-1"></i>New</button>';
        h += '</div></div></div>';
        return h;
    }

    function loadFormsList() {
        var filterSel = document.getElementById('hubFilterType');
        var typeParam = filterSel ? filterSel.value : '';
        var url = 'api/ics-forms.php?limit=50';
        if (typeParam) url += '&form_type=' + encodeURIComponent(typeParam);

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                renderSavedForms(resp.forms || []);
            })
            .catch(function () {
                renderSavedForms([]);
            });
    }

    function renderSavedForms(forms) {
        var tbody = document.getElementById('savedFormsBody');
        if (!tbody) return;

        if (forms.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-body-secondary py-3">'
                + 'No saved forms yet. Use the cards above to create a new form.</td></tr>';
            return;
        }

        var html = '';
        forms.forEach(function (f) {
            var typeBadge = getFormTypeBadge(f);
            var statusBadge = getStatusBadge(f.status);
            var incLink = f.incident_id
                ? '<a href="incident-detail.php?id=' + f.incident_id + '">#' + f.incident_id + '</a>'
                : '<span class="text-body-secondary">--</span>';
            html += '<tr class="saved-form-row" data-form-id="' + f.id + '" style="cursor:pointer">';
            html += '<td>' + typeBadge + '</td>';
            html += '<td>' + escHtml(f.title || '(untitled)') + '</td>';
            html += '<td>' + incLink + '</td>';
            html += '<td>' + statusBadge + '</td>';
            html += '<td>' + escHtml(f.created_by_name) + '</td>';
            html += '<td>' + formatDate(f.updated_at) + '</td>';
            // `can_delete` is computed per-row by api/ics-forms.php with the
            // same function the delete action re-checks with, so the button
            // appears exactly where it will work. Hiding it is a courtesy,
            // not the gate.
            html += '<td class="text-end">';
            if (f.can_delete) {
                html += '<button type="button" class="btn btn-sm btn-outline-danger ics-delete-btn"'
                     + ' data-form-id="' + f.id + '"'
                     // escAttr, not escHtml: escHtml leaves quotes alone, and
                     // describeForm() wraps the user-supplied title in them.
                     + ' data-form-label="' + escAttr(describeForm(f)) + '"'
                     + ' title="Delete this form"><i class="bi bi-trash"></i></button>';
            }
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;

        // Click to open
        var rows = tbody.querySelectorAll('.saved-form-row');
        rows.forEach(function (row) {
            row.addEventListener('click', function () {
                var fid = parseInt(this.getAttribute('data-form-id'), 10);
                loadSavedForm(fid);
            });
        });

        // Delete — stop the click from also opening the row's editor.
        var delBtns = tbody.querySelectorAll('.ics-delete-btn');
        delBtns.forEach(function (btn) {
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                deleteSavedForm(
                    parseInt(this.getAttribute('data-form-id'), 10),
                    this.getAttribute('data-form-label')
                );
            });
        });
    }

    /**
     * "ICS-214 "Bridge collapse"" — what the confirm dialog names.
     * Phase 140: a custom-type form has no fixed "ICS-nnn" number, so its
     * type label reads from _meta -- checked in priority order across the
     * three shapes `f` can arrive in here: a full saved-form row (loadSavedForm,
     * carries form_data._meta), a hub list row (custom_form_number/title from
     * the JSON_EXTRACT columns), or the minimal object saveForm() builds
     * right after a save (same two convenience fields, sourced from the
     * just-used template).
     */
    function describeForm(f) {
        if (f && f.form_type === 'custom') {
            var meta = (f.form_data && f.form_data._meta) || {};
            var typeLabel = meta.form_number || f.custom_form_number
                || meta.form_title || f.custom_form_title || 'Custom Form';
            return typeLabel + ' "' + (f.title || '(untitled)') + '"';
        }
        return 'ICS-' + String(f.form_type || '').toUpperCase()
            + ' "' + (f.title || '(untitled)') + '"';
    }

    /**
     * Delete a saved form. Soft delete — the confirmation says so, because
     * "delete" on an operational record should not read as destruction when
     * it isn't. An admin can restore it from Settings -> Wastebasket.
     */
    function deleteSavedForm(id, label, onDone) {
        if (!id) return;
        if (!confirm('Delete ' + label + '?\n\n'
            + 'It will be moved to the wastebasket, where an administrator can '
            + 'restore it. It is not permanently erased.')) {
            return;
        }

        fetch('api/ics-forms.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'delete',
                id: id,
                csrf_token: csrfToken
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) {
                    showAlert('danger', resp.error);
                    return;
                }
                showAlert('success', resp.message || 'Form moved to the wastebasket.');
                if (onDone) { onDone(); } else { loadFormsList(); }
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to delete form: ' + err.message);
            });
    }

    function loadSavedForm(id) {
        fetch('api/ics-forms.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (form) {
                if (form.error) {
                    showAlert('danger', form.error);
                    return;
                }
                // Keep the row so the toolbar can read can_delete. Cleared in
                // openFormEditor for a brand-new form.
                currentForm = form;
                // Phase 140: custom_type_id travels on the saved row itself
                // (SELECT * on ics_forms includes it) -- thread it through so
                // re-saving this form keeps pointing at the same type.
                openFormEditor(form.form_type, form.id, form.form_data, form.incident_id, form.title, form.status, form.custom_type_id);
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to load form: ' + err.message);
            });
    }

    // ═══════════════════════════════════════════════════════════
    // Form Editor
    // ═══════════════════════════════════════════════════════════
    function openFormEditor(formType, formId, savedData, incidentId, savedTitle, savedStatus, customTypeId) {
        currentFormId = formId || 0;
        currentCustomTypeId = customTypeId || 0;
        // A new form (id 0) carries no saved row — drop any previous one so a
        // stale can_delete cannot leak a Delete button onto an unsaved form.
        if (!currentFormId) currentForm = null;

        // Phase 140 fix (GH#69 follow-up, 2026-08-16): re-opening an
        // ALREADY-SAVED custom-type instance must keep rendering the exact
        // field list it was first saved with, forever -- the same guarantee
        // ics_form_custom_print_html() already gives on print (it renders
        // solely from form_data._meta, never a fresh type lookup -- see
        // inc/ics-form-types.php's docblock). Before this fix, reopening a
        // saved instance for EDITING still fetched the type's CURRENT
        // (possibly since-edited) field list via api/ics-forms.php's
        // template endpoint, using savedData only to fill in values by key
        // -- so an administrator editing the type after the fact silently
        // changed how every earlier submission rendered in the editor, even
        // though print already froze correctly. A brand-new instance (no
        // savedData._meta yet, because nothing has been saved) still needs
        // the live type definition and falls through to the fetch below.
        if (formType === 'custom' && savedData && savedData._meta && Array.isArray(savedData._meta.fields)) {
            var meta = savedData._meta;
            var frozenTpl = {
                form_type: 'custom',
                custom_type_id: meta.type_id || currentCustomTypeId,
                slug: meta.type_slug || '',
                form_number: meta.form_number || '',
                form_title: meta.form_title || '',
                badge_color: meta.badge_color || 'secondary',
                icon: meta.icon || 'bi-file-earmark-text',
                fields: meta.fields
            };
            if (frozenTpl.custom_type_id) currentCustomTypeId = frozenTpl.custom_type_id;
            _finishOpenFormEditor(frozenTpl, formType, savedData, incidentId, savedTitle, savedStatus);
            return;
        }

        // Fetch template. Phase 140: a custom type's blank/loaded template
        // needs to know WHICH type -- travels as a second query param.
        var templateUrl = 'api/ics-forms.php?template=' + encodeURIComponent(formType);
        if (formType === 'custom' && currentCustomTypeId) {
            templateUrl += '&custom_type_id=' + currentCustomTypeId;
        }
        fetch(templateUrl)
            .then(function (r) { return r.json(); })
            .then(function (tpl) {
                if (tpl.error) {
                    showAlert('danger', tpl.error);
                    return;
                }
                // ics_form_custom_template()'s return shape carries its own
                // custom_type_id -- keep module state in sync from the
                // authoritative source, not just the caller's argument.
                if (tpl.custom_type_id) currentCustomTypeId = tpl.custom_type_id;
                _finishOpenFormEditor(tpl, formType, savedData, incidentId, savedTitle, savedStatus);
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to load form template: ' + err.message);
            });
    }

    /** Shared tail of openFormEditor() -- render the field list, show the
     * editor, and set up the toolbar/incident-link state. Reached either
     * from a frozen _meta snapshot (saved custom instance) or from the
     * live-template fetch (built-in types, and a brand-new custom form). */
    function _finishOpenFormEditor(tpl, formType, savedData, incidentId, savedTitle, savedStatus) {
        currentTemplate = tpl;
        renderFormEditor(tpl, savedData || {}, savedTitle || '', savedStatus || 'draft');
        showEditor();

        // Show/hide XML export button (only for ICS-213)
        var xmlBtn = document.getElementById('btnExportXml');
        if (xmlBtn) {
            xmlBtn.style.display = (formType === '213') ? '' : 'none';
        }

        // Show/hide Delete. Only for a form that already exists AND
        // that the server said this user may delete. A brand-new
        // unsaved form has nothing to delete.
        var delBtn = document.getElementById('btnDeleteForm');
        if (delBtn) {
            var canDelete = currentFormId > 0 && currentForm && currentForm.can_delete;
            delBtn.style.display = canDelete ? '' : 'none';
        }

        // Set incident link if provided
        var linkHidden = document.getElementById('linkIncidentId');
        var linkSearch = document.getElementById('linkIncidentSearch');
        var linkClear = document.getElementById('btnClearIncidentLink');
        if (linkHidden && incidentId) {
            linkHidden.value = incidentId;
            // Show the incident info in the search input
            if (linkSearch) linkSearch.value = 'Loading #' + incidentId + '...';
            if (linkClear) linkClear.style.display = '';
            loadIncidentData(incidentId);
            // Update search input after data loads
            setTimeout(function () {
                if (incidentData && linkSearch) {
                    // Phase 99p — prefer the case number.
                    var label = incidentData.incident_number || ('#' + incidentId);
                    if (incidentData.type_name) label += ' ' + incidentData.type_name;
                    if (incidentData.street) label += ' — ' + incidentData.street;
                    linkSearch.value = label;
                }
            }, 1000);
        } else {
            if (linkHidden) linkHidden.value = '';
            if (linkSearch) linkSearch.value = '';
            if (linkClear) linkClear.style.display = 'none';
        }
    }

    function renderFormEditor(tpl, data, title, status) {
        var editorTitle = document.getElementById('editorTitle');
        if (editorTitle) {
            editorTitle.textContent = tpl.form_number + ' — ' + tpl.form_title;
        }

        var titleInput = document.getElementById('formTitle');
        if (titleInput) titleInput.value = title;

        var statusSel = document.getElementById('formStatus');
        if (statusSel) statusSel.value = status;

        var container = document.getElementById('formFieldsContainer');
        if (!container) return;

        var html = '';
        var tabIdx = 1;
        tpl.fields.forEach(function (field) {
            if (field.type === 'table') {
                html += renderTableField(field, data[field.key] || []);
            } else if (field.type === 'section_header') {
                // No input, no tabIndex, no data key -- a divider only.
                html += renderSimpleField(field, '', 0);
            } else {
                html += renderSimpleField(field, data[field.key] || '', tabIdx);
                tabIdx++;
            }
        });
        container.innerHTML = html;

        // Bind "Add Row" buttons for table fields
        var addBtns = container.querySelectorAll('[data-add-row]');
        addBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                addTableRow(this.getAttribute('data-add-row'));
            });
        });

        // Bind "Remove Row" buttons
        bindRemoveButtons(container);
    }

    function renderSimpleField(field, value, tabIdx) {
        var id = 'ics_' + field.key;
        var req = field.required ? ' <span class="text-danger">*</span>' : '';

        // Phase 140 — section_header: a divider only, no input, excluded
        // from collectFormData() and from the tabIndex sequence.
        if (field.type === 'section_header') {
            return '<div class="col-12 mt-2 mb-1"><h6 class="border-bottom pb-1 text-body-secondary">'
                + escHtml(field.label) + '</h6></div>';
        }

        // Phase 140 — checkbox: Bootstrap's form-check markup puts the
        // input BEFORE its label, unlike every other field type here, so
        // it gets its own branch rather than slotting into the common
        // label-then-input div below.
        if (field.type === 'checkbox') {
            var checked = value ? ' checked' : '';
            var ch = '<div class="mb-2 form-check">';
            ch += '<input type="checkbox" class="form-check-input" id="' + id + '" name="' + field.key
                + '" tabindex="' + tabIdx + '"' + checked + '>';
            ch += '<label class="form-check-label" for="' + id + '">' + escHtml(field.label) + req + '</label>';
            ch += '</div>';
            return ch;
        }

        var h = '<div class="mb-2">';
        h += '<label class="form-label" for="' + id + '">' + escHtml(field.label) + req + '</label>';

        if (field.type === 'textarea') {
            var rows = field.rows || 4;
            h += '<textarea class="form-control form-control-sm" id="' + id + '" name="' + field.key
                + '" rows="' + rows + '" tabindex="' + tabIdx + '">'
                + escHtml(value) + '</textarea>';
        } else if (field.type === 'number') {
            // Phase 140 — optional min/max/step, matching the field-type
            // palette's server-side caps (inc/ics-form-types.php).
            var minAttr = (field.min !== undefined && field.min !== null && field.min !== '') ? ' min="' + escAttr(field.min) + '"' : '';
            var maxAttr = (field.max !== undefined && field.max !== null && field.max !== '') ? ' max="' + escAttr(field.max) + '"' : '';
            var stepAttr = (field.step !== undefined && field.step !== null && field.step !== '') ? ' step="' + escAttr(field.step) + '"' : '';
            h += '<input type="number" class="form-control form-control-sm" id="' + id
                + '" name="' + field.key + '" value="' + escAttr(value)
                + '"' + minAttr + maxAttr + stepAttr + ' tabindex="' + tabIdx + '">';
        } else if (field.type === 'select') {
            h += renderSelectField(id, field.key, field.options, value, tabIdx);
        } else if (field.type === 'date') {
            h += '<input type="date" class="form-control form-control-sm" id="' + id
                + '" name="' + field.key + '" value="' + escAttr(value)
                + '" tabindex="' + tabIdx + '">';
        } else if (field.type === 'time') {
            h += '<input type="time" class="form-control form-control-sm" id="' + id
                + '" name="' + field.key + '" value="' + escAttr(value)
                + '" tabindex="' + tabIdx + '">';
        } else if (field.type === 'datetime-local') {
            h += '<input type="datetime-local" class="form-control form-control-sm" id="' + id
                + '" name="' + field.key + '" value="' + escAttr(value)
                + '" tabindex="' + tabIdx + '">';
        } else {
            h += '<input type="text" class="form-control form-control-sm" id="' + id
                + '" name="' + field.key + '" value="' + escAttr(value)
                + '" tabindex="' + tabIdx + '">';
        }

        h += '</div>';
        return h;
    }

    /**
     * Phase 140 — shared <select> builder for both simple select fields and
     * table select-columns. `options` is either an array of plain strings
     * or an array of {label, value} objects (mirroring
     * ics_form_type_validate_options()'s accepted shapes server-side).
     * Every option's label/value is escaped -- these come from
     * agency-authored type definitions, never trusted as safe HTML.
     */
    function renderSelectField(id, name, options, value, tabIdx) {
        var tIdx = tabIdx ? ' tabindex="' + tabIdx + '"' : '';
        var idAttr = id ? ' id="' + id + '"' : '';
        var h = '<select class="form-select form-select-sm"' + idAttr + ' name="' + name + '"' + tIdx + '>';
        h += '<option value=""></option>';
        (options || []).forEach(function (opt) {
            var isObj = opt && typeof opt === 'object';
            var optVal = isObj ? (opt.value !== undefined ? opt.value : opt.label) : opt;
            var optLabel = isObj ? (opt.label !== undefined ? opt.label : opt.value) : opt;
            var sel = (String(optVal) === String(value)) ? ' selected' : '';
            h += '<option value="' + escAttr(optVal) + '"' + sel + '>' + escHtml(optLabel) + '</option>';
        });
        h += '</select>';
        return h;
    }

    function renderTableField(field, rows) {
        var h = '<div class="mb-3" id="tbl_wrap_' + field.key + '">';
        h += '<label class="form-label fw-bold">' + escHtml(field.label) + '</label>';
        h += '<div class="table-responsive">';
        h += '<table class="table table-sm table-bordered mb-1" id="tbl_' + field.key + '">';
        h += '<thead><tr>';
        field.columns.forEach(function (col) {
            var w = col.width && col.width !== 'auto' ? ' style="width:' + col.width + '"' : '';
            h += '<th' + w + '>' + escHtml(col.label) + '</th>';
        });
        h += '<th style="width:40px"></th></tr></thead>';
        h += '<tbody>';

        if (rows.length > 0) {
            rows.forEach(function (rowData, idx) {
                h += buildTableRow(field, rowData, idx);
            });
        } else {
            // Phase 140 fix (2026-08-16): honor the type's own configured
            // "Default rows" (the field-builder input at
            // data-field-prop="default_rows", server-capped 1-5 by
            // inc/ics-form-types.php ics_form_type_validate_fields())
            // instead of a hardcoded row count that ignored it entirely --
            // an author could set Default rows to any value and a brand-new
            // instance always started with exactly 3 empty rows regardless.
            var startRows = parseInt(field.default_rows, 10);
            if (!startRows || startRows < 1) startRows = 1;
            if (startRows > 5) startRows = 5;
            for (var i = 0; i < startRows; i++) {
                h += buildTableRow(field, {}, i);
            }
        }

        h += '</tbody></table>';
        h += '</div>';
        h += '<button type="button" class="btn btn-sm btn-outline-primary" data-add-row="' + field.key + '">';
        h += '<i class="bi bi-plus-lg me-1"></i>Add Row</button>';
        h += '</div>';
        return h;
    }

    /** Phase 140 — table-column input, widened from time/text to the full
     * palette (text/number/date/time/select) the plan's field-type table
     * defines for table columns. */
    function buildTableCell(field, col, idx, val) {
        var name = field.key + '[' + idx + '][' + col.key + ']';
        if (col.type === 'time') {
            return '<td><input type="time" class="form-control form-control-sm" name="'
                + name + '" value="' + escAttr(val) + '"></td>';
        }
        if (col.type === 'date') {
            return '<td><input type="date" class="form-control form-control-sm" name="'
                + name + '" value="' + escAttr(val) + '"></td>';
        }
        if (col.type === 'number') {
            return '<td><input type="number" class="form-control form-control-sm" name="'
                + name + '" value="' + escAttr(val) + '"></td>';
        }
        if (col.type === 'select') {
            return '<td>' + renderSelectField('', name, col.options, val, 0) + '</td>';
        }
        return '<td><input type="text" class="form-control form-control-sm" name="'
            + name + '" value="' + escAttr(val) + '"></td>';
    }

    function buildTableRow(field, rowData, idx) {
        var h = '<tr>';
        field.columns.forEach(function (col) {
            h += buildTableCell(field, col, idx, rowData[col.key] || '');
        });
        h += '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove row">'
            + '<i class="bi bi-trash"></i></button></td>';
        h += '</tr>';
        return h;
    }

    function addTableRow(fieldKey) {
        // Find the template field definition
        if (!currentTemplate) return;
        var field = null;
        currentTemplate.fields.forEach(function (f) {
            if (f.key === fieldKey) field = f;
        });
        if (!field) return;

        var tbody = document.querySelector('#tbl_' + fieldKey + ' tbody');
        if (!tbody) return;

        // Reuses buildTableRow() (rather than duplicating its per-column-type
        // markup here) so a new blank row and a loaded-data row are always
        // built by the exact same code -- a table select-column only needed
        // wiring in one place, not two.
        var idx = tbody.querySelectorAll('tr').length;
        var temp = document.createElement('tbody');
        temp.innerHTML = buildTableRow(field, {}, idx);
        var tr = temp.firstElementChild;
        tbody.appendChild(tr);

        bindRemoveButtons(tbody);

        // Focus first input/select in new row
        var firstInput = tr.querySelector('input, select');
        if (firstInput) firstInput.focus();
    }

    function bindRemoveButtons(container) {
        var btns = container.querySelectorAll('.btn-remove-row');
        btns.forEach(function (btn) {
            btn.onclick = function () {
                var tr = this.closest('tr');
                if (tr) tr.remove();
            };
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Collect form data from rendered fields
    // ═══════════════════════════════════════════════════════════
    function collectFormData() {
        if (!currentTemplate) return {};
        var data = {};

        currentTemplate.fields.forEach(function (field) {
            if (field.type === 'section_header') {
                return; // divider only -- no input, nothing to collect
            }
            if (field.type === 'table') {
                data[field.key] = collectTableData(field);
            } else if (field.type === 'checkbox') {
                var cbEl = document.getElementById('ics_' + field.key);
                data[field.key] = cbEl ? cbEl.checked : false;
            } else {
                var el = document.getElementById('ics_' + field.key);
                data[field.key] = el ? el.value : '';
            }
        });

        return data;
    }

    function collectTableData(field) {
        var tbody = document.querySelector('#tbl_' + field.key + ' tbody');
        if (!tbody) return [];

        var rows = [];
        var trs = tbody.querySelectorAll('tr');
        trs.forEach(function (tr) {
            var rowObj = {};
            var hasData = false;
            field.columns.forEach(function (col) {
                var input = tr.querySelector('[name*="[' + col.key + ']"]');
                var val = input ? input.value.trim() : '';
                rowObj[col.key] = val;
                if (val) hasData = true;
            });
            if (hasData) rows.push(rowObj);
        });

        return rows;
    }

    // ═══════════════════════════════════════════════════════════
    // Save
    // ═══════════════════════════════════════════════════════════
    function saveForm(status) {
        if (!currentTemplate) return;

        var titleInput = document.getElementById('formTitle');
        var title = titleInput ? titleInput.value.trim() : '';
        var linkInput = document.getElementById('linkIncidentId');
        var incidentId = linkInput ? linkInput.value.trim() : '';

        if (!title) {
            showAlert('warning', 'Please enter a form title.');
            if (titleInput) titleInput.focus();
            return;
        }

        var formData = collectFormData();

        var payload = {
            csrf_token:  csrfToken,
            action:      'save',
            form_type:   currentTemplate.form_type,
            title:       title,
            status:      status,
            form_data:   formData,
            incident_id: incidentId || null
        };

        if (currentFormId > 0) {
            payload.id = currentFormId;
        }

        // Phase 140: which ics_form_types row this instance belongs to --
        // the server re-resolves and re-validates it (ics_form_custom_template()),
        // this is a hint, not a trust boundary.
        if (currentTemplate.form_type === 'custom' && currentCustomTypeId) {
            payload.custom_type_id = currentCustomTypeId;
        }

        fetch('api/ics-forms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.error) {
                showAlert('danger', resp.error);
                return;
            }
            currentFormId = resp.id;
            var statusSel = document.getElementById('formStatus');
            if (statusSel) statusSel.value = status;

            // The save reply says whether this user may delete what they just
            // saved. Keep the toolbar in step: Delete appears right after the
            // first save of a new draft, and goes away when a non-privileged
            // author finalizes it (a finalized form is admin-only).
            var titleInput = document.getElementById('formTitle');
            currentForm = {
                id: resp.id,
                form_type: currentTemplate ? currentTemplate.form_type : '',
                title: titleInput ? titleInput.value : '',
                status: status,
                can_delete: !!resp.can_delete,
                // Phase 140: so describeForm() (used by the Delete
                // confirmation right after this save) can show the custom
                // type's own label without a second round-trip -- sourced
                // from the template just used to render this editor, which
                // for a form saved THIS moment is exactly what the server
                // just froze into _meta.
                custom_form_number: currentTemplate ? currentTemplate.form_number : '',
                custom_form_title: currentTemplate ? currentTemplate.form_title : ''
            };
            var delBtn = document.getElementById('btnDeleteForm');
            if (delBtn) delBtn.style.display = resp.can_delete ? '' : 'none';

            showAlert('success', 'Form saved successfully (ID: ' + resp.id + ').');
        })
        .catch(function (err) {
            showAlert('danger', 'Save failed: ' + err.message);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Print — open print-optimized HTML in new window
    // ═══════════════════════════════════════════════════════════
    function printForm() {
        if (!currentFormId) {
            // If not saved yet, do a client-side print. Deferred off the
            // GH#105 -- root cause confirmed to be an open EventSource,
            // not click-vs-keyboard timing; appPrint() (event-bus.js)
            // closes it before calling print().
            appPrint();
            return;
        }

        fetch('api/ics-forms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                action:     'export_pdf',
                id:         currentFormId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.error) {
                showAlert('danger', resp.error);
                return;
            }
            var w = window.open('', '_blank');
            w.document.write(resp.html);
            w.document.close();
            setTimeout(function () { w.print(); }, 500);
        })
        .catch(function (err) {
            showAlert('danger', 'Print failed: ' + err.message);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Export XML (ICS-213 only)
    // ═══════════════════════════════════════════════════════════
    function exportXml() {
        if (!currentFormId) {
            showAlert('warning', 'Please save the form first before exporting XML.');
            return;
        }

        fetch('api/ics-forms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: csrfToken,
                action:     'export_xml',
                id:         currentFormId
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp.error) {
                showAlert('danger', resp.error);
                return;
            }
            // Trigger download
            var blob = new Blob([resp.xml], { type: 'application/xml' });
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = resp.filename || 'ics213.xml';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        })
        .catch(function (err) {
            showAlert('danger', 'XML export failed: ' + err.message);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // Incident linking — auto-populate from incident data
    // ═══════════════════════════════════════════════════════════
    function loadIncidentData(ticketId) {
        if (!ticketId) return;

        fetch('api/incident-detail.php?id=' + ticketId)
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                if (resp.error) {
                    showAlert('warning', 'Could not load incident #' + ticketId + ': ' + resp.error);
                    return;
                }
                // GH#101 — api/incident-detail.php returns an envelope
                // ({incident, assignments, actions}), not a flat incident
                // object. Every field autoPopulateFromIncident() (and the
                // preview label built in _finishOpenFormEditor() above,
                // which reads incidentData.incident_number/.type_name/
                // .street) expects — inc.id, inc.scope, inc.street,
                // inc.type_name, etc. — lives under resp.incident. Reading
                // resp directly made every field undefined (hence
                // "Incident #undefined"). Fall back to resp itself so this
                // degrades instead of breaking again if the endpoint is
                // ever flattened later.
                var inc = (resp && resp.incident) ? resp.incident : resp;
                incidentData = inc;
                autoPopulateFromIncident(inc);
                showAlert('info', 'Linked to incident #' + ticketId + ' — fields auto-populated.');
            })
            .catch(function () {
                showAlert('warning', 'Could not load incident #' + ticketId);
            });
    }

    function autoPopulateFromIncident(inc) {
        if (!currentTemplate) return;
        var type = currentTemplate.form_type;

        // Phase 99p — fallback uses case number, not internal id.
        var incFallback = inc.incident_number ? ('Incident ' + inc.incident_number) : ('Incident #' + inc.id);
        // Set title if empty
        var titleEl = document.getElementById('formTitle');
        if (titleEl && !titleEl.value) {
            titleEl.value = (inc.scope || incFallback);
        }

        // Common: incident_name
        setFieldIfEmpty('incident_name', inc.scope || incFallback);

        // Date fields
        var now = new Date();
        setFieldIfEmpty('date_prepared', now.toISOString().substring(0, 10));
        setFieldIfEmpty('date', now.toISOString().substring(0, 10));
        setFieldIfEmpty('time', now.toTimeString().substring(0, 5));

        // ICS-213 specific
        // GH#101 — api/incident-detail.php's actual output key is
        // type_name (it.`type` AS `type_name`), not incident_type_name.
        if (type === '213') {
            var subject = (inc.type_name || 'Incident') + ' - ' + (inc.scope || '');
            setFieldIfEmpty('subject', subject);

            var body = '';
            if (inc.type_name) body += 'Type: ' + inc.type_name + '\n';
            if (inc.street) body += 'Location: ' + inc.street + (inc.city ? ', ' + inc.city : '') + '\n';
            if (inc.description) body += 'Description: ' + inc.description + '\n';
            setFieldIfEmpty('message', body);
        }

        // Op period from incident times.
        // GH#101 — the endpoint never returns a bare `date` key; the
        // real incident-time field is `problemstart` (created/updated
        // are also available but problemstart is the operational start).
        if (inc.problemstart) {
            var dtVal = inc.problemstart.replace(' ', 'T').substring(0, 16);
            setFieldIfEmpty('op_period_from', dtVal);
        }
    }

    function setFieldIfEmpty(key, value) {
        var el = document.getElementById('ics_' + key);
        if (el && !el.value && value) {
            el.value = value;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // View toggling
    // ═══════════════════════════════════════════════════════════
    function showHub() {
        var hub = document.getElementById('hubSection');
        var editor = document.getElementById('editorSection');
        if (hub) hub.style.display = '';
        if (editor) editor.style.display = 'none';
        currentTemplate = null;
        currentFormId = 0;
        currentForm = null;
        incidentData = null;
        loadFormsList();
    }

    function showEditor() {
        var hub = document.getElementById('hubSection');
        var editor = document.getElementById('editorSection');
        if (hub) hub.style.display = 'none';
        if (editor) editor.style.display = '';
    }

    // ═══════════════════════════════════════════════════════════
    // Utility functions
    // ═══════════════════════════════════════════════════════════
    function showAlert(type, message) {
        var area = document.getElementById('alertArea');
        if (!area) return;
        var id = 'alert_' + Date.now();
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-2" id="' + id + '">'
            + escHtml(message)
            + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
        // Auto-dismiss after 5s
        setTimeout(function () {
            var el = document.getElementById(id);
            if (el) el.remove();
        }, 5000);
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatDate(str) {
        if (!str) return '';
        try {
            var d = new Date(str);
            return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        } catch (e) {
            return str;
        }
    }

    /**
     * Phase 140: takes the WHOLE saved-form row (was: just the type
     * string) so a custom-type row can read its badge from its own frozen
     * _meta rather than a fixed lookup table (matches describeForm()'s
     * first-branch pattern). `badge_color` is agency-authored, so it is
     * checked against the SAME 7-value Bootstrap enum
     * ics_form_type_validate_metadata() enforces server-side, defense in
     * depth against a stale/unvalidated value ever reaching a raw class
     * attribute -- never trusted as a bare string concatenation.
     */
    function getFormTypeBadge(f) {
        if (f && f.form_type === 'custom') {
            var allowedColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];
            var cLabel = escHtml(f.custom_form_number || f.custom_form_title || 'Custom');
            var cColor = f.custom_badge_color;
            if (allowedColors.indexOf(cColor) === -1) cColor = 'secondary';
            return '<span class="badge bg-' + cColor + '">' + cLabel + '</span>';
        }

        var type = f && f.form_type;
        var labels = {
            '213':   'ICS-213',
            '214':   'ICS-214',
            '202':   'ICS-202',
            '205':   'ICS-205',
            '205a':  'ICS-205A',
            '206':   'ICS-206',
            '213rr': 'ICS-213RR',
            '214a':  'ICS-214a',
            '221':   'ICS-221'
        };
        var colors = {
            '213':   'primary',
            '214':   'success',
            '202':   'info',
            '205':   'warning',
            '205a':  'secondary',
            '206':   'teal',
            '213rr': 'danger',
            '214a':  'indigo',
            '221':   'orange'
        };
        var label = labels[type] || type;
        var color = colors[type] || 'secondary';
        // Bootstrap CSS variable colors need inline style instead of bg- class
        var cssVarColors = { 'teal': true, 'indigo': true, 'orange': true };
        if (cssVarColors[color]) {
            return '<span class="badge" style="background-color:var(--bs-' + color + ')">' + label + '</span>';
        }
        return '<span class="badge bg-' + color + '">' + label + '</span>';
    }

    function getStatusBadge(status) {
        var colors = { draft: 'secondary', final: 'success', sent: 'primary' };
        var color = colors[status] || 'secondary';
        return '<span class="badge bg-' + color + '">' + escHtml(status) + '</span>';
    }

})();
