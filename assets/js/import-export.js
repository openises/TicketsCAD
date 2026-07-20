(function () {
    'use strict';

    var csrf = document.getElementById('csrfToken').value;

    var TARGET_ICONS = {
        member:      'bi-person-badge',
        responder:   'bi-truck',
        facility:    'bi-building',
        in_types:    'bi-exclamation-triangle',
        team:        'bi-people-fill',
        constituent: 'bi-people',
        vehicle:     'bi-truck',
        equipment:   'bi-box-seam',
        user:        'bi-person-lock',
        incident:    'bi-journal-text'
    };

    // ── State ──
    var selectedTarget = '';
    var csvBase64 = '';
    var previewData = null;
    var currentMap = {};
    var importableColumns = [];  // DB column definitions for current target
    var errorRowsData = [];      // error_rows from validation
    var importLog = [];          // row-by-row log for CSV download

    // ═══════════════════════════════════════════════════════════
    //  IMPORT WIZARD
    // ═══════════════════════════════════════════════════════════

    // Load targets
    function loadTargets() {
        fetch('api/import-export.php?targets=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var targets = data.targets || {};
                renderTargetCards(targets);
                renderExportOptions(targets);
            });
    }

    function renderTargetCards(targets) {
        var el = document.getElementById('targetCards');
        var html = '';
        var keys = Object.keys(targets);
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var icon = TARGET_ICONS[key] || 'bi-table';
            html += '<div class="col-6 col-md-3">';
            html += '<div class="target-card" data-target="' + key + '">';
            html += '<div class="icon"><i class="bi ' + icon + '"></i></div>';
            html += '<div class="fw-semibold small">' + esc(targets[key]) + '</div>';
            html += '</div></div>';
        }
        el.innerHTML = html;

        // Bind clicks
        var cards = el.querySelectorAll('.target-card');
        for (var j = 0; j < cards.length; j++) {
            cards[j].addEventListener('click', function () {
                var all = document.querySelectorAll('.target-card');
                for (var k = 0; k < all.length; k++) all[k].classList.remove('selected');
                this.classList.add('selected');
                selectedTarget = this.getAttribute('data-target');
                document.getElementById('btnStep1Next').disabled = false;
            });
        }
    }

    function renderExportOptions(targets) {
        var sel = document.getElementById('exportTarget');
        var html = '';
        var keys = Object.keys(targets);
        for (var i = 0; i < keys.length; i++) {
            html += '<option value="' + keys[i] + '">' + esc(targets[keys[i]]) + '</option>';
        }
        sel.innerHTML = html;
    }

    // ── Step navigation ──
    function goToStep(step) {
        var steps = document.querySelectorAll('.imex-step');
        for (var i = 0; i < steps.length; i++) {
            steps[i].classList.remove('active');
        }
        document.getElementById('step' + step).classList.add('active');

        for (var j = 1; j <= 4; j++) {
            var dot = document.getElementById('stepDot' + j);
            dot.className = 'step-dot';
            if (j < step) dot.classList.add('done');
            if (j === step) dot.classList.add('active');
        }
    }

    // Step 1 → 2
    document.getElementById('btnStep1Next').addEventListener('click', function () {
        if (!selectedTarget) return;
        goToStep(2);
    });

    // Step 2 → 1
    document.getElementById('btnStep2Back').addEventListener('click', function () {
        goToStep(1);
    });

    // Step 3 → 2
    document.getElementById('btnStep3Back').addEventListener('click', function () {
        goToStep(2);
    });

    // File input change
    document.getElementById('csvFile').addEventListener('change', function () {
        document.getElementById('btnStep2Upload').disabled = !this.files.length;
    });

    // Upload button
    document.getElementById('btnStep2Upload').addEventListener('click', function () {
        var fileInput = document.getElementById('csvFile');
        if (!fileInput.files.length) return;

        var statusEl = document.getElementById('uploadStatus');
        statusEl.style.display = '';
        statusEl.innerHTML = '<div class="d-flex align-items-center gap-2 small text-primary"><div class="spinner-border spinner-border-sm"></div>Uploading and parsing...</div>';

        var formData = new FormData();
        formData.append('file', fileInput.files[0]);
        formData.append('target', selectedTarget);

        fetch('api/import-export.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf },
            body: formData
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.error) {
                statusEl.innerHTML = '<div class="alert alert-danger py-1 small">' + esc(data.error) + '</div>';
                return;
            }
            previewData = data;
            csvBase64 = data.csv_data || '';
            currentMap = data.auto_map || {};
            renderMappingStep(data);
            goToStep(3);
            statusEl.style.display = 'none';
        })
        .catch(function (err) {
            statusEl.innerHTML = '<div class="alert alert-danger py-1 small">Upload failed: ' + esc(err.message) + '</div>';
        });
    });

    // ── Step 3: Mapping ──
    function renderMappingStep(data) {
        var headers = data.headers || [];
        var autoMap = data.auto_map || {};
        var preview = data.preview || [];
        var validation = data.validation || {};

        // Get importable columns for this target
        fetch('api/import-export.php?config=' + selectedTarget, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (configData) {
                importableColumns = configData.importable || [];
                renderColumnMapping(headers, autoMap, importableColumns);
                renderPreview(headers, preview);
                renderValidationSummary(validation, data.row_count);
                renderErrorRowEditor(validation.error_rows || [], importableColumns);
            });
    }

    function renderColumnMapping(csvHeaders, autoMap, importable) {
        var container = document.getElementById('mappingContainer');
        var html = '';

        for (var i = 0; i < csvHeaders.length; i++) {
            var csvCol = csvHeaders[i];
            var mapped = autoMap[csvCol] || '';

            html += '<div class="mapping-row">';
            // CSV column label — colored info
            html += '<span class="fw-semibold text-info" style="min-width:140px;font-size:0.78rem" title="CSV file column">';
            html += '<i class="bi bi-file-earmark-spreadsheet" style="font-size:0.65rem;opacity:0.6"></i> ';
            html += esc(csvCol);
            html += '</span>';
            html += '<span class="mapping-arrow"><i class="bi bi-arrow-right"></i></span>';

            // DB column dropdown — colored success
            html += '<select class="form-select form-select-sm col-map-select" data-csv-col="' + esc(csvCol) + '" style="max-width:200px;border-color:var(--bs-success);font-size:0.78rem">';
            html += '<option value="">(skip)</option>';
            for (var j = 0; j < importable.length; j++) {
                var imp = importable[j];
                var sel = (mapped === imp.db_column) ? ' selected' : '';
                var req = imp.required ? ' *' : '';
                html += '<option value="' + imp.db_column + '"' + sel + '>' + esc(imp.label) + req + '</option>';
            }
            html += '</select>';

            if (mapped) {
                html += '<i class="bi bi-check-circle-fill text-success" style="font-size:0.7rem"></i>';
            }
            html += '</div>';
        }

        container.innerHTML = html;

        // Bind change to update mapping
        var selects = container.querySelectorAll('.col-map-select');
        for (var k = 0; k < selects.length; k++) {
            selects[k].addEventListener('change', function () {
                var csvCol = this.getAttribute('data-csv-col');
                if (this.value) {
                    currentMap[csvCol] = this.value;
                } else {
                    delete currentMap[csvCol];
                }
            });
        }
    }

    function renderPreview(headers, rows) {
        var headEl = document.getElementById('previewHead');
        var bodyEl = document.getElementById('previewBody');

        var headHtml = '<tr>';
        for (var i = 0; i < headers.length; i++) {
            headHtml += '<th>' + esc(headers[i]) + '</th>';
        }
        headHtml += '</tr>';
        headEl.innerHTML = headHtml;

        var bodyHtml = '';
        for (var r = 0; r < rows.length; r++) {
            bodyHtml += '<tr>';
            for (var c = 0; c < headers.length; c++) {
                bodyHtml += '<td>' + esc(rows[r][headers[c]] || '') + '</td>';
            }
            bodyHtml += '</tr>';
        }
        bodyEl.innerHTML = bodyHtml;

        document.getElementById('previewCount').textContent = '(showing ' + rows.length + ' of ' + (previewData ? previewData.row_count : '?') + ')';
    }

    function renderValidationSummary(validation, totalRows) {
        var el = document.getElementById('validationSummary');
        var html = '<div class="d-flex gap-3 small">';
        html += '<span class="text-success"><i class="bi bi-check-circle me-1"></i>' + validation.valid_count + ' valid</span>';
        if (validation.error_count > 0) {
            html += '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' + validation.error_count + ' errors</span>';
        }
        if (validation.warning_count > 0) {
            html += '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>' + validation.warning_count + ' warnings</span>';
        }
        html += '</div>';

        if (validation.errors && validation.errors.length > 0) {
            html += '<div class="mt-1 small text-danger">';
            for (var i = 0; i < Math.min(validation.errors.length, 5); i++) {
                html += '<div>' + esc(validation.errors[i]) + '</div>';
            }
            if (validation.errors.length > 5) {
                html += '<div>... and ' + (validation.errors.length - 5) + ' more</div>';
            }
            html += '</div>';
        }

        el.innerHTML = html;
        document.getElementById('importRowCount').textContent = validation.valid_count || totalRows;
    }

    // ═══════════════════════════════════════════════════════════
    //  ERROR ROW EDITOR
    // ═══════════════════════════════════════════════════════════

    function renderErrorRowEditor(errorRows, importable) {
        errorRowsData = errorRows || [];
        var section = document.getElementById('errorReviewSection');
        var editor = document.getElementById('errorRowEditor');
        var badge = document.getElementById('errorRowCountBadge');
        var btnFix = document.getElementById('btnImportFixed');

        if (!errorRows || errorRows.length === 0) {
            section.style.display = 'none';
            return;
        }

        section.style.display = '';
        badge.textContent = errorRows.length;

        // Build a map of db_column -> label for display
        var colLabels = {};
        var colRequired = {};
        for (var c = 0; c < importable.length; c++) {
            colLabels[importable[c].db_column] = importable[c].label;
            colRequired[importable[c].db_column] = importable[c].required;
        }

        // Get the list of mapped DB columns (from currentMap)
        var mappedDbCols = [];
        var mapKeys = Object.keys(currentMap);
        for (var m = 0; m < mapKeys.length; m++) {
            var dbCol = currentMap[mapKeys[m]];
            if (mappedDbCols.indexOf(dbCol) === -1) {
                mappedDbCols.push(dbCol);
            }
        }

        var html = '';
        for (var i = 0; i < errorRows.length; i++) {
            var er = errorRows[i];
            var cardId = 'errRow' + i;

            html += '<div class="error-row-card" id="' + cardId + '" data-idx="' + i + '">';
            html += '<div class="d-flex justify-content-between align-items-center mb-1">';
            html += '<div class="error-msg"><i class="bi bi-exclamation-circle me-1"></i><strong>Row ' + er.row_num + ':</strong> ';
            for (var e = 0; e < er.errors.length; e++) {
                html += esc(er.errors[e]);
                if (e < er.errors.length - 1) html += '; ';
            }
            html += '</div>';
            html += '<button class="btn btn-xs btn-outline-secondary skip-error-row" data-idx="' + i + '" title="Skip this row"><i class="bi bi-x"></i></button>';
            html += '</div>';

            // Editable fields — show all mapped columns
            html += '<div class="error-row-fields">';
            for (var f = 0; f < mappedDbCols.length; f++) {
                var dc = mappedDbCols[f];
                var label = colLabels[dc] || dc;
                var isReq = colRequired[dc];
                // Get value: try mapped data first, then search original CSV row
                var val = '';
                if (er.mapped && er.mapped[dc] !== undefined && er.mapped[dc] !== null) {
                    val = er.mapped[dc];
                } else {
                    // Search the original CSV row for this mapping
                    for (var mk = 0; mk < mapKeys.length; mk++) {
                        if (currentMap[mapKeys[mk]] === dc && er.original && er.original[mapKeys[mk]] !== undefined) {
                            val = er.original[mapKeys[mk]] || '';
                            break;
                        }
                    }
                }

                html += '<div class="field-group">';
                html += '<label>' + esc(label) + (isReq ? ' *' : '') + '</label>';
                html += '<input type="text" class="form-control form-control-sm err-field-input" ';
                html += 'data-row="' + i + '" data-col="' + esc(dc) + '" ';
                html += 'value="' + esc(val) + '"';
                if (isReq && !val) html += ' class="form-control form-control-sm err-field-input is-invalid"';
                html += '>';
                html += '</div>';
            }
            html += '</div>';
            html += '</div>';
        }

        editor.innerHTML = html;

        // Bind skip buttons
        var skipBtns = editor.querySelectorAll('.skip-error-row');
        for (var s = 0; s < skipBtns.length; s++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var idx = parseInt(btn.getAttribute('data-idx'), 10);
                    var card = document.getElementById('errRow' + idx);
                    if (card) {
                        card.style.display = 'none';
                        card.setAttribute('data-skipped', '1');
                    }
                    updateFixedCount();
                });
            })(skipBtns[s]);
        }

        // Bind field changes — validate on input
        var inputs = editor.querySelectorAll('.err-field-input');
        for (var inp = 0; inp < inputs.length; inp++) {
            inputs[inp].addEventListener('input', function () {
                var row = parseInt(this.getAttribute('data-row'), 10);
                var col = this.getAttribute('data-col');
                var val = this.value.trim();

                // Update the data
                if (!errorRowsData[row].fixed) {
                    errorRowsData[row].fixed = {};
                    // Copy mapped data as base
                    var base = errorRowsData[row].mapped || {};
                    var baseKeys = Object.keys(base);
                    for (var bk = 0; bk < baseKeys.length; bk++) {
                        errorRowsData[row].fixed[baseKeys[bk]] = base[baseKeys[bk]];
                    }
                }
                errorRowsData[row].fixed[col] = val || null;

                // Visual validation for required
                if (colRequired[col] && !val) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                }

                // Check if all required fields are satisfied for this row
                var card = document.getElementById('errRow' + row);
                var allGood = true;
                var rowInputs = card.querySelectorAll('.err-field-input');
                for (var ri = 0; ri < rowInputs.length; ri++) {
                    var riCol = rowInputs[ri].getAttribute('data-col');
                    if (colRequired[riCol] && !rowInputs[ri].value.trim()) {
                        allGood = false;
                    }
                }

                if (allGood) {
                    card.classList.add('fixed');
                    card.querySelector('.error-msg').innerHTML = '<i class="bi bi-check-circle me-1"></i><strong>Row ' + errorRowsData[row].row_num + ':</strong> Fixed — ready to import';
                } else {
                    card.classList.remove('fixed');
                }

                updateFixedCount();
            });
        }

        // Populate initial fixed data from original values
        for (var ir = 0; ir < errorRowsData.length; ir++) {
            errorRowsData[ir].fixed = {};
            var baseMapped = errorRowsData[ir].mapped || {};
            var bmKeys = Object.keys(baseMapped);
            for (var bmi = 0; bmi < bmKeys.length; bmi++) {
                errorRowsData[ir].fixed[bmKeys[bmi]] = baseMapped[bmKeys[bmi]];
            }
            // Also pull in values from original CSV row for unmapped fields
            for (var mk2 = 0; mk2 < mapKeys.length; mk2++) {
                var dc2 = currentMap[mapKeys[mk2]];
                if (errorRowsData[ir].fixed[dc2] === undefined || errorRowsData[ir].fixed[dc2] === null) {
                    if (errorRowsData[ir].original && errorRowsData[ir].original[mapKeys[mk2]]) {
                        errorRowsData[ir].fixed[dc2] = errorRowsData[ir].original[mapKeys[mk2]];
                    }
                }
            }
        }

        updateFixedCount();
    }

    function updateFixedCount() {
        var btnFix = document.getElementById('btnImportFixed');
        var fixedCount = 0;
        var cards = document.querySelectorAll('.error-row-card');
        for (var i = 0; i < cards.length; i++) {
            if (cards[i].getAttribute('data-skipped') === '1') continue;
            if (cards[i].classList.contains('fixed')) fixedCount++;
        }
        btnFix.disabled = fixedCount === 0;
        btnFix.innerHTML = '<i class="bi bi-check-circle me-1"></i>Import ' + fixedCount + ' Fixed Row' + (fixedCount !== 1 ? 's' : '');
    }

    // ── Import Fixed Rows button ──
    document.getElementById('btnImportFixed').addEventListener('click', function () {
        var fixedRows = [];
        for (var i = 0; i < errorRowsData.length; i++) {
            var card = document.getElementById('errRow' + i);
            if (!card || card.getAttribute('data-skipped') === '1') continue;
            if (!card.classList.contains('fixed')) continue;

            // Read current input values
            var row = {};
            var inputs = card.querySelectorAll('.err-field-input');
            for (var inp = 0; inp < inputs.length; inp++) {
                var col = inputs[inp].getAttribute('data-col');
                var val = inputs[inp].value.trim();
                row[col] = val || null;
            }
            fixedRows.push(row);
        }

        if (fixedRows.length === 0) {
            alert('No fixed rows to import.');
            return;
        }

        var btn = this;
        var mode = document.querySelector('input[name="importMode"]:checked').value;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';

        fetch('api/import-export.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({
                action: 'import_fixed',
                target: selectedTarget,
                rows: fixedRows,
                mode: mode
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Import Fixed Rows';

            if (data.error) {
                alert('Import failed: ' + data.error);
                return;
            }

            // Show mini result
            var msg = 'Fixed rows imported: ' + data.inserted + ' inserted, ' + data.updated + ' updated';
            if (data.errors && data.errors.length > 0) {
                msg += ', ' + data.errors.length + ' errors';
            }
            alert(msg);

            // Add to import log
            for (var i = 0; i < fixedRows.length; i++) {
                importLog.push({
                    row: 'Fixed',
                    status: 'Imported (fixed)',
                    data: fixedRows[i],
                    errors: ''
                });
            }

            // Hide the fixed rows
            var cards = document.querySelectorAll('.error-row-card.fixed');
            for (var c = 0; c < cards.length; c++) {
                cards[c].style.display = 'none';
                cards[c].setAttribute('data-skipped', '1');
            }
            updateFixedCount();
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Import Fixed Rows';
            alert('Import failed: ' + err.message);
        });
    });

    // ── Skip All Error Rows ──
    document.getElementById('btnSkipErrors').addEventListener('click', function () {
        var cards = document.querySelectorAll('.error-row-card');
        for (var i = 0; i < cards.length; i++) {
            cards[i].style.display = 'none';
            cards[i].setAttribute('data-skipped', '1');
        }
        updateFixedCount();
    });

    // ═══════════════════════════════════════════════════════════
    //  IMPORT EXECUTION (Step 3 → 4)
    // ═══════════════════════════════════════════════════════════

    document.getElementById('btnStep3Import').addEventListener('click', function () {
        if (!csvBase64 || Object.keys(currentMap).length === 0) {
            alert('No column mappings configured');
            return;
        }

        var mode = document.querySelector('input[name="importMode"]:checked').value;
        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Importing...';

        fetch('api/import-export.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf
            },
            body: JSON.stringify({
                action: 'import',
                target: selectedTarget,
                csv_data: csvBase64,
                column_map: currentMap,
                mode: mode
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Import';

            if (data.error) {
                document.getElementById('importResults').innerHTML =
                    '<div class="alert alert-danger">' + esc(data.error) + '</div>';
                goToStep(4);
                return;
            }

            // Build import log
            buildImportLog(data);
            renderImportResults(data);
            goToStep(4);
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-check-circle me-1"></i>Import';
            alert('Import failed: ' + err.message);
        });
    });

    function buildImportLog(data) {
        importLog = [];
        var ts = new Date().toISOString();

        // Summary header
        importLog.push({
            row: 'SUMMARY',
            status: 'Target: ' + selectedTarget + ' | Date: ' + ts,
            data: {},
            errors: 'Inserted: ' + data.inserted + ' | Updated: ' + data.updated + ' | Skipped: ' + data.skipped + ' | Errors: ' + (data.errors ? data.errors.length : 0)
        });

        // Valid rows
        for (var i = 0; i < (data.valid_rows || 0); i++) {
            importLog.push({
                row: i + 1,
                status: 'OK',
                data: {},
                errors: ''
            });
        }

        // Validation errors
        if (data.validation_errors) {
            for (var e = 0; e < data.validation_errors.length; e++) {
                importLog.push({
                    row: '',
                    status: 'VALIDATION ERROR',
                    data: {},
                    errors: data.validation_errors[e]
                });
            }
        }

        // Import errors
        if (data.errors) {
            for (var ie = 0; ie < data.errors.length; ie++) {
                importLog.push({
                    row: '',
                    status: 'IMPORT ERROR',
                    data: {},
                    errors: data.errors[ie]
                });
            }
        }

        // Warnings
        if (data.validation_warnings) {
            for (var w = 0; w < data.validation_warnings.length; w++) {
                importLog.push({
                    row: '',
                    status: 'WARNING',
                    data: {},
                    errors: data.validation_warnings[w]
                });
            }
        }
    }

    function renderImportResults(data) {
        var el = document.getElementById('importResults');
        var html = '<div class="card">';
        html += '<div class="card-header py-2"><i class="bi bi-check-circle-fill text-success me-2"></i><strong>Import Complete</strong></div>';
        html += '<div class="card-body">';

        html += '<div class="row g-3 mb-3">';
        html += '<div class="col-3 text-center">';
        html += '<div class="fs-3 fw-bold text-success">' + data.inserted + '</div>';
        html += '<div class="small text-body-secondary">Inserted</div>';
        html += '</div>';
        html += '<div class="col-3 text-center">';
        html += '<div class="fs-3 fw-bold text-primary">' + data.updated + '</div>';
        html += '<div class="small text-body-secondary">Updated</div>';
        html += '</div>';
        html += '<div class="col-3 text-center">';
        html += '<div class="fs-3 fw-bold text-secondary">' + data.skipped + '</div>';
        html += '<div class="small text-body-secondary">Skipped</div>';
        html += '</div>';
        html += '<div class="col-3 text-center">';
        html += '<div class="fs-3 fw-bold text-danger">' + (data.errors ? data.errors.length : 0) + '</div>';
        html += '<div class="small text-body-secondary">Errors</div>';
        html += '</div>';
        html += '</div>';

        if (data.errors && data.errors.length > 0) {
            html += '<div class="alert alert-danger py-2 small">';
            html += '<strong>Errors:</strong><br>';
            for (var i = 0; i < Math.min(data.errors.length, 10); i++) {
                html += esc(data.errors[i]) + '<br>';
            }
            if (data.errors.length > 10) {
                html += '... and ' + (data.errors.length - 10) + ' more';
            }
            html += '</div>';
        }

        if (data.validation_warnings && data.validation_warnings.length > 0) {
            html += '<div class="alert alert-warning py-2 small">';
            html += '<strong>Warnings:</strong><br>';
            for (var w = 0; w < Math.min(data.validation_warnings.length, 5); w++) {
                html += esc(data.validation_warnings[w]) + '<br>';
            }
            html += '</div>';
        }

        html += '</div></div>';
        el.innerHTML = html;

        // Show download log button
        var logBtn = document.getElementById('btnDownloadLog');
        if (logBtn) logBtn.style.display = '';
    }

    // ═══════════════════════════════════════════════════════════
    //  IMPORT LOG DOWNLOAD
    // ═══════════════════════════════════════════════════════════

    document.getElementById('btnDownloadLog').addEventListener('click', function () {
        if (importLog.length === 0) {
            alert('No import log available.');
            return;
        }

        var csv = 'Row,Status,Details\r\n';
        for (var i = 0; i < importLog.length; i++) {
            var entry = importLog[i];
            csv += csvQuote(String(entry.row)) + ',';
            csv += csvQuote(entry.status) + ',';
            csv += csvQuote(entry.errors) + '\r\n';
        }

        var filename = selectedTarget + '_import_log_' + formatDateForFilename() + '.csv';
        downloadCSV(csv, filename);
    });

    function csvQuote(str) {
        if (!str) return '""';
        // Escape double quotes and wrap in quotes
        return '"' + String(str).replace(/"/g, '""') + '"';
    }

    function formatDateForFilename() {
        var d = new Date();
        return d.getFullYear() +
            ('0' + (d.getMonth() + 1)).slice(-2) +
            ('0' + d.getDate()).slice(-2) + '_' +
            ('0' + d.getHours()).slice(-2) +
            ('0' + d.getMinutes()).slice(-2) +
            ('0' + d.getSeconds()).slice(-2);
    }

    function downloadCSV(csvContent, filename) {
        var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    // ═══════════════════════════════════════════════════════════
    //  START OVER
    // ═══════════════════════════════════════════════════════════

    document.getElementById('btnStartOver').addEventListener('click', function () {
        selectedTarget = '';
        csvBase64 = '';
        previewData = null;
        currentMap = {};
        importableColumns = [];
        errorRowsData = [];
        importLog = [];
        document.getElementById('csvFile').value = '';
        document.getElementById('uploadStatus').style.display = 'none';
        document.getElementById('btnStep1Next').disabled = true;
        document.getElementById('btnStep2Upload').disabled = true;
        document.getElementById('btnDownloadLog').style.display = 'none';
        var cards = document.querySelectorAll('.target-card');
        for (var i = 0; i < cards.length; i++) cards[i].classList.remove('selected');
        goToStep(1);
    });

    // ═══════════════════════════════════════════════════════════
    //  EXPORT
    // ═══════════════════════════════════════════════════════════

    document.getElementById('btnExport').addEventListener('click', function () {
        var target = document.getElementById('exportTarget').value;
        var search = document.getElementById('exportSearch').value;
        var url = 'api/import-export.php?export=' + encodeURIComponent(target);
        if (search) url += '&search=' + encodeURIComponent(search);
        window.location.href = url;
    });

    // ═══════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════

    function esc(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    // ── Init ──
    loadTargets();

})();
