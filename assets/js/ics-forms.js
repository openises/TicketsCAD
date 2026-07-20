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
    var csrfToken       = '';     // CSRF token from page
    var incidentData    = null;   // Linked incident data (if any)

    // ── Init ──
    document.addEventListener('DOMContentLoaded', function () {
        csrfToken = document.getElementById('csrfToken')
            ? document.getElementById('csrfToken').value : '';
        initTheme();
        loadFormsList();
        bindHubEvents();
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
        // "New" buttons on form type cards
        var newBtns = document.querySelectorAll('[data-new-form]');
        newBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var type = this.getAttribute('data-new-form');
                openFormEditor(type, 0);
            });
        });

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
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">'
                + 'No saved forms yet. Use the cards above to create a new form.</td></tr>';
            return;
        }

        var html = '';
        forms.forEach(function (f) {
            var typeBadge = getFormTypeBadge(f.form_type);
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
    }

    function loadSavedForm(id) {
        fetch('api/ics-forms.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (form) {
                if (form.error) {
                    showAlert('danger', form.error);
                    return;
                }
                openFormEditor(form.form_type, form.id, form.form_data, form.incident_id, form.title, form.status);
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to load form: ' + err.message);
            });
    }

    // ═══════════════════════════════════════════════════════════
    // Form Editor
    // ═══════════════════════════════════════════════════════════
    function openFormEditor(formType, formId, savedData, incidentId, savedTitle, savedStatus) {
        currentFormId = formId || 0;

        // Fetch template
        fetch('api/ics-forms.php?template=' + encodeURIComponent(formType))
            .then(function (r) { return r.json(); })
            .then(function (tpl) {
                if (tpl.error) {
                    showAlert('danger', tpl.error);
                    return;
                }
                currentTemplate = tpl;
                renderFormEditor(tpl, savedData || {}, savedTitle || '', savedStatus || 'draft');
                showEditor();

                // Show/hide XML export button (only for ICS-213)
                var xmlBtn = document.getElementById('btnExportXml');
                if (xmlBtn) {
                    xmlBtn.style.display = (formType === '213') ? '' : 'none';
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
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to load form template: ' + err.message);
            });
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
        var h = '<div class="mb-2">';
        h += '<label class="form-label" for="' + id + '">' + escHtml(field.label) + req + '</label>';

        if (field.type === 'textarea') {
            var rows = field.rows || 4;
            h += '<textarea class="form-control form-control-sm" id="' + id + '" name="' + field.key
                + '" rows="' + rows + '" tabindex="' + tabIdx + '">'
                + escHtml(value) + '</textarea>';
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
            // Start with 3 empty rows
            for (var i = 0; i < 3; i++) {
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

    function buildTableRow(field, rowData, idx) {
        var h = '<tr>';
        field.columns.forEach(function (col) {
            var val = rowData[col.key] || '';
            var name = field.key + '[' + idx + '][' + col.key + ']';
            if (col.type === 'time') {
                h += '<td><input type="time" class="form-control form-control-sm" name="'
                    + name + '" value="' + escAttr(val) + '"></td>';
            } else {
                h += '<td><input type="text" class="form-control form-control-sm" name="'
                    + name + '" value="' + escAttr(val) + '"></td>';
            }
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

        var idx = tbody.querySelectorAll('tr').length;
        var tr = document.createElement('tr');
        tr.innerHTML = '';
        field.columns.forEach(function (col) {
            var name = field.key + '[' + idx + '][' + col.key + ']';
            var td = '<td>';
            if (col.type === 'time') {
                td += '<input type="time" class="form-control form-control-sm" name="' + name + '" value="">';
            } else {
                td += '<input type="text" class="form-control form-control-sm" name="' + name + '" value="">';
            }
            td += '</td>';
            tr.innerHTML += td;
        });
        tr.innerHTML += '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row" title="Remove row">'
            + '<i class="bi bi-trash"></i></button></td>';
        tbody.appendChild(tr);

        bindRemoveButtons(tbody);

        // Focus first input in new row
        var firstInput = tr.querySelector('input');
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
            if (field.type === 'table') {
                data[field.key] = collectTableData(field);
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
            // If not saved yet, do a client-side print
            window.print();
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
                incidentData = resp;
                autoPopulateFromIncident(resp);
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
        if (type === '213') {
            var subject = (inc.incident_type_name || 'Incident') + ' - ' + (inc.scope || '');
            setFieldIfEmpty('subject', subject);

            var body = '';
            if (inc.incident_type_name) body += 'Type: ' + inc.incident_type_name + '\n';
            if (inc.street) body += 'Location: ' + inc.street + (inc.city ? ', ' + inc.city : '') + '\n';
            if (inc.description) body += 'Description: ' + inc.description + '\n';
            setFieldIfEmpty('message', body);
        }

        // Op period from incident times
        if (inc.date) {
            var dtVal = inc.date.replace(' ', 'T').substring(0, 16);
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

    function getFormTypeBadge(type) {
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
