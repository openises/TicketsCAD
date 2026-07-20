/**
 * NewUI v4.0 - Constituents Import Wizard
 *
 * 4-step import: Upload → Map Columns → Preview Conflicts → Execute
 * Exposes window.openImportModal() for the main constituents.js to call.
 */
(function () {
    'use strict';

    var DB_FIELDS = [
        { value: '',              label: '-- Skip --' },
        { value: 'contact',       label: 'Name' },
        { value: 'phone',         label: 'Phone' },
        { value: 'phone_type',    label: 'Phone Type' },
        { value: 'phone_2',       label: 'Phone 2' },
        { value: 'phone_2_type',  label: 'Phone 2 Type' },
        { value: 'phone_3',       label: 'Phone 3' },
        { value: 'phone_3_type',  label: 'Phone 3 Type' },
        { value: 'phone_4',       label: 'Phone 4' },
        { value: 'phone_4_type',  label: 'Phone 4 Type' },
        { value: 'email',         label: 'Email' },
        { value: 'street',        label: 'Street' },
        { value: 'apartment',     label: 'Apartment' },
        { value: 'city',          label: 'City' },
        { value: 'state',         label: 'State' },
        { value: 'post_code',     label: 'Zip' },
        { value: 'community',     label: 'Community' },
        { value: 'miscellaneous', label: 'Notes' },
        { value: 'reference',     label: 'Reference' },
        { value: 'lat',           label: 'Latitude' },
        { value: 'lng',           label: 'Longitude' }
    ];

    var MERGE_FIELDS = [
        { key: 'contact',       label: 'Name' },
        { key: 'phone',         label: 'Phone' },
        { key: 'phone_type',    label: 'Phone Type' },
        { key: 'phone_2',       label: 'Phone 2' },
        { key: 'phone_2_type',  label: 'Phone 2 Type' },
        { key: 'phone_3',       label: 'Phone 3' },
        { key: 'phone_3_type',  label: 'Phone 3 Type' },
        { key: 'phone_4',       label: 'Phone 4' },
        { key: 'phone_4_type',  label: 'Phone 4 Type' },
        { key: 'email',         label: 'Email' },
        { key: 'street',        label: 'Street' },
        { key: 'apartment',     label: 'Apartment' },
        { key: 'city',          label: 'City' },
        { key: 'state',         label: 'State' },
        { key: 'post_code',     label: 'Zip' },
        { key: 'community',     label: 'Community' },
        { key: 'miscellaneous', label: 'Notes' },
        { key: 'reference',     label: 'Reference' }
    ];

    // Import state
    var state = {
        step: 1,
        headers: [],
        preview: [],
        guessedMap: {},
        totalRows: 0,
        fileName: '',
        conflicts: [],
        newCount: 0
    };

    var importModal = null;
    var mergeModal = null;
    var mergeRecordA = null;
    var mergeRecordB = null;

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // ── Import Modal ────────────────────────────────────────────

    window.openImportModal = function () {
        state.step = 1;
        state.headers = [];
        state.preview = [];
        state.guessedMap = {};
        state.totalRows = 0;
        state.conflicts = [];
        state.newCount = 0;

        var fileInput = document.getElementById('importFileInput');
        if (fileInput) fileInput.value = '';

        showStep(1);

        if (!importModal) {
            importModal = new bootstrap.Modal(document.getElementById('importModal'));
        }
        importModal.show();
    };

    // ── Merge Modal ─────────────────────────────────────────────

    window.openMergeModal = function (recordA, recordB) {
        mergeRecordA = recordA;
        mergeRecordB = recordB;
        renderMergeTable();

        if (!mergeModal) {
            mergeModal = new bootstrap.Modal(document.getElementById('mergeModal'));
        }
        mergeModal.show();
    };

    // ── Step Navigation ─────────────────────────────────────────

    function showStep(n) {
        state.step = n;

        // Show/hide step panels
        var panels = document.querySelectorAll('.import-step');
        for (var i = 0; i < panels.length; i++) {
            var stepNum = parseInt(panels[i].getAttribute('data-step'), 10);
            if (stepNum === n) {
                panels[i].classList.remove('d-none');
            } else {
                panels[i].classList.add('d-none');
            }
        }

        // Update step indicators
        var indicators = document.querySelectorAll('.import-step-indicator');
        for (var j = 0; j < indicators.length; j++) {
            var indStep = parseInt(indicators[j].getAttribute('data-step'), 10);
            if (indStep === n) {
                indicators[j].classList.add('active');
                indicators[j].querySelector('i').className = 'bi bi-' + indStep + '-circle-fill me-1';
            } else if (indStep < n) {
                indicators[j].classList.remove('active');
                indicators[j].querySelector('i').className = 'bi bi-' + indStep + '-circle-fill me-1 text-success';
            } else {
                indicators[j].classList.remove('active');
                indicators[j].querySelector('i').className = 'bi bi-' + indStep + '-circle me-1';
            }
        }

        // Update buttons
        var nextBtn = document.getElementById('importNextBtn');
        var backBtn = document.getElementById('importBackBtn');
        var closeBtn = document.getElementById('importCloseBtn');

        backBtn.classList.add('d-none');
        nextBtn.classList.remove('d-none');

        if (n === 1) {
            nextBtn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload &amp; Parse';
            closeBtn.textContent = 'Cancel';
        } else if (n === 2) {
            backBtn.classList.remove('d-none');
            nextBtn.innerHTML = '<i class="bi bi-arrow-right me-1"></i>Preview &amp; Check Conflicts';
        } else if (n === 3) {
            backBtn.classList.remove('d-none');
            nextBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Execute Import';
        } else if (n === 4) {
            nextBtn.classList.add('d-none');
            closeBtn.textContent = 'Close';
        }

        clearImportAlert();
    }

    // ── Step 1: Upload & Parse ──────────────────────────────────

    function uploadAndParse() {
        var fileInput = document.getElementById('importFileInput');
        if (!fileInput.files || !fileInput.files[0]) {
            showImportAlert('Please select a file.', 'danger');
            return;
        }

        var formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('csrf_token', getCsrf());
        formData.append('action', 'parse');

        var nextBtn = document.getElementById('importNextBtn');
        nextBtn.disabled = true;
        nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Parsing...';

        fetch('api/constituents-import.php', {
            method: 'POST',
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            nextBtn.disabled = false;

            if (data.error) {
                showImportAlert(esc(data.error), 'danger');
                showStep(1);
                return;
            }

            state.headers = data.headers || [];
            state.preview = data.preview || [];
            state.guessedMap = data.guessed_map || {};
            state.totalRows = data.total_rows || 0;
            state.fileName = data.file_name || '';

            renderColumnMapping();
            showStep(2);
        })
        .catch(function (err) {
            nextBtn.disabled = false;
            showImportAlert('Upload failed: ' + esc(err.message), 'danger');
            showStep(1);
        });
    }

    // ── Step 2: Column Mapping ──────────────────────────────────

    function renderColumnMapping() {
        var tbody = document.getElementById('mappingBody');
        var infoEl = document.getElementById('importFileInfo');

        infoEl.textContent = state.fileName + ' — ' + state.totalRows + ' rows, ' + state.headers.length + ' columns';

        var html = '';
        for (var i = 0; i < state.headers.length; i++) {
            var header = state.headers[i];
            var guessed = state.guessedMap[String(i)] || '';

            // Preview data from first 3 rows
            var previewVals = [];
            for (var p = 0; p < Math.min(3, state.preview.length); p++) {
                var val = state.preview[p][i] || '';
                if (val.length > 30) val = val.substring(0, 30) + '...';
                previewVals.push(esc(val));
            }

            html += '<tr>';
            html += '<td class="fw-semibold">' + esc(header) + '</td>';
            html += '<td class="text-body-secondary">' + previewVals.join('<br>') + '</td>';
            html += '<td>';
            html += '<select class="form-select form-select-sm mapping-select" data-col="' + i + '">';
            for (var f = 0; f < DB_FIELDS.length; f++) {
                var selected = (DB_FIELDS[f].value === guessed) ? ' selected' : '';
                html += '<option value="' + DB_FIELDS[f].value + '"' + selected + '>' + DB_FIELDS[f].label + '</option>';
            }
            html += '</select>';
            html += '</td>';
            html += '</tr>';
        }

        tbody.innerHTML = html;

        // Populate first/last/middle name dropdowns
        var firstNameCol = document.getElementById('firstNameCol');
        var lastNameCol = document.getElementById('lastNameCol');
        var middleNameCol = document.getElementById('middleNameCol');
        var colOpts = '<option value="">--</option>';
        for (var c = 0; c < state.headers.length; c++) {
            colOpts += '<option value="' + c + '">' + esc(state.headers[c]) + '</option>';
        }
        firstNameCol.innerHTML = colOpts;
        lastNameCol.innerHTML = colOpts;
        middleNameCol.innerHTML = colOpts;

        // If guessed map has first/last name flags, set them
        if (state.guessedMap['_first_name_col'] !== undefined) {
            document.getElementById('chkFirstLast').checked = true;
            document.getElementById('firstLastRow').classList.remove('d-none');
            firstNameCol.value = state.guessedMap['_first_name_col'];
            lastNameCol.value = state.guessedMap['_last_name_col'] || '';
            if (state.guessedMap['_middle_name_col'] !== undefined) {
                middleNameCol.value = state.guessedMap['_middle_name_col'];
            }
        }
    }

    function getColumnMap() {
        var map = {};
        var selects = document.querySelectorAll('.mapping-select');
        for (var i = 0; i < selects.length; i++) {
            var col = selects[i].getAttribute('data-col');
            var val = selects[i].value;
            if (val) {
                map[col] = val;
            }
        }
        return map;
    }

    function getDefaults() {
        var defaults = {};
        var city = document.getElementById('defaultCity');
        var st = document.getElementById('defaultState');
        var community = document.getElementById('defaultCommunity');
        if (city && city.value.trim()) defaults.city = city.value.trim();
        if (st && st.value.trim()) defaults.state = st.value.trim();
        if (community && community.value.trim()) defaults.community = community.value.trim();
        return defaults;
    }

    function getNameParts() {
        var chk = document.getElementById('chkFirstLast');
        if (!chk || !chk.checked) return { first: null, last: null, middle: null, order: 'first_last' };
        var first = document.getElementById('firstNameCol');
        var last = document.getElementById('lastNameCol');
        var middle = document.getElementById('middleNameCol');
        var orderRadio = document.querySelector('input[name="nameOrder"]:checked');
        return {
            first: (first && first.value !== '') ? parseInt(first.value, 10) : null,
            last: (last && last.value !== '') ? parseInt(last.value, 10) : null,
            middle: (middle && middle.value !== '') ? parseInt(middle.value, 10) : null,
            order: orderRadio ? orderRadio.value : 'first_last'
        };
    }

    // ── Step 3: Preview Conflicts ───────────────────────────────

    function previewConflicts() {
        var columnMap = getColumnMap();

        // Validate: need at least contact or phone mapped
        var hasContact = false;
        var hasPhone = false;
        for (var k in columnMap) {
            if (columnMap[k] === 'contact') hasContact = true;
            if (columnMap[k] === 'phone') hasPhone = true;
        }
        var np = getNameParts();
        if (np.first !== null) hasContact = true;

        if (!hasContact && !hasPhone) {
            showImportAlert('Please map at least the Name or Phone column.', 'warning');
            return;
        }

        document.getElementById('previewLoading').classList.remove('d-none');
        document.getElementById('conflictsList').innerHTML = '';
        document.getElementById('noConflictsMsg').classList.add('d-none');

        var nextBtn = document.getElementById('importNextBtn');
        nextBtn.disabled = true;

        showStep(3);

        fetch('api/constituents-import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'preview',
                csrf_token: getCsrf(),
                column_map: columnMap,
                defaults: getDefaults(),
                first_name_col: np.first,
                last_name_col: np.last,
                middle_name_col: np.middle,
                name_order: np.order
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            nextBtn.disabled = false;
            document.getElementById('previewLoading').classList.add('d-none');

            if (data.error) {
                showImportAlert(esc(data.error), 'danger');
                return;
            }

            state.conflicts = data.conflicts || [];
            state.newCount = data.new_count || 0;

            document.getElementById('previewNewCount').textContent = state.newCount + ' new';
            document.getElementById('previewConflictCount').textContent = state.conflicts.length + ' conflicts';

            if (state.conflicts.length === 0) {
                document.getElementById('noConflictsMsg').classList.remove('d-none');
            } else {
                renderConflicts();
            }
        })
        .catch(function (err) {
            nextBtn.disabled = false;
            document.getElementById('previewLoading').classList.add('d-none');
            showImportAlert('Preview failed: ' + esc(err.message), 'danger');
        });
    }

    function renderConflicts() {
        var container = document.getElementById('conflictsList');
        var html = '';

        for (var i = 0; i < state.conflicts.length; i++) {
            var c = state.conflicts[i];
            var imp = c.import_data;
            var ex = c.existing;
            var rowKey = String(c.import_row);

            html += '<div class="card mb-2">';
            html += '<div class="card-header py-1 d-flex align-items-center small" data-bs-toggle="collapse" data-bs-target="#conflict' + i + '" role="button">';
            html += '<i class="bi bi-exclamation-triangle text-warning me-2"></i>';
            html += '<strong>Row ' + (c.import_row + 1) + ':</strong>&nbsp;';
            html += esc(imp.contact || '') + ' (' + esc(imp.phone || imp.email || '') + ')';
            html += ' <span class="text-body-secondary ms-1">matches existing: ' + esc(ex.contact || '') + '</span>';
            html += '<div class="ms-auto">';
            html += '<select class="form-select form-select-sm conflict-action" data-row="' + rowKey + '" style="width:auto;" onclick="event.stopPropagation();">';
            html += '<option value="">(use default)</option>';
            html += '<option value="skip">Skip</option>';
            html += '<option value="overwrite">Overwrite</option>';
            html += '<option value="merge">Merge</option>';
            html += '</select>';
            html += '</div>';
            html += '</div>';

            html += '<div class="collapse" id="conflict' + i + '">';
            html += '<div class="card-body py-1 px-3">';
            html += '<table class="table table-sm table-bordered small mb-0">';
            html += '<thead><tr><th>Field</th><th>Import</th><th>Existing</th></tr></thead><tbody>';

            var fields = ['contact', 'phone', 'phone_2', 'phone_3', 'phone_4', 'email',
                          'street', 'apartment', 'city', 'state', 'post_code', 'community',
                          'miscellaneous', 'reference'];
            for (var f = 0; f < fields.length; f++) {
                var fld = fields[f];
                var impVal = imp[fld] || '';
                var exVal = ex[fld] || '';
                if (!impVal && !exVal) continue;
                var diffClass = (impVal && exVal && impVal !== exVal) ? ' class="table-warning"' : '';
                html += '<tr' + diffClass + '><td>' + fld + '</td><td>' + esc(impVal) + '</td><td>' + esc(exVal) + '</td></tr>';
            }

            html += '</tbody></table></div></div></div>';
        }

        container.innerHTML = html;
    }

    // ── Step 4: Execute Import ──────────────────────────────────

    function executeImport() {
        var columnMap = getColumnMap();
        var np = getNameParts();

        // Gather per-row conflict resolutions
        var resolutions = {};
        var selects = document.querySelectorAll('.conflict-action');
        for (var i = 0; i < selects.length; i++) {
            var row = selects[i].getAttribute('data-row');
            var val = selects[i].value;
            if (val) {
                resolutions[row] = val;
            }
        }

        var globalAction = document.getElementById('globalConflictAction');
        var globalVal = globalAction ? globalAction.value : 'skip';

        var nextBtn = document.getElementById('importNextBtn');
        nextBtn.disabled = true;
        nextBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';

        fetch('api/constituents-import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'execute',
                csrf_token: getCsrf(),
                column_map: columnMap,
                defaults: getDefaults(),
                first_name_col: np.first,
                last_name_col: np.last,
                middle_name_col: np.middle,
                name_order: np.order,
                conflict_resolutions: resolutions,
                global_conflict_action: globalVal
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            nextBtn.disabled = false;

            if (data.error) {
                showImportAlert(esc(data.error), 'danger');
                showStep(3);
                return;
            }

            // Show results
            document.getElementById('resultInserted').textContent = data.inserted || 0;
            document.getElementById('resultUpdated').textContent = data.updated || 0;
            document.getElementById('resultSkipped').textContent = data.skipped || 0;

            if (data.errors && data.errors.length > 0) {
                var errHtml = '<strong>Errors (' + data.errors.length + '):</strong>';
                for (var e = 0; e < Math.min(20, data.errors.length); e++) {
                    var err = data.errors[e];
                    var contactLabel = err.contact ? ' — ' + esc(err.contact) : '';
                    errHtml += '<div class="card card-body py-2 px-3 mt-2 bg-body-tertiary small">';
                    errHtml += '<div><strong>Row ' + err.row + contactLabel + '</strong></div>';
                    errHtml += '<div class="text-danger">' + esc(err.message) + '</div>';
                    if (err.data) {
                        errHtml += '<div class="mt-1 text-body-secondary"><small>';
                        var fields = Object.keys(err.data);
                        var parts = [];
                        for (var fi = 0; fi < fields.length; fi++) {
                            var fv = err.data[fields[fi]];
                            if (fv) parts.push('<strong>' + esc(fields[fi]) + ':</strong> ' + esc(String(fv)));
                        }
                        errHtml += parts.join(' &middot; ');
                        errHtml += '</small></div>';
                    }
                    errHtml += '</div>';
                }
                if (data.errors.length > 20) {
                    errHtml += '<div class="mt-1 text-body-secondary small">...and ' + (data.errors.length - 20) + ' more</div>';
                }
                document.getElementById('resultErrorList').innerHTML = errHtml;
                document.getElementById('resultErrors').classList.remove('d-none');
            } else {
                document.getElementById('resultErrors').classList.add('d-none');
            }

            showStep(4);

            // Refresh the constituent list
            if (typeof window.refreshConstituentList === 'function') {
                window.refreshConstituentList();
            }
        })
        .catch(function (err) {
            nextBtn.disabled = false;
            showImportAlert('Import failed: ' + esc(err.message), 'danger');
            showStep(3);
        });
    }

    // ── Merge ───────────────────────────────────────────────────

    function renderMergeTable() {
        var tbody = document.getElementById('mergeBody');
        var html = '';

        for (var i = 0; i < MERGE_FIELDS.length; i++) {
            var f = MERGE_FIELDS[i];
            var valA = (mergeRecordA && mergeRecordA[f.key]) ? mergeRecordA[f.key] : '';
            var valB = (mergeRecordB && mergeRecordB[f.key]) ? mergeRecordB[f.key] : '';
            if (!valA && !valB) continue;

            var diffClass = (valA && valB && valA !== valB) ? ' class="table-warning"' : '';

            html += '<tr' + diffClass + '>';
            html += '<td class="fw-semibold">' + f.label + '</td>';
            html += '<td>' + esc(valA) + '</td>';
            html += '<td>' + esc(valB) + '</td>';
            html += '<td>';
            html += '<div class="btn-group btn-group-sm">';
            html += '<button type="button" class="btn btn-outline-primary btn-sm merge-pick active" data-field="' + f.key + '" data-source="a">A</button>';
            html += '<button type="button" class="btn btn-outline-primary btn-sm merge-pick" data-field="' + f.key + '" data-source="b">B</button>';
            html += '</div>';
            html += '</td>';
            html += '</tr>';
        }

        tbody.innerHTML = html;

        // Bind pick buttons
        var picks = tbody.querySelectorAll('.merge-pick');
        for (var j = 0; j < picks.length; j++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    // Toggle active within same group
                    var siblings = btn.parentElement.querySelectorAll('.merge-pick');
                    for (var s = 0; s < siblings.length; s++) {
                        siblings[s].classList.remove('active');
                    }
                    btn.classList.add('active');
                });
            })(picks[j]);
        }
    }

    function autoMerge() {
        var picks = document.querySelectorAll('#mergeBody .merge-pick');
        for (var i = 0; i < picks.length; i += 2) {
            var btnA = picks[i];
            var btnB = picks[i + 1];
            if (!btnA || !btnB) continue;

            var field = btnA.getAttribute('data-field');
            var valA = (mergeRecordA && mergeRecordA[field]) ? mergeRecordA[field] : '';
            var valB = (mergeRecordB && mergeRecordB[field]) ? mergeRecordB[field] : '';

            // Pick non-empty; if both non-empty, prefer newer record
            btnA.classList.remove('active');
            btnB.classList.remove('active');

            if (valA && !valB) {
                btnA.classList.add('active');
            } else if (!valA && valB) {
                btnB.classList.add('active');
            } else if (valA && valB) {
                // Prefer newer (compare updated timestamps)
                var dateA = mergeRecordA.updated ? new Date(mergeRecordA.updated) : new Date(0);
                var dateB = mergeRecordB.updated ? new Date(mergeRecordB.updated) : new Date(0);
                if (dateB > dateA) {
                    btnB.classList.add('active');
                } else {
                    btnA.classList.add('active');
                }
            } else {
                btnA.classList.add('active');
            }
        }
    }

    function executeMerge() {
        if (!mergeRecordA || !mergeRecordB) return;

        // Build merged fields from selections
        var mergedFields = {};
        var picks = document.querySelectorAll('#mergeBody .merge-pick.active');
        for (var i = 0; i < picks.length; i++) {
            var field = picks[i].getAttribute('data-field');
            var source = picks[i].getAttribute('data-source');
            var record = (source === 'a') ? mergeRecordA : mergeRecordB;
            mergedFields[field] = record[field] || '';
        }

        // Determine survivor
        var survivorRadio = document.querySelector('input[name="mergeSurvivor"]:checked');
        var survivor = survivorRadio ? survivorRadio.value : 'a';
        var primaryId = (survivor === 'a') ? mergeRecordA.id : mergeRecordB.id;
        var secondaryId = (survivor === 'a') ? mergeRecordB.id : mergeRecordA.id;

        fetch('api/constituents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'merge',
                csrf_token: getCsrf(),
                primary_id: primaryId,
                secondary_id: secondaryId,
                fields: mergedFields
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                var area = document.getElementById('mergeAlertArea');
                area.innerHTML = '<div class="alert alert-danger alert-dismissible fade show py-1 small">' +
                    esc(data.error) + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
                return;
            }

            if (mergeModal) mergeModal.hide();

            // Show success and refresh
            var alertArea = document.getElementById('alertArea');
            alertArea.innerHTML = '<div class="alert alert-success alert-dismissible fade show py-1 small">' +
                'Contacts merged successfully.' +
                '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';

            if (typeof window.refreshConstituentList === 'function') {
                window.refreshConstituentList();
            }
        })
        .catch(function (err) {
            var area = document.getElementById('mergeAlertArea');
            area.innerHTML = '<div class="alert alert-danger py-1 small">Merge failed: ' + esc(err.message) + '</div>';
        });
    }

    // ── Alerts ──────────────────────────────────────────────────

    function showImportAlert(msg, type) {
        var area = document.getElementById('importAlertArea');
        if (!area) return;
        area.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-1 small">' +
            msg + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
    }

    function clearImportAlert() {
        var area = document.getElementById('importAlertArea');
        if (area) area.innerHTML = '';
    }

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Event Bindings ──────────────────────────────────────────

    document.addEventListener('DOMContentLoaded', function () {
        // Next button
        var nextBtn = document.getElementById('importNextBtn');
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                if (state.step === 1) {
                    uploadAndParse();
                } else if (state.step === 2) {
                    previewConflicts();
                } else if (state.step === 3) {
                    executeImport();
                }
            });
        }

        // Back button
        var backBtn = document.getElementById('importBackBtn');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                if (state.step === 2) {
                    showStep(1);
                } else if (state.step === 3) {
                    showStep(2);
                }
            });
        }

        // First/Last name toggle
        var chkFL = document.getElementById('chkFirstLast');
        if (chkFL) {
            chkFL.addEventListener('change', function () {
                var row = document.getElementById('firstLastRow');
                if (chkFL.checked) {
                    row.classList.remove('d-none');
                } else {
                    row.classList.add('d-none');
                }
            });
        }

        // Auto-merge button
        var btnAuto = document.getElementById('btnAutoMerge');
        if (btnAuto) {
            btnAuto.addEventListener('click', autoMerge);
        }

        // Do merge button
        var btnMerge = document.getElementById('btnDoMerge');
        if (btnMerge) {
            btnMerge.addEventListener('click', executeMerge);
        }
    });

})();
