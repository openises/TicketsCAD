/**
 * NewUI v4.0 - SOP Wiki
 *
 * Handles the SOP wiki page tree, viewer, editor, and revision history.
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    // ── State ──
    var pages = [];
    var currentPage = null;
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var previewVisible = false;
    var currentRevisionData = null;

    // ── DOM refs ──
    var pageTree       = document.getElementById('pageTree');
    var treeSearch     = document.getElementById('treeSearch');
    var viewMode       = document.getElementById('viewMode');
    var editMode       = document.getElementById('editMode');
    var historyMode    = document.getElementById('historyMode');
    var breadcrumb     = document.getElementById('breadcrumb');
    var pageTitle      = document.getElementById('pageTitle');
    var pageContent    = document.getElementById('pageContent');
    var pageMeta       = document.getElementById('pageMeta');
    var viewActions    = document.getElementById('viewActions');
    var alertArea      = document.getElementById('alertArea');
    var editPageId     = document.getElementById('editPageId');
    var editTitle      = document.getElementById('editTitle');
    var editSlug       = document.getElementById('editSlug');
    var editParent     = document.getElementById('editParent');
    var editContent    = document.getElementById('editContent');
    var editSummary    = document.getElementById('editSummary');
    var editPreview    = document.getElementById('editPreview');
    var editorCol      = document.getElementById('editorCol');
    var previewCol     = document.getElementById('previewCol');
    var editModeLabel  = document.getElementById('editModeLabel');
    var historyBody    = document.getElementById('historyBody');
    var revisionViewer = document.getElementById('revisionViewer');
    var revisionLabel  = document.getElementById('revisionLabel');
    var revisionContent = document.getElementById('revisionContent');
    var deleteModal    = null; // initialized after DOM ready

    // ── Helpers ──

    function showAlert(msg, type) {
        var cls = type || 'info';
        alertArea.innerHTML = '<div class="alert alert-' + cls + ' alert-dismissible fade show py-2 small" role="alert">' +
            msg + '<button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button></div>';
        setTimeout(function () {
            var el = alertArea.querySelector('.alert');
            if (el) { el.remove(); }
        }, 5000);
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function renderMarkdown(md) {
        if (typeof marked !== 'undefined') {
            return marked.parse(md || '');
        }
        return '<pre>' + escHtml(md) + '</pre>';
    }

    /**
     * Check if a slug exists in the loaded pages list.
     */
    function pageExists(slug) {
        for (var i = 0; i < pages.length; i++) {
            if (pages[i].slug === slug) return true;
        }
        return false;
    }

    /**
     * Bind wiki-style navigation on links inside rendered markdown content.
     * - Links starting with # are treated as internal wiki links (e.g. [Fire SOP](#structure-fire))
     * - Links that are just a slug with no protocol are also treated as wiki links
     * - Missing pages get a dashed red "create" style
     */
    function bindWikiLinks(container) {
        var links = container.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            (function (link) {
                var href = link.getAttribute('href') || '';

                // Determine if this is an internal wiki link
                var slug = '';
                if (href.charAt(0) === '#') {
                    // Hash link: #some-slug
                    slug = href.substring(1);
                } else if (href.indexOf('://') === -1 && href.indexOf('/') === -1 && href.indexOf('.') === -1 && href.indexOf('@') === -1 && href.length > 0) {
                    // Bare slug with no protocol, path separators, dots, or @
                    slug = href;
                }

                if (!slug) return; // External link, leave as-is

                // Style missing page links
                if (!pageExists(slug)) {
                    link.classList.add('sop-missing-link');
                    link.setAttribute('title', 'Page does not exist — click to create');
                } else {
                    link.classList.add('sop-wiki-link');
                }

                // Intercept click to navigate via wiki system
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.location.hash = slug;
                    loadPageBySlug(slug);
                });
            })(links[i]);
        }
    }

    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (res) {
            return res.json();
        });
    }

    function apiPost(url, data) {
        data.csrf_token = csrfToken;
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function (res) {
            return res.json();
        });
    }

    // ── Page Tree ──

    function loadPages() {
        apiGet('api/sop-pages.php').then(function (data) {
            if (data.error) {
                pageTree.innerHTML = '<div class="text-danger small p-2">' + escHtml(data.error) + '</div>';
                return;
            }
            pages = data.pages || [];
            renderTree();

            // Load page from URL hash
            var hash = window.location.hash.replace('#', '');
            if (hash) {
                loadPageBySlug(hash);
            } else if (pages.length > 0) {
                // Load first root page (home)
                var rootPages = pages.filter(function (p) { return !p.parent_id; });
                if (rootPages.length > 0) {
                    loadPageBySlug(rootPages[0].slug);
                }
            }
        }).catch(function (err) {
            pageTree.innerHTML = '<div class="text-danger small p-2">Failed to load pages</div>';
        });
    }

    function buildTree(parentId) {
        var children = pages.filter(function (p) {
            return parentId === null ? !p.parent_id : p.parent_id === parentId;
        });
        children.sort(function (a, b) {
            return a.sort_order - b.sort_order || a.title.localeCompare(b.title);
        });
        return children;
    }

    function renderTree(filter) {
        var filterLower = (filter || '').toLowerCase();
        var html = renderTreeLevel(null, 0, filterLower);
        if (!html) {
            html = '<div class="text-body-secondary small p-2 text-center">No pages found</div>';
        }
        pageTree.innerHTML = html;

        // Bind click events
        var links = pageTree.querySelectorAll('.sop-tree-link');
        for (var i = 0; i < links.length; i++) {
            (function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    var slug = link.getAttribute('data-slug');
                    loadPageBySlug(slug);
                });
            })(links[i]);
        }

        // Bind toggle events
        var toggles = pageTree.querySelectorAll('.sop-tree-toggle');
        for (var j = 0; j < toggles.length; j++) {
            (function (toggle) {
                toggle.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var childList = toggle.parentElement.parentElement.querySelector('.sop-tree-children');
                    if (childList) {
                        childList.classList.toggle('d-none');
                        var icon = toggle.querySelector('i');
                        if (icon) {
                            icon.className = childList.classList.contains('d-none')
                                ? 'bi bi-chevron-right'
                                : 'bi bi-chevron-down';
                        }
                    }
                });
            })(toggles[j]);
        }
    }

    function renderTreeLevel(parentId, depth, filter) {
        var children = buildTree(parentId);
        if (children.length === 0) return '';

        var html = '';
        for (var i = 0; i < children.length; i++) {
            var page = children[i];
            var childHtml = renderTreeLevel(page.id, depth + 1, filter);
            var hasChildren = childHtml !== '';
            var matchesFilter = !filter || page.title.toLowerCase().indexOf(filter) !== -1;

            // If filtering, show only matching pages and their ancestors
            if (filter && !matchesFilter && !childHtml) continue;

            var isActive = currentPage && currentPage.slug === page.slug;
            var activeClass = isActive ? ' sop-tree-active' : '';

            html += '<div class="sop-tree-item' + activeClass + '">';
            html += '<div class="sop-tree-row" style="padding-left: ' + (depth * 16 + 8) + 'px;">';

            if (hasChildren) {
                html += '<span class="sop-tree-toggle"><i class="bi bi-chevron-down"></i></span>';
            } else {
                html += '<span class="sop-tree-icon"><i class="bi bi-file-text"></i></span>';
            }

            html += '<a href="#' + escHtml(page.slug) + '" class="sop-tree-link" data-slug="' + escHtml(page.slug) + '">';
            html += escHtml(page.title);
            html += '</a>';
            html += '</div>';

            if (hasChildren) {
                html += '<div class="sop-tree-children">' + childHtml + '</div>';
            }

            html += '</div>';
        }
        return html;
    }

    // ── Page Loading ──

    function loadPageBySlug(slug) {
        apiGet('api/sop-pages.php?slug=' + encodeURIComponent(slug)).then(function (data) {
            if (data.error) {
                // Page not found — open editor to create it (wiki-style)
                if (data.error.indexOf('not found') !== -1) {
                    openEditorForNewSlug(slug);
                    return;
                }
                showAlert(data.error, 'danger');
                return;
            }
            displayPage(data.page, data.breadcrumb);
        }).catch(function () {
            showAlert('Failed to load page', 'danger');
        });
    }

    /**
     * Open the editor pre-filled for a new page from a missing wiki link.
     * Converts the slug back to a readable title.
     */
    function openEditorForNewSlug(slug) {
        // Convert slug to title: "hazmat-response" -> "Hazmat Response"
        var title = slug.replace(/-/g, ' ').replace(/\b\w/g, function (c) {
            return c.toUpperCase();
        });

        showMode('edit');
        editModeLabel.textContent = 'Create: ' + title;
        editPageId.value = '';
        editTitle.value = title;
        editSlug.value = slug;
        editContent.value = '# ' + title + '\n\nStart writing here...\n';
        editSummary.value = '';

        populateParentDropdown(null);

        // If we were viewing a page, default parent to current page
        if (currentPage) {
            editParent.value = currentPage.id;
        } else {
            editParent.value = '';
        }

        if (previewVisible) {
            togglePreview();
        }

        window.location.hash = slug;
        showAlert('Page "' + title + '" does not exist yet. You can create it here.', 'info');
        editTitle.focus();
    }

    function loadPageById(id) {
        apiGet('api/sop-pages.php?id=' + id).then(function (data) {
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }
            displayPage(data.page, data.breadcrumb);
        }).catch(function () {
            showAlert('Failed to load page', 'danger');
        });
    }

    function displayPage(page, crumbs) {
        currentPage = page;
        window.location.hash = page.slug;

        // Show view mode, hide others
        showMode('view');

        // Breadcrumb
        var bc = '';
        if (crumbs && crumbs.length > 0) {
            for (var i = 0; i < crumbs.length; i++) {
                bc += '<li class="breadcrumb-item"><a href="#' + escHtml(crumbs[i].slug) + '" class="sop-breadcrumb-link" data-slug="' +
                    escHtml(crumbs[i].slug) + '">' + escHtml(crumbs[i].title) + '</a></li>';
            }
        }
        bc += '<li class="breadcrumb-item active">' + escHtml(page.title) + '</li>';
        breadcrumb.innerHTML = bc;

        // Bind breadcrumb clicks
        var bcLinks = breadcrumb.querySelectorAll('.sop-breadcrumb-link');
        for (var j = 0; j < bcLinks.length; j++) {
            (function (link) {
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    loadPageBySlug(link.getAttribute('data-slug'));
                });
            })(bcLinks[j]);
        }

        // Title
        pageTitle.innerHTML = '<h2>' + escHtml(page.title) + '</h2>';

        // Content (render markdown)
        pageContent.innerHTML = renderMarkdown(page.content);

        // Intercept internal wiki links in rendered content
        bindWikiLinks(pageContent);

        // Meta
        var metaText = 'Created by ' + escHtml(page.created_by_name) + ' on ' + formatDate(page.created_at);
        if (page.updated_by_name) {
            metaText += ' — Last updated by ' + escHtml(page.updated_by_name) + ' on ' + formatDate(page.updated_at);
        }
        pageMeta.innerHTML = metaText;
        pageMeta.classList.remove('d-none');

        // Show action buttons
        viewActions.style.display = '';
        viewActions.classList.remove('d-none');

        // Show print button
        document.getElementById('btnPrint').classList.remove('d-none');

        // Highlight in tree
        renderTree(treeSearch.value);
    }

    // ── Mode Switching ──

    function showMode(mode) {
        viewMode.classList.toggle('d-none', mode !== 'view');
        editMode.classList.toggle('d-none', mode !== 'edit');
        historyMode.classList.toggle('d-none', mode !== 'history');

        var btnBack = document.getElementById('btnBackToView');
        btnBack.classList.toggle('d-none', mode === 'view');
    }

    // ── Editor ──

    function openEditor(pageData, parentId) {
        showMode('edit');

        if (pageData) {
            // Edit existing
            editModeLabel.textContent = 'Edit: ' + pageData.title;
            editPageId.value = pageData.id;
            editTitle.value = pageData.title;
            editSlug.value = pageData.slug;
            editContent.value = pageData.content;
            editSummary.value = '';
        } else {
            // New page
            editModeLabel.textContent = 'New Page';
            editPageId.value = '';
            editTitle.value = '';
            editSlug.value = '';
            editContent.value = '';
            editSummary.value = '';
        }

        // Populate parent dropdown
        populateParentDropdown(pageData ? pageData.id : null);

        // Set parent
        if (parentId) {
            editParent.value = parentId;
        } else if (pageData && pageData.parent_id) {
            editParent.value = pageData.parent_id;
        } else {
            editParent.value = '';
        }

        // Close preview if open
        if (previewVisible) {
            togglePreview();
        }

        editTitle.focus();
    }

    function populateParentDropdown(excludeId) {
        var html = '<option value="">— None (root) —</option>';
        for (var i = 0; i < pages.length; i++) {
            var p = pages[i];
            if (excludeId && p.id === excludeId) continue;
            var selected = '';
            html += '<option value="' + p.id + '"' + selected + '>' + escHtml(p.title) + '</option>';
        }
        editParent.innerHTML = html;
    }

    function savePage() {
        var id = editPageId.value ? parseInt(editPageId.value, 10) : 0;
        var title = editTitle.value.trim();
        var slug = editSlug.value.trim();
        var content = editContent.value;
        var parentId = editParent.value ? parseInt(editParent.value, 10) : null;
        var summary = editSummary.value.trim();

        if (!title) {
            showAlert('Title is required', 'warning');
            editTitle.focus();
            return;
        }
        if (!content) {
            showAlert('Content is required', 'warning');
            editContent.focus();
            return;
        }

        var payload = {
            title: title,
            slug: slug,
            content: content,
            parent_id: parentId,
            summary: summary
        };
        if (id > 0) {
            payload.id = id;
        }

        apiPost('api/sop-save.php', payload).then(function (data) {
            if (data.errors) {
                showAlert(data.errors.join(', '), 'danger');
                return;
            }
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }

            showAlert('Page saved successfully', 'success');

            // Reload tree and display saved page
            apiGet('api/sop-pages.php').then(function (listData) {
                pages = listData.pages || [];
                renderTree();
                loadPageBySlug(data.page.slug);
            });
        }).catch(function () {
            showAlert('Failed to save page', 'danger');
        });
    }

    // ── Delete ──

    function deletePage() {
        if (!currentPage) return;

        apiPost('api/sop-delete.php', { id: currentPage.id }).then(function (data) {
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }

            showAlert(data.message, 'success');
            currentPage = null;

            // Reset view
            pageTitle.innerHTML = '';
            pageContent.innerHTML = '<div class="text-center text-body-secondary py-5">' +
                '<i class="bi bi-journal-text" style="font-size: 3rem;"></i>' +
                '<p class="mt-3">Select a page from the sidebar or create a new one.</p></div>';
            pageMeta.classList.add('d-none');
            viewActions.style.display = 'none';
            breadcrumb.innerHTML = '<li class="breadcrumb-item text-body-secondary">Select a page</li>';
            window.location.hash = '';

            // Reload tree
            apiGet('api/sop-pages.php').then(function (listData) {
                pages = listData.pages || [];
                renderTree();
            });

            deleteModal.hide();
        }).catch(function () {
            showAlert('Failed to delete page', 'danger');
        });
    }

    // ── Revision History ──

    function showHistory() {
        if (!currentPage) return;
        showMode('history');
        revisionViewer.classList.add('d-none');

        apiGet('api/sop-revisions.php?page_id=' + currentPage.id).then(function (data) {
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }
            var revisions = data.revisions || [];
            if (revisions.length === 0) {
                historyBody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-3">No revisions yet</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < revisions.length; i++) {
                var r = revisions[i];
                html += '<tr>';
                html += '<td class="small">' + escHtml(formatDate(r.edited_at)) + '</td>';
                html += '<td class="small">' + escHtml(r.edited_by_name) + '</td>';
                html += '<td class="small">' + escHtml(r.title) + '</td>';
                html += '<td class="small">' + escHtml(r.summary || '—') + '</td>';
                html += '<td><button class="btn btn-sm btn-outline-secondary py-0 px-1 sop-view-revision" data-id="' + r.id + '">View</button></td>';
                html += '</tr>';
            }
            historyBody.innerHTML = html;

            // Bind view buttons
            var btns = historyBody.querySelectorAll('.sop-view-revision');
            for (var j = 0; j < btns.length; j++) {
                (function (btn) {
                    btn.addEventListener('click', function () {
                        viewRevision(parseInt(btn.getAttribute('data-id'), 10));
                    });
                })(btns[j]);
            }
        }).catch(function () {
            showAlert('Failed to load revision history', 'danger');
        });
    }

    function viewRevision(revId) {
        apiGet('api/sop-revisions.php?revision_id=' + revId).then(function (data) {
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }
            var rev = data.revision;
            currentRevisionData = rev;
            revisionLabel.textContent = 'Revision from ' + formatDate(rev.edited_at) + ' — ' + escHtml(rev.title);
            revisionContent.innerHTML = renderMarkdown(rev.content);
            revisionViewer.classList.remove('d-none');
        }).catch(function () {
            showAlert('Failed to load revision', 'danger');
        });
    }

    function restoreRevision() {
        if (!currentRevisionData || !currentPage) return;

        var payload = {
            id: currentPage.id,
            title: currentRevisionData.title,
            content: currentRevisionData.content,
            parent_id: currentPage.parent_id,
            summary: 'Restored revision from ' + formatDate(currentRevisionData.edited_at)
        };

        apiPost('api/sop-save.php', payload).then(function (data) {
            if (data.error) {
                showAlert(data.error, 'danger');
                return;
            }
            showAlert('Revision restored successfully', 'success');
            apiGet('api/sop-pages.php').then(function (listData) {
                pages = listData.pages || [];
                renderTree();
                loadPageBySlug(data.page.slug);
            });
        }).catch(function () {
            showAlert('Failed to restore revision', 'danger');
        });
    }

    // ── Preview Toggle ──

    function togglePreview() {
        previewVisible = !previewVisible;
        if (previewVisible) {
            editorCol.className = 'col-6';
            previewCol.classList.remove('d-none');
            updatePreview();
        } else {
            editorCol.className = 'col-12';
            previewCol.classList.add('d-none');
        }
    }

    function updatePreview() {
        if (previewVisible) {
            editPreview.innerHTML = renderMarkdown(editContent.value);
        }
    }

    // ── Markdown Toolbar ──

    function insertMarkdown(type) {
        var textarea = editContent;
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var text = textarea.value;
        var selected = text.substring(start, end);
        var before = text.substring(0, start);
        var after = text.substring(end);
        var insert = '';
        var cursorOffset = 0;

        switch (type) {
            case 'bold':
                insert = '**' + (selected || 'bold text') + '**';
                cursorOffset = selected ? insert.length : 2;
                break;
            case 'italic':
                insert = '*' + (selected || 'italic text') + '*';
                cursorOffset = selected ? insert.length : 1;
                break;
            case 'heading':
                insert = '## ' + (selected || 'Heading');
                cursorOffset = insert.length;
                break;
            case 'link':
                if (selected) {
                    // Default to wiki-style hash link using selected text as slug
                    var suggestedSlug = generateSlug(selected);
                    insert = '[' + selected + '](#' + suggestedSlug + ')';
                    cursorOffset = insert.length;
                } else {
                    insert = '[Page Title](#page-slug)';
                    cursorOffset = 1;
                }
                break;
            case 'ul':
                insert = '- ' + (selected || 'List item');
                cursorOffset = insert.length;
                break;
            case 'ol':
                insert = '1. ' + (selected || 'List item');
                cursorOffset = insert.length;
                break;
            case 'code':
                if (selected && selected.indexOf('\n') !== -1) {
                    insert = '```\n' + selected + '\n```';
                } else {
                    insert = '```\n' + (selected || 'code') + '\n```';
                }
                cursorOffset = 4;
                break;
            case 'table':
                insert = '| Column 1 | Column 2 | Column 3 |\n|----------|----------|----------|\n| Cell 1   | Cell 2   | Cell 3   |';
                cursorOffset = insert.length;
                break;
            case 'quote':
                insert = '> ' + (selected || 'Quote text');
                cursorOffset = insert.length;
                break;
            case 'hr':
                insert = '\n---\n';
                cursorOffset = insert.length;
                break;
        }

        textarea.value = before + insert + after;
        textarea.selectionStart = start + cursorOffset;
        textarea.selectionEnd = start + cursorOffset;
        textarea.focus();
        updatePreview();
    }

    // ── Auto-generate slug ──

    function generateSlug(title) {
        var slug = title.toLowerCase();
        slug = slug.replace(/[^a-z0-9\-]/g, '-');
        slug = slug.replace(/-+/g, '-');
        slug = slug.replace(/^-|-$/g, '');
        return slug.substring(0, 128);
    }

    // ── Event Bindings ──

    function init() {
        deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));

        // Load page tree
        loadPages();

        // Tree search
        treeSearch.addEventListener('input', function () {
            renderTree(treeSearch.value);
        });

        // New Page button
        document.getElementById('btnNewPage').addEventListener('click', function () {
            openEditor(null, null);
        });

        // Edit button
        document.getElementById('btnEdit').addEventListener('click', function () {
            if (currentPage) {
                openEditor(currentPage, null);
            }
        });

        // History button
        document.getElementById('btnHistory').addEventListener('click', function () {
            showHistory();
        });

        // New Child Page button
        document.getElementById('btnNewChild').addEventListener('click', function () {
            if (currentPage) {
                openEditor(null, currentPage.id);
            }
        });

        // Delete button
        document.getElementById('btnDelete').addEventListener('click', function () {
            if (currentPage) {
                document.getElementById('deletePageName').textContent = currentPage.title;
                deleteModal.show();
            }
        });

        // Confirm delete
        document.getElementById('btnConfirmDelete').addEventListener('click', function () {
            deletePage();
        });

        // Save button
        document.getElementById('btnSave').addEventListener('click', function () {
            savePage();
        });

        // Cancel edit
        document.getElementById('btnCancelEdit').addEventListener('click', function () {
            if (currentPage) {
                showMode('view');
            } else {
                showMode('view');
            }
        });

        // Back to view button
        document.getElementById('btnBackToView').addEventListener('click', function () {
            showMode('view');
        });

        // Close history
        document.getElementById('btnCloseHistory').addEventListener('click', function () {
            showMode('view');
        });

        // Restore revision
        document.getElementById('btnRestoreRevision').addEventListener('click', function () {
            restoreRevision();
        });

        // Toggle preview
        document.getElementById('btnTogglePreview').addEventListener('click', function () {
            togglePreview();
        });

        // Live preview on input
        editContent.addEventListener('input', function () {
            updatePreview();
        });

        // Auto-generate slug from title
        editTitle.addEventListener('input', function () {
            if (!editPageId.value) {
                editSlug.value = generateSlug(editTitle.value);
            }
        });

        // Markdown toolbar buttons
        var toolbarBtns = document.querySelectorAll('[data-md]');
        for (var i = 0; i < toolbarBtns.length; i++) {
            (function (btn) {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    insertMarkdown(btn.getAttribute('data-md'));
                });
            })(toolbarBtns[i]);
        }

        // Ctrl+S to save in editor
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                if (!editMode.classList.contains('d-none')) {
                    e.preventDefault();
                    savePage();
                }
            }
        });

        // Print button
        document.getElementById('btnPrint').addEventListener('click', function () {
            window.print();
        });

        // Hash change navigation
        window.addEventListener('hashchange', function () {
            var hash = window.location.hash.replace('#', '');
            if (hash && (!currentPage || currentPage.slug !== hash)) {
                loadPageBySlug(hash);
            }
        });
    }

    // ── Start ──
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
