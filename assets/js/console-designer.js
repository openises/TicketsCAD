/**
 * NewUI v4.0 — Console Designer (Phase 114b, slice b2.5 — free-form)
 *
 * Eric's 2026-07-07 direction: draw.io-style freedom — a grid layout
 * within a grid layout:
 *
 *   OUTER — the view canvas (12 columns, 20px rows) driven by GridStack
 *   (the same engine as the dashboard). Each strip is a widget: drag by
 *   its handle bar, resize both dimensions.
 *
 *   INNER — each strip body is a fine 12-column × 14px-row grid with
 *   CUSTOM pointer-drag placement (not a nested GridStack — live
 *   GridStack instances inside GridStack items hard-froze the renderer;
 *   see the b2.5 commit). Components snap to the grid, can be dragged
 *   anywhere, resized from the corner handle, and — unlike a packing
 *   engine — may OVERLAP/stack freely, exactly like draw.io.
 *
 * Click a component to edit its props in the inspector (text, colours,
 * PTT mode). The palette only offers components the channel is capable
 * of; "future" components (backends arrive with the audio bus) carry a
 * corner tag and render disabled at runtime.
 *
 * Designer mode never keys TX — components are presentation previews.
 *
 * ES5 IIFE — no arrow functions, no let/const, no template literals.
 */
(function () {
    'use strict';

    var CH_API = 'api/channels.php';
    var VIEWS_API = 'api/console-views.php';
    var OUTER_CELL = 20;   // px per outer row — matches runtime console.js
    var INNER_CELL = 14;   // px per inner row — matches runtime console.js
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    var viewListEl = document.getElementById('cdViewList');
    var canvasEl = document.getElementById('cdCanvas');
    var canvasTitle = document.getElementById('cdCanvasTitle');
    var channelListEl = document.getElementById('cdChannelList');
    var paletteEl = document.getElementById('cdPalette');
    var paletteBody = document.getElementById('cdPaletteBody');
    var inspectorEl = document.getElementById('cdInspector');
    var inspectorBody = document.getElementById('cdInspectorBody');
    var saveBtn = document.getElementById('cdSaveBtn');
    var newViewBtn = document.getElementById('cdNewViewBtn');
    var dirtyFlag = document.getElementById('cdDirtyFlag');
    if (!viewListEl || !canvasEl || typeof window.GridStack === 'undefined') { return; }

    var channels = [];
    var channelsById = {};
    var views = [];
    var componentCatalog = {};   // type -> {needs, label, future, props}
    var currentViewId = null;
    var meta = { name: '', icon: '' };
    var dirty = false;

    var outerGrid = null;        // GridStack instance for the canvas
    var strips = {};             // stripId -> {channel_id, overrides, el, grid, comps:{compId->comp}}
    var stripSeq = 0, compSeq = 0;
    var selStrip = null;         // selected strip id
    var selComp = null;          // selected comp id (within selStrip)

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
            sip: 'bi-telephone', intercom: 'bi-door-open', ptt1: 'bi-mic'
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
            cb(j || {});
        }).catch(function () { cb({ error: 'network error' }); });
    }

    function compAllowed(type, caps) {
        var def = componentCatalog[type];
        if (!def) { return false; }
        if (def.needs === null) { return true; }
        for (var i = 0; i < def.needs.length; i++) {
            if (caps[def.needs[i]]) { return true; }
        }
        return false;
    }

    function setDirty(d) {
        dirty = d;
        if (dirtyFlag) { dirtyFlag.textContent = d ? 'unsaved changes' : ''; }
        if (saveBtn) { saveBtn.classList.toggle('d-none', currentViewId === null); }
    }

    // ── Component preview rendering (inside inner-grid widgets) ──
    function compPreview(comp, ch) {
        var def = componentCatalog[comp.type] || { label: comp.type, future: false };
        var props = comp.props || {};
        var box = el('div', 'cdc cdc-' + comp.type);
        if (comp.type === 'label') {
            box.textContent = props.text || (ch ? (ch.short_label || ch.label) : 'Label');
            if (props.bg) { box.style.background = props.bg; }
            if (props.fg) { box.style.color = props.fg; }
        } else if (comp.type === 'ptt') {
            box.textContent = props.text || 'PTT';
            box.style.background = props.color || '#dc3545';
        } else if (comp.type === 'led') {
            box.appendChild(el('span', 'console-led console-led-connected'));
        } else if (comp.type === 'activity') {
            box.textContent = 'last caller · 2m ago';
        } else if (comp.type === 'text') {
            box.appendChild(el('div', 'cdc-text-hint', 'Messages / feed'));
        } else if (comp.type === 'monitor') {
            box.textContent = props.text || 'Mon';
        } else if (comp.type === 'mute') {
            box.textContent = props.text || 'Mute';
        } else if (comp.type === 'volume') {
            box.appendChild(el('div', 'cdc-vol-track'));
        } else if (comp.type === 'say') {
            box.textContent = props.text || 'Say';
        } else {
            box.textContent = def.label;
        }
        if (def.future) {
            box.appendChild(el('span', 'cdc-future-tag', 'future'));
        }
        return box;
    }

    // ── Strips on the outer grid ─────────────────────────────────
    function addStrip(channelId, layout, overrides, comps, skipDirty) {
        var ch = channelsById[channelId];
        var sid = 's' + (++stripSeq);
        layout = layout || { x: 0, y: 0, w: 3, h: 14 };

        var content = el('div', 'cd-strip-frame');
        var handle = el('div', 'cds-handle');
        handle.appendChild(el('i', 'bi ' + adapterIcon(ch ? ch.adapter : '') + ' me-1'));
        handle.appendChild(el('span', 'cds-handle-label',
            (overrides && (overrides.short_label || overrides.label))
            || (ch ? (ch.short_label || ch.label) : ('#' + channelId))));
        var rm = el('button', 'btn cd-strip-remove', null);
        rm.type = 'button';
        rm.title = 'Remove strip';
        rm.appendChild(el('i', 'bi bi-x-lg'));
        handle.appendChild(rm);
        content.appendChild(handle);
        var body = el('div', 'cds-body');
        content.appendChild(body);

        var widget = outerGrid.addWidget({
            x: layout.x, y: layout.y, w: layout.w, h: layout.h, content: '',
        });
        widget.setAttribute('data-strip-id', sid);
        widget.querySelector('.grid-stack-item-content').appendChild(content);

        var st = { id: sid, channel_id: channelId, overrides: overrides || {}, el: widget, body: body, comps: {} };
        strips[sid] = st;

        for (var i = 0; i < (comps || []).length; i++) {
            addComp(st, comps[i], true);
        }

        rm.addEventListener('click', function (e) {
            e.stopPropagation();
            outerGrid.removeWidget(widget);
            delete strips[sid];
            if (selStrip === sid) { selStrip = null; selComp = null; }
            setDirty(true);
            renderInspector();
            renderPalette();
        });

        handle.addEventListener('click', function () {
            selStrip = sid;
            selComp = null;
            highlight();
            renderInspector();
            renderPalette();
        });
        body.addEventListener('mousedown', function (e) {
            if (e.target === body) {
                selStrip = sid;
                selComp = null;
                highlight();
                renderInspector();
                renderPalette();
            }
        });

        return st;
    }

    // ── Components: custom snap-to-grid drag/resize (may overlap) ─
    function placeComp(c) {
        c.el.style.left = (c.x / 12 * 100) + '%';
        c.el.style.width = (c.w / 12 * 100) + '%';
        c.el.style.top = (c.y * INNER_CELL) + 'px';
        c.el.style.height = (c.h * INNER_CELL) + 'px';
    }

    function addComp(st, comp, skipDirty) {
        var cid = 'c' + (++compSeq);
        var ch = channelsById[st.channel_id];
        var wrap = el('div', 'cd-comp');
        wrap.setAttribute('data-comp-id', cid);
        wrap.appendChild(compPreview(comp, ch));
        var grip = el('div', 'cd-comp-resize');
        grip.title = 'Resize';
        wrap.appendChild(grip);
        st.body.appendChild(wrap);

        var c = {
            type: comp.type, props: comp.props || {}, el: wrap,
            x: comp.x || 0, y: comp.y || 0,
            w: Math.max(1, Math.min(12, comp.w || 12)),
            h: Math.max(1, comp.h || 2)
        };
        st.comps[cid] = c;
        placeComp(c);

        function select() {
            selStrip = st.id;
            selComp = cid;
            highlight();
            renderInspector();
            renderPalette();
        }

        // Drag to move (snap to grid, overlap allowed — draw.io style).
        wrap.addEventListener('mousedown', function (e) {
            if (e.button !== 0 || e.target === grip) { return; }
            e.preventDefault();
            e.stopPropagation();
            select();
            var colW = st.body.clientWidth / 12;
            var startX = e.clientX, startY = e.clientY;
            var origX = c.x, origY = c.y;
            var moved = false;
            var onMove = function (ev) {
                var dx = Math.round((ev.clientX - startX) / colW);
                var dy = Math.round((ev.clientY - startY) / INNER_CELL);
                var nx = Math.max(0, Math.min(12 - c.w, origX + dx));
                var ny = Math.max(0, origY + dy);
                if (nx !== c.x || ny !== c.y) {
                    c.x = nx; c.y = ny;
                    placeComp(c);
                    moved = true;
                }
            };
            var onUp = function () {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (moved) { setDirty(true); }
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Corner grip to resize.
        grip.addEventListener('mousedown', function (e) {
            if (e.button !== 0) { return; }
            e.preventDefault();
            e.stopPropagation();
            select();
            var colW = st.body.clientWidth / 12;
            var startX = e.clientX, startY = e.clientY;
            var origW = c.w, origH = c.h;
            var moved = false;
            var onMove = function (ev) {
                var dw = Math.round((ev.clientX - startX) / colW);
                var dh = Math.round((ev.clientY - startY) / INNER_CELL);
                var nw = Math.max(1, Math.min(12 - c.x, origW + dw));
                var nh = Math.max(1, origH + dh);
                if (nw !== c.w || nh !== c.h) {
                    c.w = nw; c.h = nh;
                    placeComp(c);
                    moved = true;
                }
            };
            var onUp = function () {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (moved) { setDirty(true); }
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        if (!skipDirty) { setDirty(true); }
        return cid;
    }

    function refreshCompPreview(st, cid) {
        var c = st.comps[cid];
        var grip = c.el.querySelector('.cd-comp-resize');
        c.el.innerHTML = '';
        c.el.appendChild(compPreview({ type: c.type, props: c.props }, channelsById[st.channel_id]));
        c.el.appendChild(grip);
    }

    function highlight() {
        var nodes = canvasEl.querySelectorAll('[data-strip-id]');
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].classList.toggle('cd-strip-selected',
                nodes[i].getAttribute('data-strip-id') === selStrip);
        }
        var comps = canvasEl.querySelectorAll('[data-comp-id]');
        for (var k = 0; k < comps.length; k++) {
            comps[k].classList.toggle('cd-comp-selected',
                comps[k].getAttribute('data-comp-id') === selComp);
        }
    }

    // ── Serialize the canvas back to API format ──────────────────
    function serialize() {
        var out = [];
        for (var sid in strips) {
            if (!Object.prototype.hasOwnProperty.call(strips, sid)) { continue; }
            var st = strips[sid];
            var n = st.el.gridstackNode || {};
            var comps = [];
            for (var cid in st.comps) {
                if (!Object.prototype.hasOwnProperty.call(st.comps, cid)) { continue; }
                var c = st.comps[cid];
                var comp = { type: c.type, x: c.x, y: c.y, w: c.w, h: c.h };
                if (c.props && Object.keys(c.props).length) { comp.props = c.props; }
                comps.push(comp);
            }
            out.push({
                channel_id: st.channel_id,
                layout: { x: n.x || 0, y: n.y || 0, w: n.w || 3, h: n.h || 14 },
                overrides: st.overrides,
                components: comps,
            });
        }
        return out;
    }

    // ── View list ────────────────────────────────────────────────
    function renderViewList() {
        viewListEl.innerHTML = '';
        if (!views.length) {
            viewListEl.appendChild(el('div', 'list-group-item small text-body-secondary',
                'No views yet — create one.'));
        }
        for (var i = 0; i < views.length; i++) {
            (function (v) {
                var a = el('a', 'list-group-item list-group-item-action py-2 d-flex align-items-center'
                    + (v.id === currentViewId ? ' active' : ''), null);
                a.href = '#';
                a.appendChild(el('i', 'bi ' + (v.icon || 'bi-broadcast-pin') + ' me-2'));
                a.appendChild(el('span', 'flex-grow-1 text-truncate small', v.name));
                a.appendChild(el('span', 'badge bg-secondary ms-1', String((v.strips || []).length)));
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (dirty && !window.confirm('Discard unsaved changes to the current view?')) { return; }
                    selectView(v.id);
                });
                viewListEl.appendChild(a);
            })(views[i]);
        }
    }

    function findView(id) {
        for (var i = 0; i < views.length; i++) {
            if (views[i].id === id) { return views[i]; }
        }
        return null;
    }

    function destroyCanvas() {
        strips = {};
        if (outerGrid) {
            // destroy(false): tear down the engine but KEEP the #cdCanvas
            // element — destroy(true) removes the grid element itself from
            // the DOM, after which every re-init works on a detached node
            // and strips silently render nowhere.
            try { outerGrid.destroy(false); } catch (e) {}
            outerGrid = null;
        }
        canvasEl.innerHTML = '';
    }

    function selectView(id) {
        currentViewId = id;
        selStrip = null;
        selComp = null;
        destroyCanvas();
        var v = findView(id);
        meta = { name: v ? v.name : '', icon: (v && v.icon) || '' };
        renderCanvasChrome();
        if (v) {
            outerGrid = GridStack.init({
                column: 12,
                cellHeight: OUTER_CELL,
                margin: 4,
                float: true,
                animate: false,
                handle: '.cds-handle',
                disableOneColumnMode: true,
            }, canvasEl);
            outerGrid.on('change', function () { setDirty(true); });
            for (var i = 0; i < (v.strips || []).length; i++) {
                var s = v.strips[i];
                addStrip(s.channel_id, s.layout, s.overrides || {}, s.components || [], true);
            }
        }
        setDirty(false);
        renderViewList();
        renderInspector();
        renderPalette();
    }

    // ── Canvas chrome (view name/icon/delete in the card header) ─
    function renderCanvasChrome() {
        canvasTitle.innerHTML = '';
        if (currentViewId === null) {
            canvasTitle.textContent = 'Select or create a view';
            canvasEl.classList.remove('grid-stack');
            canvasEl.appendChild(el('div', 'text-body-secondary p-3 small',
                'Pick a view on the left, or create one, then click channels to add strips. Drag strips by their title bar; drag/resize the components inside.'));
            return;
        }
        canvasEl.classList.add('grid-stack');
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
        var delBtn = el('button', 'btn btn-sm btn-outline-danger ms-2', null);
        delBtn.type = 'button';
        delBtn.title = 'Delete this view';
        delBtn.appendChild(el('i', 'bi bi-trash'));
        delBtn.addEventListener('click', function () {
            if (!window.confirm('Delete view "' + meta.name + '"? Dispatchers lose this tab.')) { return; }
            post({ action: 'delete', id: currentViewId }, function (j) {
                if (j.ok) {
                    currentViewId = null;
                    destroyCanvas();
                    setDirty(false);
                    renderViewList();
                    renderCanvasChrome();
                    renderInspector();
                    renderPalette();
                } else {
                    window.alert(j.error || 'Delete failed');
                }
            });
        });
        canvasTitle.appendChild(delBtn);
    }

    if (newViewBtn) {
        newViewBtn.addEventListener('click', function () {
            if (viewListEl.querySelector('.cd-newview-row')) { return; }
            var row = el('div', 'list-group-item py-2 cd-newview-row');
            var grp = el('div', 'input-group input-group-sm');
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.className = 'form-control form-control-sm';
            inp.placeholder = 'View name';
            inp.maxLength = 80;
            var ok = el('button', 'btn btn-sm btn-primary', null);
            ok.type = 'button';
            ok.appendChild(el('i', 'bi bi-check-lg'));
            grp.appendChild(inp);
            grp.appendChild(ok);
            row.appendChild(grp);
            viewListEl.insertBefore(row, viewListEl.firstChild);
            inp.focus();
            var create = function () {
                var name = inp.value.replace(/^\s+|\s+$/g, '');
                if (!name) { row.parentNode.removeChild(row); return; }
                ok.disabled = true;
                post({ action: 'create', name: name }, function (j) {
                    if (j.ok) {
                        renderViewList();
                        selectView(j.id);
                    } else {
                        ok.disabled = false;
                        window.alert(j.error || 'Create failed');
                    }
                });
            };
            ok.addEventListener('click', create);
            inp.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); create(); }
                if (e.key === 'Escape') { row.parentNode.removeChild(row); }
            });
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
                if (parseInt(ch.enabled, 10) !== 1) {
                    a.appendChild(el('span', 'badge text-bg-secondary ms-1', 'off'));
                }
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    if (currentViewId === null || !outerGrid) {
                        window.alert('Select or create a view first.');
                        return;
                    }
                    var comps = defaultComps(ch.capabilities || {});
                    var h = 5 + Math.ceil((maxCompY(comps) * INNER_CELL + 30) / OUTER_CELL);
                    var st = addStrip(ch.id, { w: 3, h: h }, {}, comps);
                    selStrip = st.id;
                    selComp = null;
                    setDirty(true);
                    highlight();
                    renderInspector();
                    renderPalette();
                });
                channelListEl.appendChild(a);
            })(channels[i]);
        }
    }

    function maxCompY(comps) {
        var m = 0;
        for (var i = 0; i < comps.length; i++) {
            if (comps[i].y + comps[i].h > m) { m = comps[i].y + comps[i].h; }
        }
        return m;
    }

    // Default component set for a fresh strip — mirrors Eric's sketch
    // (label block top, LED beside, activity, wide PTT, feed box).
    function defaultComps(caps) {
        var comps = [
            { type: 'label', x: 0, y: 0, w: 10, h: 3 },
            { type: 'led', x: 10, y: 0, w: 2, h: 1 },
            { type: 'activity', x: 0, y: 3, w: 12, h: 2 },
        ];
        var y = 5;
        if (caps.voice_tx) {
            comps.push({ type: 'ptt', x: 0, y: y, w: 12, h: 3 });
            y += 3;
        }
        if (caps.text_rx || caps.text_tx || caps.source) {
            comps.push({ type: 'text', x: 0, y: y, w: 12, h: 10 });
            y += 10;
        }
        return comps;
    }

    // ── Palette — add components to the SELECTED strip ───────────
    function renderPalette() {
        if (!paletteEl || !paletteBody) { return; }
        var st = selStrip ? strips[selStrip] : null;
        if (!st) {
            paletteEl.classList.add('d-none');
            return;
        }
        paletteEl.classList.remove('d-none');
        paletteBody.innerHTML = '';
        var ch = channelsById[st.channel_id];
        var caps = (ch && ch.capabilities) || {};
        for (var type in componentCatalog) {
            if (!Object.prototype.hasOwnProperty.call(componentCatalog, type)) { continue; }
            if (!compAllowed(type, caps)) { continue; }
            (function (t) {
                var def = componentCatalog[t];
                var b = el('button', 'btn btn-sm btn-outline-secondary cd-palette-btn', null);
                b.type = 'button';
                b.appendChild(document.createTextNode(def.label));
                if (def.future) {
                    b.appendChild(el('span', 'badge text-bg-warning ms-1 cd-palette-future', 'future'));
                    b.title = 'Backend arrives with the audio bus (Phase 114c+) — placeable now for layout planning';
                }
                b.addEventListener('click', function () {
                    var sizes = {
                        label: { w: 12, h: 3 }, led: { w: 2, h: 1 }, activity: { w: 12, h: 2 },
                        ptt: { w: 12, h: 3 }, text: { w: 12, h: 8 }, monitor: { w: 4, h: 2 },
                        mute: { w: 4, h: 2 }, volume: { w: 12, h: 1 }, say: { w: 4, h: 2 }
                    };
                    var sz = sizes[t] || { w: 6, h: 2 };
                    var cid = addComp(st, { type: t, x: 0, y: 0, w: sz.w, h: sz.h, props: {} });
                    selComp = cid;
                    highlight();
                    renderInspector();
                });
                paletteBody.appendChild(b);
            })(type);
        }
    }

    // ── Inspector ────────────────────────────────────────────────
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
        inp.addEventListener('input', function () {
            clear.classList.remove('d-none');
            onChange(inp.value);
        });
        clear.addEventListener('click', function (e) {
            e.preventDefault();
            clear.classList.add('d-none');
            onChange('');
        });
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
        var st = selStrip ? strips[selStrip] : null;
        if (!st) {
            inspectorEl.classList.add('d-none');
            return;
        }
        inspectorEl.classList.remove('d-none');
        inspectorBody.innerHTML = '';
        var ch = channelsById[st.channel_id];

        // Component selected → component props
        if (selComp && st.comps[selComp]) {
            var c = st.comps[selComp];
            var def = componentCatalog[c.type] || { label: c.type, props: [] };
            inspectorBody.appendChild(el('div', 'small fw-semibold mb-2',
                def.label + (def.future ? ' (future)' : '')));

            if (def.props.indexOf('text') !== -1) {
                inspectorBody.appendChild(textRow('Text', c.props.text,
                    c.type === 'label' ? (ch ? ch.label : '') : def.label, 40,
                    function (v) {
                        if (v) { c.props.text = v; } else { delete c.props.text; }
                        setDirty(true);
                        refreshCompPreview(st, selComp);
                    }));
            }
            if (def.props.indexOf('color') !== -1) {
                inspectorBody.appendChild(colorRow('Button colour', c.props.color, function (v) {
                    if (v) { c.props.color = v; } else { delete c.props.color; }
                    setDirty(true);
                    refreshCompPreview(st, selComp);
                }));
            }
            if (def.props.indexOf('bg') !== -1) {
                inspectorBody.appendChild(colorRow('Background', c.props.bg, function (v) {
                    if (v) { c.props.bg = v; } else { delete c.props.bg; }
                    setDirty(true);
                    refreshCompPreview(st, selComp);
                }));
            }
            if (def.props.indexOf('fg') !== -1) {
                inspectorBody.appendChild(colorRow('Text colour', c.props.fg, function (v) {
                    if (v) { c.props.fg = v; } else { delete c.props.fg; }
                    setDirty(true);
                    refreshCompPreview(st, selComp);
                }));
            }
            if (def.props.indexOf('mode') !== -1) {
                var pm = document.createElement('select');
                pm.className = 'form-select form-select-sm';
                var optM = el('option', null, 'Momentary (hold to talk)'); optM.value = 'momentary';
                var optL = el('option', null, 'Latch (click on / click off)'); optL.value = 'latch';
                pm.appendChild(optM); pm.appendChild(optL);
                pm.value = c.props.mode || 'momentary';
                pm.addEventListener('change', function () {
                    if (pm.value === 'latch') { c.props.mode = 'latch'; } else { delete c.props.mode; }
                    setDirty(true);
                });
                inspectorBody.appendChild(inspectorRow('PTT mode', pm));
            }

            var delC = el('button', 'btn btn-sm btn-outline-danger w-100 mt-1', null);
            delC.type = 'button';
            delC.appendChild(el('i', 'bi bi-trash me-1'));
            delC.appendChild(document.createTextNode('Remove component'));
            delC.addEventListener('click', function () {
                // Inner components are custom DOM nodes in the strip body
                // (only the OUTER strips are GridStack widgets). The old
                // st.grid.removeWidget() referenced a non-existent grid
                // property on the strip and threw, aborting the handler --
                // so the button did nothing. Remove the node directly.
                if (c.el && c.el.parentNode) { c.el.parentNode.removeChild(c.el); }
                delete st.comps[selComp];
                selComp = null;
                setDirty(true);
                renderInspector();
                renderPalette();
            });
            inspectorBody.appendChild(delC);
            return;
        }

        // Strip selected → strip-level settings
        inspectorBody.appendChild(el('div', 'small fw-semibold mb-2',
            (ch ? ch.label + ' — ' + ch.adapter : 'missing channel')));
        inspectorBody.appendChild(textRow('Label override', st.overrides.label, ch ? ch.label : '', 120,
            function (v) {
                if (v) { st.overrides.label = v; } else { delete st.overrides.label; }
                setDirty(true);
                var hl = st.el.querySelector('.cds-handle-label');
                if (hl) { hl.textContent = st.overrides.short_label || st.overrides.label || (ch ? (ch.short_label || ch.label) : ''); }
            }));
        inspectorBody.appendChild(textRow('Short label', st.overrides.short_label, 'shown in tight spots', 24,
            function (v) {
                if (v) { st.overrides.short_label = v; } else { delete st.overrides.short_label; }
                setDirty(true);
                var hl = st.el.querySelector('.cds-handle-label');
                if (hl) { hl.textContent = st.overrides.short_label || st.overrides.label || (ch ? (ch.short_label || ch.label) : ''); }
            }));
        inspectorBody.appendChild(colorRow('Strip accent colour', st.overrides.color, function (v) {
            if (v) { st.overrides.color = v; } else { delete st.overrides.color; }
            setDirty(true);
            var frame = st.el.querySelector('.cd-strip-frame');
            if (frame) { frame.style.borderTopColor = v || ''; }
        }));
        inspectorBody.appendChild(el('div', 'small text-body-secondary',
            'Click a component on the strip to edit it; use the palette below to add more. Drag the strip by its title bar; resize from the corner.'));
    }

    // ── Publish ──────────────────────────────────────────────────
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            if (currentViewId === null) { return; }
            saveBtn.disabled = true;
            var finish = function (j) {
                saveBtn.disabled = false;
                if (j.ok) {
                    setDirty(false);
                    renderViewList();
                } else {
                    window.alert(j.error || 'Publish failed');
                }
            };
            var v = findView(currentViewId);
            var metaChanged = v && (v.name !== meta.name || (v.icon || '') !== (meta.icon || ''));
            var publishStrips = function () {
                post({ action: 'save_strips', id: currentViewId, strips: serialize() }, finish);
            };
            if (metaChanged) {
                post({ action: 'update', id: currentViewId, name: meta.name, icon: meta.icon }, function (j) {
                    if (!j.ok) { finish(j); return; }
                    publishStrips();
                });
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
            componentCatalog = (j && j.components) || {};
            renderViewList();
            renderChannelList();
            if (views.length) { selectView(views[0].id); } else { renderCanvasChrome(); }
        })
        .catch(function () {
            canvasEl.appendChild(el('div', 'text-danger p-3 small', 'Failed to load channels/views.'));
        });
})();
