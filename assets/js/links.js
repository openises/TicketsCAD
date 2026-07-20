/**
 * NewUI v4.0 - External Links Page
 *
 * Loads links from api/links.php, renders card grid grouped by category.
 * Admin users can add/edit/delete links inline via Bootstrap modals.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ───────────────────────────────────────────────────────
    var allLinks = [];
    var categories = [];
    var filterCategory = 'all';
    var searchTerm = '';
    var isAdmin = false;
    var csrfToken = '';

    // ── DOM References ──────────────────────────────────────────────
    var container = null;
    var loadingSpinner = null;
    var emptyState = null;
    var linkCountEl = null;
    var searchInput = null;
    var categoryFiltersEl = null;
    var alertArea = null;

    // Modal elements (admin only)
    var linkModal = null;
    var linkModalInstance = null;
    var deleteModal = null;
    var deleteModalInstance = null;

    // ── Initialization ──────────────────────────────────────────────
    function init() {
        container = document.getElementById('linksContainer');
        loadingSpinner = document.getElementById('loadingSpinner');
        emptyState = document.getElementById('emptyState');
        linkCountEl = document.getElementById('linkCount');
        searchInput = document.getElementById('linkSearch');
        categoryFiltersEl = document.getElementById('categoryFilters');
        alertArea = document.getElementById('alertArea');

        var adminEl = document.getElementById('isAdmin');
        isAdmin = adminEl && adminEl.value === '1';

        var csrfEl = document.getElementById('csrfToken');
        csrfToken = csrfEl ? csrfEl.value : '';

        // Bind search
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                searchTerm = this.value.toLowerCase().trim();
                render();
            });
        }

        // Bind category filter clicks
        if (categoryFiltersEl) {
            categoryFiltersEl.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-filter]');
                if (!btn) return;
                filterCategory = btn.getAttribute('data-filter');
                // Update active state
                var btns = categoryFiltersEl.querySelectorAll('[data-filter]');
                for (var i = 0; i < btns.length; i++) {
                    btns[i].classList.toggle('active', btns[i] === btn);
                }
                render();
            });
        }

        // Admin: setup modals
        if (isAdmin) {
            linkModal = document.getElementById('linkModal');
            deleteModal = document.getElementById('deleteLinkModal');

            if (linkModal) {
                linkModalInstance = new bootstrap.Modal(linkModal);
            }
            if (deleteModal) {
                deleteModalInstance = new bootstrap.Modal(deleteModal);
            }

            // Add link buttons
            var btnAdd = document.getElementById('btnAddLink');
            var btnAddEmpty = document.getElementById('btnAddLinkEmpty');
            if (btnAdd) btnAdd.addEventListener('click', openAddModal);
            if (btnAddEmpty) btnAddEmpty.addEventListener('click', openAddModal);

            // Save button
            var btnSave = document.getElementById('btnSaveLink');
            if (btnSave) btnSave.addEventListener('click', saveLink);

            // Confirm delete
            var btnDel = document.getElementById('btnConfirmDelete');
            if (btnDel) btnDel.addEventListener('click', confirmDelete);

            // Icon preview
            var iconInput = document.getElementById('editLinkIcon');
            if (iconInput) {
                iconInput.addEventListener('input', function () {
                    var preview = document.getElementById('iconPreview');
                    if (preview) {
                        preview.innerHTML = '<i class="bi ' + escHtml(this.value) + '"></i>';
                    }
                });
            }
        }

        loadLinks();
    }

    // ── API ─────────────────────────────────────────────────────────
    function loadLinks() {
        fetch('api/links.php')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }
                allLinks = data.links || [];
                categories = data.categories || [];
                buildCategoryFilters();
                render();
            })
            .catch(function (err) {
                showAlert('danger', 'Failed to load links: ' + err.message);
            })
            .finally(function () {
                if (loadingSpinner) loadingSpinner.classList.add('d-none');
            });
    }

    function saveLink() {
        var id = document.getElementById('editLinkId').value || '0';
        var title = document.getElementById('editLinkTitle').value.trim();
        var url = document.getElementById('editLinkUrl').value.trim();
        var desc = document.getElementById('editLinkDesc').value.trim();
        var icon = document.getElementById('editLinkIcon').value.trim() || 'bi-link-45deg';
        var cat = document.getElementById('editLinkCategory').value.trim() || 'General';
        var sort = document.getElementById('editLinkSort').value || '0';
        var active = document.getElementById('editLinkActive').checked ? '1' : '0';

        if (!title || !url) {
            showAlert('warning', 'Title and URL are required.');
            return;
        }

        var body = new FormData();
        body.append('action', 'save');
        body.append('csrf_token', csrfToken);
        body.append('id', id);
        body.append('title', title);
        body.append('url', url);
        body.append('description', desc);
        body.append('icon', icon);
        body.append('category', cat);
        body.append('sort_order', sort);
        body.append('active', active);

        fetch('api/links.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }
                if (linkModalInstance) linkModalInstance.hide();
                showAlert('success', data.message || 'Link saved.');
                loadLinks();
            })
            .catch(function (err) {
                showAlert('danger', 'Save failed: ' + err.message);
            });
    }

    function deleteLink(id, name) {
        document.getElementById('deleteLinkId').value = id;
        document.getElementById('deleteLinkName').textContent = name;
        if (deleteModalInstance) deleteModalInstance.show();
    }

    function confirmDelete() {
        var id = document.getElementById('deleteLinkId').value;

        var body = new FormData();
        body.append('action', 'delete');
        body.append('csrf_token', csrfToken);
        body.append('id', id);

        fetch('api/links.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }
                if (deleteModalInstance) deleteModalInstance.hide();
                showAlert('success', data.message || 'Link deleted.');
                loadLinks();
            })
            .catch(function (err) {
                showAlert('danger', 'Delete failed: ' + err.message);
            });
    }

    // ── Rendering ───────────────────────────────────────────────────
    function render() {
        if (!container) return;

        var filtered = allLinks.filter(function (link) {
            if (filterCategory !== 'all' && link.category !== filterCategory) return false;
            if (searchTerm) {
                var hay = (link.title + ' ' + link.description + ' ' + link.url + ' ' + link.category).toLowerCase();
                if (hay.indexOf(searchTerm) === -1) return false;
            }
            return true;
        });

        if (linkCountEl) linkCountEl.textContent = filtered.length;

        if (filtered.length === 0 && allLinks.length === 0) {
            container.classList.add('d-none');
            emptyState.classList.remove('d-none');
            return;
        }

        emptyState.classList.add('d-none');
        container.classList.remove('d-none');

        if (filtered.length === 0) {
            container.innerHTML = '<div class="text-center text-body-secondary py-4">No links match your search.</div>';
            return;
        }

        // Group by category
        var grouped = {};
        for (var i = 0; i < filtered.length; i++) {
            var cat = filtered[i].category || 'General';
            if (!grouped[cat]) grouped[cat] = [];
            grouped[cat].push(filtered[i]);
        }

        var html = '';
        var catKeys = Object.keys(grouped).sort();
        for (var c = 0; c < catKeys.length; c++) {
            var catName = catKeys[c];
            var links = grouped[catName];

            html += '<div class="links-category-section">';
            html += '<div class="links-category-title"><i class="bi bi-folder me-1"></i>' + escHtml(catName) + '</div>';
            html += '<div class="row g-2">';

            for (var j = 0; j < links.length; j++) {
                html += renderCard(links[j]);
            }

            html += '</div></div>';
        }

        container.innerHTML = html;

        // Bind admin actions
        if (isAdmin) {
            var editBtns = container.querySelectorAll('.btn-edit-link');
            for (var e = 0; e < editBtns.length; e++) {
                editBtns[e].addEventListener('click', handleEditClick);
            }
            var delBtns = container.querySelectorAll('.btn-delete-link');
            for (var d = 0; d < delBtns.length; d++) {
                delBtns[d].addEventListener('click', handleDeleteClick);
            }
        }
    }

    function renderCard(link) {
        var inactiveClass = (parseInt(link.active, 10) === 0) ? ' link-card-inactive' : '';
        var iconClass = link.icon || 'bi-link-45deg';
        var adminHtml = '';

        if (isAdmin) {
            adminHtml = '<div class="link-admin-actions">' +
                '<button type="button" class="btn btn-sm btn-outline-secondary btn-edit-link" ' +
                'data-id="' + link.id + '" title="Edit"><i class="bi bi-pencil"></i></button> ' +
                '<button type="button" class="btn btn-sm btn-outline-danger btn-delete-link" ' +
                'data-id="' + link.id + '" data-title="' + escAttr(link.title) + '" title="Delete"><i class="bi bi-trash"></i></button>' +
                '</div>';
        }

        var html = '<div class="col-sm-6 col-md-4 col-lg-3">' +
            '<div class="card link-card position-relative' + inactiveClass + '">' +
            adminHtml +
            '<div class="card-body d-flex align-items-start gap-2">' +
            '<div class="link-card-icon"><i class="bi ' + escHtml(iconClass) + '"></i></div>' +
            '<div class="flex-grow-1 min-w-0">' +
            '<a href="' + escAttr(link.url) + '" class="link-card-title" target="_blank" rel="noopener noreferrer">' +
            escHtml(link.title) + ' <i class="bi bi-box-arrow-up-right" style="font-size:0.65rem"></i></a>' +
            (link.description ? '<p class="link-card-desc">' + escHtml(link.description) + '</p>' : '') +
            '<div class="link-card-url">' + escHtml(truncateUrl(link.url)) + '</div>' +
            '</div></div></div></div>';

        return html;
    }

    function buildCategoryFilters() {
        if (!categoryFiltersEl) return;

        // Keep the "All" button
        var html = '<button type="button" class="btn btn-outline-secondary' +
            (filterCategory === 'all' ? ' active' : '') + '" data-filter="all">All</button>';

        for (var i = 0; i < categories.length; i++) {
            var isActive = (filterCategory === categories[i]) ? ' active' : '';
            html += '<button type="button" class="btn btn-outline-secondary' + isActive + '" ' +
                'data-filter="' + escAttr(categories[i]) + '">' + escHtml(categories[i]) + '</button>';
        }

        categoryFiltersEl.innerHTML = html;

        // Update datalist in modal
        var datalist = document.getElementById('categoryList');
        if (datalist) {
            var dlHtml = '';
            for (var c = 0; c < categories.length; c++) {
                dlHtml += '<option value="' + escAttr(categories[c]) + '">';
            }
            datalist.innerHTML = dlHtml;
        }
    }

    // ── Modal Handlers ──────────────────────────────────────────────
    function openAddModal() {
        document.getElementById('linkModalLabel').textContent = 'Add Link';
        document.getElementById('editLinkId').value = '0';
        document.getElementById('editLinkTitle').value = '';
        document.getElementById('editLinkUrl').value = '';
        document.getElementById('editLinkDesc').value = '';
        document.getElementById('editLinkIcon').value = 'bi-link-45deg';
        document.getElementById('editLinkCategory').value = 'General';
        document.getElementById('editLinkSort').value = '0';
        document.getElementById('editLinkActive').checked = true;
        document.getElementById('iconPreview').innerHTML = '<i class="bi bi-link-45deg"></i>';
        if (linkModalInstance) linkModalInstance.show();
    }

    function handleEditClick(e) {
        var btn = e.currentTarget;
        var id = parseInt(btn.getAttribute('data-id'), 10);
        var link = null;
        for (var i = 0; i < allLinks.length; i++) {
            if (parseInt(allLinks[i].id, 10) === id) {
                link = allLinks[i];
                break;
            }
        }
        if (!link) return;

        document.getElementById('linkModalLabel').textContent = 'Edit Link';
        document.getElementById('editLinkId').value = link.id;
        document.getElementById('editLinkTitle').value = link.title || '';
        document.getElementById('editLinkUrl').value = link.url || '';
        document.getElementById('editLinkDesc').value = link.description || '';
        document.getElementById('editLinkIcon').value = link.icon || 'bi-link-45deg';
        document.getElementById('editLinkCategory').value = link.category || 'General';
        document.getElementById('editLinkSort').value = link.sort_order || '0';
        document.getElementById('editLinkActive').checked = (parseInt(link.active, 10) === 1);
        document.getElementById('iconPreview').innerHTML = '<i class="bi ' + escHtml(link.icon || 'bi-link-45deg') + '"></i>';

        if (linkModalInstance) linkModalInstance.show();
    }

    function handleDeleteClick(e) {
        var btn = e.currentTarget;
        var id = btn.getAttribute('data-id');
        var title = btn.getAttribute('data-title');
        deleteLink(id, title);
    }

    // ── Utilities ───────────────────────────────────────────────────
    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function truncateUrl(url) {
        if (!url) return '';
        // Remove protocol for display
        var display = url.replace(/^https?:\/\//, '');
        if (display.length > 50) {
            display = display.substring(0, 47) + '...';
        }
        return display;
    }

    function showAlert(type, message) {
        if (!alertArea) return;
        alertArea.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show py-2" role="alert">' +
            escHtml(message) +
            '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        // Auto-dismiss success
        if (type === 'success') {
            setTimeout(function () {
                var alert = alertArea.querySelector('.alert');
                if (alert) {
                    var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                    bsAlert.close();
                }
            }, 3000);
        }
    }

    // ── Boot ────────────────────────────────────────────────────────
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
