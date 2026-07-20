/*
 * NewUI v4.0 — Translations / i18n admin panel (Phase 8)
 *
 * Backs the #panel-translations UI in settings.php. Drives the captions_i18n
 * table via /api/captions.php. Pure ES5 IIFE, no jQuery, no template literals.
 *
 * Lifecycle:
 *   - Loaded by settings.php on every page render.
 *   - Idle until the Translations sidebar tab is activated (config.js calls
 *     onPanelActivated('translations') by hash navigation or click). We hook
 *     that via a small polyfill: on first visibility of #panel-translations,
 *     fetch + render.
 *
 * State (closure-scoped):
 *   captions   {key: {category, perLang: {lang: {id, value}}}}
 *   languages  ['en','de',...]
 *   categories sorted unique categories present in the data
 *
 * Persistence: every edit/add/delete is an immediate API call. There is no
 * "Save All" button — that matches the rest of NewUI's per-row save pattern.
 */
(function () {
    'use strict';

    // Built-in display names mirror language-switcher.js. Kept independent
    // so each file is self-contained.
    var LANG_NAMES = {
        en: 'English',  de: 'Deutsch',     fr: 'Français',  es: 'Español',
        it: 'Italiano', pt: 'Português',   nl: 'Nederlands',sv: 'Svenska',
        no: 'Norsk',    da: 'Dansk',       fi: 'Suomi',     pl: 'Polski',
        cs: 'Čeština',  ja: '日本語',      ko: '한국어',     zh: '中文',
        ar: 'العربية',  he: 'עברית',       ru: 'Русский',  uk: 'Українська'
    };

    function langDisplay(code) {
        return LANG_NAMES[code] || code.toUpperCase();
    }

    function csrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : (window.CSRF_TOKEN || '');
    }

    // ── State ─────────────────────────────────────────────────────────────
    var captions   = {};
    var languages  = [];
    var categories = [];
    var inited     = false;

    // ── Init lifecycle ────────────────────────────────────────────────────
    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    /*
     * Wait for the translations panel to become visible (config.js adds
     * the .active class to the right panel after a sidebar click). On
     * first activation we load data; subsequent activations are no-ops.
     */
    function bindActivation() {
        var panel = document.getElementById('panel-translations');
        if (!panel) return;

        // MutationObserver on class changes (fires when .active is added)
        if (typeof window.MutationObserver === 'function') {
            var obs = new MutationObserver(function () {
                if (panel.classList.contains('active') && !inited) {
                    inited = true;
                    loadAll();
                }
            });
            obs.observe(panel, { attributes: true, attributeFilter: ['class'] });
        }

        // Hash-based deep-link: if user lands on settings.php#translations,
        // activate ourselves on load.
        if (window.location.hash === '#translations' && !inited) {
            // Give config.js a tick to wire its own tab handler.
            setTimeout(function () {
                if (!inited) { inited = true; loadAll(); }
            }, 250);
        }
    }

    // ── Data loading ──────────────────────────────────────────────────────
    function loadAll() {
        fetch('api/captions.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                buildState(data);
                renderHead();
                renderBody();
                populateCategoryFilter();
                updateCount();
            })
            .catch(function (err) {
                renderError('Failed to load captions: ' + (err && err.message ? err.message : 'unknown'));
            });
    }

    function buildState(data) {
        captions = {};
        languages = (data.languages || []).slice();
        if (languages.length === 0) languages = ['en'];

        // Ensure en first, current second, rest alphabetical for display order.
        languages.sort(function (a, b) {
            if (a === 'en') return -1;
            if (b === 'en') return 1;
            return a.localeCompare(b);
        });

        var rows = data.captions || [];
        var catSet = {};
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (!captions[r.caption_key]) {
                captions[r.caption_key] = { category: r.category || 'general', perLang: {} };
            }
            captions[r.caption_key].perLang[r.lang] = {
                id: parseInt(r.id, 10),
                value: r.value
            };
            catSet[r.category || 'general'] = true;
        }
        categories = Object.keys(catSet).sort();
    }

    // ── Render ────────────────────────────────────────────────────────────
    function renderHead() {
        var head = document.getElementById('trTableHead');
        if (!head) return;
        var totalKeys = Object.keys(captions).length;
        var html = '<th style="width:25%">Key</th>';
        html += '<th style="width:10%">Category</th>';
        for (var i = 0; i < languages.length; i++) {
            var lang = languages[i];
            var label = langDisplay(lang);
            // Phase 8b: completeness % per column.
            var filled = 0;
            var keys = Object.keys(captions);
            for (var j = 0; j < keys.length; j++) {
                var entry = captions[keys[j]];
                if (entry.perLang[lang] && entry.perLang[lang].value) filled++;
            }
            var pct = totalKeys > 0 ? Math.round(filled * 100 / totalKeys) : 0;
            var pctColor = pct >= 80 ? 'text-success' : (pct >= 40 ? 'text-warning' : 'text-danger');
            html += '<th>';
            html += '<span class="badge bg-secondary me-1" style="font-family:monospace;font-size:0.65rem">' +
                lang.toUpperCase() + '</span>';
            html += label;
            html += '<br><small class="' + pctColor + '" style="font-weight:400;font-size:0.7rem">' +
                pct + '% (' + filled + '/' + totalKeys + ')</small>';
            html += '</th>';
        }
        html += '<th style="width:60px"></th>';
        head.innerHTML = html;
    }

    function renderBody() {
        var body = document.getElementById('trTableBody');
        if (!body) return;

        var search = (document.getElementById('trSearch').value || '').toLowerCase();
        var catFilter = document.getElementById('trCategoryFilter').value || '';
        var showFilter = document.getElementById('trShowFilter').value || 'all';

        var keys = Object.keys(captions).sort();
        if (keys.length === 0) {
            body.innerHTML = '<tr><td colspan="99" class="text-center text-body-secondary py-3">' +
                'No captions yet. Click "Add Caption" to create one.</td></tr>';
            return;
        }

        var html = '';
        var matched = 0;
        for (var i = 0; i < keys.length; i++) {
            var key = keys[i];
            var entry = captions[key];
            if (catFilter && entry.category !== catFilter) continue;

            // Search across key and all values.
            if (search) {
                var hay = key.toLowerCase();
                for (var lang in entry.perLang) {
                    if (entry.perLang.hasOwnProperty(lang)) {
                        hay += ' ' + (entry.perLang[lang].value || '').toLowerCase();
                    }
                }
                if (hay.indexOf(search) === -1) continue;
            }

            // Show filter.
            if (showFilter === 'untranslated') {
                var hasGap = false;
                for (var j = 0; j < languages.length; j++) {
                    if (!entry.perLang[languages[j]] || !entry.perLang[languages[j]].value) {
                        hasGap = true;
                        break;
                    }
                }
                if (!hasGap) continue;
            }

            matched++;
            html += '<tr data-key="' + encodeAttr(key) + '">';
            html += '<td><code style="font-size:0.8rem">' + escapeHtml(key) + '</code></td>';
            html += '<td><small class="text-body-secondary">' + escapeHtml(entry.category) + '</small></td>';
            for (var k = 0; k < languages.length; k++) {
                var ln = languages[k];
                var cellEntry = entry.perLang[ln];
                var val = cellEntry ? cellEntry.value : '';
                html += '<td class="tr-cell" data-lang="' + ln + '" data-key="' + encodeAttr(key) + '" ';
                html += 'style="cursor:text;min-width:140px;' + (val ? '' : 'background:rgba(255,193,7,0.08)') + '">';
                html += escapeHtml(val || '—');
                html += '</td>';
            }
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-link text-danger p-0 tr-del" title="Delete caption" data-key="' +
                encodeAttr(key) + '"><i class="bi bi-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        }

        if (matched === 0) {
            html = '<tr><td colspan="99" class="text-center text-body-secondary py-3">' +
                'No captions match the current filters.</td></tr>';
        }

        body.innerHTML = html;

        bindCellHandlers();
        bindDeleteHandlers();
        document.getElementById('trCount').textContent = matched + ' / ' + keys.length + ' captions';
    }

    function populateCategoryFilter() {
        var sel = document.getElementById('trCategoryFilter');
        if (!sel) return;
        var existing = sel.value;
        var html = '<option value="">All</option>';
        for (var i = 0; i < categories.length; i++) {
            html += '<option value="' + encodeAttr(categories[i]) + '">' + escapeHtml(categories[i]) + '</option>';
        }
        sel.innerHTML = html;
        if (existing) sel.value = existing;
    }

    function updateCount() {
        var n = Object.keys(captions).length;
        var el = document.getElementById('trCount');
        if (el) el.textContent = n + ' captions';
    }

    function renderError(msg) {
        var body = document.getElementById('trTableBody');
        if (body) {
            body.innerHTML = '<tr><td colspan="99" class="text-center text-danger py-3">' +
                '<i class="bi bi-exclamation-triangle me-1"></i>' + escapeHtml(msg) + '</td></tr>';
        }
    }

    // ── Inline edit ───────────────────────────────────────────────────────
    function bindCellHandlers() {
        var cells = document.querySelectorAll('#trTableBody .tr-cell');
        for (var i = 0; i < cells.length; i++) {
            cells[i].addEventListener('click', onCellClick);
        }
    }

    function onCellClick(ev) {
        var cell = ev.currentTarget;
        if (cell.querySelector('input')) return; // already editing

        var key = decodeAttr(cell.getAttribute('data-key'));
        var lang = cell.getAttribute('data-lang');
        var current = (captions[key] && captions[key].perLang[lang]) ?
            captions[key].perLang[lang].value : '';

        var input = document.createElement('input');
        input.type = 'text';
        input.value = current;
        input.className = 'form-control form-control-sm';
        input.style.fontSize = '0.85rem';

        cell.innerHTML = '';
        cell.appendChild(input);
        input.focus();
        input.select();

        var committed = false;
        function commit() {
            if (committed) return;
            committed = true;
            var newVal = input.value;
            if (newVal === current) {
                // No change — just restore display.
                cell.innerHTML = escapeHtml(current || '—');
                if (!current) cell.style.background = 'rgba(255,193,7,0.08)';
                return;
            }
            saveCell(key, lang, newVal, cell);
        }
        function cancel() {
            if (committed) return;
            committed = true;
            cell.innerHTML = escapeHtml(current || '—');
            if (!current) cell.style.background = 'rgba(255,193,7,0.08)';
        }

        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            else if (e.key === 'Escape') { e.preventDefault(); cancel(); cell.removeChild(input); }
        });
    }

    function saveCell(key, lang, value, cell) {
        var entry = captions[key];
        var category = entry ? entry.category : 'general';

        cell.innerHTML = '<i class="bi bi-hourglass-split text-body-secondary"></i>';

        var existing = entry && entry.perLang[lang] ? entry.perLang[lang].id : null;
        var body = {
            caption_key: key,
            lang: lang,
            value: value,
            category: category,
            csrf_token: csrf()
        };
        if (existing) body.id = existing;
        body.action = 'save';

        fetch('api/captions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 200 && res.body.saved) {
                    if (!entry.perLang[lang]) entry.perLang[lang] = { id: res.body.id, value: value };
                    else { entry.perLang[lang].value = value; entry.perLang[lang].id = res.body.id || entry.perLang[lang].id; }
                    cell.innerHTML = escapeHtml(value || '—');
                    cell.style.background = value ? '' : 'rgba(255,193,7,0.08)';
                } else {
                    cell.innerHTML = '<span class="text-danger">save failed</span>';
                    if (typeof window.alert === 'function') window.alert((res.body && res.body.error) || 'Save failed');
                }
            })
            .catch(function (err) {
                cell.innerHTML = '<span class="text-danger">network error</span>';
                if (typeof window.alert === 'function') window.alert('Save failed: ' + (err && err.message ? err.message : 'unknown'));
            });
    }

    // ── Delete row ────────────────────────────────────────────────────────
    function bindDeleteHandlers() {
        var btns = document.querySelectorAll('#trTableBody .tr-del');
        for (var i = 0; i < btns.length; i++) {
            btns[i].addEventListener('click', onDelete);
        }
    }

    function onDelete(ev) {
        ev.preventDefault();
        var key = decodeAttr(ev.currentTarget.getAttribute('data-key'));
        if (!key) return;
        if (!window.confirm('Delete caption "' + key + '" and all its translations?')) return;

        var entry = captions[key];
        if (!entry) return;
        var ids = [];
        for (var lang in entry.perLang) {
            if (entry.perLang.hasOwnProperty(lang) && entry.perLang[lang].id) {
                ids.push(entry.perLang[lang].id);
            }
        }

        var pending = ids.length;
        if (pending === 0) {
            delete captions[key];
            renderBody();
            return;
        }

        for (var i = 0; i < ids.length; i++) {
            (function (id) {
                fetch('api/captions.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                    body: JSON.stringify({ action: 'delete', id: id, csrf_token: csrf() })
                }).then(function () {
                    pending--;
                    if (pending === 0) {
                        delete captions[key];
                        renderBody();
                        updateCount();
                    }
                });
            })(ids[i]);
        }
    }

    // ── Add caption ───────────────────────────────────────────────────────
    function onAddCaption() {
        var key = window.prompt('New caption key (e.g. nav.menu.foo or btn.confirm):');
        if (!key) return;
        key = key.trim();
        if (!/^[a-z0-9_.-]{2,128}$/i.test(key)) {
            window.alert('Key must be 2-128 chars, alphanumeric / dot / underscore / dash.');
            return;
        }
        if (captions[key]) {
            window.alert('Caption "' + key + '" already exists.');
            return;
        }
        var defaultVal = window.prompt('English value for "' + key + '":');
        if (defaultVal === null) return;
        var category = window.prompt('Category (e.g. nav.menu, btn, form, status):', 'general') || 'general';

        var body = {
            action: 'save',
            caption_key: key,
            lang: 'en',
            value: defaultVal,
            category: category,
            csrf_token: csrf()
        };
        fetch('api/captions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j.saved) {
                    captions[key] = { category: category, perLang: { en: { id: j.id, value: defaultVal } } };
                    if (categories.indexOf(category) === -1) {
                        categories.push(category);
                        categories.sort();
                        populateCategoryFilter();
                    }
                    if (languages.indexOf('en') === -1) languages.unshift('en');
                    renderBody();
                    updateCount();
                } else {
                    window.alert((j.error) || 'Save failed');
                }
            });
    }

    // Phase 8b: the in-panel "Add Language" prompt-dialog was retired in
    // favour of the dedicated Languages admin (Settings → App Preferences
    // → Languages), which exposes display name, native name, sort order,
    // enable/default flags, and completeness % — none of which fit in a
    // prompt() chain. The Translations panel now treats the language list
    // as read-only; columns appear/disappear based on what the registry
    // has enabled.

    // ── Export ────────────────────────────────────────────────────────────
    function onExport() {
        fetch('api/captions.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'export', csrf_token: csrf() })
        })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var blob = new Blob([JSON.stringify(j, null, 2)], { type: 'application/json' });
                var url = URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = url;
                var date = new Date().toISOString().slice(0, 10);
                a.download = 'captions-' + date + '.json';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
            });
    }

    // ── Import ────────────────────────────────────────────────────────────
    function onImport(ev) {
        var file = ev.target.files && ev.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var parsed;
            try {
                parsed = JSON.parse(e.target.result);
            } catch (err) {
                window.alert('Invalid JSON: ' + err.message);
                return;
            }
            var items = parsed.export || parsed.captions || parsed;
            if (!Array.isArray(items)) {
                window.alert('Expected an array of captions (or {export: [...]} from a previous export).');
                return;
            }
            if (!window.confirm('Import ' + items.length + ' caption rows? Existing keys/langs will be overwritten.')) return;

            fetch('api/captions.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({ action: 'import', captions: items, csrf_token: csrf() })
            })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    window.alert('Imported ' + (j.imported || 0) + ' rows. ' +
                                 (j.errors ? '(' + j.errors + ' errors skipped.)' : ''));
                    // Reset file input so the same file can be re-uploaded if needed.
                    ev.target.value = '';
                    // Reload data from server.
                    loadAll();
                });
        };
        reader.readAsText(file);
    }

    // ── Search / filter ───────────────────────────────────────────────────
    function bindFilters() {
        var search = document.getElementById('trSearch');
        var cat    = document.getElementById('trCategoryFilter');
        var show   = document.getElementById('trShowFilter');
        if (search) search.addEventListener('input', renderBody);
        if (cat)    cat.addEventListener('change', renderBody);
        if (show)   show.addEventListener('change', renderBody);

        // Phase 8b: Add Language flow moved to the dedicated Languages
        // admin panel. The old #btnTrAddLang prompt-dialog is gone;
        // a Languages tab button takes its place in settings.php.
        var addCap = document.getElementById('btnTrAddCaption');
        if (addCap) addCap.addEventListener('click', onAddCaption);
        var exp = document.getElementById('btnTrExport');
        if (exp) exp.addEventListener('click', onExport);
        var imp = document.getElementById('trImportFile');
        if (imp) imp.addEventListener('change', onImport);
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function encodeAttr(s) {
        return String(s || '').replace(/"/g, '&quot;');
    }
    function decodeAttr(s) {
        return String(s || '').replace(/&quot;/g, '"');
    }

    // ── Boot ──────────────────────────────────────────────────────────────
    ready(function () {
        bindActivation();
        bindFilters();
    });
})();
