/*
 * NewUI v4.0 — Languages registry admin (Phase 8b)
 *
 * Backs the #panel-languages UI in settings.php. Drives the `languages`
 * registry via /api/languages.php. ES5 IIFE, no jQuery, no template
 * literals.
 *
 * Lifecycle: idle until #panel-languages becomes visible (config.js
 * activates panels via .active class). On first activation we fetch
 * the registry and render. Edits go through /api/languages.php; we
 * reload after every change so completeness numbers stay accurate.
 *
 * Conventions match assets/js/translations-admin.js: same fetch shape,
 * same CSRF mechanics, same inline-edit pattern.
 */
(function () {
    'use strict';

    var state = {
        languages: [],
        totalKeys: 0,
        defaultCode: 'en',
        inited: false
    };

    function csrf() {
        var el = document.getElementById('csrfToken');
        return el ? el.value : (window.CSRF_TOKEN || '');
    }

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    function bindActivation() {
        var panel = document.getElementById('panel-languages');
        if (!panel) return;

        if (typeof window.MutationObserver === 'function') {
            new MutationObserver(function () {
                if (panel.classList.contains('active') && !state.inited) {
                    state.inited = true;
                    load();
                }
            }).observe(panel, { attributes: true, attributeFilter: ['class'] });
        }
        if (window.location.hash === '#languages' && !state.inited) {
            setTimeout(function () { if (!state.inited) { state.inited = true; load(); } }, 250);
        }
    }

    // ── Data ──────────────────────────────────────────────────────────────
    function load() {
        fetch('api/languages.php', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                state.languages = data.languages || [];
                state.totalKeys = data.total_keys || 0;
                state.defaultCode = data.default || 'en';
                render();
            })
            .catch(function (err) {
                renderError('Failed to load languages: ' + (err && err.message ? err.message : 'unknown'));
            });
    }

    // ── Render ────────────────────────────────────────────────────────────
    function render() {
        var body = document.getElementById('langTableBody');
        if (!body) return;
        if (state.languages.length === 0) {
            body.innerHTML = '<tr><td colspan="99" class="text-center text-body-secondary py-3">' +
                'No languages configured yet. Add one above to start translating.</td></tr>';
            return;
        }

        var html = '';
        for (var i = 0; i < state.languages.length; i++) {
            html += rowHtml(state.languages[i]);
        }
        body.innerHTML = html;
        bindRowHandlers();
    }

    function rowHtml(l) {
        var pct = l.completeness || 0;
        var pctColor = pct >= 80 ? 'bg-success' : (pct >= 40 ? 'bg-warning' : 'bg-danger');
        var enabledChecked = l.enabled ? 'checked' : '';
        var defaultRadio = l.is_default
            ? '<i class="bi bi-check-circle-fill text-success" title="Install default"></i>'
            : '<button type="button" class="btn btn-sm btn-link p-0 lang-set-default" data-code="' +
              esc(l.code) + '" title="Make this the install default">' +
              '<i class="bi bi-circle text-body-secondary"></i></button>';

        var canDelete = (l.code !== 'en' && !l.is_default);
        var deleteBtn = canDelete
            ? '<button class="btn btn-sm btn-link text-danger p-0 lang-delete" data-code="' + esc(l.code) +
              '" title="Delete language (will remove all its captions)"><i class="bi bi-trash"></i></button>'
            : '<i class="bi bi-lock text-body-tertiary" title="Cannot delete (English or current default)"></i>';

        return '<tr data-code="' + esc(l.code) + '">' +
            '<td><code class="fw-semibold">' + esc(l.code) + '</code></td>' +
            '<td><span class="lang-display lang-edit" data-field="display_name">' + esc(l.display_name) + '</span></td>' +
            '<td><span class="lang-native lang-edit" data-field="native_name">' + esc(l.native_name || '—') + '</span></td>' +
            '<td class="text-center">' +
                '<div class="form-check form-switch d-inline-block">' +
                '<input type="checkbox" class="form-check-input lang-toggle-enabled" ' + enabledChecked +
                ' data-code="' + esc(l.code) + '" ' +
                ((l.is_default && l.enabled) ? 'disabled title="Cannot disable the install default"' : '') + '>' +
                '</div></td>' +
            '<td class="text-center">' + defaultRadio + '</td>' +
            '<td>' +
                '<div class="progress" style="height:18px;min-width:120px">' +
                '<div class="progress-bar ' + pctColor + '" role="progressbar" ' +
                'style="width:' + pct + '%" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100">' +
                pct + '% (' + l.caption_count + '/' + state.totalKeys + ')</div>' +
                '</div></td>' +
            '<td class="text-center"><span class="lang-sort lang-edit" data-field="sort_order">' + l.sort_order + '</span></td>' +
            '<td class="text-end">' + deleteBtn + '</td>' +
            '</tr>';
    }

    function renderError(msg) {
        var body = document.getElementById('langTableBody');
        if (body) {
            body.innerHTML = '<tr><td colspan="99" class="text-center text-danger py-3">' +
                '<i class="bi bi-exclamation-triangle me-1"></i>' + esc(msg) + '</td></tr>';
        }
    }

    // ── Row interactions ──────────────────────────────────────────────────
    function bindRowHandlers() {
        var i;
        var edits = document.querySelectorAll('#langTableBody .lang-edit');
        for (i = 0; i < edits.length; i++) edits[i].addEventListener('click', onEditField);

        var toggles = document.querySelectorAll('#langTableBody .lang-toggle-enabled');
        for (i = 0; i < toggles.length; i++) toggles[i].addEventListener('change', onToggleEnabled);

        var defaults = document.querySelectorAll('#langTableBody .lang-set-default');
        for (i = 0; i < defaults.length; i++) defaults[i].addEventListener('click', onSetDefault);

        var dels = document.querySelectorAll('#langTableBody .lang-delete');
        for (i = 0; i < dels.length; i++) dels[i].addEventListener('click', onDelete);
    }

    function onEditField(ev) {
        var span = ev.currentTarget;
        var row = span.closest('tr');
        var code = row.getAttribute('data-code');
        var field = span.getAttribute('data-field');
        var lang = findLang(code);
        if (!lang || span.querySelector('input')) return;

        var current = lang[field];
        if (field === 'sort_order') current = String(current);
        else if (field === 'native_name' && current === undefined) current = '';

        var input = document.createElement('input');
        input.type = field === 'sort_order' ? 'number' : 'text';
        input.className = 'form-control form-control-sm';
        input.value = current || '';
        input.style.maxWidth = field === 'sort_order' ? '70px' : '180px';
        span.innerHTML = '';
        span.appendChild(input);
        input.focus();
        input.select();

        var done = false;
        function commit() {
            if (done) return;
            done = true;
            var v = input.value;
            if (String(v) === String(current)) {
                span.innerHTML = esc(current || (field === 'native_name' ? '—' : ''));
                return;
            }
            saveField(code, field, v, span);
        }
        function cancel() {
            if (done) return;
            done = true;
            span.innerHTML = esc(current || (field === 'native_name' ? '—' : ''));
        }
        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter')  { e.preventDefault(); input.blur(); }
            if (e.key === 'Escape') { e.preventDefault(); cancel(); }
        });
    }

    function saveField(code, field, value, span) {
        var lang = findLang(code);
        if (!lang) return;
        var body = {
            action: 'save',
            csrf_token: csrf(),
            code: code,
            display_name: lang.display_name,
            native_name: lang.native_name || '',
            sort_order: lang.sort_order
        };
        body[field] = (field === 'sort_order') ? parseInt(value, 10) : value;

        span.innerHTML = '<i class="bi bi-hourglass-split text-body-secondary"></i>';
        fetch('api/languages.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify(body)
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status === 200 && res.body.saved) {
                    lang[field] = body[field];
                    load(); // reload to refresh completeness + sort order
                } else {
                    span.innerHTML = '<span class="text-danger">save failed</span>';
                    window.alert((res.body && res.body.error) || 'Save failed');
                }
            })
            .catch(function (err) {
                span.innerHTML = '<span class="text-danger">network error</span>';
                window.alert('Save failed: ' + (err && err.message ? err.message : 'unknown'));
            });
    }

    function onToggleEnabled(ev) {
        var cb = ev.currentTarget;
        var code = cb.getAttribute('data-code');
        var enabled = cb.checked ? 1 : 0;
        fetch('api/languages.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'toggle_enabled', code: code, enabled: enabled, csrf_token: csrf() })
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status !== 200 || !res.body.updated) {
                    cb.checked = !cb.checked; // revert
                    window.alert((res.body && res.body.error) || 'Toggle failed');
                }
                load();
            });
    }

    function onSetDefault(ev) {
        var code = ev.currentTarget.getAttribute('data-code');
        if (!window.confirm('Make "' + code + '" the install default? New sessions without a stored preference will use this language.')) return;
        fetch('api/languages.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'set_default', code: code, csrf_token: csrf() })
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status !== 200 || !res.body.updated) {
                    window.alert((res.body && res.body.error) || 'Set-default failed');
                }
                load();
            });
    }

    function onDelete(ev) {
        var code = ev.currentTarget.getAttribute('data-code');
        var lang = findLang(code);
        if (!lang) return;
        var caps = lang.caption_count || 0;
        var msg = 'Delete language "' + code + '"?\n\n' +
                  'This will remove ' + caps + ' caption rows and clear preferred_lang for any user set to this language.\n\n' +
                  'You cannot undo this.';
        if (!window.confirm(msg)) return;
        fetch('api/languages.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
            body: JSON.stringify({ action: 'delete', code: code, csrf_token: csrf() })
        })
            .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
            .then(function (res) {
                if (res.status !== 200 || !res.body.deleted) {
                    window.alert((res.body && res.body.error) || 'Delete failed');
                } else {
                    window.alert('Deleted "' + code + '" and ' + res.body.captions_removed + ' caption rows.');
                }
                load();
            });
    }

    // ── Add-language form ─────────────────────────────────────────────────
    function bindAddForm() {
        var form = document.getElementById('langAddForm');
        if (!form) return;
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var code = (document.getElementById('langAddCode').value || '').trim().toLowerCase();
            var disp = (document.getElementById('langAddDisplay').value || '').trim();
            var nat  = (document.getElementById('langAddNative').value  || '').trim();
            var sort = parseInt(document.getElementById('langAddSort').value, 10) || 100;
            if (!/^[a-z0-9]{2}([a-z0-9\-]{0,6})$/.test(code)) {
                window.alert('Invalid code. Use 2-8 lowercase letters/digits/dashes (e.g. "fr" or "pt-br").');
                return;
            }
            if (!disp) {
                window.alert('Display name is required.');
                return;
            }
            fetch('api/languages.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() },
                body: JSON.stringify({
                    action: 'save',
                    code: code,
                    display_name: disp,
                    native_name: nat,
                    sort_order: sort,
                    csrf_token: csrf()
                })
            })
                .then(function (r) { return r.json().then(function (j) { return { status: r.status, body: j }; }); })
                .then(function (res) {
                    if (res.status !== 200 || !res.body.saved) {
                        window.alert((res.body && res.body.error) || 'Add failed');
                        return;
                    }
                    // Reset form, reload table.
                    form.reset();
                    document.getElementById('langAddSort').value = '100';
                    load();
                });
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    function findLang(code) {
        for (var i = 0; i < state.languages.length; i++) {
            if (state.languages[i].code === code) return state.languages[i];
        }
        return null;
    }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ── Boot ──────────────────────────────────────────────────────────────
    ready(function () {
        bindActivation();
        bindAddForm();
    });
})();
