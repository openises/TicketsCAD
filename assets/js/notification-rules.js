/**
 * Settings -> Notification Rules (Phase 155, GH#144).
 *
 * Drives the panel in inc/notification-rules-panel.php from api/notification-rules.php.
 * Started once by config.js the first time the tab is opened:
 *     window.NotificationRulesAdmin.init()
 *
 * RULES THIS FILE FOLLOWS
 *   - Every server value (rule names, recipients, subjects, bodies, error text) is put on
 *     the page with textContent / createTextNode. A rule's text is administrator-typed,
 *     a delivery-log row can hold dispatch data a caller typed, and an address can
 *     contain anything: none of it is ever concatenated into markup.
 *   - Every button is type="button" (the panel is not a form).
 *   - ES5 only: no arrow functions, let/const, template literals.
 *   - Nothing here decides what is allowed. The endpoint validates and authorises every
 *     request (Super Admin only, CSRF on every POST); this script only shows the answer.
 *   - The Preview pane calls the SAME planner a real event uses, so what it shows is what
 *     would be sent, to whom, and what would be skipped and why.
 */
(function () {
    'use strict';

    var API = 'api/notification-rules.php';
    var INFO_KEY = 'nr_info_dismissed';

    var inited = false;
    var meta = null;            // GET meta
    var rules = [];             // GET list
    var userInfo = {};          // 'user:12' -> {name, login, has_email, has_mobile}
    var editing = null;         // {id:int|null, recipients:[canonical strings], listId:int|null, preset:string|null}
    var lastPreview = null;
    var previewTimer = null;
    var previewSeq = 0;
    var searchTimer = null;
    var searchSeq = 0;
    var refreshTimer = null;
    var busy = {};              // action name -> true while a request is in flight
    var lastTextField = null;   // the subject or message field the user was last typing in
    var logState = { offset: 0, limit: 25, total: 0 };
    var ruleModal = null;
    var logModal = null;

    // ── tiny helpers ────────────────────────────────────────────────────────
    function $(id) { return document.getElementById(id); }
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = text;
        return n;
    }
    function clear(n) { while (n && n.firstChild) n.removeChild(n.firstChild); }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') : '';
    }
    function show(n, on) { if (n) { if (on) n.classList.remove('d-none'); else n.classList.add('d-none'); } }
    function plural(n, one, many) { return n + ' ' + (n === 1 ? one : many); }
    function announce(text) {
        var live = $('nrLive');
        if (!live) return;
        live.textContent = '';
        window.setTimeout(function () { live.textContent = text; }, 30);
    }
    function icon(name, extra) { return el('i', 'bi bi-' + name + (extra ? ' ' + extra : '')); }

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
        if (r.body && r.body.errors && r.body.errors.length) return r.body.errors.join(' ');
        return (r.body && r.body.error) ? String(r.body.error) : 'Something went wrong (HTTP ' + r.status + ').';
    }

    function showMsg(kind, text, warnings) {
        var host = $('nrMsg');
        if (!host) return;
        clear(host);
        var box = el('div', 'alert alert-' + kind + ' small py-2 d-flex align-items-start gap-2 mb-2');
        box.setAttribute('role', kind === 'danger' ? 'alert' : 'status');
        var body = el('div', 'flex-grow-1');
        body.appendChild(el('div', '', text));
        if (warnings && warnings.length) {
            var ul = el('ul', 'mb-0 mt-1 ps-3');
            var i;
            for (i = 0; i < warnings.length; i++) ul.appendChild(el('li', '', warnings[i]));
            body.appendChild(ul);
        }
        box.appendChild(body);
        var x = el('button', 'btn-close btn-sm');
        x.type = 'button';
        x.setAttribute('aria-label', 'Dismiss');
        x.addEventListener('click', function () { clear(host); });
        box.appendChild(x);
        host.appendChild(box);
        announce(text);
        if (kind === 'success') window.setTimeout(function () { if (box.parentNode === host) clear(host); }, 8000);
    }

    function gotoTab(tab) {
        if (!tab) return;
        var link = document.querySelector('.config-tab-link[data-tab="' + tab + '"]');
        if (link) link.click(); else window.location.hash = tab;
    }

    // ── lookups into meta ───────────────────────────────────────────────────
    function eventDef(id) {
        var i;
        if (!meta) return null;
        for (i = 0; i < meta.events.length; i++) if (meta.events[i].id === id) return meta.events[i];
        return null;
    }
    function channelDef(code) {
        var i;
        if (!meta) return null;
        for (i = 0; i < meta.channels.length; i++) if (meta.channels[i].code === code) return meta.channels[i];
        return null;
    }
    function channelName(code) {
        if (code === 'all') return 'Every channel';
        var c = channelDef(code);
        return c ? c.name : code;
    }
    function channelKind(code) {
        if (code === 'all') return 'legacy';
        var c = channelDef(code);
        return c ? c.kind : 'legacy';
    }
    function severityLabel(value) {
        var i;
        for (i = 0; i < meta.severities.length; i++) if (Number(meta.severities[i].value) === Number(value)) return meta.severities[i].label;
        return 'level ' + value;
    }
    function typeLabel(id) {
        var i;
        for (i = 0; i < meta.incident_types.length; i++) if (meta.incident_types[i].id === Number(id)) return meta.incident_types[i].type;
        return 'type #' + id;
    }
    function listDef(id) {
        var i;
        for (i = 0; i < meta.email_lists.length; i++) if (meta.email_lists[i].id === Number(id)) return meta.email_lists[i];
        return null;
    }
    function recipientLabel(canon) {
        var kind = canon.split(':')[0];
        var rest = canon.slice(kind.length + 1);
        if (kind === 'user') return userInfo[canon] ? userInfo[canon].name : 'User #' + rest;
        return rest;
    }

    // ── load ────────────────────────────────────────────────────────────────
    function denied() {
        show($('nrDenied'), true);
        show($('nrMain'), false);
    }

    function loadAll() {
        return api('GET', 'meta').then(function (r) {
            if (r.status === 403) { denied(); return null; }
            if (r.status !== 200 || !r.body.events) { showMsg('danger', errText(r)); return null; }
            show($('nrDenied'), false);
            show($('nrMain'), true);
            meta = r.body;
            renderMeta();
            return loadRules();
        });
    }

    function loadRules() {
        return api('GET', 'list').then(function (r) {
            if (r.status !== 200) {
                var tb = $('nrTbody');
                clear(tb);
                var tr = el('tr'); var td = el('td', 'text-danger small', errText(r));
                td.colSpan = 7; tr.appendChild(td); tb.appendChild(tr);
                return null;
            }
            rules = r.body.rules || [];
            // Name the people the rules point at (one lookup for all of them).
            var ids = [];
            var i; var j;
            for (i = 0; i < rules.length; i++) {
                for (j = 0; j < rules[i].recipients.length; j++) {
                    var c = rules[i].recipients[j];
                    if (c.indexOf('user:') === 0 && !userInfo[c]) ids.push(c.slice(5));
                }
            }
            if (!ids.length) { renderRules(); return null; }
            return fetchUsersByIds(ids).then(renderRules);
        });
    }

    function fetchUsersByIds(ids) {
        var seen = {}; var uniq = []; var i;
        for (i = 0; i < ids.length; i++) if (!seen[ids[i]]) { seen[ids[i]] = true; uniq.push(ids[i]); }
        return api('GET', 'users', null, '&ids=' + encodeURIComponent(uniq.slice(0, 100).join(','))).then(function (r) {
            var users = (r.body && r.body.users) || [];
            var k;
            for (k = 0; k < users.length; k++) userInfo['user:' + users[k].id] = users[k];
        });
    }

    function refreshQueue() {
        return api('GET', 'queue').then(function (r) {
            if (r.status === 200 && meta) { meta.queue = r.body; renderQueue(); }
        });
    }

    // ── status strip, presets, selects ──────────────────────────────────────
    function renderMeta() {
        renderChannelBadges();
        renderQueue();
        renderPresets();
        renderSettings();
        fillRuleSelects();
        renderPlaceholders();
        var bodyMax = $('nrBodyMax');
        if (bodyMax && meta.limits) bodyMax.textContent = String(meta.limits.body);
    }

    function renderChannelBadges() {
        var host = $('nrChannelBadges');
        clear(host);
        var i;
        for (i = 0; i < meta.channels.length; i++) {
            (function (c) {
                var b = el('button', 'btn btn-sm py-0 px-2 ' + (c.configured ? 'btn-outline-success' : 'btn-outline-secondary'));
                b.type = 'button';
                b.appendChild(icon(c.configured ? 'check-circle-fill' : 'dash-circle', 'me-1'));
                b.appendChild(document.createTextNode(c.name + (c.configured ? '' : ' - not set up')));
                b.title = c.configured ? c.name + ' is set up' : c.name + ' is not set up yet. Open its settings.';
                if (c.tab) b.addEventListener('click', function () { gotoTab(c.tab); });
                host.appendChild(b);
            })(meta.channels[i]);
        }
    }

    function renderQueue() {
        var q = meta.queue || {};
        var host = $('nrQueueInfo');
        clear(host);
        host.appendChild(icon('circle-fill', q.scheduler_live ? 'text-success' : 'text-warning'));
        var parts = [q.scheduler_live ? 'Scheduler running' : 'No scheduler heartbeat'];
        parts.push(plural(q.rule_pending || 0, 'delivery waiting', 'deliveries waiting'));
        if (q.rule_oldest_age_s !== null && q.rule_oldest_age_s !== undefined && (q.rule_pending || 0) > 0) {
            parts.push('oldest ' + Math.max(1, Math.round(q.rule_oldest_age_s / 60)) + ' min');
        }
        if (q.all_failed) parts.push(q.all_failed + ' failed');
        var span = el('span', '', parts.join(' · '));
        span.title = 'All outbound notifications waiting: ' + (q.all_pending || 0) + '; failed: ' + (q.all_failed || 0);
        host.appendChild(span);
        show($('nrSchedBanner'), !q.scheduler_live);

        var banner = $('nrBreakerBanner');
        clear(banner);
        var any = false;
        var ch;
        for (ch in (q.breakers || {})) {
            if (!Object.prototype.hasOwnProperty.call(q.breakers, ch)) continue;
            var b = q.breakers[ch];
            if (b.open) {
                any = true;
                var line = el('div');
                line.appendChild(icon('exclamation-octagon-fill', 'me-1'));
                var txt = 'Delivery by ' + channelName(ch) + ' is paused after ' + b.fails + ' failures in a row'
                    + (b.last_error ? ' (' + b.last_error + ')' : '') + '. It retries by itself in about ' + b.retry_in + ' seconds.';
                line.appendChild(document.createTextNode(txt));
                line.title = b.reason;
                banner.appendChild(line);
            } else if (b.half_open) {
                any = true;
                var l2 = el('div');
                l2.appendChild(icon('exclamation-triangle-fill', 'me-1'));
                l2.appendChild(document.createTextNode(channelName(ch) + ' failed recently; the next sweep will try it again.'));
                l2.title = b.reason;
                banner.appendChild(l2);
            }
        }
        show(banner, any);
    }

    function renderPresets() {
        var host = $('nrPresetMenu');
        clear(host);
        var i;
        for (i = 0; i < meta.presets.length; i++) {
            (function (p) {
                var li = el('li');
                var b = el('button', 'dropdown-item small');
                b.type = 'button';
                b.textContent = p.label;
                b.title = p.description;
                b.addEventListener('click', function () { openRule(null, p); });
                li.appendChild(b);
                host.appendChild(li);
            })(meta.presets[i]);
        }
    }

    function renderSettings() {
        var s = meta.settings || {};
        $('nrSetFormat').value = s.email_format || 'text';
        $('nrSetPrefs').value = s.prefs_mode || 'explicit_only';
        $('nrSetRetention').value = String(s.retention_days === undefined ? 180 : s.retention_days);
    }

    function fillRuleSelects() {
        var i; var o;
        var ev = $('nrEvent'); clear(ev);
        for (i = 0; i < meta.events.length; i++) {
            o = el('option', '', meta.events[i].label); o.value = meta.events[i].id; ev.appendChild(o);
        }
        var sev = $('nrSeverity'); clear(sev);
        o = el('option', '', 'Any severity'); o.value = ''; sev.appendChild(o);
        for (i = 0; i < meta.severities.length; i++) {
            var s = meta.severities[i];
            o = el('option', '', s.label + (s.is_high_alert ? ' (high alert)' : '')); o.value = String(s.value); sev.appendChild(o);
        }
        var ty = $('nrType'); clear(ty);
        o = el('option', '', 'Any incident type'); o.value = ''; ty.appendChild(o);
        var groups = {}; var order = [];
        for (i = 0; i < meta.incident_types.length; i++) {
            var t = meta.incident_types[i]; var g = t.group || 'Other';
            if (!groups[g]) { groups[g] = el('optgroup'); groups[g].label = g; order.push(g); }
            o = el('option', '', t.type); o.value = String(t.id); groups[g].appendChild(o);
        }
        for (i = 0; i < order.length; i++) ty.appendChild(groups[order[i]]);
        var ch = $('nrChannel'); clear(ch);
        for (i = 0; i < meta.channels.length; i++) {
            var c = meta.channels[i];
            o = el('option', '', c.name + (c.configured ? '' : ' (not set up)')); o.value = c.code; ch.appendChild(o);
        }
        var ls = $('nrList'); clear(ls);
        o = el('option', '', 'No list'); o.value = ''; ls.appendChild(o);
        for (i = 0; i < meta.email_lists.length; i++) {
            var l = meta.email_lists[i];
            var label = l.name + ' (' + plural(l.address_count, 'address', 'addresses')
                + (l.problem_count ? ', ' + plural(l.problem_count, 'problem', 'problems') : '') + ')';
            o = el('option', '', label); o.value = String(l.id); ls.appendChild(o);
        }
    }

    function renderPlaceholders() {
        var host = $('nrPlaceholders');
        clear(host);
        var i;
        for (i = 0; i < meta.placeholders.length; i++) {
            (function (p) {
                var b = el('button', 'btn btn-outline-secondary btn-sm py-0 px-1');
                b.type = 'button';
                b.textContent = '{' + p.token + '}';
                b.title = p.description + ' - for example: ' + p.example;
                b.addEventListener('click', function () { insertToken(p.token); });
                host.appendChild(b);
            })(meta.placeholders[i]);
        }
    }

    function insertToken(token) {
        var f = lastTextField || $('nrBody');
        var text = '{' + token + '}';
        var start = f.selectionStart; var end = f.selectionEnd;
        if (start === undefined || start === null) { start = f.value.length; end = start; }
        f.value = f.value.slice(0, start) + text + f.value.slice(end);
        var pos = start + text.length;
        try { f.setSelectionRange(pos, pos); } catch (e) { /* not a text field */ }
        f.focus();
        updateCounts();
        schedulePreview();
    }

    // ── rules table ─────────────────────────────────────────────────────────
    function filterSummary(r) {
        var parts = [];
        if (r.severity_filter !== null) parts.push('severity: ' + severityLabel(r.severity_filter));
        if (r.incident_type_filter !== null) parts.push('type: ' + typeLabel(r.incident_type_filter));
        return parts;
    }

    function recipientSummary(r) {
        var kind = channelKind(r.channel);
        if (kind === 'shared') return { text: 'the channel set up in Settings', warn: false };
        var names = []; var i;
        for (i = 0; i < r.recipients.length; i++) names.push(recipientLabel(r.recipients[i]));
        var list = r.email_list_id !== null ? listDef(r.email_list_id) : null;
        var bits = [];
        if (names.length) bits.push(names.slice(0, 2).join(', ') + (names.length > 2 ? ' +' + (names.length - 2) + ' more' : ''));
        if (r.email_list_id !== null) bits.push('list: ' + (list ? list.name : '#' + r.email_list_id + ' (archived or missing)'));
        if (!bits.length) return { text: 'nobody yet', warn: true };
        return { text: bits.join('; '), warn: false };
    }

    function renderRules() {
        var tb = $('nrTbody');
        clear(tb);
        show($('nrEmpty'), rules.length === 0);
        var i;
        for (i = 0; i < rules.length; i++) tb.appendChild(ruleRow(rules[i]));
    }

    function ruleRow(r) {
        var tr = el('tr');
        tr.setAttribute('data-rule-id', String(r.id));
        if (!r.active) tr.className = 'text-body-secondary';

        var td = el('td');
        var sw = el('div', 'form-check form-switch mb-0');
        var cb = el('input', 'form-check-input');
        cb.type = 'checkbox'; cb.checked = !!r.active; cb.id = 'nrOn' + r.id;
        cb.setAttribute('aria-label', 'Rule "' + r.name + '" is on');
        cb.addEventListener('change', function () { toggleRule(r, cb); });
        sw.appendChild(cb); td.appendChild(sw); tr.appendChild(td);

        td = el('td');
        var nameBtn = el('button', 'btn btn-link btn-sm p-0 text-start fw-semibold', r.name);
        nameBtn.type = 'button';
        nameBtn.title = 'Created ' + r.created_at + (r.created_by ? ' (by account #' + r.created_by + ')' : '') + ', last changed ' + r.updated_at;
        nameBtn.addEventListener('click', function () { openRule(r, null); });
        td.appendChild(nameBtn);
        tr.appendChild(td);

        td = el('td', 'small');
        var evd = eventDef(r.event_type);
        td.appendChild(el('div', '', evd ? evd.label : r.event_type));
        var fs = filterSummary(r);
        if (fs.length) td.appendChild(el('div', 'text-body-secondary', fs.join(' · ')));
        if (r.once_per_incident) td.appendChild(el('span', 'badge text-bg-info', 'first time only'));
        tr.appendChild(td);

        td = el('td', 'small');
        td.appendChild(el('div', '', channelName(r.channel)));
        var rs = recipientSummary(r);
        td.appendChild(el('div', rs.warn ? 'text-warning' : 'text-body-secondary', rs.text));
        tr.appendChild(td);

        td = el('td', 'small', r.last_fired_at ? r.last_fired_at : 'never');
        tr.appendChild(td);

        td = el('td', 'small text-end');
        td.appendChild(el('span', '', r.sent_30d + ' sent'));
        if (r.failed_30d) { td.appendChild(document.createTextNode(' · ')); td.appendChild(el('span', 'text-danger', r.failed_30d + ' failed')); }
        tr.appendChild(td);

        td = el('td', 'text-end text-nowrap');
        td.appendChild(actionBtn('pencil', 'Edit rule ' + r.name, function () { openRule(r, null); }));
        td.appendChild(actionBtn('files', 'Duplicate rule ' + r.name, function () { duplicateRule(r); }));
        td.appendChild(actionBtn('send', 'Send a test of rule ' + r.name + ' to me', function () { testSaved(r); }));
        td.appendChild(actionBtn('journal-text', 'Delivery log for rule ' + r.name, function () { openLog(r.id); }));
        td.appendChild(actionBtn('trash', 'Delete rule ' + r.name, function () { deleteRule(r); }, 'text-danger'));
        tr.appendChild(td);
        return tr;
    }

    function actionBtn(ic, label, fn, extra) {
        var b = el('button', 'btn btn-sm btn-link py-0 px-1 ' + (extra || ''));
        b.type = 'button';
        b.setAttribute('aria-label', label);
        b.title = label;
        b.appendChild(icon(ic));
        b.addEventListener('click', fn);
        return b;
    }

    function toggleRule(r, cb) {
        var want = cb.checked;
        cb.disabled = true;
        api('POST', 'toggle', { id: r.id, active: want }).then(function (res) {
            cb.disabled = false;
            if (res.status === 200 && res.body.ok) {
                r.active = res.body.active;
                cb.checked = !!res.body.active;
                announce('Rule ' + r.name + ' is now ' + (res.body.active ? 'on' : 'off'));
                var row = cb.closest('tr');
                if (row) row.className = res.body.active ? '' : 'text-body-secondary';
            } else {
                cb.checked = !want;
                showMsg('danger', errText(res));
            }
        });
    }

    function duplicateRule(r) {
        api('POST', 'duplicate', { id: r.id }).then(function (res) {
            if (res.status === 200 && res.body.ok) {
                showMsg('success', 'Copied as "' + res.body.rule.name + '". The copy is switched off until you turn it on.');
                loadRules();
            } else showMsg('danger', errText(res));
        });
    }

    function deleteRule(r) {
        if (!window.confirm('Delete the rule "' + r.name + '"?\n\nIts delivery history stays in the log.')) return;
        api('POST', 'delete', { id: r.id }).then(function (res) {
            if (res.status === 200 && res.body.ok) {
                var row = document.querySelector('tr[data-rule-id="' + res.body.id + '"]');
                if (row && row.parentNode) row.parentNode.removeChild(row);
                rules = rules.filter(function (x) { return x.id !== res.body.id; });
                show($('nrEmpty'), rules.length === 0);
                showMsg('success', 'Rule "' + r.name + '" deleted.');
            } else showMsg('danger', errText(res));
        });
    }

    // ── rule modal ──────────────────────────────────────────────────────────
    function openRule(rule, preset) {
        if (!meta) return;
        var base = rule;
        if (!base) {
            base = preset ? preset.rule : {};
        }
        editing = {
            id: rule ? rule.id : null,
            recipients: (rule && rule.recipients) ? rule.recipients.slice() : [],
            listId: rule ? rule.email_list_id : null,
            preset: preset ? preset.id : null
        };
        $('nrRuleTitle').textContent = rule ? 'Edit rule' : (preset ? 'New rule from template' : 'New rule');
        $('nrName').value = base.name || '';
        $('nrEvent').value = base.event_type || meta.events[0].id;
        $('nrSeverity').value = (base.severity_filter === null || base.severity_filter === undefined) ? '' : String(base.severity_filter);
        $('nrType').value = (base.incident_type_filter === null || base.incident_type_filter === undefined) ? '' : String(base.incident_type_filter);
        $('nrOnce').checked = !!base.once_per_incident;
        $('nrActive').checked = rule ? !!rule.active : true;
        ensureChannelOption(base.channel || 'email');
        $('nrChannel').value = base.channel || 'email';
        ensureListOption(editing.listId);
        $('nrList').value = editing.listId === null ? '' : String(editing.listId);
        applyList();
        $('nrSubject').value = base.subject_template || '';
        $('nrBody').value = base.body_template || '';
        $('nrUserQ').value = ''; $('nrEmailIn').value = ''; $('nrPhoneIn').value = '';
        hideUserResults();
        show($('nrFormErrors'), false);
        $('nrSample').value = 'synthetic';
        clear($('nrTestResult'));
        var note = $('nrPresetNote');
        if (note) { note.textContent = preset ? preset.description : ''; show(note, !!preset); }
        applyEvent();
        applyChannel(false);
        updateCounts();
        lastPreview = null;
        clearPreview();

        var ids = [];
        var i;
        for (i = 0; i < editing.recipients.length; i++) {
            var c = editing.recipients[i];
            if (c.indexOf('user:') === 0 && !userInfo[c]) ids.push(c.slice(5));
        }
        var done = function () { renderChips(); runPreview(); };
        if (ids.length) fetchUsersByIds(ids).then(done); else done();

        var m = ensureRuleModal();
        var focusName = function () {
            $('nrRuleModal').removeEventListener('shown.bs.modal', focusName);
            // Deferred and AFTER shown: Bootstrap's focus trap otherwise lands focus on the
            // dialog itself (the GH#135 lesson), and typing then fires page shortcuts.
            window.setTimeout(function () { $('nrName').focus(); }, 0);
        };
        $('nrRuleModal').addEventListener('shown.bs.modal', focusName);
        m.show();
    }

    function ensureRuleModal() {
        if (!ruleModal) ruleModal = window.bootstrap.Modal.getOrCreateInstance($('nrRuleModal'));
        return ruleModal;
    }

    function ensureChannelOption(code) {
        var sel = $('nrChannel');
        var i;
        for (i = 0; i < sel.options.length; i++) if (sel.options[i].value === code) return;
        var o = el('option', '', code === 'all' ? 'Every channel (legacy)' : code + ' (not offered here)');
        o.value = code;
        sel.appendChild(o);
    }

    function ensureListOption(id) {
        if (id === null || id === undefined) return;
        var sel = $('nrList');
        var i;
        for (i = 0; i < sel.options.length; i++) if (sel.options[i].value === String(id)) return;
        var o = el('option', '', 'List #' + id + ' (archived or missing)');
        o.value = String(id);
        sel.appendChild(o);
    }

    /** The event decides which filters and switches make sense, and the default text. */
    function applyEvent() {
        var def = eventDef($('nrEvent').value);
        $('nrEventDesc').textContent = def ? def.description : '';
        show($('nrFilters'), !!(def && def.has_ticket));
        var onceOk = !!(def && def.once_capable && meta.supports && meta.supports.once_per_incident);
        show($('nrOnceWrap'), onceOk);
        if (!onceOk) $('nrOnce').checked = false;
        $('nrSubject').placeholder = def ? def.default_subject : '';
        $('nrBody').placeholder = def ? def.default_body : '';
    }

    /** What the chosen channel can address decides which "add" controls show. */
    function applyChannel(prune) {
        var code = $('nrChannel').value;
        var kind = channelKind(code);
        var c = channelDef(code);
        show($('nrRecipBlock'), kind !== 'shared');
        show($('nrAddUser'), kind === 'user' || kind === 'email' || kind === 'phone' || kind === 'legacy');
        show($('nrAddEmail'), kind === 'email' || kind === 'legacy');
        show($('nrAddPhone'), kind === 'phone' || kind === 'legacy');
        show($('nrAddList'), code === 'email' || code === 'smtp');
        var note = $('nrSharedNote');
        if (kind === 'shared') {
            note.textContent = c.name + ' posts once to the one destination set up in Settings (' + c.name + '). '
                + 'It takes no recipients, and every post is visible to everyone in that channel.';
            show(note, true);
        } else show(note, false);

        var hint = $('nrChannelHint');
        clear(hint);
        if (c && !c.configured) {
            hint.appendChild(document.createTextNode(c.name + ' is not set up yet, so nothing will be delivered until it is. '));
            if (c.tab) {
                var b = el('button', 'btn btn-link btn-sm p-0 align-baseline', 'Open its settings');
                b.type = 'button';
                b.addEventListener('click', function () { ensureRuleModal().hide(); gotoTab(c.tab); });
                hint.appendChild(b);
            }
        } else if (kind === 'user') {
            hint.textContent = 'Delivered to the people you add, using their account (chat inside the CAD, or a browser push).';
        } else if (kind === 'phone') {
            hint.textContent = 'Texts go to each person\'s mobile number. People with no number are skipped, and you are told which.';
        }

        if (prune) {
            var kept = []; var dropped = 0; var i;
            for (i = 0; i < editing.recipients.length; i++) {
                var r = editing.recipients[i]; var rk = r.split(':')[0];
                var fits = (kind === 'legacy') || (kind === 'user' && rk === 'user')
                    || (kind === 'email' && (rk === 'user' || rk === 'email'))
                    || (kind === 'phone' && (rk === 'user' || rk === 'tel'));
                if (fits) kept.push(r); else dropped++;
            }
            if (kind === 'shared') { dropped += kept.length; kept = []; }
            editing.recipients = kept;
            if (code !== 'email' && code !== 'smtp') $('nrList').value = '';
            if (dropped) announce(plural(dropped, 'recipient was', 'recipients were') + ' removed because this channel cannot send to them.');
            renderChips();
        }
    }

    function renderChips() {
        var host = $('nrChips');
        clear(host);
        var kind = channelKind($('nrChannel').value);
        var i;
        if (!editing.recipients.length) {
            host.appendChild(el('span', 'small text-body-secondary', kind === 'shared' ? '' : 'Nobody added yet.'));
            return;
        }
        for (i = 0; i < editing.recipients.length; i++) {
            (function (canon) {
                var chip = el('span', 'badge rounded-pill bg-body-secondary text-body border d-inline-flex align-items-center gap-1 fw-normal');
                chip.setAttribute('role', 'listitem');
                var info = userInfo[canon];
                var k = canon.split(':')[0];
                chip.appendChild(icon(k === 'user' ? 'person' : (k === 'email' ? 'envelope' : 'telephone')));
                chip.appendChild(document.createTextNode(recipientLabel(canon)));
                if (info) {
                    if (kind === 'email' && !info.has_email) chip.appendChild(warnIcon('no email address on file'));
                    if (kind === 'phone' && !info.has_mobile) chip.appendChild(warnIcon('no mobile number on file'));
                }
                var x = el('button', 'btn-close');
                x.type = 'button';
                x.style.fontSize = '0.55rem';
                x.setAttribute('aria-label', 'Remove ' + recipientLabel(canon));
                x.addEventListener('click', function () {
                    editing.recipients = editing.recipients.filter(function (v) { return v !== canon; });
                    renderChips();
                    schedulePreview();
                    $('nrUserQ').focus();
                });
                chip.appendChild(x);
                host.appendChild(chip);
            })(editing.recipients[i]);
        }
    }

    function warnIcon(title) {
        var w = icon('exclamation-triangle-fill', 'text-warning');
        w.title = title;
        w.setAttribute('role', 'img');
        w.setAttribute('aria-label', title);
        return w;
    }

    function addRecipient(canon) {
        var lower = canon.toLowerCase();
        var i;
        for (i = 0; i < editing.recipients.length; i++) if (editing.recipients[i].toLowerCase() === lower) return false;
        if (meta.limits && editing.recipients.length >= meta.limits.recipients) {
            showFormErrors(['A rule can have at most ' + meta.limits.recipients + ' recipients - use an email list for larger groups.']);
            return false;
        }
        editing.recipients.push(canon);
        return true;
    }

    // user picker --------------------------------------------------------------
    function hideUserResults() {
        var r = $('nrUserResults');
        if (!r) return;
        clear(r); show(r, false);
        $('nrUserQ').setAttribute('aria-expanded', 'false');
    }

    function searchUsers() {
        var q = $('nrUserQ').value.trim();
        if (!q) { hideUserResults(); return; }
        var seq = ++searchSeq;
        api('GET', 'users', null, '&q=' + encodeURIComponent(q)).then(function (r) {
            if (seq !== searchSeq) return;
            var host = $('nrUserResults');
            clear(host);
            var users = (r.body && r.body.users) || [];
            var kind = channelKind($('nrChannel').value);
            if (!users.length) {
                var none = el('div', 'list-group-item small text-body-secondary', 'No matching account.');
                host.appendChild(none);
            }
            var i;
            for (i = 0; i < users.length; i++) {
                (function (u) {
                    var b = el('button', 'list-group-item list-group-item-action small d-flex justify-content-between');
                    b.type = 'button';
                    b.setAttribute('role', 'option');
                    b.appendChild(el('span', '', u.name + (u.login && u.login !== u.name ? ' (' + u.login + ')' : '')));
                    var flags = [];
                    if (kind === 'email' && !u.has_email) flags.push('no email');
                    if (kind === 'phone' && !u.has_mobile) flags.push('no mobile');
                    if (flags.length) b.appendChild(el('span', 'text-warning', flags.join(', ')));
                    b.addEventListener('click', function () {
                        userInfo['user:' + u.id] = u;
                        if (addRecipient('user:' + u.id)) { renderChips(); schedulePreview(); announce(u.name + ' added.'); }
                        $('nrUserQ').value = '';
                        hideUserResults();
                        $('nrUserQ').focus();
                    });
                    host.appendChild(b);
                })(users[i]);
            }
            show(host, true);
            $('nrUserQ').setAttribute('aria-expanded', 'true');
        });
    }

    function onUserKey(e) {
        var results = $('nrUserResults');
        var items = results ? results.querySelectorAll('button') : [];
        if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); items[0].focus(); }
        else if (e.key === 'Escape' && !results.classList.contains('d-none')) { e.preventDefault(); e.stopPropagation(); hideUserResults(); }
        else if (e.key === 'Enter' && items.length) { e.preventDefault(); items[0].click(); }
    }
    function onResultsKey(e) {
        var items = $('nrUserResults').querySelectorAll('button');
        var idx = Array.prototype.indexOf.call(items, document.activeElement);
        if (e.key === 'ArrowDown') { e.preventDefault(); if (idx < items.length - 1) items[idx + 1].focus(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); if (idx > 0) items[idx - 1].focus(); else $('nrUserQ').focus(); }
        else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); hideUserResults(); $('nrUserQ').focus(); }
    }

    // typed addresses ------------------------------------------------------------
    var EMAIL_RE = /^[^@\s<>"',;]+@[^@\s<>"',;]+\.[^@\s<>"',;]+$/;
    function addEmails() {
        var inp = $('nrEmailIn');
        var tokens = inp.value.split(/[,;\s]+/);
        var bad = []; var added = 0; var i;
        for (i = 0; i < tokens.length; i++) {
            var t = tokens[i].trim();
            if (!t) continue;
            if (!EMAIL_RE.test(t)) { bad.push(t); continue; }
            if (addRecipient('email:' + t)) added++;
        }
        inp.classList.toggle('is-invalid', bad.length > 0);
        if (bad.length) {
            showFormErrors([(bad.length === 1 ? 'This is not an email address: ' : 'These are not email addresses: ') + bad.join(', ')]);
            inp.value = bad.join(', ');
        } else { inp.value = ''; show($('nrFormErrors'), false); }
        if (added) { renderChips(); schedulePreview(); announce(plural(added, 'address', 'addresses') + ' added.'); }
    }
    function addPhone() {
        var inp = $('nrPhoneIn');
        var raw = inp.value.trim();
        if (!raw) return;
        var plus = raw.charAt(0) === '+' ? '+' : '';
        var digits = raw.replace(/\D/g, '');
        if (!/^[0-9 ()+.\-]+$/.test(raw) || digits.length < 7 || digits.length > 15) {
            inp.classList.add('is-invalid');
            showFormErrors(['That does not look like a phone number (7 to 15 digits).']);
            return;
        }
        inp.classList.remove('is-invalid');
        if (addRecipient('tel:' + plus + digits)) { renderChips(); schedulePreview(); announce('Number added.'); }
        inp.value = '';
        show($('nrFormErrors'), false);
    }

    /** What the chosen email list holds, and whether it has problems the administrator should fix first. */
    function applyList() {
        var id = $('nrList').value;
        var hint = $('nrListHint');
        var l = id === '' ? null : listDef(id);
        if (!l) { hint.textContent = id === '' ? 'Lists are managed under Settings, Email Lists.' : 'This list is archived or missing, so it will send to nobody.'; return; }
        hint.textContent = plural(l.address_count, 'address', 'addresses') + ' now'
            + (l.problem_count ? ', and ' + plural(l.problem_count, 'entry cannot', 'entries cannot') + ' be used (open Email Lists to fix)' : '')
            + '. The list is read each time the rule fires, so changing it later changes who is told.';
    }

    function updateCounts() {
        $('nrSubjectCount').textContent = String($('nrSubject').value.length);
        $('nrBodyCount').textContent = String($('nrBody').value.length);
    }

    function showFormErrors(list) {
        var box = $('nrFormErrors');
        clear(box);
        if (list.length === 1) box.textContent = list[0];
        else {
            var ul = el('ul', 'mb-0 ps-3'); var i;
            for (i = 0; i < list.length; i++) ul.appendChild(el('li', '', list[i]));
            box.appendChild(ul);
        }
        show(box, true);
        var modalBody = box.closest('.modal-body');
        if (modalBody) modalBody.scrollTop = 0;
        announce(list[0]);
    }

    /** The rule exactly as the endpoint takes it. */
    function collectRule() {
        var sev = $('nrSeverity').value; var ty = $('nrType').value; var ls = $('nrList').value;
        var kind = channelKind($('nrChannel').value);
        return {
            name: $('nrName').value.trim(),
            event_type: $('nrEvent').value,
            channel: $('nrChannel').value,
            severity_filter: sev === '' ? null : Number(sev),
            incident_type_filter: ty === '' ? null : Number(ty),
            recipients: kind === 'shared' ? [] : editing.recipients.slice(),
            email_list_id: (ls === '' || kind === 'shared') ? null : Number(ls),
            subject_template: $('nrSubject').value,
            body_template: $('nrBody').value,
            once_per_incident: $('nrOnce').checked ? 1 : 0,
            active: $('nrActive').checked ? 1 : 0
        };
    }

    // preview ------------------------------------------------------------------
    function schedulePreview() {
        if (previewTimer) window.clearTimeout(previewTimer);
        previewTimer = window.setTimeout(runPreview, 450);
    }

    function clearPreview() {
        $('nrPrevSubject').textContent = '';
        $('nrPrevBody').textContent = '';
        $('nrPrevSummary').textContent = '';
        clear($('nrPrevList'));
        clear($('nrWarnings'));
        show($('nrWarnings'), false);
    }

    function runPreview() {
        if (!editing) return;
        var seq = ++previewSeq;
        var data = collectRule();
        data.sample = $('nrSample').value;
        api('POST', 'preview', data).then(function (r) {
            if (seq !== previewSeq || !editing) return;
            renderPreview(r);
        });
    }

    function renderPreview(r) {
        clearPreview();
        if (r.status !== 200 || !r.body.rendered) {
            lastPreview = null;
            $('nrPrevSummary').textContent = 'No preview yet: ' + errText(r);
            return;
        }
        var b = r.body;
        lastPreview = b;
        $('nrPrevSubject').textContent = b.rendered.subject;
        $('nrPrevBody').textContent = b.rendered.body;
        var summary = 'Using ' + b.sample + ': ' + plural(b.queued, 'message would be sent', 'messages would be sent')
            + (b.skipped ? ', ' + b.skipped + ' skipped' : '') + ' (' + plural(b.recipient_count, 'recipient', 'recipients') + ').'
            + (b.rendered.format === 'html' ? ' Email goes out as HTML.' : '');
        $('nrPrevSummary').textContent = summary;
        var ul = $('nrPrevList');
        var i; var shownN = Math.min(b.deliveries.length, 25);
        for (i = 0; i < shownN; i++) {
            var d = b.deliveries[i];
            var li = el('li', 'd-flex gap-2');
            li.appendChild(icon(d.status === 'queued' ? 'check-circle text-success' : 'dash-circle text-body-secondary'));
            var txt = el('span', '');
            txt.appendChild(el('span', '', (d.label ? d.label + ' ' : '') + '(' + d.address + ') by ' + channelName(d.channel)));
            if (d.reason) txt.appendChild(el('div', 'text-body-secondary', d.reason));
            if (d.delayed_until) txt.appendChild(el('div', 'text-body-secondary', 'held until ' + d.delayed_until + ' by a security label'));
            li.appendChild(txt);
            ul.appendChild(li);
        }
        if (b.deliveries.length > shownN) ul.appendChild(el('li', 'text-body-secondary', '...and ' + (b.deliveries.length - shownN) + ' more.'));

        var warns = [];
        var nameEmpty = $('nrName').value.trim() === '';
        for (i = 0; i < (b.warnings || []).length; i++) {
            if (nameEmpty && /^Give the rule a name/.test(b.warnings[i])) continue;   // not news yet
            warns.push(b.warnings[i]);
        }
        if (warns.length) {
            var w = $('nrWarnings');
            var wl = el('ul', 'mb-0 ps-3');
            for (i = 0; i < warns.length; i++) wl.appendChild(el('li', '', warns[i]));
            w.appendChild(wl);
            show(w, true);
        }
    }

    // save -------------------------------------------------------------------
    function saveRule(thenTest) {
        if (busy.save) return;
        var data = collectRule();
        if (!data.name) {
            showFormErrors(['Give the rule a name.']);
            $('nrName').focus();
            return;
        }
        busy.save = true;
        $('nrBtnSave').disabled = true; $('nrBtnSaveTest').disabled = true;
        var action = editing.id ? 'update' : 'create';
        if (editing.id) data.id = editing.id;
        api('POST', action, data).then(function (r) {
            busy.save = false;
            $('nrBtnSave').disabled = false; $('nrBtnSaveTest').disabled = false;
            if (r.status === 200 && r.body.ok) {
                editing.id = r.body.id;
                show($('nrFormErrors'), false);
                loadRules().then(refreshQueue);
                if (thenTest) {
                    announce('Saved. Sending a test.');
                    runTest('me', false);
                } else {
                    ensureRuleModal().hide();
                    showMsg('success', 'Rule "' + r.body.rule.name + '" saved.', r.body.warnings);
                }
            } else {
                showFormErrors(r.body && r.body.errors && r.body.errors.length ? r.body.errors : [errText(r)]);
            }
        });
    }

    // test send ----------------------------------------------------------------
    function describeDestinations(preview) {
        var out = []; var i;
        if (!preview) return out;
        for (i = 0; i < preview.deliveries.length; i++) if (preview.deliveries[i].status === 'queued') out.push(preview.deliveries[i].address);
        return out;
    }

    function renderTestResult(host, r) {
        clear(host);
        if (r.status === 200 && r.body.ok) {
            var ul = el('ul', 'list-unstyled mb-0');
            var i;
            for (i = 0; i < r.body.sent.length; i++) {
                var s = r.body.sent[i];
                var li = el('li');
                li.appendChild(icon(s.ok ? 'check-circle-fill text-success' : 'x-circle-fill text-danger', 'me-1'));
                li.appendChild(document.createTextNode(s.ok ? 'Sent to ' + s.address : 'Failed to ' + s.address + ': ' + s.error));
                ul.appendChild(li);
            }
            for (i = 0; i < r.body.skipped.length; i++) {
                var sk = el('li', 'text-body-secondary');
                sk.appendChild(icon('dash-circle', 'me-1'));
                sk.appendChild(document.createTextNode('Skipped ' + r.body.skipped[i].address + ': ' + r.body.skipped[i].reason));
                ul.appendChild(sk);
            }
            host.appendChild(ul);
            if (r.body.failed) host.appendChild(el('div', 'text-danger mt-1', plural(r.body.failed, 'test message', 'test messages') + ' failed - see the delivery log.'));
            announce('Test finished.');
        } else {
            var msg = errText(r);
            if (r.body && r.body.skipped && r.body.skipped.length) msg += ' (' + r.body.skipped[0].address + ': ' + r.body.skipped[0].reason + ')';
            host.appendChild(el('div', 'text-danger', msg));
            announce(msg);
        }
    }

    /** mode 'me' | 'rule'. Asks before anything that reaches other people. */
    function runTest(mode, confirmed) {
        if (busy.test) return;
        var data = collectRule();
        data.mode = mode; data.sample = $('nrSample').value;
        if (confirmed) data.confirm_real = true;
        var host = $('nrTestResult');
        if (mode === 'rule' && !confirmed) {
            var dests = describeDestinations(lastPreview);
            var sharedKind = channelKind(data.channel) === 'shared';
            var text = sharedKind
                ? 'This posts a message marked [TEST] to the shared ' + channelName(data.channel) + ' destination, where everyone in it will see it.\n\nSend it?'
                : 'This sends a real message marked [TEST] to ' + plural(dests.length, 'destination', 'destinations') + ':\n\n'
                    + dests.slice(0, 15).join('\n') + (dests.length > 15 ? '\n...and ' + (dests.length - 15) + ' more' : '')
                    + '\n\nIf any of these is a pager or an Active911 alert address, real devices will be paged. Send it?';
            if (!dests.length && !sharedKind) { host.textContent = 'There are no recipients to send a test to yet.'; return; }
            if (!window.confirm(text)) return;
            confirmed = true; data.confirm_real = true;
        }
        busy.test = true;
        $('nrTestMe').disabled = true; $('nrTestRule').disabled = true;
        host.textContent = 'Sending...';
        api('POST', 'test_send', data).then(function (r) {
            if (r.status === 409 && r.body.code === 'confirm_required' && !confirmed) {
                busy.test = false;
                $('nrTestMe').disabled = false; $('nrTestRule').disabled = false;
                if (window.confirm(r.body.error + '\n\nSend it?')) { runTest(mode, true); return; }
                clear(host);
                return;
            }
            busy.test = false;
            $('nrTestMe').disabled = false; $('nrTestRule').disabled = false;
            renderTestResult(host, r);
            refreshQueue();
        });
    }

    /** "Test" on a saved rule's row: one message to the administrator alone. */
    function testSaved(r) {
        var data = {
            name: r.name, event_type: r.event_type, channel: r.channel, severity_filter: r.severity_filter,
            incident_type_filter: r.incident_type_filter, recipients: r.recipients, email_list_id: r.email_list_id,
            subject_template: r.subject_template, body_template: r.body_template, once_per_incident: r.once_per_incident,
            active: r.active, mode: 'me', sample: 'synthetic'
        };
        var send = function (confirm) {
            if (confirm) data.confirm_real = true;
            api('POST', 'test_send', data).then(function (res) {
                if (res.status === 409 && res.body.code === 'confirm_required' && !confirm) {
                    if (window.confirm(res.body.error + '\n\nSend it?')) send(true);
                    return;
                }
                if (res.status === 200 && res.body.ok) {
                    var ok = 0; var i;
                    for (i = 0; i < res.body.sent.length; i++) if (res.body.sent[i].ok) ok++;
                    showMsg(res.body.failed ? 'warning' : 'success',
                        'Test of "' + r.name + '": ' + ok + ' sent' + (res.body.failed ? ', ' + res.body.failed + ' failed (see the delivery log)' : '') + '.', res.body.warnings);
                } else showMsg('danger', errText(res));
                refreshQueue();
            });
        };
        send(false);
    }

    // delivery settings -------------------------------------------------------------
    function saveSettings() {
        var msg = $('nrSetMsg');
        msg.className = 'small text-body-secondary';
        msg.textContent = 'Saving...';
        api('POST', 'save_settings', {
            email_format: $('nrSetFormat').value,
            prefs_mode: $('nrSetPrefs').value,
            retention_days: $('nrSetRetention').value === '' ? 180 : Number($('nrSetRetention').value)
        }).then(function (r) {
            if (r.status === 200 && r.body.ok) {
                meta.settings = r.body.settings;
                renderSettings();
                msg.className = 'small text-success';
                msg.textContent = 'Saved.';
                announce('Delivery settings saved.');
            } else {
                msg.className = 'small text-danger';
                msg.textContent = errText(r);
            }
        });
    }

    // delivery log -------------------------------------------------------------------
    function openLog(ruleId) {
        var sel = $('nrLogRule');
        clear(sel);
        var o = el('option', '', 'All rules'); o.value = ''; sel.appendChild(o);
        var i;
        for (i = 0; i < rules.length; i++) { o = el('option', '', rules[i].name); o.value = String(rules[i].id); sel.appendChild(o); }
        sel.value = ruleId ? String(ruleId) : '';
        $('nrLogStatus').value = ''; $('nrLogFrom').value = ''; $('nrLogTo').value = '';
        logState.offset = 0;
        if (!logModal) logModal = window.bootstrap.Modal.getOrCreateInstance($('nrLogModal'));
        logModal.show();
        loadLog();
    }

    function loadLog() {
        var qs = '&limit=' + logState.limit + '&offset=' + logState.offset;
        var rid = $('nrLogRule').value, st = $('nrLogStatus').value, fr = $('nrLogFrom').value, to = $('nrLogTo').value;
        if (rid) qs += '&rule_id=' + encodeURIComponent(rid);
        if (st) qs += '&status=' + encodeURIComponent(st);
        if (fr) qs += '&from=' + encodeURIComponent(fr);
        if (to) qs += '&to=' + encodeURIComponent(to);
        var body = $('nrLogBody');
        clear(body);
        var tr = el('tr'); var td = el('td', 'text-body-secondary', 'Loading...'); td.colSpan = 7; tr.appendChild(td); body.appendChild(tr);
        api('GET', 'log', null, qs).then(function (r) {
            clear(body);
            if (r.status !== 200) {
                var e = el('tr'); var ed = el('td', 'text-danger', errText(r)); ed.colSpan = 7; e.appendChild(ed); body.appendChild(e);
                return;
            }
            logState.total = r.body.total;
            var rows = r.body.rows || [];
            if (!rows.length) {
                var n = el('tr'); var nd = el('td', 'text-body-secondary', 'Nothing in the log for these filters.'); nd.colSpan = 7; n.appendChild(nd); body.appendChild(n);
            }
            var i;
            for (i = 0; i < rows.length; i++) appendLogRow(body, rows[i]);
            var from = rows.length ? logState.offset + 1 : 0;
            $('nrLogInfo').textContent = from + ' to ' + (logState.offset + rows.length) + ' of ' + logState.total;
            $('nrLogPrev').disabled = logState.offset <= 0;
            $('nrLogNext').disabled = logState.offset + logState.limit >= logState.total;
        });
    }

    var STATUS_LABEL = { queued: 'Waiting', sent: 'Sent', failed: 'Failed', skipped: 'Skipped', killed: 'Cancelled', expired: 'Expired' };
    var STATUS_CLASS = { queued: 'text-bg-warning', sent: 'text-bg-success', failed: 'text-bg-danger', skipped: 'text-bg-secondary', killed: 'text-bg-secondary', expired: 'text-bg-danger' };

    function appendLogRow(body, row) {
        var tr = el('tr');
        tr.appendChild(el('td', 'text-nowrap', row.sent_at));
        var ruleText = row.rule_name !== null ? row.rule_name : (row.rule_id !== null ? '(deleted rule)' : '(test send)');
        tr.appendChild(el('td', '', ruleText));
        var tdI = el('td');
        if (row.ticket_id !== null) {
            var a = el('a', '', '#' + row.ticket_id);
            a.href = 'incident-detail.php?id=' + encodeURIComponent(String(row.ticket_id));
            tdI.appendChild(a);
        } else tdI.textContent = '-';
        tr.appendChild(tdI);
        tr.appendChild(el('td', '', channelName(row.channel)));
        tr.appendChild(el('td', 'text-break', row.recipient));
        var tdS = el('td');
        var badge = el('span', 'badge ' + (STATUS_CLASS[row.effective_status] || 'text-bg-secondary'), STATUS_LABEL[row.effective_status] || row.effective_status);
        if (row.effective_status !== row.status) badge.title = 'The log row says "' + row.status + '"; the queue says "' + row.effective_status + '".';
        tdS.appendChild(badge);
        tr.appendChild(tdS);
        var tdD = el('td');
        if (row.reason) tdD.appendChild(el('div', row.effective_status === 'failed' ? 'text-danger' : 'text-body-secondary', row.reason));
        var toggle = el('button', 'btn btn-link btn-sm p-0', 'Show message');
        toggle.type = 'button';
        toggle.setAttribute('aria-expanded', 'false');
        tdD.appendChild(toggle);
        tr.appendChild(tdD);
        body.appendChild(tr);

        var detail = el('tr', 'd-none');
        var dd = el('td'); dd.colSpan = 7;
        dd.appendChild(el('div', 'fw-semibold', row.subject || '(no subject)'));
        dd.appendChild(el('pre', 'mb-0 small', row.body + (row.body_truncated ? '\n[shortened]' : '')));
        detail.appendChild(dd);
        body.appendChild(detail);
        toggle.addEventListener('click', function () {
            var open = detail.classList.contains('d-none');
            show(detail, open);
            toggle.textContent = open ? 'Hide message' : 'Show message';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    // ── wiring ───────────────────────────────────────────────────────────────
    function scheduleRefresh() {
        if (refreshTimer) window.clearTimeout(refreshTimer);
        refreshTimer = window.setTimeout(function () {
            var panel = $('panel-notifications');
            if (panel && panel.classList.contains('active') && !($('nrRuleModal').classList.contains('show'))) loadAll();
        }, 800);
    }

    function bind() {
        $('nrBtnNew').addEventListener('click', function () { openRule(null, null); });
        $('nrBtnLog').addEventListener('click', function () { openLog(null); });
        $('nrBtnRefresh').addEventListener('click', function () { loadAll().then(function () { announce('Refreshed.'); }); });
        $('nrSetSave').addEventListener('click', saveSettings);
        $('nrBtnSave').addEventListener('click', function () { saveRule(false); });
        $('nrBtnSaveTest').addEventListener('click', function () { saveRule(true); });
        $('nrTestMe').addEventListener('click', function () { runTest('me', false); });
        $('nrTestRule').addEventListener('click', function () { runTest('rule', false); });
        $('nrLogApply').addEventListener('click', function () { logState.offset = 0; loadLog(); });
        $('nrLogPrev').addEventListener('click', function () { logState.offset = Math.max(0, logState.offset - logState.limit); loadLog(); });
        $('nrLogNext').addEventListener('click', function () { logState.offset += logState.limit; loadLog(); });
        $('nrEmailAdd').addEventListener('click', addEmails);
        $('nrPhoneAdd').addEventListener('click', addPhone);
        $('nrEmailIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addEmails(); } });
        $('nrPhoneIn').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addPhone(); } });
        $('nrResetText').addEventListener('click', function () {
            $('nrSubject').value = ''; $('nrBody').value = '';
            updateCounts(); schedulePreview(); announce('Subject and message cleared - the default will be used.');
        });

        $('nrEvent').addEventListener('change', function () { applyEvent(); schedulePreview(); });
        $('nrChannel').addEventListener('change', function () { applyChannel(true); applyList(); schedulePreview(); });
        $('nrList').addEventListener('change', applyList);
        var fields = ['nrName', 'nrSeverity', 'nrType', 'nrOnce', 'nrActive', 'nrList', 'nrSample'];
        var i;
        for (i = 0; i < fields.length; i++) {
            $(fields[i]).addEventListener('change', schedulePreview);
        }
        $('nrName').addEventListener('input', schedulePreview);
        $('nrSubject').addEventListener('input', function () { updateCounts(); schedulePreview(); });
        $('nrBody').addEventListener('input', function () { updateCounts(); schedulePreview(); });
        $('nrSubject').addEventListener('focus', function () { lastTextField = $('nrSubject'); });
        $('nrBody').addEventListener('focus', function () { lastTextField = $('nrBody'); });

        $('nrUserQ').addEventListener('input', function () {
            if (searchTimer) window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(searchUsers, 250);
        });
        $('nrUserQ').addEventListener('keydown', onUserKey);
        $('nrUserResults').addEventListener('keydown', onResultsKey);
        document.addEventListener('click', function (e) {
            var box = $('nrAddUser');
            if (box && !box.contains(e.target)) hideUserResults();
        });

        // Ctrl+Enter saves; Escape is Bootstrap's own.
        $('nrRuleModal').addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); saveRule(false); }
        });
        $('nrRuleModal').addEventListener('hidden.bs.modal', function () {
            editing = null;
            previewSeq++;
            if (previewTimer) window.clearTimeout(previewTimer);
        });

        // "go to another settings tab" links in the info box
        var gotos = document.querySelectorAll('#panel-notifications [data-nr-goto]');
        for (i = 0; i < gotos.length; i++) {
            (function (b) { b.addEventListener('click', function () { gotoTab(b.getAttribute('data-nr-goto')); }); })(gotos[i]);
        }

        // The info box stays open until the administrator hides it (per browser).
        var dismissed = false;
        try { dismissed = window.localStorage.getItem(INFO_KEY) === '1'; } catch (e) { dismissed = false; }
        if (dismissed) {
            $('nrInfoBody').classList.remove('show');
            $('nrInfoToggle').setAttribute('aria-expanded', 'false');
        }
        $('nrInfoDismiss').addEventListener('click', function () {
            try { window.localStorage.setItem(INFO_KEY, '1'); } catch (e) { /* private mode: fine, it just comes back */ }
            window.bootstrap.Collapse.getOrCreateInstance($('nrInfoBody'), { toggle: false }).hide();
        });

        if (window.EventBus && typeof window.EventBus.on === 'function') {
            window.EventBus.on('notification_rules:changed', scheduleRefresh);
        }
    }

    function init() {
        if (inited) return;
        if (!$('panel-notifications')) return;
        inited = true;
        bind();
        loadAll();
    }

    window.NotificationRulesAdmin = { init: init, refresh: loadAll };
})();
