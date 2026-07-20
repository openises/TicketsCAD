/**
 * NewUI v4.0 — Status Workflow Designer (Phase 105, a beta tester GH #16)
 *
 * Jira-style visual editor for allowed unit-status transitions.
 * Pure SVG, no libraries. Statuses are draggable rounded-rect nodes;
 * transitions are curved arrows created by dragging from a node's +
 * port onto another node. Clicking an arrow opens a floating panel
 * with per-transition conditions + delete. Clicking a node opens the
 * side panel with the "reachable from any status" toggle (the
 * synthetic from_status_id = 0 edge).
 *
 * Everything is a live client-side copy until Save POSTs the whole
 * graph to api/status-workflow.php (replace-all semantics).
 *
 * Conventions:
 *   - ES5 only (var, function, no arrow functions, no template
 *     literals) — IIFE-wrapped, strict mode
 *   - No jQuery, no build step
 *   - Theme-aware via Bootstrap CSS variables (styles live on
 *     workflow-designer.php)
 */
(function () {
    'use strict';

    var SVG_NS = 'http://www.w3.org/2000/svg';
    var API_URL = 'api/status-workflow.php';
    var CSRF_TOKEN = (document.getElementById('csrfToken') || {}).value || '';

    var NODE_H = 38;
    var PORT_R = 9;
    var EDGE_CURVE = 26;      // perpendicular offset for the quadratic curve
    var DRAG_THRESHOLD = 5;   // px of movement before a click becomes a drag

    var MODE_HELP = {
        off: 'Workflow is ignored — every status change is allowed (default behavior).',
        warn: 'Every change is still allowed, but changes outside the workflow show a warning and are written to the audit log.',
        enforce: 'A status change is rejected unless a matching arrow exists and its conditions pass.'
    };

    // ── State ───────────────────────────────────────────────────────
    var state = {
        statuses: [],        // [{id, status_val, bg_color, text_color, hide}]
        transitions: [],     // [{from_status_id, to_status_id, conditions:{}}]
        layout: {},          // { '<id>': {x, y} }
        mode: 'off',
        dirty: false,
        selectedNodeId: null,
        selectedEdgeKey: null,   // 'from>to'
        // interaction state
        dragNodeId: null,
        dragOffset: null,        // {dx, dy} pointer-to-node offset
        dragStart: null,         // {x, y} where the press started
        dragMoved: false,
        connectFromId: null,     // node id a rubber-band starts from
        connectPos: null         // {x, y} current rubber-band end
    };

    var svg = document.getElementById('wfCanvas');
    var wrap = document.getElementById('wfCanvasWrap');
    var nodeGeom = {};   // '<id>': {x, y, w, h} — filled each render

    function $(id) { return document.getElementById(id); }

    // ── Small helpers ───────────────────────────────────────────────

    function statusById(id) {
        var i;
        for (i = 0; i < state.statuses.length; i++) {
            if (state.statuses[i].id === id) return state.statuses[i];
        }
        return null;
    }

    function statusName(id) {
        if (id === 0) return 'ANY';
        var s = statusById(id);
        return s ? s.status_val : ('status #' + id);
    }

    function edgeKey(t) { return t.from_status_id + '>' + t.to_status_id; }

    function findTransition(fromId, toId) {
        var i;
        for (i = 0; i < state.transitions.length; i++) {
            if (state.transitions[i].from_status_id === fromId
                && state.transitions[i].to_status_id === toId) {
                return i;
            }
        }
        return -1;
    }

    function markDirty() {
        state.dirty = true;
        var btn = $('wfBtnSave');
        if (btn) btn.classList.add('btn-warning');
    }

    function clearDirty() {
        state.dirty = false;
        var btn = $('wfBtnSave');
        if (btn) btn.classList.remove('btn-warning');
    }

    function showAlert(kind, message) {
        var slot = $('wfAlertSlot');
        if (!slot) return;
        var div = document.createElement('div');
        div.className = 'alert alert-' + kind + ' alert-dismissible fade show py-2 small';
        div.setAttribute('role', 'alert');
        div.appendChild(document.createTextNode(message));
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close';
        close.setAttribute('data-bs-dismiss', 'alert');
        close.setAttribute('aria-label', 'Close');
        div.appendChild(close);
        slot.innerHTML = '';
        slot.appendChild(div);
    }

    function svgEl(tag, attrs) {
        var el = document.createElementNS(SVG_NS, tag);
        var k;
        for (k in attrs) {
            if (Object.prototype.hasOwnProperty.call(attrs, k)) {
                el.setAttribute(k, attrs[k]);
            }
        }
        return el;
    }

    function canvasPoint(evt) {
        var rect = svg.getBoundingClientRect();
        return {
            x: evt.clientX - rect.left,
            y: evt.clientY - rect.top
        };
    }

    // Safe SVG paint value: the DB stores admin-configured colors like
    // '#ff0000' / 'red' / 'transparent'. Anything odd falls back.
    function safeColor(value, fallback) {
        var v = String(value || '').trim();
        if (v === '' || !/^[#a-zA-Z0-9(),.%\s-]+$/.test(v)) return fallback;
        return v;
    }

    // ── Layout helpers ──────────────────────────────────────────────

    function nodeWidth(status) {
        var label = status.status_val || '';
        return Math.max(110, label.length * 8 + 50);
    }

    function ensureLayout() {
        // Any status missing a saved position gets a slot on a circle.
        var missing = [];
        var i;
        for (i = 0; i < state.statuses.length; i++) {
            var id = String(state.statuses[i].id);
            if (!state.layout[id]) missing.push(state.statuses[i]);
        }
        if (!missing.length) return;

        var w = svg.clientWidth || 900;
        var h = svg.clientHeight || 500;
        var cx = w / 2;
        var cy = h / 2;
        var r = Math.max(120, Math.min(cx, cy) - 90);
        var n = state.statuses.length;
        for (i = 0; i < state.statuses.length; i++) {
            var sid = String(state.statuses[i].id);
            if (!state.layout[sid]) {
                var angle = (2 * Math.PI * i / n) - Math.PI / 2;
                state.layout[sid] = {
                    x: Math.round(cx + r * Math.cos(angle) - nodeWidth(state.statuses[i]) / 2),
                    y: Math.round(cy + r * Math.sin(angle) - NODE_H / 2)
                };
            }
        }
    }

    function clampPos(x, y, w) {
        var maxX = Math.max(10, (svg.clientWidth || 900) - w - 10);
        var maxY = Math.max(10, (svg.clientHeight || 500) - NODE_H - 10);
        return {
            x: Math.min(Math.max(x, 4), maxX),
            y: Math.min(Math.max(y, 4), maxY)
        };
    }

    // Intersection of the segment center-A → center-B with A's border.
    function rectBorderPoint(geom, towardX, towardY) {
        var cx = geom.x + geom.w / 2;
        var cy = geom.y + geom.h / 2;
        var dx = towardX - cx;
        var dy = towardY - cy;
        if (dx === 0 && dy === 0) return { x: cx, y: cy };
        var scaleX = dx !== 0 ? (geom.w / 2) / Math.abs(dx) : Infinity;
        var scaleY = dy !== 0 ? (geom.h / 2) / Math.abs(dy) : Infinity;
        var scale = Math.min(scaleX, scaleY);
        return { x: cx + dx * scale, y: cy + dy * scale };
    }

    // ── Rendering ───────────────────────────────────────────────────

    function render() {
        while (svg.firstChild) svg.removeChild(svg.firstChild);
        nodeGeom = {};

        // defs: arrowheads (normal + selected)
        var defs = svgEl('defs', {});
        defs.appendChild(buildMarker('wfArrow', 'var(--bs-secondary-color)'));
        defs.appendChild(buildMarker('wfArrowSel', 'var(--bs-primary)'));
        svg.appendChild(defs);

        ensureLayout();

        // Node geometry first (edges need both endpoints)
        var i;
        for (i = 0; i < state.statuses.length; i++) {
            var s = state.statuses[i];
            var pos = state.layout[String(s.id)];
            nodeGeom[String(s.id)] = {
                x: pos.x, y: pos.y, w: nodeWidth(s), h: NODE_H
            };
        }

        // Edges under nodes
        var edgeLayer = svgEl('g', { 'class': 'wf-edge-layer' });
        svg.appendChild(edgeLayer);
        for (i = 0; i < state.transitions.length; i++) {
            var t = state.transitions[i];
            if (t.from_status_id === 0) continue;  // ANY: badge, not arrow
            drawEdge(edgeLayer, t);
        }

        // Rubber band (while connecting)
        if (state.connectFromId !== null && state.connectPos) {
            var fromGeom = nodeGeom[String(state.connectFromId)];
            if (fromGeom) {
                var start = {
                    x: fromGeom.x + fromGeom.w,
                    y: fromGeom.y + fromGeom.h / 2
                };
                svg.appendChild(svgEl('line', {
                    'class': 'wf-rubber',
                    x1: start.x, y1: start.y,
                    x2: state.connectPos.x, y2: state.connectPos.y
                }));
            }
        }

        // Nodes on top
        for (i = 0; i < state.statuses.length; i++) {
            drawNode(state.statuses[i]);
        }
    }

    function buildMarker(id, color) {
        var marker = svgEl('marker', {
            id: id, viewBox: '0 0 10 10', refX: '9', refY: '5',
            markerWidth: '7', markerHeight: '7', orient: 'auto-start-reverse'
        });
        var path = svgEl('path', { d: 'M 0 0 L 10 5 L 0 10 z' });
        path.setAttribute('style', 'fill: ' + color + ';');
        marker.appendChild(path);
        return marker;
    }

    function edgePathD(t) {
        var a = nodeGeom[String(t.from_status_id)];
        var b = nodeGeom[String(t.to_status_id)];
        if (!a || !b) return null;
        var acx = a.x + a.w / 2, acy = a.y + a.h / 2;
        var bcx = b.x + b.w / 2, bcy = b.y + b.h / 2;
        var p1 = rectBorderPoint(a, bcx, bcy);
        var p2 = rectBorderPoint(b, acx, acy);
        // Perpendicular offset to the left of travel so A→B and B→A
        // curve to opposite sides instead of overlapping.
        var dx = p2.x - p1.x, dy = p2.y - p1.y;
        var len = Math.sqrt(dx * dx + dy * dy) || 1;
        var nx = -dy / len, ny = dx / len;
        var mx = (p1.x + p2.x) / 2 + nx * EDGE_CURVE;
        var my = (p1.y + p2.y) / 2 + ny * EDGE_CURVE;
        return 'M ' + p1.x + ' ' + p1.y + ' Q ' + mx + ' ' + my + ' ' + p2.x + ' ' + p2.y;
    }

    function edgeHasConditions(t) {
        return !!(t.conditions && (t.conditions.requires_assignment
            || t.conditions.requires_no_assignment));
    }

    function drawEdge(layer, t) {
        var d = edgePathD(t);
        if (!d) return;
        var key = edgeKey(t);
        var selected = (state.selectedEdgeKey === key);

        var visible = svgEl('path', {
            d: d,
            'class': 'wf-edge'
                + (selected ? ' wf-edge-selected' : '')
                + (edgeHasConditions(t) ? ' wf-edge-conditional' : ''),
            'marker-end': 'url(#' + (selected ? 'wfArrowSel' : 'wfArrow') + ')'
        });
        var hit = svgEl('path', { d: d, 'class': 'wf-edge-hit' });
        (function (edgeT) {
            hit.addEventListener('click', function (evt) {
                evt.stopPropagation();
                selectEdge(edgeT, evt);
            });
        })(t);
        layer.appendChild(visible);
        layer.appendChild(hit);
    }

    function drawNode(status) {
        var geom = nodeGeom[String(status.id)];
        var g = svgEl('g', {
            'class': 'wf-node'
                + (state.selectedNodeId === status.id ? ' wf-selected' : '')
                + (state.dragNodeId === status.id ? ' wf-dragging' : '')
                + (status.hide === 'y' ? ' wf-hidden-status' : ''),
            transform: 'translate(' + geom.x + ',' + geom.y + ')'
        });
        g.setAttribute('data-node-id', String(status.id));

        var rect = svgEl('rect', {
            'class': 'wf-node-rect',
            width: geom.w, height: geom.h, rx: 8, ry: 8
        });
        rect.setAttribute('style',
            'fill: ' + safeColor(status.bg_color, 'var(--bs-secondary-bg)') + ';');
        g.appendChild(rect);

        var label = svgEl('text', {
            x: geom.w / 2, y: geom.h / 2 + 4,
            'text-anchor': 'middle',
            'font-size': '13', 'font-weight': '600'
        });
        label.setAttribute('style',
            'fill: ' + safeColor(status.text_color, 'var(--bs-body-color)') + '; pointer-events: none;');
        label.appendChild(document.createTextNode(
            status.status_val + (status.hide === 'y' ? ' (hidden)' : '')));
        g.appendChild(label);

        // ANY badge when the synthetic 0 → this edge exists
        if (findTransition(0, status.id) !== -1) {
            var badgeW = 36;
            var badge = svgEl('rect', {
                'class': 'wf-any-badge-bg',
                x: geom.w / 2 - badgeW / 2, y: -16,
                width: badgeW, height: 14, rx: 7, ry: 7
            });
            g.appendChild(badge);
            var badgeText = svgEl('text', {
                'class': 'wf-any-badge-text',
                x: geom.w / 2, y: -5.5, 'text-anchor': 'middle'
            });
            badgeText.appendChild(document.createTextNode('ANY'));
            g.appendChild(badgeText);
        }

        // "+" connection port on the right edge
        var port = svgEl('circle', {
            'class': 'wf-port',
            cx: geom.w, cy: geom.h / 2, r: PORT_R
        });
        port.setAttribute('data-port-for', String(status.id));
        g.appendChild(port);
        g.appendChild(svgEl('line', {
            'class': 'wf-port-plus',
            x1: geom.w - 4.5, y1: geom.h / 2, x2: geom.w + 4.5, y2: geom.h / 2
        }));
        g.appendChild(svgEl('line', {
            'class': 'wf-port-plus',
            x1: geom.w, y1: geom.h / 2 - 4.5, x2: geom.w, y2: geom.h / 2 + 4.5
        }));

        // Interactions
        (function (s) {
            g.addEventListener('mousedown', function (evt) {
                if (evt.button !== 0) return;
                var target = evt.target;
                if (target && target.getAttribute
                    && target.getAttribute('data-port-for') === String(s.id)) {
                    // Start rubber-band connect
                    evt.preventDefault();
                    evt.stopPropagation();
                    state.connectFromId = s.id;
                    state.connectPos = canvasPoint(evt);
                    render();
                    return;
                }
                // Start (potential) drag
                evt.preventDefault();
                evt.stopPropagation();
                var pt = canvasPoint(evt);
                var geomNow = nodeGeom[String(s.id)];
                state.dragNodeId = s.id;
                state.dragMoved = false;
                state.dragStart = { x: pt.x, y: pt.y };
                state.dragOffset = { dx: pt.x - geomNow.x, dy: pt.y - geomNow.y };
                g.classList.add('wf-dragging');
            });
        })(status);

        svg.appendChild(g);
    }

    // ── Selection + panels ──────────────────────────────────────────

    function selectNode(id) {
        state.selectedNodeId = id;
        state.selectedEdgeKey = null;
        hideEdgePanel();
        renderNodePanel();
        render();
    }

    function deselectAll() {
        state.selectedNodeId = null;
        state.selectedEdgeKey = null;
        hideEdgePanel();
        renderNodePanel();
        render();
    }

    function renderNodePanel() {
        var empty = $('wfNodePanelEmpty');
        var panel = $('wfNodePanel');
        if (!empty || !panel) return;
        if (state.selectedNodeId === null) {
            empty.classList.remove('d-none');
            panel.classList.add('d-none');
            return;
        }
        var status = statusById(state.selectedNodeId);
        if (!status) { deselectAll(); return; }
        empty.classList.add('d-none');
        panel.classList.remove('d-none');
        $('wfNodePanelName').textContent = status.status_val;
        $('wfNodeAnySource').checked = (findTransition(0, status.id) !== -1);

        // Simple in/out counts for orientation
        var inCount = 0, outCount = 0, i;
        for (i = 0; i < state.transitions.length; i++) {
            var t = state.transitions[i];
            if (t.to_status_id === status.id) inCount++;
            if (t.from_status_id === status.id) outCount++;
        }
        $('wfNodeStats').textContent =
            'Incoming arrows: ' + inCount + ' — outgoing arrows: ' + outCount;
    }

    function selectEdge(t, evt) {
        state.selectedEdgeKey = edgeKey(t);
        state.selectedNodeId = null;
        renderNodePanel();
        render();
        showEdgePanel(t, evt);
    }

    function currentSelectedTransition() {
        if (!state.selectedEdgeKey) return null;
        var parts = state.selectedEdgeKey.split('>');
        var idx = findTransition(parseInt(parts[0], 10), parseInt(parts[1], 10));
        return idx === -1 ? null : state.transitions[idx];
    }

    function showEdgePanel(t, evt) {
        var panel = $('wfEdgePanel');
        if (!panel) return;
        $('wfEdgePanelTitle').textContent =
            statusName(t.from_status_id) + ' → ' + statusName(t.to_status_id);
        $('wfCondAssign').checked = !!(t.conditions && t.conditions.requires_assignment);
        $('wfCondNoAssign').checked = !!(t.conditions && t.conditions.requires_no_assignment);
        panel.classList.remove('d-none');

        // Position near the click, clamped inside the canvas wrap
        var wrapRect = wrap.getBoundingClientRect();
        var px = evt.clientX - wrapRect.left + 12;
        var py = evt.clientY - wrapRect.top + 12;
        px = Math.min(px, wrapRect.width - panel.offsetWidth - 8);
        py = Math.min(py, wrapRect.height - panel.offsetHeight - 8);
        panel.style.left = Math.max(4, px) + 'px';
        panel.style.top = Math.max(4, py) + 'px';
    }

    function hideEdgePanel() {
        var panel = $('wfEdgePanel');
        if (panel) panel.classList.add('d-none');
    }

    // ── Mutations ───────────────────────────────────────────────────

    function addTransition(fromId, toId) {
        if (fromId === toId) {
            showAlert('warning', 'A status cannot transition to itself.');
            return;
        }
        if (findTransition(fromId, toId) !== -1) return;  // already exists
        state.transitions.push({
            from_status_id: fromId,
            to_status_id: toId,
            conditions: {}
        });
        markDirty();
        render();
    }

    function deleteSelectedTransition() {
        var t = currentSelectedTransition();
        if (!t) return;
        var idx = findTransition(t.from_status_id, t.to_status_id);
        if (idx !== -1) state.transitions.splice(idx, 1);
        state.selectedEdgeKey = null;
        hideEdgePanel();
        markDirty();
        render();
    }

    function setEdgeConditions(requiresAssign, requiresNoAssign) {
        var t = currentSelectedTransition();
        if (!t) return;
        t.conditions = {};
        if (requiresAssign) t.conditions.requires_assignment = true;
        if (requiresNoAssign) t.conditions.requires_no_assignment = true;
        markDirty();
        render();
    }

    function setAnySource(nodeId, enabled) {
        var idx = findTransition(0, nodeId);
        if (enabled && idx === -1) {
            state.transitions.push({
                from_status_id: 0, to_status_id: nodeId, conditions: {}
            });
            markDirty();
        } else if (!enabled && idx !== -1) {
            state.transitions.splice(idx, 1);
            markDirty();
        }
        render();
    }

    // ── Canvas-level mouse handlers (drag / connect) ────────────────

    function hitTestNode(pt) {
        var i;
        for (i = 0; i < state.statuses.length; i++) {
            var id = String(state.statuses[i].id);
            var g = nodeGeom[id];
            if (!g) continue;
            if (pt.x >= g.x - PORT_R && pt.x <= g.x + g.w + PORT_R
                && pt.y >= g.y && pt.y <= g.y + g.h) {
                return state.statuses[i].id;
            }
        }
        return null;
    }

    document.addEventListener('mousemove', function (evt) {
        if (state.dragNodeId !== null) {
            var pt = canvasPoint(evt);
            var sid = String(state.dragNodeId);
            var geom = nodeGeom[sid];
            if (!geom) return;
            // Don't start actually moving until the pointer has
            // travelled past the click threshold — keeps plain clicks
            // from nudging the node.
            if (!state.dragMoved) {
                var dist = Math.abs(pt.x - state.dragStart.x)
                         + Math.abs(pt.y - state.dragStart.y);
                if (dist < DRAG_THRESHOLD) return;
                state.dragMoved = true;
            }
            var pos = clampPos(pt.x - state.dragOffset.dx, pt.y - state.dragOffset.dy, geom.w);
            state.layout[sid] = { x: Math.round(pos.x), y: Math.round(pos.y) };
            render();
            return;
        }
        if (state.connectFromId !== null) {
            state.connectPos = canvasPoint(evt);
            render();
        }
    });

    document.addEventListener('mouseup', function (evt) {
        if (state.dragNodeId !== null) {
            var nodeId = state.dragNodeId;
            var moved = state.dragMoved;
            state.dragNodeId = null;
            state.dragOffset = null;
            state.dragStart = null;
            state.dragMoved = false;
            if (moved) {
                markDirty();   // position changed → layout needs saving
                render();
            } else {
                selectNode(nodeId);   // treat as a click
            }
            return;
        }
        if (state.connectFromId !== null) {
            var fromId = state.connectFromId;
            var pt = canvasPoint(evt);
            var targetId = hitTestNode(pt);
            state.connectFromId = null;
            state.connectPos = null;
            if (targetId !== null && targetId !== fromId) {
                addTransition(fromId, targetId);
            } else {
                if (targetId === fromId && targetId !== null) {
                    showAlert('warning', 'A status cannot transition to itself.');
                }
                render();
            }
        }
    });

    svg.addEventListener('mousedown', function (evt) {
        // Only fires when the press landed on the empty canvas (node
        // handlers stopPropagation). Deselect everything.
        if (evt.target === svg) {
            deselectAll();
        }
    });

    // ── Toolbar / panel wiring ──────────────────────────────────────

    function updateModeHelp() {
        var sel = $('wfMode');
        var help = $('wfModeHelp');
        if (sel && help) {
            help.textContent = MODE_HELP[sel.value] || '';
        }
    }

    $('wfMode').addEventListener('change', function () {
        state.mode = this.value;
        updateModeHelp();
        markDirty();
    });

    $('wfEdgePanelClose').addEventListener('click', function () {
        state.selectedEdgeKey = null;
        hideEdgePanel();
        render();
    });

    $('wfBtnDeleteEdge').addEventListener('click', deleteSelectedTransition);

    // Mutually exclusive condition checkboxes
    $('wfCondAssign').addEventListener('change', function () {
        if (this.checked) $('wfCondNoAssign').checked = false;
        setEdgeConditions($('wfCondAssign').checked, $('wfCondNoAssign').checked);
    });
    $('wfCondNoAssign').addEventListener('change', function () {
        if (this.checked) $('wfCondAssign').checked = false;
        setEdgeConditions($('wfCondAssign').checked, $('wfCondNoAssign').checked);
    });

    $('wfNodeAnySource').addEventListener('change', function () {
        if (state.selectedNodeId !== null) {
            setAnySource(state.selectedNodeId, this.checked);
        }
    });

    $('wfBtnSave').addEventListener('click', save);

    $('wfBtnReset').addEventListener('click', function () {
        if (state.dirty && !window.confirm('Discard unsaved workflow changes and reload from the server?')) {
            return;
        }
        loadData();
    });

    window.addEventListener('resize', function () { render(); });

    // ── Server I/O ──────────────────────────────────────────────────

    function loadData() {
        fetch(API_URL, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }
                state.statuses = data.statuses || [];
                state.transitions = [];
                var i;
                var raw = data.transitions || [];
                for (i = 0; i < raw.length; i++) {
                    state.transitions.push({
                        from_status_id: parseInt(raw[i].from_status_id, 10),
                        to_status_id: parseInt(raw[i].to_status_id, 10),
                        conditions: (raw[i].conditions && typeof raw[i].conditions === 'object')
                            ? raw[i].conditions : {}
                    });
                }
                state.layout = {};
                var lay = data.layout || {};
                var k;
                for (k in lay) {
                    if (Object.prototype.hasOwnProperty.call(lay, k)) {
                        state.layout[k] = {
                            x: parseInt(lay[k].x, 10) || 0,
                            y: parseInt(lay[k].y, 10) || 0
                        };
                    }
                }
                state.mode = data.mode || 'off';
                state.selectedNodeId = null;
                state.selectedEdgeKey = null;
                $('wfMode').value = state.mode;
                updateModeHelp();
                clearDirty();
                hideEdgePanel();
                renderNodePanel();
                render();
            })
            .catch(function () {
                showAlert('danger', 'Failed to load the status workflow from the server.');
            });
    }

    function save() {
        var btn = $('wfBtnSave');
        btn.disabled = true;
        var payload = {
            action: 'save',
            csrf_token: CSRF_TOKEN,
            mode: state.mode,
            transitions: state.transitions,
            layout: state.layout
        };
        fetch(API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                if (data.error) {
                    showAlert('danger', data.error);
                    return;
                }
                clearDirty();
                showAlert('success', 'Workflow saved — ' + (data.transition_count || 0)
                    + ' transition(s), mode: ' + (data.mode || state.mode) + '.');
            })
            .catch(function () {
                btn.disabled = false;
                showAlert('danger', 'Failed to save the workflow.');
            });
    }

    // ── Boot ────────────────────────────────────────────────────────
    updateModeHelp();
    loadData();
})();
