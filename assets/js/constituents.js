/**
 * NewUI v4.0 - Constituents Page Logic
 *
 * Handles: list display, search, pagination, CRUD operations,
 * phone lookup integration for incident creation.
 * Phone type dropdowns with custom label support.
 * Export, import trigger, merge selection.
 */
(function () {
    'use strict';

    var currentPage = 1;
    var totalPages = 0;
    var selectedId = null;
    var searchTimer = null;
    var checkedIds = [];

    // Standard phone type options (must match HTML select options)
    var PHONE_TYPES = ['Mobile', 'Home', 'Work', 'Day', 'Night', 'Text', 'Fax'];

    function getCsrf() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initPhoneTypeDropdowns();
        loadConstituents();
        bindEvents();

        // Issue #42: State fields are DB-backed <select>s (contact + import default).
        if (window.TCADStates) {
            window.TCADStates.fill(document.getElementById('editState'));
            window.TCADStates.fill(document.getElementById('defaultState'));
        }

        var searchEl = document.getElementById('searchInput');
        if (searchEl) searchEl.focus();
    });

    // Expose for import wizard to call after import completes
    window.refreshConstituentList = function () {
        var search = document.getElementById('searchInput');
        loadConstituents(search ? search.value.trim() || null : null);
    };

    // ── Theme toggle ──
    function initTheme() {
        var btns = document.querySelectorAll('#themeToggle button');
        for (var i = 0; i < btns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function () {
                    var theme = btn.getAttribute('data-theme');
                    document.documentElement.setAttribute('data-bs-theme', theme === 'Night' ? 'dark' : 'light');
                    for (var j = 0; j < btns.length; j++) {
                        btns[j].className = 'btn ' + (btns[j].getAttribute('data-theme') === theme
                            ? (theme === 'Day' ? 'btn-warning' : 'btn-primary')
                            : 'btn-outline-secondary');
                    }
                });
            })(btns[i]);
        }
    }

    // ── Phone Type Dropdown Logic ──
    function initPhoneTypeDropdowns() {
        var selects = document.querySelectorAll('.phone-type-select');
        for (var i = 0; i < selects.length; i++) {
            (function (select) {
                var customInput = select.parentElement.querySelector('.phone-type-custom');
                select.addEventListener('change', function () {
                    if (select.value === 'custom') {
                        customInput.classList.remove('d-none');
                        customInput.focus();
                    } else {
                        customInput.classList.add('d-none');
                        customInput.value = '';
                    }
                });
            })(selects[i]);
        }
    }

    function setPhoneType(selectId, customId, value) {
        var select = document.getElementById(selectId);
        var custom = document.getElementById(customId);
        if (!select || !custom) return;

        if (!value) {
            select.value = '';
            custom.classList.add('d-none');
            custom.value = '';
            return;
        }

        var isStandard = false;
        for (var i = 0; i < PHONE_TYPES.length; i++) {
            if (PHONE_TYPES[i] === value) {
                isStandard = true;
                break;
            }
        }

        if (isStandard) {
            select.value = value;
            custom.classList.add('d-none');
            custom.value = '';
        } else {
            select.value = 'custom';
            custom.classList.remove('d-none');
            custom.value = value;
        }
    }

    function getPhoneType(selectId, customId) {
        var select = document.getElementById(selectId);
        var custom = document.getElementById(customId);
        if (!select) return '';

        if (select.value === 'custom') {
            return custom ? custom.value.trim() : '';
        }
        return select.value;
    }

    // ── Checkbox / Merge Selection ──
    function updateMergeButton() {
        var btn = document.getElementById('btnMerge');
        if (!btn) return;

        if (checkedIds.length === 2) {
            btn.classList.remove('d-none');
        } else {
            btn.classList.add('d-none');
        }
    }

    function updateSelectAllState() {
        var selectAll = document.getElementById('selectAll');
        if (!selectAll) return;

        var checkboxes = document.querySelectorAll('.constituent-check');
        var allChecked = checkboxes.length > 0;
        for (var i = 0; i < checkboxes.length; i++) {
            if (!checkboxes[i].checked) {
                allChecked = false;
                break;
            }
        }
        selectAll.checked = allChecked && checkboxes.length > 0;
    }

    // ── Data Loading ──
    function loadConstituents(search) {
        var url = 'api/constituents.php?page=' + currentPage;
        if (search) url = 'api/constituents.php?search=' + encodeURIComponent(search);

        fetch(url)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderList(data.constituents || []);
                totalPages = data.pages || 1;
                var total = data.total || 0;
                document.getElementById('totalCount').textContent = '(' + total + ' contacts)';
                document.getElementById('pageInfo').textContent =
                    search ? (data.constituents || []).length + ' results' : 'Page ' + currentPage + ' of ' + totalPages;
                document.getElementById('btnPrev').disabled = currentPage <= 1;
                document.getElementById('btnNext').disabled = currentPage >= totalPages;
            })
            .catch(function () {
                document.getElementById('constituentsBody').innerHTML =
                    '<tr><td colspan="6" class="text-center text-danger py-3">Failed to load constituents</td></tr>';
            });
    }

    function renderList(constituents) {
        var tbody = document.getElementById('constituentsBody');

        if (constituents.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-3">No contacts found</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < constituents.length; i++) {
            var c = constituents[i];
            var address = [c.street, c.apartment].filter(Boolean).join(' ');
            var hasNotes = c.miscellaneous ? true : false;
            var phoneDisplay = escHtml(c.phone || '');
            if (c.phone_type) phoneDisplay += ' <span class="text-body-secondary">(' + escHtml(c.phone_type) + ')</span>';
            var isChecked = false;
            for (var ci = 0; ci < checkedIds.length; ci++) {
                if (checkedIds[ci] === c.id) { isChecked = true; break; }
            }
            html += '<tr class="constituent-row' + (c.id == selectedId ? ' table-active' : '') + '" data-id="' + c.id + '">' +
                '<td onclick="event.stopPropagation();">' +
                '<input type="checkbox" class="form-check-input constituent-check" data-id="' + c.id + '"' +
                (isChecked ? ' checked' : '') + '>' +
                '</td>' +
                '<td class="fw-semibold">' + escHtml(c.contact) + '</td>' +
                '<td><small>' + phoneDisplay + '</small></td>' +
                '<td><small>' + escHtml(address) + '</small></td>' +
                '<td><small>' + escHtml(c.city || '') + '</small></td>' +
                '<td>' + (hasNotes ? '<i class="bi bi-chat-left-text text-warning" title="Has notes"></i>' : '') + '</td>' +
                '</tr>';
        }
        tbody.innerHTML = html;

        // Row click handlers
        var rows = tbody.querySelectorAll('.constituent-row');
        for (var j = 0; j < rows.length; j++) {
            (function (row) {
                row.addEventListener('click', function () {
                    var id = parseInt(row.getAttribute('data-id'));
                    selectConstituent(id);
                });
            })(rows[j]);
        }

        // Checkbox handlers
        var checks = tbody.querySelectorAll('.constituent-check');
        for (var k = 0; k < checks.length; k++) {
            (function (chk) {
                chk.addEventListener('change', function () {
                    var cid = parseInt(chk.getAttribute('data-id'));
                    if (chk.checked) {
                        // Add to checked list (max 2 for merge)
                        if (checkedIds.length >= 2) {
                            chk.checked = false;
                            showAlert('You can only select up to 2 contacts for merging.', 'warning');
                            return;
                        }
                        checkedIds.push(cid);
                    } else {
                        var idx = -1;
                        for (var x = 0; x < checkedIds.length; x++) {
                            if (checkedIds[x] === cid) { idx = x; break; }
                        }
                        if (idx >= 0) checkedIds.splice(idx, 1);
                    }
                    updateMergeButton();
                    updateSelectAllState();
                });
            })(checks[k]);
        }
    }

    function selectConstituent(id) {
        selectedId = id;

        var rows = document.querySelectorAll('.constituent-row');
        for (var i = 0; i < rows.length; i++) {
            var rowId = parseInt(rows[i].getAttribute('data-id'));
            if (rowId === id) {
                rows[i].classList.add('table-active');
            } else {
                rows[i].classList.remove('table-active');
            }
        }

        fetch('api/constituents.php?id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.constituent) {
                    renderDetail(data.constituent);
                }
            });
    }

    function renderDetail(c) {
        var detailCard = document.getElementById('detailCard');
        var editCard = document.getElementById('editCard');
        detailCard.classList.remove('d-none');
        editCard.classList.add('d-none');

        document.getElementById('detailTitle').textContent = c.contact;
        document.getElementById('detailActions').style.display = '';
        document.getElementById('detailActions').classList.remove('d-none');

        var phones = [
            { num: c.phone, type: c.phone_type },
            { num: c.phone_2, type: c.phone_2_type },
            { num: c.phone_3, type: c.phone_3_type },
            { num: c.phone_4, type: c.phone_4_type }
        ];
        var activePhones = [];
        for (var i = 0; i < phones.length; i++) {
            if (phones[i].num) activePhones.push(phones[i]);
        }

        var address = [c.street, c.apartment].filter(Boolean).join(' ');
        var cityState = [c.city, c.state, c.post_code].filter(Boolean).join(', ');

        var html = '';

        // Contact info
        html += '<div class="mb-3">';
        html += '<h6 class="text-body-secondary mb-1"><i class="bi bi-telephone me-1"></i>Phone Numbers</h6>';
        for (var p = 0; p < activePhones.length; p++) {
            var phone = activePhones[p];
            var typeLabel = phone.type ? ' <span class="badge bg-secondary bg-opacity-50">' + escHtml(phone.type) + '</span>' : '';
            if (p === 0) {
                html += '<div class="small"><strong>' + escHtml(phone.num) + '</strong>' + typeLabel + '</div>';
            } else {
                html += '<div class="small">' + escHtml(phone.num) + typeLabel + '</div>';
            }
        }
        if (c.email) {
            html += '<div class="small mt-1"><i class="bi bi-envelope me-1"></i>' + escHtml(c.email) + '</div>';
        }
        html += '</div>';

        // Address
        if (address || cityState) {
            html += '<div class="mb-3">';
            html += '<h6 class="text-body-secondary mb-1"><i class="bi bi-geo-alt me-1"></i>Address</h6>';
            if (address) html += '<div class="small">' + escHtml(address) + '</div>';
            if (cityState) html += '<div class="small">' + escHtml(cityState) + '</div>';
            if (c.community) html += '<div class="small text-body-secondary">' + escHtml(c.community) + '</div>';
            html += '</div>';
        }

        // Notes/Warnings
        if (c.miscellaneous) {
            html += '<div class="mb-3">';
            html += '<h6 class="text-danger mb-1"><i class="bi bi-exclamation-triangle me-1"></i>Notes / Warnings</h6>';
            html += '<div class="alert alert-warning py-1 px-2 small mb-0">' + escHtml(c.miscellaneous) + '</div>';
            html += '</div>';
        }

        // Reference
        if (c.reference) {
            html += '<div class="mb-3">';
            html += '<h6 class="text-body-secondary mb-1"><i class="bi bi-hash me-1"></i>Reference</h6>';
            html += '<div class="small">' + escHtml(c.reference) + '</div>';
            html += '</div>';
        }

        // Updated
        if (c.updated) {
            html += '<div class="text-body-tertiary small mt-3">';
            html += '<i class="bi bi-clock me-1"></i>Last updated: ' + escHtml(c.updated);
            html += '</div>';
        }

        document.getElementById('detailContent').innerHTML = html;
    }

    // ── Edit Form ──
    function showEditForm(constituent) {
        var detailCard = document.getElementById('detailCard');
        var editCard = document.getElementById('editCard');
        detailCard.classList.add('d-none');
        editCard.classList.remove('d-none');

        var c = constituent || {};
        document.getElementById('editTitle').textContent = c.id ? 'Edit Contact' : 'New Contact';
        document.getElementById('editId').value = c.id || '';
        document.getElementById('editContact').value = c.contact || '';
        document.getElementById('editPhone').value = c.phone || '';
        document.getElementById('editPhone2').value = c.phone_2 || '';
        document.getElementById('editPhone3').value = c.phone_3 || '';
        document.getElementById('editPhone4').value = c.phone_4 || '';
        document.getElementById('editEmail').value = c.email || '';
        document.getElementById('editStreet').value = c.street || '';
        document.getElementById('editApartment').value = c.apartment || '';
        document.getElementById('editCity').value = c.city || '';
        // Issue #42: State is a DB-backed <select>; inject legacy value if absent.
        if (window.TCADStates) {
            window.TCADStates.setValue(document.getElementById('editState'), c.state || '');
        } else {
            document.getElementById('editState').value = c.state || '';
        }
        document.getElementById('editPostCode').value = c.post_code || '';
        document.getElementById('editCommunity').value = c.community || '';
        document.getElementById('editMisc').value = c.miscellaneous || '';
        document.getElementById('editReference').value = c.reference || '';

        // Phone types
        setPhoneType('editPhoneType', 'editPhoneTypeCustom', c.phone_type || '');
        setPhoneType('editPhone2Type', 'editPhone2TypeCustom', c.phone_2_type || '');
        setPhoneType('editPhone3Type', 'editPhone3TypeCustom', c.phone_3_type || '');
        setPhoneType('editPhone4Type', 'editPhone4TypeCustom', c.phone_4_type || '');

        setTimeout(function () {
            document.getElementById('editContact').focus();
        }, 100);
    }

    function hideEditForm() {
        document.getElementById('detailCard').classList.remove('d-none');
        document.getElementById('editCard').classList.add('d-none');
    }

    function saveConstituent() {
        var body = {
            id: document.getElementById('editId').value || undefined,
            csrf_token: getCsrf(),
            contact: document.getElementById('editContact').value.trim(),
            phone: document.getElementById('editPhone').value.trim(),
            phone_type: getPhoneType('editPhoneType', 'editPhoneTypeCustom'),
            phone_2: document.getElementById('editPhone2').value.trim(),
            phone_2_type: getPhoneType('editPhone2Type', 'editPhone2TypeCustom'),
            phone_3: document.getElementById('editPhone3').value.trim(),
            phone_3_type: getPhoneType('editPhone3Type', 'editPhone3TypeCustom'),
            phone_4: document.getElementById('editPhone4').value.trim(),
            phone_4_type: getPhoneType('editPhone4Type', 'editPhone4TypeCustom'),
            email: document.getElementById('editEmail').value.trim(),
            street: document.getElementById('editStreet').value.trim(),
            apartment: document.getElementById('editApartment').value.trim(),
            city: document.getElementById('editCity').value.trim(),
            state: document.getElementById('editState').value.trim(),
            post_code: document.getElementById('editPostCode').value.trim(),
            community: document.getElementById('editCommunity').value.trim(),
            miscellaneous: document.getElementById('editMisc').value.trim(),
            reference: document.getElementById('editReference').value.trim()
        };

        if (!body.contact) {
            showAlert('Contact name is required.', 'danger');
            return;
        }
        if (!body.phone) {
            showAlert('Phone number is required.', 'danger');
            return;
        }

        fetch('api/constituents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (result.error) {
                showAlert(result.error, 'danger');
                return;
            }
            showAlert('Contact saved successfully.', 'success');
            hideEditForm();
            selectedId = result.constituent ? result.constituent.id : selectedId;
            loadConstituents(document.getElementById('searchInput').value.trim());
            if (selectedId) {
                setTimeout(function () { selectConstituent(selectedId); }, 300);
            }
        })
        .catch(function (err) {
            showAlert('Failed to save: ' + err.message, 'danger');
        });
    }

    function deleteConstituent(id) {
        if (!confirm('Are you sure you want to delete this contact? This cannot be undone.')) return;

        fetch('api/constituents.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'delete', id: id, csrf_token: getCsrf() })
        })
        .then(function (r) { return r.json(); })
        .then(function (result) {
            if (result.error) {
                showAlert(result.error, 'danger');
                return;
            }
            showAlert('Contact deleted.', 'info');
            selectedId = null;
            document.getElementById('detailContent').innerHTML =
                '<div class="text-center text-body-secondary py-4">' +
                '<i class="bi bi-person-lines-fill" style="font-size: 3rem; opacity: 0.3;"></i>' +
                '<p class="mt-2">Click a contact to view details, or create a new one.</p></div>';
            document.getElementById('detailTitle').textContent = 'Select a contact';
            document.getElementById('detailActions').style.display = 'none';
            loadConstituents(document.getElementById('searchInput').value.trim());
        });
    }

    // ── Merge trigger ──
    function startMerge() {
        if (checkedIds.length !== 2) {
            showAlert('Select exactly 2 contacts to merge.', 'warning');
            return;
        }

        // Fetch both records
        var promises = [];
        for (var i = 0; i < checkedIds.length; i++) {
            promises.push(
                fetch('api/constituents.php?id=' + checkedIds[i]).then(function (r) { return r.json(); })
            );
        }

        Promise.all(promises).then(function (results) {
            var a = results[0] && results[0].constituent ? results[0].constituent : null;
            var b = results[1] && results[1].constituent ? results[1].constituent : null;

            if (!a || !b) {
                showAlert('Failed to load selected contacts.', 'danger');
                return;
            }

            if (typeof window.openMergeModal === 'function') {
                window.openMergeModal(a, b);
            }
        });
    }

    // ── Event Bindings ──
    function bindEvents() {
        // Search with debounce
        document.getElementById('searchInput').addEventListener('input', function () {
            var val = this.value.trim();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                currentPage = 1;
                loadConstituents(val || null);
            }, 300);
        });

        // Pagination
        document.getElementById('btnPrev').addEventListener('click', function () {
            if (currentPage > 1) {
                currentPage--;
                loadConstituents();
            }
        });
        document.getElementById('btnNext').addEventListener('click', function () {
            if (currentPage < totalPages) {
                currentPage++;
                loadConstituents();
            }
        });

        // New contact
        document.getElementById('btnNew').addEventListener('click', function () {
            selectedId = null;
            showEditForm(null);
        });

        // Edit button
        document.getElementById('btnEdit').addEventListener('click', function () {
            if (!selectedId) return;
            fetch('api/constituents.php?id=' + selectedId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.constituent) showEditForm(data.constituent);
                });
        });

        // Delete button
        document.getElementById('btnDelete').addEventListener('click', function () {
            if (selectedId) deleteConstituent(selectedId);
        });

        // Cancel edit
        document.getElementById('btnCancelEdit').addEventListener('click', hideEditForm);

        // Save form
        document.getElementById('constituentForm').addEventListener('submit', function (e) {
            e.preventDefault();
            saveConstituent();
        });

        // Export
        var btnExport = document.getElementById('btnExport');
        if (btnExport) {
            btnExport.addEventListener('click', function () {
                var search = document.getElementById('searchInput').value.trim();
                var url = 'api/constituents-export.php';
                if (search) url += '?search=' + encodeURIComponent(search);
                window.location.href = url;
            });
        }

        // Import
        var btnImport = document.getElementById('btnImport');
        if (btnImport) {
            btnImport.addEventListener('click', function () {
                if (typeof window.openImportModal === 'function') {
                    window.openImportModal();
                }
            });
        }

        // Merge
        var btnMerge = document.getElementById('btnMerge');
        if (btnMerge) {
            btnMerge.addEventListener('click', startMerge);
        }

        // Select All checkbox
        var selectAll = document.getElementById('selectAll');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checks = document.querySelectorAll('.constituent-check');
                checkedIds = [];
                if (selectAll.checked && checks.length <= 2) {
                    for (var i = 0; i < checks.length; i++) {
                        checks[i].checked = true;
                        checkedIds.push(parseInt(checks[i].getAttribute('data-id')));
                    }
                } else {
                    selectAll.checked = false;
                    for (var j = 0; j < checks.length; j++) {
                        checks[j].checked = false;
                    }
                }
                updateMergeButton();
            });
        }
    }

    // ── Helpers ──
    function showAlert(message, type) {
        var area = document.getElementById('alertArea');
        area.innerHTML =
            '<div class="alert alert-' + type + ' alert-dismissible fade show py-1 small" role="alert">' +
            message +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
    }

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

})();
