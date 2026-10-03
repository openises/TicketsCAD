/**
 * Settings -> Email Lists (Phase 155, GH#145).
 *
 * Drives the panel in inc/email-lists-panel.php from api/email-lists.php.
 * config.js calls window.EmailListsAdmin.load() each time the tab is opened.
 *
 * REPLACES the Phase 41 block that lived in config.js. That code could only add a
 * typed address (the Manage modal's single button was "Add inline address"), put a
 * member's name straight into markup (stored XSS), and left its click handlers on
 * window.__el_* globals.
 *
 * RULES THIS FILE FOLLOWS
 *   - Every server value (list names, member names, addresses, errors) goes on the
 *     page with textContent. Names are typed by users and read from the roster and
 *     the address book; none of it is ever concatenated into markup.
 *   - Every button is type="button". ES5 only.
 *   - The pickers search on the SERVER (api/email-lists.php?action=search_recipients):
 *     the address book is private people's data and unbounded (every unknown caller
 *     becomes a constituent), so the browser never holds it.
 *   - The Manage modal is refreshed IN PLACE after an add or remove - never re-shown,
 *     which is what stacked backdrops and froze the page behind the old modal.
 *   - Nothing here decides what is allowed. The endpoint validates and authorises every
 *     request; this file shows the answer.
 */
(function () {
    'use strict';

    var API = 'api/email-lists.php';

    var lists = [];
    var bound = false;
    var picker = null;
    var manage = { id: null, name: '', detail: null };
    var manageModal = null;
    var newModal = null;
    var optionsLoaded = false;

    // ── tiny helpers ────────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function clear(n) { while (n && n.firstChild) n.removeChild(n.firstChild); }
    function icon(name, extra) { return el('i', 'bi bi-' + name + (extra ? ' ' + extra : '')); }
    function show(n, on) { if (n) { if (on) n.classList.remove('d-none'); else n.classList.add('d-none'); } }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    /** One request. Always resolves to {status, body} - never rejects. */
    function api(method, action, data, qs) {
        var url = API + '?action=' + encodeURIComponent(action) + (qs || '');
        var opts = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json' } };
        if (method === 'POST') {
            var payload = {};
            var k;
            if (data) for (k in data) { if (Object.prototype.hasOwnProperty.call(data, k)) payload[k] = data[k]; }
            payload.csrf_token = csrf();
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(payload);
        }
        return fetch(url, opts).then(function (r) {
            return r.json().then(function (b) { return { status: r.status, body: b || {} }; },
                function () { return { status: r.status, body: { error: 'The server sent an unexpected reply.' } }; });
        }, function () {
            return { status: 0, body: { error: 'Could not reach the server.' } };
        });
    }
    function errText(r) {
        return (r.body && r.body.error) ? String(r.body.error) : 'Something went wrong (HTTP ' + r.status + ').';
    }

    function say(host, kind, text) {
        host.className = 'small text-' + (kind === 'danger' ? 'danger' : (kind === 'success' ? 'success' : 'body-secondary'));
        host.textContent = text;
    }

    function showMsg(kind, text) {
        var host = $('elMsg');
        clear(host);
        var box = el('div', 'alert alert-' + kind + ' small py-2 d-flex align-items-start gap-2 mb-2');
        box.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
        box.appendChild(el('div', 'flex-grow-1', text));
        var x = el('button', 'btn-close btn-sm');
        x.type = 'button';
        x.setAttribute('aria-label', 'Dismiss');
        x.addEventListener('click', function () { clear(host); });
        box.appendChild(x);
        host.appendChild(box);
        if (kind === 'success' || kind === 'info') window.setTimeout(function () { if (box.parentNode === host) clear(host); }, 8000);
    }

    // ── pure helpers (exported for the unit tests) ──────────────────────────
    /** What each status code is called and how it is drawn. Parity with email_list_status_labels() is tested. */
    var STATUS = {
        ok:                { label: 'OK', kind: 'ok', icon: 'check-circle-fill' },
        no_email:          { label: 'No email address on file', kind: 'problem', icon: 'exclamation-triangle-fill' },
        invalid_email:     { label: 'Not a valid email address', kind: 'problem', icon: 'exclamation-triangle-fill' },
        member_deleted:    { label: 'Member was deleted', kind: 'problem', icon: 'person-x-fill' },
        missing_ref:       { label: 'Record no longer exists', kind: 'problem', icon: 'question-circle-fill' },
        sub_list_archived: { label: 'Sub-list is archived', kind: 'problem', icon: 'archive-fill' },
        cycle:             { label: 'Loop in nested lists', kind: 'problem', icon: 'arrow-repeat' },
        too_deep:          { label: 'Nested too deeply', kind: 'problem', icon: 'layers-fill' },
        partial:           { label: 'Some entries inside have problems', kind: 'problem', icon: 'exclamation-triangle-fill' },
        empty:             { label: 'Contributes no addresses', kind: 'problem', icon: 'dash-circle-fill' },
        status_skipped:    { label: 'Skipped (member status)', kind: 'policy', icon: 'slash-circle-fill' },
        opted_out:         { label: 'Opted out of email', kind: 'policy', icon: 'slash-circle-fill' },
        duplicate:         { label: 'Duplicate (already provided)', kind: 'dup', icon: 'files' }
    };
    function statusInfo(code) {
        return STATUS[code] || { label: String(code), kind: 'problem', icon: 'question-circle-fill' };
    }

    var PHRASES = {
        no_email: ['entry has no email address', 'entries have no email address'],
        invalid_email: ['entry has an invalid email address', 'entries have an invalid email address'],
        member_deleted: ['member was deleted', 'members were deleted'],
        missing_ref: ['entry points at a deleted record', 'entries point at a deleted record'],
        sub_list_archived: ['sub-list is archived', 'sub-lists are archived'],
        cycle: ['entry loops back on itself', 'entries loop back on themselves'],
        too_deep: ['entry is nested too deeply', 'entries are nested too deeply'],
        partial: ['sub-list has problems inside', 'sub-lists have problems inside'],
        empty: ['sub-list contributes nothing', 'sub-lists contribute nothing'],
        opted_out: ['opted out of email', 'opted out of email'],
        duplicate: ['duplicate collapsed', 'duplicates collapsed']
    };

    /**
     * The summary banner as data: {headline, parts[], problems}. headline names the unique
     * addresses and entries; parts lists every non-OK status with a count, in a stable order.
     */
    function bannerText(summary) {
        var s = summary || {};
        var entries = s.entries || 0;
        var addrs = s.unique_addresses || 0;
        var headline = entries === 0
            ? 'This list is empty. Add a recipient below.'
            : 'This list currently resolves to ' + plural(addrs, 'unique address', 'unique addresses') + ' from ' + plural(entries, 'entry', 'entries') + '.';
        var parts = [];
        var by = s.by_status || {};
        var order = ['no_email', 'invalid_email', 'member_deleted', 'missing_ref', 'sub_list_archived', 'cycle', 'too_deep', 'partial', 'empty', 'status_skipped', 'opted_out', 'duplicate'];
        var i;
        for (i = 0; i < order.length; i++) {
            var code = order[i];
            var n = by[code] || 0;
            if (!n) continue;
            if (code === 'status_skipped') {
                var names = (s.skip_statuses || []).join(', ');
                parts.push(n + ' skipped (member status' + (names ? ' ' + names : '') + ')');
            } else {
                parts.push(n + ' ' + PHRASES[code][n === 1 ? 0 : 1]);
            }
        }
        return { headline: headline, parts: parts, problems: s.problems || 0, addresses: addrs, entries: entries };
    }

    /** The request body for "Add" - one shape per entry type. */
    function buildAddRequest(listId, type, item, inline) {
        var req = { list_id: Number(listId), member_type: type };
        if (type === 'inline') {
            req.inline_email = String((inline && inline.email) || '').trim();
            var nm = String((inline && inline.name) || '').trim();
            if (nm) req.display_name = nm;
        } else {
            req.ref_id = Number(item && item.id);
        }
        return req;
    }

    var TYPE_LABEL = { member: 'Member', constituent: 'Contact', list: 'Sub-list', inline: 'Address' };

    // ── the list table ──────────────────────────────────────────────────────
    function load() {
        bind();
        var body = $('emailListsBody');
        clear(body);
        body.appendChild(el('div', 'text-body-secondary p-3 small', 'Loading lists...'));
        return api('GET', 'list').then(function (r) {
            clear(body);
            if (r.status !== 200) {
                body.appendChild(el('div', 'text-danger p-3 small', errText(r)));
                return;
            }
            lists = r.body.lists || [];
            render();
        });
    }

    function render() {
        var body = $('emailListsBody');
        clear(body);
        var q = (($('emailListFilter') || {}).value || '').toLowerCase();
        var shown = [];
        var i;
        for (i = 0; i < lists.length; i++) {
            var l = lists[i];
            if (q && (l.name + ' ' + (l.slug || '') + ' ' + (l.description || '')).toLowerCase().indexOf(q) === -1) continue;
            shown.push(l);
        }
        if (!shown.length) {
            var none = el('div', 'text-body-secondary p-3 small');
            none.appendChild(document.createTextNode(lists.length ? 'No list matches that filter.' : 'No lists yet. Click '));
            if (!lists.length) { none.appendChild(el('strong', '', 'New List')); none.appendChild(document.createTextNode(' above to create one.')); }
            body.appendChild(none);
            return;
        }
        var wrap = el('div', 'table-responsive');
        var table = el('table', 'table table-sm table-hover mb-0 align-middle');
        var cap = el('caption', 'visually-hidden', 'Email lists');
        table.appendChild(cap);
        var thead = el('thead'); var hr = el('tr');
        var heads = [['Name', ''], ['Slug', ''], ['Description', ''], ['Entries', 'text-end'], ['Addresses', 'text-end'], ['Problems', 'text-end'], ['', 'text-end']];
        for (i = 0; i < heads.length; i++) {
            var th = el('th', heads[i][1], heads[i][0]);
            th.scope = 'col';
            if (heads[i][0] === '') th.appendChild(el('span', 'visually-hidden', 'Actions'));
            hr.appendChild(th);
        }
        thead.appendChild(hr); table.appendChild(thead);
        var tb = el('tbody');
        for (i = 0; i < shown.length; i++) tb.appendChild(listRow(shown[i]));
        table.appendChild(tb); wrap.appendChild(table); body.appendChild(wrap);
    }

    function listRow(l) {
        var tr = el('tr');
        tr.setAttribute('data-list-id', String(l.id));
        tr.appendChild(el('td', 'fw-semibold', l.name));
        tr.appendChild(el('td', 'font-monospace small text-body-secondary', l.slug));
        tr.appendChild(el('td', 'small', (l.description || '').substr(0, 80)));
        tr.appendChild(el('td', 'text-end', String(l.entry_count)));
        tr.appendChild(el('td', 'text-end', String(l.address_count)));
        var tdP = el('td', 'text-end');
        if (l.problem_count > 0) {
            var b = el('span', 'badge text-bg-warning');
            b.appendChild(icon('exclamation-triangle-fill', 'me-1'));
            b.appendChild(document.createTextNode(String(l.problem_count)));
            b.appendChild(el('span', 'visually-hidden', ' ' + (l.problem_count === 1 ? 'entry has a problem' : 'entries have problems')));
            tdP.appendChild(b);
        } else tdP.appendChild(document.createTextNode('0'));
        tr.appendChild(tdP);
        var tdA = el('td', 'text-end text-nowrap');
        var man = el('button', 'btn btn-sm btn-outline-primary me-1');
        man.type = 'button';
        man.appendChild(icon('pencil', 'me-1'));
        man.appendChild(document.createTextNode('Manage'));
        man.setAttribute('aria-label', 'Manage list ' + l.name);
        man.addEventListener('click', function () { openManage(l.id); });
        tdA.appendChild(man);
        var arc = el('button', 'btn btn-sm btn-outline-danger');
        arc.type = 'button';
        arc.title = 'Archive';
        arc.setAttribute('aria-label', 'Archive list ' + l.name);
        arc.appendChild(icon('archive'));
        arc.addEventListener('click', function () { archiveList(l); });
        tdA.appendChild(arc);
        tr.appendChild(tdA);
        return tr;
    }

    // ── new list / archive ──────────────────────────────────────────────────
    function openNew() {
        $('elNewName').value = ''; $('elNewDesc').value = '';
        show($('elNewErr'), false);
        if (!newModal) newModal = window.bootstrap.Modal.getOrCreateInstance($('elNewModal'));
        var focus = function () {
            $('elNewModal').removeEventListener('shown.bs.modal', focus);
            window.setTimeout(function () { $('elNewName').focus(); }, 0);
        };
        $('elNewModal').addEventListener('shown.bs.modal', focus);
        newModal.show();
    }

    function createList() {
        var name = $('elNewName').value.trim();
        if (!name) { var e = $('elNewErr'); e.textContent = 'Give the list a name.'; show(e, true); $('elNewName').focus(); return; }
        $('elNewCreate').disabled = true;
        api('POST', 'create', { name: name, description: $('elNewDesc').value.trim() }).then(function (r) {
            $('elNewCreate').disabled = false;
            if (r.status === 200 && r.body.ok) {
                newModal.hide();
                showMsg('success', 'List "' + r.body.name + '" created.');
                load();
                openManage(r.body.id);
            } else {
                var box = $('elNewErr'); box.textContent = errText(r); show(box, true);
            }
        });
    }

    function archiveList(l, force) {
        if (!force && !window.confirm('Archive the list "' + l.name + '"? It will no longer be offered as a target.')) return;
        api('POST', 'archive', { id: l.id, force: !!force }).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                showMsg('info', 'List "' + l.name + '" archived.' + (r.body.rules && r.body.rules.length ? ' ' + plural(r.body.rules.length, 'notification rule still names it', 'notification rules still name it') + '.' : ''));
                load();
            } else if (r.status === 409 && r.body.code === 'in_use') {
                if (window.confirm(r.body.error)) archiveList(l, true);
            } else showMsg('danger', errText(r));
        });
    }

    // ── list options ────────────────────────────────────────────────────────
    function loadOptions() {
        return api('GET', 'get_options').then(function (r) {
            if (r.status !== 200) { say($('elOptionsMsg'), 'danger', errText(r)); return; }
            var host = $('elSkipStatuses');
            clear(host);
            var chosen = {};
            var i;
            for (i = 0; i < r.body.skip_statuses.length; i++) chosen[String(r.body.skip_statuses[i]).toLowerCase()] = true;
            var labels = (r.body.status_labels || []).slice();
            // a label stored in the setting whose status has since been deleted must stay visible so it can be un-ticked
            for (i = 0; i < r.body.skip_statuses.length; i++) {
                var have = false; var j;
                for (j = 0; j < labels.length; j++) if (String(labels[j]).toLowerCase() === String(r.body.skip_statuses[i]).toLowerCase()) have = true;
                if (!have) labels.push(r.body.skip_statuses[i]);
            }
            if (!labels.length) host.appendChild(el('span', 'text-body-secondary', 'No member statuses are defined yet.'));
            for (i = 0; i < labels.length; i++) {
                var wrap = el('div', 'form-check');
                var cb = el('input', 'form-check-input');
                cb.type = 'checkbox'; cb.id = 'elSkip' + i; cb.value = labels[i];
                cb.checked = !!chosen[String(labels[i]).toLowerCase()];
                var lab = el('label', 'form-check-label', labels[i]);
                lab.setAttribute('for', cb.id);
                wrap.appendChild(cb); wrap.appendChild(lab); host.appendChild(wrap);
            }
            $('elRequireEmail').checked = !!r.body.require_email_on_add;
            optionsLoaded = true;
        });
    }

    function saveOptions() {
        var picked = [];
        var boxes = $('elSkipStatuses').querySelectorAll('input[type=checkbox]');
        var i;
        for (i = 0; i < boxes.length; i++) if (boxes[i].checked) picked.push(boxes[i].value);
        say($('elOptionsMsg'), 'muted', 'Saving...');
        api('POST', 'save_options', { skip_statuses: picked, require_email_on_add: $('elRequireEmail').checked }).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                say($('elOptionsMsg'), 'success', 'Saved. ' + (r.body.skip_statuses.length ? 'Skipping: ' + r.body.skip_statuses.join(', ') + '.' : 'No status is skipped.'));
                load();
                if (manage.id) refreshDetail();
            } else say($('elOptionsMsg'), 'danger', errText(r));
        });
    }

    // ── the Manage modal ────────────────────────────────────────────────────
    function openManage(id) {
        manage.id = id; manage.detail = null;
        clear($('elEntriesBody'));
        $('elSummary').className = 'alert alert-secondary small py-2 mb-2';
        $('elSummary').textContent = 'Loading...';
        show($('elManageErr'), false);
        $('elAddMsg').textContent = ''; $('elCsvResult').textContent = ''; $('elEditMsg').textContent = '';
        $('elCsvText').value = '';
        $('elPreviewDetails').open = false; $('elCsvDetails').open = false; $('elEditDetails').open = false;
        selectType('member');
        if (!manageModal) manageModal = window.bootstrap.Modal.getOrCreateInstance($('elManageModal'));
        var focusType = function () {
            $('elManageModal').removeEventListener('shown.bs.modal', focusType);
            // Deferred and AFTER shown: Bootstrap's focus trap otherwise lands focus on the dialog
            // itself, and typing then fires page shortcuts (the GH#135 lesson).
            window.setTimeout(function () {
                var r = document.querySelector('#elTypeGroup input[name="elType"]:checked');
                if (r) r.focus();
            }, 0);
        };
        $('elManageModal').addEventListener('shown.bs.modal', focusType);
        manageModal.show();
        refreshDetail();
    }

    /** Re-read the list and redraw the banner and entries IN PLACE. */
    function refreshDetail() {
        if (!manage.id) return Promise.resolve();
        var id = manage.id;
        return api('GET', 'detail', null, '&id=' + encodeURIComponent(String(id))).then(function (r) {
            if (id !== manage.id) return;
            if (r.status !== 200) { var eb = $('elManageErr'); eb.textContent = errText(r); show(eb, true); return; }
            show($('elManageErr'), false);
            manage.detail = r.body;
            manage.name = r.body.list.name;
            $('elManageTitle').textContent = 'Manage list: ' + r.body.list.name + ' (' + r.body.list.slug + ')';
            $('elEditName').value = r.body.list.name;
            $('elEditDesc').value = r.body.list.description || '';
            renderSummary(r.body.summary);
            renderEntries(r.body.members);
            if ($('elPreviewDetails').open) loadPreview();
        });
    }

    function renderSummary(summary) {
        var b = bannerText(summary);
        var box = $('elSummary');
        clear(box);
        box.className = 'alert small py-2 mb-2 alert-' + (b.problems > 0 ? 'warning' : 'success');
        box.appendChild(icon(b.problems > 0 ? 'exclamation-triangle-fill' : 'check-circle-fill', 'me-1'));
        box.appendChild(el('strong', '', b.headline));
        if (b.parts.length) {
            box.appendChild(el('div', 'mt-1', b.parts.join(' · ')));
        }
    }

    function recipientCell(row) {
        var td = el('td');
        var label = row.label;
        if (!label) label = row.orphan ? '(deleted record)' : (row.member_type === 'inline' ? '(no address)' : '(unnamed)');
        var main = el('div', '');
        main.appendChild(el('span', row.orphan ? 'text-body-secondary fst-italic' : 'fw-semibold', label));
        if (row.callsign) main.appendChild(el('span', 'text-body-secondary', ' (' + row.callsign + ')'));
        td.appendChild(main);
        if (row.resolved_email && row.resolved_email !== label) td.appendChild(el('div', 'text-body-secondary', row.resolved_email));
        // the repair route, by where the bad data lives
        if (row.status === 'no_email' || row.status === 'invalid_email' || row.status === 'member_deleted') {
            if (row.member_type === 'member' && row.roster_id > 0) {
                var a = el('a', 'small', 'Fix in Roster');
                a.href = 'roster.php?id=' + encodeURIComponent(String(row.roster_id));
                td.appendChild(a);
            } else if (row.member_type === 'constituent') {
                var c = el('a', 'small', 'Edit in Contacts');
                c.href = 'constituents.php';
                td.appendChild(c);
            }
        }
        return td;
    }

    function renderEntries(rows) {
        var body = $('elEntriesBody');
        clear(body);
        if (!rows.length) {
            var tr0 = el('tr'); var td0 = el('td', 'text-body-secondary text-center', 'No entries yet.'); td0.colSpan = 6; tr0.appendChild(td0); body.appendChild(tr0);
            return;
        }
        var i;
        for (i = 0; i < rows.length; i++) body.appendChild(entryRow(rows[i], i));
    }

    function entryRow(row, index) {
        var tr = el('tr');
        tr.setAttribute('data-entry-id', String(row.id));
        var tdT = el('td'); tdT.appendChild(el('span', 'badge text-bg-secondary', TYPE_LABEL[row.member_type] || row.member_type)); tr.appendChild(tdT);
        tr.appendChild(recipientCell(row));

        var st = statusInfo(row.status);
        var tdS = el('td');
        var cls = st.kind === 'ok' ? 'text-success' : (st.kind === 'problem' ? 'text-warning' : 'text-body-secondary');
        var line = el('div', cls);
        line.appendChild(icon(st.icon, 'me-1'));
        line.appendChild(document.createTextNode(st.label));
        tdS.appendChild(line);
        if (row.status_detail) tdS.appendChild(el('div', 'text-body-secondary', row.status_detail));
        tr.appendChild(tdS);

        var tdC = el('td');
        if (row.member_type === 'list' && row.sub_list_id > 0) {
            var open = el('button', 'btn btn-link btn-sm p-0', plural(row.contributes, 'address', 'addresses'));
            open.type = 'button';
            open.title = 'Open this sub-list';
            open.addEventListener('click', function () { openManage(row.sub_list_id); });
            tdC.appendChild(open);
        } else tdC.appendChild(document.createTextNode(String(row.contributes)));
        tr.appendChild(tdC);

        var tdA = el('td', 'text-body-secondary');
        tdA.appendChild(el('div', '', row.added_by_name || ''));
        tdA.appendChild(el('div', '', row.added_at || ''));
        tr.appendChild(tdA);

        var tdR = el('td', 'text-end');
        var who = row.label || (row.orphan ? 'deleted record' : 'entry');
        var rm = el('button', 'btn btn-sm btn-outline-danger');
        rm.type = 'button';
        rm.setAttribute('aria-label', 'Remove ' + who);
        rm.appendChild(icon('x-lg'));
        rm.addEventListener('click', function () { removeEntry(row, who, index); });
        tdR.appendChild(rm);
        tr.appendChild(tdR);
        return tr;
    }

    function removeEntry(row, who, index) {
        if (!window.confirm('Remove "' + who + '" from this list?')) return;
        api('POST', 'remove_member', { id: row.id, list_id: manage.id }).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                refreshDetail().then(function () {
                    // focus moves to the next row's Remove button, or back to the picker
                    var btns = $('elEntriesBody').querySelectorAll('button[aria-label^="Remove"]');
                    if (btns.length) (btns[Math.min(index, btns.length - 1)]).focus();
                    else focusPicker();
                });
                load();
                say($('elAddMsg'), 'muted', 'Removed ' + who + '.');
            } else {
                var eb = $('elManageErr'); eb.textContent = errText(r); show(eb, true);
            }
        });
    }

    // ── adding ──────────────────────────────────────────────────────────────
    function currentType() {
        var r = document.querySelector('#elTypeGroup input[name="elType"]:checked');
        return r ? r.value : 'member';
    }

    function focusPicker() {
        if (currentType() === 'inline') $('elInlineEmail').focus(); else $('elPickerInput').focus();
    }

    function setAddButton(item) {
        var btn = $('elAddBtn');
        var txt = $('elAddBtnText');
        var type = currentType();
        if (type === 'inline') {
            btn.disabled = false; txt.textContent = 'Add';
            return;
        }
        if (!item) { btn.disabled = true; txt.textContent = 'Add'; return; }
        btn.disabled = false;
        txt.textContent = (type !== 'list' && item.has_email === false) ? 'Add anyway (no email on file)' : 'Add';
    }

    function selectType(type) {
        var radios = document.querySelectorAll('#elTypeGroup input[name="elType"]');
        var i;
        for (i = 0; i < radios.length; i++) radios[i].checked = (radios[i].value === type);
        var inline = type === 'inline';
        show($('elPickerBlock'), !inline);
        show($('elInlineBlock'), inline);
        show($('elHasEmailWrap'), type === 'constituent');
        var labels = { member: ['Member', 'Type a name or callsign, or click to browse'], constituent: ['Contact', 'Type a name, email or phone, or click to browse'], list: ['Sub-list', 'Type a list name, or click to browse'] };
        if (labels[type]) { $('elPickerLabel').textContent = labels[type][0]; $('elPickerInput').placeholder = labels[type][1]; }
        if (picker) picker.clear();
        setAddButton(null);
        say($('elAddMsg'), 'muted', '');
    }

    function onPickerQuery(query, setItems) {
        var type = currentType();
        if (type === 'inline' || !manage.id) { setItems([]); return; }
        var hasEmail = type === 'constituent' ? ($('elHasEmail').checked ? '1' : '0') : '0';
        var qs = '&type=' + encodeURIComponent(type) + '&q=' + encodeURIComponent(query) + '&list_id=' + encodeURIComponent(String(manage.id)) + '&has_email=' + hasEmail;
        api('GET', 'search_recipients', null, qs).then(function (r) {
            if (r.status !== 200) { var eb = $('elManageErr'); eb.textContent = errText(r); show(eb, true); setItems([]); return; }
            show($('elManageErr'), false);
            setItems(r.body.items || []);
        });
    }

    function ensurePicker() {
        if (picker || !window.SearchableSelect) return;
        picker = window.SearchableSelect.attach($('elPickerInput'), $('elPickerValue'), [], {
            onQuery: onPickerQuery,
            hideEmptyOption: true,
            getLabel: function (it) { return it.label; },
            getValue: function (it) { return String(it.id); },
            getSubLabel: function (it) { return it.sublabel; },
            isDisabled: function (it) { return !!it.disabled_reason; },
            getDisabledReason: function (it) { return it.disabled_reason; },
            noMatchLabel: 'No matches',
            onCommit: function (item) {
                setAddButton(item);
                $('elAddBtn').focus();       // Enter in an open picker commits the highlight and moves to Add
            }
        });
        $('elPickerValue').addEventListener('change', function () { if (!$('elPickerValue').value) setAddButton(null); });
    }

    function addEntry() {
        var type = currentType();
        var item = null; var inline = null;
        if (type === 'inline') {
            inline = { email: $('elInlineEmail').value, name: $('elInlineName').value };
            if (!inline.email.trim()) { say($('elAddMsg'), 'danger', 'Type an email address.'); $('elInlineEmail').focus(); return; }
        } else {
            item = picker ? picker.getSelectedItem() : null;
            if (!item) { say($('elAddMsg'), 'danger', 'Choose someone from the list first.'); $('elPickerInput').focus(); return; }
        }
        var req = buildAddRequest(manage.id, type, item, inline);
        $('elAddBtn').disabled = true;
        api('POST', 'add_member', req).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                var warn = (r.body.warnings && r.body.warnings.length) ? ' (' + (r.body.warnings.indexOf('no_email') >= 0 ? 'no email address on file yet' : r.body.warnings.join(', ')) + ')' : '';
                say($('elAddMsg'), 'success', 'Added ' + r.body.label + ' to ' + r.body.list_name + '.' + warn);
                if (type === 'inline') { $('elInlineEmail').value = ''; $('elInlineName').value = ''; }
                else if (picker) picker.clear();
                setAddButton(null);
                refreshDetail();
                load();
                focusPicker();              // the add-many flow: back to the picker, ready for the next one
            } else {
                setAddButton(item);
                if (type === 'inline') $('elAddBtn').disabled = false;
                say($('elAddMsg'), 'danger', errText(r));
            }
        });
    }

    // ── preview / csv / rename ──────────────────────────────────────────────
    function loadPreview() {
        var host = $('elPreviewBody');
        clear(host);
        host.textContent = 'Loading...';
        api('GET', 'resolve', null, '&id=' + encodeURIComponent(String(manage.id))).then(function (r) {
            clear(host);
            if (r.status !== 200) { host.appendChild(el('span', 'text-danger', errText(r))); return; }
            host.appendChild(el('div', 'fw-semibold mb-1', plural(r.body.count, 'address would receive a message', 'addresses would receive a message') + ':'));
            var ul = el('ul', 'mb-2 ps-3');
            var i;
            var rec = r.body.recipients || [];
            for (i = 0; i < Math.min(rec.length, 50); i++) ul.appendChild(el('li', '', (rec[i].name ? rec[i].name + ' ' : '') + '<' + rec[i].email + '>'));
            if (rec.length > 50) ul.appendChild(el('li', 'text-body-secondary', '...and ' + (rec.length - 50) + ' more'));
            if (!rec.length) ul.appendChild(el('li', 'text-body-secondary', 'Nobody.'));
            host.appendChild(ul);
            var ex = r.body.excluded || [];
            if (ex.length) {
                host.appendChild(el('div', 'fw-semibold mb-1', 'Left out:'));
                var ul2 = el('ul', 'mb-0 ps-3');
                for (i = 0; i < ex.length; i++) ul2.appendChild(el('li', '', (ex[i].label || '(deleted record)') + ' - ' + statusInfo(ex[i].status).label + (ex[i].detail ? ' (' + ex[i].detail + ')' : '')));
                host.appendChild(ul2);
            }
        });
    }

    function importCsv() {
        var text = $('elCsvText').value;
        if (!text.trim()) { say($('elCsvResult'), 'danger', 'Paste at least one address first.'); return; }
        say($('elCsvResult'), 'muted', 'Importing...');
        api('POST', 'import_csv', { list_id: manage.id, csv_text: text }).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                var msg = 'Added ' + r.body.added + ', skipped ' + r.body.skipped + ', already on the list ' + r.body.duplicates + '.';
                if (r.body.errors && r.body.errors.length) msg += ' ' + r.body.errors.slice(0, 3).join(' ');
                say($('elCsvResult'), (r.body.skipped || (r.body.errors && r.body.errors.length)) ? 'danger' : 'success', msg);
                if (r.body.added) $('elCsvText').value = '';
                refreshDetail();
                load();
            } else say($('elCsvResult'), 'danger', errText(r));
        });
    }

    function saveEdit() {
        var name = $('elEditName').value.trim();
        if (!name) { say($('elEditMsg'), 'danger', 'The list needs a name.'); return; }
        api('POST', 'update', { id: manage.id, name: name, description: $('elEditDesc').value.trim() }).then(function (r) {
            if (r.status === 200 && r.body.ok) { say($('elEditMsg'), 'success', 'Saved.'); refreshDetail(); load(); }
            else say($('elEditMsg'), 'danger', errText(r));
        });
    }

    // ── wiring ──────────────────────────────────────────────────────────────
    function bind() {
        if (bound) return;
        if (!$('panel-email-lists')) return;
        bound = true;
        $('btnNewEmailList').addEventListener('click', openNew);
        $('elNewCreate').addEventListener('click', createList);
        $('elNewName').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); createList(); } });
        $('emailListFilter').addEventListener('input', render);
        $('elOptionsSave').addEventListener('click', saveOptions);
        $('elBtnOptions').addEventListener('click', function () { if (!optionsLoaded) loadOptions(); });
        $('elAddBtn').addEventListener('click', addEntry);
        $('elCsvBtn').addEventListener('click', importCsv);
        $('elEditSave').addEventListener('click', saveEdit);
        $('elPreviewDetails').addEventListener('toggle', function () { if ($('elPreviewDetails').open && manage.id) loadPreview(); });
        $('elInlineEmail').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addEntry(); } });
        $('elInlineName').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addEntry(); } });
        $('elAddBtn').addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && e.ctrlKey) { e.preventDefault(); addEntry(); }
        });
        $('elManageModal').addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && !$('elAddBtn').disabled) { e.preventDefault(); addEntry(); }
        });
        $('elHasEmail').addEventListener('change', function () { if (picker) { picker.clear(); setAddButton(null); } });
        var radios = document.querySelectorAll('#elTypeGroup input[name="elType"]');
        var i;
        for (i = 0; i < radios.length; i++) {
            radios[i].addEventListener('change', function () { selectType(currentType()); });
        }
        $('elManageModal').addEventListener('hidden.bs.modal', function () { manage.id = null; if (picker) picker.clear(); load(); });
        ensurePicker();
    }

    window.EmailListsAdmin = {
        load: load,
        helpers: { bannerText: bannerText, buildAddRequest: buildAddRequest, statusInfo: statusInfo, statuses: STATUS }
    };
})();
