/*
 * Phase 139 (2026-08-14) — Quick Notes page.
 *
 * ES5 IIFE, matching the project's convention. No build step, no modules.
 */
(function () {
    'use strict';

    var notesListEl   = document.getElementById('notesList');
    var notesEmptyEl  = document.getElementById('notesEmpty');
    var newNoteInput  = document.getElementById('newNoteInput');
    var filterGroup   = document.getElementById('notesFilterGroup');
    var wikiTreeEl    = document.getElementById('wikiTree');
    var wikiTreeEmptyEl = document.getElementById('wikiTreeEmpty');
    var btnNewWikiPage = document.getElementById('btnNewWikiPage');
    var alertArea     = document.getElementById('alertArea');

    var currentFilter = 'all'; // all | open | done
    var notesCache = [];
    var wikiPagesCache = [];
    var dragNoteId = null;

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function escHtml(s) {
        if (s == null) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function showAlert(msg, type) {
        if (!alertArea) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + (type || 'danger') + ' alert-dismissible fade show py-2 small';
        div.innerHTML = escHtml(msg) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        alertArea.innerHTML = '';
        alertArea.appendChild(div);
    }

    function showToast(msg) {
        if (typeof window.showBriefToast === 'function') {
            window.showBriefToast(msg);
        } else {
            showAlert(msg, 'success');
        }
    }

    function apiGet(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function apiPost(url, body) {
        body.csrf_token = csrf();
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json(); });
    }

    function formatTs(ts) {
        if (!ts) return '';
        // MySQL DATETIME "YYYY-MM-DD HH:MM:SS" -- Safari chokes on the
        // space separator with new Date(), so swap it for a 'T' first.
        var d = new Date(String(ts).replace(' ', 'T'));
        if (isNaN(d.getTime())) return ts;
        return d.toLocaleString(undefined, {
            month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
        });
    }

    // ── Notes list ──

    function loadNotes() {
        var doneParam = currentFilter === 'open' ? '&done=0' : (currentFilter === 'done' ? '&done=1' : '');
        apiGet('api/quick-notes.php?action=list' + doneParam).then(function (data) {
            notesCache = data.notes || [];
            renderNotes();
        }).catch(function () { showAlert('Failed to load notes'); });
    }

    function renderNotes() {
        if (!notesCache.length) {
            notesListEl.innerHTML = '';
            notesEmptyEl.classList.remove('d-none');
            return;
        }
        notesEmptyEl.classList.add('d-none');
        var html = '';
        notesCache.forEach(function (n) {
            html += '<div class="list-group-item quick-note-card' + (n.done ? ' quick-note-done' : '') + '" '
                 +  'draggable="true" data-note-id="' + n.id + '">'
                 +  '<div class="d-flex align-items-start gap-2">'
                 +  '<div class="form-check mt-1">'
                 +  '<input class="form-check-input note-done-toggle" type="checkbox" data-note-id="' + n.id + '"' + (n.done ? ' checked' : '') + '>'
                 +  '</div>'
                 +  '<div class="flex-grow-1">'
                 +  '<div class="small text-body-secondary">' + escHtml(formatTs(n.captured_at)) + '</div>'
                 +  '<div class="quick-note-text">' + escHtml(n.note_text) + '</div>'
                 +  '</div>'
                 +  '<div class="d-flex flex-column gap-1">'
                 +  '<button type="button" class="btn btn-xs btn-outline-primary note-send-btn" data-note-id="' + n.id + '" title="Send to…"><i class="bi bi-send"></i></button>'
                 +  '<button type="button" class="btn btn-xs btn-outline-danger note-delete-btn" data-note-id="' + n.id + '" title="Delete"><i class="bi bi-trash"></i></button>'
                 +  '</div>'
                 +  '</div>'
                 +  '</div>';
        });
        notesListEl.innerHTML = html;
        wireNoteCardEvents();
    }

    function wireNoteCardEvents() {
        notesListEl.querySelectorAll('.note-done-toggle').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = parseInt(cb.getAttribute('data-note-id'), 10);
                apiPost('api/quick-notes.php', { action: 'set_done', id: id, done: cb.checked }).then(function (res) {
                    if (res.errors) { showAlert(res.errors.join(', ')); return; }
                    loadNotes();
                });
            });
        });
        notesListEl.querySelectorAll('.note-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(btn.getAttribute('data-note-id'), 10);
                if (!confirm('Delete this note? This cannot be undone.')) return;
                apiPost('api/quick-notes.php', { action: 'delete', id: id }).then(function (res) {
                    if (res.errors) { showAlert(res.errors.join(', ')); return; }
                    loadNotes();
                });
            });
        });
        notesListEl.querySelectorAll('.note-send-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openSendToModal(parseInt(btn.getAttribute('data-note-id'), 10));
            });
        });
        notesListEl.querySelectorAll('.quick-note-card').forEach(function (card) {
            card.addEventListener('dragstart', function (e) {
                dragNoteId = parseInt(card.getAttribute('data-note-id'), 10);
                e.dataTransfer.setData('text/plain', String(dragNoteId));
                e.dataTransfer.effectAllowed = 'copyMove';
            });
        });
    }

    if (filterGroup) {
        filterGroup.querySelectorAll('button').forEach(function (btn) {
            btn.addEventListener('click', function () {
                filterGroup.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                currentFilter = btn.getAttribute('data-filter');
                loadNotes();
            });
        });
    }

    if (newNoteInput) {
        newNoteInput.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            var text = newNoteInput.value.trim();
            if (!text) return;
            apiPost('api/quick-notes.php', { action: 'create', note_text: text }).then(function (res) {
                if (res.errors) { showAlert(res.errors.join(', ')); return; }
                newNoteInput.value = '';
                showToast('Note captured');
                loadNotes();
            });
        });
    }

    // ── Personal wiki tree (drag-drop target) ──

    function loadWikiTree() {
        apiGet('api/quick-notes.php?action=wiki_tree').then(function (data) {
            wikiPagesCache = data.pages || [];
            renderWikiTree();
        }).catch(function () { showAlert('Failed to load your personal wiki pages'); });
    }

    function buildChildren(parentId) {
        return wikiPagesCache.filter(function (p) { return p.parent_id === parentId; });
    }

    function renderTreeLevel(parentId, depth) {
        var children = buildChildren(parentId);
        var html = '';
        children.forEach(function (page) {
            var childHtml = renderTreeLevel(page.id, depth + 1);
            html += '<div class="sop-tree-item">';
            html += '<div class="sop-tree-row wiki-drop-target" style="padding-left:' + (depth * 16 + 8) + 'px;" data-page-id="' + page.id + '" data-page-title="' + escHtml(page.title) + '">';
            html += '<span class="sop-tree-icon"><i class="bi bi-file-text"></i></span>';
            html += '<span class="sop-tree-link">' + escHtml(page.title) + '</span>';
            html += '</div>';
            if (childHtml) html += '<div class="sop-tree-children">' + childHtml + '</div>';
            html += '</div>';
        });
        return html;
    }

    function renderWikiTree() {
        if (!wikiPagesCache.length) {
            wikiTreeEl.innerHTML = '';
            wikiTreeEmptyEl.classList.remove('d-none');
            return;
        }
        wikiTreeEmptyEl.classList.add('d-none');
        wikiTreeEl.innerHTML = renderTreeLevel(null, 0);
        wireDropTargets();
    }

    function wireDropTargets() {
        wikiTreeEl.querySelectorAll('.wiki-drop-target').forEach(function (row) {
            row.addEventListener('dragover', function (e) {
                e.preventDefault();
                row.classList.add('wiki-drop-hover');
            });
            row.addEventListener('dragleave', function () {
                row.classList.remove('wiki-drop-hover');
            });
            row.addEventListener('drop', function (e) {
                e.preventDefault();
                row.classList.remove('wiki-drop-hover');
                var noteId = parseInt(e.dataTransfer.getData('text/plain'), 10) || dragNoteId;
                if (!noteId) return;
                openWikiDropConfirm(noteId, parseInt(row.getAttribute('data-page-id'), 10), row.getAttribute('data-page-title'));
            });
        });
    }

    var wikiDropModalEl = document.getElementById('wikiDropModal');
    var wikiDropModal = wikiDropModalEl ? new bootstrap.Modal(wikiDropModalEl) : null;
    function openWikiDropConfirm(noteId, pageId, pageTitle) {
        document.getElementById('wikiDropPageName').textContent = pageTitle || '';
        var copyBtn = document.getElementById('wikiDropCopyBtn');
        var moveBtn = document.getElementById('wikiDropMoveBtn');
        var newCopyBtn = copyBtn.cloneNode(true);
        var newMoveBtn = moveBtn.cloneNode(true);
        copyBtn.parentNode.replaceChild(newCopyBtn, copyBtn);
        moveBtn.parentNode.replaceChild(newMoveBtn, moveBtn);
        newCopyBtn.addEventListener('click', function () { doCopyToWiki(noteId, pageId, false); });
        newMoveBtn.addEventListener('click', function () { doCopyToWiki(noteId, pageId, true); });
        if (wikiDropModal) wikiDropModal.show();
    }

    function doCopyToWiki(noteId, pageId, move) {
        apiPost('api/quick-notes.php', { action: 'copy_to_wiki', id: noteId, page_id: pageId, move: move }).then(function (res) {
            if (res.errors) { showAlert(res.errors.join(', ')); return; }
            if (wikiDropModal) wikiDropModal.hide();
            showToast(move ? 'Note moved to page' : 'Note copied to page');
            loadNotes();
        });
    }

    if (btnNewWikiPage) {
        btnNewWikiPage.addEventListener('click', function () {
            var title = prompt('New personal page title:');
            if (!title || !title.trim()) return;
            // There's no dedicated create-a-blank-page action -- a new
            // personal page always starts from a note's text (copy_to_wiki
            // with page_id=null), so ask for the opening line here.
            var opening = prompt('First line for "' + title.trim() + '" (required to create the page):');
            if (!opening || !opening.trim()) return;
            apiPost('api/quick-notes.php', { action: 'create', note_text: opening.trim() }).then(function (createRes) {
                if (createRes.errors) { showAlert(createRes.errors.join(', ')); return; }
                apiPost('api/quick-notes.php', {
                    action: 'copy_to_wiki', id: createRes.id, page_id: null, new_page_title: title.trim(), move: true
                }).then(function (res) {
                    if (res.errors) { showAlert(res.errors.join(', ')); return; }
                    showToast('Page created');
                    loadWikiTree();
                    loadNotes();
                });
            });
        });
    }

    // ── Send-to modal (incident activity log / ICS-214) ──

    var sendToModalEl = document.getElementById('sendToModal');
    var sendToModal = sendToModalEl ? new bootstrap.Modal(sendToModalEl) : null;
    var sendToModalBody = document.getElementById('sendToModalBody');

    function openSendToModal(noteId) {
        sendToModalBody.innerHTML =
            '<div class="mb-3">'
          + '<label class="form-label form-label-sm">Search for an incident</label>'
          + '<input type="text" id="stIncidentSearch" class="form-control form-control-sm" placeholder="Incident # or scope…" autocomplete="off">'
          + '<div id="stIncidentResults" class="list-group mt-1"></div>'
          + '</div>'
          + '<div id="stTargetChoice" class="d-none">'
          + '  <div class="small text-body-secondary mb-2">Send this note to:</div>'
          + '  <div class="d-flex flex-column gap-2" id="stTargetButtons"></div>'
          + '</div>';

        var searchInput = document.getElementById('stIncidentSearch');
        var resultsEl = document.getElementById('stIncidentResults');
        var searchTimer = null;
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var term = searchInput.value.trim();
            if (term.length < 2) { resultsEl.innerHTML = ''; return; }
            searchTimer = setTimeout(function () {
                apiGet('api/incidents.php?search=' + encodeURIComponent(term)).then(function (data) {
                    var rows = data.incidents || data.rows || [];
                    resultsEl.innerHTML = rows.slice(0, 8).map(function (t) {
                        var label = '#' + (t.id) + (t.scope ? ' — ' + escHtml(t.scope) : '');
                        return '<button type="button" class="list-group-item list-group-item-action py-1 small" data-ticket-id="' + t.id + '">' + label + '</button>';
                    }).join('');
                    resultsEl.querySelectorAll('button').forEach(function (b) {
                        b.addEventListener('click', function () {
                            pickIncidentForSend(noteId, parseInt(b.getAttribute('data-ticket-id'), 10));
                        });
                    });
                }).catch(function () {});
            }, 250);
        });

        if (sendToModal) sendToModal.show();
    }

    function pickIncidentForSend(noteId, ticketId) {
        var choiceEl = document.getElementById('stTargetChoice');
        var btnsEl = document.getElementById('stTargetButtons');
        choiceEl.classList.remove('d-none');
        btnsEl.innerHTML = '<div class="spinner-border spinner-border-sm"></div>';

        apiGet('api/quick-notes.php?action=ics214_forms&ticket_id=' + ticketId).then(function (data) {
            var forms = data.forms || [];
            var html = '';
            html += '<div class="btn-group btn-group-sm" role="group">'
                  + '<button type="button" class="btn btn-outline-primary" id="stCopyActivityLog"><i class="bi bi-files me-1"></i>Copy to incident log</button>'
                  + '<button type="button" class="btn btn-outline-secondary" id="stMoveActivityLog"><i class="bi bi-arrow-right me-1"></i>Move</button>'
                  + '</div>';
            if (forms.length) {
                html += '<div class="mt-2"><label class="form-label form-label-sm">Or an existing ICS-214:</label>'
                      + '<select id="stIcs214Select" class="form-select form-select-sm mb-1">'
                      + forms.map(function (f) { return '<option value="' + f.id + '">' + escHtml(f.title || ('Form #' + f.id)) + '</option>'; }).join('')
                      + '</select>'
                      + '<div class="btn-group btn-group-sm" role="group">'
                      + '<button type="button" class="btn btn-outline-primary" id="stCopyIcs214"><i class="bi bi-files me-1"></i>Copy to 214</button>'
                      + '<button type="button" class="btn btn-outline-secondary" id="stMoveIcs214"><i class="bi bi-arrow-right me-1"></i>Move</button>'
                      + '</div></div>';
            } else {
                html += '<div class="small text-body-secondary mt-2">No ICS-214 forms exist yet for this incident — create one from the ICS Forms page first if you want to send a note there.</div>';
            }
            btnsEl.innerHTML = html;

            var copyActivity = document.getElementById('stCopyActivityLog');
            var moveActivity = document.getElementById('stMoveActivityLog');
            if (copyActivity) copyActivity.addEventListener('click', function () { doSendToActivityLog(noteId, ticketId, false); });
            if (moveActivity) moveActivity.addEventListener('click', function () { doSendToActivityLog(noteId, ticketId, true); });

            var copyIcs = document.getElementById('stCopyIcs214');
            var moveIcs = document.getElementById('stMoveIcs214');
            if (copyIcs) copyIcs.addEventListener('click', function () {
                doSendToIcs214(noteId, parseInt(document.getElementById('stIcs214Select').value, 10), false);
            });
            if (moveIcs) moveIcs.addEventListener('click', function () {
                doSendToIcs214(noteId, parseInt(document.getElementById('stIcs214Select').value, 10), true);
            });
        }).catch(function () { btnsEl.innerHTML = '<div class="text-danger small">Could not load ICS-214 forms.</div>'; });
    }

    function doSendToActivityLog(noteId, ticketId, move) {
        apiPost('api/quick-notes.php', { action: 'copy_to_activity_log', id: noteId, ticket_id: ticketId, move: move }).then(function (res) {
            if (res.errors) { showAlert(res.errors.join(', ')); return; }
            if (sendToModal) sendToModal.hide();
            showToast(move ? 'Note moved to incident log' : 'Note copied to incident log');
            loadNotes();
        });
    }
    function doSendToIcs214(noteId, formId, move) {
        apiPost('api/quick-notes.php', { action: 'copy_to_ics214', id: noteId, form_id: formId, move: move }).then(function (res) {
            if (res.errors) { showAlert(res.errors.join(', ')); return; }
            if (sendToModal) sendToModal.hide();
            showToast(move ? 'Note moved to ICS-214' : 'Note copied to ICS-214');
            loadNotes();
        });
    }

    // Expose for tests / future tooling.
    window.QuickNotes = { loadNotes: loadNotes, loadWikiTree: loadWikiTree };

    loadNotes();
    loadWikiTree();
})();
