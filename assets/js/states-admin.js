/**
 * NewUI v4.0 — States / Provinces admin page (GH#153).
 *
 * Drives states-admin.php against api/states.php:
 *   - the current list: filter, add, edit (code + name), delete;
 *   - the configuration wizard: choose built-in lists (or a one-click quick
 *     pick) -> their options -> replace or add -> a server-side preview
 *     (adds, removals with the number of saved records holding each code,
 *     renames, skipped codes, conflicts) -> apply -> optional field label;
 *   - CSV import (file or pasted text) with the same preview, and export;
 *   - downloads of each built-in list as CSV;
 *   - the field label (caption form.state).
 *
 * Every value from the server is written with textContent — never
 * innerHTML. ES5 only, one IIFE, no globals.
 */
(function () {
    'use strict';

    var API = 'api/states.php';
    var CUSTOM = '__custom__';

    var page = {
        list: [],
        catalog: null,
        currentLabel: 'State',
        lang: 'en',
        languages: [],
        editingId: 0
    };
    var wiz = { step: 1, selection: {}, mode: 'replace', preview: null, labelSuggestion: 'State' };
    var imp = { csv: '', mode: 'replace', preview: null };

    // ── small helpers ──────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }

    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = String(text); }
        return n;
    }

    function button(cls, text, icon) {
        var b = el('button', cls);
        b.type = 'button';
        if (icon) {
            var i = el('i', 'bi ' + icon + (text ? ' me-1' : ''));
            b.appendChild(i);
        }
        if (text) { b.appendChild(document.createTextNode(text)); }
        return b;
    }

    function clear(node) {
        while (node && node.firstChild) { node.removeChild(node.firstChild); }
    }

    function show(node, on) {
        if (node) { node.classList.toggle('d-none', !on); }
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : (many || one + 's'));
    }

    function parseReply(r) {
        return r.text().then(function (t) {
            var body = null;
            try { body = JSON.parse(t); } catch (e) { body = null; }
            if (!body || typeof body !== 'object') {
                body = { error: 'The server sent an unexpected reply (HTTP ' + r.status + ').' };
            }
            return { ok: r.ok, code: r.status, body: body };
        });
    }

    function apiGet(qs) {
        return fetch(API + '?' + qs, { credentials: 'same-origin' }).then(parseReply);
    }

    function apiPost(payload) {
        payload.csrf_token = csrf();
        return fetch(API, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(parseReply);
    }

    function failText(res) {
        return (res && res.body && res.body.error) ? res.body.error : 'The request failed.';
    }

    var toastTimer = null;
    function toast(msg, kind) {
        var t = $('stToast');
        if (!t) { return; }
        t.className = 'alert alert-' + (kind || 'success');
        t.textContent = msg;
        if (toastTimer) { clearTimeout(toastTimer); }
        toastTimer = setTimeout(function () { t.className = 'alert d-none'; }, kind === 'danger' ? 12000 : 7000);
    }

    function showError(node, msg) {
        if (!node) { return; }
        node.textContent = msg || '';
        show(node, !!msg);
    }

    // ── the current list ───────────────────────────────────────────────
    function loadList() {
        return apiGet('action=list').then(function (res) {
            if (!res.ok) {
                toast(failText(res), 'danger');
                return;
            }
            var d = res.body;
            page.list = d.states || [];
            page.currentLabel = d.field_label || 'State';
            page.lang = d.label_lang || 'en';
            page.languages = d.languages || ['en'];
            $('stCount').textContent = String(d.count);
            renderList();
            renderSchema(d.schema || {});
            renderLabelCard();
        }).catch(function () {
            toast('Could not load the list. Check your connection and reload the page.', 'danger');
        });
    }

    function renderSchema(s) {
        var banner = $('stSchemaBanner');
        clear(banner);
        var problems = [];
        if (s.utf8mb4 === false) {
            problems.push('The table cannot store every accented name yet. Run php sql/run_migrations.php on the server.');
        }
        if (s.duplicates && s.duplicates.length) {
            var codes = [];
            for (var i = 0; i < s.duplicates.length; i++) {
                codes.push(s.duplicates[i].code + ' (' + s.duplicates[i].rows + ' rows)');
            }
            problems.push('These codes appear more than once: ' + codes.join(', ')
                + '. Delete or recode the extra entries, then run php sql/run_gh153_states_translator_utf8mb4.php on the server to make codes unique.');
        } else if (s.unique_code === false && s.utf8mb4 !== false) {
            problems.push('Codes are not yet protected by a unique key. Run php sql/run_gh153_states_translator_utf8mb4.php on the server.');
        }
        for (var j = 0; j < problems.length; j++) {
            banner.appendChild(el('div', '', problems[j]));
        }
        show(banner, problems.length > 0);
    }

    function filterText() {
        var f = $('stFilter');
        return f ? f.value.replace(/^\s+|\s+$/g, '').toLowerCase() : '';
    }

    function renderList() {
        var body = $('stRows');
        clear(body);
        var q = filterText();
        var shown = 0;
        for (var i = 0; i < page.list.length; i++) {
            var r = page.list[i];
            if (q && r.code.toLowerCase().indexOf(q) === -1 && r.name.toLowerCase().indexOf(q) === -1) {
                continue;
            }
            body.appendChild(page.editingId === r.id ? editRow(r) : viewRow(r));
            shown++;
        }
        if (!shown) {
            var tr = el('tr');
            var td = el('td', 'text-body-secondary', page.list.length
                ? 'Nothing matches the filter.'
                : 'The list is empty, so every State dropdown is empty. Use the configuration wizard or Add entry.');
            td.colSpan = 3;
            tr.appendChild(td);
            body.appendChild(tr);
        }
    }

    function viewRow(r) {
        var tr = el('tr');
        tr.appendChild(el('td', 'font-monospace', r.code));
        tr.appendChild(el('td', '', r.name));
        var td = el('td', 'text-end text-nowrap');
        var edit = button('btn btn-sm btn-outline-secondary me-1', 'Edit', 'bi-pencil');
        edit.addEventListener('click', function () {
            page.editingId = r.id;
            renderList();
            var c = $('stEditCode');
            if (c) { c.focus(); }
        });
        var del = button('btn btn-sm btn-outline-danger', '', 'bi-trash');
        del.setAttribute('aria-label', 'Delete ' + r.code + ' — ' + r.name);
        del.title = 'Delete';
        del.addEventListener('click', function () { deleteRow(r, del); });
        td.appendChild(edit);
        td.appendChild(del);
        tr.appendChild(td);
        return tr;
    }

    function editRow(r) {
        var tr = el('tr', 'table-active');
        var c1 = el('td');
        var code = el('input', 'form-control form-control-sm font-monospace text-uppercase');
        code.id = 'stEditCode';
        code.maxLength = 4;
        code.value = r.code;
        code.setAttribute('aria-label', 'Code');
        c1.appendChild(code);
        var c2 = el('td');
        var name = el('input', 'form-control form-control-sm');
        name.id = 'stEditName';
        name.maxLength = 64;
        name.value = r.name;
        name.setAttribute('aria-label', 'Name');
        c2.appendChild(name);
        var c3 = el('td', 'text-end text-nowrap');
        var save = button('btn btn-sm btn-success me-1', 'Save', 'bi-check-lg');
        var cancel = button('btn btn-sm btn-outline-secondary', 'Cancel');
        save.addEventListener('click', function () { saveEdit(r, code.value, name.value, save); });
        cancel.addEventListener('click', function () { page.editingId = 0; renderList(); });
        function onKey(e) {
            if (e.key === 'Enter') { e.preventDefault(); saveEdit(r, code.value, name.value, save); }
            if (e.key === 'Escape') { e.preventDefault(); page.editingId = 0; renderList(); }
        }
        code.addEventListener('keydown', onKey);
        name.addEventListener('keydown', onKey);
        c3.appendChild(save);
        c3.appendChild(cancel);
        tr.appendChild(c1);
        tr.appendChild(c2);
        tr.appendChild(c3);
        return tr;
    }

    function saveEdit(r, code, name, btn) {
        btn.disabled = true;
        apiPost({ action: 'update', id: r.id, code: code, name: name }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) { toast(failText(res), 'danger'); return; }
            page.editingId = 0;
            var d = res.body;
            var msg = d.changed ? 'Saved ' + d.code + ' — ' + d.name + '.' : 'Nothing changed.';
            if (d.changed && d.old_code && d.old_code.toUpperCase() !== d.code && d.old_code_usage > 0) {
                msg += ' ' + plural(d.old_code_usage, 'saved record') + ' still hold the old code '
                    + d.old_code + ' and keep showing it.';
            }
            toast(msg + ' Reload open pages to see the change.', 'success');
            loadList();
        }).catch(function () { btn.disabled = false; toast('The save did not reach the server.', 'danger'); });
    }

    function deleteRow(r, btn) {
        btn.disabled = true;
        apiGet('action=usage&codes=' + encodeURIComponent(r.code)).then(function (res) {
            var u = (res.ok && res.body.usage) ? res.body.usage[r.code.toUpperCase()] : null;
            var n = u ? u.total : 0;
            var msg = 'Remove ' + r.code + ' — ' + r.name + ' from the list?';
            if (n > 0) {
                msg += '\n\n' + plural(n, 'saved record') + ' use this code (' + usageText(u.by) + '). '
                    + 'They keep the code ' + r.code + ' and still show it; it just stops being offered for new entries.';
            }
            if (!window.confirm(msg)) { btn.disabled = false; return null; }
            return apiPost({ action: 'delete', id: r.id }).then(function (res2) {
                btn.disabled = false;
                if (!res2.ok) { toast(failText(res2), 'danger'); return; }
                toast('Removed ' + r.code + ' — ' + r.name + '. Reload open pages to see the change.', 'success');
                loadList();
            });
        }).catch(function () { btn.disabled = false; toast('The request did not reach the server.', 'danger'); });
    }

    function usageText(by) {
        var parts = [];
        var merged = {};
        var order = [];
        for (var i = 0; i < (by || []).length; i++) {
            var label = by[i].label;
            if (!merged[label]) { merged[label] = 0; order.push(label); }
            merged[label] += by[i].count;
        }
        for (var j = 0; j < order.length; j++) {
            parts.push(order[j] + ': ' + merged[order[j]]);
        }
        return parts.join(', ');
    }

    function initAddRow() {
        $('stBtnAdd').addEventListener('click', function () {
            show($('stAddRow'), true);
            $('stAddCode').focus();
        });
        $('stAddCancel').addEventListener('click', function () {
            $('stAddCode').value = '';
            $('stAddName').value = '';
            show($('stAddRow'), false);
        });
        function add() {
            var btn = $('stAddSave');
            btn.disabled = true;
            apiPost({ action: 'create', code: $('stAddCode').value, name: $('stAddName').value }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) { toast(failText(res), 'danger'); return; }
                var s = res.body.state;
                toast('Added ' + s.code + ' — ' + s.name + '. Reload open pages to see it.', 'success');
                $('stAddCode').value = '';
                $('stAddName').value = '';
                $('stAddCode').focus();
                loadList();
            }).catch(function () { btn.disabled = false; toast('The save did not reach the server.', 'danger'); });
        }
        $('stAddSave').addEventListener('click', add);
        function onKey(e) { if (e.key === 'Enter') { e.preventDefault(); add(); } }
        $('stAddCode').addEventListener('keydown', onKey);
        $('stAddName').addEventListener('keydown', onKey);
        $('stFilter').addEventListener('input', renderList);
    }

    // ── field label ────────────────────────────────────────────────────
    function labelChoices() {
        return (page.catalog && page.catalog.label_choices) ? page.catalog.label_choices
            : ['State', 'Province', 'State or Province', 'Region', 'County'];
    }

    function fillLabelSelect(sel, custom, current) {
        clear(sel);
        var choices = labelChoices();
        var matched = false;
        for (var i = 0; i < choices.length; i++) {
            var o = el('option', '', choices[i]);
            o.value = choices[i];
            if (choices[i] === current) { o.selected = true; matched = true; }
            sel.appendChild(o);
        }
        var c = el('option', '', 'Something else…');
        c.value = CUSTOM;
        sel.appendChild(c);
        if (!matched && current) {
            sel.value = CUSTOM;
            custom.value = current;
        }
        show(custom, sel.value === CUSTOM);
    }

    function chosenLabel(sel, custom) {
        return sel.value === CUSTOM ? custom.value : sel.value;
    }

    function renderLabelCard() {
        $('stLabelCurrent').textContent = '"' + page.currentLabel + '"';
        fillLabelSelect($('stLabelChoice'), $('stLabelCustom'), page.currentLabel);
        var lang = $('stLabelLang');
        clear(lang);
        var langs = page.languages.length ? page.languages : ['en'];
        for (var i = 0; i < langs.length; i++) {
            var o = el('option', '', langs[i]);
            o.value = langs[i];
            if (langs[i] === page.lang) { o.selected = true; }
            lang.appendChild(o);
        }
    }

    function saveLabel(label, lang, btn) {
        if (btn) { btn.disabled = true; }
        return apiPost({ action: 'set_label', label: label, lang: lang }).then(function (res) {
            if (btn) { btn.disabled = false; }
            if (!res.ok) { toast(failText(res), 'danger'); return false; }
            toast('The State fields are now called "' + res.body.label + '" (' + res.body.lang + '). Reload open pages to see it.', 'success');
            loadList();
            return true;
        }).catch(function () {
            if (btn) { btn.disabled = false; }
            toast('The save did not reach the server.', 'danger');
            return false;
        });
    }

    function initLabelCard() {
        $('stLabelChoice').addEventListener('change', function () {
            show($('stLabelCustom'), this.value === CUSTOM);
        });
        $('stLabelSave').addEventListener('click', function () {
            saveLabel(chosenLabel($('stLabelChoice'), $('stLabelCustom')), $('stLabelLang').value, this);
        });
    }

    // ── presets: catalog + downloads ───────────────────────────────────
    function loadCatalog() {
        return apiGet('action=presets').then(function (res) {
            if (!res.ok) { toast(failText(res), 'danger'); return; }
            page.catalog = res.body;
            renderDownloads();
            renderLabelCard();
        });
    }

    function presetById(id) {
        var ps = page.catalog ? page.catalog.presets : [];
        for (var i = 0; i < ps.length; i++) {
            if (ps[i].id === id) { return ps[i]; }
        }
        return null;
    }

    function renderDownloads() {
        var ul = $('stPresetDownloads');
        clear(ul);
        var ps = page.catalog.presets;
        for (var i = 0; i < ps.length; i++) {
            var p = ps[i];
            var li = el('li', 'd-flex align-items-start gap-2 mb-2');
            var a = el('a', 'btn btn-sm btn-outline-secondary flex-shrink-0');
            a.href = API + '?action=preset_csv&preset=' + encodeURIComponent(p.id);
            a.setAttribute('aria-label', 'Download ' + p.name + ' as CSV');
            a.appendChild(el('i', 'bi bi-download'));
            var txt = el('div');
            txt.appendChild(el('div', 'fw-semibold', p.name));
            var defaults = [];
            for (var j = 0; j < p.options.length; j++) {
                if (p.options[j].default) { defaults.push(p.options[j].label); }
            }
            txt.appendChild(el('div', 'text-body-secondary',
                plural(p.count, 'entry', 'entries') + (defaults.length ? ' + ' + defaults.join(', ') : '') + ' · ' + p.source));
            li.appendChild(a);
            li.appendChild(txt);
            ul.appendChild(li);
        }
    }

    // ── the wizard ─────────────────────────────────────────────────────
    var wizModal = null;

    function openWizard() {
        if (!page.catalog) { toast('The built-in lists are still loading.', 'warning'); return; }
        wiz = { step: 1, selection: {}, mode: 'replace', preview: null, labelSuggestion: 'State' };
        $('stWizModeReplace').checked = true;
        renderWizardChoose();
        goStep(1);
        if (!wizModal) { wizModal = new bootstrap.Modal($('stWizard')); }
        wizModal.show();
    }

    function selectedIds() {
        var ids = [];
        var ps = page.catalog.presets;
        for (var i = 0; i < ps.length; i++) {
            if (Object.prototype.hasOwnProperty.call(wiz.selection, ps[i].id)) { ids.push(ps[i].id); }
        }
        return ids;
    }

    function renderWizardChoose() {
        var picks = $('stQuickPicks');
        clear(picks);
        var qp = page.catalog.quick_picks;
        for (var i = 0; i < qp.length; i++) {
            (function (q) {
                var b = button('btn btn-sm btn-outline-primary', q.label);
                b.addEventListener('click', function () {
                    wiz.selection = {};
                    for (var pid in q.selection) {
                        if (Object.prototype.hasOwnProperty.call(q.selection, pid)) {
                            wiz.selection[pid] = q.selection[pid].slice();
                        }
                    }
                    renderWizardChoose();
                    goStep(3);
                });
                picks.appendChild(b);
            })(qp[i]);
        }
        var box = $('stPresetChecks');
        clear(box);
        var ps = page.catalog.presets;
        for (var j = 0; j < ps.length; j++) {
            (function (p) {
                var wrap = el('div', 'form-check');
                var cb = el('input', 'form-check-input');
                cb.type = 'checkbox';
                cb.id = 'stPreset_' + p.id;
                cb.checked = Object.prototype.hasOwnProperty.call(wiz.selection, p.id);
                cb.addEventListener('change', function () {
                    if (cb.checked) {
                        var defaults = [];
                        for (var k = 0; k < p.options.length; k++) {
                            if (p.options[k].default) { defaults.push(p.options[k].key); }
                        }
                        wiz.selection[p.id] = defaults;
                    } else {
                        delete wiz.selection[p.id];
                    }
                });
                var lab = el('label', 'form-check-label small');
                lab.htmlFor = cb.id;
                lab.appendChild(el('span', 'fw-semibold', p.name));
                lab.appendChild(el('span', 'text-body-secondary', ' — ' + p.source));
                wrap.appendChild(cb);
                wrap.appendChild(lab);
                box.appendChild(wrap);
            })(ps[j]);
        }
    }

    function renderWizardOptions() {
        var box = $('stOptionGroups');
        clear(box);
        var ids = selectedIds();
        var any = false;
        for (var i = 0; i < ids.length; i++) {
            var p = presetById(ids[i]);
            if (!p || !p.options.length) { continue; }
            any = true;
            var fs = el('fieldset', 'mb-3');
            fs.appendChild(el('legend', 'fs-6 fw-semibold', p.name + ' (' + plural(p.count, 'entry', 'entries') + ')'));
            for (var j = 0; j < p.options.length; j++) {
                (function (pid, o) {
                    var wrap = el('div', 'form-check');
                    var cb = el('input', 'form-check-input');
                    cb.type = 'checkbox';
                    cb.id = 'stOpt_' + pid + '_' + o.key;
                    cb.checked = wiz.selection[pid].indexOf(o.key) !== -1;
                    cb.addEventListener('change', function () {
                        var list = wiz.selection[pid];
                        var at = list.indexOf(o.key);
                        if (cb.checked && at === -1) { list.push(o.key); }
                        if (!cb.checked && at !== -1) { list.splice(at, 1); }
                    });
                    var lab = el('label', 'form-check-label small', o.label + ' — ' + plural(o.count, 'entry', 'entries'));
                    lab.htmlFor = cb.id;
                    wrap.appendChild(cb);
                    wrap.appendChild(lab);
                    fs.appendChild(wrap);
                })(p.id, p.options[j]);
            }
            box.appendChild(fs);
        }
        if (!any) {
            box.appendChild(el('p', 'small text-body-secondary', 'The lists you chose have no options. Press Next.'));
        }
    }

    function goStep(n) {
        wiz.step = n;
        showError($('stWizError'), '');
        for (var i = 1; i <= 5; i++) { show($('stWizStep' + i), i === n); }
        var items = $('stWizSteps').querySelectorAll('li');
        for (var j = 0; j < items.length; j++) {
            var on = Number(items[j].getAttribute('data-step')) === n;
            items[j].classList.toggle('fw-bold', on);
            items[j].classList.toggle('text-primary', on);
            if (on) { items[j].setAttribute('aria-current', 'step'); } else { items[j].removeAttribute('aria-current'); }
        }
        if (n === 2) { renderWizardOptions(); }
        show($('stWizBack'), n > 1 && n < 5);
        show($('stWizNext'), n < 4);
        show($('stWizApply'), n === 4 && !!wiz.preview);
        show($('stWizDownload'), n >= 2 && n <= 4);
        show($('stWizSetLabel'), n === 5);
        show($('stWizDone'), n === 5);
        if (n === 4) { runPreview(); }
    }

    function wizMode() {
        return $('stWizModeAdd').checked ? 'add' : 'replace';
    }

    function wizNext() {
        if (wiz.step === 1 && selectedIds().length === 0) {
            showError($('stWizError'), 'Choose at least one list, or press one of the one-click buttons.');
            return;
        }
        goStep(wiz.step + 1);
    }

    function selectionJson() {
        var out = {};
        var ids = selectedIds();
        for (var i = 0; i < ids.length; i++) { out[ids[i]] = wiz.selection[ids[i]]; }
        return out;
    }

    function runPreview() {
        wiz.mode = wizMode();
        wiz.preview = null;
        var box = $('stWizPreview');
        clear(box);
        box.appendChild(el('p', 'small text-body-secondary', 'Working out what would change…'));
        show($('stWizApply'), false);
        apiPost({ action: 'preset_preview', selection: selectionJson(), mode: wiz.mode }).then(function (res) {
            clear(box);
            if (!res.ok) { showError($('stWizError'), failText(res)); return; }
            wiz.preview = res.body;
            wiz.labelSuggestion = res.body.label_suggestion || 'State';
            renderPreview(box, res.body);
            show($('stWizApply'), wiz.step === 4 && !res.body.too_many);
        }).catch(function () { showError($('stWizError'), 'The preview did not reach the server.'); });
    }

    function previewTable(rows, cols) {
        var wrap = el('div', 'table-responsive mb-2');
        wrap.style.maxHeight = '14rem';
        var t = el('table', 'table table-sm mb-0');
        var thead = el('thead');
        var hr = el('tr');
        for (var i = 0; i < cols.length; i++) { hr.appendChild(el('th', '', cols[i][0])); }
        thead.appendChild(hr);
        t.appendChild(thead);
        var tb = el('tbody');
        for (var j = 0; j < rows.length; j++) {
            var tr = el('tr');
            for (var k = 0; k < cols.length; k++) {
                tr.appendChild(el('td', k === 0 ? 'font-monospace' : '', cols[k][1](rows[j])));
            }
            tb.appendChild(tr);
        }
        t.appendChild(tb);
        wrap.appendChild(t);
        return wrap;
    }

    function section(box, title, rows, cols, cls) {
        if (!rows || !rows.length) { return; }
        box.appendChild(el('h6', 'mt-3 ' + (cls || ''), title + ' (' + rows.length + ')'));
        box.appendChild(previewTable(rows, cols));
    }

    function renderPreview(box, p) {
        var summary = el('div', 'alert alert-info small');
        var lines = [];
        if (p.mode === 'replace') {
            lines.push('The list will be REPLACED: it goes from ' + plural(p.current_count, 'entry', 'entries')
                + ' to ' + plural(p.final_count, 'entry', 'entries') + '.');
        } else {
            lines.push('Entries will be ADDED: the list goes from ' + plural(p.current_count, 'entry', 'entries')
                + ' to ' + plural(p.final_count, 'entry', 'entries') + '. Nothing is removed.');
        }
        lines.push(p.adds.length + ' added, ' + p.removes.length + ' removed, ' + p.renames.length + ' renamed, '
            + p.unchanged + ' unchanged' + (p.skipped.length ? ', ' + p.skipped.length + ' skipped (already on the list)' : '') + '.');
        for (var i = 0; i < lines.length; i++) { summary.appendChild(el('div', '', lines[i])); }
        box.appendChild(summary);

        if (p.final_count === 0) {
            box.appendChild(el('div', 'alert alert-warning small', 'This leaves the list EMPTY: every State dropdown will offer nothing.'));
        }
        if (p.too_many) {
            box.appendChild(el('div', 'alert alert-danger small', 'The result is larger than the list can hold. Choose fewer lists.'));
        }
        if (p.removes.length) {
            var warn = el('div', 'alert alert-warning small');
            warn.appendChild(el('div', 'fw-semibold', p.records_using_removed > 0
                ? plural(p.records_using_removed, 'saved record') + ' use codes that would be removed.'
                : 'No saved record uses any of the codes that would be removed.'));
            warn.appendChild(el('div', '', 'Saved records are never changed: they keep their code and still show it. '
                + 'Removed codes are simply no longer offered for new entries.'));
            box.appendChild(warn);
        }
        if (p.conflicts && p.conflicts.length) {
            section(box, 'Codes used by two of the lists you chose — the first is kept', p.conflicts, [
                ['Code', function (r) { return r.code; }],
                ['Kept', function (r) { return r.kept + ' (' + r.kept_from + ')'; }],
                ['Not added', function (r) { return r.dropped + ' (' + r.dropped_from + ')'; }]
            ], 'text-warning-emphasis');
        }
        section(box, 'To remove', p.removes, [
            ['Code', function (r) { return r.code; }],
            ['Name', function (r) { return r.name + (r.duplicate ? ' (duplicate row)' : ''); }],
            ['Saved records using it', function (r) { return r.usage ? r.usage + ' — ' + usageText(r.usage_by) : 'none'; }]
        ], 'text-danger');
        section(box, 'To add', p.adds, [
            ['Code', function (r) { return r.code; }],
            ['Name', function (r) { return r.name; }]
        ], 'text-success');
        section(box, 'To rename', p.renames, [
            ['Code', function (r) { return r.code; }],
            ['Now', function (r) { return r.old_name; }],
            ['Becomes', function (r) { return r.new_name; }]
        ]);
        section(box, 'Skipped — already on the list', p.skipped, [
            ['Code', function (r) { return r.code; }],
            ['On the list as', function (r) { return r.existing_name; }],
            ['Would have been', function (r) { return r.name; }]
        ], 'text-body-secondary');
    }

    function wizApply() {
        var p = wiz.preview;
        if (!p) { return; }
        if (p.final_count === 0 && !window.confirm('This empties the list. Every State dropdown will offer nothing. Continue?')) {
            return;
        }
        var btn = $('stWizApply');
        btn.disabled = true;
        apiPost({ action: 'preset_apply', selection: selectionJson(), mode: wiz.mode, list_hash: p.list_hash }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) {
                showError($('stWizError'), failText(res));
                if (res.code === 409) { runPreview(); }
                return;
            }
            var d = res.body;
            $('stWizResult').textContent = d.changed
                ? 'Done: ' + d.added + ' added, ' + d.removed + ' removed, ' + d.renamed + ' renamed. The list now has '
                    + plural(d.final_count, 'entry', 'entries') + '. Pages that are already open show the new list after a reload.'
                : 'Nothing needed to change: the list already matched your choice.';
            wiz.labelSuggestion = d.label_suggestion || wiz.labelSuggestion;
            $('stWizLabelSuggest').textContent = '"' + wiz.labelSuggestion + '"';
            fillLabelSelect($('stWizLabelChoice'), $('stWizLabelCustom'), wiz.labelSuggestion);
            goStep(5);
            loadList();
        }).catch(function () { btn.disabled = false; showError($('stWizError'), 'The apply did not reach the server.'); });
    }

    function wizDownload() {
        var ids = selectedIds();
        if (!ids.length) { showError($('stWizError'), 'Choose at least one list first.'); return; }
        window.location.href = API + '?action=preset_csv&selection=' + encodeURIComponent(JSON.stringify(selectionJson()));
    }

    function initWizard() {
        $('stBtnWizard').addEventListener('click', openWizard);
        $('stWizNext').addEventListener('click', wizNext);
        $('stWizBack').addEventListener('click', function () {
            goStep(wiz.step === 3 && !hasOptions() ? 1 : wiz.step - 1);
        });
        $('stWizApply').addEventListener('click', wizApply);
        $('stWizDownload').addEventListener('click', wizDownload);
        $('stWizLabelChoice').addEventListener('change', function () {
            show($('stWizLabelCustom'), this.value === CUSTOM);
        });
        $('stWizSetLabel').addEventListener('click', function () {
            var btn = this;
            saveLabel(chosenLabel($('stWizLabelChoice'), $('stWizLabelCustom')), page.lang, btn).then(function (ok) {
                if (ok && wizModal) { wizModal.hide(); }
            });
        });
    }

    function hasOptions() {
        var ids = selectedIds();
        for (var i = 0; i < ids.length; i++) {
            var p = presetById(ids[i]);
            if (p && p.options.length) { return true; }
        }
        return false;
    }

    // ── import ─────────────────────────────────────────────────────────
    var impModal = null;

    function openImport() {
        imp = { csv: '', mode: 'replace', preview: null };
        $('stImportFile').value = '';
        $('stImportText').value = '';
        $('stImportModeReplace').checked = true;
        clear($('stImportPreview'));
        showError($('stImportError'), '');
        show($('stImportApply'), false);
        if (!impModal) { impModal = new bootstrap.Modal($('stImport')); }
        impModal.show();
    }

    function readImportText() {
        var file = $('stImportFile').files && $('stImportFile').files[0];
        if (!file) {
            return Promise.resolve($('stImportText').value);
        }
        if (file.size > 262144) {
            return Promise.reject(new Error('The file is larger than 256 KB.'));
        }
        return new Promise(function (resolve, reject) {
            var fr = new FileReader();
            fr.onload = function () { resolve(String(fr.result || '')); };
            fr.onerror = function () { reject(new Error('The file could not be read.')); };
            fr.readAsText(file, 'UTF-8');
        });
    }

    function importPreview() {
        showError($('stImportError'), '');
        show($('stImportApply'), false);
        var box = $('stImportPreview');
        clear(box);
        readImportText().then(function (text) {
            imp.csv = text;
            imp.mode = $('stImportModeAdd').checked ? 'add' : 'replace';
            if (!text.replace(/\s+/g, '')) {
                showError($('stImportError'), 'Choose a file or paste the CSV text first.');
                return null;
            }
            box.appendChild(el('p', 'small text-body-secondary', 'Checking the file…'));
            return apiPost({ action: 'import_preview', csv: text, mode: imp.mode }).then(function (res) {
                clear(box);
                if (!res.ok) { showError($('stImportError'), failText(res)); return; }
                var d = res.body;
                if (d.errors && d.errors.length) {
                    var al = el('div', 'alert alert-danger small');
                    al.appendChild(el('div', 'fw-semibold', 'The file has ' + plural(d.errors.length, 'problem') + '. Nothing was changed. Fix them and preview again:'));
                    var ul = el('ul', 'mb-0');
                    for (var i = 0; i < d.errors.length && i < 50; i++) {
                        ul.appendChild(el('li', '', (d.errors[i].line ? 'Line ' + d.errors[i].line + ': ' : '') + d.errors[i].message));
                    }
                    al.appendChild(ul);
                    box.appendChild(al);
                    return;
                }
                imp.preview = d;
                renderPreview(box, d);
                show($('stImportApply'), !d.too_many);
            });
        }).catch(function (e) {
            showError($('stImportError'), (e && e.message) ? e.message : 'The file could not be checked.');
        });
    }

    function importApply() {
        var p = imp.preview;
        if (!p) { return; }
        if (p.final_count === 0 && !window.confirm('This empties the list. Every State dropdown will offer nothing. Continue?')) {
            return;
        }
        var btn = $('stImportApply');
        btn.disabled = true;
        apiPost({ action: 'import_apply', csv: imp.csv, mode: imp.mode, list_hash: p.list_hash }).then(function (res) {
            btn.disabled = false;
            if (!res.ok) {
                showError($('stImportError'), failText(res));
                if (res.code === 409) { importPreview(); }
                return;
            }
            var d = res.body;
            if (impModal) { impModal.hide(); }
            toast(d.changed
                ? 'Imported: ' + d.added + ' added, ' + d.removed + ' removed, ' + d.renamed + ' renamed; the list has '
                    + plural(d.final_count, 'entry', 'entries') + '. Reload open pages to see it.'
                : 'Nothing needed to change: the list already matched the file.', 'success');
            loadList();
        }).catch(function () { btn.disabled = false; showError($('stImportError'), 'The import did not reach the server.'); });
    }

    function initImport() {
        $('stBtnImport').addEventListener('click', openImport);
        $('stImportPreviewBtn').addEventListener('click', importPreview);
        $('stImportApply').addEventListener('click', importApply);
        $('stImportFile').addEventListener('change', function () { imp.preview = null; show($('stImportApply'), false); });
        $('stImportText').addEventListener('input', function () { imp.preview = null; show($('stImportApply'), false); });
        $('stImportModeReplace').addEventListener('change', function () { imp.preview = null; show($('stImportApply'), false); });
        $('stImportModeAdd').addEventListener('change', function () { imp.preview = null; show($('stImportApply'), false); });
    }

    // ── start ──────────────────────────────────────────────────────────
    function init() {
        if (!$('stRows')) { return; }
        initAddRow();
        initLabelCard();
        initWizard();
        initImport();
        loadCatalog();
        loadList();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
