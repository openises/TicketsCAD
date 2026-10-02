/**
 * NewUI v4.0 — Console Designer (Phase 152 — Console rebuild)
 *
 * Rebuilt from the b2.5 free-form GridStack + custom-drag canvas to a
 * plain ADMIN-ASSIGNED ORDERED LIST (order via Up/Down buttons + a 1x/2x
 * width toggle per strip), per the 5-persona design review's UNANIMOUS
 * finding (specs/phase-152-comms-console-v2/tasks.md, "Console rebuild"):
 * real dispatch consoles (MCC 7500, Zetron, Avtec) use admin-assigned
 * layouts, not live drag — and no GridStack/drag library is needed
 * anywhere in the new console or designer as a result.
 *
 * A strip's content is no longer a freely-placed component array — it is
 * the same {show:{ptt,sel,mon,mute,vol,text,vu,recall,patchchips},hotkey}
 * bag console.js's renderStrip() consumes (inc/console-views.php). The
 * Inspector (right pane) edits a selected strip's overrides + show flags
 * + hotkey directly; there is no separate "palette" of placeable
 * components anymore — the show-flag checkboxes ARE the palette.
 *
 * vu has no backend yet (VU metering is a separate, still-pending
 * Console rebuild task) — its checkbox renders disabled with an honest
 * "future" tag, mirroring this project's own precedent for the old
 * 'say' (TTS) component, rather than silently omitting it or pretending
 * it works. patchchips and recall became real the moment the patch rail
 * (assets/js/console-patch-rail.js) and the Recall tab (assets/js/
 * console-recall.js) shipped — see FUTURE_FLAGS below.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var CH_API = 'api/channels.php';
    var VIEWS_API = 'api/console-views.php';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    // Mirrors console_strip_template_needs() (inc/console-views.php) — a
    // capability-gate map so the designer can never OFFER a checkbox the
    // server would silently drop to false anyway. Kept in sync manually
    // (nine small, stable keys); tests/test_phase152_console_strip_templates.php
    // asserts this object's keys match the server's own map.
    var SHOW_FLAG_NEEDS = {
        ptt: ['voice_tx'], mon: ['voice_rx'], mute: ['voice_rx'], vol: ['voice_rx'],
        text: ['text_rx', 'text_tx', 'source'],
        vu: ['voice_rx'], recall: ['voice_rx', 'text_rx', 'text_tx', 'source'],
        sel: null, patchchips: null
    };
    var SHOW_FLAG_LABELS = {
        ptt: 'PTT', sel: 'Select', mon: 'Monitor', mute: 'Mute', vol: 'Volume',
        text: 'Text / messages', vu: 'VU meter', recall: 'Recall shortcut',
        patchchips: 'Patch badges'
    };
    // Not yet backed by a real subsystem (VU metering, Recall tab, patch
    // rail are separate pending Console rebuild tasks) — offered as
    // visibly-future, disabled checkboxes rather than omitted outright.
    var FUTURE_FLAGS = { vu: true };
    var HOTKEY_RE = /^(F[1-9]|F1[0-2]|[A-Za-z0-9])$/;

    function showFlagAllowed(key, caps) {
        var needs = SHOW_FLAG_NEEDS[key];
        if (needs === null) { return true; }
        for (var i = 0; i < needs.length; i++) { if (caps[needs[i]]) { return true; } }
        return false;
    }

    var viewListEl = document.getElementById('cdViewList');       // shared (admin-only panel, may be absent)
    var myViewListEl = document.getElementById('cdMyViewList');   // personal (always present)
    var stripListEl = document.getElementById('cdStripList');
    var canvasTitle = document.getElementById('cdCanvasTitle');
    var channelListEl = document.getElementById('cdChannelList');
    var inspectorEl = document.getElementById('cdInspector');
    var inspectorBody = document.getElementById('cdInspectorBody');
    var saveBtn = document.getElementById('cdSaveBtn');
    var newViewBtn = document.getElementById('cdNewViewBtn');
    var newPersonalViewBtn = document.getElementById('cdNewPersonalViewBtn');
    var cloneBtn = document.getElementById('cdCloneBtn');
    var cloneSourcesCard = document.getElementById('cdCloneSourcesCard');
    var cloneSourceListEl = document.getElementById('cdCloneSourceList');
    var dirtyFlag = document.getElementById('cdDirtyFlag');
    if (!myViewListEl || !stripListEl) { return; }

    var channels = [];
    var channelsById = {};
    var views = [];              // shared (owner_user_id NULL)
    var myViews = [];             // the caller's own personal views
    var sharedPersonalViews = []; // OTHER users' is_shared personal views (clone sources)
    var currentViewId = null;
    var currentViewScope = null; // 'shared' | 'personal' — which list currentViewId lives in
    var meta = { name: '', icon: '', is_shared: false };
    var dirty = false;

    // The one piece of client-side state this rebuild actually needs: a
    // plain ordered array. Array index IS the strip's position — the
    // server derives position from array order on save (inc/console-
    // views.php's console_view_save_strips()), so nothing here tracks a
    // position number explicitly.
    var strips = [];              // [{channel_id, overrides, show, hotkey, width}]
    var selIndex = null;          // index into strips[], or null

    // ── Helpers ──────────────────────────────────────────────────
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined && text !== null) { n.textContent = text; }
        return n;
    }

    function adapterIcon(adapter) {
        var map = {
            zello: 'bi-mic-fill', dmr_bm: 'bi-broadcast', dmr_local: 'bi-broadcast',
            mesh: 'bi-diagram-3', meshcore: 'bi-diagram-3', aprs: 'bi-geo-alt',
            local_chat: 'bi-chat-dots', smtp: 'bi-envelope', sms: 'bi-phone',
            slack: 'bi-slack', push: 'bi-bell', nws: 'bi-cloud-lightning-rain',
            eventbus: 'bi-lightning-charge', allstar: 'bi-broadcast-pin',
            sip: 'bi-telephone', intercom: 'bi-door-open', intercom_dd: 'bi-door-open', ptt1: 'bi-mic'
        };
        return map[adapter] || 'bi-broadcast-pin';
    }

    function post(payload, cb) {
        payload.csrf_token = csrf;
        fetch(VIEWS_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); }).then(function (j) {
            if (j && j.views) { views = j.views; }
            if (j && j.my_views) { myViews = j.my_views; }
            if (j && j.shared_personal_views) { sharedPersonalViews = j.shared_personal_views; }
            cb(j || {});
        }).catch(function () { cb({ error: 'network error' }); });
    }

    var toastEl = document.getElementById('cdToast');
    var toastTimer = null;
    function showToast(type, message) {
        if (!toastEl) { return; }
        toastEl.className = 'alert alert-' + type + ' py-2 mb-3';
        toastEl.textContent = message;
        if (toastTimer) { window.clearTimeout(toastTimer); }
        toastTimer = window.setTimeout(function () {
            toastEl.classList.add('d-none');
        }, 4000);
    }

    function setDirty(d) {
        dirty = d;
        if (dirtyFlag) { dirtyFlag.textContent = d ? 'unsaved changes' : ''; }
        if (saveBtn) { saveBtn.classList.toggle('d-none', currentViewId === null); }
    }

    // Default show-flags for a strip freshly added from the channel list —
    // everything the channel is capable of, matching console.js's
    // defaultShowTemplate() for the auto view (same reasoning: a new
    // strip should start USEFUL, not blank).
    function defaultShowForChannel(caps) {
        return {
            ptt: !!caps.voice_tx, sel: true,
            mon: !!caps.voice_rx, mute: !!caps.voice_rx, vol: !!caps.voice_rx,
            text: !!(caps.text_rx || caps.text_tx || caps.source),
            vu: false, recall: false, patchchips: false
        };
    }

    // ── Strip list (middle pane) ────────────────────────────────────
    function renderStripList() {
        stripListEl.innerHTML = '';
        if (!strips.length) {
            stripListEl.appendChild(el('div', 'text-body-secondary p-3 small',
                currentViewId === null
                    ? 'Pick a view on the left, or create one.'
                    : 'No strips yet — click a channel on the right to add one.'));
            return;
        }
        for (var i = 0; i < strips.length; i++) {
            (function (s, idx) {
                var ch = channelsById[s.channel_id];
                var row = el('div', 'cd-strip-row list-group-item d-flex align-items-center gap-2'
                    + (idx === selIndex ? ' active' : ''));
                row.appendChild(el('i', 'bi ' + adapterIcon(ch ? ch.adapter : '')));
                var lbl = el('span', 'flex-grow-1 text-truncate small',
                    s.overrides.short_label || s.overrides.label || (ch ? (ch.short_label || ch.label) : ('#' + s.channel_id)));
                row.appendChild(lbl);
                if (s.hotkey) { row.appendChild(el('span', 'badge text-bg-secondary', s.hotkey)); }
                row.appendChild(el('span', 'badge text-bg-info', s.width === 2 ? '2x' : '1x'));

                var upBtn = el('button', 'btn btn-sm btn-outline-secondary py-0 px-1', null);
                upBtn.type = 'button'; upBtn.title = 'Move up'; upBtn.disabled = idx === 0;
                upBtn.appendChild(el('i', 'bi bi-arrow-up'));
                upBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var t = strips[idx - 1]; strips[idx - 1] = strips[idx]; strips[idx] = t;
                    if (selIndex === idx) { selIndex = idx - 1; } else if (selIndex === idx - 1) { selIndex = idx; }
                    setDirty(true); renderStripList(); renderInspector();
                });
                row.appendChild(upBtn);

                var downBtn = el('button', 'btn btn-sm btn-outline-secondary py-0 px-1', null);
                downBtn.type = 'button'; downBtn.title = 'Move down'; downBtn.disabled = idx === strips.length - 1;
                downBtn.appendChild(el('i', 'bi bi-arrow-down'));
                downBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var t = strips[idx + 1]; strips[idx + 1] = strips[idx]; strips[idx] = t;
                    if (selIndex === idx) { selIndex = idx + 1; } else if (selIndex === idx + 1) { selIndex = idx; }
                    setDirty(true); renderStripList(); renderInspector();
                });
                row.appendChild(downBtn);

                var rmBtn = el('button', 'btn btn-sm btn-outline-danger py-0 px-1', null);
                rmBtn.type = 'button'; rmBtn.title = 'Remove strip';
                rmBtn.appendChild(el('i', 'bi bi-x-lg'));
                rmBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    strips.splice(idx, 1);
                    if (selIndex === idx) { selIndex = null; } else if (selIndex !== null && selIndex > idx) { selIndex--; }
                    setDirty(true); renderStripList(); renderInspector();
                });
                row.appendChild(rmBtn);

                row.addEventListener('click', function () {
                    selIndex = idx;
                    renderStripList();
                    renderInspector();
                });
                stripListEl.appendChild(row);
            })(strips[i], i);
        }
    }

    // ── Inspector (right pane, bottom card) ─────────────────────────
    function inspectorRow(labelText, inputEl2) {
        var row = el('div', 'mb-2');
        row.appendChild(el('label', 'form-label small mb-1', labelText));
        row.appendChild(inputEl2);
        return row;
    }

    function colorRow(labelText, value, onChange) {
        var wrap = el('div', 'd-flex align-items-center gap-2');
        var inp = document.createElement('input');
        inp.type = 'color';
        inp.className = 'form-control form-control-color form-control-sm';
        inp.value = /^#[0-9a-fA-F]{6}$/.test(value || '') ? value : '#dc3545';
        var clear = el('a', 'small' + (value ? '' : ' d-none'), 'clear');
        clear.href = '#';
        inp.addEventListener('input', function () { clear.classList.remove('d-none'); onChange(inp.value); });
        clear.addEventListener('click', function (e) { e.preventDefault(); clear.classList.add('d-none'); onChange(''); });
        wrap.appendChild(inp);
        wrap.appendChild(clear);
        return inspectorRow(labelText, wrap);
    }

    function textRow(labelText, value, placeholder, maxLen, onChange) {
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm';
        inp.maxLength = maxLen;
        inp.value = value || '';
        inp.placeholder = placeholder || '';
        inp.addEventListener('input', function () { onChange(inp.value); });
        return inspectorRow(labelText, inp);
    }

    function renderInspector() {
        var s = (selIndex !== null) ? strips[selIndex] : null;
        if (!s) { inspectorEl.classList.add('d-none'); return; }
        inspectorEl.classList.remove('d-none');
        inspectorBody.innerHTML = '';
        var ch = channelsById[s.channel_id];
        var caps = (ch && ch.capabilities) || {};

        inspectorBody.appendChild(el('div', 'small fw-semibold mb-2',
            (ch ? ch.label + ' — ' + ch.adapter : 'missing channel #' + s.channel_id)));

        inspectorBody.appendChild(textRow('Label override', s.overrides.label, ch ? ch.label : '', 120, function (v) {
            if (v) { s.overrides.label = v; } else { delete s.overrides.label; }
            setDirty(true); renderStripList();
        }));
        inspectorBody.appendChild(textRow('Short label', s.overrides.short_label, 'shown in tight spots', 24, function (v) {
            if (v) { s.overrides.short_label = v; } else { delete s.overrides.short_label; }
            setDirty(true); renderStripList();
        }));
        inspectorBody.appendChild(colorRow('Strip accent color', s.overrides.color, function (v) {
            if (v) { s.overrides.color = v; } else { delete s.overrides.color; }
            setDirty(true);
        }));
        if (caps.voice_tx) {
            inspectorBody.appendChild(colorRow('PTT button color', s.overrides.ptt_color, function (v) {
                if (v) { s.overrides.ptt_color = v; } else { delete s.overrides.ptt_color; }
                setDirty(true);
            }));
        }

        var widthSel = document.createElement('select');
        widthSel.className = 'form-select form-select-sm';
        var o1 = el('option', null, 'Normal (1x)'); o1.value = '1';
        var o2 = el('option', null, 'Wide (2x)'); o2.value = '2';
        widthSel.appendChild(o1); widthSel.appendChild(o2);
        widthSel.value = String(s.width === 2 ? 2 : 1);
        widthSel.addEventListener('change', function () {
            s.width = (widthSel.value === '2') ? 2 : 1;
            setDirty(true); renderStripList();
        });
        inspectorBody.appendChild(inspectorRow('Width', widthSel));

        var hkInp = document.createElement('input');
        hkInp.type = 'text';
        hkInp.className = 'form-control form-control-sm';
        hkInp.maxLength = 3;
        hkInp.placeholder = 'e.g. F5 or A';
        hkInp.value = s.hotkey || '';
        hkInp.title = 'A single key or F1-F12 — toggles Select on this strip. Must be unique within the view.';
        hkInp.addEventListener('input', function () {
            var v = hkInp.value.replace(/^\s+|\s+$/g, '').toUpperCase();
            s.hotkey = (v && HOTKEY_RE.test(v)) ? v : null;
            setDirty(true); renderStripList();
        });
        inspectorBody.appendChild(inspectorRow('Hotkey (optional)', hkInp));

        inspectorBody.appendChild(el('div', 'small fw-semibold mt-3 mb-1', 'Controls shown on this strip'));
        var flagsWrap = el('div', 'd-flex flex-column gap-1');
        var order = ['sel', 'ptt', 'mon', 'mute', 'vol', 'text', 'vu', 'recall', 'patchchips'];
        for (var i = 0; i < order.length; i++) {
            (function (key) {
                var allowed = showFlagAllowed(key, caps);
                var future = !!FUTURE_FLAGS[key];
                var lbl = el('label', 'form-check form-check-inline mb-0', null);
                var inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.className = 'form-check-input';
                inp.checked = !!s.show[key];
                inp.disabled = !allowed || future;
                inp.addEventListener('change', function () {
                    s.show[key] = inp.checked;
                    setDirty(true);
                });
                lbl.appendChild(inp);
                lbl.appendChild(el('span', 'form-check-label small', SHOW_FLAG_LABELS[key]));
                if (future) {
                    lbl.appendChild(el('span', 'badge text-bg-warning ms-1', 'future'));
                    lbl.title = 'Arrives with a later phase — no backend yet';
                } else if (!allowed) {
                    lbl.title = 'This channel is not capable of this control';
                }
                flagsWrap.appendChild(lbl);
            })(order[i]);
        }
        inspectorBody.appendChild(flagsWrap);
    }

    // ── View list ────────────────────────────────────────────────
    function renderOneViewList(targetEl, list, scope, emptyText) {
        if (!targetEl) { return; }
        targetEl.innerHTML = '';
        if (!list.length) {
            targetEl.appendChild(el('div', 'list-group-item small text-body-secondary', emptyText));
        }
        for (var i = 0; i < list.length; i++) {
            (function (v) {
                var a = el('a', 'list-group-item list-group-item-action py-2 d-flex align-items-center'
                    + ((v.id === currentViewId && scope === currentViewScope) ? ' active' : ''), null);
                a.href = '#';
                a.appendChild(el('i', 'bi ' + (v.icon || 'bi-broadcast-pin') + ' me-2'));
                a.appendChild(el('span', 'flex-grow-1 text-truncate small', v.name));
                if (scope === 'personal' && v.is_shared) {
                    var shIcon = el('i', 'bi bi-people-fill text-success me-1');
                    shIcon.title = 'Shared — other operators can clone this';
                    a.appendChild(shIcon);
                }
                a.appendChild(el('span', 'badge bg-secondary ms-1', String((v.strips || []).length)));
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (dirty && !window.confirm('Discard unsaved changes to the current view?')) { return; }
                    selectView(v.id, scope);
                });
                targetEl.appendChild(a);
            })(list[i]);
        }
    }

    function renderViewList() {
        if (viewListEl) { renderOneViewList(viewListEl, views, 'shared', 'No shared views yet — create one.'); }
        renderOneViewList(myViewListEl, myViews, 'personal', 'No personal views yet — create one, or clone an existing view.');
    }

    function findView(id, scope) {
        var list = (scope === 'personal') ? myViews : views;
        for (var i = 0; i < list.length; i++) { if (list[i].id === id) { return list[i]; } }
        return null;
    }

    function selectView(id, scope) {
        currentViewId = id;
        currentViewScope = scope;
        selIndex = null;
        var v = findView(id, scope);
        meta = { name: v ? v.name : '', icon: (v && v.icon) || '', is_shared: !!(v && v.is_shared) };
        strips = [];
        if (v) {
            for (var i = 0; i < (v.strips || []).length; i++) {
                var s = v.strips[i];
                strips.push({
                    channel_id: s.channel_id,
                    overrides: s.overrides || {},
                    show: s.show || {},
                    hotkey: s.hotkey || null,
                    width: s.width === 2 ? 2 : 1
                });
            }
        }
        renderCanvasChrome();
        setDirty(false);
        renderViewList();
        renderStripList();
        renderInspector();
    }

    // ── Canvas chrome (view name/icon/delete in the card header) ─
    function renderCanvasChrome() {
        canvasTitle.innerHTML = '';
        if (currentViewId === null) {
            canvasTitle.textContent = 'Select or create a view';
            return;
        }
        var nameInp = document.createElement('input');
        nameInp.type = 'text';
        nameInp.className = 'form-control form-control-sm d-inline-block cd-name-input';
        nameInp.value = meta.name;
        nameInp.maxLength = 80;
        nameInp.addEventListener('input', function () { meta.name = nameInp.value; setDirty(true); });
        canvasTitle.appendChild(nameInp);
        var iconInp = document.createElement('input');
        iconInp.type = 'text';
        iconInp.className = 'form-control form-control-sm d-inline-block ms-2 cd-icon-input';
        iconInp.placeholder = 'bi-broadcast-pin';
        iconInp.value = meta.icon;
        iconInp.title = 'Tab icon (a Bootstrap Icons bi-* class)';
        iconInp.addEventListener('input', function () { meta.icon = iconInp.value; setDirty(true); });
        canvasTitle.appendChild(iconInp);

        // "Available for others to adopt" toggle, personal views only.
        // Never a live shared tab; just makes this view show up in OTHER
        // operators' "Clone from…" list (console_views.is_shared).
        if (currentViewScope === 'personal') {
            var shareLbl = el('label', 'form-check form-check-inline ms-2 mb-0 cd-share-toggle', null);
            var shareInp = document.createElement('input');
            shareInp.type = 'checkbox';
            shareInp.className = 'form-check-input';
            shareInp.checked = !!meta.is_shared;
            shareInp.title = 'Let other operators clone this layout for themselves';
            shareInp.addEventListener('change', function () { meta.is_shared = shareInp.checked; setDirty(true); });
            shareLbl.appendChild(shareInp);
            shareLbl.appendChild(el('span', 'form-check-label small', 'Shared'));
            canvasTitle.appendChild(shareLbl);
        }

        var delBtn = el('button', 'btn btn-sm btn-outline-danger ms-2', null);
        delBtn.type = 'button';
        delBtn.title = 'Delete this view';
        delBtn.appendChild(el('i', 'bi bi-trash'));
        delBtn.addEventListener('click', function () {
            var warn = (currentViewScope === 'shared')
                ? 'Delete shared view "' + meta.name + '"? Every dispatcher using it loses this tab.'
                : 'Delete your personal view "' + meta.name + '"?';
            if (!window.confirm(warn)) { return; }
            post({ action: 'delete', id: currentViewId }, function (j) {
                if (j.ok) {
                    currentViewId = null; currentViewScope = null; strips = []; selIndex = null;
                    setDirty(false); renderViewList(); renderCanvasChrome(); renderStripList(); renderInspector();
                } else {
                    window.alert(j.error || 'Delete failed');
                }
            });
        });
        canvasTitle.appendChild(delBtn);
    }

    function promptNewViewRow(containerEl, scope, payloadExtra, placeholder) {
        if (containerEl.querySelector('.cd-newview-row')) { return; }
        var row = el('div', 'list-group-item py-2 cd-newview-row');
        var grp = el('div', 'input-group input-group-sm');
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm';
        inp.placeholder = placeholder || 'View name';
        inp.maxLength = 80;
        var ok = el('button', 'btn btn-sm btn-primary', null);
        ok.type = 'button';
        ok.appendChild(el('i', 'bi bi-check-lg'));
        grp.appendChild(inp);
        grp.appendChild(ok);
        row.appendChild(grp);
        containerEl.insertBefore(row, containerEl.firstChild);
        inp.focus();
        var create = function () {
            var name = inp.value.replace(/^\s+|\s+$/g, '');
            if (!name) { row.parentNode.removeChild(row); return; }
            ok.disabled = true;
            var payload = { action: 'create', name: name };
            for (var k in payloadExtra) { if (Object.prototype.hasOwnProperty.call(payloadExtra, k)) { payload[k] = payloadExtra[k]; } }
            post(payload, function (j) {
                if (j.ok) { renderViewList(); selectView(j.id, scope); }
                else { ok.disabled = false; window.alert(j.error || 'Create failed'); }
            });
        };
        ok.addEventListener('click', create);
        inp.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); create(); }
            if (e.key === 'Escape') { row.parentNode.removeChild(row); }
        });
    }

    if (newViewBtn) { newViewBtn.addEventListener('click', function () { promptNewViewRow(viewListEl, 'shared', {}, 'Shared view name'); }); }
    if (newPersonalViewBtn) { newPersonalViewBtn.addEventListener('click', function () { promptNewViewRow(myViewListEl, 'personal', { personal: true }, 'Personal view name'); }); }

    // ── Clone an existing view ──────────────────────────────────────
    function renderCloneSources() {
        if (!cloneSourceListEl) { return; }
        cloneSourceListEl.innerHTML = '';
        var groups = [
            { label: 'Shared Views', list: views },
            { label: 'My Personal Views', list: myViews },
            { label: 'Shared by other operators', list: sharedPersonalViews }
        ];
        var any = false;
        for (var g = 0; g < groups.length; g++) {
            if (!groups[g].list.length) { continue; }
            any = true;
            cloneSourceListEl.appendChild(el('div', 'list-group-item py-1 small fw-semibold text-body-secondary', groups[g].label));
            for (var i = 0; i < groups[g].list.length; i++) {
                (function (v) {
                    var a = el('a', 'list-group-item list-group-item-action py-2 d-flex align-items-center', null);
                    a.href = '#';
                    a.appendChild(el('i', 'bi ' + (v.icon || 'bi-broadcast-pin') + ' me-2'));
                    var lbl = v.name + (v.owner_display ? ' — ' + v.owner_display : '');
                    a.appendChild(el('span', 'flex-grow-1 text-truncate small', lbl));
                    a.appendChild(el('span', 'badge bg-secondary ms-1', String((v.strips || []).length)));
                    a.addEventListener('click', function (e) {
                        e.preventDefault();
                        cloneSourcesCard.classList.add('d-none');
                        var suggested = v.name + ' copy';
                        promptNewViewRow(myViewListEl, 'personal', { personal: true, based_on_view_id: v.id }, 'New view name');
                        var pending = myViewListEl.querySelector('.cd-newview-row input');
                        if (pending) { pending.value = suggested; pending.select(); }
                    });
                    cloneSourceListEl.appendChild(a);
                })(groups[g].list[i]);
            }
        }
        if (!any) { cloneSourceListEl.appendChild(el('div', 'list-group-item small text-body-secondary', 'No views available to clone yet.')); }
    }

    if (cloneBtn) {
        cloneBtn.addEventListener('click', function () {
            var hidden = cloneSourcesCard.classList.contains('d-none');
            if (hidden) { renderCloneSources(); }
            cloneSourcesCard.classList.toggle('d-none');
        });
    }

    // ── Channel list — click to add a strip ──────────────────────
    function renderChannelList() {
        channelListEl.innerHTML = '';
        for (var i = 0; i < channels.length; i++) {
            (function (ch) {
                var a = el('a', 'list-group-item list-group-item-action py-1 d-flex align-items-center', null);
                a.href = '#';
                a.appendChild(el('i', 'bi ' + adapterIcon(ch.adapter) + ' me-2'));
                var lbl = el('span', 'flex-grow-1 text-truncate small', ch.label);
                lbl.title = ch.channel_key;
                a.appendChild(lbl);
                if (parseInt(ch.enabled, 10) !== 1) { a.appendChild(el('span', 'badge text-bg-secondary ms-1', 'off')); }
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (currentViewId === null) { window.alert('Select or create a view first.'); return; }
                    strips.push({ channel_id: ch.id, overrides: {}, show: defaultShowForChannel(ch.capabilities || {}), hotkey: null, width: 1 });
                    selIndex = strips.length - 1;
                    setDirty(true); renderStripList(); renderInspector();
                });
                channelListEl.appendChild(a);
            })(channels[i]);
        }
    }

    // ── Publish ──────────────────────────────────────────────────
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (currentViewId === null) { return; }
            saveBtn.disabled = true;
            var finish = function (j) {
                saveBtn.disabled = false;
                if (j.ok) {
                    setDirty(false); renderViewList();
                    showToast('success', 'View published.');
                } else {
                    showToast('danger', j.error || 'Publish failed.');
                }
            };
            var v = findView(currentViewId, currentViewScope);
            var metaChanged = v && (v.name !== meta.name || (v.icon || '') !== (meta.icon || '')
                || (currentViewScope === 'personal' && !!v.is_shared !== !!meta.is_shared));
            var publishStrips = function () {
                var payload = [];
                for (var i = 0; i < strips.length; i++) {
                    var s = strips[i];
                    payload.push({ channel_id: s.channel_id, overrides: s.overrides, show: s.show, hotkey: s.hotkey, width: s.width });
                }
                post({ action: 'save_strips', id: currentViewId, strips: payload }, finish);
            };
            if (metaChanged) {
                var updatePayload = { action: 'update', id: currentViewId, name: meta.name, icon: meta.icon };
                if (currentViewScope === 'personal') { updatePayload.is_shared = !!meta.is_shared; }
                post(updatePayload, function (j) { if (!j.ok) { finish(j); return; } publishStrips(); });
            } else {
                publishStrips();
            }
        });
    }

    // ── Boot ─────────────────────────────────────────────────────
    fetch(CH_API)
        .then(function (r) { return r.json(); })
        .then(function (j) {
            channels = (j && j.channels) || [];
            channelsById = {};
            for (var i = 0; i < channels.length; i++) { channelsById[channels[i].id] = channels[i]; }
            return fetch(VIEWS_API);
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            views = (j && j.views) || [];
            myViews = (j && j.my_views) || [];
            sharedPersonalViews = (j && j.shared_personal_views) || [];
            renderViewList();
            renderChannelList();
            if (views.length) { selectView(views[0].id, 'shared'); }
            else if (myViews.length) { selectView(myViews[0].id, 'personal'); }
            else { renderCanvasChrome(); renderStripList(); }
        })
        .catch(function () {
            stripListEl.appendChild(el('div', 'text-danger p-3 small', 'Failed to load channels/views.'));
        });
})();
